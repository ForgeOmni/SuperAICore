<?php

namespace SuperAICore\Support;

/**
 * Container-safe config reader for code that runs on the standalone
 * `bin/superaicore` console as well as inside a Laravel host.
 *
 * `function_exists('config')` alone is not a safe guard: in a dev checkout
 * the helper is autoloaded by illuminate/foundation even when no container
 * is booted, and calling it then throws "Target class [config] does not
 * exist". Wrap the call instead — absent/broken config resolves to the
 * caller's default.
 */
final class ConfigValue
{
    public static function get(string $key, mixed $default = null): mixed
    {
        if (!function_exists('config')) {
            return $default;
        }
        try {
            return config($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
