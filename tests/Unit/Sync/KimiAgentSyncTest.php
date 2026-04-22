<?php

namespace SuperAICore\Tests\Unit\Sync;

use PHPUnit\Framework\TestCase;
use SuperAICore\Registry\Agent;
use SuperAICore\Sync\KimiAgentSync;
use SuperAICore\Sync\Manifest;

/**
 * Mirrors the shape of CopilotAgentWriterTest — pins the two-file-per-
 * agent layout Kimi requires (`agent.yaml` + `system.md`), the Claude
 * tool-name translation, user-edit detection, and stale-removal on
 * agent deletion from source.
 */
final class KimiAgentSyncTest extends TestCase
{
    private string $home;
    private string $agentsDir;
    private string $manifestPath;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/kimi-sync-' . bin2hex(random_bytes(4));
        $this->agentsDir = $this->home . '/agents/superaicore';
        $this->manifestPath = $this->agentsDir . '/.superaicore-manifest.json';
        mkdir($this->home, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->home);
    }

    public function test_first_sync_emits_agent_yaml_and_system_md(): void
    {
        $writer = $this->writer();
        $report = $writer->sync([
            $this->agent('reviewer', 'Audits PRs', body: 'You are a reviewer.', tools: ['Read', 'Bash']),
        ]);

        $this->assertCount(2, $report['written']);
        $dir = $this->agentsDir . '/reviewer';
        $this->assertFileExists($dir . '/agent.yaml');
        $this->assertFileExists($dir . '/system.md');

        $yaml = (string) file_get_contents($dir . '/agent.yaml');
        $this->assertStringContainsString('version: 1', $yaml);
        $this->assertStringContainsString('name: reviewer', $yaml);
        $this->assertStringContainsString('system_prompt_path: ./system.md', $yaml);
        $this->assertStringContainsString('kimi_cli.tools.file:ReadFile', $yaml);
        $this->assertStringContainsString('kimi_cli.tools.shell:Shell', $yaml);

        $this->assertSame("You are a reviewer.\n", file_get_contents($dir . '/system.md'));
    }

    public function test_tool_map_translates_common_claude_names(): void
    {
        $this->assertSame(
            [
                'kimi_cli.tools.file:ReadFile',
                'kimi_cli.tools.file:WriteFile',
                'kimi_cli.tools.file:StrReplaceFile',
                'kimi_cli.tools.shell:Shell',
                'kimi_cli.tools.web:FetchURL',
                'kimi_cli.tools.web:SearchWeb',
            ],
            $this->writer()->mapTools(['Read', 'Write', 'Edit', 'Bash', 'WebFetch', 'WebSearch']),
        );
    }

    public function test_tool_map_dedupes_edit_and_multi_edit(): void
    {
        // Both Edit and MultiEdit map to StrReplaceFile — the resulting
        // tools list must not list it twice.
        $mapped = $this->writer()->mapTools(['Edit', 'MultiEdit']);
        $this->assertSame(['kimi_cli.tools.file:StrReplaceFile'], $mapped);
    }

    public function test_tool_map_strips_permission_expressions_and_drops_unknowns(): void
    {
        // Claude allows `Bash(git:*)` — only the bare name matters for Kimi.
        // Unknown tools (e.g. `NotebookEdit` or typos) silently drop.
        $mapped = $this->writer()->mapTools(['Bash(git:*)', 'Grep', 'NotebookEdit', 'InvalidTool']);
        $this->assertSame([
            'kimi_cli.tools.shell:Shell',
            'kimi_cli.tools.file:Grep',
        ], $mapped);
    }

    public function test_empty_tools_falls_back_to_default_working_set(): void
    {
        $mapped = $this->writer()->mapTools([]);
        $this->assertSame(KimiAgentSync::DEFAULT_TOOLS, $mapped);
        $this->assertNotContains('kimi_cli.tools.agent:Agent', $mapped,
            'synced agents should not recursively spawn sub-agents');
    }

    public function test_second_sync_is_idempotent(): void
    {
        $writer = $this->writer();
        $agents = [$this->agent('reviewer', 'desc')];

        $writer->sync($agents);
        $second = $writer->sync($agents);

        $this->assertSame([], $second['written']);
        $this->assertCount(2, $second['unchanged']);
    }

    public function test_user_edited_agent_yaml_is_preserved(): void
    {
        $writer = $this->writer();
        $writer->sync([$this->agent('reviewer', 'orig')]);

        $path = $this->agentsDir . '/reviewer/agent.yaml';
        file_put_contents($path, "# user edit\n" . file_get_contents($path));

        $report = $writer->sync([$this->agent('reviewer', 'desc')]);

        $this->assertSame([], $report['written']);
        $this->assertContains($path, $report['user_edited']);
        $this->assertStringContainsString('# user edit', file_get_contents($path));
    }

    public function test_user_edited_system_md_is_preserved(): void
    {
        $writer = $this->writer();
        $writer->sync([$this->agent('reviewer', 'desc', body: 'v1 body')]);

        $path = $this->agentsDir . '/reviewer/system.md';
        file_put_contents($path, "v1 body\nmanual append\n");

        $report = $writer->sync([$this->agent('reviewer', 'desc', body: 'v2 body')]);

        $this->assertContains($path, $report['user_edited']);
        $this->assertStringContainsString('manual append', file_get_contents($path));
    }

    public function test_stale_agent_is_removed_when_source_disappears(): void
    {
        $writer = $this->writer();
        $writer->sync([$this->agent('gone', 'desc')]);
        $this->assertFileExists($this->agentsDir . '/gone/agent.yaml');
        $this->assertFileExists($this->agentsDir . '/gone/system.md');

        $second = $writer->sync([]);

        $this->assertContains($this->agentsDir . '/gone/agent.yaml', $second['removed']);
        $this->assertContains($this->agentsDir . '/gone/system.md', $second['removed']);
        $this->assertFileDoesNotExist($this->agentsDir . '/gone/agent.yaml');
        $this->assertFileDoesNotExist($this->agentsDir . '/gone/system.md');
    }

    public function test_stale_agent_with_user_edits_is_kept(): void
    {
        $writer = $this->writer();
        $writer->sync([$this->agent('gone', 'desc')]);

        $sysPath = $this->agentsDir . '/gone/system.md';
        file_put_contents($sysPath, "# user preserved this\n");

        $second = $writer->sync([]);

        $this->assertContains($sysPath, $second['stale_kept']);
        $this->assertFileExists($sysPath);
    }

    public function test_agent_path_uses_superaicore_namespace(): void
    {
        // Callers need the deterministic path for `--agent-file <...>`
        // invocations. Pin it.
        $w = $this->writer();
        $this->assertSame(
            $this->agentsDir . '/my-agent',
            $w->agentDir('my-agent'),
        );
        $this->assertSame(
            $this->agentsDir . '/my-agent/agent.yaml',
            $w->agentFilePath('my-agent'),
        );
    }

    public function test_name_with_unsafe_chars_is_sanitised(): void
    {
        // Keep the safety filter behaviour in lockstep with CopilotAgentWriter —
        // any path separator or shell meta becomes a single dash.
        $this->assertSame(
            $this->agentsDir . '/foo-bar-baz',
            $this->writer()->agentDir('foo/bar baz'),
        );
    }

    public function test_dry_run_does_not_touch_disk_or_manifest(): void
    {
        $writer = $this->writer();
        $report = $writer->sync([$this->agent('dryrun', 'desc')], dryRun: true);

        $this->assertCount(2, $report['written']);
        $this->assertFileDoesNotExist($this->agentsDir . '/dryrun/agent.yaml');
        $this->assertFileDoesNotExist($this->agentsDir . '/dryrun/system.md');
        $this->assertFileDoesNotExist($this->manifestPath);
    }

    private function writer(): KimiAgentSync
    {
        return new KimiAgentSync($this->agentsDir, new Manifest($this->manifestPath));
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
