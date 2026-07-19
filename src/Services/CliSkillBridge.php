<?php

namespace SuperAICore\Services;

use SuperAICore\Contracts\SkillLibrary;

/**
 * Bridges a host's {@see SkillLibrary} into every CLI backend's native
 * surface, the same way {@see McpManager::syncAllBackends()} bridges MCP.
 *
 * Per backend, skills land in one of three shapes:
 *   - `native_dir`   — codex/gemini/grok/cursor/qwen auto-load a skills
 *                      directory of `SKILL.md` packs. We drop one thin
 *                      wrapper dir per skill (prefixed, so we never touch
 *                      the user's own skills).
 *   - `instructions` — copilot/kiro (and legacy kimi-cli) have no skills
 *                      dir but auto-load a custom-instructions file; we
 *                      write a digest that tells the model how to load any
 *                      skill on demand. (Current kimi-code has a real
 *                      skills dir, so `kimi` is promoted to `native_dir`
 *                      by descriptor() when that install is detected.)
 *   - `source`       — claude reads the host's `.claude/skills` directly;
 *                      nothing to install.
 *
 * Safety (the lesson from the symlink write-through incident): we NEVER
 * write through a symlink. Before writing any wrapper path we unlink an
 * existing symlink first, so a stale `~/.codex/skills/super-team-x ->
 * .../.claude/skills/x` link can never let a wrapper clobber the source.
 *
 * Laziness: each sync stamps the library {@see SkillLibrary::fingerprint()}
 * into a per-backend manifest. {@see ensureSynced()} re-installs only when
 * the fingerprint drifts, so the on-dispatch hook costs one hash compare
 * on the hot path.
 */
class CliSkillBridge
{
    /** Marker file (in each backend's skills/config dir) holding our state. */
    public const MANIFEST = '.superteam-skill-sync.json';

    /**
     * Per-backend bridge descriptor. `dir` / `file` are relative to $HOME.
     * Adding a CLI here is the only change needed to bridge skills to it.
     *
     * @var array<string,array{mode:string,dir?:string,file?:string,prefix?:string}>
     */
    public const BACKENDS = [
        'codex'  => ['mode' => 'native_dir', 'dir' => '.codex/skills',         'prefix' => 'super-team-'],
        'gemini' => ['mode' => 'native_dir', 'dir' => '.gemini/skills',        'prefix' => 'super-team-'],
        'grok'   => ['mode' => 'native_dir', 'dir' => '.grok/skills',          'prefix' => 'super-team-'],
        'cursor' => ['mode' => 'native_dir', 'dir' => '.cursor/skills-cursor', 'prefix' => 'super-team-'],
        'qwen'   => ['mode' => 'native_dir', 'dir' => '.qwen/skills',          'prefix' => 'super-team-'],
        'copilot' => ['mode' => 'instructions', 'file' => '.copilot/super-team-skills.md'],
        // Legacy kimi-cli shape. The current kimi-code auto-discovers a real
        // skills dir (~/.kimi-code/skills, SKILL.md packs) — descriptor()
        // swaps this entry to native_dir when that layout is active.
        'kimi'    => ['mode' => 'instructions', 'file' => '.kimi/super-team-skills.md'],
        'kiro'    => ['mode' => 'instructions', 'file' => '.kiro/super-team-skills.md'],
        'claude'  => ['mode' => 'source'],
        // agy's extension surface is `agy plugin` (no verified writable
        // skills dir / instructions file yet) — nothing to bridge for now.
        'antigravity' => ['mode' => 'none'],
        'superagent' => ['mode' => 'none'],
    ];

    public function __construct(
        protected ?SkillLibrary $library = null,
    ) {
        if ($this->library === null && function_exists('app')) {
            try {
                if (app()->bound(SkillLibrary::class)) {
                    $this->library = app(SkillLibrary::class);
                }
            } catch (\Throwable) {
                // no host library bound — bridge stays a no-op
            }
        }
    }

    /** Is a host skill library available to bridge? */
    public function active(): bool
    {
        return $this->library instanceof SkillLibrary;
    }

