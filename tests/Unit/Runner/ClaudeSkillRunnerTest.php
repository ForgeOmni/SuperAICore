<?php

namespace SuperAICore\Tests\Unit\Runner;

use PHPUnit\Framework\TestCase;
use SuperAICore\Registry\Skill;
use SuperAICore\Runner\ClaudeSkillRunner;

final class ClaudeSkillRunnerTest extends TestCase
{
    public function test_dry_run_announces_allowed_tools(): void
    {
        $buffer = '';
        $runner = new ClaudeSkillRunner(
            binary: 'claude',
            writer: function (string $c) use (&$buffer) { $buffer .= $c; },
        );

        $skill = new Skill(
            name: 'demo',
            description: null,
            source: Skill::SOURCE_PROJECT(),
            body: 'noop',
            path: '/tmp/SKILL.md',
            allowedTools: ['Read', 'Write'],
        );

        $exit = $runner->runSkill($skill, [], true);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('[dry-run]', $buffer);
        $this->assertStringContainsString('--allowedTools Read,Write', $buffer);
    }

    public function test_dry_run_without_allowed_tools_does_not_emit_flag(): void
    {
        $buffer = '';
        $runner = new ClaudeSkillRunner(
            writer: function (string $c) use (&$buffer) { $buffer .= $c; },
        );

        $skill = new Skill(
            name: 'demo',
            description: null,
            source: Skill::SOURCE_PROJECT(),
            body: 'noop',
            path: '/tmp/SKILL.md',
        );

        $runner->runSkill($skill, [], true);

        $this->assertStringNotContainsString('--allowedTools', $buffer);
    }
}
