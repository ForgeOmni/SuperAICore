<?php

declare(strict_types=1);

namespace SuperAICore\Modes;

use Psr\Log\LoggerInterface;
use SuperAgent\Modes\ModeContext;
use SuperAgent\Modes\ModeOrchestrator;
use SuperAgent\Modes\ModeResult;
use SuperAgent\Pipeline\StepStatus;
use SuperAgent\Squad\DifficultyClass;
use SuperAgent\Squad\SquadDispatchRequest;
use SuperAgent\Squad\ModelTierMap;
use SuperAgent\Squad\PeerOrchestrator;
use SuperAgent\Squad\ReviewerLoopRunner;
use SuperAgent\Squad\SquadCheckpointStore;
use SuperAgent\Squad\SquadPlan;
use SuperAgent\Squad\SubTask;
use SuperAgent\Squad\TaskDecomposer;

/**
 * CLI-layer Squad — the host-side analogue to SDK 1.0.0 Squad mode.
 *
 * The trick: SDK already gave us a complete peer-orchestration engine
 * (`PeerOrchestrator` + `ModelTierMap` + `PeerMailbox` +
 * `SquadCheckpointStore` + cost-aware downshift). The ONLY thing it
 * needs is a `callable(SquadDispatchRequest)` dispatcher. We provide
 * the dispatcher → `CrossLayerDispatcher::squadAdapter()` → arbitrary
 * routing to CLI backends / SDK providers / nested modes.
 *
 * What this gives us out of the box:
 *
 *   ┌────────────────────────────────────────────────────────────┐
 *   │ Plain CLI squad     — every role is a `cli:<name>` tag     │
 *   │ Mixed squad         — `research` on `cli:claude_cli`,      │
 *   │                       `design` on `sdk:anthropic`,         │
 *   │                       `verify` on `cli:codex_cli`          │
 *   │ Recursive squad     — `decide` role with provider `smart`  │
 *   │                       runs the smart orchestrator on that  │
 *   │                       step's prompt; its subtasks land on  │
 *   │                       individual CLI backends              │
 *   │ Pure SDK squad      — every role on `sdk:<provider>`;      │
 *   │                       degrades to SDK's native Squad path  │
 *   │                       (still routed through `superagent`   │
 *   │                       backend so usage tracking applies)   │
 *   └────────────────────────────────────────────────────────────┘
 *
 * Tier-map shape (`options['tier_map']`):
 *
 *   [
 *     'trivial'  => ['provider' => 'cli:gemini_cli',  'model' => 'gemini-2.5-flash'],
 *     'easy'     => ['provider' => 'cli:gemini_cli',  'model' => 'gemini-2.5-flash'],
 *     'moderate' => ['provider' => 'cli:codex_cli',   'model' => 'gpt-5.1'],
 *     'hard'     => ['provider' => 'cli:claude_cli',  'model' => 'claude-sonnet-4-6'],
 *     'expert'   => ['provider' => 'sdk:anthropic',   'model' => 'claude-opus-4-8'],
 *   ]
 *
 * Falls back to `super-ai-core.cli_squad.tier_map` config when no
 * per-call map is supplied.
 */
class CliSquadOrchestrator implements ModeOrchestrator
{
    public function modeName(): string
    {
        return 'squad';
    }

    /**
     * Cross-mode entry — adapts legacy `run($task, $options): array`
     * to `ModeResult`. Cost accrues under 'squad' in the shared
     * ledger; squad_id / roles / completed-steps ride along on
     * modeSpecific for envelope rendering.
     *
     * @param array<string,mixed> $options
     */
    public function execute(string $task, ModeContext $context, array $options = []): ModeResult
    {
        $legacy = $this->run($task, $options);
        $cost = (float) ($legacy['cost_usd'] ?? 0.0);
        $context->costLedger->record('squad', $cost);
        return new ModeResult(
            text:    (string) ($legacy['text'] ?? ''),
            costUsd: $cost,
            mode:    'squad',
            trace:   $context->modeStack,
            modeSpecific: [
                'squad_id'    => $legacy['squad_id'] ?? '',
                'completed'   => $legacy['completed'] ?? [],
                'roles'       => $legacy['roles'] ?? [],
                'mailbox_log' => $legacy['mailbox_log'] ?? [],
                'inner_mode'  => $legacy['mode'] ?? null,
            ],
        );
    }

