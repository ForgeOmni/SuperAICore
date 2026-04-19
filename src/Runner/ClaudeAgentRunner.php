<?php

namespace SuperAICore\Runner;

use SuperAICore\Registry\Agent;
use SuperAICore\Runner\Concerns\MonitoredProcess;
use SuperAICore\Services\ClaudeModelResolver;
use Symfony\Component\Process\Process;

/**
 * Runs a sub-agent on the Claude CLI. Body = system prompt, task =
 * user prompt; both are concatenated into a single `claude -p` call
 * so we don't rely on flags that may vary across claude-cli versions.
 *
 * If the agent declares `model:`, we pass it through `--model`, resolving
 * short family aliases (`opus`/`sonnet`/`haiku`) via `ClaudeModelResolver`.
 */
final class ClaudeAgentRunner implements AgentRunner
{
    use MonitoredProcess;

    public function __construct(
        private readonly string $binary = 'claude',
        private readonly ?\Closure $writer = null,
    ) {}

    public function runAgent(Agent $agent, string $task, bool $dryRun): int
    {
        $prompt = trim($agent->body) . "\n\n---\n\n" . trim($task) . "\n";

        $cmd = [$this->binary, '-p', $prompt];
        if ($agent->model) {
            $resolved = ClaudeModelResolver::resolve($agent->model) ?? $agent->model;
            $cmd[] = '--model';
            $cmd[] = $resolved;
        }
        if ($agent->allowedTools) {
            $cmd[] = '--allowedTools';
            $cmd[] = implode(',', $agent->allowedTools);
        }

        if ($dryRun) {
            $extra  = $agent->model ? " --model {$agent->model}" : '';
            $extra .= $agent->allowedTools ? ' --allowedTools ' . implode(',', $agent->allowedTools) : '';
            $this->emit("[dry-run] {$this->binary} -p <agent:{$agent->name} system + task>{$extra}\n");
            return 0;
        }

        $process = new Process($cmd);

        return $this->runMonitored(
            process: $process,
            backend: 'claude',
            commandSummary: $this->binary . " -p <agent:{$agent->name}>",
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
