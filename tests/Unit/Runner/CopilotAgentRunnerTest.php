<?php

namespace SuperAICore\Tests\Unit\Runner;

use PHPUnit\Framework\TestCase;
use SuperAICore\Registry\Agent;
use SuperAICore\Runner\CopilotAgentRunner;
use SuperAICore\Sync\CopilotAgentWriter;
use SuperAICore\Sync\Manifest;

final class CopilotAgentRunnerTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/copilot-runner-' . bin2hex(random_bytes(4));
        mkdir($this->home, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->home);
    }

    public function test_dry_run_announces_command_and_auto_syncs_agent_file(): void
    {
        $buffer = '';
        $writer = new CopilotAgentWriter(
            $this->home . '/agents',
            new Manifest($this->home . '/agents/.superaicore-manifest.json'),
        );
        $runner = new CopilotAgentRunner(
            writer: function (string $c) use (&$buffer) { $buffer .= $c; },
            syncer: $writer,
            copilotHome: $this->home,
        );

        $exit = $runner->runAgent($this->agent('reviewer'), 'audit the diff', true);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('[sync] wrote', $buffer);
        $this->assertStringContainsString('[dry-run]', $buffer);
        $this->assertStringContainsString('--agent reviewer', $buffer);
        $this->assertStringContainsString('--allow-all-tools', $buffer);
        $this->assertFileExists($this->home . '/agents/reviewer.agent.md');
    }

    public function test_dry_run_omits_allow_all_tools_when_disabled(): void
    {
        $buffer = '';
        $writer = new CopilotAgentWriter(
            $this->home . '/agents',
            new Manifest($this->home . '/agents/.superaicore-manifest.json'),
        );
        $runner = new CopilotAgentRunner(
            allowAllTools: false,
            writer: function (string $c) use (&$buffer) { $buffer .= $c; },
            syncer: $writer,
            copilotHome: $this->home,
        );

        $runner->runAgent($this->agent('reviewer'), 'task', true);

        $this->assertStringNotContainsString('--allow-all-tools', $buffer);
    }

    public function test_user_edited_target_is_announced(): void
    {
        $writer = new CopilotAgentWriter(
            $this->home . '/agents',
            new Manifest($this->home . '/agents/.superaicore-manifest.json'),
        );
        $writer->syncOne($this->agent('reviewer'));

        // User mutates the target
        $path = $this->home . '/agents/reviewer.agent.md';
        file_put_contents($path, "# manual\n" . file_get_contents($path));

        $buffer = '';
        $runner = new CopilotAgentRunner(
            writer: function (string $c) use (&$buffer) { $buffer .= $c; },
            syncer: $writer,
            copilotHome: $this->home,
        );

        $runner->runAgent($this->agent('reviewer'), 'task', true);

        $this->assertStringContainsString('user-edited target preserved', $buffer);
    }

    private function agent(string $name): Agent
    {
        return new Agent(
            name: $name,
            description: 'desc',
            source: Agent::SOURCE_USER(),
            body: 'system prompt body',
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
