<?php

namespace SuperAICore\Tests\Unit\Runner;

use PHPUnit\Framework\TestCase;
use SuperAICore\Registry\Skill;
use SuperAICore\Runner\CopilotSkillRunner;

final class CopilotSkillRunnerTest extends TestCase
{
    public function test_dry_run_includes_allow_all_tools_flag(): void
    {
        $buffer = '';
        $runner = new CopilotSkillRunner(
            binary: 'copilot',
            allowAllTools: true,
            writer: function (string $c) use (&$buffer) { $buffer .= $c; },
        );

        $skill = new Skill(
            name: 'demo',
            description: null,
            source: Skill::SOURCE_PROJECT(),
            body: 'noop',
            path: '/tmp/SKILL.md',
        );

        $exit = $runner->runSkill($skill, [], true);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('[dry-run]', $buffer);
        $this->assertStringContainsString('--allow-all-tools', $buffer);
    }

    public function test_dry_run_omits_allow_all_tools_when_disabled(): void
    {
        $buffer = '';
        $runner = new CopilotSkillRunner(
            allowAllTools: false,
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

        $this->assertStringNotContainsString('--allow-all-tools', $buffer);
    }

    public function test_allowed_tools_emits_translation_note(): void
    {
        $buffer = '';
        $runner = new CopilotSkillRunner(
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

        $runner->runSkill($skill, [], true);

        $this->assertStringContainsString('[note]', $buffer);
        $this->assertStringContainsString('allowed-tools declared', $buffer);
        $this->assertStringContainsString('Read,Write', $buffer);
    }
}
