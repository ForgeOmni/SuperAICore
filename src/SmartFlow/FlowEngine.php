<?php

declare(strict_types=1);

namespace SuperAICore\SmartFlow;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SuperAICore\Contracts\Backend;

/**
 * Top-level orchestrator for SmartFlow — the multi-CLI port of Claude Code's
 * built-in `Workflow`. Wires together the persona registry, cross-CLI agent
 * runner, call-ledger (fresh or resumed), budget, and the {@see Flow} context,
 * runs the flow body, and returns a {@see FlowResult}.
 *
 *   $engine = new FlowEngine();
 *   $result = $engine->run($definition, ['goal' => '...'], new FlowOptions(rehearse: true));
 *
 * The flow body may be a {@see FlowDefinition} or a bare `callable(Flow): mixed`.
 * CLI execution is delegated to a batch runner: by default a sequential runner
 * built on {@see BackendAgentRunner}; multi-call batches run on a
 * {@see ProcessPool} for true wall-clock concurrency across CLIs, and tests
 * inject their own.
 */
final class FlowEngine
{
    private PersonaRegistry $personas;
    private LoggerInterface $logger;
    /** @var (callable(string): ?Backend)|null */
    private $backendResolver;

    /**
     * @param (callable(string): ?Backend)|null $backendResolver
     */
    public function __construct(
        ?PersonaRegistry $personas = null,
        ?LoggerInterface $logger = null,
        ?callable $backendResolver = null,
    ) {
        $this->personas = $personas ?? PersonaRegistry::load();
        $this->logger = $logger ?? new NullLogger();
        $this->backendResolver = $backendResolver;
    }

    public function personas(): PersonaRegistry
    {
        return $this->personas;
    }

    /**
     * @param FlowDefinition|callable(Flow): mixed $flow
     * @param array<string, mixed> $args
     */
    public function run(FlowDefinition|callable $flow, array $args = [], ?FlowOptions $opts = null): FlowResult
    {
        $opts ??= new FlowOptions();
        $definition = $flow instanceof FlowDefinition
            ? $flow
            : FlowDefinition::make('anonymous', '', $flow);

        $fake = $opts->isFake();
        $runId = $opts->runId ?? CallLedger::newRunId($definition->name);

        // Ledger: resume reads the prior run's entries; the new run writes a
        // fresh file (in-memory for dry runs).
        $dir = $opts->ledgerDir ?? CallLedger::resolveDir();
        $prior = [];
        if ($opts->resumeRunId !== null && $opts->resumeRunId !== '') {
            $priorPath = rtrim($dir, '/\\') . '/' . $opts->resumeRunId . '.jsonl';
            $prior = CallLedger::readEntries($priorPath);
        }
        $path = $opts->dryRun ? null : rtrim($dir, '/\\') . '/' . $runId . '.jsonl';
        $ledger = new CallLedger($runId, $path, $prior);

        $budget = new Budget(
            totalUsd: $opts->budgetUsd ?? $this->defaultBudgetUsd($definition),
            totalTokens: $opts->budgetTokens ?? $this->defaultBudgetTokens($definition),
        );

        $batchRunner = $opts->batchRunner ?? $this->defaultBatchRunner($opts, $fake);

        $flowContext = new Flow(
            args: $args,
            budget: $budget,
            batchRunner: $batchRunner,
            ledger: $ledger,
            listeners: $opts->listeners,
            logger: $this->logger,
        );

        $status = 'completed';
        $error = null;
        $value = null;

        try {
            $value = $definition->run($flowContext);
        } catch (BudgetExceededException $e) {
            $status = 'failed';
            $error = $e->getMessage();
            $this->logger->warning('Flow stopped: budget exceeded', ['flow' => $definition->name, 'error' => $error]);
        } catch (\Throwable $e) {
            $status = 'failed';
            $error = $e->getMessage();
            $this->logger->error('Flow failed', ['flow' => $definition->name, 'error' => $error]);
        }

        return new FlowResult(
            name: $definition->name,
            runId: $runId,
            status: $status,
            value: $value,
            ledger: $ledger->summary(),
            budget: $budget->toArray(),
            flowSignature: FlowSignature::forFlow($definition->name, $args),
            fake: $fake,
            error: $error,
            ledgerPath: $path,
        );
    }

    /**
     * Build the batch runner. Multi-call batches run on a {@see ProcessPool} for
     * true cross-CLI concurrency; rehearsals, single calls, injected-resolver
     * test runs, and environments where `proc_open` is unavailable stay
     * in-process and deterministic.
     *
     * @return callable(list<AgentCall>): list<AgentResult>
     */
    private function defaultBatchRunner(FlowOptions $opts, bool $fake): callable
    {
        $runner = new BackendAgentRunner(
            personas: $this->personas,
            fake: $fake,
            defaultBackend: $opts->defaultBackend,
            defaultModel: $opts->defaultModel,
            logger: $this->logger,
            backendResolver: $this->backendResolver,
        );

        $sequential = static function (array $calls) use ($runner): array {
            $out = [];
            foreach ($calls as $call) {
                $out[] = $runner->run($call);
            }
            return $out;
        };

        // Rehearsal must stay in-process (deterministic, zero cost). An injected
        // backend resolver is a closure we cannot ship to a subprocess.
        if ($fake || $this->backendResolver !== null) {
            return $sequential;
        }

        $concurrency = $opts->concurrency ?? (int) Cfg::get('super-ai-core.smartflow.concurrency', 4);
        if ($concurrency <= 1) {
            return $sequential;
        }

        $pool = new ProcessPool(
            concurrency: $concurrency,
            basePath: getcwd() ?: null,
            defaultBackend: $opts->defaultBackend,
            defaultModel: $opts->defaultModel,
            fake: false,
            logger: $this->logger,
        );
        if (!$pool->isAvailable()) {
            return $sequential;
        }

        return static function (array $calls) use ($pool, $sequential): array {
            // One call doesn't justify a subprocess round-trip.
            return count($calls) <= 1 ? $sequential($calls) : $pool->runBatch($calls);
        };
    }

    private function defaultBudgetUsd(FlowDefinition $def): ?float
    {
        if (isset($def->defaults['budget_usd'])) {
            return (float) $def->defaults['budget_usd'];
        }
        $v = Cfg::get('super-ai-core.smartflow.budget.usd');
        if (is_numeric($v)) {
            return (float) $v;
        }
        return null;
    }

    private function defaultBudgetTokens(FlowDefinition $def): ?int
    {
        if (isset($def->defaults['budget_tokens'])) {
            return (int) $def->defaults['budget_tokens'];
        }
        $v = Cfg::get('super-ai-core.smartflow.budget.tokens');
        if (is_numeric($v)) {
            return (int) $v;
        }
        return null;
    }
}
