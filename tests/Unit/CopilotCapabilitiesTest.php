<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Capabilities\CopilotCapabilities;

final class CopilotCapabilitiesTest extends TestCase
{
    private string $tmpHome;
    private ?string $origHome;

    protected function setUp(): void
    {
        $this->tmpHome = sys_get_temp_dir() . '/copilot-cap-' . bin2hex(random_bytes(4));
        @mkdir($this->tmpHome . '/.copilot', 0755, true);
        $this->origHome = getenv('HOME') ?: null;
        putenv('HOME=' . $this->tmpHome);
    }

    protected function tearDown(): void
    {
        if ($this->origHome !== null) {
            putenv('HOME=' . $this->origHome);
        }
        $this->rrmdir($this->tmpHome);
    }

    public function test_render_emits_mcpservers_block_for_supplied_specs(): void
    {
        $cap = new CopilotCapabilities();

        $json = $cap->renderMcpConfig([
            ['key' => 'fetch', 'command' => 'uvx', 'args' => ['mcp-server-fetch'], 'env' => []],
        ]);

        $data = json_decode($json, true);
        $this->assertArrayHasKey('mcpServers', $data);
        $this->assertArrayHasKey('fetch', $data['mcpServers']);
        $this->assertSame('uvx', $data['mcpServers']['fetch']['command']);
    }

    public function test_render_preserves_user_added_servers_outside_our_keyset(): void
    {
        // User has hand-added a "github" entry for Copilot's built-in MCP plus
        // a custom "myserver". A sync that only owns "fetch" must leave these
        // alone instead of wiping the whole mcpServers block.
        file_put_contents($this->tmpHome . '/.copilot/mcp-config.json', json_encode([
            'mcpServers' => [
                'github'   => ['command' => 'github-mcp', 'args' => []],
                'myserver' => ['command' => 'mine',       'args' => ['--flag']],
            ],
            'theme' => 'dark',
        ]));

        $cap = new CopilotCapabilities();
        $json = $cap->renderMcpConfig([
            ['key' => 'fetch', 'command' => 'uvx', 'args' => ['mcp-server-fetch'], 'env' => []],
        ]);

        $data = json_decode($json, true);
        $this->assertSame('dark', $data['theme'] ?? null);
        $this->assertArrayHasKey('github',   $data['mcpServers']);
        $this->assertArrayHasKey('myserver', $data['mcpServers']);
        $this->assertArrayHasKey('fetch',    $data['mcpServers']);
        $this->assertSame('github-mcp', $data['mcpServers']['github']['command']);
    }

    public function test_render_overrides_existing_entry_when_we_own_the_key(): void
    {
        file_put_contents($this->tmpHome . '/.copilot/mcp-config.json', json_encode([
            'mcpServers' => [
                'fetch' => ['command' => 'old-binary', 'args' => []],
            ],
        ]));

        $cap = new CopilotCapabilities();
        $json = $cap->renderMcpConfig([
            ['key' => 'fetch', 'command' => 'new-binary', 'args' => [], 'env' => []],
        ]);

        $data = json_decode($json, true);
        $this->assertSame('new-binary', $data['mcpServers']['fetch']['command']);
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
