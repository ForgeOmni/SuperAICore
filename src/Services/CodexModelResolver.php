<?php

namespace SuperAICore\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Resolves the codex CLI model dynamically from the CLI's own state files,
 * not a hardcoded list — because codex drops old model slugs without notice
 * (e.g. "gpt-5"/"gpt-5-codex" were both de-listed in favor of "gpt-5.4").
 *
 * Sources (in priority order):
 *   1. ~/.codex/models_cache.json  — authoritative list codex itself maintains
 *   2. ~/.codex/config.toml         — user's explicitly configured default
 *   3. Built-in defaults             — last-resort for first-run / CI
 *
 * When the caller-requested model isn't in the current supported set, we
 * fall back to (in order):
 *   a. user's configured default from config.toml
 *   b. first "list"-visibility model from models_cache.json
 *   c. built-in default
 */
class CodexModelResolver
{
    /** Per-request cache. */
    protected static ?string $cachedMode = null;
    protected static ?array $cachedSupported = null;
    protected static ?string $cachedUserDefault = null;
    protected static ?array $cachedCatalog = null;

    /** Last-resort defaults if no CLI state files are found. */
    const BUILTIN_FALLBACKS = ['gpt-5.4', 'gpt-5.4-mini', 'gpt-5.3-codex', 'gpt-5.2'];

    /**
     * Validate & possibly rewrite a requested codex model.
     */
    public static function resolve(?string $model, ?string $codexPath = null): ?string
    {
        $supported = self::supportedModels();

        // Caller didn't specify — let codex pick its own default.
        if ($model === null || $model === '') {
            return null;
        }

        if (in_array($model, $supported, true)) {
            return $model;
        }

        $fallback = self::fallbackModel($supported);
        self::warn("CodexModelResolver: requested model '{$model}' not in supported set [" . implode(', ', $supported) . "], falling back to '{$fallback}'");
        return $fallback;
    }

    /**
     * Currently supported codex models, read fresh from the CLI's state
     * files. Caches the result for the duration of this PHP process.
     */
    public static function supportedModels(): array
    {
        if (self::$cachedSupported !== null) {
            return self::$cachedSupported;
        }

        $fromCache = self::readModelsCache();
        if (!empty($fromCache)) {
            return self::$cachedSupported = $fromCache;
        }

        // Fall back to user's explicit default + builtin list so we always
        // return *something* rather than an empty list.
        $userDefault = self::userDefaultModel();
        $merged = $userDefault
            ? array_values(array_unique(array_merge([$userDefault], self::BUILTIN_FALLBACKS)))
            : self::BUILTIN_FALLBACKS;

        return self::$cachedSupported = $merged;
    }

    /**
     * Parse ~/.codex/models_cache.json and return slugs of visible models.
     * Codex refreshes this file itself; we just read it.
     */
    protected static function readModelsCache(): array
    {
        return array_map(fn ($m) => $m['slug'], self::catalog());
    }

