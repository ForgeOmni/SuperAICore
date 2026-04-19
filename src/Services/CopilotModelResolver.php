<?php

namespace SuperAICore\Services;

/**
 * Canonical GitHub Copilot CLI model catalog for aicore.
 *
 * The copilot CLI (github/copilot-cli) accepts a fixed set of model IDs
 * via `--model`. Its identifiers use **dot** separators (e.g.
 * `claude-sonnet-4.5`, `gpt-5.1`) — unlike the Anthropic Claude CLI which
 * uses **dash** separators (`claude-sonnet-4-6`). When a host pipes a
 * Claude-shaped model name through the copilot backend, it silently
 * rejects it with "Model '...' from --model flag is not available" and
 * exits 1 with no assistant output.
 *
 * This resolver bridges the gap:
 *   - `resolve()` translates Claude dash format → dot format and falls
 *     back to the family default when an explicit version isn't in
 *     copilot's supported list yet (so asking for `claude-sonnet-4-7`
 *     quietly downgrades to the latest available `claude-sonnet-*`).
 *   - `FAMILIES` + `CATALOG` mirror `ClaudeModelResolver` so EngineCatalog
 *     can render a family-aware dropdown.
 *
 * Keep this file up to date when copilot CLI adds new routed models.
 * Last verified against copilot CLI 1.0.32 (2026-04-19).
 */
class CopilotModelResolver
{
    /**
     * Short alias → latest full model ID routed by copilot.
     */
    const FAMILIES = [
        // Claude — copilot routes these server-side via a GitHub subscription.
        'sonnet' => 'claude-sonnet-4.6',
        'opus'   => 'claude-opus-4.5',
        'haiku'  => 'claude-haiku-4.5',
        // OpenAI — copilot ships its own gpt-5 lineup.
        'gpt'    => 'gpt-5.1',
    ];

    /**
     * Ordered user-facing catalog, newest-first per family.
     * Shape mirrors ClaudeModelResolver::catalog() so UI code can
     * iterate both resolvers uniformly.
     */
    const CATALOG = [
        // Claude — Sonnet
        ['slug' => 'claude-sonnet-4.6', 'display_name' => 'Sonnet 4.6', 'family' => 'sonnet'],
        ['slug' => 'claude-sonnet-4.5', 'display_name' => 'Sonnet 4.5', 'family' => 'sonnet'],
        ['slug' => 'claude-sonnet-4',   'display_name' => 'Sonnet 4',   'family' => 'sonnet'],
        // Claude — Opus
        ['slug' => 'claude-opus-4.6',   'display_name' => 'Opus 4.6',   'family' => 'opus'],
        ['slug' => 'claude-opus-4.5',   'display_name' => 'Opus 4.5',   'family' => 'opus'],
        // Claude — Haiku
        ['slug' => 'claude-haiku-4.5',  'display_name' => 'Haiku 4.5',  'family' => 'haiku'],
        // OpenAI
        ['slug' => 'gpt-5.1',           'display_name' => 'GPT-5.1',          'family' => 'gpt'],
        ['slug' => 'gpt-5.1-codex',     'display_name' => 'GPT-5.1 Codex',    'family' => 'gpt'],
        ['slug' => 'gpt-5.1-codex-mini','display_name' => 'GPT-5.1 Codex Mini','family' => 'gpt'],
        ['slug' => 'gpt-5',             'display_name' => 'GPT-5',            'family' => 'gpt'],
        ['slug' => 'gpt-5-mini',        'display_name' => 'GPT-5 mini',       'family' => 'gpt'],
        ['slug' => 'gpt-4.1',           'display_name' => 'GPT-4.1',          'family' => 'gpt'],
        // Gemini (preview, gated)
        ['slug' => 'gemini-3-pro-preview', 'display_name' => 'Gemini 3 Pro (preview)', 'family' => 'gemini'],
    ];

    /**
     * Resolve a family alias or a foreign (e.g. Claude-CLI dash) model
     * name into the concrete ID the copilot CLI accepts.
     *
     *   resolve('sonnet')                → 'claude-sonnet-4.6'  (family default)
     *   resolve('claude-sonnet-4-6')     → 'claude-sonnet-4.6'  (dash → dot)
     *   resolve('claude-sonnet-4-7')     → 'claude-sonnet-4.6'  (fallback: 4.7 not in copilot yet)
     *   resolve('claude-opus-4-7[1m]')   → 'claude-opus-4.5'    (strip context tag + fallback)
     *   resolve('claude-sonnet-4.5')     → 'claude-sonnet-4.5'  (already valid, passthrough)
     *   resolve('gpt-5.1')               → 'gpt-5.1'            (passthrough)
     *   resolve(null)                    → null                 (caller uses engine default)
     */
    public static function resolve(?string $model): ?string
    {
        if ($model === null || $model === '') {
            return null;
        }

        // 1. Family alias (sonnet/opus/haiku/gpt).
        if (isset(self::FAMILIES[$model])) {
            return self::FAMILIES[$model];
        }

        // 2. Strip Claude-CLI's `[1m]`-style context suffix and any
        //    trailing `-YYYYMMDD` date stamp (`claude-sonnet-4-5-20241022`
        //    → `claude-sonnet-4-5`) — copilot doesn't expose either.
        $stripped = preg_replace('/\[[^\]]+\]$/', '', $model);
        $stripped = preg_replace('/-\d{6,8}$/', '', $stripped);

        // 3. Exact match against the catalog (covers dot-format inputs).
        if (self::inCatalog($stripped)) {
            return $stripped;
        }

        // 4. Translate Claude-CLI dash format → dot format.
        //    `claude-sonnet-4-6` → `claude-sonnet-4.6`
        //    `claude-sonnet-4-5-20241022` → `claude-sonnet-4.5` (drop date suffix)
        $dot = self::dashToDot($stripped);
        if ($dot !== null && self::inCatalog($dot)) {
            return $dot;
        }

        // 5. Fallback to the family default for a Claude-shaped model.
        $family = self::familyFromName($stripped);
        if ($family !== null && isset(self::FAMILIES[$family])) {
            return self::FAMILIES[$family];
        }

        // 6. Give up — pass the original through and let copilot error out
        //    with its own message. Better than silently substituting.
        return $model;
    }

    public static function defaultFor(string $family): ?string
    {
        return self::FAMILIES[$family] ?? null;
    }

    public static function catalog(): array
    {
        return self::CATALOG;
    }

    /** @return string[] */
    public static function families(): array
    {
        return array_keys(self::FAMILIES);
    }

    private static function inCatalog(string $slug): bool
    {
        foreach (self::CATALOG as $entry) {
            if ($entry['slug'] === $slug) return true;
        }
        return false;
    }

    /**
     * `claude-sonnet-4-6` → `claude-sonnet-4.6`
     * `claude-sonnet-4-5-20241022` → `claude-sonnet-4.5`
     * `foo-bar` → null (no version-looking tail to convert)
     */
    private static function dashToDot(string $slug): ?string
    {
        // Match `<family-prefix>-<major>-<minor>[-<date>]`
        if (preg_match('/^(.*)-(\d+)-(\d+)(?:-\d+)?$/', $slug, $m)) {
            return "{$m[1]}-{$m[2]}.{$m[3]}";
        }
        return null;
    }

    private static function familyFromName(string $slug): ?string
    {
        if (str_contains($slug, 'sonnet')) return 'sonnet';
        if (str_contains($slug, 'opus'))   return 'opus';
        if (str_contains($slug, 'haiku'))  return 'haiku';
        if (str_starts_with($slug, 'gpt')) return 'gpt';
        return null;
    }
}
