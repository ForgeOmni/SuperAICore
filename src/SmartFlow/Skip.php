<?php

declare(strict_types=1);

namespace SuperAICore\SmartFlow;

/**
 * The SKIP sentinel — returned by {@see Flow::agent()} when a structured-output
 * call fails schema validation across all three extraction layers (native →
 * submitted → extracted). A flow can `.filter()` SKIP values out of a result set
 * instead of crashing on a malformed CLI reply.
 *
 * It is a singleton object (not a bare null/string) so it never collides with a
 * legitimately-empty CLI result and so `===` identity comparison is exact:
 *
 *     $out = $flow->agent($prompt, ['schema' => $schema]);
 *     if ($out === $flow->SKIP) { ... }            // identity check
 *     if (Skip::isSkip($out)) { ... }              // helper check
 */
final class Skip
{
    private static ?Skip $instance = null;

    private function __construct() {}

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public static function isSkip(mixed $value): bool
    {
        return $value instanceof self;
    }

    public function __toString(): string
    {
        return '[SKIP]';
    }
}
