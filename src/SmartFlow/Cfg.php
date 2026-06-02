<?php

declare(strict_types=1);

namespace SuperAICore\SmartFlow;

/**
 * Container-safe config reader. The global `config()` helper resolves to
 * Laravel's when illuminate is present, but throws a BindingResolutionException
 * if no application container is booted (e.g. a plain PHPUnit run or the pure
 * standalone `bin/superaicore` console). This reads `config()` behind a
 * try/catch so SmartFlow never crashes on configuration access in either mode,
 * falling back to the supplied default.
 */
final class Cfg
{
    public static function get(string $key, mixed $default = null): mixed
    {
        if (function_exists('config')) {
            try {
                $value = config($key, null);
                if ($value !== null) {
                    return $value;
                }
            } catch (\Throwable) {
                // no booted container — ignore and fall through to $default
            }
        }

        return $default;
    }
}
