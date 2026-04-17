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

    public static function resolve(?string $model): ?string
    {
        if ($model === null || $model === '') return null;
        return self::ALIASES[$model] ?? $model;
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
