<?php

namespace SuperAICore\Support;

/**
 * Single source of truth for the package's table-name prefix.
 *
 * Every migration and Eloquent model in this package reads the prefix from
 * config('super-ai-core.table_prefix') so the bare names — ai_providers,
 * ai_services, ai_usage_logs, etc. — don't collide with an arbitrary host
 * app's tables.
 *
 * Default is "sac_" (SuperAICore). Set to "" to keep the raw names.
 */
class TablePrefix
{
    public const DEFAULT = 'sac_';

    public static function value(): string
    {
        if (function_exists('config')) {
            $value = config('super-ai-core.table_prefix', self::DEFAULT);
            return is_string($value) ? $value : self::DEFAULT;
        }
        return self::DEFAULT;
    }

    public static function apply(string $name): string
    {
        return self::value() . $name;
    }
}
