<?php

declare(strict_types=1);

namespace SuperAICore\Federation\Pii;

/**
 * Outcome of running a message through the PII pipeline.
 *
 * `$blocked` true means at least one detector matched a category whose
 * policy is BLOCK. Callers MUST NOT forward `$text` (it's the original
 * input untouched) — they should drop the message and audit
 * `$matches`.
 *
 * For non-blocking outcomes, `$text` is the rewritten payload —
 * REDACTED / HASHed / PASSed per the policy table — and `$matches`
 * lists every applied transform so audit logs can record exactly what
 * was scrubbed.
 */
final class PipelineResult
{
    /**
     * @param AppliedAction[] $actions
     */
    public function __construct(
        public readonly string $text,
        public readonly bool $blocked,
        public readonly array $actions,
    ) {}

    public static function blocked(string $original, array $actions): self
    {
        return new self($original, true, $actions);
    }

    public static function processed(string $rewritten, array $actions): self
    {
        return new self($rewritten, false, $actions);
    }
}
