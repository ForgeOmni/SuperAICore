<?php

namespace SuperAICore\Services;

/**
 * Minimal catalog + family-alias resolver for Google Gemini.
 *
 * Unlike codex, gemini-cli does not keep a local models_cache.json we can
 * read; and unlike Claude, the API is stable enough that a short hand-curated
 * table is sufficient. We expose a `catalog()` the providers page can feed
 * into its "fetch models" fallback, plus a `resolve()` that rewrites the
 * family-level aliases "pro" / "flash" / "flash-lite" to the current full id.
 */
class GeminiModelResolver
{
    /** Family alias → current full model id. Bump when Google retires a slug. */
    const ALIASES = [
        'pro'        => 'gemini-2.5-pro',
        'flash'      => 'gemini-2.5-flash',
        'flash-lite' => 'gemini-2.5-flash-lite',
    ];

    /** Hand-maintained catalog. Keep in sync with config.model_pricing. */
    const CATALOG = [
        ['slug' => 'gemini-2.5-pro',        'display_name' => 'Gemini 2.5 Pro'],
        ['slug' => 'gemini-2.5-flash',      'display_name' => 'Gemini 2.5 Flash'],
        ['slug' => 'gemini-2.5-flash-lite', 'display_name' => 'Gemini 2.5 Flash Lite'],
    ];

    /**
     * Resolve a family alias or explicit ID to the concrete model ID.
     *
     * Resolution order:
     *   1. Local ALIASES table (`pro` / `flash` / `flash-lite`).
     *   2. SuperAgent ModelCatalog — picks up aliases like `gemini` /
     *      `gemini-2` / `gemini-pro` that the bundled catalog ships and
     *      that `superagent models update` can expand.
     *   3. Pass through unchanged.
     */
    public static function resolve(?string $model): ?string
    {
        if ($model === null || $model === '') return null;
        if (isset(self::ALIASES[$model])) {
            return self::ALIASES[$model];
        }
        if (class_exists(\SuperAgent\Providers\ModelCatalog::class)) {
            try {
                $resolved = \SuperAgent\Providers\ModelCatalog::resolveAlias($model);
                if ($resolved !== null && str_starts_with($resolved, 'gemini')) {
                    return $resolved;
                }
            } catch (\Throwable) {
                // fall through
            }
        }
        return $model;
    }

    public static function defaultFor(string $family = 'pro'): string
    {
        return self::ALIASES[$family] ?? self::ALIASES['pro'];
    }

    public static function catalog(): array
    {
        return self::CATALOG;
    }
}
