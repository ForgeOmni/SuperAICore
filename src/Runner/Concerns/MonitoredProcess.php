<?php

namespace SuperAICore\Runner\Concerns;

use SuperAICore\Models\AiProcess;
use SuperAICore\Services\CliOutputParser;
use SuperAICore\Services\UsageRecorder;
use SuperAICore\Support\ProcessRegistrar;
use Symfony\Component\Process\Process;

/**
 * Shared start() + wait() + ai_processes registration for the engine
 * runners. Replaces the bare `Process::run()` pattern so every CLI the
 * package drives shows up in the Process Monitor UI with a live PID,
 * status row, and tee'd log file.
 *
 * Consumers must provide an `emit(string $chunk): void` method — the
 * trait calls it for each chunk streamed back from the subprocess.
 */
trait MonitoredProcess
{
    /**
     * Start $process, register it under the given backend, stream output
     * through $this->emit() + the tee log, then close the registrar row
     * with finished/failed based on exit code.
     *
     * @param array<string,mixed> $metadata forwarded to ProcessRegistrar::start()
     */
    protected function runMonitored(
        Process $process,
        string $backend,
        string $commandSummary,
        string $externalLabel,
        array $metadata = [],
    ): int {
        $process->setTimeout(null);
        $process->start();

        $logFile = ProcessRegistrar::defaultLogPath($backend, $externalLabel);
        $logFh = ProcessRegistrar::openLog($logFile);
        $procRow = ProcessRegistrar::start(
            backend: $backend,
            pid: (int) $process->getPid(),
            command: $commandSummary,
            logFile: $logFile,
            externalLabel: $externalLabel,
            metadata: $metadata ?: null,
        );

        $exit = $process->wait(function ($type, $buffer) use ($logFh) {
            $this->emit($buffer);
            if ($logFh) @fwrite($logFh, $buffer);
        });

        if ($logFh) @fclose($logFh);
        ProcessRegistrar::end($procRow, $exit === 0 ? AiProcess::STATUS_FINISHED : AiProcess::STATUS_FAILED);

        return $exit;
    }

    /**
     * Variant of runMonitored() that also:
     *   1. Measures wall-clock duration
     *   2. Reads back the tee'd log file when the process exits
     *   3. Parses usage via {@see CliOutputParser} for the given $engine
     *   4. Emits an `ai_usage_logs` row via {@see UsageRecorder}
     *
     * Opt-in — `runMonitored()` keeps its existing behaviour for callers
     * that don't want the parse overhead or don't run the CLI in a mode
     * that emits structured output. Callers that do want tracking should
     * make sure their CLI is invoked with the appropriate output format
     * (e.g. `claude -p --output-format=stream-json --verbose`).
     *
     * Accounting failures never propagate — they're logged at debug level
     * so a parser mismatch can never break the actual run.
     *
     * @param non-empty-string $engine  one of 'claude' | 'codex' | 'gemini' | 'copilot'
     *                                   drives the parser selection
     * @param array{task_type?: ?string, capability?: ?string, user_id?: ?int,
     *              provider_id?: ?int, service_id?: ?int, metadata?: array,
     *              model?: ?string} $context
     */
    protected function runMonitoredAndRecord(
        Process $process,
        string $backend,
        string $commandSummary,
        string $externalLabel,
        string $engine,
        array $context = [],
        array $metadata = [],
    ): int {
        $started = microtime(true);

        $logFile = ProcessRegistrar::defaultLogPath($backend, $externalLabel);
        // Buffer the stream in memory too so we don't re-read the tee file
        // on systems where fsync lags the process exit.
        $captured = '';

        $process->setTimeout(null);
        $process->start();

        $logFh = ProcessRegistrar::openLog($logFile);
        $procRow = ProcessRegistrar::start(
            backend: $backend,
            pid: (int) $process->getPid(),
            command: $commandSummary,
            logFile: $logFile,
            externalLabel: $externalLabel,
            metadata: $metadata ?: null,
        );

        $exit = $process->wait(function ($type, $buffer) use ($logFh, &$captured) {
            $this->emit($buffer);
            if ($logFh) @fwrite($logFh, $buffer);
            $captured .= $buffer;
        });

        if ($logFh) @fclose($logFh);
        ProcessRegistrar::end($procRow, $exit === 0 ? AiProcess::STATUS_FINISHED : AiProcess::STATUS_FAILED);

        // Accounting — best-effort.
        try {
            $durationMs = (int) round((microtime(true) - $started) * 1000);
            $parsed = match ($engine) {
                'claude'  => CliOutputParser::parseClaude($captured),
                'codex'   => CliOutputParser::parseCodex($captured),
                'copilot' => CliOutputParser::parseCopilot($captured),
                'gemini'  => CliOutputParser::parseGemini($captured),
                default   => null,
            };

            $dispatcherBackend = $engine === 'superagent' ? 'superagent' : $engine . '_cli';

            $payload = [
                'task_type'     => $context['task_type'] ?? null,
                'capability'    => $context['capability'] ?? null,
                'backend'       => $dispatcherBackend,
                'model'         => $parsed['model'] ?? $context['model'] ?? 'unknown',
                'input_tokens'  => (int) ($parsed['input_tokens']  ?? 0),
                'output_tokens' => (int) ($parsed['output_tokens'] ?? 0),
                'duration_ms'   => $durationMs,
                'user_id'       => $context['user_id'] ?? null,
                'provider_id'   => $context['provider_id'] ?? null,
                'service_id'    => $context['service_id'] ?? null,
                'metadata'      => array_merge($context['metadata'] ?? [], [
                    'external_label' => $externalLabel,
                    'exit_code'      => $exit,
                    'parsed'         => $parsed !== null,
                ]),
            ];

            if ($parsed) {
                if (isset($parsed['cache_read_input_tokens'])) {
                    $payload['cache_read_tokens'] = (int) $parsed['cache_read_input_tokens'];
                }
                if (isset($parsed['cache_creation_input_tokens'])) {
                    $payload['cache_write_tokens'] = (int) $parsed['cache_creation_input_tokens'];
                }
                if (isset($parsed['total_cost_usd'])) {
                    $payload['cost_usd'] = (float) $parsed['total_cost_usd'];
                }
            }

            if (function_exists('app')) {
                app(UsageRecorder::class)->record($payload);
            }
        } catch (\Throwable $e) {
            if (function_exists('logger')) {
                try { logger()->debug('runMonitoredAndRecord usage log skipped: ' . $e->getMessage()); }
                catch (\Throwable) {}
            }
        }

        return $exit;
    }
}
