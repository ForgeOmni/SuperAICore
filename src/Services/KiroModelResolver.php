<?php

namespace SuperAICore\Services;

use Symfony\Component\Process\Process;

/**
 * AWS Kiro CLI model catalog — dynamically pulled from the CLI itself.
 *
 * Kiro is the only CLI engine in the matrix that exposes its authoritative
 * model list programmatically (`kiro-cli chat --list-models --format json`),
 * so this resolver does NOT carry a hard-coded catalog like its Claude /
 * Codex / Gemini / Copilot siblings. The flow is:
 *
 *   1. In-process memoized catalog — fastest, survives within a request.
 *   2. On-disk cache — `~/.cache/superaicore/kiro-models.json` (TTL 24h).
 *      Shared across CLI and web invocations on the same host.
 *   3. Live probe — spawn `kiro-cli chat --list-models --format json-pretty`,
 *      parse, persist to the cache file, return.
 *   4. Static fallback — the 12 models Kiro shipped at 0.6.2 cut. Only used
 *      when `kiro-cli` is missing from $PATH entirely (fresh install, or a
 *      host app running against a read-only filesystem).
 *
 * Kiro uses **dot-separated** versioning (`claude-sonnet-4.6`, not
 * `claude-sonnet-4-6`) — unlike Claude Code CLI. Passing a dash-format ID
 * results in a silent server-side rejection, so `resolve()` translates.
 *
 * Shape mirrors `CopilotModelResolver` / `ClaudeModelResolver` so any UI
 * code that iterates a resolver uniformly keeps working.
 */
class KiroModelResolver
{
    /** Cache TTL for the on-disk model catalog. */
    private const CACHE_TTL_SECONDS = 86400; // 24h

    private static ?array $memoCatalog = null;

    /**
     * Static fallback — the 12 models Kiro shipped at 0.6.2 cut. Kept in
     * sync with `kiro-cli chat --list-models` so builds without the CLI
     * installed still get a reasonable picker. NOT authoritative when the
     * CLI is available — the live catalog always wins.
     */
    private const STATIC_FALLBACK = [
        ['slug' => 'auto',             'display_name' => 'Auto (Kiro router picks the cheapest model)', 'family' => null],
        ['slug' => 'claude-opus-4.6',  'display_name' => 'Claude Opus 4.6',                              'family' => 'opus'],
        ['slug' => 'claude-sonnet-4.6','display_name' => 'Claude Sonnet 4.6 (1M context)',               'family' => 'sonnet'],
        ['slug' => 'claude-opus-4.5',  'display_name' => 'Claude Opus 4.5',                              'family' => 'opus'],
        ['slug' => 'claude-sonnet-4.5','display_name' => 'Claude Sonnet 4.5',                            'family' => 'sonnet'],
        ['slug' => 'claude-sonnet-4',  'display_name' => 'Claude Sonnet 4',                              'family' => 'sonnet'],
        ['slug' => 'claude-haiku-4.5', 'display_name' => 'Claude Haiku 4.5',                             'family' => 'haiku'],
        ['slug' => 'deepseek-3.2',     'display_name' => 'DeepSeek V3.2 (preview)',                      'family' => 'deepseek'],
        ['slug' => 'minimax-m2.5',     'display_name' => 'MiniMax M2.5',                                 'family' => 'minimax'],
        ['slug' => 'minimax-m2.1',     'display_name' => 'MiniMax M2.1 (preview)',                       'family' => 'minimax'],
        ['slug' => 'glm-5',            'display_name' => 'GLM-5',                                        'family' => 'glm'],
        ['slug' => 'qwen3-coder-next', 'display_name' => 'Qwen3 Coder Next (preview)',                   'family' => 'qwen'],
    ];

    /**
     * Family → latest full model ID. Computed from the catalog: the first
     * entry in each family wins, matching the order kiro-cli returns
     * (newest-first within a family as of 2026-04).
     *
     * @return array<string,string>
     */
    public static function families(): array
    {
        $map = [];
        foreach (self::catalog() as $entry) {
            $family = $entry['family'] ?? null;
            if ($family && !isset($map[$family])) {
                $map[$family] = $entry['slug'];
            }
        }
        return $map;
    }

    /**
     * Ordered catalog, newest-first within each family. Shape mirrors
     * `ClaudeModelResolver::catalog()` — each row is
     * `['slug' => string, 'display_name' => string, 'family' => ?string]`.
     */
    public static function catalog(): array
    {
        if (self::$memoCatalog !== null) {
            return self::$memoCatalog;
        }

        // 1. Fresh on-disk cache wins over a CLI spawn.
        $cached = self::readCache();
        if ($cached !== null) {
            return self::$memoCatalog = $cached;
        }

        // 2. Live probe.
        $probed = self::probeCli();
        if ($probed !== null) {
            self::writeCache($probed);
            return self::$memoCatalog = $probed;
        }

        // 3. Static fallback — kiro-cli missing or malformed.
        return self::$memoCatalog = self::STATIC_FALLBACK;
    }

    public static function defaultFor(string $family): ?string
    {
        return self::families()[$family] ?? null;
    }

