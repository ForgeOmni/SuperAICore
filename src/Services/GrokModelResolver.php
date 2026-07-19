<?php

namespace SuperAICore\Services;

use SuperAICore\Support\CliBinaryLocator;
use Symfony\Component\Process\Process;

/**
 * Canonical Grok Build CLI model catalog for aicore.
 *
 * The `grok` binary (xAI's "Grok Build" agentic CLI) authenticates against
 * a grok.com subscription (`grok login`, OAuth) and routes model IDs via
 * `-m/--model`. Its authoritative list comes from `grok models` and is
 * account-dependent: as of grok CLI 0.2.103 (re-verified 2026-07-19) this
 * Build plan lists ONLY `grok-4.5` (booked under the routed SKU
 * `grok-4.5-build` in `modelUsage`); other plans have exposed
 * `grok-composer-2.5-fast` (0.2.93-era Build) or the single-model
 * `grok-build` lineup. `grok-4.5` is the static default here; the other
 * ids stay routable for accounts that carry them, and hosts pick up their
 * exact list through `liveCatalog()`.
 *
 * This is DISTINCT from the metered xAI **API** provider (the SDK's
 * `GrokProvider`, `AiProvider::TYPE_GROK`, `XAI_API_KEY`, `grok-4.5`):
 *   - `grok_cli` engine → grok.com subscription, model `grok-build`, $0/token.
 *   - `grok` provider type → api.x.ai, model `grok-4.5` (SDK 1.1.6 default;
 *     grok-4.3 reachable by id), usage-billed.
 * The two share the brand but nothing else; keep them apart in routing.
 *
 * Billing on this channel is by subscription, so the cost calculator
 * treats `grok_cli:*` rows as $0 (grouped under "Subscription engines").
 */
class GrokModelResolver
{
    /**
     * Short alias → concrete model ID `grok --model` accepts.
     */
    const FAMILIES = [
        'grok'     => 'grok-4.5',
        'composer' => 'grok-composer-2.5-fast',
    ];

    /**
     * Ordered, user-facing catalog — superset of account lineups seen
     * across grok CLI 0.2.93 → 0.2.103 (`grok-4.5` default everywhere;
     * Composer / legacy `grok-build` only on plans that expose them —
     * 0.2.103 on this account lists `grok-4.5` alone). Prefer
     * `liveCatalog()` for the account truth; unknown ids pass through so
     * the CLI raises its own error.
     */
    const CATALOG = [
        ['slug' => 'grok-4.5',               'display_name' => 'Grok 4.5',               'family' => 'grok'],
        ['slug' => 'grok-composer-2.5-fast', 'display_name' => 'Grok Composer 2.5 Fast', 'family' => 'composer'],
        ['slug' => 'grok-build',             'display_name' => 'Grok Build (legacy)',    'family' => 'grok'],
    ];

    /**
     * Resolve a family alias or explicit ID into the model the grok CLI
     * accepts. Unknown input passes through unchanged so the CLI surfaces
     * its own error instead of a silent substitution.
     *
     *   resolve('grok')        → 'grok-4.5'
     *   resolve('composer')    → 'grok-composer-2.5-fast'
     *   resolve('grok-build')  → 'grok-build'  (passthrough; legacy accounts)
     *   resolve('grok-4.3')    → 'grok-4.3'    (passthrough; CLI may reject — that's the API SKU)
     *   resolve(null)          → null          (engine default)
     */
    public static function resolve(?string $model): ?string
    {
        if ($model === null || $model === '') {
            return null;
        }
        if (isset(self::FAMILIES[$model])) {
            return self::FAMILIES[$model];
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
     * Live probe of `grok models`. The CLI prints a human block:
     *   "Available models:\n  * grok-build (default)".
     * Best-effort: returns the static CATALOG on any failure. Never throws.
     *
     * @return array<int,array{slug:string,display_name:string}>
     */
    public static function liveCatalog(): array
    {
        try {
            $bin = function_exists('app')
                ? app(CliBinaryLocator::class)->find('grok')
                : 'grok';
            $process = new Process([$bin, 'models']);
            $process->setTimeout(15);
            $process->run();
            if (!$process->isSuccessful()) return self::CATALOG;

            $rows = [];
            $inList = false;
            foreach (preg_split('/\r\n|\n|\r/', $process->getOutput()) ?: [] as $line) {
                $trimmed = trim($line);
                if (stripos($trimmed, 'available models') !== false) {
                    $inList = true;
                    continue;
                }
                if (!$inList) continue;
                // "  * grok-build (default)" → slug "grok-build".
                if (preg_match('/^\*?\s*([a-z0-9.\-]+)/i', $trimmed, $m)) {
                    $slug = $m[1];
                    $rows[] = [
                        'slug'         => $slug,
                        'display_name' => self::displayName($slug),
                    ];
                }
            }
            return $rows ?: self::CATALOG;
        } catch (\Throwable) {
            return self::CATALOG;
        }
    }
}
