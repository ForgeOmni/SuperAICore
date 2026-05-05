<?php

declare(strict_types=1);

namespace SuperAICore\Runner;

use SuperAICore\Guidance\Gates\DestructiveCommandScanner;

/**
 * Enforces the active `ApprovalMode` on each tool call before it
 * reaches the backend. Composes the existing `DestructiveCommandScanner`
 * for the destructive-command branch.
 *
 * One-shot retry token: when the user runs `/approve` (host-side CLI
 * or UI), the host stamps a `tool_use_id` on `pendingOverrideToolUseId`
 * before re-issuing the same call. The gate sees the match and lets
 * the call through ONCE; the override is single-use and self-clears
 * after consumption to prevent accidental approval-replay on a
 * subsequent identical call.
 *
 * The gate is stateless w.r.t. the user override; the host owns the
 * override state and passes the `userOverrideToolUseId` parameter on
 * each call. That keeps the gate trivially testable.
 */
final class ApprovalGate
{
    public function __construct(
        private DestructiveCommandScanner $scanner = new DestructiveCommandScanner(),
    ) {}

    /**
     * @param string                 $toolName              the tool the
     *                                                      agent wants to call
     * @param array<string, mixed>   $arguments             call arguments
     * @param ApprovalMode           $mode                  active mode
     * @param string|null            $toolUseId             id of THIS call
     * @param string|null            $approvedToolUseId     a tool_use_id the
     *                                                      user explicitly
     *                                                      approved via
     *                                                      `/approve`; matches
     *                                                      override the gate
     *                                                      for one call
     */
    public function evaluate(
        string $toolName,
        array $arguments,
        ApprovalMode $mode,
        ?string $toolUseId = null,
        ?string $approvedToolUseId = null,
    ): ApprovalDecision {
        // Universal escape hatch: if the user has explicitly approved
        // THIS specific tool_use_id, let it through irrespective of
        // mode. The host is responsible for clearing the override
        // after consumption so it can't be replayed.
        if ($toolUseId !== null
            && $approvedToolUseId !== null
            && hash_equals($approvedToolUseId, $toolUseId)
        ) {
            return ApprovalDecision::allow('user approved (one-shot override)');
        }

        $isReadOnly = in_array($toolName, ApprovalMode::readOnlyAllowlist(), true);
        $destructiveHit = $this->scanDestructive($toolName, $arguments);

        return match ($mode) {
            ApprovalMode::Never   => $this->evaluateNever($isReadOnly, $toolName),
            ApprovalMode::Suggest => $this->evaluateSuggest($isReadOnly, $destructiveHit, $toolName),
            ApprovalMode::Auto    => $this->evaluateAuto($destructiveHit),
        };
    }

    private function evaluateNever(bool $isReadOnly, string $toolName): ApprovalDecision
    {
        if ($isReadOnly) {
            return ApprovalDecision::allow('read-only tool, mode=never');
        }
        return ApprovalDecision::hardDeny(
            "Mode is `never` (read-only); tool `{$toolName}` mutates state. "
            . "Switch to suggest or auto mode to allow this call.",
            'mode_disallows',
        );
    }

    private function evaluateSuggest(bool $isReadOnly, ?string $destructiveHit, string $toolName): ApprovalDecision
    {
        if ($isReadOnly) {
            return ApprovalDecision::allow('read-only tool');
        }
        if ($destructiveHit !== null) {
            // Destructive — extra-loud reason so the UI can format it
            // distinctively (red banner vs orange dot, etc.).
            return ApprovalDecision::suggestApproval(
                "Destructive operation detected ({$destructiveHit}). Use `/approve` to allow this single call.",
                'destructive_pending_approval',
            );
        }
        return ApprovalDecision::suggestApproval(
            "`{$toolName}` mutates state. Use `/approve` to allow this single call.",
            'mutation_pending_approval',
        );
    }

    private function evaluateAuto(?string $destructiveHit): ApprovalDecision
    {
        if ($destructiveHit !== null) {
            // Auto mode lets ordinary mutations through, but
            // destructive ops still pause for `/approve`. Codex
            // does the same — the scanner is the safety floor that
            // sits below ALL modes except an explicit kill-switch.
            return ApprovalDecision::suggestApproval(
                "Destructive operation detected ({$destructiveHit}). Use `/approve` to allow even in auto mode.",
                'destructive_pending_approval',
            );
        }
        return ApprovalDecision::allow('auto mode');
    }

    /**
     * Scan tool arguments for destructive shell content. Currently
     * only the `agent_bash` / `bash` / `shell` toolset feeds into the
     * shell scanner; SQL gates fire on `agent_sql_exec` etc.
     *
     * @param array<string, mixed> $arguments
     */
    private function scanDestructive(string $toolName, array $arguments): ?string
    {
        $shellishTools = ['agent_bash', 'bash', 'shell', 'agent_shell', 'execute_command'];
        if (! in_array($toolName, $shellishTools, true)) {
            return null;
        }
        $cmd = (string) ($arguments['command'] ?? $arguments['cmd'] ?? '');
        if ($cmd === '') {
            return null;
        }
        return $this->scanner->firstMatch($cmd);
    }
}
