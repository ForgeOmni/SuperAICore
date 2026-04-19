<?php

namespace SuperAICore\Runner\Concerns;

use SuperAICore\Models\AiProcess;
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
}
