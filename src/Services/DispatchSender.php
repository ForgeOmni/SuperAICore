<?php

namespace SuperAICore\Services;

use SuperAICore\Support\FailureClassifier;

/**
 * Candidate-pool dispatch loop behind `superaicore send` / `resume` —
 * ai-dispatch parity for its send contract.
 *
 * Walks the AliasRouter's `{backend, model}` candidates in order and
 * returns one flat, agent-consumable result contract:
 *
 *   ok, status, text, requested_target, route_source, backend_used,
 *   model_used, session_id, degraded, degrade_reason, failure_class,
 *   route_trace[], duration_ms, cost_usd, usage, log_file, run_id
 *
 * Fall-through policy (ai-dispatch semantics): a candidate that fails
 * with a retryable class (quota / rate_limit / auth / network — see
 * FailureClassifier) or is simply absent falls through to the next
 * candidate and marks the final result `degraded: true`. A failure that
 * classifies as anything else (tool_policy / validation / unmatched
 * runtime error) FAILS CLOSED — fallback must never hide a broken task.
 */
class DispatchSender
{
    public function __construct(
        protected Dispatcher $dispatcher,
        protected BackendRegistry $backends,
        protected ?RunStore $runs = null,
    ) {}

    /**
     * @param list<array{backend: string, model: ?string}> $candidates
     * @param array<string,mixed> $options {
     *   cwd?: string, timeout?: int, system?: string, max_tokens?: int,
     *   task_name?: string, stream?: bool, resume_session_id?: string,
     *   onChunk?: callable, retry_on_classes?: string[],
     * }
     * @return array<string,mixed> the result contract described above
     */
    public function send(
        string $requestedTarget,
        string $routeSource,
        array $candidates,
        string $prompt,
        array $options = [],
    ): array {
        $start = microtime(true);
        $trace = [];
        $final = null;
        $failClosed = false;

        foreach ($candidates as $candidate) {
            $backendName = $candidate['backend'];
            $step = ['backend' => $backendName, 'model' => $candidate['model']];

            $backend = $this->backends->get($backendName);
            if (!$backend) {
                $trace[] = $step + ['status' => 'skipped', 'reason' => 'backend_not_registered'];
                continue;
            }
            if (!empty($options['check_availability']) && !$backend->isAvailable()) {
                $trace[] = $step + ['status' => 'skipped', 'reason' => 'backend_unavailable'];
                continue;
            }

            $attemptStart = microtime(true);
            $result = $this->dispatcher->dispatch($this->dispatchOptions($candidate, $prompt, $options));
            $attemptMs = (int) round((microtime(true) - $attemptStart) * 1000);

            if ($result === null) {
                // The Dispatcher swallows the backend's error text (it logs
                // and returns null), so a null is unclassifiable — treat it
                // like an unavailable engine and keep walking the pool.
                $trace[] = $step + ['status' => 'failed', 'reason' => 'null_result', 'failure_class' => null, 'duration_ms' => $attemptMs];
                continue;
            }

            $exitCode = (int) ($result['exit_code'] ?? 0);
            $text = (string) ($result['text'] ?? '');
            if ($exitCode === 0 && trim($text) !== '') {
                $trace[] = $step + ['status' => 'ok', 'duration_ms' => $attemptMs];
                $final = $result;
                break;
            }

            $classified = FailureClassifier::classify($this->failureHaystack($result));
            $retryable = FailureClassifier::isRetryable($classified['class'], $options['retry_on_classes'] ?? null);
            $trace[] = $step + [
                'status' => 'failed',
                'reason' => $exitCode !== 0 ? "exit_code_{$exitCode}" : 'empty_output',
                'failure_class' => $classified['class'],
                'matched_pattern' => $classified['matched_pattern'],
                'duration_ms' => $attemptMs,
            ];
            if (!$retryable) {
                $failClosed = true;
                $final = $result;   // keep log_file / usage for diagnosis
                break;
            }
        }

        $contract = $this->buildContract($requestedTarget, $routeSource, $trace, $final, $failClosed, $options);
        $contract['duration_ms'] = (int) round((microtime(true) - $start) * 1000);

        if ($this->runs !== null) {
            $runId = $this->runs->record($contract + [
                'prompt_excerpt' => mb_substr($prompt, 0, 2000),
                'cwd' => $options['cwd'] ?? null,
                'resumed_from' => $options['resume_session_id'] ?? null,
            ]);
            if ($runId !== null) {
                $contract['run_id'] = $runId;
            }
        }

        return $contract;
    }