    private const DEFAULT_TIER_MAP = [
        'trivial'  => ['provider' => 'cli:gemini_cli',  'model' => 'gemini-2.5-flash'],
        'easy'     => ['provider' => 'cli:gemini_cli',  'model' => 'gemini-2.5-flash'],
        'moderate' => ['provider' => 'cli:codex_cli',   'model' => 'gpt-5.1'],
        'hard'     => ['provider' => 'cli:claude_cli',  'model' => 'claude-sonnet-4-6'],
        'expert'   => ['provider' => 'cli:claude_cli',  'model' => 'claude-opus-4-8'],
    ];

    public function __construct(
        private CrossLayerDispatcher $dispatcher,
        private ?LoggerInterface $logger = null,
        /** @var array{tier_map?:array, checkpoint_dir?:?string, max_cost_usd?:?float} */
        private array $config = [],
    ) {}

    /**
     * @param  array<string,mixed> $options
     * @return array{
     *   text:string, cost_usd:float, mode:string,
     *   squad_id:string, completed:list<string>,
     *   roles:list<array{name:string,provider:string,model:string,tier:string}>,
     *   checkpoint_path:?string, mailbox_log:list<array<string,mixed>>
     * }
     */
    public function run(string $task, array $options = []): array
    {
        if (!class_exists(PeerOrchestrator::class)) {
            // SDK not available — degrade to a single CLI dispatch
            // so the caller still gets *some* output back.
            $fallback = $this->dispatcher->dispatch([
                'provider' => 'cli:claude_cli',
                'prompt'   => $task,
                'options'  => $options,
            ]);
            return [
                'text'            => (string) ($fallback['output'] ?? ''),
                'cost_usd'        => (float)  ($fallback['cost_usd'] ?? 0.0),
                'mode'            => 'squad_degraded',
                'squad_id'        => '',
                'completed'       => [],
                'roles'           => [],
                'checkpoint_path' => null,
                'mailbox_log'     => [],
            ];
        }

        $squadId = (string) ($options['squad_id'] ?? $this->mintSquadId());

        // SquadPlan path — when the caller passes a pre-built plan
        // (typically from YamlSquadLoader / TeamRegistry), use the
        // plan's subtasks, tier_map override, and reviewer_loop
        // bindings instead of inferring them from the task string.
        $plan = $options['plan'] ?? null;
        if ($plan instanceof SquadPlan) {
            $subTasks  = $plan->subTasks;
            $tierMap   = $plan->tierMap !== [] ? $this->tierMapFromPlan($plan) : $this->resolveTierMap($options);
            $loops     = $plan->loops;
        } else {
            $subTasks  = $this->resolveSubTasks($options, $task);
            $tierMap   = $this->resolveTierMap($options);
            $loops     = [];
        }
        $checkpointStore = $this->resolveCheckpointStore($options);
        $maxCost  = $this->resolveMaxCost($options);

        // Track per-step cost the orchestrator can't see directly —
        // the SDK reads `cost_usd` off the dispatcher tuple, so the
        // accumulator captures everything that flowed through.
        $costAccumulator = ['total' => 0.0];
        $baseDispatcher = $this->wrapDispatcher($costAccumulator);

        // Cross-mode wrapper: when individual SubTasks declare
        // `mode: smart` / `mode: squad` / `mode: auto` in the YAML
        // plan, route those specific steps through the SDK
        // ModeRouter SPI instead of the plain leaf dispatcher.
        // Falls back to the plain dispatcher silently when no host
        // has installed a router — keeps SuperAICore loosely coupled
        // to SuperAgent's Modes namespace (the wrapper is opt-in
        // per-step AND per-host-install).
        $wrapped = $this->maybeWrapCrossMode($baseDispatcher, $subTasks);

        // Reviewer-loop wrapper: when the plan declared writer/reviewer
        // bindings, wrap the dispatcher so rejection re-runs the writer
        // with the reviewer's feedback prepended. Transparent for plans
        // without loops.
        if ($loops !== [] && class_exists(ReviewerLoopRunner::class)) {
            $loopRunner = new ReviewerLoopRunner($loops, null, $this->logger ?? new \Psr\Log\NullLogger());
            $wrapped = $loopRunner->wrap($wrapped);
        }

        $orchestrator = new PeerOrchestrator(
            agentDispatcher:  $wrapped,
            approvalHandler:  null,
            logger:           $this->logger ?? new \Psr\Log\NullLogger(),
            checkpointStore:  $checkpointStore,
            output:           null,
            maxCostUsd:       $maxCost,
        );

        $result = $orchestrator->run(
            squadId:  $squadId,
            subTasks: $subTasks,
            tierMap:  $tierMap,
            inputs:   (array) ($options['inputs'] ?? []),
        );

        $text = $this->extractFinalText($result);
        $completed = $result->completedStepNames();
        $rolesOut = [];
        foreach ($result->roles as $role) {
            $rolesOut[] = [
                'name'     => $role->name,
                'provider' => $role->provider,
                'model'    => $role->model,
                'tier'     => $role->tier->value,
            ];
        }

        return [
            'text'            => $text,
            'cost_usd'        => (float) $costAccumulator['total'],
            'mode'            => 'squad',
            'has_cross_mode'  => $this->planHasCrossMode($subTasks),
            'squad_id'        => $squadId,
            'completed'       => $completed,
            'roles'           => $rolesOut,
            'checkpoint_path' => $checkpointStore !== null && isset($options['checkpoint_dir'])
                ? rtrim((string) $options['checkpoint_dir'], '/') . '/' . $squadId . '.json'
                : null,
            'mailbox_log'     => $result->mailbox !== null
                ? array_map(fn($m) => method_exists($m, 'toArray') ? $m->toArray() : (array) $m, $result->mailbox->log())
                : [],
        ];
    }

