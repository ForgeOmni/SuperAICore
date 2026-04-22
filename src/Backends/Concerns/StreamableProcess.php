<?php

namespace SuperAICore\Backends\Concerns;

use SuperAICore\Models\AiProcess;
use SuperAICore\Support\ProcessRegistrar;
use SuperAICore\Support\TeeLogger;
use Symfony\Component\Process\Process;

/**
 * Shared register-tee-wait-end machinery for `Backend::stream()`
 * implementations.
 *
 * Replaces the bare `Process::run()` pattern that `Backend::generate()`
 * uses. Adds three things every streaming backend needs:
 *
 *   1. **Live tee** — every chunk Symfony's Process callback emits is
 *      forwarded to a {@see TeeLogger} on disk so the Process Monitor
 *      `tail -f` view always shows the latest bytes.
 *
 *   2. **Process Monitor row** — a row in `ai_processes` is created
 *      when the subprocess starts (PID, command summary, log path) and
 *      closed with `finished` / `failed` based on exit code. No-op when
 *      DB isn't reachable, so backend tests outside Laravel still work.
 *
 *   3. **Live chunk callback** — `$onChunk($buffer, $stream)` fires for
 *      each Process callback, letting hosts update UI previews in
 *      real-time without waiting for subprocess exit.
 *
 * Different from the long-standing {@see Runner\Concerns\MonitoredProcess}
 * trait in two ways:
 *   - No `emit()` requirement on the consumer (host-runner pattern that
 *     writes to STDOUT). Backends are silent by default; UI updates flow
 *     through `$onChunk` only when the caller passes one.
 *   - Returns a richer envelope that bundles the captured output, log
 *     path, and timing — backends parse `captured` to populate their
 *     usage envelope.
 */
trait StreamableProcess
{
    /**
     * Spawn $process, register it under $backend, stream its output
     * through TeeLogger + $onChunk, then close everything cleanly.
     *
     * @param Process       $process          Already-built Symfony process
     * @param string        $backend          Process Monitor key (e.g. 'claude_cli')
     * @param string        $commandSummary   Short string for the monitor row
     * @param string|null   $logFile          Tee target; auto-named when null
     * @param int|null      $timeout          Hard timeout (seconds); null = inherit Process default
     * @param int|null      $idleTimeout      Idle timeout (seconds); null = inherit
     * @param callable|null $onChunk          fn(string $buffer, string $stream): void
     * @param string|null   $externalLabel    Monitor row label (e.g. 'task:42')
     * @param array         $monitorMetadata  Extra fields stored on the monitor row
     *
     * @return array{
     *   exit_code: int,
     *   captured: string,
     *   stderr: string,
     *   log_file: string,
     *   duration_ms: int,
     * }
     */
    protected function runStreaming(
        Process $process,
        string $backend,
        string $commandSummary,
        ?string $logFile = null,
        ?int $timeout = null,
        ?int $idleTimeout = null,
        ?callable $onChunk = null,
        ?string $externalLabel = null,
        array $monitorMetadata = [],
    ): array {
        $logFile = $logFile ?: ProcessRegistrar::defaultLogPath($backend, $externalLabel ?? 'stream');
        $tee = new TeeLogger($logFile);

        // Apply timeout overrides — null means "leave whatever the
        // backend constructor already set" (typically Process default
        // of 60s for short calls, but stream() callers should pass an
        // explicit value for long-running tasks).
        if ($timeout !== null) $process->setTimeout($timeout);
        if ($idleTimeout !== null) $process->setIdleTimeout($idleTimeout);

        $startedAt = microtime(true);
        $captured = '';
        $stderrCaptured = '';

        $process->start();

        $procRow = ProcessRegistrar::start(
            backend: $backend,
            pid: (int) $process->getPid(),
            command: $commandSummary,
            logFile: $logFile,
            externalLabel: $externalLabel,
            metadata: $monitorMetadata ?: null,
        );

        try {
            $exit = $process->wait(function ($type, $buffer) use ($tee, $onChunk, &$captured, &$stderrCaptured) {
                $tee->write($buffer);
                if ($type === Process::ERR) {
                    $stderrCaptured .= $buffer;
                } else {
                    $captured .= $buffer;
                }
                if ($onChunk) {
                    $onChunk($buffer, $type);
                }
            });
        } catch (\Throwable $e) {
            $tee->close();
            ProcessRegistrar::end($procRow, AiProcess::STATUS_FAILED);
            throw $e;
        }

        $tee->close();
        ProcessRegistrar::end($procRow, $exit === 0 ? AiProcess::STATUS_FINISHED : AiProcess::STATUS_FAILED);

        return [
            'exit_code'   => $exit,
            'captured'    => $captured,
            'stderr'      => $stderrCaptured,
            'log_file'    => $logFile,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }
}
