<?php

namespace SuperAICore\Support;

use SuperAICore\Models\AiProcess;

/**
 * Optional helper that registers a CLI subprocess into the `ai_processes`
 * table so the Process Monitor UI can surface it (with PID, status, and a
 * tail-able log file).
 *
 * No-op outside Laravel: when the AiProcess model can't bind (no Eloquent
 * connection, no app container) the start/end methods silently return null.
 * Runners therefore wrap calls in plain `if ($proc) { ... }` checks without
 * needing to know whether they're inside a host app.
 *
 * Standard usage:
 *
 *   $proc = ProcessRegistrar::start('copilot', $process->getPid(), 'copilot --agent ...', $logFile);
 *   $exit = $process->wait();
 *   ProcessRegistrar::end($proc, $exit === 0 ? 'finished' : 'failed');
 *
 * The log file path is stored in the row but writing to it is the runner's
 * responsibility — typically by tee'ing the streaming output via an
 * `fopen(... 'a')` handle in the Symfony Process callback.
 */
final class ProcessRegistrar
{
    /**
     * Register the process. Returns the AiProcess row when persisted, null
     * when the database isn't reachable (any throw is swallowed).
     */
    public static function start(
        string $backend,
        int $pid,
        ?string $command = null,
        ?string $logFile = null,
        ?string $externalLabel = null,
        ?array $metadata = null,
    ): ?AiProcess {
        if ($pid <= 1) return null;

        try {
            return AiProcess::create([
                'pid'            => $pid,
                'backend'        => $backend,
                'command'        => $command !== null ? mb_substr($command, 0, 500) : null,
                'external_label' => $externalLabel !== null ? mb_substr($externalLabel, 0, 255) : null,
                'log_file'       => $logFile,
                'status'         => AiProcess::STATUS_RUNNING,
                'started_at'     => function_exists('now') ? now() : date('Y-m-d H:i:s'),
                'metadata'       => $metadata,
            ]);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Mark a previously-registered row as terminated. `$status` is one of
     * AiProcess::STATUS_FINISHED / FAILED / KILLED. A null `$proc` is
     * tolerated so callers can always pass through the start() return value.
     */
    public static function end(?AiProcess $proc, string $status): void
    {
        if ($proc === null) return;
        try {
            $proc->update([
                'status'   => $status,
                'ended_at' => function_exists('now') ? now() : date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // best-effort — swallow
        }
    }

    /**
     * Open a log file for tee'ing subprocess output. Returns the resource
     * (caller owns fclose) or null when the log file path is empty/unwritable.
     *
     * @return resource|null
     */
    public static function openLog(?string $logFile)
    {
        if (!$logFile) return null;
        $dir = dirname($logFile);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }
        $fh = @fopen($logFile, 'a');
        return $fh ?: null;
    }

    /**
     * Convenience: derive a default log path under sys_get_temp_dir() so
     * runners that want monitor support but don't need a specific location
     * can just call this and pass the result to `start()` + `openLog()`.
     */
    public static function defaultLogPath(string $backend, string $label = ''): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $label) ?: 'session';
        $stamp = date('Ymd-His');
        $rand  = bin2hex(random_bytes(3));
        return rtrim(sys_get_temp_dir(), '/') . "/superaicore-{$backend}-{$slug}-{$stamp}-{$rand}.log";
    }
}
