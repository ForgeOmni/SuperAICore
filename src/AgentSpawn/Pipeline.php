<?php

namespace SuperAICore\AgentSpawn;

use Psr\Log\LoggerInterface;
use SuperAICore\Models\AiProvider;
use SuperAICore\Runner\TaskResultEnvelope;
use SuperAICore\Services\CapabilityRegistry;
use SuperAICore\Services\Dispatcher;
use SuperAICore\Services\EngineCatalog;

/**
 * Three-phase agent-spawn-emulation pipeline. Lifts the
 * `maybeRunSpawnPlan()` + `runConsolidationPass()` choreography that
 * downstream hosts (SuperTeam, etc.) used to maintain themselves
 * (~150 lines) into SuperAICore so every CLI added to the package
 * inherits it for free.
 *
 * Phases:
 *   1. (in the prompt itself, via `BackendCapabilities::spawnPreamble()` —
 *      already prepended by `transformPrompt()` for codex/gemini today)
 *      The model writes `_spawn_plan.json` to the run's output directory
 *      and stops.
 *   2. {@see Orchestrator::run()} fans out N child CLI processes in
 *      parallel, each writing into its own subdir.
 *   3. Pipeline re-invokes the same backend with the consolidation
 *      prompt (`BackendCapabilities::consolidationPrompt()`) pointing at
 *      every child's output subdir. The model reads them and writes the
 *      final summary/meta files.
 *
 * `TaskRunner` calls `maybeRun()` automatically after the first pass
 * when `options['spawn_plan_dir']` is set; hosts wiring `spawn_plan_dir`
 * pre-Phase-C automatically activated this behavior on upgrade.
 *
 * Returns null when:
 *   - The first pass envelope failed (no point consolidating an error).
 *   - No `_spawn_plan.json` was found under the candidate paths.
 *   - The backend's `BackendCapabilities::consolidationPrompt()` returned
 *     '' (backend doesn't participate in the protocol — claude has its
 *     native Agent tool, kiro/copilot/superagent don't fit).
 *
 * Returning null tells `TaskRunner` "no consolidation happened — keep
 * the first-pass envelope as-is".
 */
class Pipeline
{
    /**
     * @param \Closure(string $engineKey): Orchestrator|null $orchestratorFactory
     *        Test seam — production code defaults to `Orchestrator::forBackend()`.
     *        Pass a custom closure when unit-testing to stub the Phase 2 fanout.
     */
    public function __construct(
        protected CapabilityRegistry $caps,
        protected Dispatcher $dispatcher,
        protected EngineCatalog $catalog,
        protected ?LoggerInterface $logger = null,
        protected ?\Closure $orchestratorFactory = null,
    ) {}

