<?php

namespace SuperAICore\Runner;

use SuperAICore\Registry\Skill;
use SuperAICore\Runner\Concerns\MonitoredProcess;
use Symfony\Component\Process\Process;

/**
 * Sends a skill's (already-translated) body to `gemini --prompt "" --yolo`
 * via stdin and streams combined output back to the caller.
 *
 * `--yolo` is Gemini CLI's non-interactive one-shot flag. The empty
 * `--prompt ""` tells gemini to read the actual prompt from stdin instead
 * of the flag (matches how AgentSpawn/GeminiChildRunner invokes it).
 */
final class GeminiSkillRunner implements SkillRunner
{
    use MonitoredProcess;

    public function __construct(
        private readonly string $binary = 'gemini',
        private readonly ?\Closure $writer = null,
    ) {}

    public function runSkill(Skill $skill, array $args, bool $dryRun): int
    {
        $prompt = $skill->body;
        if ($args) {
            $prompt .= "\n\n<args>\n" . implode("\n", $args) . "\n</args>\n";
        }

        if ($skill->allowedTools) {
            $this->emit("[note] allowed-tools declared (" . implode(',', $skill->allowedTools) . ") — gemini has no enforcement flag; relying on model obedience.\n");
        }

        $cmd = [$this->binary, '--prompt', '', '--yolo'];

        if ($dryRun) {
            $this->emit('[dry-run] ' . implode(' ', $cmd) . " <stdin: skill:{$skill->name} body + " . count($args) . " args>\n");
            return 0;
        }

        $process = new Process($cmd);
        $process->setInput($prompt);

        return $this->runMonitored(
            process: $process,
            backend: 'gemini',
            commandSummary: implode(' ', $cmd) . " <stdin: skill:{$skill->name}>",
            externalLabel: "skill:{$skill->name}",
            metadata: ['kind' => 'skill', 'skill_name' => $skill->name],
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