    /**
     * Full model catalog as parsed from ~/.codex/models_cache.json.
     * Each entry: ['slug' => ..., 'display_name' => ..., 'efforts' => [...]].
     * Returns empty array when the cache file is missing/unreadable.
     */
    public static function catalog(): array
    {
        if (self::$cachedCatalog !== null) {
            return self::$cachedCatalog;
        }

        $path = self::homeDir() . '/.codex/models_cache.json';
        if (!is_file($path) || !is_readable($path)) {
            return self::$cachedCatalog = [];
        }

        $raw = @file_get_contents($path);
        if ($raw === false) return self::$cachedCatalog = [];

        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['models']) || !is_array($data['models'])) {
            return self::$cachedCatalog = [];
        }

        $out = [];
        foreach ($data['models'] as $model) {
            if (!is_array($model)) continue;
            // Only list user-visible, API-supported models.
            if (($model['visibility'] ?? null) !== 'list') continue;
            if (($model['supported_in_api'] ?? true) === false) continue;
            $slug = $model['slug'] ?? null;
            if (!is_string($slug) || $slug === '') continue;

            $efforts = [];
            foreach (($model['supported_reasoning_levels'] ?? []) as $level) {
                $e = $level['effort'] ?? null;
                if (is_string($e) && $e !== '') $efforts[] = $e;
            }

            $out[] = [
                'slug' => $slug,
                'display_name' => $model['display_name'] ?? $slug,
                'efforts' => $efforts,
                'default_effort' => $model['default_reasoning_level'] ?? null,
            ];
        }
        return self::$cachedCatalog = $out;
    }

    /**
     * Union of effort levels across all supported models — the safe set
     * any supported model will accept.
     */
    public static function supportedEfforts(): array
    {
        $union = [];
        foreach (self::catalog() as $m) {
            foreach ($m['efforts'] as $e) {
                if (!in_array($e, $union, true)) $union[] = $e;
            }
        }
        return $union;
    }

    /**
     * Read user's configured default model from ~/.codex/config.toml.
     * Tiny hand-rolled parser — we only need the top-level `model = "..."`.
     */
    public static function userDefaultModel(): ?string
    {
        if (self::$cachedUserDefault !== null) {
            return self::$cachedUserDefault ?: null;
        }

        $path = self::homeDir() . '/.codex/config.toml';
        if (!is_file($path) || !is_readable($path)) {
            return self::$cachedUserDefault = '';
        }

        $raw = @file_get_contents($path);
        if ($raw === false) return self::$cachedUserDefault = '';

        // Only look at lines before the first [section] — those are the
        // top-level keys we care about.
        $lines = preg_split('/\r\n|\n|\r/', $raw);
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) continue;
            if (str_starts_with($trimmed, '[')) break;
            if (preg_match('/^model\s*=\s*"([^"]+)"\s*$/', $trimmed, $m)) {
                return self::$cachedUserDefault = $m[1];
            }
        }
        return self::$cachedUserDefault = '';
    }

    /**
     * Pick the best fallback: user's config.toml default if it's in the
     * supported set, else the first supported one.
     */
    protected static function fallbackModel(array $supported): ?string
    {
        $userDefault = self::userDefaultModel();
        if ($userDefault && in_array($userDefault, $supported, true)) {
            return $userDefault;
        }
        return $supported[0] ?? null;
    }

    /**
     * Detect codex login mode. Kept for callers that need to know (e.g.
     * display-only), but is no longer required for model resolution since
     * we now read the authoritative models_cache directly.
     */
    public static function detectLoginMode(?string $codexPath = null): string
    {
        if (self::$cachedMode !== null) {
            return self::$cachedMode;
        }

        $binary = $codexPath ?: 'codex';
        try {
            $p = Process::fromShellCommandline(escapeshellarg($binary) . ' login status 2>&1');
            $p->setTimeout(5);
            $p->run();
            $out = strtolower(trim($p->getOutput()));
        } catch (\Throwable $e) {
            return self::$cachedMode = 'unknown';
        }

        if (str_contains($out, 'chatgpt')) return self::$cachedMode = 'chatgpt';
        if (str_contains($out, 'api key') || str_contains($out, 'api-key') || str_contains($out, 'openai_api_key')) {
            return self::$cachedMode = 'api';
        }
        return self::$cachedMode = 'unknown';
    }

    public static function flushCache(): void
    {
        self::$cachedMode = null;
        self::$cachedSupported = null;
        self::$cachedUserDefault = null;
        self::$cachedCatalog = null;
    }

    protected static function homeDir(): string
    {
        $home = getenv('HOME');
        if ($home) return rtrim($home, '/\\');
        if (PHP_OS_FAMILY === 'Windows') {
            $profile = getenv('USERPROFILE');
            if ($profile) return rtrim($profile, '/\\');
        }
        return '';
    }

    protected static function warn(string $msg): void
    {
        if (!class_exists(Log::class)) return;
        try {
            Log::warning($msg);
        } catch (\Throwable $e) {
            // never break task execution for a log failure
        }
    }
}
