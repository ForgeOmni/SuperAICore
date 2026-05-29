<?php

namespace SuperAICore\Services;

use SuperAICore\Support\CliBinaryLocator;
use Symfony\Component\Process\Process;

/**
 * Canonical Cursor Composer CLI model catalog for aicore.
 *
 * The `cursor-agent` CLI (Cursor's headless Composer agent) accepts a
 * fixed set of model IDs via `--model` and exposes the authoritative
 * list through `cursor-agent models` (one `slug - Display Name` per line).
 * Account tier decides which rows are routable; the picker here mirrors
 * the public lineup verified 2026-05-28 against cursor-agent 2026.05.28.
 *
 * Cursor's "Composer" model family (`composer-2.5` / `composer-2.5-fast`,
 * the latter the account default) is the headline tier; the CLI also
 * proxies Anthropic (`claude-opus-4-8-thinking-high`) and OpenAI
 * (`gpt-5.x-codex`, `gpt-5.5-high`) SKUs plus the `auto` router.
 *
 * Billing is by Cursor subscription (no per-token metering on this
 * channel), so the cost calculator treats `cursor:*` rows as $0 — the
 * dashboard groups them under "Subscription engines" like Copilot/Kiro.
 *
 * `liveCatalog()` re-probes the binary for hosts that want the exact
 * account lineup; `catalog()` stays static so UI pickers never block on
 * a network round-trip.
 */
class CursorModelResolver
{
    /**
     * Short alias → concrete model ID `cursor-agent --model` accepts.
     * `composer` is the Cursor-native family; the rest let users type a
     * familiar short name and land on the strongest routable sibling.
     */
    const FAMILIES = [
        'composer' => 'composer-2.5-fast',
        'auto'     => 'auto',
        'opus'     => 'claude-opus-4-8-thinking-high',
        'gpt'      => 'gpt-5.5-high',
    ];

    /**
     * Ordered, user-facing catalog (newest/headline first). Shape mirrors
     * ClaudeModelResolver::catalog() so EngineCatalog renders it uniformly.
     */
    const CATALOG = [
        // Auto router
        ['slug' => 'auto',                              'display_name' => 'Auto',                  'family' => 'auto'],
        // Cursor Composer (native, subscription-billed)
        ['slug' => 'composer-2.5-fast',                 'display_name' => 'Composer 2.5 Fast',     'family' => 'composer'],
        ['slug' => 'composer-2.5',                      'display_name' => 'Composer 2.5',          'family' => 'composer'],
        // Anthropic (proxied)
        ['slug' => 'claude-opus-4-8-thinking-high',     'display_name' => 'Opus 4.8 1M Thinking',  'family' => 'opus'],
        ['slug' => 'claude-opus-4-7-thinking-high',     'display_name' => 'Opus 4.7 1M Thinking',  'family' => 'opus'],
        // OpenAI (proxied)
        ['slug' => 'gpt-5.5-high',                      'display_name' => 'GPT-5.5 1M High',       'family' => 'gpt'],
        ['slug' => 'gpt-5.4-high',                      'display_name' => 'GPT-5.4 1M High',       'family' => 'gpt'],
        ['slug' => 'gpt-5.3-codex',                     'display_name' => 'Codex 5.3',             'family' => 'gpt'],
        ['slug' => 'gpt-5.3-codex-high',                'display_name' => 'Codex 5.3 High',        'family' => 'gpt'],
        ['slug' => 'gpt-5.2',                           'display_name' => 'GPT-5.2',               'family' => 'gpt'],
    ];

    /**
     * Resolve a family alias or explicit ID into the concrete model the
     * cursor-agent CLI accepts. Unknown input passes through unchanged so
     * the CLI surfaces its own "model not available" error rather than us
     * silently substituting.
     *
     *   resolve('composer')              → 'composer-2.5-fast'
     *   resolve('opus')                  → 'claude-opus-4-8-thinking-high'
     *   resolve('composer-2.5')          → 'composer-2.5'   (passthrough)
     *   resolve('claude-opus-4-8[1m]')   → 'claude-opus-4-8-thinking-high' (strip tag + family)
     *   resolve(null)                    → null             (engine default)
     */
    public static function resolve(?string $model): ?string
    {
        if ($model === null || $model === '') {
            return null;
        }
        if (isset(self::FAMILIES[$model])) {
            return self::FAMILIES[$model];
        }
        if (self::inCatalog($model)) {
            return $model;
        }

        // Strip Claude-CLI `[1m]`-style context tags and trailing date stamps
        // so `claude-opus-4-8[1m]` / `claude-opus-4-8-20260528` map onto the
        // Cursor thinking SKU for the same family.
        $stripped = (string) preg_replace('/\[[^\]]+\]$/', '', $model);
        $stripped = (string) preg_replace('/-\d{6,8}$/', '', $stripped);
        if (self::inCatalog($stripped)) {
            return $stripped;
        }
        $family = self::familyFromName($stripped);
        if ($family !== null && isset(self::FAMILIES[$family])) {
            return self::FAMILIES[$family];
        }
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

    public static function displayName(string $slug): string
    {
        foreach (self::CATALOG as $entry) {
            if ($entry['slug'] === $slug) return $entry['display_name'];
        }
        if (isset(self::FAMILIES[$slug])) return ucfirst($slug);
        return $slug;
    }

    /**
     * Live probe of `cursor-agent models` → `[['slug'=>,'display_name'=>], …]`.
     * Best-effort: returns the static CATALOG when the binary is missing,
     * not logged in, or the output can't be parsed. Never throws.
     *
     * @return array<int,array{slug:string,display_name:string}>
     */
    public static function liveCatalog(): array
    {
        try {
            $bin = function_exists('app')
                ? app(CliBinaryLocator::class)->find('cursor')
                : 'cursor-agent';
            $process = new Process([$bin, 'models']);
            $process->setTimeout(15);
            $process->run();
            if (!$process->isSuccessful()) return self::CATALOG;

            $rows = [];
            foreach (preg_split('/\r\n|\n|\r/', $process->getOutput()) ?: [] as $line) {
                $line = trim($line);
                // Lines look like "composer-2.5-fast - Composer 2.5 Fast (default)".
                if ($line === '' || !preg_match('/^([a-z0-9.\-]+)\s+-\s+(.+)$/i', $line, $m)) {
                    continue;
                }
                $rows[] = [
                    'slug'         => $m[1],
                    'display_name' => trim(preg_replace('/\s*\((?:current|default)\)\s*$/i', '', $m[2]) ?? $m[2]),
                ];
            }
            return $rows ?: self::CATALOG;
        } catch (\Throwable) {
            return self::CATALOG;
        }
    }

    private static function inCatalog(string $slug): bool
    {
        foreach (self::CATALOG as $entry) {
            if ($entry['slug'] === $slug) return true;
        }
        return false;
    }

    private static function familyFromName(string $slug): ?string
    {
        if (str_contains($slug, 'composer')) return 'composer';
        if (str_contains($slug, 'opus'))     return 'opus';
        if (str_starts_with($slug, 'gpt'))   return 'gpt';
        return null;
    }
}
