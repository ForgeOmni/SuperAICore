<?php

namespace SuperAICore\Tests\Unit\Sync;

use PHPUnit\Framework\TestCase;
use SuperAICore\Registry\Agent;
use SuperAICore\Sync\CopilotAgentWriter;
use SuperAICore\Sync\Manifest;

final class CopilotAgentWriterTest extends TestCase
{
    private string $home;
    private string $agentsDir;
    private string $manifestPath;

    protected function setUp(): void
    {
        $this->home         = sys_get_temp_dir() . '/copilot-sync-' . bin2hex(random_bytes(4));
        $this->agentsDir    = $this->home . '/agents';
        $this->manifestPath = $this->agentsDir . '/.superaicore-manifest.json';
        mkdir($this->home, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->home);
    }

    public function test_first_sync_writes_agent_md_per_claude_agent(): void
    {
        $writer = $this->writer();
        $report = $writer->sync([$this->agent('reviewer', 'Audits PRs', body: 'You are a reviewer.', tools: ['Read', 'Bash(git:*)'])]);

        $this->assertCount(1, $report['written']);
        $path = $this->agentsDir . '/reviewer.agent.md';
        $this->assertFileExists($path);

        $contents = file_get_contents($path);
        $this->assertStringContainsString('name: reviewer', $contents);
        $this->assertStringContainsString('description: Audits PRs', $contents);
        $this->assertStringContainsString('- read(*)', $contents);
        $this->assertStringContainsString('- shell(git:*)', $contents);
        $this->assertStringContainsString('You are a reviewer.', $contents);
    }

    public function test_drops_claude_only_model_field(): void
    {
        $writer = $this->writer();
        $writer->sync([$this->agent('reviewer', 'desc', model: 'claude-opus-4-6')]);
        $contents = file_get_contents($this->agentsDir . '/reviewer.agent.md');

        $this->assertStringNotContainsString('claude-opus-4-6', $contents);
        $this->assertStringNotContainsString('model:', $contents);
    }

    public function test_second_sync_is_idempotent(): void
    {
        $writer = $this->writer();
        $agents = [$this->agent('reviewer', 'desc')];

        $writer->sync($agents);
        $second = $writer->sync($agents);

        $this->assertSame([], $second['written']);
        $this->assertCount(1, $second['unchanged']);
    }

    public function test_user_edited_target_is_preserved(): void
    {
        $writer = $this->writer();
        $writer->sync([$this->agent('reviewer', 'orig')]);
        $path = $this->agentsDir . '/reviewer.agent.md';

        file_put_contents($path, "# user edit\n" . file_get_contents($path));

        $report = $writer->sync([$this->agent('reviewer', 'NEW description')]);

        $this->assertSame([], $report['written']);
        $this->assertContains($path, $report['user_edited']);
        $this->assertStringContainsString('# user edit', file_get_contents($path));
    }

    public function test_stale_target_is_removed_when_source_disappears(): void
    {
        $writer = $this->writer();
        $writer->sync([$this->agent('gone', 'desc')]);
        $path = $this->agentsDir . '/gone.agent.md';
        $this->assertFileExists($path);

        $second = $writer->sync([]);

        $this->assertContains($path, $second['removed']);
        $this->assertFileDoesNotExist($path);
    }

    public function test_sync_one_writes_when_target_missing(): void
    {
        $writer = $this->writer();
        $report = $writer->syncOne($this->agent('runner-test', 'desc'));

        $this->assertSame(CopilotAgentWriter::STATUS_WRITTEN, $report['status']);
        $this->assertFileExists($report['path']);
    }

    public function test_sync_one_is_unchanged_when_target_byte_equal(): void
    {
        $writer = $this->writer();
        $agent = $this->agent('runner-test', 'desc');

        $writer->syncOne($agent);
        $second = $writer->syncOne($agent);

        $this->assertSame(CopilotAgentWriter::STATUS_UNCHANGED, $second['status']);
    }

    public function test_sync_one_respects_user_edited_target(): void
    {
        $writer = $this->writer();
        $agent = $this->agent('runner-test', 'orig');

        $writer->syncOne($agent);
        $path = $writer->agentPath('runner-test');
        file_put_contents($path, "# user touched\n" . file_get_contents($path));

        $second = $writer->syncOne($this->agent('runner-test', 'NEW'));

        $this->assertSame(CopilotAgentWriter::STATUS_USER_EDITED, $second['status']);
        $this->assertStringContainsString('# user touched', file_get_contents($path));
    }

    private function writer(): CopilotAgentWriter
    {
        return new CopilotAgentWriter($this->agentsDir, new Manifest($this->manifestPath));
    }

    private function agent(string $name, string $desc, ?string $model = null, array $tools = [], string $body = 'body'): Agent
    {
        return new Agent(
            name: $name,
            description: $desc,
            source: Agent::SOURCE_USER(),
            body: $body,
            path: "/tmp/agents/{$name}.md",
            model: $model,
            allowedTools: $tools,
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