    /**
     * Lazy on-dispatch entry point. Cheap: one fingerprint compare; only
     * re-installs the given backend when the library changed since last
     * sync. Safe to call before every CLI spawn.
     */
    /**
     * Live bridge descriptor for a backend. Identical to the BACKENDS
     * entry except for `kimi`, whose surface depends on which CLI
     * generation is installed: kimi-code gets a first-class skills dir,
     * legacy kimi-cli keeps the instructions-digest file.
     *
     * @return array{mode:string,dir?:string,file?:string,prefix?:string}|null
     */
    public function descriptor(string $backend): ?array
    {
        $desc = self::BACKENDS[$backend] ?? null;
        if ($backend === 'kimi' && $desc !== null && \SuperAICore\Support\KimiRuntime::isKimiCode()) {
            return [
                'mode'   => 'native_dir',
                'dir'    => \SuperAICore\Support\KimiRuntime::skillsRelPath(),
                'prefix' => 'super-team-',
            ];
        }
        return $desc;
    }

    public function ensureSynced(string $backend): void
    {
        if (!$this->active()) return;
        $desc = $this->descriptor($backend);
        if (!$desc || in_array($desc['mode'], ['source', 'none'], true)) return;
        try {
            if ($this->needsSync($backend)) {
                $this->syncBackend($backend);
            }
        } catch (\Throwable) {
            // never let a sync hiccup block a dispatch
        }
    }

    /** True when the backend has no stamp or its stamp != current fingerprint. */
    public function needsSync(string $backend): bool
    {
        if (!$this->active()) return false;
        $stamp = $this->readManifest($backend)['fingerprint'] ?? null;
        return $stamp !== $this->library->fingerprint();
    }

    /**
     * Sync every bridgeable backend. When $backends is null, uses every
     * key in BACKENDS whose mode is not source/none.
     *
     * @return array<int,array{backend:string,mode:string,installed:int,pruned:int,path:string,error:?string}>
     */
    public function syncAll(?array $backends = null): array
    {
        $backends ??= array_keys(array_filter(
            self::BACKENDS,
            fn ($d) => !in_array($d['mode'], ['source', 'none'], true),
        ));
        $report = [];
        foreach ($backends as $b) {
            $report[] = $this->syncBackend($b);
        }
        return $report;
    }

    /**
     * Install/refresh one backend. Returns a report row (never throws on
     * an individual skill failure — accumulates and reports).
     *
     * @return array{backend:string,mode:string,installed:int,pruned:int,path:string,error:?string}
     */
    public function syncBackend(string $backend): array
    {
        $row = ['backend' => $backend, 'mode' => '', 'installed' => 0, 'pruned' => 0, 'path' => '', 'error' => null];
        if (!$this->active()) {
            $row['error'] = 'no SkillLibrary bound';
            return $row;
        }
        $desc = $this->descriptor($backend);
        if (!$desc) { $row['error'] = 'unknown backend'; return $row; }
        $row['mode'] = $desc['mode'];
        if (in_array($desc['mode'], ['source', 'none'], true)) {
            return $row; // nothing to install
        }
        $home = self::home();
        if ($home === '') { $row['error'] = 'HOME unknown'; return $row; }

        try {
            if ($desc['mode'] === 'native_dir') {
                $this->syncNativeDir($backend, $desc, $home, $row);
            } elseif ($desc['mode'] === 'instructions') {
                $this->syncInstructions($backend, $desc, $home, $row);
            }
        } catch (\Throwable $e) {
            $row['error'] = $e->getMessage();
        }
        return $row;
    }

    // ─── native_dir mode ───────────────────────────────────────────────

    protected function syncNativeDir(string $backend, array $desc, string $home, array &$row): void
    {
        $prefix = $desc['prefix'] ?? 'super-team-';
        $dir = self::join($home, $desc['dir']);
        $row['path'] = $dir;
        $this->ensureRealDir($dir); // never write through a symlinked dir

        $prev = $this->readManifest($backend);
        $prevWrappers = (array) ($prev['wrappers'] ?? []);

        $wantNames = [];
        foreach ($this->library->skills() as $skill) {
            $name = (string) ($skill['name'] ?? '');
            if ($name === '') continue;
            $content = $this->library->skillWrapper($backend, $name);
            if ($content === '') continue;
            $wrapperName = $prefix . $name;
            $wantNames[] = $wrapperName;
            $this->writeWrapperDir(self::join($dir, $wrapperName), $content);
            $row['installed']++;
        }

        // Prune ONLY wrappers we installed before that are no longer wanted
        // (tracked in our manifest). Never touch the user's own skills.
        $want = array_flip($wantNames);
        foreach ($prevWrappers as $old) {
            if (!isset($want[$old]) && is_string($old) && str_starts_with($old, $prefix)) {
                $this->removePath(self::join($dir, $old));
                $row['pruned']++;
            }
        }

        $this->writeManifest($backend, $dir, ['wrappers' => $wantNames]);
    }

