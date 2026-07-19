<?php

namespace SuperAICore\Services;

use Symfony\Component\Process\Process;

/**
 * Maps model aliases to what the Antigravity CLI (`agy`) accepts.
 *
 * agy (verified 1.1.4, 2026-07-19) is Google's replacement for the retired
 * gemini-cli consumer tiers ("Antigravity suite"; gemini-cli individual
 * OAuth died 2026-06-18). Its `--model` flag accepts ONLY the full display
 * name exactly as `agy models` prints it — e.g. `Gemini 3.5 Flash (Low)`.
 * Slugs are NOT accepted, and the failure mode is nasty: an unknown value
 * makes print mode dump the model list to stdout and exit 0, which would
 * become the dispatch "answer". So `resolve()` is STRICT: known display
 * names and slugs map through; anything unknown resolves to null (drop
 * `--model`, ride the CLI's default) instead of passing through.
 *
 * The account lineup routes multiple vendors (verified live):
 * Gemini 3.5 Flash (Medium|High|Low), Gemini 3.1 Pro (Low|High),
 * Claude Sonnet 4.6 (Thinking), Claude Opus 4.6 (Thinking),
 * GPT-OSS 120B (Medium). `liveCatalog()` reads the account truth.
 *
 * Subscription-billed channel (Google account login) — the cost calculator
 * treats `antigravity_cli:*` rows as $0, same as the other CLI engines.
 */
class AntigravityModelResolver
{
    /**
     * Short alias → display name `agy --model` accepts. Effort-suffixed
     * variants pick the tier agy lists first for that family.
     */
    const FAMILIES = [
        'flash'   => 'Gemini 3.5 Flash (Medium)',
        'pro'     => 'Gemini 3.1 Pro (High)',
        'sonnet'  => 'Claude Sonnet 4.6 (Thinking)',
        'opus'    => 'Claude Opus 4.6 (Thinking)',
        'gpt-oss' => 'GPT-OSS 120B (Medium)',
    ];

    /**
     * Slug → display name. Slugs follow the cross-engine id conventions so
     * callers can say `gemini-3.5-flash` like everywhere else in the stack.
     */
    const SLUGS = [
        'gemini-3.5-flash'        => 'Gemini 3.5 Flash (Medium)',
        'gemini-3.5-flash-medium' => 'Gemini 3.5 Flash (Medium)',
        'gemini-3.5-flash-high'   => 'Gemini 3.5 Flash (High)',
        'gemini-3.5-flash-low'    => 'Gemini 3.5 Flash (Low)',
        'gemini-3.1-pro'          => 'Gemini 3.1 Pro (High)',
        'gemini-3.1-pro-high'     => 'Gemini 3.1 Pro (High)',
        'gemini-3.1-pro-low'      => 'Gemini 3.1 Pro (Low)',
        'claude-sonnet-4-6'       => 'Claude Sonnet 4.6 (Thinking)',
        'claude-sonnet-4.6'       => 'Claude Sonnet 4.6 (Thinking)',
        'claude-opus-4-6'         => 'Claude Opus 4.6 (Thinking)',
        'claude-opus-4.6'         => 'Claude Opus 4.6 (Thinking)',
        'gpt-oss-120b'            => 'GPT-OSS 120B (Medium)',
    ];

    /**
     * Ordered, user-facing catalog for pickers: slug + display name pairs.
     * Slugs are what the UI stores; resolve() turns them into the display
     * form at spawn time.
     */
    const CATALOG = [
        ['slug' => 'gemini-3.5-flash',      'display_name' => 'Gemini 3.5 Flash (Medium)',      'family' => 'flash'],
        ['slug' => 'gemini-3.5-flash-high', 'display_name' => 'Gemini 3.5 Flash (High)',        'family' => 'flash'],
        ['slug' => 'gemini-3.5-flash-low',  'display_name' => 'Gemini 3.5 Flash (Low)',         'family' => 'flash'],
        ['slug' => 'gemini-3.1-pro',        'display_name' => 'Gemini 3.1 Pro (High)',          'family' => 'pro'],
        ['slug' => 'gemini-3.1-pro-low',    'display_name' => 'Gemini 3.1 Pro (Low)',           'family' => 'pro'],
        ['slug' => 'claude-sonnet-4-6',     'display_name' => 'Claude Sonnet 4.6 (Thinking)',   'family' => 'sonnet'],
        ['slug' => 'claude-opus-4-6',       'display_name' => 'Claude Opus 4.6 (Thinking)',     'family' => 'opus'],
        ['slug' => 'gpt-oss-120b',          'display_name' => 'GPT-OSS 120B (Medium)',          'family' => 'gpt-oss'],
    ];

    /**
     * Resolve an alias / slug / display name to the `--model` value, or
     * null for "send no --model" (unknown input included — see class doc
     * for why unknown must NOT pass through).
     */
    public static function resolve(?string $model): ?string
    {
        if ($model === null || trim($model) === '') return null;
        $raw = trim($model);
        $key = strtolower($raw);

        if (isset(self::FAMILIES[$key])) return self::FAMILIES[$key];
        if (isset(self::SLUGS[$key]))    return self::SLUGS[$key];

        // Exact display name (case-insensitive) → canonical display form.
        foreach (self::CATALOG as $row) {
            if (strcasecmp($raw, $row['display_name']) === 0) {
                return $row['display_name'];
            }
        }

        return null;
    }

    /**
     * The account's live model list from `agy models` — one display name
     * per line, optionally indented. Empty array on any failure (not
     * signed in, binary missing) so callers fall back to CATALOG.
     *
     * @return string[]
     */
    public static function liveCatalog(string $binary = 'agy'): array
    {
        try {
            $p = new Process([$binary, 'models']);
            $p->setTimeout(15);
            $p->run();
            if (!$p->isSuccessful() && trim($p->getOutput()) === '') return [];
            $models = [];
            foreach (preg_split('/\r\n|\n|\r/', $p->getOutput()) ?: [] as $line) {
                $line = trim($line);
                // Model lines look like "Gemini 3.5 Flash (Medium)"; skip
                // sign-in errors and headers.
                if ($line === '' || str_contains($line, 'sign in') || str_ends_with($line, ':')) continue;
                $models[] = $line;
            }
            return $models;
        } catch (\Throwable) {
            return [];
        }
    }
}