    /**
     * Resolve a family alias or foreign model name to the concrete ID
     * `kiro-cli chat --model` accepts.
     *
     *   resolve('sonnet')                → 'claude-sonnet-4.6'  (family default)
     *   resolve('claude-sonnet-4-6')     → 'claude-sonnet-4.6'  (dash → dot)
     *   resolve('claude-sonnet-4-7')     → 'claude-sonnet-4.6'  (fallback: 4.7 not in kiro yet)
     *   resolve('claude-opus-4-7[1m]')   → 'claude-opus-4.6'    (strip [1m] + fallback)
     *   resolve('claude-sonnet-4.5')     → 'claude-sonnet-4.5'  (already valid)
     *   resolve('auto')                  → 'auto'               (routing primitive)
     *   resolve(null)                    → null                 (caller uses engine default)
     */
    public static function resolve(?string $model): ?string
    {
        if ($model === null || $model === '') {
            return null;
        }

        $families = self::families();
        if (isset($families[$model])) {
            return $families[$model];
        }

        // Strip Claude-CLI's `[1m]`-style context suffix and any trailing
        // `-YYYYMMDD` date stamp.
        $stripped = preg_replace('/\[[^\]]+\]$/', '', $model);
        $stripped = preg_replace('/-\d{6,8}$/', '', $stripped);

        if (self::inCatalog($stripped)) {
            return $stripped;
        }

        // `claude-sonnet-4-6` → `claude-sonnet-4.6`
        $dot = self::dashToDot($stripped);
        if ($dot !== null && self::inCatalog($dot)) {
            return $dot;
        }

        $family = self::familyFromName($stripped);
        if ($family !== null && isset($families[$family])) {
            return $families[$family];
        }

        // Give up — pass through and let kiro-cli error with its own message.
        return $model;
    }

    /**
     * Force a refresh of the on-disk cache — spawns the CLI probe and
     * writes the result regardless of cache freshness. Returns the fresh
     * catalog, or null when the probe failed (in which case the old
     * cache or static fallback is untouched and remains in effect for
     * subsequent `catalog()` calls).
     *
     * Exposed so a host app or a scheduled job (`super-ai-core:models
     * update`) can refresh without waiting for the TTL.
     */
    public static function refresh(): ?array
    {
        $probed = self::probeCli();
        if ($probed !== null) {
            self::writeCache($probed);
            self::$memoCatalog = $probed;
        }
        return $probed;
    }

    /**
     * Parse the JSON body emitted by `kiro-cli chat --list-models --format
     * json-pretty`. Public for testing — accepts the raw stdout string and
     * returns a normalized catalog or null on malformed input.
     *
     * @return array<int,array{slug:string,display_name:string,family:?string}>|null
     */
    public static function parseListModels(string $json): ?array
    {
        $payload = json_decode($json, true);
        if (!is_array($payload) || !isset($payload['models']) || !is_array($payload['models'])) {
            return null;
        }

        $out = [];
        foreach ($payload['models'] as $row) {
            $id = (string) ($row['model_id'] ?? $row['model_name'] ?? '');
            if ($id === '') continue;
            $description = trim((string) ($row['description'] ?? ''));
            $out[] = [
                'slug'         => $id,
                'display_name' => $description !== '' ? $description : $id,
                'family'       => self::familyFromName($id),
            ];
        }
        return $out !== [] ? $out : null;
    }

    // ────────────────────────────────────────────────────────────────────
    // Internals
    // ────────────────────────────────────────────────────────────────────

    private static function probeCli(): ?array
    {
        $binary = self::binary();
        // Probe the version first — if `kiro-cli` isn't on PATH at all we
        // don't want to wait on the chat subcommand (which spins up the
        // agent runtime).
        try {
            $which = new Process(['which', $binary]);
            $which->run();
            if (!$which->isSuccessful()) return null;

            $probe = new Process([$binary, 'chat', '--list-models', '--format', 'json-pretty']);
            $probe->setTimeout(15);
            $probe->run();
            if (!$probe->isSuccessful()) return null;

            return self::parseListModels($probe->getOutput());
        } catch (\Throwable) {
            return null;
        }
    }

    private static function readCache(): ?array
    {
        $path = self::cachePath();
        if (!is_file($path)) return null;
        $mtime = @filemtime($path);
        if ($mtime === false || (time() - $mtime) > self::CACHE_TTL_SECONDS) return null;

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') return null;
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['catalog']) || !is_array($decoded['catalog'])) return null;

        return $decoded['catalog'];
    }

    private static function writeCache(array $catalog): void
    {
        $path = self::cachePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $payload = [
            'fetched_at' => date('c'),
            'catalog'    => $catalog,
        ];
        @file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private static function cachePath(): string
    {
        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: sys_get_temp_dir());
        return rtrim($home, '/') . '/.cache/superaicore/kiro-models.json';
    }

    private static function binary(): string
    {
        return getenv('KIRO_CLI_BIN') ?: 'kiro-cli';
    }

    private static function inCatalog(string $slug): bool
    {
        foreach (self::catalog() as $entry) {
            if (($entry['slug'] ?? null) === $slug) return true;
        }
        return false;
    }

    private static function dashToDot(string $slug): ?string
    {
        if (preg_match('/^(.*)-(\d+)-(\d+)(?:-\d+)?$/', $slug, $m)) {
            return "{$m[1]}-{$m[2]}.{$m[3]}";
        }
        return null;
    }

    private static function familyFromName(string $slug): ?string
    {
        if (str_contains($slug, 'sonnet'))        return 'sonnet';
        if (str_contains($slug, 'opus'))          return 'opus';
        if (str_contains($slug, 'haiku'))         return 'haiku';
        if (str_starts_with($slug, 'deepseek'))   return 'deepseek';
        if (str_starts_with($slug, 'minimax'))    return 'minimax';
        if (str_starts_with($slug, 'glm'))        return 'glm';
        if (str_starts_with($slug, 'qwen'))       return 'qwen';
        return null;
    }
}
