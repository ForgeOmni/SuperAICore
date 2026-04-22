<?php

namespace SuperAICore\Runner;

use Psr\Log\LoggerInterface;
use SuperAICore\AgentSpawn\Pipeline;
use SuperAICore\Services\Dispatcher;

/**
 * One-call execution entry point for hosts that drive long-running CLI
 * task runs.
 *
 * Replaces the ~150 lines of "build prompt file → spawn → tee log →
 * extract usage → wrap into result array" that downstream hosts
 * (SuperTeam, Shopify Autopilot, ...) each invented for themselves.
 * Internally drives `Dispatcher::dispatch(['stream' => true, ...])`
 * (the Phase A primitive), wraps the result into the canonical
 * {@see TaskResultEnvelope}, and offers two convenience persistence
 * hooks (`prompt_file`, `summary_file`) so hosts can keep their
 * existing on-disk debug breadcrumbs without a custom file write.
 *
 * Spawn-plan support is opt-in via `spawn_plan_dir` and currently
 * a no-op — Phase C lands `AgentSpawn\Pipeline` and TaskRunner will
 * call into it transparently. Hosts can wire `spawn_plan_dir` today
 * and pick up the behavior automatically when they upgrade.
 *
 * Typical host usage:
 *
 *   $envelope = app(TaskRunner::class)->run('claude_cli', $prompt, [
 *       'log_file'      => $logFile,
 *       'prompt_file'   => $promptFile,        // optional — debug breadcrumb
 *       'summary_file'  => $summaryFile,       // optional — write final text
 *       'timeout'       => 7200,
 *       'idle_timeout'  => 1800,
 *       'mcp_mode'      => 'empty',
 *       'task_type'     => 'tasks.run',
 *       'capability'    => $task->type,
 *       'user_id'       => auth()->id(),
 *       'provider_id'   => $providerId,
 *       'metadata'      => ['task_id' => $task->id],
 *       'onChunk'       => fn ($chunk) => $taskResult->updateQuietly(['preview' => $chunk]),
 *   ]);
 *
 *   if ($envelope->success) {
 *       $taskResult->update([
 *           'content'      => $envelope->summary,
 *           'raw_output'   => $envelope->output,
 *           'metadata'     => ['usage' => $envelope->usage, 'cost_usd' => $envelope->costUsd],
 *           'status'       => 'success',
 *           'finished_at'  => now(),
 *       ]);
 *   }
 *
 * No host code change is required to keep using `Dispatcher::dispatch()`
 * directly — `TaskRunner` is purely additive.
 */
class TaskRunner
{
    /**
     * TaskRunner-only options that should NOT be forwarded to Dispatcher
     * (they're consumed here for pre/post processing).
     */
    private const RUNNER_ONLY_OPTIONS = [
        'prompt_file',
        'summary_file',
        'spawn_plan_dir',
    ];

    public function __construct(
        protected Dispatcher $dispatcher,
        protected ?Pipeline $pipeline = null,
        protected ?LoggerInterface $logger = null,
    ) {}

    /**
     * Execute a single task.
     *
     * @param string $backend  Backend name (e.g. 'claude_cli', 'kiro_cli')
     * @param string $prompt   Fully-built prompt — host owns prompt construction
     * @param array  $options  See class docblock for the full option set.
     *                         All keys are forwarded to Dispatcher except
     *                         the `RUNNER_ONLY_OPTIONS` listed above.
     */
    public function run(string $backend, string $prompt, array $options = []): TaskResultEnvelope
    {
        if ($prompt === '') {
            return TaskResultEnvelope::failed(
                exitCode: 1,
                error: 'TaskRunner: empty prompt',
                backend: $backend,
            );
        }

        // Optional debug breadcrumb — keeps host parity with the
        // hand-rolled spawn pattern that always wrote a prompt file.
        if (!empty($options['prompt_file'])) {
            $this->writeFile((string) $options['prompt_file'], $prompt);
        }

        $dispatchOptions = $this->buildDispatchOptions($backend, $prompt, $options);
        $result = $this->dispatcher->dispatch($dispatchOptions);

        if ($result === null) {
            return TaskResultEnvelope::failed(
                exitCode: 1,
                logFile: $options['log_file'] ?? null,
                error: 'Dispatcher returned no result — provider not configured, CLI not signed in, backend disabled, or model rejected the request.',
                backend: $backend,
            );
        }

        $text = (string) ($result['text'] ?? '');

        // Optional: persist the assistant's final text where the host wants it
        if (!empty($options['summary_file']) && $text !== '') {
            $this->writeFile((string) $options['summary_file'], $text);
        }

        $exitCode = isset($result['exit_code']) ? (int) $result['exit_code'] : 0;
        $success = $exitCode === 0 && $text !== '';

        $firstPass = new TaskResultEnvelope(
            success:        $success,
            exitCode:       $exitCode,
            output:         $text,
            summary:        $text,
            usage:          $result['usage'] ?? [],
            costUsd:        isset($result['cost_usd']) ? (float) $result['cost_usd'] : null,
            shadowCostUsd:  isset($result['shadow_cost_usd']) ? (float) $result['shadow_cost_usd'] : null,
            billingModel:   $result['billing_model'] ?? null,
            model:          $result['model'] ?? null,
            backend:        $result['backend'] ?? $backend,
            durationMs:     (int) ($result['duration_ms'] ?? 0),
            logFile:        $result['log_file'] ?? ($options['log_file'] ?? null),
            usageLogId:     isset($result['usage_log_id']) ? (int) $result['usage_log_id'] : null,
            spawnReport:    null,
        );

        // Phase C activation — if the host wired `spawn_plan_dir` and
        // Pipeline is available, hand off to it. Pipeline returns null
        // when no plan was found / backend doesn't participate / first
        // pass failed, in which case we keep the first-pass envelope
        // unchanged. Pipeline's success doesn't auto-rewrite the
        // summary_file (that's per-phase semantics — the consolidation
        // re-call writes its own files via the model itself).
        if ($success && $this->pipeline !== null && !empty($options['spawn_plan_dir'])) {
            $consolidated = $this->pipeline->maybeRun(
                backend: $backend,
                outputDir: (string) $options['spawn_plan_dir'],
                firstPass: $firstPass,
                options: $options,
            );
            if ($consolidated !== null) {
                return $consolidated;
            }
        }

        return $firstPass;
    }

    /**
     * Strip TaskRunner-only options + force `stream: true` + ensure
     * backend / prompt land in the dispatch payload.
     */
    protected function buildDispatchOptions(string $backend, string $prompt, array $options): array
    {
        foreach (self::RUNNER_ONLY_OPTIONS as $key) {
            unset($options[$key]);
        }
        $options['backend'] = $backend;
        $options['prompt'] = $prompt;
        $options['stream'] = true;
        return $options;
    }

    /**
     * Best-effort write — creates parent dir if missing, swallows write
     * failures (host's persistence is debug-only convenience; a failed
     * tee shouldn't kill the run).
     */
    protected function writeFile(string $path, string $contents): void
    {
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($path, $contents);
    }
}
