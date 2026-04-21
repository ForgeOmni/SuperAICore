<?php

namespace SuperAICore\Runner;

use SuperAICore\Console\Commands\KiroSyncCommand;
use SuperAICore\Registry\Agent;
use SuperAICore\Runner\Concerns\MonitoredProcess;
use SuperAICore\Sync\KiroAgentWriter;
use SuperAICore\Sync\Manifest;
use Symfony\Component\Process\Process;

/**
 * Runs a sub-agent on the Kiro CLI:
 *
 *     kiro-cli chat --no-interactive --trust-all-tools --agent <name> "<task>"
 *
 * Auto-sync: before exec, the agent JSON at `~/.kiro/agents/<name>.json` is
 * reconciled against the source `.claude/agents/<name>.md`. Users never have
 * to remember to run `kiro:sync` first.
 *
 * Headless mode has no `--model` flag — the agent JSON's `model` field is
 * authoritative. When Agent carries a model, the JSON we write already
 * contains it, so there's nothing extra to pass on the command line.
 */
final class KiroAgentRunner implements AgentRunner
{
    use MonitoredProcess;

    public function __construct(
        private readonly string $binary = 'kiro-cli',
        private readonly bool $trustAllTools = true,
        private readonly ?\Closure $writer = null,
        private readonly ?KiroAgentWriter $syncer = null,
        private readonly ?string $kiroHome = null,
    ) {}

    public function runAgent(Agent $agent, string $task, bool $dryRun): int
    {
        $home      = $this->kiroHome ?? KiroSyncCommand::defaultHome();
        $agentsDir = rtrim($home, '/') . '/agents';
        $syncer    = $this->syncer ?? new KiroAgentWriter(
            $agentsDir,
            new Manifest($agentsDir . '/.superaicore-manifest.json'),
        );

        $sync = $syncer->syncOne($agent);
        if ($sync['status'] === KiroAgentWriter::STATUS_WRITTEN) {
            $this->emit("[sync] wrote {$sync['path']}\n");
        } elseif ($sync['status'] === KiroAgentWriter::STATUS_USER_EDITED) {
            $this->emit("[sync] user-edited target preserved: {$sync['path']}\n");
        }

        $cmd = [$this->binary, 'chat', '--no-interactive', '--agent', $agent->name];
        if ($this->trustAllTools) {
            $cmd[] = '--trust-all-tools';
        }
        $cmd[] = $task;

        if ($dryRun) {
            $extra = $this->trustAllTools ? ' --trust-all-tools' : '';
            $this->emit("[dry-run] {$this->binary} chat --no-interactive --agent {$agent->name}{$extra} <task>\n");
            return 0;
        }

        $process = new Process($cmd);

        return $this->runMonitored(
            process: $process,
            backend: 'kiro',
            commandSummary: "{$this->binary} chat --agent {$agent->name} ...",
            externalLabel: "agent:{$agent->name}",
            metadata: ['kind' => 'agent', 'agent_name' => $agent->name],
        );
    }

    protected function emit(string $chunk): void
    {
        if ($this->writer) {
            ($this->writer)($chunk);
        } else {
            fwrite(\STDOUT, $chunk);
        }
    }
}
