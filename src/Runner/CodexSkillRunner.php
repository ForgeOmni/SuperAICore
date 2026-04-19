<?php

namespace SuperAICore\Runner;

use SuperAICore\Registry\Skill;
use SuperAICore\Runner\Concerns\MonitoredProcess;
use Symfony\Component\Process\Process;

/**
 * Sends a skill's (already-translated) body to `codex exec -` via stdin
 * and streams combined stdout/stderr back to the caller.
 */
final class CodexSkillRunner implements SkillRunner
{
    use MonitoredProcess;

    public function __construct(
        private readonly string $binary = 'codex',
        private readonly ?\Closure $writer = null,
    ) {}

    public function runSkill(Skill $skill, array $args, bool $dryRun): int
    {
        $prompt = $skill->body;
        if ($args) {
            $prompt .= "\n\n<args>\n" . implode("\n", $args) . "\n</args>\n";
        }

        if ($skill->allowedTools) {
            $this->emit("[note] allowed-tools declared (" . implode(',', $skill->allowedTools) . ") — codex has no enforcement flag; relying on model obedience.\n");
        }

        $cmd = [$this->binary, 'exec', '--full-auto', '--skip-git-repo-check', '-'];

        if ($dryRun) {
            $this->emit('[dry-run] ' . implode(' ', $cmd) . " <stdin: skill:{$skill->name} body + " . count($args) . " args>\n");
            return 0;
        }

        $process = new Process($cmd);
        $process->setInput($prompt);

        return $this->runMonitored(
            process: $process,
            backend: 'codex',
            commandSummary: implode(' ', array_slice($cmd, 0, 4)) . " <stdin: skill:{$skill->name}>",
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