    /**
     * Optionally wrap the dispatcher so SubTasks with cross-mode
     * fields (`mode:` / `team:` / `mode_chain:` etc. in the YAML)
     * route through SDK's `ModeRouter` instead of executing as a
     * plain leaf dispatch.
     *
     * **Loose coupling**: we never directly reference our own
     * `CliModeRouter` — instead we consult SDK's
     * `Modes\ModeRouterRegistry` SPI. If a host (e.g.
     * `SquadDispatcherBridge`) installed a router, cross-mode steps
     * recurse through it. If nothing was installed (SDK without the
     * SPI, host that doesn't want cross-mode, tests), we silently
     * pass through to the base dispatcher — pre-1.0.1 behaviour.
     *
     * The wrapper builds a name→SubTask map so each dispatch can
     * decide based on the original SubTask (PeerOrchestrator's
     * `SquadDispatchRequest` only carries the `SquadRole`).
     *
     * @param callable(SquadDispatchRequest): mixed $base
     * @param SubTask[]                              $subTasks
     */
    private function maybeWrapCrossMode(callable $base, array $subTasks): callable
    {
        // SDK guard: silently degrade when Modes SPI isn't on the
        // classpath (host vendor-pinned to a pre-Modes SDK build).
        if (!class_exists(\SuperAgent\Modes\ModeRouterRegistry::class)) {
            return $base;
        }

        $byName = [];
        $hasCross = false;
        foreach ($subTasks as $st) {
            $byName[$st->name] = $st;
            if (method_exists($st, 'isCrossMode') && $st->isCrossMode()) {
                $hasCross = true;
            }
        }
        if (!$hasCross) {
            return $base;
        }

        $logger = $this->logger ?? new \Psr\Log\NullLogger();
        return function (SquadDispatchRequest $req) use ($base, $byName, $logger) {
            $stepName = $req->role->name;
            $st = $byName[$stepName] ?? null;
            if ($st === null || !method_exists($st, 'isCrossMode') || !$st->isCrossMode()) {
                return $base($req);
            }
            // Look up a host-registered cross-mode router. No-op fallback
            // when none is installed — preserves loose coupling: the
            // SubTask still ran, it just ran as a plain step.
            $router = \SuperAgent\Modes\ModeRouterRegistry::get();
            if ($router === null) {
                $logger->debug('CliSquadOrchestrator: cross-mode SubTask saw no router, falling back', [
                    'step' => $stepName,
                    'mode' => $st->mode,
                ]);
                return $base($req);
            }

            // Build a one-shot ModeContext for this recursion. We
            // don't have access to the parent ModeContext here
            // (PeerOrchestrator doesn't carry one), so each
            // cross-mode step gets its own root — costs and
            // blackboard reads land in a fresh ledger. Hosts that
            // want a unified ledger across the squad call
            // `execute()` on this orchestrator with a `ModeContext`
            // (cross-mode entry); single-shot YAML callers see one
            // ledger per cross-mode step, which still tracks the
            // recursion cost correctly.
            $ctx = \SuperAgent\Modes\ModeContext::root('squad');
            try {
                $mode = $st->mode ?? 'auto';
                $opts = [];
                if (!empty($st->teamRef)) $opts['team'] = $st->teamRef;
                $result = $router->descend($mode, $req->prompt, $ctx, $opts);
                $logger->info('CliSquadOrchestrator: cross-mode step recursed', [
                    'step' => $stepName,
                    'mode' => $mode,
                    'cost_usd' => $result->costUsd,
                    'trace' => $result->trace,
                ]);
                return ['output' => $result->text, 'cost_usd' => $result->costUsd];
            } catch (\Throwable $e) {
                $logger->warning('CliSquadOrchestrator: cross-mode dispatch failed, falling back', [
                    'step'  => $stepName,
                    'mode'  => $st->mode,
                    'error' => $e->getMessage(),
                ]);
                return $base($req);
            }
        };
    }

