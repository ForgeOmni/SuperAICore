<?php

namespace SuperAICore\Runner;

use SuperAICore\Capabilities\GeminiCapabilities;
use SuperAICore\Registry\Agent;
use Symfony\Component\Process\Process;

/**
 * Runs a sub-agent on gemini-cli. Gemini's non-interactive mode has no
 * separate system/user slots, so we concatenate body + task into one
 * prompt piped on stdin (matches `AgentSpawn\GeminiChildRunner` shape).
 */
final class GeminiAgentRunner implements AgentRunner
{
    public function __construct(
        private readonly string $binary = 'gemini',
        private readonly ?\Closure $writer = null,
    ) {}

    public function runAgent(Agent $agent, string $task, bool $dryRun): int
    {
        $prompt = trim($agent->body) . "\n\n---\n\n" . trim($task) . "\n";
        // Inject the Gemini preamble so the model uses native tool names
        // and the Spawn Plan protocol instead of `codebase_investigator`.
        $prompt = (new GeminiCapabilities())->transformPrompt($prompt);

        if ($agent->allowedTools) {
            $this->emit("[note] allowed-tools declared (" . implode(',', $agent->allowedTools) . ") — gemini has no enforcement flag; relying on model obedience.\n");
        }

        $cmd = [$this->binary, '--prompt', '', '--yolo'];
        if ($agent->model) {
            $cmd[] = '--model';
            $cmd[] = $agent->model;
        }

        if ($dryRun) {
            $this->emit('[dry-run] ' . implode(' ', $cmd) . " <stdin: agent:{$agent->name} system + task>\n");
            return 0;
        }

        $process = new Process($cmd);
        $process->setInput($prompt);
        $process->setTimeout(null);
        return $process->run(function ($type, $buffer) {
            $this->emit($buffer);
        });
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
