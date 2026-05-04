<?php

namespace SuperAICore\Sync;

/**
 * Projects a host-app hooks manifest into Claude Code's
 * `.claude/settings.json` `hooks` block.
 *
 * Only useful when the source manifest lives somewhere OTHER than
 * `.claude/settings.json` itself (e.g. `<project>/.superaicore/hooks.json`,
 * a host-app DB row, a multi-tenant template). When the host is already
 * editing `.claude/settings.json` directly, this writer is a no-op against
 * its own input — so a `hooks:sync --source .claude/settings.json --to claude`
 * still works and just reports `unchanged`.
 *
 * Mirrors `CopilotHookWriter` (single-key merge into a JSON file owned by
 * the engine) — see that class for the full design rationale. Differences:
 *   - target: `<project>/.claude/settings.json` (project scope, not user)
 *   - manifest key: `__claude_hooks__`
 *   - settings.json may carry many other host-managed keys (model,
 *     permissions, env, mcpServers, …); we only touch `hooks`.
 */
final class ClaudeHookWriter implements HookWriterInterface
{
    public const STATUS_WRITTEN     = 'written';
    public const STATUS_UNCHANGED   = 'unchanged';
    public const STATUS_USER_EDITED = 'user_edited';
    public const STATUS_CLEARED     = 'cleared';

    private const MANIFEST_KEY = '__claude_hooks__';

    public function __construct(
        private readonly string $settingsJsonPath,
        private readonly Manifest $manifest,
    ) {}

    public function engineKey(): string
    {
        return 'claude';
    }

    public function isAvailable(): bool
    {
        // Available whenever the parent dir exists or can be created. We
        // don't probe for the `claude` binary itself — a host might pre-
        // populate `.claude/` before Claude Code is installed.
        $dir = dirname($this->settingsJsonPath);
        return is_dir($dir) || @mkdir($dir, 0755, true) || is_dir($dir);
    }

    public function sync(?array $hooks): array
    {
        $current = $this->readSettings();
        $previous = $this->manifest->read()[self::MANIFEST_KEY] ?? null;
        $currentHooks = $current['hooks'] ?? null;

        $currentHash = $currentHooks !== null
            ? hash('sha256', $this->canonicalize($currentHooks))
            : null;

        if ($currentHash !== null && $previous !== null && $currentHash !== $previous) {
            return ['status' => self::STATUS_USER_EDITED, 'path' => $this->settingsJsonPath];
        }

        $want = $hooks ?: null;

        if ($want === null) {
            if (!array_key_exists('hooks', $current)) {
                return ['status' => self::STATUS_UNCHANGED, 'path' => $this->settingsJsonPath];
            }
            unset($current['hooks']);
            $this->writeSettings($current);
            $this->persistManifest(null);
            return ['status' => self::STATUS_CLEARED, 'path' => $this->settingsJsonPath];
        }

        $wantHash = hash('sha256', $this->canonicalize($want));
        if ($currentHash === $wantHash) {
            $this->persistManifest($wantHash);
            return ['status' => self::STATUS_UNCHANGED, 'path' => $this->settingsJsonPath];
        }

        $current['hooks'] = $want;
        $this->writeSettings($current);
        $this->persistManifest($wantHash);

        return ['status' => self::STATUS_WRITTEN, 'path' => $this->settingsJsonPath];
    }

    private function readSettings(): array
    {
        if (!is_file($this->settingsJsonPath)) return [];
        $raw = @file_get_contents($this->settingsJsonPath);
        if ($raw === false || $raw === '') return [];
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function writeSettings(array $settings): void
    {
        $dir = dirname($this->settingsJsonPath);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @file_put_contents(
            $this->settingsJsonPath,
            json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
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

    private function canonicalize(array $hooks): string
    {
        $norm = $this->recursiveKsort($hooks);
        return json_encode($norm, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function recursiveKsort(array $data): array
    {
        if (array_is_list($data)) {
            return array_map(fn ($v) => is_array($v) ? $this->recursiveKsort($v) : $v, $data);
        }
        ksort($data);
        foreach ($data as $k => $v) {
            if (is_array($v)) $data[$k] = $this->recursiveKsort($v);
        }
        return $data;
    }
}
