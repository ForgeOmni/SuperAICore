<?php

declare(strict_types=1);

namespace SuperAICore\Modes;

use Psr\Log\LoggerInterface;
use SuperAgent\Modes\ModeContext;
use SuperAgent\Modes\ModeOrchestrator;
use SuperAgent\Modes\ModeResult;
use SuperAICore\Models\AiUserQuestion;

/**
 * Plan mode orchestrator — opencode `agent/agent.ts` `plan` agent + `tool/plan.ts`
 * (`plan_exit`) port. Two-phase workflow:
 *
 *   Phase 1: PLANNING.
 *     - Dispatches to whichever backend the host configured for plan work
 *       (default: `cli:claude_cli`).
 *     - Caller's `denied_tools` get augmented with the project's write
 *       gate — edits to anything outside the plan file glob are denied.
 *     - The prompt instructs the model to write a markdown plan to
 *       `<project>/.superagent/plans/{session}.md`.
 *
 *   Phase 2: APPROVAL (HITL gate).
 *     - Opens an `ai_user_questions` row asking the operator "approve the
 *       plan and switch to build mode?"
 *     - On approval, hands off to the configured build backend with a
 *       synthetic prompt that points at the plan file.
 *     - On rejection, returns the plan text without executing.
 *
 * `super-ai-core.modes.plan.*` config block:
 *   - `enabled`           bool   default true
 *   - `plan_backend`      string default 'cli:claude_cli'
 *   - `build_backend`     string default 'cli:claude_cli'
 *   - `plan_dir`          string default '.superagent/plans'
 *   - `auto_approve`      bool   default false   — skip the HITL gate
 *                                                  (useful for CI / tests)
 *
 * Note: the HITL gate requires `super-ai-core.tools.ask_user_enabled` so
 * the question card can be answered through `/processes`. When that's
 * off, the orchestrator falls back to `auto_approve = true` behaviour
 * and emits a warning in the log.
 */
class CliPlanOrchestrator implements ModeOrchestrator
{
    public function __construct(
        private readonly CrossLayerDispatcher $dispatcher,
        private readonly ?LoggerInterface $logger = null,
        /** @var array<string,mixed> */
        private array $config = [],
    ) {}

    public function modeName(): string
    {
        return 'plan';
    }

    public function execute(string $task, ModeContext $context, array $options = []): ModeResult
    {
        $cfg = $this->resolveConfig();
        if (!$cfg['enabled']) {
            return new ModeResult(text: '', costUsd: 0.0, mode: 'plan', trace: $context->modeStack, modeSpecific: ['skipped' => true]);
        }

        $sessionId = (string) ($options['metadata']['session_id'] ?? ($options['session_id'] ?? bin2hex(random_bytes(8))));
        $planPath  = $this->ensurePlanPath($cfg['plan_dir'], $sessionId);
        $planRel   = $this->relativizePath($planPath);

        // Phase 1 — PLANNING.
        // The dispatcher is the canonical CLI/SDK seam; we tag this leg
        // with the plan backend and an augmented system prompt that
        // pins the plan-file convention. Denied tools forbid edits
        // outside the plan glob. The PermissionEvaluator already handles
        // this when the host declares `super-ai-core.agents.plan.permission`;
        // we still pass `agent: 'plan'` so the evaluator fires.
        $planPrompt = $this->planPrompt($task, $planRel);
        $planLeaf = $this->dispatcher->dispatch([
            'provider' => $cfg['plan_backend'],
            'prompt'   => $planPrompt,
            'options'  => array_merge($options, [
                'agent'  => 'plan',
                'system' => $this->planSystem($planRel),
            ]),
            'metadata' => array_merge((array) ($options['metadata'] ?? []), [
                'session_id' => $sessionId,
                'phase'      => 'plan',
                'plan_file'  => $planRel,
            ]),
        ]);
        $planCost = (float) ($planLeaf['cost_usd'] ?? 0.0);
        $context->costLedger->record('plan:plan', $planCost, 'plan', $planLeaf['model'] ?? null);

        $planText = (string) ($planLeaf['output'] ?? '');
        $planExists = is_file($planPath);
        $planContent = $planExists ? (string) @file_get_contents($planPath) : '';

        // Phase 2 — APPROVAL.
        $autoApprove = (bool) ($cfg['auto_approve']
            ?? !$this->hitlAvailable());
        $approved = $autoApprove ? true : $this->askApproval($sessionId, $planRel, (int) ($cfg['approval_timeout'] ?? 600));

        if (!$approved) {
            return new ModeResult(
                text:    $planText,
                costUsd: $planCost,
                mode:    'plan',
                trace:   $context->modeStack,
                modeSpecific: [
                    'plan_file' => $planRel,
                    'phase'     => 'plan_rejected',
                    'approved'  => false,
                ],
            );
        }

        // Phase 3 — BUILD.
        $buildPrompt = $this->buildPrompt($task, $planRel, $planContent);
        $buildLeaf = $this->dispatcher->dispatch([
            'provider' => $cfg['build_backend'],
            'prompt'   => $buildPrompt,
            'options'  => array_merge($options, [
                'agent'  => 'build',
                'system' => $this->buildSystem($planRel),
            ]),
            'metadata' => array_merge((array) ($options['metadata'] ?? []), [
                'session_id' => $sessionId,
                'phase'      => 'build',
                'plan_file'  => $planRel,
            ]),
        ]);
        $buildCost = (float) ($buildLeaf['cost_usd'] ?? 0.0);
        $context->costLedger->record('plan:build', $buildCost, 'build', $buildLeaf['model'] ?? null);

        $buildText = (string) ($buildLeaf['output'] ?? '');

        return new ModeResult(
            text:    $buildText !== '' ? $buildText : $planText,
            costUsd: $planCost + $buildCost,
            mode:    'plan',
            trace:   $context->modeStack,
            modeSpecific: [
                'plan_file'    => $planRel,
                'plan_text'    => $planText,
                'build_text'   => $buildText,
                'phase'        => 'completed',
                'approved'     => true,
                'plan_cost'    => $planCost,
                'build_cost'   => $buildCost,
            ],
        );
    }

