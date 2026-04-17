<?php

namespace SuperAICore\Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use SuperAICore\Registry\Agent;
use SuperAICore\Registry\AgentRegistry;

final class AgentRegistryTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        $this->fixtureRoot = dirname(__DIR__, 2) . '/Fixtures/agents';
    }

    public function test_merges_two_sources_with_project_winning(): void
    {
        $registry = new AgentRegistry(
            cwd: $this->fixtureRoot . '/cwd',
            home: $this->fixtureRoot . '/home',
        );

        $agents = $registry->all();

        $this->assertContains('security-reviewer', array_keys($agents));
        $this->assertContains('geminer', array_keys($agents));

        $sec = $agents['security-reviewer'];
        $this->assertSame(Agent::SOURCE_PROJECT(), $sec->source);
        $this->assertSame('claude-sonnet-4-6', $sec->model);
        $this->assertSame(['Read', 'Grep', 'Bash'], $sec->allowedTools);
        $this->assertStringContainsString('senior security reviewer', $sec->body);
    }

    public function test_user_only_agent_is_loaded(): void
    {
        $registry = new AgentRegistry(
            cwd: $this->fixtureRoot . '/cwd',
            home: $this->fixtureRoot . '/home',
        );

        $geminer = $registry->get('geminer');

        $this->assertNotNull($geminer);
        $this->assertSame(Agent::SOURCE_USER(), $geminer->source);
        $this->assertSame('gemini-2.5-pro', $geminer->model);
    }

    public function test_missing_frontmatter_name_falls_back_to_filename_stem(): void
    {
        $registry = new AgentRegistry(
            cwd: $this->fixtureRoot . '/cwd',
            home: $this->fixtureRoot . '/home',
        );

        $nameless = $registry->get('nameless');

        $this->assertNotNull($nameless);
        $this->assertSame('nameless', $nameless->name);
        $this->assertNull($nameless->model);
        $this->assertSame([], $nameless->allowedTools);
    }

    public function test_get_returns_null_for_unknown_agent(): void
    {
        $registry = new AgentRegistry(
            cwd: $this->fixtureRoot . '/cwd',
            home: $this->fixtureRoot . '/home',
        );

        $this->assertNull($registry->get('does-not-exist'));
    }

    public function test_empty_environment_returns_empty(): void
    {
        $empty = sys_get_temp_dir() . '/agent-registry-' . bin2hex(random_bytes(4));
        mkdir($empty, 0755, true);

        $registry = new AgentRegistry(cwd: $empty, home: $empty);

        $this->assertSame([], $registry->all());

        rmdir($empty);
    }
}