    /**
     * Detect a spawn plan in `$outputDir`, fan out children via
     * Orchestrator, then re-invoke the backend for consolidation.
     *
     * @param string             $backend     dispatcher backend name (e.g. 'codex_cli')
     *                                         OR engine key (e.g. 'codex'). Either resolves correctly.
     * @param string             $outputDir   where the model was told to write the plan + final files
     * @param TaskResultEnvelope $firstPass   envelope returned by the first-pass run
     * @param array              $options     forwarded to Dispatcher for the consolidation re-call
     */
    public function maybeRun(
        string $backend,
        string $outputDir,
        TaskResultEnvelope $firstPass,
        array $options = [],
    ): ?TaskResultEnvelope {
        if (!$firstPass->success) {
            return null;
        }

        $engineKey = $this->resolveEngineKey($backend);
        $capability = $this->caps->for($engineKey);

        // Fast exit: backend doesn't participate in the spawn-plan
        // protocol (capability returns '' for both methods).
        $consolidationProbe = $capability->consolidationPrompt(
            new SpawnPlan([], 1), [], $outputDir
        );
        if ($consolidationProbe === '') {
            return null;
        }

        $planPath = $this->locatePlanFile($outputDir);
        if ($planPath === null) {
            return null;
        }

        // Move plan into the canonical (output dir) location so subsequent
        // runs don't pick up a stale plan from cwd / project root.
        $canonical = rtrim($outputDir, '/\\') . DIRECTORY_SEPARATOR . '_spawn_plan.json';
        if ($planPath !== $canonical) {
            @rename($planPath, $canonical);
            $planPath = $canonical;
        }

        // Resolve the host's agents directory so plans that omit
        // `system_prompt` (the preferred minimal shape) get their role
        // definitions loaded from disk instead of forcing the model to
        // embed multi-line markdown inside JSON.
        $projectRoot = $options['project_root'] ?? \dirname($outputDir, 1);
        $agentsDir = $options['agents_dir']
            ?? rtrim((string) $projectRoot, '/\\') . DIRECTORY_SEPARATOR
               . '.claude' . DIRECTORY_SEPARATOR . 'agents';

        $plan = SpawnPlan::fromFile($planPath, is_dir($agentsDir) ? $agentsDir : null);
        if ($plan === null) {
            $this->log('warning', "Pipeline: spawn plan at {$planPath} failed to parse");
            return null;
        }

        $this->log('info', "Pipeline: spawn plan detected ({$engineKey}) — {$this->agentCount($plan)} agents");

        // Phase 2 — parallel fanout via existing Orchestrator (or test stub)
        try {
            $orchestrator = $this->orchestratorFactory
                ? ($this->orchestratorFactory)($engineKey)
                : Orchestrator::forBackend($engineKey);
        } catch (\InvalidArgumentException $e) {
            // No ChildRunner registered for this engine — bail and let
            // the caller keep the first-pass envelope.
            $this->log('warning', "Pipeline: no ChildRunner for engine '{$engineKey}': {$e->getMessage()}");
            return null;
        }

        $env = array_merge(getenv(), $options['fanout_env'] ?? []);
        $report = $orchestrator->run(
            plan: $plan,
            outputRoot: $outputDir,
            projectRoot: $options['project_root'] ?? \dirname($outputDir, 1),
            env: $env,
            model: $options['model'] ?? null,
        );

        $succeeded = count(array_filter($report, static fn ($r) => ($r['exit'] ?? 1) === 0));
        $this->log('info', "Pipeline: fanout complete — {$succeeded}/" . count($report) . ' agents exit 0');

        // Bubble up the Orchestrator's post-fanout audit warnings (weak-model
        // contract violations: non-whitelisted extensions, sibling-role
        // sub-directories, consolidator-reserved filenames). Surface to the
        // operator via laravel.log so regressions are visible without opening
        // per-agent run.log files in $TMPDIR.
        foreach ($report as $r) {
            $ws = $r['warnings'] ?? [];
            if (!$ws) continue;
            $name = (string) ($r['name'] ?? '?');
            foreach ($ws as $w) {
                $this->log('warning', "Pipeline: audit [{$name}] — {$w}");
            }
        }

        // Phase 3 — consolidation re-call against the same backend
        $consolidationPrompt = $capability->consolidationPrompt($plan, $report, $outputDir);

        $dispatcherBackend = $this->resolveDispatcherBackend($engineKey, $backend);

        $consolidationOptions = array_merge($options, [
            'backend'        => $dispatcherBackend,
            'prompt'         => $consolidationPrompt,
            'stream'         => true,
            // Stamp metadata so the ai_usage_logs row + Process Monitor
            // row tell the operator this was a consolidation pass.
            'task_type'      => $options['task_type'] ?? 'tasks.run',
            'capability'     => ($options['capability'] ?? null) . '.consolidate',
            'external_label' => isset($options['external_label']) ? $options['external_label'] . ':consolidate' : null,
            'metadata'       => array_merge($options['metadata'] ?? [], [
                'spawn_plan_agents' => count($plan->agents),
                'phase'             => 'consolidation',
            ]),
        ]);

        // Strip TaskRunner-only options that shouldn't reach the dispatcher.
        unset(
            $consolidationOptions['prompt_file'],
            $consolidationOptions['summary_file'],
            $consolidationOptions['spawn_plan_dir'],
            $consolidationOptions['fanout_env'],
            $consolidationOptions['project_root'],
        );

        $consolidationResult = $this->dispatcher->dispatch($consolidationOptions);

        if ($consolidationResult === null) {
            $this->log('warning', 'Pipeline: consolidation dispatch returned null');
            // Keep the first-pass envelope but stamp the spawn report so
            // the host can see fanout happened even though consolidation
            // failed.
            return new TaskResultEnvelope(
                success:        $firstPass->success,
                exitCode:       $firstPass->exitCode,
                output:         $firstPass->output,
                summary:        $firstPass->summary,
                usage:          $firstPass->usage,
                costUsd:        $firstPass->costUsd,
                shadowCostUsd:  $firstPass->shadowCostUsd,
                billingModel:   $firstPass->billingModel,
                model:          $firstPass->model,
                backend:        $firstPass->backend,
                durationMs:     $firstPass->durationMs,
                logFile:        $firstPass->logFile,
                usageLogId:     $firstPass->usageLogId,
                spawnReport:    $report,
                error:          'consolidation pass failed — fanout report retained',
            );
        }

        // Merge the two passes into one envelope. `output` keeps both
        // (first pass + consolidation) for traceability; `summary` is
        // the consolidation result (the user-facing answer).
        $consolidationText = (string) ($consolidationResult['text'] ?? '');
        $exitCode = (int) ($consolidationResult['exit_code'] ?? 0);

        // The plan file is an internal mechanism, not a deliverable. Once
        // consolidation has run we no longer need it — remove so it doesn't
        // clutter the output dir the founder browses. Retained on failure
        // paths above (consolidation null, or fanout exceptions) to aid
        // post-mortem debugging.
        if ($exitCode === 0 && $consolidationText !== '') {
            @unlink($planPath);
        }

        return new TaskResultEnvelope(
            success:        $exitCode === 0 && $consolidationText !== '',
            exitCode:       $exitCode,
            output:         $firstPass->output . "\n\n--- consolidation ---\n" . $consolidationText,
            summary:        $consolidationText !== '' ? $consolidationText : $firstPass->summary,
            usage:          $consolidationResult['usage'] ?? $firstPass->usage,
            costUsd:        isset($consolidationResult['cost_usd'])
                                ? (float) $consolidationResult['cost_usd'] + ($firstPass->costUsd ?? 0.0)
                                : $firstPass->costUsd,
            shadowCostUsd:  isset($consolidationResult['shadow_cost_usd'])
                                ? (float) $consolidationResult['shadow_cost_usd'] + ($firstPass->shadowCostUsd ?? 0.0)
                                : $firstPass->shadowCostUsd,
            billingModel:   $consolidationResult['billing_model'] ?? $firstPass->billingModel,
            model:          $consolidationResult['model'] ?? $firstPass->model,
            backend:        $consolidationResult['backend'] ?? $firstPass->backend,
            durationMs:     $firstPass->durationMs + (int) ($consolidationResult['duration_ms'] ?? 0),
            logFile:        $consolidationResult['log_file'] ?? $firstPass->logFile,
            usageLogId:     isset($consolidationResult['usage_log_id'])
                                ? (int) $consolidationResult['usage_log_id']
                                : $firstPass->usageLogId,
            spawnReport:    $report,
        );
    }

