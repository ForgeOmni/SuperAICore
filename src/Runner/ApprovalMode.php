<?php

declare(strict_types=1);

namespace SuperAICore\Runner;

/**
 * Tool-execution approval mode — three-tier dial that mirrors codex's
 * `/permissions` command (renamed from the older `/approvals`).
 *
 *   Auto    — no human in the loop; tools execute as the model
 *             requests them. Destructive operations still hit the
 *             `Guidance\Gates\DestructiveCommandScanner` and require
 *             the user-supplied override flag, but everything else
 *             flows through.
 *   Suggest — the agent runs read-only tools freely, but mutations
 *             (file writes, shell commands, network calls) are
 *             surfaced to the user for one-shot approval. This is
 *             where `/approve` lives: a denial is recoverable — the
 *             user can grant a single retry of the rejected call
 *             without restarting the turn.
 *   Never   — pure-read operation. The agent CANNOT mutate state.
 *             Useful for "explain this codebase" sessions where the
 *             user wants zero risk of accidental edits.
 *
 * Default: Suggest. Hosts override per-thread via `AiProcess.approval_mode`
 * or globally via `super-ai-core.runner.approval_mode`.
 */
enum ApprovalMode: string
{
    case Auto    = 'auto';
    case Suggest = 'suggest';
    case Never   = 'never';

    public function label(): string
    {
        return match ($this) {
            self::Auto    => 'Auto-approve all tools',
            self::Suggest => 'Suggest mode (mutations need approval)',
            self::Never   => 'Read-only',
        };
    }

    /**
     * Tools that the agent is allowed to call without surfacing the
     * call to the user, regardless of mode. Strictly read-only — any
     * tool that writes files, runs shell commands, or makes outbound
     * network calls MUST NOT appear here.
     *
     * @return list<string>
     */
    public static function readOnlyAllowlist(): array
    {
        return [
            'agent_grep',
            'agent_glob',
            'agent_read',
            'agent_ls',
            'agent_status',
            'web_search',
            'web_fetch',
            'agent_get_goal',
        ];
    }

    /**
     * Parse a free-form string ("auto" / "suggest" / "never" / aliases)
     * into the enum. Unknown values fall back to Suggest — the safest
     * default, since it always yields control to the user before any
     * mutation lands.
     */
    public static function parse(?string $value): self
    {
        $key = strtolower(trim((string) $value));
        return match ($key) {
            'auto', 'yolo', 'full-auto', 'auto-edit'    => self::Auto,
            'never', 'read-only', 'readonly', 'read'    => self::Never,
            default                                      => self::Suggest,
        };
    }
}
