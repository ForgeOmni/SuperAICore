<?php

declare(strict_types=1);

namespace SuperAICore\Federation\Pii;

/**
 * One transform the pipeline applied to a message, recorded so audit
 * logs reflect what was scrubbed without re-leaking the original value.
 *
 * `replacement` is the literal string that ended up in the rewritten
 * output (`[REDACTED:email]`, `[HASH:ab12cd34]`, etc.). For PASS we
 * omit the action entirely — only deviations from the source are logged.
 */
final class AppliedAction
{
    public function __construct(
        public readonly string $detectorName,
        public readonly Policy $policy,
        public readonly int $offset,
        public readonly int $originalLength,
        public readonly string $replacement,
    ) {}
}
