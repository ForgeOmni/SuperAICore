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

    public function list(array $systemProcesses): array
    {
        // Refresh status: mark rows whose OS pid has gone away as finished.
        foreach (AiProcess::where('status', AiProcess::STATUS_RUNNING)->get() as $p) {
            if (!$p->isAlive()) {
                $p->update(['status' => AiProcess::STATUS_FINISHED, 'ended_at' => now()]);
            }
        }

        return AiProcess::orderByDesc('started_at')
            ->limit(100)
            ->get()
            ->map(fn (AiProcess $p) => new ProcessEntry(
                id: ProcessEntry::compose(self::KEY, $p->id),
                pid: $p->pid,
                backend: (string) ($p->backend ?? ''),
                status: (string) ($p->status ?? AiProcess::STATUS_FINISHED),
                external_label: $p->external_label,
                external_id: $p->external_id,
                command: $p->command,
                started_at: $p->started_at,
                log_file: $p->log_file,
            ))
            ->all();
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
