<?php

namespace SuperAICore\Tests\Unit\Sync;

use PHPUnit\Framework\TestCase;
use SuperAICore\Registry\Agent;
use SuperAICore\Registry\Skill;
use SuperAICore\Sync\GeminiCommandWriter;
use SuperAICore\Sync\Manifest;

final class GeminiCommandWriterTest extends TestCase
{
    private string $home;
    private string $commandsDir;
    private string $manifestPath;

    protected function setUp(): void
    {
        $this->home         = sys_get_temp_dir() . '/gemini-sync-' . bin2hex(random_bytes(4));
        $this->commandsDir  = $this->home . '/commands';
        $this->manifestPath = $this->commandsDir . '/.superaicore-manifest.json';
        mkdir($this->home, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->home);
    }

    public function test_first_sync_writes_toml_for_every_skill_and_agent(): void
    {
        $writer = $this->writer();

        $report = $writer->sync(
            [$this->skill('init', 'Bootstrap the project')],
            [$this->agent('security-reviewer', 'Audit a diff')],
        );

        $this->assertCount(2, $report['written']);

        $skillFile = $this->commandsDir . '/skill/init.toml';
        $agentFile = $this->commandsDir . '/agent/security-reviewer.toml';

        $this->assertFileExists($skillFile);
        $this->assertFileExists($agentFile);

        $skillToml = file_get_contents($skillFile);
        $this->assertStringContainsString('# @generated-by: superaicore', $skillToml);
        $this->assertStringContainsString('description = "Bootstrap the project"', $skillToml);
        $this->assertStringContainsString("prompt = '!{superaicore skill:run init {{args}}}'", $skillToml);

        $agentToml = file_get_contents($agentFile);
        $this->assertStringContainsString('agent:run security-reviewer', $agentToml);
        $this->assertStringContainsString('"{{args}}"', $agentToml);

        $this->assertFileExists($this->manifestPath);
    }

    public function test_second_sync_is_idempotent_when_nothing_changed(): void
    {
        $writer = $this->writer();
        $skills = [$this->skill('init', 'Bootstrap')];
        $agents = [$this->agent('security-reviewer', 'Audit')];

        $writer->sync($skills, $agents);
        $second = $writer->sync($skills, $agents);

        $this->assertSame([], $second['written']);
        $this->assertSame([], $second['removed']);
        $this->assertCount(2, $second['unchanged']);
    }

    public function test_sync_removes_stale_toml_when_skill_disappears(): void
    {
        $writer = $this->writer();

        $writer->sync([$this->skill('init', 'Bootstrap')], []);
        $skillFile = $this->commandsDir . '/skill/init.toml';
        $this->assertFileExists($skillFile);

        // Second sync without that skill → our TOML should be removed.
        $second = $writer->sync([], []);

        $this->assertFileDoesNotExist($skillFile);
        $this->assertContains($skillFile, $second['removed']);
    }

    public function test_user_edited_toml_is_preserved(): void
    {
        $writer = $this->writer();
        $skill  = $this->skill('init', 'Bootstrap');

        $writer->sync([$skill], []);
        $skillFile = $this->commandsDir . '/skill/init.toml';

        // User manually tweaks the TOML…
        file_put_contents($skillFile, "# my hand-edited version\n" . file_get_contents($skillFile));

        // …and changes the upstream skill description. Next sync should
        // refuse to overwrite, even though content differs.
        $skill2 = $this->skill('init', 'NEW description');
        $report = $writer->sync([$skill2], []);

        $this->assertSame([], $report['written']);
        $this->assertContains($skillFile, $report['user_edited']);
        $this->assertStringContainsString('my hand-edited version', file_get_contents($skillFile));
    }

    public function test_user_edited_stale_is_not_deleted(): void
    {
        $writer = $this->writer();

        $writer->sync([$this->skill('init', 'Bootstrap')], []);
        $skillFile = $this->commandsDir . '/skill/init.toml';
        file_put_contents($skillFile, "# user owns this now\n");

        // Upstream skill gone, but file was edited → keep it.
        $report = $writer->sync([], []);

        $this->assertSame([], $report['removed']);
        $this->assertContains($skillFile, $report['stale_kept']);
        $this->assertFileExists($skillFile);
    }

    public function test_user_deleted_toml_is_recreated(): void
    {
        $writer = $this->writer();
        $skill  = $this->skill('init', 'Bootstrap');

        $writer->sync([$skill], []);
        $skillFile = $this->commandsDir . '/skill/init.toml';
        @unlink($skillFile);
        $this->assertFileDoesNotExist($skillFile);

        $second = $writer->sync([$skill], []);

        $this->assertFileExists($skillFile);
        $this->assertContains($skillFile, $second['written']);
    }

    public function test_dry_run_reports_changes_without_touching_disk(): void
    {
        $writer = $this->writer();
        $skill  = $this->skill('init', 'Bootstrap');

        $report = $writer->sync([$skill], [], dryRun: true);

        $this->assertContains($this->commandsDir . '/skill/init.toml', $report['written']);
        $this->assertFileDoesNotExist($this->commandsDir . '/skill/init.toml');
        $this->assertFileDoesNotExist($this->manifestPath);
    }

    private function writer(): GeminiCommandWriter
    {
        return new GeminiCommandWriter($this->commandsDir, new Manifest($this->manifestPath));
    }

    private function skill(string $name, string $description): Skill
    {
        return new Skill(
            name: $name,
            description: $description,
            source: Skill::SOURCE_USER(),
            body: 'body',
            path: "/tmp/skills/{$name}/SKILL.md",
        );
    }

    private function agent(string $name, string $description): Agent
    {
        return new Agent(
            name: $name,
            description: $description,
            source: Agent::SOURCE_USER(),
            body: 'body',
            path: "/tmp/agents/{$name}.md",
        );
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $e) {
            if ($e === '.' || $e === '..') continue;
            $p = $dir . '/' . $e;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
