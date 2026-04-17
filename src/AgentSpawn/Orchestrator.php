<?php

namespace SuperAICore\AgentSpawn;

use SuperAICore\Models\AiProvider;

/**
 * Two-phase agent-spawn orchestration for backends that lack a native
 * sub-agent primitive (codex, gemini).
 *
 * Flow:
 *   Phase 1. The backend CLI runs the parent skill with a preamble that
 *            instructs it to emit `_spawn_plan.json` listing the agents
 *            it would have spawned, then STOP without playing roles.
 *   Phase 2. Host detects the plan file and calls
 *            {@see Orchestrator::run()} to fan out N child CLI processes
 *            in parallel (bounded by SpawnPlan::$concurrency).
 *            Each child writes its outputs into the agent's subdir.
 *   Phase 3. Host re-invokes the parent backend with a "consolidate"
 *            prompt that points at the child output files — the model
 *            reads them and produces the final summary/meta files.
 *
 * This class handles Phase 2. Phase 1 is passive (the backend preamble
 * already instructs the model). Phase 3 lives in the host orchestrator
 * (SuperTeam's ExecuteTask), which knows the final-summary requirements.
 */
class Orchestrator
{
    public function __construct(
        protected ChildRunner $runner,
    ) {}

    /**
     * Factory — pick the right runner for a backend.
     */
    public static function forBackend(string $backend, ?string $binary = null): self
    {
        $runner = match ($backend) {
            AiProvider::BACKEND_CODEX  => new CodexChildRunner($binary ?: 'codex'),
            AiProvider::BACKEND_GEMINI => new GeminiChildRunner($binary ?: 'gemini'),
            default => throw new \InvalidArgumentException("No ChildRunner for backend {$backend}"),
        };
        return new self($runner);
    }

    /**
     * Execute every agent in the plan in parallel (bounded by $plan->concurrency).
     * Returns a report per agent — caller collects the output files from
     * each agent's $outputSubdir.
     *
     * @return array<int,array{name:string,exit:int,log:string,duration_ms:int,error:?string}>
     */
    public function run(
        SpawnPlan $plan,
        string $outputRoot,
        string $projectRoot,
        array $env = [],
        ?string $model = null,
        ?callable $onAgentStart = null,
        ?callable $onAgentFinish = null,
    ): array {
        $report = [];
        $pool = [];  // running processes

        foreach ($plan->agents as $agent) {
            // Ensure per-agent output dir exists
            $subdir = rtrim($outputRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $agent['output_subdir'];
            if (!is_dir($subdir)) @mkdir($subdir, 0755, true);

            // Throttle to concurrency limit — block until a slot opens
            while (count($pool) >= $plan->concurrency) {
                foreach ($pool as $i => $entry) {
                    if (!$entry['process']->isRunning()) {
                        $report[] = $this->finalize($entry, $onAgentFinish);
                        unset($pool[$i]);
                    }
                }
                if (count($pool) >= $plan->concurrency) usleep(200_000);  // 200ms
            }

            $logFile = $subdir . DIRECTORY_SEPARATOR . 'run.log';
            $process = $this->runner->build($agent, $outputRoot, $logFile, $projectRoot, $env, $model);
            $startedAt = microtime(true);
            $process->start();

            $entry = [
                'agent' => $agent,
                'process' => $process,
                'log' => $logFile,
                'started_at' => $startedAt,
            ];
            $pool[] = $entry;

            if ($onAgentStart) $onAgentStart($agent, $process);
        }

        // Drain remaining
        while (!empty($pool)) {
            foreach ($pool as $i => $entry) {
                if (!$entry['process']->isRunning()) {
                    $report[] = $this->finalize($entry, $onAgentFinish);
                    unset($pool[$i]);
                }
            }
            if (!empty($pool)) usleep(200_000);
        }

        return $report;
    }

    protected function finalize(array $entry, ?callable $onAgentFinish): array
    {
        $process = $entry['process'];
        $exit = (int) $process->getExitCode();
        $duration = (int) round((microtime(true) - $entry['started_at']) * 1000);
        $error = $exit === 0 ? null : trim($process->getErrorOutput() ?: '');

        $result = [
            'name' => $entry['agent']['name'],
            'exit' => $exit,
            'log' => $entry['log'],
            'duration_ms' => $duration,
            'error' => $error,
        ];

        if ($onAgentFinish) $onAgentFinish($entry['agent'], $result);

        return $result;
    }
}
