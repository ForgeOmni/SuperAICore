<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Backends\ClaudeCliBackend;

final class ClaudeCliBackendTest extends TestCase
{
    public function test_parses_real_json_envelope(): void
    {
        $backend = new ClaudeCliBackend();

        // Minimal slice modelled on actual `claude -p ... --output-format=json`
        // output (trimmed modelUsage values, identical shape).
        $json = json_encode([
            'type'           => 'result',
            'subtype'        => 'success',
            'is_error'       => false,
            'duration_ms'    => 1523,
            'num_turns'      => 1,
            'result'         => 'hi',
            'stop_reason'    => 'end_turn',
            'total_cost_usd' => 0.066762,
            'usage' => [
                'input_tokens'                 => 6,
                'cache_creation_input_tokens'  => 9292,
                'cache_read_input_tokens'      => 16204,
                'output_tokens'                => 6,
            ],
            'modelUsage' => [
                'claude-haiku-4-5-20251001' => ['costUSD' => 0.000405, 'outputTokens' => 12],
                'claude-opus-4-7[1m]'       => ['costUSD' => 0.066357, 'outputTokens' => 6],
            ],
        ]);

        $parsed = $backend->parseJson($json);

        $this->assertNotNull($parsed);
        $this->assertSame('hi', $parsed['text']);
        $this->assertSame('claude-opus-4-7[1m]', $parsed['model']);  // highest cost
        $this->assertSame(6, $parsed['input_tokens']);
        $this->assertSame(6, $parsed['output_tokens']);
        $this->assertSame(16204, $parsed['cache_read_input_tokens']);
        $this->assertSame(9292, $parsed['cache_creation_input_tokens']);
        $this->assertSame(0.066762, $parsed['total_cost_usd']);
        $this->assertSame('end_turn', $parsed['stop_reason']);
    }

    public function test_picks_first_model_when_cost_missing(): void
    {
        $backend = new ClaudeCliBackend();

        $json = json_encode([
            'type'   => 'result',
            'result' => 'ok',
            'usage'  => ['input_tokens' => 1, 'output_tokens' => 1],
            'modelUsage' => [
                'claude-first'  => ['outputTokens' => 5],
                'claude-second' => ['outputTokens' => 99],
            ],
        ]);

        $parsed = $backend->parseJson($json);

        $this->assertSame('claude-first', $parsed['model']);
    }

    public function test_returns_null_for_non_json_output(): void
    {
        $backend = new ClaudeCliBackend();
        $this->assertNull($backend->parseJson(''));
        $this->assertNull($backend->parseJson('plain text'));
        $this->assertNull($backend->parseJson('{"broken":'));
    }

    public function test_returns_null_when_type_is_not_result(): void
    {
        $backend = new ClaudeCliBackend();
        $this->assertNull($backend->parseJson('{"type":"partial","result":"x"}'));
    }

    public function test_tolerates_missing_usage_and_model_usage(): void
    {
        $backend = new ClaudeCliBackend();

        $parsed = $backend->parseJson('{"type":"result","result":"hello","stop_reason":"end_turn"}');

        $this->assertSame('hello', $parsed['text']);
        $this->assertNull($parsed['model']);
        $this->assertSame(0, $parsed['input_tokens']);
        $this->assertSame(0, $parsed['output_tokens']);
        $this->assertSame(0.0, $parsed['total_cost_usd']);
    }

    public function test_isavailable_only_checks_binary_in_path(): void
    {
        $backend = new ClaudeCliBackend(binary: '__definitely_not_a_real_binary__');
        $this->assertFalse($backend->isAvailable());
    }

    // ── buildChatArgs() flag matrix (streamChat argv, 1.0.8) ──

    public function test_chat_args_default_to_empty_mcp_surface(): void
    {
        $args = (new ClaudeCliBackend())->buildChatArgs('claude');

        // Back-compat lock: pre-1.0.8 streamChat always pinned an empty
        // MCP config; the default must stay byte-identical.
        $this->assertContains('--mcp-config', $args);
        $this->assertContains('{"mcpServers":{}}', $args);
        $this->assertContains('--strict-mcp-config', $args);
        $this->assertContains('--permission-mode', $args);
        $this->assertSame('Read,Glob,Grep', $args[array_search('--tools', $args, true) + 1]);
    }

    public function test_chat_args_mcp_mode_file_passes_config_path(): void
    {
        $args = (new ClaudeCliBackend())->buildChatArgs('claude', [
            'mcp_mode'        => 'file',
            'mcp_config_file' => '/tmp/subset.json',
        ]);

        $this->assertSame('/tmp/subset.json', $args[array_search('--mcp-config', $args, true) + 1]);
        $this->assertContains('--strict-mcp-config', $args);
        $this->assertNotContains('{"mcpServers":{}}', $args);
    }

    public function test_chat_args_mcp_mode_file_without_path_falls_back_to_empty(): void
    {
        $args = (new ClaudeCliBackend())->buildChatArgs('claude', ['mcp_mode' => 'file']);

        // Never silently inherit the user's whole MCP surface.
        $this->assertContains('{"mcpServers":{}}', $args);
        $this->assertContains('--strict-mcp-config', $args);
    }

    public function test_chat_args_mcp_mode_inherit_adds_no_mcp_flags(): void
    {
        $args = (new ClaudeCliBackend())->buildChatArgs('claude', ['mcp_mode' => 'inherit']);

        $this->assertNotContains('--mcp-config', $args);
        $this->assertNotContains('--strict-mcp-config', $args);
    }

    public function test_chat_args_appends_extra_cli_flags_in_order(): void
    {
        $args = (new ClaudeCliBackend())->buildChatArgs('claude', [
            'allowed_tools'   => ['Read', 'WebFetch'],
            'extra_cli_flags' => ['--allowedTools', 'mcp__fetch__*'],
        ]);

        $this->assertSame('Read,WebFetch', $args[array_search('--tools', $args, true) + 1]);
        $this->assertSame(['--allowedTools', 'mcp__fetch__*'], array_slice($args, -2));
    }
}
