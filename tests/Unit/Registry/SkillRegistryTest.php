<?php

namespace SuperAICore\Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use SuperAICore\Registry\Skill;
use SuperAICore\Registry\SkillRegistry;

final class SkillRegistryTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        $this->fixtureRoot = dirname(__DIR__, 2) . '/Fixtures/skills';
    }

    public function test_merges_three_sources_with_project_winning(): void
    {
        $registry = new SkillRegistry(
            cwd: $this->fixtureRoot . '/cwd',
            home: $this->fixtureRoot . '/home',
        );

        $skills = $registry->all();

        $this->assertSame(['alpha', 'audit', 'beta', 'gamma', 'notebookish', 'toolheavy'], array_keys($skills));
        $this->assertSame(Skill::SOURCE_PROJECT(), $skills['alpha']->source);
        $this->assertSame(Skill::SOURCE_USER(), $skills['beta']->source);
        $this->assertSame(Skill::SOURCE_PLUGIN(), $skills['gamma']->source);
        $this->assertSame(Skill::SOURCE_PROJECT(), $skills['toolheavy']->source);
        $this->assertStringContainsString('project body', $skills['alpha']->body);

        // allowed-tools frontmatter propagates to the value object.
        $this->assertSame(['Read', 'Write'], $skills['beta']->allowedTools);
        $this->assertSame(['WebFetch', 'Read'], $skills['audit']->allowedTools);
    }

    public function test_get_returns_null_for_unknown_skill(): void
    {
        $registry = new SkillRegistry(
            cwd: $this->fixtureRoot . '/cwd',
            home: $this->fixtureRoot . '/home',
        );

        $this->assertNull($registry->get('does-not-exist'));
        $this->assertSame('gamma', $registry->get('gamma')?->name);
    }

    public function test_empty_environment_returns_empty(): void
    {
        $empty = sys_get_temp_dir() . '/superaicore-skill-registry-' . bin2hex(random_bytes(4));
        mkdir($empty, 0755, true);

        $registry = new SkillRegistry(cwd: $empty, home: $empty);

        $this->assertSame([], $registry->all());

        rmdir($empty);
    }
}
