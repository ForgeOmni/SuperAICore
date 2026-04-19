<?php

namespace SuperAICore\Sync;

/**
 * Syncs a host-app's Claude Code-style `hooks` block into Copilot's
 * `config.json`. Copilot accepts PascalCase event names
 * (`PreToolUse`/`PostToolUse`/`SessionStart`/…) verbatim and delivers
 * the VS Code / Claude-compatible snake_case payload to the hook
 * script — no translation needed beyond placement.
 *
 * Design:
 *   - Source: an array of Claude-style hooks (read from
 *     `.claude/settings.json.hooks` or a host-app equivalent).
 *   - Target: `$copilotHome/config.json`, a JSON file Copilot owns.
 *     We merge our `hooks` key into whatever else lives there
 *     (trustedFolders, banner, firstLaunchAt, …) without disturbing
 *     the user's other settings.
 *   - Idempotence: the payload we write is deterministic, so running
 *     `sync` twice is a no-op.
 *   - User-edits: if the user has hand-edited their hooks section in
 *     config.json, we detect the drift via the manifest's previous
 *     hash and report `STATUS_USER_EDITED` instead of clobbering.
 *
 * Why not `.github/hooks/*.json` per-hook files: those live in a git
 * repo and would get committed. Host apps typically want a user-level,
 * repo-agnostic surface — config.json fits that.
 */
final class CopilotHookWriter
{
    public const STATUS_WRITTEN     = 'written';
    public const STATUS_UNCHANGED   = 'unchanged';
    public const STATUS_USER_EDITED = 'user_edited';
    public const STATUS_CLEARED     = 'cleared';

    /** Manifest key used to track the last payload we wrote. */
    private const MANIFEST_KEY = '__copilot_hooks__';

    public function __construct(
        private readonly string $configJsonPath,
        private readonly Manifest $manifest,
    ) {}

    /**
     * Merge the given hooks block into Copilot's config.json. Pass
     * `$hooks = []` (or null) to request deletion of the previously-
     * written block (cleanup path).
     *
     * @param array<string,array<int,array<string,mixed>>>|null $hooks Claude-style hooks map
     * @return array{status:string, path:string}
     */
    public function sync(?array $hooks): array
    {
        $current = $this->readConfig();
        $previous = $this->manifest->read()[self::MANIFEST_KEY] ?? null;
        $currentHooks = $current['hooks'] ?? null;

        $currentHash = $currentHooks !== null
            ? hash('sha256', $this->canonicalize($currentHooks))
            : null;

        // Detect user edits: the hooks block on disk is non-null AND
        // differs from what we recorded last time.
        if ($currentHash !== null && $previous !== null && $currentHash !== $previous) {
            return ['status' => self::STATUS_USER_EDITED, 'path' => $this->configJsonPath];
        }

        $want = $hooks ?: null;

        if ($want === null) {
            if (!array_key_exists('hooks', $current)) {
                return ['status' => self::STATUS_UNCHANGED, 'path' => $this->configJsonPath];
            }
            unset($current['hooks']);
            $this->writeConfig($current);
            $this->persistManifest(null);
            return ['status' => self::STATUS_CLEARED, 'path' => $this->configJsonPath];
        }

        $wantHash = hash('sha256', $this->canonicalize($want));
        if ($currentHash === $wantHash) {
            // Make sure the manifest catches up even if it was missing
            $this->persistManifest($wantHash);
            return ['status' => self::STATUS_UNCHANGED, 'path' => $this->configJsonPath];
        }

        $current['hooks'] = $want;
        $this->writeConfig($current);
        $this->persistManifest($wantHash);

        return ['status' => self::STATUS_WRITTEN, 'path' => $this->configJsonPath];
    }

    /** Read the config.json file. Returns [] when missing. */
    private function readConfig(): array
    {
        if (!is_file($this->configJsonPath)) return [];
        $raw = @file_get_contents($this->configJsonPath);
        if ($raw === false || $raw === '') return [];
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function writeConfig(array $config): void
    {
        $dir = dirname($this->configJsonPath);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @file_put_contents(
            $this->configJsonPath,
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        );
    }

    private function persistManifest(?string $hash): void
    {
        $entries = $this->manifest->read();
        if ($hash === null) {
            unset($entries[self::MANIFEST_KEY]);
        } else {
            $entries[self::MANIFEST_KEY] = $hash;
        }
        $this->manifest->write($entries);
    }

    /**
     * Key-sort the hooks payload so the sha256 is invariant to PHP's
     * associative-array ordering. Without this, the same config
     * written by different host apps could show up as "drift".
     */
    private function canonicalize(array $hooks): string
    {
        $norm = $this->recursiveKsort($hooks);
        return json_encode($norm, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function recursiveKsort(array $data): array
    {
        if (array_is_list($data)) {
            return array_map(fn($v) => is_array($v) ? $this->recursiveKsort($v) : $v, $data);
        }
        ksort($data);
        foreach ($data as $k => $v) {
            if (is_array($v)) $data[$k] = $this->recursiveKsort($v);
        }
        return $data;
    }

    /**
     * Read a Claude-style hooks block out of a settings.json-shaped file.
     * Returns null when the file is missing or has no hooks block —
     * callers can treat that as "no hooks to sync" vs. "clear existing".
     *
     * @return array<string,array<int,array<string,mixed>>>|null
     */
    public static function readFromSettings(string $settingsPath): ?array
    {
        if (!is_file($settingsPath)) return null;
        $raw = @file_get_contents($settingsPath);
        if ($raw === false || $raw === '') return null;
        $data = json_decode($raw, true);
        if (!is_array($data)) return null;
        $hooks = $data['hooks'] ?? null;
        return is_array($hooks) ? $hooks : null;
    }
}
