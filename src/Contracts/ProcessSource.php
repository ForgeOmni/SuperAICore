<?php

namespace SuperAICore\Contracts;

use SuperAICore\Support\ProcessEntry;

/**
 * A contributor to SuperAICore's process monitor page.
 *
 * Host apps (SuperTeam, SuperPilot, etc.) implement this to expose their
 * own "running task" concept (TaskResult, Job, WorkflowStep, ...) without
 * duplicating OS-scanning/log-tailing code.
 */
interface ProcessSource
{
    /**
     * Short stable identifier for this source, used as the first half of
     * composite IDs (e.g. "task_result" → entries addressed as
     * "task_result.13"). Must be URL-safe and match /^[a-z0-9_]+$/.
     */
    public function key(): string;

    /**
     * List all processes currently known to this source (running + recent
     * zombies). Returns ProcessEntry DTOs.
     *
     * @param array $systemProcesses OS process rows from ProcessMonitor::getSystemProcesses()
     * @return ProcessEntry[]
     */
    public function list(array $systemProcesses): array;

    /**
     * Resolve the absolute log-file path for a given entry id (without the
     * source prefix). Returns null if there isn't one.
     */
    public function logFile(string $id): ?string;

    /**
     * Kill the process backing the given entry id. Implementations typically
     * pkill the OS pid and update their own DB row. Return true on success.
     */
    public function kill(string $id): bool;
}
