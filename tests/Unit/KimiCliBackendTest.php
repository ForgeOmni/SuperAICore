<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Backends\KimiCliBackend;

/**
 * MVP-1 coverage focuses on the stream-json parser and (via reflection)
 * the command builder. End-to-end generate() / stream() are exercised by
 * the smoke tests run during MVP-1 bring-up against a real `kimi` binary
 * (see docs/kimi-cli-backend.md §5) — Unit tests deliberately avoid
 * spawning the child to stay hermetic.
 */
final class KimiCliBackendTest extends TestCase
{
    public function test_parse_stream_json_extracts_final_assistant_text(): void
    {
        // Single-turn response (no tool use) — the minimal shape every
        // kimi --print --output-format stream-json run produces.
        $out = json_encode([
            'role'    => 'assistant',
            'content' => [
                ['type' => 'think', 'think' => 'short CoT', 'encrypted' => null],
                ['type' => 'text',  'text'  => 'Hi'],
            ],
        ]) . "\n";

        $p = (new KimiCliBackend())->parseStreamJson($out);

        $this->assertSame('Hi', $p['text']);
        $this->assertSame(1, $p['turns']);
        $this->assertSame(0, $p['tool_calls']);
    }

    public function test_parse_stream_json_last_assistant_wins_across_tool_use_turns(): void
    {
        // Three-line trace: tool-calling assistant → tool result → final
        // assistant with the user-visible answer. Mirrors the real Shell-
        // using probe captured during RFC research.
        $lines = [
            json_encode([
                'role'    => 'assistant',
                'content' => [['type' => 'think', 'think' => 'plan']],
                'tool_calls' => [[
                    'type'     => 'function',
                    'id'       => 'tool_A',
                    'function' => ['name' => 'Shell', 'arguments' => '{"command":"date +%H:%M"}'],
                ]],
            ]),
            json_encode([
                'role'    => 'tool',
                'content' => [['type' => 'text', 'text' => '16:17']],
                'tool_call_id' => 'tool_A',
            ]),
            json_encode([
                'role'    => 'assistant',
                'content' => [['type' => 'text', 'text' => '16:17']],
            ]),
        ];

        $p = (new KimiCliBackend())->parseStreamJson(implode("\n", $lines) . "\n");

        $this->assertSame('16:17', $p['text']);
        $this->assertSame(2, $p['turns']);
        $this->assertSame(1, $p['tool_calls']);
    }

    public function test_parse_stream_json_skips_blank_and_non_json_lines(): void
    {
        $mixed = "\n"
            . "To resume this session: kimi -r abc-123\n"
            . json_encode(['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'ok']]]) . "\n"
            . "  \n";

        $p = (new KimiCliBackend())->parseStreamJson($mixed);

        $this->assertSame('ok', $p['text']);
    }

    public function test_parse_stream_json_returns_empty_text_when_no_assistant_present(): void
    {
        // Only a tool result — no assistant. Envelope should report empty
        // text so the backend's generate() returns null to the caller
        // (same convention as other CLI backends on a failed run).
        $p = (new KimiCliBackend())->parseStreamJson(json_encode([
            'role'    => 'tool',
            'content' => [['type' => 'text', 'text' => 'no assistant']],
            'tool_call_id' => 'x',
        ]));

        $this->assertSame('', $p['text']);
        $this->assertSame(0, $p['turns']);
    }

    public function test_parse_stream_json_ignores_think_blocks_in_text_accumulation(): void
    {
        // The final text is the concatenation of `type=text` blocks only —
        // CoT (`type=think`) stays internal and should NOT appear in the
        // surfaced envelope text.
        $out = json_encode([
            'role'    => 'assistant',
            'content' => [
                ['type' => 'think', 'think' => 'thought — must not leak'],
                ['type' => 'text',  'text'  => 'visible one'],
                ['type' => 'text',  'text'  => ' visible two'],
            ],
        ]);

        $p = (new KimiCliBackend())->parseStreamJson($out);

        $this->assertSame('visible one visible two', $p['text']);
        $this->assertStringNotContainsString('thought', $p['text']);
    }

    public function test_build_command_emits_print_stream_json_and_prompt(): void
    {
        $cmd = $this->buildCommand(new KimiCliBackend(), [
            'prompt' => 'hello',
        ], []);

        $this->assertSame('kimi', $cmd[0]);
        $this->assertContains('--print', $cmd);
        $this->assertContains('--output-format=stream-json', $cmd);
        $this->assertContains('--max-steps-per-turn', $cmd);
        $this->assertContains('--prompt', $cmd);
        $this->assertContains('hello', $cmd);
    }

    public function test_build_command_includes_model_when_provided(): void
    {
        $cmd = $this->buildCommand(new KimiCliBackend(), [
            'prompt' => 'p',
            'model'  => 'kimi-code/kimi-for-coding',
        ], []);

        $mflag = array_search('--model', $cmd, true);
        $this->assertIsInt($mflag);
        $this->assertSame('kimi-code/kimi-for-coding', $cmd[$mflag + 1]);
    }

    public function test_build_command_omits_model_when_absent(): void
    {
        $cmd = $this->buildCommand(new KimiCliBackend(), ['prompt' => 'p'], []);
        $this->assertNotContains('--model', $cmd);
    }

    public function test_build_command_injects_mcp_config_file_when_readable(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'kimi-mcp-') . '.json';
        file_put_contents($tmp, json_encode(['mcpServers' => []]));

        try {
            $cmd = $this->buildCommand(new KimiCliBackend(), [
                'prompt'          => 'p',
                'mcp_config_file' => $tmp,
            ], []);

            $this->assertContains('--mcp-config-file', $cmd);
            $idx = array_search('--mcp-config-file', $cmd, true);
            $this->assertSame($tmp, $cmd[$idx + 1]);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_build_command_omits_mcp_flag_when_path_missing(): void
    {
        $cmd = $this->buildCommand(new KimiCliBackend(), [
            'prompt'          => 'p',
            'mcp_config_file' => '/nope/does-not-exist.json',
        ], []);

        $this->assertNotContains('--mcp-config-file', $cmd);
    }

    public function test_build_command_respects_max_steps_per_turn_override(): void
    {
        $cmd = $this->buildCommand(new KimiCliBackend(), [
            'prompt'             => 'p',
            'max_steps_per_turn' => 50,
        ], []);

        $idx = array_search('--max-steps-per-turn', $cmd, true);
        $this->assertSame('50', $cmd[$idx + 1]);
    }

    public function test_name_is_kimi_cli(): void
    {
        $this->assertSame('kimi_cli', (new KimiCliBackend())->name());
    }

    /**
     * Reflection helper — buildCommand() is protected because it's an
     * internal seam, but it's the piece we want to pin most tightly
     * against the kimi v1.38.0 flag surface.
     *
     * @param array<string,mixed> $options
     * @param array<string,mixed> $providerConfig
     * @return list<string>
     */
    private function buildCommand(KimiCliBackend $b, array $options, array $providerConfig): array
    {
        $m = new \ReflectionMethod($b, 'buildCommand');
        $m->setAccessible(true);
        return $m->invoke($b, $options, $providerConfig, (string) ($options['prompt'] ?? ''));
    }
}
