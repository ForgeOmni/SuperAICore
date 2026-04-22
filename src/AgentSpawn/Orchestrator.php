<?php

namespace SuperAICore\AgentSpawn;

use SuperAICore\Models\AiProvider;

/**
 * Two-phase agent-spawn orchestration for backends that lack a native
 * sub-agent primitive (codex, gemini).
 *
 * Flow:
 *   Phase 1. The backend CLI runs the parent skill with a preamble that
 *            instructs it to emit `_spawn_plan.json` listing the agents
 *            it would have spawned, then STOP without playing roles.
 *   Phase 2. Host detects the plan file and calls
 *            {@see Orchestrator::run()} to fan out N child CLI processes
 *            in parallel (bounded by SpawnPlan::$concurrency).
 *            Each child writes its outputs into the agent's subdir.
 *   Phase 3. Host re-invokes the parent backend with a "consolidate"
 *            prompt that points at the child output files — the model
 *            reads them and produces the final summary/meta files.
 *
 * This class handles Phase 2. Phase 1 is passive (the backend preamble
 * already instructs the model). Phase 3 lives in the host orchestrator
 * (SuperTeam's ExecuteTask), which knows the final-summary requirements.
 */
class Orchestrator
{
    public function __construct(
        protected ChildRunner $runner,
    ) {}

    /**
     * Factory — pick the right runner for a backend.
     */
    public static function forBackend(string $backend, ?string $binary = null): self
    {
        $runner = match ($backend) {
            AiProvider::BACKEND_CODEX  => new CodexChildRunner($binary ?: 'codex'),
            AiProvider::BACKEND_GEMINI => new GeminiChildRunner($binary ?: 'gemini'),
            default => throw new \InvalidArgumentException("No ChildRunner for backend {$backend}"),
        };
        return new self($runner);
    }

