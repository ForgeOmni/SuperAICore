<?php

namespace SuperAICore\Runner;

use SuperAICore\Registry\Skill;
use Symfony\Component\Process\Process;

/**
 * Sends a skill's body as a prompt to `claude -p` and streams the output.
 * Args (if any) are appended as an <args> XML block so the model sees them
 * as structured input without having to be escape-aware.
 */
final class ClaudeSkillRunner implements SkillRunner
{
    public function __construct(
        private readonly string $binary = 'claude',
        private readonly ?\Closure $writer = null,
    ) {}

    public function runSkill(Skill $skill, array $args, bool $dryRun): int
    {
        $prompt = $skill->body;
        if ($args) {
            $prompt .= "\n\n<args>\n" . implode("\n", $args) . "\n</args>\n";
        }

        $cmd = [$this->binary, '-p', $prompt];
        if ($skill->allowedTools) {
            $cmd[] = '--allowedTools';
            $cmd[] = implode(',', $skill->allowedTools);
        }

        if ($dryRun) {
            $allow = $skill->allowedTools ? ' --allowedTools ' . implode(',', $skill->allowedTools) : '';
            $this->emit('[dry-run] ' . $this->binary . " -p <skill:{$skill->name} body + " . count($args) . " args>{$allow}\n");
            return 0;
        }

        $process = new Process($cmd);
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
