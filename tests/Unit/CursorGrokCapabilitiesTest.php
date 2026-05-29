<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Capabilities\CursorCapabilities;
use SuperAICore\Capabilities\GrokCapabilities;
use SuperAICore\Services\CapabilityRegistry;

final class CursorGrokCapabilitiesTest extends TestCase
{
    public function test_cursor_capabilities_shape(): void
    {
        $cap = new CursorCapabilities();
        $this->assertSame('cursor', $cap->key());
        $this->assertTrue($cap->supportsMcp());
        $this->assertSame('.cursor/mcp.json', $cap->mcpConfigPath());
        $this->assertSame('stream-json', $cap->streamFormat());
        $this->assertSame([], $cap->toolNameMap());
        // renderMcpConfig emits the standard mcpServers shape with our key.
        $json = $cap->renderMcpConfig([['key' => 'demo', 'command' => 'node', 'args' => ['x.js']]]);
        $decoded = json_decode($json, true);
        $this->assertArrayHasKey('demo', $decoded['mcpServers']);
    }

    public function test_grok_capabilities_shape(): void
    {
        $cap = new GrokCapabilities();
        $this->assertSame('grok', $cap->key());
        $this->assertTrue($cap->supportsSubAgents());
        $this->assertTrue($cap->supportsMcp());
        // Grok owns its MCP registry behind `grok mcp add` — no flat file.
        $this->assertNull($cap->mcpConfigPath());
        $this->assertSame('', $cap->renderMcpConfig([['key' => 'demo', 'command' => 'node']]));
    }

    public function test_registry_resolves_new_capabilities(): void
    {
        $registry = new CapabilityRegistry();
        $this->assertInstanceOf(CursorCapabilities::class, $registry->for('cursor'));
        $this->assertInstanceOf(GrokCapabilities::class, $registry->for('grok'));
    }
}