    /**
     * Execute every agent in the plan in parallel (bounded by $plan->concurrency).
     * Returns a report per agent — caller collects the output files from
     * each agent's $outputSubdir.
     *
     * After every child exits, each subdir is audited (see
     * {@see self::auditAgentOutput()}); any contract violations land in
     * `warnings[]` so the caller can surface them to the operator and
     * (optionally) re-dispatch the offending child rather than consolidating
     * its fabricated or wrong-language output.
     *
     * @return array<int,array{name:string,exit:int,log:string,duration_ms:int,error:?string,warnings?:string[]}>
     */
    public function run(
        SpawnPlan $plan,
        string $outputRoot,
        string $projectRoot,
        array $env = [],
        ?string $model = null,
        ?callable $onAgentStart = null,
        ?callable $onAgentFinish = null,
    ): array {
        $report = [];
        $pool = [];  // running processes

        // Per-fanout temp root for intermediate child artifacts
        // (run.log / run.prompt.md / run-exec.sh / -last.txt). These are
        // plumbing, not deliverables, and must stay out of the user-facing
        // $outputRoot so the founder browsing the run directory sees only
        // each agent's real outputs (.md / .csv / .png). ChildRunner derives
        // prompt/script filenames from $logFile via str_replace, so colocating
        // them here keeps the full set together per-agent for debugging.
        $tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'superaicore-spawn-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);

        foreach ($plan->agents as $agent) {
            // Ensure per-agent output dir exists (the child writes its
            // real deliverables here via Write/write_file).
            $subdir = rtrim($outputRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $agent['output_subdir'];
            if (!is_dir($subdir)) @mkdir($subdir, 0755, true);

            // Per-agent plumbing dir under the fanout temp root.
            $tempSubdir = $tempRoot . DIRECTORY_SEPARATOR . $agent['output_subdir'];
            if (!is_dir($tempSubdir)) @mkdir($tempSubdir, 0755, true);

            // Throttle to concurrency limit — block until a slot opens
            while (count($pool) >= $plan->concurrency) {
                foreach ($pool as $i => $entry) {
                    if (!$entry['process']->isRunning()) {
                        $report[] = $this->finalize($entry, $onAgentFinish);
                        unset($pool[$i]);
                    }
                }
                if (count($pool) >= $plan->concurrency) usleep(200_000);  // 200ms
            }

            $logFile = $tempSubdir . DIRECTORY_SEPARATOR . 'run.log';
            $process = $this->runner->build($agent, $outputRoot, $logFile, $projectRoot, $env, $model);
            $startedAt = microtime(true);
            $process->start();

            $entry = [
                'agent' => $agent,
                'process' => $process,
                'log' => $logFile,
                'started_at' => $startedAt,
            ];
            $pool[] = $entry;

            if ($onAgentStart) $onAgentStart($agent, $process);
        }

        // Drain remaining
        while (!empty($pool)) {
            foreach ($pool as $i => $entry) {
                if (!$entry['process']->isRunning()) {
                    $report[] = $this->finalize($entry, $onAgentFinish);
                    unset($pool[$i]);
                }
            }
            if (!empty($pool)) usleep(200_000);
        }

        // Post-fanout sanitizer. Weak models (notably Gemini Flash, RUN 68,
        // 2026-04-22) ignore the per-agent guard clauses from the backend
        // preamble and leave behind (a) non-whitelisted extensions like
        // `generate_charts.py`, (b) sibling-role sub-directories like
        // `regional-khanna/ceo/*` containing fabricated cross-agent reports,
        // and (c) skill-reserved consolidator files like `summary.md` /
        // `思维导图.md` / `流程图.md` inside an agent's subdir. We don't
        // delete — deleting a child's output silently is a bigger foot-gun
        // than a loud warning — but we annotate each report entry with a
        // `warnings` list so the host surfaces it to the operator and the
        // /team consolidator can treat the child as "completed_unsafe" and
        // re-dispatch if necessary.
        foreach ($report as &$r) {
            $subdir = rtrim($outputRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ($r['name'] ?? '');
            if (!is_dir($subdir)) continue;
            $r['warnings'] = self::auditAgentOutput($subdir, (string) ($r['name'] ?? ''));
        }
        unset($r);

        return $report;
    }

    /**
     * Scan an agent's output subdir for contract violations. Returns a list
     * of human-readable warnings (empty when clean). Never modifies disk.
     *
     * @return string[]
     */
    protected static function auditAgentOutput(string $subdir, string $agentName): array
    {
        $warnings = [];
        $allowedExt = ['md', 'csv', 'png'];
        $consolidatorReserved = [
            'summary.md', '摘要.md', '思维导图.md', '流程图.md',
            'mindmap.md', 'flowchart.md',
        ];
        // Sibling-role directory names the agent should NEVER create inside
        // its own subdir. Allow-list: its own name, plus `_signals` (IAP
        // findings board) and any `_`-prefixed meta dir.
        $siblingRoleHint = '/^[a-z][a-z0-9-]*-[a-z0-9-]+$/'; // "kebab-case with at least one dash" matches role names

        $bad_ext = [];
        $reserved = [];
        $sibling_dirs = [];

        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($subdir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($rii as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            $rel = ltrim(str_replace($subdir, '', $fileInfo->getPathname()), '/');
            if ($fileInfo->isDir()) continue;
            $base = $fileInfo->getFilename();
            $ext = strtolower($fileInfo->getExtension());
            if ($ext !== '' && !in_array($ext, $allowedExt, true)) {
                $bad_ext[] = $rel;
            }
            if (in_array($base, $consolidatorReserved, true)) {
                $reserved[] = $rel;
            }
        }

        // Scan direct children of $subdir for sibling-role sub-directories.
        foreach (new \DirectoryIterator($subdir) as $entry) {
            if (!$entry->isDir() || $entry->isDot()) continue;
            $name = $entry->getFilename();
            if ($name === '_signals' || str_starts_with($name, '_') || str_starts_with($name, '.')) continue;
            if ($name === $agentName) continue;
            // Heuristic: if it looks like an agent id (contains a dash), flag it.
            if (preg_match($siblingRoleHint, $name) || in_array($name, [
                'ceo', 'cfo', 'cto', 'marketing', 'sales', 'legal', 'hr', 'ops',
                'product', 'qa', 'compliance', 'growth', 'data', 'social', 'pr',
                'review',
            ], true)) {
                $sibling_dirs[] = $name;
            }
        }

        if ($bad_ext)      $warnings[] = 'non-whitelisted extensions: ' . implode(', ', array_slice($bad_ext, 0, 10));
        if ($reserved)     $warnings[] = 'consolidator-reserved filenames inside agent subdir: ' . implode(', ', $reserved);
        if ($sibling_dirs) $warnings[] = 'sibling-role sub-directories (IAP depth=1 violation): ' . implode(', ', $sibling_dirs);

        return $warnings;
    }

    protected function finalize(array $entry, ?callable $onAgentFinish): array
    {
        $process = $entry['process'];
        $exit = (int) $process->getExitCode();
        $duration = (int) round((microtime(true) - $entry['started_at']) * 1000);
        $error = $exit === 0 ? null : trim($process->getErrorOutput() ?: '');

        $result = [
            'name' => $entry['agent']['name'],
            'exit' => $exit,
            'log' => $entry['log'],
            'duration_ms' => $duration,
            'error' => $error,
        ];

        if ($onAgentFinish) $onAgentFinish($entry['agent'], $result);

        return $result;
    }
}
