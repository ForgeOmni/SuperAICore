<?php

namespace SuperAICore\Services;

/**
 * Canonical GitHub Copilot CLI model catalog for aicore.
 *
 * The copilot CLI (github/copilot-cli) accepts a fixed set of model IDs
 * via `--model`. Its identifiers use **dot** separators (e.g.
 * `claude-sonnet-4.5`, `gpt-5.4`) — unlike the Anthropic Claude CLI which
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
 * Last verified against copilot CLI 1.0.71 (2026-07-19). Roster source:
 * GitHub's supported-models reference, "Copilot CLI" column (parsed from
 * the live per-client table), cross-checked with the CLI changelog
 * (Claude Fable 5 in 1.0.61, Claude Sonnet 5 in 1.0.67, kimi-k2.7-code
 * in 1.0.68, GPT-5.6 in 1.0.70). gpt-5.1 was retired upstream on
 * 2026-04-15 (resolve() degrades it to the gpt family default).
 * Since ~1.0.64 the CLI also accepts family aliases (`sonnet`, `opus`,
 * `haiku`, `gpt`, `gemini`) natively, so an unresolved alias reaching the
 * binary is no longer fatal — this resolver still maps them so hosts see
 * the concrete slug (cost rows, dropdowns) instead of an alias.
 *
 * Slug caveats (plan-gated accounts can't live-verify premium slugs —
 * an off-plan model and an unknown slug fail with the same message):
 * the GPT-5.6 SKUs ship as three named builds (Luna/Sol/Terra); their
 * dot ids follow the display names (`gpt-5.6-sol`, …) per the same
 * convention codex uses. "Claude Opus 4.8 (fast mode)" is preview-only
 * with no published slug — deliberately absent below.
 *
 * Note: model *availability* is plan-gated server-side. Free/Student
 * plans only route Auto/mini SKUs; `--model` outside the plan fails with
 * "Model '...' from --model flag is not available" — same message as an
 * unknown slug. Docs-listed SKUs outside Copilot CLI's column
 * (raptor-mini, gpt-5.4-nano, gemini-2.5-pro, gemini-3-flash) pass
 * through resolve() untouched and fail with the CLI's own error.
 */
class CopilotModelResolver
{
    /**
     * Short alias → latest full model ID routed by copilot.
     */
    const FAMILIES = [
        // Claude — copilot routes these server-side via a GitHub subscription.
        'sonnet' => 'claude-sonnet-5',
        'fable'  => 'claude-fable-5',
        'opus'   => 'claude-opus-4.8',
        'haiku'  => 'claude-haiku-4.5',
        // OpenAI — copilot ships its own gpt-5 lineup (Sol is the
        // balanced default of the 5.6 trio, mirroring codex's default).
        'gpt'    => 'gpt-5.6-sol',
        // Gemini — flash is the routed flagship; 3.1 Pro also available.
        'gemini' => 'gemini-3.5-flash',
        // Moonshot — single routed SKU, added copilot 1.0.68.
        'kimi'   => 'kimi-k2.7-code',
        // Microsoft — single routed SKU.
        'mai'    => 'mai-code-1-flash',
    ];

    /**
     * Ordered user-facing catalog, newest-first per family.
     * Shape mirrors ClaudeModelResolver::catalog() so UI code can
     * iterate both resolvers uniformly.
     */
    const CATALOG = [
        // Claude — Sonnet
        ['slug' => 'claude-sonnet-5',   'display_name' => 'Sonnet 5',   'family' => 'sonnet'],
        ['slug' => 'claude-sonnet-4.6', 'display_name' => 'Sonnet 4.6', 'family' => 'sonnet'],
        ['slug' => 'claude-sonnet-4.5', 'display_name' => 'Sonnet 4.5', 'family' => 'sonnet'],
        // Claude — Fable (Mythos-class tier above Opus, copilot 1.0.61+)
        ['slug' => 'claude-fable-5',    'display_name' => 'Fable 5',    'family' => 'fable'],
        // Claude — Opus
        ['slug' => 'claude-opus-4.8',   'display_name' => 'Opus 4.8',   'family' => 'opus'],
        ['slug' => 'claude-opus-4.7',   'display_name' => 'Opus 4.7',   'family' => 'opus'],
        ['slug' => 'claude-opus-4.6',   'display_name' => 'Opus 4.6',   'family' => 'opus'],
        ['slug' => 'claude-opus-4.5',   'display_name' => 'Opus 4.5',   'family' => 'opus'],
        // Claude — Haiku
        ['slug' => 'claude-haiku-4.5',  'display_name' => 'Haiku 4.5',  'family' => 'haiku'],
        // OpenAI (gpt-5.1 retired upstream 2026-04-15 — degraded via FAMILIES)
        ['slug' => 'gpt-5.6-sol',       'display_name' => 'GPT-5.6 Sol',      'family' => 'gpt'],
        ['slug' => 'gpt-5.6-luna',      'display_name' => 'GPT-5.6 Luna',     'family' => 'gpt'],
        ['slug' => 'gpt-5.6-terra',     'display_name' => 'GPT-5.6 Terra',    'family' => 'gpt'],
        ['slug' => 'gpt-5.5',           'display_name' => 'GPT-5.5',          'family' => 'gpt'],
        ['slug' => 'gpt-5.4',           'display_name' => 'GPT-5.4',          'family' => 'gpt'],
        ['slug' => 'gpt-5.4-mini',      'display_name' => 'GPT-5.4 mini',     'family' => 'gpt'],
        ['slug' => 'gpt-5.3-codex',     'display_name' => 'GPT-5.3 Codex',    'family' => 'gpt'],
        ['slug' => 'gpt-5-mini',        'display_name' => 'GPT-5 mini',       'family' => 'gpt'],
        // Gemini
        ['slug' => 'gemini-3.5-flash',  'display_name' => 'Gemini 3.5 Flash', 'family' => 'gemini'],
        ['slug' => 'gemini-3.1-pro',    'display_name' => 'Gemini 3.1 Pro',   'family' => 'gemini'],
        // Moonshot
        ['slug' => 'kimi-k2.7-code',    'display_name' => 'Kimi K2.7 Code',   'family' => 'kimi'],
        // Microsoft
        ['slug' => 'mai-code-1-flash',  'display_name' => 'MAI-Code-1 Flash', 'family' => 'mai'],
    ];

    /**
     * Resolve a family alias or a foreign (e.g. Claude-CLI dash) model
     * name into the concrete ID the copilot CLI accepts.
     *
     *   resolve('sonnet')                → 'claude-sonnet-5'    (family default)
     *   resolve('claude-sonnet-4-6')     → 'claude-sonnet-4.6'  (dash → dot)
     *   resolve('claude-sonnet-4-7')     → 'claude-sonnet-5'    (fallback: 4.7 not in copilot)
     *   resolve('claude-opus-4-7[1m]')   → 'claude-opus-4.7'    (strip context tag, dash → dot)
     *   resolve('claude-sonnet-4.5')     → 'claude-sonnet-4.5'  (already valid, passthrough)
     *   resolve('gpt-5.6-sol')           → 'gpt-5.6-sol'        (already valid, passthrough)
     *   resolve('gpt-5.1')               → 'gpt-5.6-sol'        (retired 2026-04-15 → family default)
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
        if (str_contains($slug, 'fable'))  return 'fable';
        if (str_contains($slug, 'opus'))   return 'opus';
        if (str_contains($slug, 'haiku'))  return 'haiku';
        if (str_starts_with($slug, 'gpt')) return 'gpt';
        return null;
    }
}