    /** @param array{backend: string, model: ?string} $candidate */
    protected function dispatchOptions(array $candidate, string $prompt, array $options): array
    {
        $taskName = $options['task_name'] ?? null;
        $dispatch = [
            'prompt' => $prompt,
            'backend' => $candidate['backend'],
            // Streaming by default: the CLI backends then honor cwd /
            // timeout and tee a log file the route_trace can point at.
            // Non-streaming backends silently fall back to generate().
            'stream' => $options['stream'] ?? true,
            'metadata' => array_filter([
                'usage_source' => 'dispatch_send',
                'task_name' => $taskName,
            ]),
        ];
        if ($candidate['model'] !== null) {
            $dispatch['model'] = $candidate['model'];
        }
        foreach (['cwd', 'timeout', 'idle_timeout', 'system', 'max_tokens', 'resume_session_id', 'onChunk', 'session_id', 'permission_mode', 'allowed_tools'] as $key) {
            if (isset($options[$key])) {
                $dispatch[$key] = $options[$key];
            }
        }
        if ($taskName !== null) {
            $dispatch['external_label'] = 'send:' . $taskName;
        }
        return $dispatch;
    }

    /** @param array<string,mixed>|null $final */
    protected function buildContract(
        string $requestedTarget,
        string $routeSource,
        array $trace,
        ?array $final,
        bool $failClosed,
        array $options,
    ): array {
        $okStep = null;
        foreach ($trace as $step) {
            if (($step['status'] ?? '') === 'ok') {
                $okStep = $step;
                break;
            }
        }

        $ok = $okStep !== null;
        $firstMiss = $trace !== [] && ($trace[0]['status'] ?? '') !== 'ok' ? $trace[0] : null;
        $lastFailure = null;
        foreach (array_reverse($trace) as $step) {
            if (($step['status'] ?? '') !== 'ok') {
                $lastFailure = $step;
                break;
            }
        }

        return [
            'ok' => $ok,
            'status' => $ok ? 'ok' : ($failClosed ? 'failed' : ($trace === [] ? 'no_candidates' : 'exhausted')),
            'text' => $ok ? (string) ($final['text'] ?? '') : '',
            'requested_target' => $requestedTarget,
            'route_source' => $routeSource,
            'backend_used' => $ok ? ($okStep['backend'] ?? null) : null,
            'model_used' => $ok ? ($final['model'] ?? $okStep['model'] ?? null) : null,
            'session_id' => $final['session_id'] ?? null,
            'degraded' => $ok && $firstMiss !== null,
            'degrade_reason' => $ok && $firstMiss !== null
                ? ($firstMiss['reason'] ?? $firstMiss['failure_class'] ?? 'candidate_fell_through')
                : null,
            'failure_class' => $ok ? null : ($lastFailure['failure_class'] ?? ($lastFailure ? 'unknown' : null)),
            'route_trace' => $trace,
            'task_name' => $options['task_name'] ?? null,
            'cost_usd' => $final['cost_usd'] ?? null,
            'usage' => $final['usage'] ?? null,
            'log_file' => $final['log_file'] ?? null,
        ];
    }

    /** @param array<string,mixed> $result */
    protected function failureHaystack(array $result): string
    {
        $parts = [(string) ($result['text'] ?? ''), 'exit_code=' . (int) ($result['exit_code'] ?? 0)];
        $logFile = $result['log_file'] ?? null;
        if (is_string($logFile) && is_file($logFile)) {
            $size = (int) @filesize($logFile);
            $handle = @fopen($logFile, 'r');
            if ($handle) {
                if ($size > 8192) {
                    fseek($handle, -8192, SEEK_END);
                }
                $parts[] = (string) stream_get_contents($handle);
                fclose($handle);
            }
        }
        return implode("\n", $parts);
    }
}
