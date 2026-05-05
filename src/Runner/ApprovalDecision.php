<?php

declare(strict_types=1);

namespace SuperAICore\Runner;

/**
 * Outcome of an `ApprovalGate::evaluate()` call. Three-state result:
 *
 *   approved=true                   — proceed with the tool call.
 *   approved=false, canRetry=true   — denied this turn; user may grant
 *                                     one retry via `/approve` (mirrors
 *                                     codex's auto-review flow).
 *   approved=false, canRetry=false  — hard deny; the request is
 *                                     incompatible with the active mode
 *                                     (e.g. shell exec in Never mode).
 *
 * The `reason` field is shown to the user so they can decide whether
 * to flip mode or grant the one-shot override. Keep it terse and
 * actionable — "rm -rf detected" beats "command failed safety scan".
 */
final class ApprovalDecision
{
    public function __construct(
        public readonly bool   $approved,
        public readonly string $reason = '',
        public readonly bool   $canRetry = false,
        public readonly string $code = '',
    ) {}

    public static function allow(string $reason = ''): self
    {
        return new self(approved: true, reason: $reason);
    }

    public static function suggestApproval(string $reason, string $code = 'approval_required'): self
    {
        return new self(
            approved: false,
            reason:   $reason,
            canRetry: true,
            code:     $code,
        );
    }

    public static function hardDeny(string $reason, string $code = 'mode_disallows'): self
    {
        return new self(
            approved: false,
            reason:   $reason,
            canRetry: false,
            code:     $code,
        );
    }

    public function toArray(): array
    {
        return [
            'approved'  => $this->approved,
            'reason'    => $this->reason,
            'can_retry' => $this->canRetry,
            'code'      => $this->code,
        ];
    }
}