    /**
     * Look for `_spawn_plan.json` in candidate directories — model
     * doesn't always honor the absolute-path instruction, so we check
     * the cwd (project root) and a couple of likely fallbacks.
     */
    protected function locatePlanFile(string $outputDir): ?string
    {
        $cwd = getcwd();
        $candidates = [
            rtrim($outputDir, '/\\') . DIRECTORY_SEPARATOR . '_spawn_plan.json',
        ];
        if (is_string($cwd) && $cwd !== '') {
            $candidates[] = $cwd . DIRECTORY_SEPARATOR . '_spawn_plan.json';
        }

        foreach ($candidates as $c) {
            if (is_file($c)) return $c;
        }
        return null;
    }

    /**
     * Accept either a dispatcher-style name (e.g. 'codex_cli') or an
     * engine key ('codex'); always return the engine key the
     * `CapabilityRegistry` and `Orchestrator` expect.
     */
    protected function resolveEngineKey(string $name): string
    {
        // Already an engine key?
        if ($this->catalog->get($name) !== null) {
            return $name;
        }

        // Reverse-look-up via dispatcher backends listed on each engine.
        foreach ($this->catalog->all() as $key => $descriptor) {
            if (in_array($name, $descriptor->dispatcherBackends, true)) {
                return (string) $key;
            }
        }

        // Last-ditch: strip `_cli` suffix (covers the canonical naming
        // even if the catalog lookup somehow misses).
        return preg_replace('/_cli$/', '', $name) ?: $name;
    }

    /**
     * Pick the dispatcher backend for the consolidation re-call: prefer
     * the original dispatcher name if the caller passed one (so we
     * re-invoke the EXACT same engine that produced the plan). Falls
     * back to the engine's first dispatcher backend.
     */
    protected function resolveDispatcherBackend(string $engineKey, string $original): string
    {
        // If caller passed a dispatcher name (codex_cli), reuse it.
        $engine = $this->catalog->get($engineKey);
        if ($engine && in_array($original, $engine->dispatcherBackends, true)) {
            return $original;
        }
        return $engine?->dispatcherBackends[0] ?? ($engineKey . '_cli');
    }

    protected function agentCount(SpawnPlan $plan): int
    {
        return count($plan->agents);
    }

    protected function log(string $level, string $message): void
    {
        if ($this->logger) {
            $this->logger->{$level}($message);
        }
    }
}
