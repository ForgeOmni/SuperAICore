<?php

namespace SuperAICore\Runner;

use Psr\Log\LoggerInterface;
use SuperAICore\AgentSpawn\Pipeline;
use SuperAICore\Services\BackendRegistry;
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
        'fallback_chain',
        'fallback_on',
        'inherit_failure_context',
        'fallback_disabled',
    ];

    private const DEFAULT_FALLBACK_PATTERNS = [
        'rate limit',
        'rate_limit',
        'usage limit',
        'quota',
        'quota_exceeded',
        'exceeded your current quota',
        'too many requests',
        '429',
        'insufficient_quota',
        'billing',
        'budget',
        'limit reached',
        'usage_not_included',
    ];

    private const DEFAULT_AUTO_CHAIN = [
        'claude_cli',
        'codex_cli',
        'gemini_cli',
        'kimi_cli',
        'copilot_cli',
        'kiro_cli',
        'superagent',
        'anthropic_api',
        'openai_api',
        'gemini_api',
    ];

    public function __construct(
        protected Dispatcher $dispatcher,
        protected ?Pipeline $pipeline = null,
        protected ?LoggerInterface $logger = null,
        protected ?BackendRegistry $backendRegistry = null,
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

        // Resolve fallback before side effects like prompt_file writes so
        // each attempt owns its normal persistence path.
        $chain = $this->resolveFallbackChain($backend, $options);
        if (count($chain) > 1) {
            return $this->runWithFallback($chain, $prompt, $options);
        }

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
     * @param string[] $chain
     */
    protected function runWithFallback(array $chain, string $prompt, array $options): TaskResultEnvelope
    {
        $last = null;
        $attempts = [];
        $basePrompt = $prompt;
        $nextPrompt = $prompt;
        $inheritContext = array_key_exists('inherit_failure_context', $options)
            ? (bool) $options['inherit_failure_context']
            : $this->configBool('super-ai-core.task_fallback.inherit_failure_context', true);

        foreach ($chain as $index => $candidate) {
            $attemptOptions = $options;
            unset($attemptOptions['fallback_chain']);
            $attemptOptions['fallback_disabled'] = true;

            if (isset($options['metadata']) && is_array($options['metadata'])) {
                $attemptOptions['metadata'] = array_merge($options['metadata'], [
                    'fallback_chain' => $chain,
                    'fallback_attempt' => $index + 1,
                    'fallback_backend' => $candidate,
                ]);
            }

            $result = $this->run($candidate, $nextPrompt, $attemptOptions);
            $attempts[] = $this->fallbackAttemptSummary($result, $index + 1);

            if ($result->success) {
                return count($attempts) === 1 ? $result : $result->withFallbackReport($attempts);
            }

            $last = $result;
            if ($index === count($chain) - 1 || !$this->shouldFallback($result, $options)) {
                return $result->withFallbackReport($attempts);
            }

            if ($inheritContext) {
                $nextPrompt = $this->buildFallbackPrompt($basePrompt, $result, $candidate, $chain[$index + 1]);
            }
        }

        return ($last ?? TaskResultEnvelope::failed(error: 'TaskRunner fallback chain produced no attempts'))
            ->withFallbackReport($attempts);
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
     *
     * @return string[]
     */
    protected function resolveFallbackChain(string $backend, array $options): array
    {
        if (!empty($options['fallback_disabled'])) {
            return [$backend];
        }

        $chain = $options['fallback_chain'] ?? $this->configValue('super-ai-core.task_fallback.chain');
        if ($chain === 'auto' || $chain === ['auto']) {
            $chain = $this->autoFallbackChain($options);
        }
        if ($chain === null && $this->configBool('super-ai-core.task_fallback.auto_enabled', false)) {
            $chain = $this->autoFallbackChain($options);
        }
        if (is_string($chain)) {
            $chain = array_map('trim', explode(',', $chain));
        }
        if (!is_array($chain)) {
            return [$backend];
        }

        $resolved = [];
        foreach ($chain as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }
            $resolved[] = trim($candidate);
        }

        if (!$resolved || $resolved[0] !== $backend) {
            array_unshift($resolved, $backend);
        }

        return array_values(array_unique($resolved));
    }

    /**
     * @return string[]
     */
    protected function autoFallbackChain(array $options = []): array
    {
        $configured = $this->configValue('super-ai-core.task_fallback.auto_chain');
        $registryNames = $this->backendRegistry ? $this->backendRegistry->names() : [];
        $candidates = is_array($configured) && $configured
            ? $configured
            : ($registryNames ?: self::DEFAULT_AUTO_CHAIN);
        $enabled = (array) $this->configValue('super-ai-core.backends');
        $checkAvailability = $this->configBool('super-ai-core.task_fallback.check_availability', false);

        $chain = [];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }
            $backendConfig = $enabled[$candidate] ?? null;
            if (is_array($backendConfig) && array_key_exists('enabled', $backendConfig) && !$backendConfig['enabled']) {
                continue;
            }
            if ($checkAvailability && $this->backendRegistry) {
                $backend = $this->backendRegistry->get($candidate);
                if (!$backend || !$backend->isAvailable((array) ($options['provider_config'] ?? []))) {
                    continue;
                }
            }
            $chain[] = $candidate;
        }

        return $chain;
    }

    protected function shouldFallback(TaskResultEnvelope $result, array $options): bool
    {
        if ($result->success) {
            return false;
        }

        $patterns = $options['fallback_on'] ?? $this->configValue('super-ai-core.task_fallback.fallback_on');
        if ($patterns === null) {
            $patterns = self::DEFAULT_FALLBACK_PATTERNS;
        }
        if (is_string($patterns)) {
            $patterns = array_map('trim', explode(',', $patterns));
        }

        $haystack = mb_strtolower(trim(implode("\n", array_filter([
            $result->error,
            $result->output,
            $result->summary,
            $result->logFile ? $this->readTail($result->logFile, 8192) : null,
            (string) $result->exitCode,
        ], fn($value) => $value !== null && $value !== ''))));

        if ($haystack === '') {
            return $result->exitCode !== 0;
        }

        foreach ((array) $patterns as $pattern) {
            if (!is_string($pattern) || trim($pattern) === '') {
                continue;
            }
            if (str_contains($haystack, mb_strtolower(trim($pattern)))) {
                return true;
            }
        }

        return false;
    }

    protected function buildFallbackPrompt(string $originalPrompt, TaskResultEnvelope $failed, string $fromBackend, string $toBackend): string
    {
        $failureText = trim($failed->output !== '' ? $failed->output : (string) $failed->error);
        if ($failureText === '' && $failed->logFile) {
            $failureText = $this->readTail($failed->logFile, 4000);
        }
        $failureText = $this->truncate($failureText, 4000);

        return rtrim($originalPrompt) . "\n\n---\n\n"
            . "SuperAICore fallback handoff:\n"
            . "- Previous backend: {$fromBackend}\n"
            . "- Next backend: {$toBackend}\n"
            . "- Previous exit code: {$failed->exitCode}\n"
            . "- Reason: the previous backend appears unavailable, limited, or unable to complete this task.\n"
            . "- Continue the same user task from the original prompt. Use the failure context only to avoid repeating the same blocked path.\n\n"
            . "Previous backend output/log excerpt:\n"
            . "```text\n"
            . $failureText
            . "\n```\n";
    }

    /**
     * @return array<string,mixed>
     */
    protected function fallbackAttemptSummary(TaskResultEnvelope $result, int $attempt): array
    {
        return [
            'attempt' => $attempt,
            'backend' => $result->backend,
            'success' => $result->success,
            'exit_code' => $result->exitCode,
            'model' => $result->model,
            'log_file' => $result->logFile,
            'error' => $result->error ?: ($result->success ? null : $this->truncate($result->output, 500)),
        ];
    }

    protected function readTail(string $path, int $bytes): string
    {
        if (!is_file($path) || !is_readable($path)) {
            return '';
        }

        $size = filesize($path);
        if ($size === false) {
            return '';
        }

        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return '';
        }

        if ($size > $bytes) {
            @fseek($fh, -$bytes, SEEK_END);
        }
        $contents = (string) stream_get_contents($fh);
        @fclose($fh);

        return $contents;
    }

    protected function truncate(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max) . "\n...[truncated]";
    }

    protected function configValue(string $key): mixed
    {
        if (!function_exists('config')) {
            return null;
        }

        try {
            return config($key);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function configBool(string $key, bool $default): bool
    {
        $value = $this->configValue($key);
        return is_bool($value) ? $value : $default;
    }

    protected function writeFile(string $path, string $contents): void
    {
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($path, $contents);
    }
}
