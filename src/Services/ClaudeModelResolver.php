<?php

namespace SuperAICore\Services;

/**
 * Canonical Claude model catalog for aicore.
 *
 * Unlike codex CLI (which publishes ~/.codex/models_cache.json), the
 * claude CLI keeps its model table internal and accepts the short
 * aliases `opus` / `sonnet` / `haiku` — each of which it routes to the
 * currently-latest model in that family. We keep an authoritative
 * mapping here so host UI can show an accurate display name and
 * explicit model IDs (e.g. `claude-opus-4-7[1m]`) stay in sync.
 *
 * Keep this file up to date when a new Claude generation ships.
 */
class ClaudeModelResolver
{
    /**
     * Short alias → current full model ID.
     * Claude CLI resolves these at runtime too, but keeping them here
     * lets the UI show the concrete id in badges.
     */
    const FAMILIES = [
        'opus'   => 'claude-opus-4-7',
        'sonnet' => 'claude-sonnet-4-6',
        'haiku'  => 'claude-haiku-4-5-20251001',
    ];

    /**
     * Ordered, user-facing model catalog. First entry in each family
     * is the default for that family.
     *
     * Fields mirror CodexModelResolver::catalog() so the Process Monitor
     * / dropdown helpers can iterate both resolvers uniformly.
     */
    const CATALOG = [
        // Opus generations, newest first
        ['slug' => 'claude-opus-4-7',        'display_name' => 'Opus 4.7',             'family' => 'opus'],
        ['slug' => 'claude-opus-4-7[1m]',    'display_name' => 'Opus 4.7 — 1M context','family' => 'opus', 'extended_context' => '1m'],
        ['slug' => 'claude-opus-4-6',        'display_name' => 'Opus 4.6',             'family' => 'opus'],
        ['slug' => 'claude-opus-4-6[1m]',    'display_name' => 'Opus 4.6 — 1M context','family' => 'opus', 'extended_context' => '1m'],

        // Sonnet
        ['slug' => 'claude-sonnet-4-6',      'display_name' => 'Sonnet 4.6',           'family' => 'sonnet'],
        ['slug' => 'claude-sonnet-4-5-20241022','display_name' => 'Sonnet 4.5',        'family' => 'sonnet'],

        // Haiku
        ['slug' => 'claude-haiku-4-5-20251001','display_name' => 'Haiku 4.5',          'family' => 'haiku'],
    ];

    /**
     * Default model for a family (or null for the global default).
     */
    public static function defaultFor(string $family): ?string
    {
        return self::FAMILIES[$family] ?? null;
    }

    /**
     * Resolve a family alias or explicit ID to the concrete model ID
     * the API will accept. Unknown input is passed through unchanged.
     */
    public static function resolve(?string $model): ?string
    {
        if ($model === null || $model === '') return null;
        return self::FAMILIES[$model] ?? $model;
    }

    /**
     * Catalog entries for dropdowns / info panels.
     */
    public static function catalog(): array
    {
        return self::CATALOG;
    }

    /**
     * Family aliases (short names users type) in a stable order.
     * @return string[]
     */
    public static function families(): array
    {
        return array_keys(self::FAMILIES);
    }

    /**
     * Lookup the display name for a slug or alias — falls back to the
     * slug itself.
     */
    public static function displayName(string $slug): string
    {
        foreach (self::CATALOG as $entry) {
            if ($entry['slug'] === $slug) return $entry['display_name'];
        }
        if (isset(self::FAMILIES[$slug])) {
            return ucfirst($slug);
        }
        return $slug;
    }
}
