<?php

declare(strict_types=1);

namespace SuperAICore\Modes;

use Psr\Log\LoggerInterface;
use SuperAgent\Modes\ModeContext;
use SuperAgent\Modes\ModeOrchestrator;
use SuperAgent\Modes\ModeResult;
use SuperAgent\SmartContext\TaskComplexity;
use SuperAgent\Squad\DifficultyClass;
use SuperAgent\Squad\TaskDecomposer;

/**
 * CLI-layer Smart mode — the host-side analogue to SDK
 * `Evals\SmartOrchestrator`. Same three phases (plan → route by
 * difficulty/dim → merge), but the routing table maps
 * difficulty/intent onto CLI backends rather than SDK provider
 * model ids.
 *
 * Three reasons we don't just sub-class SDK's orchestrator:
 *
 *   1. SDK's `routeSubtask()` reaches into `ScoreCatalog::bestModelFor()`
 *      and returns a model id — wrong vocabulary for CLI selection.
 *      Our route table is `difficulty → cli backend`, decoupled from
 *      the SDK's eval catalog.
 *   2. SDK's `oneShot()` calls into `LLMProvider::chat()`. We need to
 *      route through `CrossLayerDispatcher` to get full
 *      CLI/SDK/recursion flexibility on every leaf.
 *   3. SDK's parallel path forks `superagent _subtask` subprocesses;
 *      that's a fine optimization for SDK-only smart runs but
 *      orthogonal to CLI-layer dispatching. We keep this version
 *      simple-serial for v1; parallelism comes back via the
 *      `parallel_group` mechanism baked into `TaskDecomposer`.
 *
 * Cost-cap + downshift parity: same `max_cost_usd` semantics as SDK's
 * SmartOrchestrator — abort after planning / each subtask / pre-merge
 * if the running total exceeds the cap.
 *
 * The merge step is itself dispatched through `CrossLayerDispatcher`
 * with a `merge_cli` or `cli:claude_cli` default — so a host that
 * wants to merge via a different backend than the one that planned
 * (e.g. plan with `cli:claude_cli`, merge with `sdk:anthropic`) just
 * sets `merge_provider` in options.
 */
class CliSmartOrchestrator implements ModeOrchestrator
{
    public function modeName(): string
    {
        return 'smart';
    }

    /**
     * Cross-mode entry — adapts the legacy `run($task, $options): array`
     * signature to ModeResult. Costs accrue under 'smart' in the
     * shared ledger; subtask routing details ride on `modeSpecific`.
     *
     * @param array<string,mixed> $options
     */
    public function execute(string $task, ModeContext $context, array $options = []): ModeResult
    {
        $legacy = $this->run($task, $options);
        $cost = (float) ($legacy['cost_usd'] ?? 0.0);
        $context->costLedger->record('smart', $cost);
        return new ModeResult(
            text:    (string) ($legacy['text'] ?? ''),
            costUsd: $cost,
            mode:    'smart',
            trace:   $context->modeStack,
            modeSpecific: [
                'plan'            => $legacy['plan'] ?? [],
                'subtask_results' => $legacy['subtask_results'] ?? [],
                'inner_mode'      => $legacy['mode'] ?? null,
            ],
        );
    }

    /** Default difficulty → CLI backend tag mapping. */
    private const DEFAULT_ROUTING = [
        'trivial'  => 'cli:gemini_cli',
        'easy'     => 'cli:gemini_cli',
        'moderate' => 'cli:codex_cli',
        'hard'     => 'cli:claude_cli',
        'expert'   => 'cli:claude_cli',
    ];

    public function __construct(
        private CrossLayerDispatcher $dispatcher,
        private ?LoggerInterface $logger = null,
        /** @var array{routing?:array<string,string>, merge_provider?:string, default_provider?:string, max_cost_usd?:?float} */
        private array $config = [],
    ) {}

