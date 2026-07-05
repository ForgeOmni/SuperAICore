<?php

namespace SuperAICore\Support;

/**
 * User-maintained, natural-language dispatch preferences — ai-dispatch
 * parity (`~/.ai-dispatch/preferences.md`).
 *
 * The file is read by the CALLING agent (via the superaicore-dispatch
 * SKILL or `superaicore preferences show`) before it picks a `send`
 * target — the scenario→model intelligence deliberately lives in prose
 * at the agent layer, not in code. SuperAICore itself never parses it.
 *
 * Path resolution:
 *   1. `super-ai-core.dispatch.preferences_path` config (Laravel host)
 *   2. `AI_CORE_PREFERENCES_PATH` env
 *   3. `~/.superaicore/preferences.md`
 */
final class DispatchPreferences
{
    public static function path(): string
    {
        $configured = ConfigValue::get('super-ai-core.dispatch.preferences_path')
            ?: (getenv('AI_CORE_PREFERENCES_PATH') ?: null);

        return $configured
            ?? rtrim((string) (getenv('HOME') ?: sys_get_temp_dir()), '/') . '/.superaicore/preferences.md';
    }

    public static function exists(): bool
    {
        return is_file(self::path());
    }

    public static function read(): ?string
    {
        $path = self::path();
        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    /**
     * Write the starter template unless the file already exists.
     * Returns true when a file was created.
     */
    public static function init(): bool
    {
        $path = self::path();
        if (is_file($path)) return false;
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        return @file_put_contents($path, self::template()) !== false;
    }

    public static function template(): string
    {
        return <<<'MD'
# SuperAICore dispatch preferences

Agents MUST read this file before choosing a `superaicore send` target.
When the user explicitly names a target or model, the user's choice wins.

Alias → backend/model routing lives in `superaicore aliases` (built-ins +
`super-ai-core.dispatch.aliases` config). Keep this file short and current —
it is prose for the calling agent, never parsed by SuperAICore itself.

## Model leanings

Write recurring model preferences worth remembering here.

## Scenario picks

### Review

**Candidate pool**: e.g. opus, gemini-pro

### Implementation

**Candidate pool**: e.g. codex, sonnet

### Bug hunting

**Candidate pool**:

### Frontend / UI

**Candidate pool**:

### Docs

**Candidate pool**:
MD . "\n";
    }
}
