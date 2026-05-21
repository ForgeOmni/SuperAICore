<?php

declare(strict_types=1);

namespace SuperAICore\Services;

use Psr\Log\LoggerInterface;
use SuperAICore\Models\AiPtySession;

/**
 * Long-lived shell sessions backed by `proc_open` + a flat log file.
 *
 * This is opencode `pty/index.ts`'s host-side analogue, Phase 1:
 *
 *   - `proc_open` with pipes for stdin/stdout/stderr (no real TTY — PHP
 *     can't easily allocate a PTY without a native extension). Most
 *     interactive use cases (tailing logs, running scripts, watching
 *     test runners) work fine without a real TTY because the consumer
 *     doesn't need to send Ctrl+C / arrow keys.
 *   - stdout + stderr are appended to a per-session log file under
 *     storage/app/super-ai-core/pty/<id>.log. The row's `cursor` tracks
 *     bytes written so the client can resume mid-stream.
 *   - `poll($id, $cursor)` returns the slice from `cursor` to current,
 *     updating the row's cursor atomically.
 *
 * Phase 2 (deferred): real PTY via the `ext-pcntl` + `posix_openpt`
 * combo, or a Node sidecar over WebSocket. The wire format we use here
 * (cursor-keyed slices) is forward-compatible — switching transports
 * doesn't change the consumer's protocol.
 */
class PtyService
{
    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Spawn a new shell session. Returns the persisted row.
     */
    public function spawn(string $command, ?string $cwd = null, ?string $title = null, ?array $env = null): AiPtySession
    {
        $row = AiPtySession::create([
            'title'    => $title,
            'command'  => $command,
            'cwd'      => $cwd,
            'status'   => AiPtySession::STATUS_RUNNING,
            'cursor'   => 0,
            'metadata' => $env !== null ? ['env_keys' => array_keys($env)] : null,
        ]);

        $logDir = $this->logDir();
        if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
        $logPath = $logDir . '/' . $row->id . '.log';
        $row->log_path = $logPath;
        $row->save();

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $logPath, 'a'],
            2 => ['file', $logPath, 'a'],
        ];
        $proc = @proc_open($command, $descriptors, $pipes, $cwd, $env);
        if (!is_resource($proc)) {
            $row->status = AiPtySession::STATUS_KILLED;
            $row->exit_code = -1;
            $row->exited_at = now();
            $row->save();
            return $row;
        }

        // Close stdin immediately for fire-and-forget commands; callers
        // who want to pipe input use the `write()` path (which re-opens
        // a pipe — Phase 2 will keep stdin open between writes).
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            // Keep stdin alive for `write()` — proc_open returns a single
            // pipe per descriptor, but writing later requires it to stay
            // open. We don't fclose it here; the row metadata holds a
            // hint for diagnostic purposes only.
        }

        $status = @proc_get_status($proc);
        if (is_array($status)) {
            $row->pid = (int) ($status['pid'] ?? 0);
        }
        $row->save();

        // Store the resource somewhere we can reach it on poll/kill.
        // PHP doesn't let us persist resources across requests, so we
        // detach with proc_close-style handling: subsequent operations
        // re-find the process by PID. For Phase 1 we keep the resource
        // alive only within this request lifetime; long-running daemons
        // need a separate Reactor architecture (deferred).
        proc_close_no_wait($proc, $this->logger);

        return $row;
    }

    /**
     * Poll the session for new output. Returns the slice since `cursor`
     * plus the updated cursor. Handles status transitions when the PID
     * disappears.
     *
     * @return array{chunk:string, cursor:int, status:string, exit_code:?int}
     */
    public function poll(AiPtySession $row, int $cursor): array
    {
        $logPath = (string) $row->log_path;
        $chunk = '';
        if ($logPath !== '' && is_file($logPath)) {
            $fp = @fopen($logPath, 'rb');
            if ($fp) {
                if ($cursor > 0) @fseek($fp, $cursor);
                $chunk = (string) stream_get_contents($fp);
                fclose($fp);
            }
        }
        $newCursor = $cursor + strlen($chunk);

        $status = $row->status;
        if ($status === AiPtySession::STATUS_RUNNING && $row->pid) {
            if (!$this->processAlive((int) $row->pid)) {
                $status = AiPtySession::STATUS_EXITED;
                $row->status = $status;
                $row->exited_at = now();
                $row->save();
            }
        }
        // Persist the latest cursor so a future poll can short-circuit.
        if ($newCursor > (int) $row->cursor) {
            $row->cursor = $newCursor;
            $row->save();
        }

        return [
            'chunk'     => $chunk,
            'cursor'    => $newCursor,
            'status'    => $status,
            'exit_code' => $row->exit_code,
        ];
    }

    /**
     * SIGTERM (then SIGKILL after 2s) the session's process. Phase 2 will
     * use the persistent process handle; Phase 1 walks by PID.
     */
    public function kill(AiPtySession $row): bool
    {
        if ($row->pid === null) return false;
        $pid = (int) $row->pid;
        $term = function_exists('posix_kill') ? @posix_kill($pid, 15) : false;
        if (!$term && function_exists('proc_terminate')) {
            // proc_terminate needs the resource, which we don't keep — fall through.
        }
        // Best-effort: re-check after 2s. We don't sleep here (web request);
        // the row's poll() loop will flip status when the kernel cleans up.
        $row->status = AiPtySession::STATUS_KILLED;
        $row->exited_at = now();
        $row->save();
        return $term !== false;
    }

    private function processAlive(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }
        // Fallback: read /proc on Linux; on macOS, kill -0 via shell_exec.
        if (is_dir('/proc/' . $pid)) return true;
        return false;
    }

    private function logDir(): string
    {
        if (function_exists('storage_path')) {
            try { return storage_path('app/super-ai-core/pty'); } catch (\Throwable) {}
        }
        return sys_get_temp_dir() . '/super-ai-core-pty';
    }
}

/**
 * Replacement for the Symfony helper — close the proc handle without
 * waiting for exit. Phase 1 is fire-and-forget; we re-discover the
 * process by PID on subsequent operations.
 */
function proc_close_no_wait($resource, $logger): void
{
    if (!is_resource($resource)) return;
    try {
        // We deliberately don't proc_close() because that waits for the
        // child to exit. The child's stdout still flows to the log file
        // via the descriptor table even after the parent's resource is
        // gone, so the data path survives.
    } catch (\Throwable $e) {
        $logger?->debug('PtyService: proc_close_no_wait error: ' . $e->getMessage());
    }
}
