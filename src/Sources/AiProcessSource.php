<?php

namespace SuperAICore\Sources;

use SuperAICore\Contracts\ProcessSource;
use SuperAICore\Models\AiProcess;
use SuperAICore\Services\ProcessMonitor;
use SuperAICore\Support\ProcessEntry;

/**
 * Built-in source backed by the ai_processes table — serves every backend
 * that calls POST /super-ai-core/processes/register.
 */
class AiProcessSource implements ProcessSource
{
    public const KEY = 'aiprocess';

    public function key(): string
    {
        return self::KEY;
    }

    /**
     * Real-time live process list.
     *
     * Source-of-truth is the OS, NOT the database. We query
     * ai_processes only for rows currently marked `running`, then verify
     * each row's PID is actually alive in `$systemProcesses` (the live
     * `ps aux` snapshot). Dead PIDs are reaped (status flipped to
     * finished) and excluded from the returned list.
     *
     * Result: the Process Monitor UI shows ONE entry per live OS thread
     * the host registered, and finished/failed/killed runs disappear
     * automatically the moment their subprocess exits — no historical
     * accumulation.
     *
     * Hosts that want a historical view should query `ai_processes`
     * directly (it remains the audit log of every spawn) instead of
     * going through the Process Monitor.
     */
    public function list(array $systemProcesses): array
    {
        // Index live PIDs once for O(1) liveness checks.
        $livePids = [];
        foreach ($systemProcesses as $sp) {
            if (isset($sp['pid'])) $livePids[(int) $sp['pid']] = true;
        }

        // Host apps can claim label namespaces (e.g. `task:`) so their own
        // ProcessSource owns the rich row for those logical runs. We still
        // reap dead PIDs for those rows — we just don't emit a duplicate
        // bare entry that the view would render as "task:3" with no badges.
        $hostOwnedPrefixes = (array) config('super-ai-core.process_monitor.host_owned_label_prefixes', []);

        $alive = [];
        foreach (AiProcess::where('status', AiProcess::STATUS_RUNNING)->orderByDesc('started_at')->get() as $p) {
            $pid = (int) $p->pid;
            $isAlive = $pid > 0 && (isset($livePids[$pid]) || $p->isAlive());

            if (!$isAlive) {
                // Reap: subprocess exited but never got the chance to
                // close its own row (e.g. host runner died mid-flight,
                // or registrar end() failed silently).
                $p->update(['status' => AiProcess::STATUS_FINISHED, 'ended_at' => now()]);
                continue;
            }

            if ($this->labelIsHostOwned((string) $p->external_label, $hostOwnedPrefixes)) {
                // A host ProcessSource emits the rich entry for this run
                // (with task/project/model/provider badges). Skipping here
                // keeps the view from double-rendering the same logical
                // process as a bare "task:3" row next to the rich one.
                continue;
            }

            $alive[] = new ProcessEntry(
                id: ProcessEntry::compose(self::KEY, $p->id),
                pid: $pid,
                backend: (string) ($p->backend ?? ''),
                status: AiProcess::STATUS_RUNNING,
                external_label: $p->external_label,
                external_id: $p->external_id,
                command: $p->command,
                started_at: $p->started_at,
                log_file: $p->log_file,
            );
        }

        return $alive;
    }

    protected function labelIsHostOwned(string $label, array $prefixes): bool
    {
        if ($label === '' || empty($prefixes)) return false;
        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($label, (string) $prefix)) {
                return true;
            }
        }
        return false;
    }

    public function logFile(string $id): ?string
    {
        $row = AiProcess::find((int) $id);
        return $row?->log_file;
    }

    public function kill(string $id): bool
    {
        $row = AiProcess::find((int) $id);
        if (!$row || !$row->pid) return false;

        $ok = ProcessMonitor::killPid((int) $row->pid);
        if ($ok) {
            $row->update(['status' => AiProcess::STATUS_KILLED, 'ended_at' => now()]);
        }
        return $ok;
    }
}
