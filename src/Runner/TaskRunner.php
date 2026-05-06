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
        'fallback_profile',
        'fallback_on',
        'fallback_max_attempts',
        'fallback_max_cost_usd',
        'fallback_backoff_ms',
        'fallback_backoff_strategy',
        'fallback_cooldown_seconds',
        'fallback_cooldown_min_failures',
        'fallback_success_min_chars',
        'fallback_success_forbidden_patterns',
        'inherit_failure_context',
        'fallback_disabled',
        'onAttemptStart',
        'onAttemptFinish',
        'onFallback',
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

    /** @var array<string,int> */
    private static array $cooldowns = [];
    /** @var array<string,int> */
    private static array $cooldownFailures = [];

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
        $skipped = [];
        $decisionEvents = [];
        $basePrompt = $prompt;
        $nextPrompt = $prompt;
        $totalCostUsd = 0.0;
        $maxAttempts = $this->intOption($options, 'fallback_max_attempts', 'super-ai-core.task_fallback.max_attempts', 0);
        $maxCostUsd = $this->floatOption($options, 'fallback_max_cost_usd', 'super-ai-core.task_fallback.max_cost_usd', 0.0);
        $inheritContext = array_key_exists('inherit_failure_context', $options)
            ? (bool) $options['inherit_failure_context']
            : $this->configBool('super-ai-core.task_fallback.inherit_failure_context', true);

        foreach ($chain as $index => $candidate) {
            if ($maxAttempts > 0 && count($attempts) >= $maxAttempts) {
                $decisionEvents[] = [
                    'event' => 'stop',
                    'reason' => 'max_attempts_reached',
                    'max_attempts' => $maxAttempts,
                ];
                break;
            }

            $cooldown = $this->cooldownRemaining($candidate);
            if ($cooldown > 0) {
                $skipped[] = [
                    'backend' => $candidate,
                    'reason' => 'cooldown',
                    'cooldown_remaining_seconds' => $cooldown,
                ];
                $decisionEvents[] = [
                    'event' => 'skip',
                    'backend' => $candidate,
                    'reason' => 'cooldown',
                    'cooldown_remaining_seconds' => $cooldown,
                ];
                continue;
            }

            $attemptOptions = $options;
            unset($attemptOptions['fallback_chain']);
            $attemptOptions['fallback_disabled'] = true;

            $attemptOptions['metadata'] = array_merge(
                isset($options['metadata']) && is_array($options['metadata']) ? $options['metadata'] : [],
                [
                    'fallback_active' => true,
                    'fallback_chain' => $chain,
                    'fallback_attempt' => $index + 1,
                    'fallback_primary_backend' => $chain[0] ?? $candidate,
                    'fallback_backend' => $candidate,
                    'fallback_chain_index' => $index,
                ],
            );

            $this->invokeCallback($options['onAttemptStart'] ?? null, [
                'attempt' => count($attempts) + 1,
                'chain_index' => $index,
                'backend' => $candidate,
                'chain' => $chain,
            ]);

            $result = $this->run($candidate, $nextPrompt, $attemptOptions);
            if ($result->success && $result->backend) {
                self::$cooldownFailures[$result->backend] = 0;
            }
            $totalCostUsd += $result->costUsd ?? 0.0;
            $fallbackReason = $this->fallbackReason($result, $options);
            $qualityReason = $result->success ? $this->qualityFailureReason($result, $options) : null;
            $retryable = (
                    (!$result->success && $fallbackReason['retryable'])
                    || ($result->success && $qualityReason !== null)
                )
                && $this->hasNextRunnableBackend($chain, $index);
            if ($maxCostUsd > 0.0 && $totalCostUsd >= $maxCostUsd) {
                $retryable = false;
                $decisionEvents[] = [
                    'event' => 'stop',
                    'backend' => $candidate,
                    'reason' => 'max_cost_reached',
                    'max_cost_usd' => $maxCostUsd,
                    'total_cost_usd' => $totalCostUsd,
                ];
            }
            $nextBackend = $retryable ? $this->nextRunnableBackend($chain, $index) : null;
            $attempts[] = $this->fallbackAttemptSummary(
                result: $result,
                attempt: count($attempts) + 1,
                retryable: $retryable,
                nextBackend: $nextBackend,
                failureClass: $fallbackReason['class'] ?? null,
                matchedPattern: $fallbackReason['matched_pattern'] ?? null,
                qualityReason: $qualityReason,
            );
            $this->recordCooldownFailure($result, $fallbackReason, $options);

            $this->invokeCallback($options['onAttemptFinish'] ?? null, [
                'attempt' => count($attempts),
                'chain_index' => $index,
                'backend' => $candidate,
                'success' => $result->success,
                'retryable' => $retryable,
                'next_backend' => $nextBackend,
                'failure_class' => $fallbackReason['class'] ?? null,
                'matched_pattern' => $fallbackReason['matched_pattern'] ?? null,
                'quality_reason' => $qualityReason,
            ]);

            if ($result->success && $qualityReason === null) {
                $decisionEvents[] = [
                    'event' => 'stop',
                    'backend' => $candidate,
                    'reason' => 'success',
                ];
                return $result->withFallbackReport($attempts, $this->fallbackDecision($chain, $options, $skipped, $decisionEvents, $totalCostUsd));
            }

            $last = $result;
            if ($index === count($chain) - 1 || !$retryable) {
                $decisionEvents[] = [
                    'event' => 'stop',
                    'backend' => $candidate,
                    'reason' => $result->success ? 'quality_guard_terminal' : ($fallbackReason['reason'] ?? 'non_retryable_failure'),
                    'failure_class' => $fallbackReason['class'] ?? null,
                    'matched_pattern' => $fallbackReason['matched_pattern'] ?? null,
                    'quality_reason' => $qualityReason,
                ];
                return $result->withFallbackReport($attempts, $this->fallbackDecision($chain, $options, $skipped, $decisionEvents, $totalCostUsd));
            }

            $this->invokeCallback($options['onFallback'] ?? null, [
                'from_backend' => $candidate,
                'to_backend' => $nextBackend,
                'attempt' => count($attempts),
                'failure_class' => $fallbackReason['class'] ?? null,
                'matched_pattern' => $fallbackReason['matched_pattern'] ?? null,
                'quality_reason' => $qualityReason,
            ]);
            $decisionEvents[] = [
                'event' => 'fallback',
                'from_backend' => $candidate,
                'to_backend' => $nextBackend,
                'failure_class' => $fallbackReason['class'] ?? null,
                'matched_pattern' => $fallbackReason['matched_pattern'] ?? null,
                'quality_reason' => $qualityReason,
            ];
            $this->applyBackoff($options, count($attempts));

            if ($inheritContext) {
                $nextPrompt = $this->buildFallbackPrompt($basePrompt, $result, $candidate, (string) $nextBackend);
            }
        }

        $decisionEvents[] = [
            'event' => 'stop',
            'reason' => $attempts ? 'chain_exhausted' : 'no_runnable_backend',
        ];

        return ($last ?? TaskResultEnvelope::failed(error: 'TaskRunner fallback chain produced no attempts'))
            ->withFallbackReport($attempts, $this->fallbackDecision($chain, $options, $skipped, $decisionEvents, $totalCostUsd));
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

        $chain = $options['fallback_chain']
            ?? $this->resolveWorkloadFallbackChain($options)
            ?? $this->configValue('super-ai-core.task_fallback.chain');
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

    public function explainFallbackChain(string $backend, array $options = []): array
    {
        $chain = $this->resolveFallbackChain($backend, $options);
        $skipped = [];
        $runnable = [];
        foreach ($chain as $candidate) {
            $cooldown = $this->cooldownRemaining($candidate);
            if ($cooldown > 0) {
                $skipped[] = [
                    'backend' => $candidate,
                    'reason' => 'cooldown',
                    'cooldown_remaining_seconds' => $cooldown,
                ];
                continue;
            }
            $runnable[] = $candidate;
        }

        return [
            'primary_backend' => $backend,
            'chain' => $chain,
            'runnable_chain' => $runnable,
            'skipped' => $skipped,
            'source' => $this->fallbackChainSource($options),
            'max_attempts' => $this->intOption($options, 'fallback_max_attempts', 'super-ai-core.task_fallback.max_attempts', 0),
            'max_cost_usd' => $this->floatOption($options, 'fallback_max_cost_usd', 'super-ai-core.task_fallback.max_cost_usd', 0.0),
        ];
    }

    protected function fallbackChainSource(array $options): string
    {
        if (array_key_exists('fallback_chain', $options)) {
            return 'option:fallback_chain';
        }
        $metadata = isset($options['metadata']) && is_array($options['metadata']) ? $options['metadata'] : [];
        if (($options['fallback_profile'] ?? ($metadata['fallback_profile'] ?? null)) !== null) {
            return 'profile';
        }
        if (($options['task_type'] ?? ($metadata['task_type'] ?? null)) !== null) {
            return 'task_type';
        }
        if (($options['capability'] ?? ($metadata['capability'] ?? null)) !== null) {
            return 'capability';
        }
        foreach (['task_kind', 'priority', 'requires_tools'] as $key) {
            if (array_key_exists($key, $metadata)) {
                return 'metadata:' . $key;
            }
        }
        if ($this->configValue('super-ai-core.task_fallback.chain') !== null) {
            return 'config:chain';
        }
        if ($this->configBool('super-ai-core.task_fallback.auto_enabled', false)) {
            return 'config:auto';
        }
        return 'none';
    }

    protected function hasNextRunnableBackend(array $chain, int $index): bool
    {
        return $this->nextRunnableBackend($chain, $index) !== null;
    }

    protected function nextRunnableBackend(array $chain, int $index): ?string
    {
        for ($i = $index + 1; $i < count($chain); $i++) {
            $candidate = $chain[$i] ?? null;
            if (is_string($candidate) && $candidate !== '' && $this->cooldownRemaining($candidate) <= 0) {
                return $candidate;
            }
        }

        return null;
    }

    protected function shouldFallback(TaskResultEnvelope $result, array $options): bool
    {
        return $this->fallbackReason($result, $options)['retryable'];
    }

    /**
     * @return array{retryable:bool,reason:string,class:?string,matched_pattern:?string}
     */
    protected function fallbackReason(TaskResultEnvelope $result, array $options): array
    {
        if ($result->success) {
            return ['retryable' => false, 'reason' => 'success', 'class' => null, 'matched_pattern' => null];
        }

        $haystack = $this->failureHaystack($result);
        $classes = $this->classifyFailure($haystack);
        $allowed = $this->normaliseList($options['fallback_on'] ?? $this->configValue('super-ai-core.task_fallback.fallback_on') ?? self::DEFAULT_FALLBACK_PATTERNS);

        if ($haystack === '') {
            return ['retryable' => $result->exitCode !== 0, 'reason' => 'empty_failure_with_nonzero_exit', 'class' => 'unknown', 'matched_pattern' => null];
        }

        foreach ($allowed as $pattern) {
            $needle = mb_strtolower($pattern);
            if (($classes['class'] ?? null) === $needle) {
                return ['retryable' => true, 'reason' => 'matched_class', 'class' => $classes['class'], 'matched_pattern' => $classes['matched_pattern'] ?? $pattern];
            }
            if (str_contains($haystack, $needle)) {
                return ['retryable' => true, 'reason' => 'matched_pattern', 'class' => $classes['class'], 'matched_pattern' => $pattern];
            }
        }

        return ['retryable' => false, 'reason' => 'no_match', 'class' => $classes['class'], 'matched_pattern' => $classes['matched_pattern']];
    }

    protected function failureHaystack(TaskResultEnvelope $result): string
    {
        return mb_strtolower(trim(implode("\n", array_filter([
            $result->error,
            $result->output,
            $result->summary,
            $result->logFile ? $this->readTail($result->logFile, 8192) : null,
            (string) $result->exitCode,
        ], fn($value) => $value !== null && $value !== ''))));
    }

    /**
     * @return array{class:?string,matched_pattern:?string}
     */
    protected function classifyFailure(string $haystack): array
    {
        $classes = $this->configValue('super-ai-core.task_fallback.failure_classes');
        if (!is_array($classes)) {
            $classes = [
                'quota' => ['quota', 'quota_exceeded', 'insufficient_quota', 'usage_not_included', 'billing', 'budget'],
                'rate_limit' => ['rate limit', 'rate_limit', 'too many requests', '429', 'limit reached'],
                'auth' => ['unauthorized', 'forbidden', 'invalid api key', 'not signed in', 'login required'],
                'tool_policy' => ['permission denied', 'policy', 'not allowed', 'approval required'],
                'validation' => ['invalid prompt', 'missing required', 'validation'],
                'network' => ['timeout', 'connection refused', 'could not resolve', 'network'],
            ];
        }

        foreach ($classes as $class => $patterns) {
            foreach ($this->normaliseList($patterns) as $pattern) {
                if ($pattern !== '' && str_contains($haystack, mb_strtolower($pattern))) {
                    return ['class' => (string) $class, 'matched_pattern' => $pattern];
                }
            }
        }

        return ['class' => null, 'matched_pattern' => null];
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
    protected function fallbackAttemptSummary(
        TaskResultEnvelope $result,
        int $attempt,
        bool $retryable = false,
        ?string $nextBackend = null,
        ?string $failureClass = null,
        ?string $matchedPattern = null,
        ?string $qualityReason = null,
    ): array
    {
        return [
            'attempt' => $attempt,
            'backend' => $result->backend,
            'success' => $result->success,
            'retryable' => $retryable,
            'next_backend' => $nextBackend,
            'failure_class' => $failureClass,
            'matched_pattern' => $matchedPattern,
            'quality_reason' => $qualityReason,
            'exit_code' => $result->exitCode,
            'model' => $result->model,
            'duration_ms' => $result->durationMs,
            'usage_log_id' => $result->usageLogId,
            'cost_usd' => $result->costUsd,
            'billing_model' => $result->billingModel,
            'log_file' => $result->logFile,
            'error' => $result->error ?: ($result->success ? null : $this->truncate($result->output, 500)),
        ];
    }

    protected function resolveWorkloadFallbackChain(array $options): mixed
    {
        $metadata = isset($options['metadata']) && is_array($options['metadata']) ? $options['metadata'] : [];

        $profile = $options['fallback_profile'] ?? ($metadata['fallback_profile'] ?? null);
        if (is_string($profile) && $profile !== '') {
            $chain = $this->chainFromMap('super-ai-core.task_fallback.chains_by_profile', $profile);
            if ($chain !== null) {
                return $chain;
            }
        }

        $taskType = $options['task_type'] ?? ($metadata['task_type'] ?? null);
        if (is_string($taskType) && $taskType !== '') {
            $chain = $this->chainFromMap('super-ai-core.task_fallback.chains_by_task_type', $taskType);
            if ($chain !== null) {
                return $chain;
            }
        }

        $capability = $options['capability'] ?? ($metadata['capability'] ?? null);
        if (is_string($capability) && $capability !== '') {
            $chain = $this->chainFromMap('super-ai-core.task_fallback.chains_by_capability', $capability);
            if ($chain !== null) {
                return $chain;
            }
        }

        $metadataChains = $this->configValue('super-ai-core.task_fallback.chains_by_metadata');
        if (is_array($metadataChains)) {
            foreach ($metadataChains as $key => $map) {
                if (!is_string($key) || !is_array($map) || !array_key_exists($key, $metadata)) {
                    continue;
                }
                $value = $metadata[$key];
                if (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                } elseif (is_array($value)) {
                    $value = implode(',', array_map('strval', $value));
                } elseif (!is_scalar($value)) {
                    continue;
                }

                $value = (string) $value;
                if (array_key_exists($value, $map)) {
                    return $map[$value];
                }
            }
        }

        return null;
    }

    protected function chainFromMap(string $configKey, string $name): mixed
    {
        $map = $this->configValue($configKey);
        if (!is_array($map) || !array_key_exists($name, $map)) {
            return null;
        }

        return $map[$name];
    }

    protected function qualityFailureReason(TaskResultEnvelope $result, array $options): ?string
    {
        $minChars = $this->intOption($options, 'fallback_success_min_chars', 'super-ai-core.task_fallback.success_min_chars', 0);
        if ($minChars > 0 && mb_strlen(trim($result->summary !== '' ? $result->summary : $result->output)) < $minChars) {
            return 'output_too_short';
        }

        $patterns = $this->normaliseList($options['fallback_success_forbidden_patterns'] ?? $this->configValue('super-ai-core.task_fallback.success_forbidden_patterns') ?? []);
        $haystack = mb_strtolower($result->summary . "\n" . $result->output);
        foreach ($patterns as $pattern) {
            if ($pattern !== '' && str_contains($haystack, mb_strtolower($pattern))) {
                return 'forbidden_success_pattern:' . $pattern;
            }
        }

        return null;
    }

    protected function fallbackDecision(array $chain, array $options, array $skipped, array $events, float $totalCostUsd): array
    {
        return [
            'source' => $this->fallbackChainSource($options),
            'chain' => $chain,
            'skipped' => $skipped,
            'events' => $events,
            'total_cost_usd' => $totalCostUsd,
        ];
    }

    protected function recordCooldownFailure(TaskResultEnvelope $result, array $reason, array $options): void
    {
        if ($result->success || empty($reason['retryable']) || !$this->configBool('super-ai-core.task_fallback.cooldown.enabled', false)) {
            return;
        }

        $backend = $result->backend;
        if (!$backend) {
            return;
        }

        $seconds = $this->intOption($options, 'fallback_cooldown_seconds', 'super-ai-core.task_fallback.cooldown.seconds', 0);
        if ($seconds <= 0) {
            return;
        }

        $minFailures = max(1, $this->intOption($options, 'fallback_cooldown_min_failures', 'super-ai-core.task_fallback.cooldown.min_failures', 1));
        self::$cooldownFailures[$backend] = (self::$cooldownFailures[$backend] ?? 0) + 1;
        if (self::$cooldownFailures[$backend] < $minFailures) {
            return;
        }

        $until = time() + $seconds;
        self::$cooldowns[$backend] = $until;
        self::$cooldownFailures[$backend] = 0;

        if (function_exists('cache')) {
            try {
                cache()->put($this->cooldownKey($backend), $until, $seconds);
            } catch (\Throwable) {
                // In-memory fallback above is enough for non-Laravel tests.
            }
        }
    }

    protected function cooldownRemaining(string $backend): int
    {
        $until = self::$cooldowns[$backend] ?? null;
        if (function_exists('cache')) {
            try {
                $cached = cache()->get($this->cooldownKey($backend));
                if (is_numeric($cached)) {
                    $until = max((int) $cached, (int) ($until ?? 0));
                }
            } catch (\Throwable) {
                // Ignore unavailable cache stores.
            }
        }

        if (!$until || $until <= time()) {
            unset(self::$cooldowns[$backend]);
            return 0;
        }

        return $until - time();
    }

    protected function cooldownKey(string $backend): string
    {
        return 'super-ai-core:task-fallback-cooldown:' . $backend;
    }

    protected function applyBackoff(array $options, int $attempt): void
    {
        $ms = $this->intOption($options, 'fallback_backoff_ms', 'super-ai-core.task_fallback.backoff_ms', 0);
        if ($ms <= 0) {
            return;
        }

        $strategy = (string) ($options['fallback_backoff_strategy'] ?? $this->configValue('super-ai-core.task_fallback.backoff_strategy') ?? 'fixed');
        if ($strategy === 'exponential') {
            $ms *= max(1, 2 ** max(0, $attempt - 1));
        }

        usleep(min($ms, 30000) * 1000);
    }

    protected function invokeCallback(mixed $callback, array $payload): void
    {
        if (!is_callable($callback)) {
            return;
        }

        try {
            $callback($payload);
        } catch (\Throwable $e) {
            $this->logger?->warning('TaskRunner fallback callback failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return string[]
     */
    protected function normaliseList(mixed $value): array
    {
        if (is_string($value)) {
            $value = array_map('trim', explode(',', $value));
        }
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return $out;
    }

    protected function intOption(array $options, string $optionKey, string $configKey, int $default): int
    {
        $value = $options[$optionKey] ?? $this->configValue($configKey);
        return is_numeric($value) ? (int) $value : $default;
    }

    protected function floatOption(array $options, string $optionKey, string $configKey, float $default): float
    {
        $value = $options[$optionKey] ?? $this->configValue($configKey);
        return is_numeric($value) ? (float) $value : $default;
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
