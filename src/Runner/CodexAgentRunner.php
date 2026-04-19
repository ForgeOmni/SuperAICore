<?php

namespace SuperAICore\Runner;

use SuperAICore\Capabilities\CodexCapabilities;
use SuperAICore\Registry\Agent;
use SuperAICore\Runner\Concerns\MonitoredProcess;
use Symfony\Component\Process\Process;

/**
 * Runs a sub-agent on codex-cli. System+task concatenated and piped into
 * `codex exec -` the same way `AgentSpawn\CodexChildRunner` does, but
 * without the sub-agent sandbox — this is a single agent call, not a
 * parallel plan.
 */
final class CodexAgentRunner implements AgentRunner
{
    use MonitoredProcess;

    public function __construct(
        private readonly string $binary = 'codex',
        private readonly ?\Closure $writer = null,
    ) {}

    public function runAgent(Agent $agent, string $task, bool $dryRun): int
    {
        $prompt = trim($agent->body) . "\n\n---\n\n" . trim($task) . "\n";
        // Inject the Codex preamble (Spawn Plan protocol + MCP-only web
        // research notice) so the agent doesn't try to invoke tools it
        // doesn't have.
        $prompt = (new CodexCapabilities())->transformPrompt($prompt);

        if ($agent->allowedTools) {
            $this->emit("[note] allowed-tools declared (" . implode(',', $agent->allowedTools) . ") — codex has no enforcement flag; relying on model obedience.\n");
        }

        $cmd = [$this->binary, 'exec', '--full-auto', '--skip-git-repo-check', '-'];
        if ($agent->model) {
            $cmd[] = '-m';
            $cmd[] = $agent->model;
        }

        if ($dryRun) {
            $this->emit('[dry-run] ' . implode(' ', $cmd) . " <stdin: agent:{$agent->name} system + task>\n");
            return 0;
        }

        $process = new Process($cmd);
        $process->setInput($prompt);

        return $this->runMonitored(
            process: $process,
            backend: 'codex',
            commandSummary: implode(' ', array_slice($cmd, 0, 4)) . " <stdin: agent:{$agent->name}>",
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
