<?php

declare(strict_types=1);

namespace SuperAICore\Federation\Pii;

/**
 * Detect occurrences of one PII category inside a string.
 *
 * Implementations should be pure (no I/O), thread-safe, and forgiving —
 * returning `[]` on any malformed input rather than throwing. The
 * pipeline iterates every detector against every message, so they need
 * to fail open rather than abort the whole scan.
 */
interface Detector
{
    /** Stable detector id, e.g. `email`, `aws_access_key`. */
    public function name(): string;

    /** Human-readable category, e.g. "Email Address". */
    public function category(): string;

    /**
     * Find all matches in the haystack.
     *
     * @return DetectionMatch[] in source order (low offset → high)
     */
    public function detect(string $haystack): array;
}