    /**
     * Wrap the cross-layer dispatcher into the SDK's expected closure
     * signature, plus a cost-accumulator side-channel.
     *
     * @param array{total:float} $costAccumulator
     */
    private function wrapDispatcher(array &$costAccumulator): callable
    {
        $adapter = $this->dispatcher->squadAdapter();
        return function ($req) use (&$costAccumulator, $adapter) {
            $r = $adapter($req);
            if (is_array($r) && isset($r['cost_usd'])) {
                $costAccumulator['total'] += (float) $r['cost_usd'];
            }
            return $r;
        };
    }

    /**
     * @param array<string,mixed> $options
     * @return SubTask[]
     */
    private function resolveSubTasks(array $options, string $task): array
    {
        if (isset($options['subtasks']) && is_array($options['subtasks']) && $options['subtasks'] !== []) {
            $out = [];
            foreach ($options['subtasks'] as $st) {
                if ($st instanceof SubTask) { $out[] = $st; continue; }
                if (!is_array($st) || empty($st['name']) || empty($st['prompt'])) continue;
                $out[] = new SubTask(
                    name:           (string) $st['name'],
                    role:           (string) ($st['role'] ?? $st['name']),
                    prompt:         (string) $st['prompt'],
                    difficulty:     DifficultyClass::tryFrom((string) ($st['difficulty'] ?? 'moderate')) ?? DifficultyClass::MODERATE,
                    dependsOn:      array_values(array_map('strval', (array) ($st['depends_on'] ?? []))),
                    requiresReview: (bool) ($st['requires_review'] ?? false),
                    systemPrompt:   isset($st['system_prompt']) ? (string) $st['system_prompt'] : null,
                    templateRef:    isset($st['template_ref']) ? (string) $st['template_ref'] : null,
                    parallelGroup:  isset($st['parallel_group']) ? (string) $st['parallel_group'] : null,
                );
            }
            return $out;
        }
        return (new TaskDecomposer())->decompose($task);
    }