    /**
     * @param  array<string,mixed> $options
     * @return array{
     *   text:string, cost_usd:float, subtask_results:list<array<string,mixed>>,
     *   plan:array<string,mixed>, mode:string
     * }
     */
    public function run(string $task, array $options = []): array
    {
        $maxCostUsd = $options['max_cost_usd']
            ?? $this->config['max_cost_usd']
            ?? null;
        $maxCostUsd = $maxCostUsd === null ? null : (float) $maxCostUsd;

        $subTasks = (new TaskDecomposer())->decompose($task);
        if ($subTasks === []) {
            $r = $this->dispatcher->dispatch([
                'provider' => $this->config['default_provider'] ?? 'cli:claude_cli',
                'prompt'   => $task,
                'options'  => $options,
            ]);
            return [
                'text'            => (string) ($r['output'] ?? ''),
                'cost_usd'        => (float)  ($r['cost_usd'] ?? 0.0),
                'subtask_results' => [],
                'plan'            => ['concurrency' => 'serial', 'subtasks' => []],
                'mode'            => 'smart_passthrough',
            ];
        }

        $routing = array_merge(self::DEFAULT_ROUTING, (array) ($options['routing'] ?? $this->config['routing'] ?? []));

        $analysis = TaskComplexity::analyze($task);
        $plan = [
            'concurrency' => 'serial',
            'complexity'  => $analysis->score,
            'subtasks'    => array_map(fn($s) => $s->toArray(), $subTasks),
        ];

        $running = 0.0;
        $priorOutputs = [];
        $subtaskResults = [];

        foreach ($subTasks as $st) {
            $tier = $st->difficulty->value;
            $providerTag = $routing[$tier] ?? ($this->config['default_provider'] ?? 'cli:claude_cli');

            $prompt = $this->renderSubtaskPrompt($task, $st->name, $st->prompt, $priorOutputs);

            if ($this->logger) {
                $this->logger->info('CliSmartOrchestrator: routing subtask', [
                    'name' => $st->name, 'tier' => $tier, 'provider' => $providerTag,
                ]);
            }

            $r = $this->dispatcher->dispatch([
                'provider'   => $providerTag,
                'prompt'     => $prompt,
                'system'     => $st->systemPrompt,
                'options'    => $options,
                'metadata'   => ['smart_subtask' => $st->name, 'smart_tier' => $tier],
            ]);

            $out = (string) ($r['output'] ?? '');
            $cost = (float) ($r['cost_usd'] ?? 0.0);
            $running += $cost;
            $priorOutputs[] = ['name' => $st->name, 'output' => $out];
            $subtaskResults[] = [
                'name'     => $st->name,
                'tier'     => $tier,
                'provider' => $providerTag,
                'output'   => $out,
                'cost_usd' => $cost,
                'backend'  => $r['backend'] ?? null,
            ];

            if ($maxCostUsd !== null && $running > $maxCostUsd) {
                if ($this->logger) {
                    $this->logger->warning('CliSmartOrchestrator: budget exceeded — aborting before merge', [
                        'running' => $running, 'cap' => $maxCostUsd,
                    ]);
                }
                return [
                    'text'            => $this->concatPartial($subtaskResults),
                    'cost_usd'        => $running,
                    'subtask_results' => $subtaskResults,
                    'plan'            => $plan,
                    'mode'            => 'smart_aborted',
                ];
            }
        }

        // Single subtask — nothing to merge.
        if (count($subtaskResults) === 1) {
            return [
                'text'            => (string) $subtaskResults[0]['output'],
                'cost_usd'        => $running,
                'subtask_results' => $subtaskResults,
                'plan'            => $plan,
                'mode'            => 'smart_single',
            ];
        }

        // Merge via cross-layer dispatch. Default to the same provider
        // we'd use for an EXPERT subtask — typically the strongest CLI.
        $mergeProvider = (string) ($options['merge_provider']
            ?? $this->config['merge_provider']
            ?? $routing['expert']
            ?? $routing['hard']
            ?? 'cli:claude_cli');
        $mergePrompt = $this->renderMergePrompt($task, $subtaskResults);
        $mergeResult = $this->dispatcher->dispatch([
            'provider' => $mergeProvider,
            'prompt'   => $mergePrompt,
            'system'   => "You are consolidating multi-part outputs into a single coherent answer. "
                        . "Integrate the parts naturally — don't preserve 'Part N' headers unless they "
                        . "genuinely help the reader. If parts conflict, prefer the harder-difficulty "
                        . "output. Match the format the user originally asked for.",
            'options'  => $options,
            'metadata' => ['smart_phase' => 'merge'],
        ]);
        $final = (string) ($mergeResult['output'] ?? '');
        $running += (float) ($mergeResult['cost_usd'] ?? 0.0);

        return [
            'text'            => $final,
            'cost_usd'        => $running,
            'subtask_results' => $subtaskResults,
            'plan'            => $plan,
            'mode'            => 'smart',
        ];
    }

    private function renderSubtaskPrompt(string $task, string $stepName, string $stepPrompt, array $priorOutputs): string
    {
        $parts = [];
        $parts[] = "ORIGINAL TASK (for context):\n" . $task;
        if ($priorOutputs !== []) {
            $parts[] = "PRIOR SUBTASK OUTPUTS:";
            foreach ($priorOutputs as $p) {
                $parts[] = "[" . $p['name'] . "]\n" . $p['output'];
            }
        }
        $parts[] = "YOUR SUBTASK (id={$stepName}):\n" . $stepPrompt;
        return implode("\n\n", $parts);
    }

    /** @param list<array<string,mixed>> $subtaskResults */
    private function renderMergePrompt(string $task, array $subtaskResults): string
    {
        $parts = ["ORIGINAL USER TASK:\n" . $task];
        foreach ($subtaskResults as $sr) {
            $parts[] = sprintf(
                "[Part %s] tier=%s, provider=%s\n%s",
                $sr['name'], $sr['tier'], $sr['provider'], $sr['output'],
            );
        }
        $parts[] = 'FINAL ANSWER:';
        return implode("\n\n", $parts);
    }

    /** @param list<array<string,mixed>> $subtaskResults */
    private function concatPartial(array $subtaskResults): string
    {
        $out = [];
        foreach ($subtaskResults as $sr) {
            if (!empty($sr['output'])) {
                $out[] = "## " . $sr['name'] . "\n\n" . $sr['output'];
            }
        }
        return implode("\n\n", $out);
    }
}
