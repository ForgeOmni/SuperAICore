<?php

namespace SuperAICore\Runner;

use SuperAICore\Backends\CopilotCliBackend;
use SuperAICore\Console\Commands\CopilotSyncCommand;
use SuperAICore\Models\AiProcess;
use SuperAICore\Registry\Agent;
use SuperAICore\Support\ProcessRegistrar;
use SuperAICore\Sync\CopilotAgentWriter;
use SuperAICore\Sync\Manifest;
use Symfony\Component\Process\Process;

/**
 * Fan out a single task across N Copilot sub-agents concurrently and
 * collect structured per-agent results. Conceptually equivalent to
 * Copilot's native `/fleet` interactive command, implemented on the
 * host side because `/fleet` has no `-p` (non-interactive) surface.
 *
 * Each child invocation:
 *
 *     copilot --agent <name> -p <task> -s --allow-all-tools --output-format=json
 *
 * All N processes start in parallel; output is polled via
 * `getIncrementalOutput()` and streamed to the caller with an
 * `[<agent>] ` line prefix so humans can follow interleaved activity.
 * Each child is also registered with `ProcessRegistrar` so the Process
 * Monitor UI sees the fan-out as N separate rows.
 */
final class CopilotFleetRunner
{
    public function __construct(
        private readonly string $binary = 'copilot',
        private readonly bool $allowAllTools = true,
        private readonly ?\Closure $writer = null,
        private readonly ?CopilotAgentWriter $syncer = null,
        private readonly ?string $copilotHome = null,
    ) {}

    /**
     * @param  Agent[] $agents   pre-resolved Agent objects to fan out to
     * @return array<int,array{agent:string, text:string, model:?string, output_tokens:int, premium_requests:int, exit_code:int}>
     */
    public function runFleet(string $task, array $agents, bool $dryRun, ?string $model = null): array
    {
        if (!$agents) {
            return [];
        }

        $home      = $this->copilotHome ?? CopilotSyncCommand::defaultHome();
        $agentsDir = rtrim($home, '/') . '/agents';
        $syncer    = $this->syncer ?? new CopilotAgentWriter(
            $agentsDir,
            new Manifest($agentsDir . '/.superaicore-manifest.json'),
        );

        // Pre-sync all agents before launching — a single agent having
        // drifted shouldn't tie up the other N-1 in a first-writer race.
        foreach ($agents as $agent) {
            $sync = $syncer->syncOne($agent);
            if ($sync['status'] === CopilotAgentWriter::STATUS_WRITTEN) {
                $this->emit("[sync] {$agent->name}: wrote {$sync['path']}\n");
            } elseif ($sync['status'] === CopilotAgentWriter::STATUS_USER_EDITED) {
                $this->emit("[sync] {$agent->name}: user-edited target preserved: {$sync['path']}\n");
            }
        }

        if ($dryRun) {
            foreach ($agents as $agent) {
                $this->emit("[dry-run] {$this->binary} --agent {$agent->name} -p <task> -s --output-format=json"
                    . ($this->allowAllTools ? ' --allow-all-tools' : '')
                    . ($model ? " --model {$model}" : '')
                    . "\n");
            }
            return [];
        }

        /** @var array<string,Process> $procs */
        $procs = [];
        /** @var array<string,string> $buffers */
        $buffers = [];
        /** @var array<string,?AiProcess> $rows */
        $rows = [];

        foreach ($agents as $agent) {
            $cmd = [$this->binary, '--agent', $agent->name, '-p', $task, '-s', '--output-format=json'];
            if ($this->allowAllTools) {
                $cmd[] = '--allow-all-tools';
            }
            $effectiveModel = $model ?? $agent->model;
            if ($effectiveModel) {
                $cmd[] = '--model';
                $cmd[] = $effectiveModel;
            }
            if ($this->copilotHome !== null) {
                $cmd[] = '--config-dir';
                $cmd[] = $home;
            }

            $process = new Process($cmd);
            $process->setTimeout(null);
            $process->start();

            $procs[$agent->name] = $process;
            $buffers[$agent->name] = '';
            $rows[$agent->name] = ProcessRegistrar::start(
                backend: 'copilot',
                pid: (int) $process->getPid(),
                command: "{$this->binary} --agent {$agent->name} -p <task> -s",
                logFile: null,
                externalLabel: "fleet:{$agent->name}",
                metadata: ['kind' => 'fleet', 'agent_name' => $agent->name, 'fleet_size' => count($agents)],
            );
        }

        $this->pollUntilAllDone($procs, $buffers);

        $backend = new CopilotCliBackend();
        $results = [];

        foreach ($procs as $name => $process) {
            $parsed = $backend->parseJsonl($buffers[$name]);
            $exit = $process->getExitCode() ?? 1;
            ProcessRegistrar::end($rows[$name], $exit === 0 ? AiProcess::STATUS_FINISHED : AiProcess::STATUS_FAILED);

            $results[] = [
                'agent'            => $name,
                'text'             => $parsed['text'] ?? '',
                'model'            => $parsed['model'] ?? null,
                'output_tokens'    => $parsed['output_tokens'] ?? 0,
                'premium_requests' => $parsed['premium_requests'] ?? 0,
                'exit_code'        => $exit,
            ];
        }

        return $results;
    }

    /**
     * Round-robin poll each process' incremental stdout/stderr, stream it
     * with an `[<agent>] ` prefix, and accumulate raw output for later
     * JSONL parsing. Returns once every process has exited.
     *
     * @param array<string,Process> $procs
     * @param array<string,string>  &$buffers  mutated in place
     */
    private function pollUntilAllDone(array $procs, array &$buffers): void
    {
        do {
            $anyRunning = false;
            foreach ($procs as $name => $process) {
                $out = $process->getIncrementalOutput() . $process->getIncrementalErrorOutput();
                if ($out !== '') {
                    $buffers[$name] .= $out;
                    $this->emitWithPrefix($name, $out);
                }
                if ($process->isRunning()) {
                    $anyRunning = true;
                }
            }
            if ($anyRunning) {
                usleep(100_000);  // 100 ms — keep CPU idle while children work
            }
        } while ($anyRunning);
    }

    private function emitWithPrefix(string $agent, string $chunk): void
    {
        // Prefix each *line* (including multi-line chunks) so log output
        // stays parseable even when two agents emit a token at the same time.
        $lines = explode("\n", rtrim($chunk, "\n"));
        foreach ($lines as $line) {
            $this->emit("[{$agent}] {$line}\n");
        }
    }

    private function emit(string $chunk): void
    {
        if ($this->writer) {
            ($this->writer)($chunk);
        } else {
            fwrite(\STDOUT, $chunk);
        }
    }
}