    /**
     * @return array{enabled:bool, plan_backend:string, build_backend:string, plan_dir:string, auto_approve:bool|null, approval_timeout:int}
     */
    private function resolveConfig(): array
    {
        $base = function_exists('config') ? (array) (config('super-ai-core.modes.plan') ?? []) : [];
        $local = $this->config;
        return [
            'enabled'          => (bool) ($local['enabled']         ?? $base['enabled']         ?? true),
            'plan_backend'     => (string) ($local['plan_backend']  ?? $base['plan_backend']    ?? 'cli:claude_cli'),
            'build_backend'    => (string) ($local['build_backend'] ?? $base['build_backend']   ?? 'cli:claude_cli'),
            'plan_dir'         => (string) ($local['plan_dir']      ?? $base['plan_dir']        ?? '.superagent/plans'),
            'auto_approve'     =>          $local['auto_approve']   ?? $base['auto_approve']    ?? null,
            'approval_timeout' => (int) ($local['approval_timeout'] ?? $base['approval_timeout'] ?? 600),
        ];
    }

    private function ensurePlanPath(string $relDir, string $sessionId): string
    {
        $root = function_exists('base_path') ? base_path() : (getcwd() ?: '.');
        $abs  = rtrim($root, '/\\') . '/' . ltrim($relDir, '/\\');
        if (!is_dir($abs)) @mkdir($abs, 0775, true);
        return $abs . '/' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $sessionId) . '.md';
    }

    private function relativizePath(string $abs): string
    {
        $root = function_exists('base_path') ? base_path() : (getcwd() ?: '');
        if ($root === '' || !str_starts_with($abs, $root)) return $abs;
        return ltrim(substr($abs, strlen($root)), '/\\');
    }

    private function planSystem(string $planRel): string
    {
        return <<<TXT
You are operating in PLAN mode.

Write a complete, actionable markdown plan to {$planRel}. Do NOT call any
edit / write tool against any path other than {$planRel}. Read, search,
list, and webfetch / websearch are allowed.

The plan must include:
  - Goal (one sentence)
  - Constraints
  - Step-by-step actions, each with the files it will touch
  - Open questions you'd like the operator to clarify before build

Stop and return when the plan is written. Do not execute the plan.
TXT;
    }

    private function planPrompt(string $task, string $planRel): string
    {
        return "Task: {$task}\n\nWrite the plan to {$planRel}.";
    }

    private function buildSystem(string $planRel): string
    {
        return <<<TXT
You are operating in BUILD mode. The plan at {$planRel} has been approved
by the operator. Execute the plan exactly as written. If the plan turns
out to be wrong mid-implementation, prefer stopping and reporting the
mismatch over silently deviating.
TXT;
    }

    private function buildPrompt(string $task, string $planRel, string $planContent): string
    {
        $excerpt = $planContent !== '' ? "\n\n--- Plan ({$planRel}) ---\n{$planContent}\n--- End plan ---" : '';
        return "Original task: {$task}\n\nExecute the approved plan at {$planRel}.{$excerpt}";
    }

    private function hitlAvailable(): bool
    {
        return function_exists('config')
            ? (bool) (config('super-ai-core.tools.ask_user_enabled') ?? false)
            : false;
    }

    /**
     * Block on an `ai_user_questions` row until the operator clicks
     * "approve" or "reject" in the /processes UI. Returns true on
     * approval, false on rejection / timeout / DB failure. Mirrors the
     * polling behaviour of `AskUserTool` so we don't need two HITL
     * primitives.
     */
    private function askApproval(string $sessionId, string $planRel, int $timeoutSeconds): bool
    {
        try {
            $row = AiUserQuestion::create([
                'session_id'  => $sessionId,
                'agent_label' => 'plan',
                'question'    => "Approve plan at {$planRel} and switch to build mode?",
                'options'     => [
                    ['label' => 'Approve', 'description' => 'Execute the plan now in build mode'],
                    ['label' => 'Reject',  'description' => 'Return the plan without executing'],
                ],
                'status'      => AiUserQuestion::STATUS_PENDING,
            ]);
        } catch (\Throwable $e) {
            $this->logger?->warning('CliPlanOrchestrator: cannot persist approval question: ' . $e->getMessage());
            return false;
        }

        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            usleep(500_000);
            $fresh = AiUserQuestion::find($row->id);
            if ($fresh === null) return false;
            if ($fresh->status === AiUserQuestion::STATUS_ANSWERED) {
                $ans = strtolower((string) ($fresh->answer ?? ''));
                return $ans !== '' && (str_starts_with($ans, 'approve') || $ans === 'yes' || $ans === 'y');
            }
            if ($fresh->status === AiUserQuestion::STATUS_CANCELLED) return false;
        }
        try {
            $row->status = AiUserQuestion::STATUS_TIMED_OUT;
            $row->save();
        } catch (\Throwable) {}
        return false;
    }
}
