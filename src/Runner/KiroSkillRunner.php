<?php

namespace SuperAICore\Runner;

use SuperAICore\Registry\Skill;
use SuperAICore\Runner\Concerns\MonitoredProcess;
use Symfony\Component\Process\Process;

/**
 * Sends a skill body to `kiro-cli chat --no-interactive` and streams output.
 *
 * Kiro reads `~/.kiro/skills/<name>/SKILL.md` natively (same YAML-frontmatter
 * format Claude uses) when agents reference them via `skill://` URIs. For
 * the headless one-shot path, we inline the skill body as the prompt so
 * execution doesn't depend on files being pre-synced.
 *
 * Args get appended as an <args> block — the same shape ClaudeSkillRunner
 * uses, so skills work verbatim across backends without author changes.
 */
final class KiroSkillRunner implements SkillRunner
{
    use MonitoredProcess;

    public function __construct(
        private readonly string $binary = 'kiro-cli',
        private readonly bool $trustAllTools = true,
        private readonly ?\Closure $writer = null,
    ) {}

    public function runSkill(Skill $skill, array $args, bool $dryRun): int
    {
        $prompt = $skill->body;
        if ($args) {
            $prompt .= "\n\n<args>\n" . implode("\n", $args) . "\n</args>\n";
        }

        $cmd = [$this->binary, 'chat', '--no-interactive'];
        if ($this->trustAllTools) {
            $cmd[] = '--trust-all-tools';
        }
        $cmd[] = $prompt;

        if ($dryRun) {
            $extra = $this->trustAllTools ? ' --trust-all-tools' : '';
            $this->emit('[dry-run] ' . $this->binary . " chat --no-interactive{$extra} <skill:{$skill->name} body + " . count($args) . " args>\n");
            return 0;
        }

        $process = new Process($cmd);

        return $this->runMonitored(
            process: $process,
            backend: 'kiro',
            commandSummary: $this->binary . " chat --no-interactive <skill:{$skill->name}>",
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
