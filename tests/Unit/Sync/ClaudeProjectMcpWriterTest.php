<?php

namespace SuperAICore\Tests\Unit\Sync;

use PHPUnit\Framework\TestCase;
use SuperAICore\Sync\ClaudeProjectMcpWriter;
use SuperAICore\Sync\Manifest;

final class ClaudeProjectMcpWriterTest extends TestCase
{
    private string $dir;
    private string $mcpJsonPath;
    private string $manifestPath;

    protected function setUp(): void
    {
        $this->dir          = sys_get_temp_dir() . '/claude-mcp-proj-' . bin2hex(random_bytes(4));
        $this->mcpJsonPath  = $this->dir . '/.mcp.json';
        $this->manifestPath = $this->dir . '/.claude/.superaicore-mcp-project-manifest.json';
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
    }

    public function test_render_emits_stable_json_with_type_command_args_env(): void
    {
        $out = ClaudeProjectMcpWriter::render([
            'fetch' => [
                'type'    => 'stdio',
                'command' => 'uvx',
                'args'    => ['fetch'],
                'env'     => ['FOO' => 'bar'],
            ],
        ]);

        $decoded = json_decode($out, true);
        $this->assertIsArray($decoded);
        $this->assertSame(
            ['type' => 'stdio', 'command' => 'uvx', 'args' => ['fetch'], 'env' => ['FOO' => 'bar']],
            $decoded['mcpServers']['fetch']
        );
        $this->assertStringEndsWith("\n", $out);
    }

    public function test_render_omits_empty_args_and_env(): void
    {
        $decoded = json_decode(ClaudeProjectMcpWriter::render([
            'bare' => ['type' => 'stdio', 'command' => 'x', 'args' => [], 'env' => []],
        ]), true);

        $this->assertArrayNotHasKey('args', $decoded['mcpServers']['bare']);
        $this->assertArrayNotHasKey('env',  $decoded['mcpServers']['bare']);
    }

    public function test_first_sync_writes_file_and_manifest(): void
    {
        $r = $this->writer()->sync($this->servers(['fetch']));

        $this->assertSame(ClaudeProjectMcpWriter::STATUS_WRITTEN, $r['status']);
        $this->assertFileExists($this->mcpJsonPath);
        $this->assertFileExists($this->manifestPath);
        $this->assertArrayHasKey($this->mcpJsonPath, (new Manifest($this->manifestPath))->read());
    }

    public function test_second_sync_with_same_input_is_unchanged(): void
    {
        $w = $this->writer();
        $w->sync($this->servers(['fetch']));
        $r = $w->sync($this->servers(['fetch']));

        $this->assertSame(ClaudeProjectMcpWriter::STATUS_UNCHANGED, $r['status']);
    }

    public function test_user_edited_file_is_preserved(): void
    {
        $w = $this->writer();
        $w->sync($this->servers(['fetch']));
        file_put_contents($this->mcpJsonPath, '{"mcpServers":{"hand":{"command":"x"}}}' . "\n");

        $r = $w->sync($this->servers(['fetch']));

        $this->assertSame(ClaudeProjectMcpWriter::STATUS_USER_EDITED, $r['status']);
        $this->assertStringContainsString('"hand"', (string) file_get_contents($this->mcpJsonPath));
    }

    public function test_dry_run_does_not_touch_disk(): void
    {
        $r = $this->writer()->sync($this->servers(['fetch']), dryRun: true);

        $this->assertSame(ClaudeProjectMcpWriter::STATUS_WRITTEN, $r['status']);
        $this->assertFileDoesNotExist($this->mcpJsonPath);
        $this->assertFileDoesNotExist($this->manifestPath);
    }

    public function test_dry_run_reports_unchanged_when_disk_already_matches(): void
    {
        $w = $this->writer();
        $w->sync($this->servers(['fetch']));

        $r = $w->sync($this->servers(['fetch']), dryRun: true);
        $this->assertSame(ClaudeProjectMcpWriter::STATUS_UNCHANGED, $r['status']);
    }

    private function writer(): ClaudeProjectMcpWriter
    {
        return new ClaudeProjectMcpWriter($this->mcpJsonPath, new Manifest($this->manifestPath));
    }

    /** @param string[] $names */
    private function servers(array $names): array
    {
        $all = [
            'fetch'  => ['type' => 'stdio', 'command' => 'uvx', 'args' => ['fetch'], 'env' => []],
            'sqlite' => ['type' => 'stdio', 'command' => 'uvx', 'args' => ['mcp-server-sqlite'], 'env' => []],
        ];
        return array_intersect_key($all, array_flip($names));
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
