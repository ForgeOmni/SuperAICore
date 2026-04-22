<?php

namespace SuperAICore\Tests\Unit\Sync;

use PHPUnit\Framework\TestCase;
use SuperAICore\Sync\ClaudeAgentMcpWriter;
use SuperAICore\Sync\Manifest;

final class ClaudeAgentMcpWriterTest extends TestCase
{
    private string $dir;
    private string $agentsDir;
    private string $manifestPath;

    protected function setUp(): void
    {
        $this->dir          = sys_get_temp_dir() . '/claude-mcp-agent-' . bin2hex(random_bytes(4));
        $this->agentsDir    = $this->dir . '/.claude/agents';
        $this->manifestPath = $this->agentsDir . '/.superaicore-mcp-manifest.json';
        mkdir($this->agentsDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
    }

    public function test_first_sync_injects_managed_block_before_closing_delim(): void
    {
        $path = $this->writeAgent('research', "---\nname: research\ndescription: r\n---\n\nBody.\n");

        $report = $this->writer()->sync(
            ['research' => ['arxiv']],
            $this->catalog(['arxiv']),
        );

        $this->assertSame([$path], $report['written']);
        $contents = (string) file_get_contents($path);
        $this->assertStringContainsString(ClaudeAgentMcpWriter::MARKER_BEGIN, $contents);
        $this->assertStringContainsString(ClaudeAgentMcpWriter::MARKER_END,   $contents);
        $this->assertStringContainsString('mcpServers:', $contents);
        $this->assertStringContainsString('arxiv:', $contents);
        $this->assertStringContainsString("Body.\n", $contents);
        $this->assertMatchesRegularExpression(
            '/' . preg_quote(ClaudeAgentMcpWriter::MARKER_END, '/') . '\n---/',
            $contents
        );
    }

    public function test_second_sync_is_idempotent(): void
    {
        $this->writeAgent('research', "---\nname: research\n---\n\nBody.\n");

        $w = $this->writer();
        $w->sync(['research' => ['arxiv']], $this->catalog(['arxiv']));
        $r2 = $w->sync(['research' => ['arxiv']], $this->catalog(['arxiv']));

        $this->assertSame([], $r2['written']);
        $this->assertCount(1, $r2['unchanged']);
    }

    public function test_unassigned_agent_files_are_untouched(): void
    {
        $other = $this->writeAgent('other', "---\nname: other\n---\n\nBody.\n");
        $before = file_get_contents($other);

        $this->writer()->sync([], []);

        $this->assertSame($before, file_get_contents($other));
    }

    public function test_empty_server_list_removes_managed_block(): void
    {
        $path = $this->writeAgent('research', "---\nname: research\n---\n\nBody.\n");

        $w = $this->writer();
        $w->sync(['research' => ['arxiv']], $this->catalog(['arxiv']));
        $this->assertStringContainsString(ClaudeAgentMcpWriter::MARKER_BEGIN, (string) file_get_contents($path));

        $r = $w->sync(['research' => []], []);

        $this->assertContains($path, $r['written']);
        $after = (string) file_get_contents($path);
        $this->assertStringNotContainsString(ClaudeAgentMcpWriter::MARKER_BEGIN, $after);
        $this->assertStringNotContainsString('mcpServers:', $after);
        $this->assertStringContainsString("name: research",   $after);
        $this->assertStringContainsString("Body.\n",          $after);
    }

    public function test_missing_agent_file_reported_not_thrown(): void
    {
        $r = $this->writer()->sync(['ghost' => ['arxiv']], $this->catalog(['arxiv']));

        $this->assertSame([], $r['written']);
        $this->assertCount(1, $r['missing']);
        $this->assertStringEndsWith('/ghost.md', $r['missing'][0]);
    }

    public function test_unknown_server_name_throws(): void
    {
        $this->writeAgent('research', "---\nname: research\n---\n\nBody.\n");
        $this->expectException(\RuntimeException::class);

        $this->writer()->sync(['research' => ['arxiv']], $this->catalog([])); // empty catalog
    }

    public function test_agent_without_frontmatter_throws(): void
    {
        $this->writeAgent('bare', "No frontmatter here.\n");
        $this->expectException(\RuntimeException::class);

        $this->writer()->sync(['bare' => ['arxiv']], $this->catalog(['arxiv']));
    }

    public function test_user_edit_inside_markers_flagged_but_overwritten(): void
    {
        $path = $this->writeAgent('research', "---\nname: research\n---\n\nBody.\n");

        $w = $this->writer();
        $w->sync(['research' => ['arxiv']], $this->catalog(['arxiv']));

        $mutated = str_replace('arxiv:', 'arxiv: # user touched this', (string) file_get_contents($path));
        file_put_contents($path, $mutated);

        $r = $w->sync(['research' => ['pubmed']], $this->catalog(['pubmed']));

        $this->assertContains($path, $r['written']);
        $this->assertContains($path, $r['user_edited']);
        $this->assertStringContainsString('pubmed:', (string) file_get_contents($path));
        $this->assertStringNotContainsString('# user touched this', (string) file_get_contents($path));
    }

    public function test_dry_run_does_not_mutate_files_or_manifest(): void
    {
        $path = $this->writeAgent('research', "---\nname: research\n---\n\nBody.\n");
        $before = file_get_contents($path);

        $r = $this->writer()->sync(
            ['research' => ['arxiv']],
            $this->catalog(['arxiv']),
            dryRun: true,
        );

        $this->assertSame([$path], $r['written']);
        $this->assertSame($before, file_get_contents($path));
        $this->assertFileDoesNotExist($this->manifestPath);
    }

    public function test_crlf_frontmatter_is_parsed(): void
    {
        $path = $this->writeAgent('winagent', "---\r\nname: winagent\r\n---\r\n\r\nBody.\r\n");

        $r = $this->writer()->sync(
            ['winagent' => ['arxiv']],
            $this->catalog(['arxiv']),
        );

        $this->assertSame([$path], $r['written']);
        $this->assertStringContainsString(ClaudeAgentMcpWriter::MARKER_BEGIN, (string) file_get_contents($path));
    }

    private function writer(): ClaudeAgentMcpWriter
    {
        return new ClaudeAgentMcpWriter($this->agentsDir, new Manifest($this->manifestPath));
    }

    private function writeAgent(string $name, string $contents): string
    {
        $path = $this->agentsDir . '/' . $name . '.md';
        file_put_contents($path, $contents);
        return $path;
    }

    /**
     * @param string[] $names
     * @return array<string, array{type:string,command:string,args:array<int,string>,env:array<string,string>}>
     */
    private function catalog(array $names): array
    {
        $all = [
            'arxiv'  => ['type' => 'stdio', 'command' => 'node',  'args' => ['arxiv.mjs'],  'env' => []],
            'pubmed' => ['type' => 'stdio', 'command' => 'uvx',   'args' => ['pubmed-mcp'], 'env' => ['NCBI_API_KEY' => 'x']],
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