    // ─── instructions mode ─────────────────────────────────────────────

    protected function syncInstructions(string $backend, array $desc, string $home, array &$row): void
    {
        $file = self::join($home, $desc['file']);
        $row['path'] = $file;
        $digest = $this->library->instructionsDigest($backend);
        if ($digest === '') { $row['error'] = 'empty digest'; return; }
        $this->ensureRealDir(dirname($file));
        // symlink-safe: never write through a symlinked file
        if (is_link($file)) @unlink($file);
        @file_put_contents($file, $digest);
        $row['installed'] = 1;
        $this->writeManifest($backend, dirname($file), ['file' => basename($file)]);
    }

    // ─── safe filesystem primitives ────────────────────────────────────

    /**
     * Write a wrapper SKILL.md into `<wrapperDir>/SKILL.md`, guaranteeing
     * `$wrapperDir` is a REAL directory (not a symlink). This is the fix
     * for the write-through-symlink incident: a stale link is unlinked,
     * never followed.
     */
    protected function writeWrapperDir(string $wrapperDir, string $content): void
    {
        if (is_link($wrapperDir)) {
            @unlink($wrapperDir);            // drop the link, keep its target intact
        }
        if (!is_dir($wrapperDir)) {
            @mkdir($wrapperDir, 0755, true);
        }
        $skillFile = $wrapperDir . '/SKILL.md';
        if (is_link($skillFile)) @unlink($skillFile);
        @file_put_contents($skillFile, $content);
    }

    /** Ensure $dir exists as a real directory; replace a symlink in its place. */
    protected function ensureRealDir(string $dir): void
    {
        if (is_link($dir)) @unlink($dir);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
    }

    /** Remove a wrapper path we own (dir or file or link), recursively. */
    protected function removePath(string $path): void
    {
        if (is_link($path)) { @unlink($path); return; }
        if (is_file($path)) { @unlink($path); return; }
        if (is_dir($path)) {
            foreach ((array) @scandir($path) as $e) {
                if ($e === '.' || $e === '..') continue;
                $this->removePath($path . '/' . $e);
            }
            @rmdir($path);
        }
    }

    // ─── manifest (per-backend fingerprint stamp) ──────────────────────

    /** @return array<string,mixed> */
    protected function readManifest(string $backend): array
    {
        $path = $this->manifestPath($backend);
        if ($path === null || !is_file($path)) return [];
        $j = json_decode((string) @file_get_contents($path), true);
        return is_array($j) ? $j : [];
    }

    protected function writeManifest(string $backend, string $dir, array $extra): void
    {
        if (!$this->active()) return;
        $this->ensureRealDir($dir);
        $payload = array_merge([
            'fingerprint' => $this->library->fingerprint(),
            'backend'     => $backend,
            'skill_count' => count($this->library->skills()),
        ], $extra);
        $file = $dir . '/' . self::MANIFEST;
        if (is_link($file)) @unlink($file);
        @file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function manifestPath(string $backend): ?string
    {
        $desc = $this->descriptor($backend);
        if (!$desc) return null;
        $home = self::home();
        if ($home === '') return null;
        if ($desc['mode'] === 'native_dir') {
            return self::join($home, $desc['dir']) . '/' . self::MANIFEST;
        }
        if ($desc['mode'] === 'instructions') {
            return self::join($home, dirname($desc['file'])) . '/' . self::MANIFEST;
        }
        return null;
    }

    // ─── helpers ───────────────────────────────────────────────────────

    protected static function home(): string
    {
        return getenv('HOME') ?: (PHP_OS_FAMILY === 'Windows' ? (getenv('USERPROFILE') ?: '') : '');
    }

    protected static function join(string $base, string $rel): string
    {
        return rtrim($base, '/\\') . '/' . ltrim($rel, '/\\');
    }
}