    /**
     * Build a ModelTierMap from a SquadPlan's tier_map field. Plans
     * loaded from YAML carry band→{provider, model} entries that
     * already match the orchestrator's expected shape.
     */
    private function tierMapFromPlan(SquadPlan $plan): ModelTierMap
    {
        $map = new ModelTierMap();
        foreach ($plan->tierMap as $bandKey => $entry) {
            $band = DifficultyClass::tryFrom((string) $bandKey);
            if ($band === null) continue;
            $provider = (string) ($entry['provider'] ?? '');
            $model    = (string) ($entry['model'] ?? '');
            if ($provider === '' || $model === '') continue;
            $map = $map->with($band, $provider, $model);
        }
        return $map;
    }

    /**
     * @param array<string,mixed> $options
     */
    private function resolveTierMap(array $options): ModelTierMap
    {
        $raw = $options['tier_map']
            ?? $this->config['tier_map']
            ?? self::DEFAULT_TIER_MAP;
        $map = new ModelTierMap();
        if (!is_array($raw)) return $map;
        foreach ($raw as $bandKey => $entry) {
            $band = DifficultyClass::tryFrom((string) $bandKey);
            if ($band === null || !is_array($entry)) continue;
            $provider = (string) ($entry['provider'] ?? '');
            $model    = (string) ($entry['model'] ?? '');
            if ($provider === '' || $model === '') continue;
            $map = $map->with($band, $provider, $model);
        }
        return $map;
    }

    private function resolveCheckpointStore(array $options): ?SquadCheckpointStore
    {
        $dir = $options['checkpoint_dir'] ?? $this->config['checkpoint_dir'] ?? null;
        if (!is_string($dir) || $dir === '') return null;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            if ($this->logger) {
                $this->logger->warning('CliSquadOrchestrator: checkpoint_dir not writable', ['dir' => $dir]);
            }
            return null;
        }
        return new SquadCheckpointStore($dir);
    }

    private function resolveMaxCost(array $options): ?float
    {
        $v = $options['max_cost_usd'] ?? $this->config['max_cost_usd'] ?? null;
        if ($v === null) return null;
        $v = (float) $v;
        return $v > 0 ? $v : null;
    }

    /**
     * @param object $squadResult SquadResult
     */
    private function extractFinalText(object $squadResult): string
    {
        $pipelineResult = $squadResult->pipelineResult ?? null;
        if ($pipelineResult === null) return '';
        $results = $pipelineResult->getStepResults();
        if ($results === []) return '';

        $last = null;
        $completedOutputs = [];
        foreach ($results as $r) {
            if ($r->status !== StepStatus::COMPLETED) continue;
            $completedOutputs[$r->stepName] = (string) ($r->output ?? '');
            $last = $r;
        }
        if ($last !== null) {
            $text = (string) ($last->output ?? '');
            if ($text !== '') return $text;
        }
        if ($completedOutputs === []) return '';
        $parts = [];
        foreach ($completedOutputs as $name => $out) {
            if ($out === '') continue;
            $parts[] = "## {$name}\n\n{$out}";
        }
        return implode("\n\n", $parts);
    }

    /**
     * Whether any subtask in the plan declared cross-mode fields.
     * Used purely for envelope reporting — the actual wrapping
     * happens upstream in `maybeWrapCrossMode`.
     *
     * @param SubTask[] $subTasks
     */
    private function planHasCrossMode(array $subTasks): bool
    {
        foreach ($subTasks as $st) {
            if (method_exists($st, 'isCrossMode') && $st->isCrossMode()) return true;
        }
        return false;
    }

    private function mintSquadId(): string
    {
        try {
            return 'csq_' . bin2hex(random_bytes(6));
        } catch (\Throwable) {
            return 'csq_' . dechex((int) (microtime(true) * 1000));
        }
    }
}
