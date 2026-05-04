<?php

namespace SuperAICore\Sync;

/**
 * Reads ONE Claude-style hooks manifest and fans it out to every
 * registered `HookWriterInterface`.
 *
 * Source-of-truth philosophy: a host app maintains hooks in a single
 * place — typically `<project>/.claude/settings.json` (the file Claude
 * Code itself reads), or a host-managed override. From there the
 * fanout projects into Copilot's `config.json`, and any future engine
 * that grows a hook concept (Kiro / Kimi / …) joins by registering a
 * writer in a ServiceProvider — no command changes required.
 *
 * Engines without native hook support (Codex / Gemini as of 2026-05)
 * are simply not registered. Calling `sync()` against an unknown
 * engine key returns `status: unknown` so callers can distinguish
 * "engine not configured" from "engine config dir missing"
 * (`status: unavailable`).
 */
final class HookFanoutService
{
    /** @var array<string, HookWriterInterface> engineKey => writer */
    private array $writers = [];

    public function register(HookWriterInterface $writer): void
    {
        $this->writers[$writer->engineKey()] = $writer;
    }

    /** @return string[] */
    public function engines(): array
    {
        return array_keys($this->writers);
    }

    /**
     * Fan a hooks block out to every registered writer.
     *
     * @param  array<string, array<int, array<string, mixed>>>|null $hooks
     *         Pass null to clear previously-written hooks on every engine.
     * @param  string[]|null $only Engine keys to limit the fanout to.
     *         Unknown keys produce `status: unknown` rows so callers can
     *         spot typos.
     * @return array<string, array{status:string, path:?string}>
     */
    public function sync(?array $hooks, ?array $only = null): array
    {
        $report = [];

        if ($only !== null) {
            foreach ($only as $key) {
                if (!isset($this->writers[$key])) {
                    $report[$key] = ['status' => 'unknown', 'path' => null];
                }
            }
        }

        foreach ($this->writers as $key => $writer) {
            if ($only !== null && !in_array($key, $only, true)) {
                continue;
            }
            if (!$writer->isAvailable()) {
                $report[$key] = ['status' => 'unavailable', 'path' => null];
                continue;
            }
            $report[$key] = $writer->sync($hooks);
        }

        return $report;
    }

    /**
     * Read a Claude-style hooks block out of any settings.json-shaped file.
     * Resolution order:
     *   1. The literal $sourcePath if given and present.
     *   2. `<cwd>/.superaicore/hooks.json` (host-managed override file).
     *   3. `<cwd>/.claude/settings.json` (Claude Code's own source).
     *
     * Returns [path, hooks] tuple. `hooks === null` means "no source
     * found — caller should treat as no-op rather than as a clear".
     *
     * @return array{0:?string, 1:array<string, mixed>|null}
     */
    public static function resolveSource(?string $sourcePath, string $cwd): array
    {
        $candidates = [];
        if ($sourcePath !== null && $sourcePath !== '') {
            $candidates[] = $sourcePath;
        } else {
            $candidates[] = rtrim($cwd, '/\\') . DIRECTORY_SEPARATOR . '.superaicore' . DIRECTORY_SEPARATOR . 'hooks.json';
            $candidates[] = rtrim($cwd, '/\\') . DIRECTORY_SEPARATOR . '.claude' . DIRECTORY_SEPARATOR . 'settings.json';
        }

        foreach ($candidates as $path) {
            if (!is_file($path)) continue;
            $raw = @file_get_contents($path);
            if ($raw === false || $raw === '') continue;
            $data = json_decode($raw, true);
            if (!is_array($data)) continue;

            // Two source shapes are accepted:
            //   1. settings.json-style — top-level `hooks` key.
            //   2. bare hooks.json — object IS the hooks map directly
            //      (PreToolUse / PostToolUse / … as top-level keys).
            $hooks = $data['hooks'] ?? null;
            if (is_array($hooks)) {
                return [$path, $hooks];
            }
            if (self::looksLikeBareHooksMap($data)) {
                return [$path, $data];
            }
        }

        return [null, null];
    }

    /**
     * Heuristic: top-level keys are Claude hook event names → treat as a
     * bare hooks map (the shape we accept in `.superaicore/hooks.json`).
     */
    private static function looksLikeBareHooksMap(array $data): bool
    {
        static $events = [
            'PreToolUse',
            'PostToolUse',
            'PreCompact',
            'Stop',
            'SessionStart',
            'SessionEnd',
            'UserPromptSubmit',
        ];
        foreach (array_keys($data) as $k) {
            if (!is_string($k)) return false;
            if (!in_array($k, $events, true)) return false;
        }
        return $data !== [];
    }
}
