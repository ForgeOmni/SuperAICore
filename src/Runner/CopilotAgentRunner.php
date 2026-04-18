<?php

namespace SuperAICore\Runner;

use SuperAICore\Console\Commands\CopilotSyncCommand;
use SuperAICore\Models\AiProcess;
use SuperAICore\Registry\Agent;
use SuperAICore\Support\ProcessRegistrar;
use SuperAICore\Sync\CopilotAgentWriter;
use SuperAICore\Sync\Manifest;
use Symfony\Component\Process\Process;

/**
 * Runs a sub-agent on the GitHub Copilot CLI:
 *
 *     copilot --agent <name> -p "<task>" -s --allow-all-tools
 *
 * Auto-sync: before exec, the agent file at
 * `~/.copilot/agents/<name>.agent.md` is reconciled against the source
 * `.claude/agents/<name>.md` via `CopilotAgentWriter::syncOne`. Users
 * never need to remember to run `copilot:sync` first.
 *
 * If the user has hand-edited the target file (writer reports
 * `STATUS_USER_EDITED`), we proceed but warn that the on-disk version
 * is being used unchanged.
 */
final class CopilotAgentRunner implements AgentRunner
{
    public function __construct(
        private readonly string $binary = 'copilot',
        private readonly bool $allowAllTools = true,
        private readonly ?\Closure $writer = null,
        private readonly ?CopilotAgentWriter $syncer = null,
        private readonly ?string $copilotHome = null,
    ) {}

    public function runAgent(Agent $agent, string $task, bool $dryRun): int
    {
        $home      = $this->copilotHome ?? CopilotSyncCommand::defaultHome();
        $agentsDir = rtrim($home, '/') . '/agents';
        $syncer    = $this->syncer ?? new CopilotAgentWriter(
            $agentsDir,
            new Manifest($agentsDir . '/.superaicore-manifest.json'),
        );

        // Auto-sync: ensure the .agent.md file mirrors the source unless the
        // user has hand-edited it. Done unconditionally — `syncOne` is a
        // no-op when the target is byte-equal.
        $sync = $syncer->syncOne($agent);
        if ($sync['status'] === CopilotAgentWriter::STATUS_WRITTEN) {
            $this->emit("[sync] wrote {$sync['path']}\n");
        } elseif ($sync['status'] === CopilotAgentWriter::STATUS_USER_EDITED) {
            $this->emit("[sync] user-edited target preserved: {$sync['path']}\n");
        }

        $cmd = [$this->binary, '--agent', $agent->name, '-p', $task, '-s'];
        if ($this->allowAllTools) {
            $cmd[] = '--allow-all-tools';
        }
        if ($agent->model) {
            $cmd[] = '--model';
            $cmd[] = $agent->model;
        }
        // When the sync target is a non-default location (e.g. tests use
        // a sandbox dir), tell the Copilot binary to look there too —
        // otherwise it scans `~/.copilot/agents` and misses our file.
        if ($this->copilotHome !== null) {
            $cmd[] = '--config-dir';
            $cmd[] = $home;
        }

        if ($dryRun) {
            $extra  = $agent->model ? " --model {$agent->model}" : '';
            $extra .= $this->allowAllTools ? ' --allow-all-tools' : '';
            $this->emit("[dry-run] {$this->binary} --agent {$agent->name} -p <task>{$extra}\n");
            return 0;
        }

        // start() + wait() instead of run() so we have the PID *before* the
        // call blocks — needed to register into ai_processes for the monitor.
        $process = new Process($cmd);
        $process->setTimeout(null);
        $process->start();

        $logFile = ProcessRegistrar::defaultLogPath('copilot', "agent-{$agent->name}");
        $logFh = ProcessRegistrar::openLog($logFile);
        $procRow = ProcessRegistrar::start(
            backend: 'copilot',
            pid: (int) $process->getPid(),
            command: implode(' ', array_slice($cmd, 0, 4)) . ' ...',
            logFile: $logFile,
            externalLabel: "agent:{$agent->name}",
            metadata: ['kind' => 'agent', 'agent_name' => $agent->name],
        );

        $exit = $process->wait(function ($type, $buffer) use ($logFh) {
            $this->emit($buffer);
            if ($logFh) @fwrite($logFh, $buffer);
        });

        if ($logFh) @fclose($logFh);
        ProcessRegistrar::end($procRow, $exit === 0 ? AiProcess::STATUS_FINISHED : AiProcess::STATUS_FAILED);

        return $exit;
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
