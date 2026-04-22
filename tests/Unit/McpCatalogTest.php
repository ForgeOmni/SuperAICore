<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Services\McpCatalog;

final class McpCatalogTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mcp-catalog-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->dir);
    }

    public function test_throws_when_file_missing(): void
    {
        $this->expectException(\RuntimeException::class);
        new McpCatalog($this->dir . '/nope.json');
    }

    public function test_throws_when_malformed(): void
    {
        $path = $this->write(['foo' => 'bar']);
        $this->expectException(\RuntimeException::class);
        new McpCatalog($path);
    }

    public function test_loads_servers_and_defaults_type_to_stdio(): void
    {
        $path = $this->write([
            'mcpServers' => [
                'fetch' => ['command' => 'uvx', 'args' => ['fetch']],
                'sse-one' => ['type' => 'sse', 'command' => 'node', 'args' => ['server.js']],
            ],
        ]);
        $cat = new McpCatalog($path);

        $this->assertSame(['fetch', 'sse-one'], $cat->names());
        $this->assertTrue($cat->has('fetch'));
        $this->assertFalse($cat->has('missing'));
        $this->assertSame('stdio', $cat->get('fetch')['type']);
        $this->assertSame('sse', $cat->get('sse-one')['type']);
        $this->assertSame(['fetch'], $cat->get('fetch')['args']);
        $this->assertSame([], $cat->get('fetch')['env']);
    }

    public function test_get_throws_for_unknown_name(): void
    {
        $path = $this->write(['mcpServers' => []]);
        $this->expectException(\InvalidArgumentException::class);
        (new McpCatalog($path))->get('nope');
    }

    public function test_subset_preserves_input_order(): void
    {
        $path = $this->write([
            'mcpServers' => [
                'a' => ['command' => 'a'],
                'b' => ['command' => 'b'],
                'c' => ['command' => 'c'],
            ],
        ]);
        $sub = (new McpCatalog($path))->subset(['c', 'a']);
        $this->assertSame(['c', 'a'], array_keys($sub));
    }

    public function test_subset_throws_on_unknown_name(): void
    {
        $path = $this->write(['mcpServers' => ['a' => ['command' => 'a']]]);
        $this->expectException(\InvalidArgumentException::class);
        (new McpCatalog($path))->subset(['a', 'ghost']);
    }

    public function test_domain_returns_empty_when_missing(): void
    {
        $path = $this->write(['mcpServers' => ['a' => ['command' => 'a']]]);
        $this->assertSame([], (new McpCatalog($path))->domain('research'));
    }

    public function test_domain_returns_configured_names(): void
    {
        $path = $this->write([
            'mcpServers' => [
                'arxiv'  => ['command' => 'x'],
                'pubmed' => ['command' => 'x'],
            ],
            'domains' => [
                'research' => ['arxiv', 'pubmed'],
            ],
        ]);
        $this->assertSame(['arxiv', 'pubmed'], (new McpCatalog($path))->domain('research'));
    }

    private function write(array $data): string
    {
        $p = $this->dir . '/catalog.json';
        file_put_contents($p, json_encode($data));
        return $p;
    }
}
