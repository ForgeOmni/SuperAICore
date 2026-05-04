<?php

declare(strict_types=1);

namespace SuperAICore\Federation\Pii;

/**
 * One PII hit inside a haystack — what was found, where, and by whom.
 *
 * `offset` and `length` are byte offsets so multibyte-aware callers can
 * still slice the original string; pipelines use them to apply
 * non-overlapping replacements without re-scanning.
 */
final class DetectionMatch
{
    public function __construct(
        public readonly string $detectorName,
        public readonly string $value,
        public readonly int $offset,
        public readonly int $length,
    ) {}
}
