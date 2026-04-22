<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\AgentSpawn\SpawnPlan;
use SuperAICore\Capabilities\KimiCapabilities;

/**
 * Covers both the (a) / (b) toggle semantics and the MCP config renderer's
 * merge behaviour. Full-path integration with `AgentSpawn\Pipeline` is
 * exercised by Pipeline's own tests — here we pin the capability's
 * return-shape contract so either branch can be selected deterministically.
 */
final class KimiCapabilitiesTest extends TestCase
{
    public function test_native_default_returns_empty_spawn_preamble(): void
    {
        $cap = new TestableKimiCapabilities(useNative: true);
        $this->assertSame('', $cap->spawnPreamble('/tmp/out'));
    }

    public function test_fallback_returns_preamble_with_sentinel(): void
    {
        $cap = new TestableKimiCapabilities(useNative: false);
        $out = $cap->spawnPreamble('/tmp/out');

        $this->assertStringContainsString('<!-- kimi-preamble-v1 -->', $out);
        $this->assertStringContainsString('Spawn Plan Protocol', $out);
        // Tool-name mapping block is part of the preamble.
        $this->assertStringContainsString('ReadFile', $out);
        $this->assertStringContainsString('WriteFile', $out);
        $this->assertStringContainsString('StrReplaceFile', $out);
    }

    public function test_native_default_returns_empty_consolidation_prompt(): void
    {
        // Pipeline's fast-exit check reads consolidationPrompt(''); if
        // that returns '' the whole three-phase protocol is skipped.
        // Pin this so toggling native mode doesn't accidentally flip it.
        $cap = new TestableKimiCapabilities(useNative: true);
        $plan = new SpawnPlan([], 1);
        $this->assertSame('', $cap->consolidationPrompt($plan, [], '/tmp/out'));
    }

    public function test_fallback_returns_language_aware_consolidation_prompt(): void
    {
        $cap = new TestableKimiCapabilities(useNative: false);
        $plan = new SpawnPlan([
            ['name' => 'zh-agent', 'system_prompt' => '', 'task_prompt' => '任务中文', 'output_subdir' => 'zh-agent'],
        ], 1);
        $out = $cap->consolidationPrompt($plan, [], '/tmp/out');

        $this->assertNotEmpty($out);
        // Chinese run → Chinese consolidation template.
        $this->assertStringContainsString('整合阶段', $out);
        $this->assertStringContainsString('摘要.md', $out);
    }

    public function test_transform_prompt_native_is_verbatim(): void
    {
        $cap = new TestableKimiCapabilities(useNative: true);
        $this->assertSame('hello', $cap->transformPrompt('hello'));
    }

    public function test_transform_prompt_fallback_injects_preamble_once(): void
    {
        $cap = new TestableKimiCapabilities(useNative: false);

        $first  = $cap->transformPrompt('the real task');
        $second = $cap->transformPrompt($first);

        $this->assertStringStartsWith('<!-- kimi-preamble-v1 -->', $first);
        $this->assertStringContainsString('the real task', $first);
        // Idempotent — second pass through the same prompt must not
        // double-inject the preamble.
        $this->assertSame($first, $second);
        $this->assertSame(1, substr_count($second, '<!-- kimi-preamble-v1 -->'));
    }

    public function test_render_mcp_config_emits_claude_compatible_shape(): void
    {
        $cap = new TestableKimiCapabilities(useNative: true);
        $content = $cap->renderMcpConfig([
            ['key' => 'fetch', 'command' => 'uvx', 'args' => ['mcp-server-fetch'], 'env' => new \stdClass()],
            ['key' => 'arxiv', 'command' => 'node', 'args' => ['arxiv.mjs'],       'env' => ['KEY' => 'x']],
        ]);
        $decoded = json_decode($content, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('mcpServers', $decoded);
        $this->assertArrayHasKey('fetch', $decoded['mcpServers']);
        $this->assertArrayHasKey('arxiv', $decoded['mcpServers']);
        $this->assertSame('uvx', $decoded['mcpServers']['fetch']['command']);
        $this->assertSame(['mcp-server-fetch'], $decoded['mcpServers']['fetch']['args']);
        $this->assertSame('stdio', $decoded['mcpServers']['fetch']['type']);
        $this->assertSame(['KEY' => 'x'], $decoded['mcpServers']['arxiv']['env']);
        // Trailing newline so editors don't complain about missing EOF nl.
        $this->assertStringEndsWith("\n", $content);
    }

    public function test_render_mcp_config_preserves_non_mcp_server_keys_on_disk(): void
    {
        // Sandbox HOME so the merge-read hits our fixture, not ~/.kimi.
        $sandbox = sys_get_temp_dir() . '/kimi-cap-' . bin2hex(random_bytes(3));
        mkdir($sandbox . '/.kimi', 0755, true);
        file_put_contents($sandbox . '/.kimi/mcp.json', json_encode([
            'mcpServers' => ['old' => ['type' => 'stdio', 'command' => 'x']],
            'oauth'      => ['token' => 'user-hand-edited-value'],
            'telemetry'  => true,
        ]));

        $prev = getenv('HOME');
        putenv('HOME=' . $sandbox);
        try {
            $cap = new TestableKimiCapabilities(useNative: true);
            $content = $cap->renderMcpConfig([
                ['key' => 'fetch', 'command' => 'uvx', 'args' => ['mcp-server-fetch']],
            ]);
            $decoded = json_decode($content, true);

            // Non-`mcpServers` user segments must survive verbatim.
            $this->assertSame(['token' => 'user-hand-edited-value'], $decoded['oauth']);
            $this->assertTrue($decoded['telemetry']);
            // `mcpServers` itself is fully replaced — contract is "we own
            // that key", matching Gemini / Copilot / Claude renderMcpConfig.
            $this->assertArrayHasKey('fetch', $decoded['mcpServers']);
            $this->assertArrayNotHasKey('old', $decoded['mcpServers']);
        } finally {
            putenv($prev === false ? 'HOME' : 'HOME=' . $prev);
            array_map('unlink', glob($sandbox . '/.kimi/*') ?: []);
            @rmdir($sandbox . '/.kimi');
            @rmdir($sandbox);
        }
    }

    public function test_key_and_tool_name_map_basics(): void
    {
        $cap = new KimiCapabilities();
        $this->assertSame('kimi', $cap->key());
        $map = $cap->toolNameMap();
        $this->assertSame('ReadFile', $map['Read']);
        $this->assertSame('WriteFile', $map['Write']);
        $this->assertSame('StrReplaceFile', $map['Edit']);
        $this->assertSame('Shell', $map['Bash']);
        $this->assertSame('FetchURL', $map['WebFetch']);
        $this->assertSame('SearchWeb', $map['WebSearch']);
    }

    public function test_mcp_config_path_is_dot_kimi_mcp_json(): void
    {
        $this->assertSame('.kimi/mcp.json', (new KimiCapabilities())->mcpConfigPath());
    }

    public function test_stream_format_is_stream_json(): void
    {
        $this->assertSame('stream-json', (new KimiCapabilities())->streamFormat());
    }
}

/**
 * Test subclass that lets us override the config-backed toggle without
 * spinning up a full Laravel container (the Unit suite doesn't bootstrap
 * one).
 */
final class TestableKimiCapabilities extends KimiCapabilities
{
    public function __construct(private readonly bool $useNative) {}

    public function useNativeAgents(): bool
    {
        return $this->useNative;
    }
}
