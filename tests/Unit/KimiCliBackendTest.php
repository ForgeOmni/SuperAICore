<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Backends\KimiCliBackend;

/**
 * Coverage focuses on the stream-json parser, the variant detector
 * (classifier), and (via reflection) the per-dialect command builders.
 * End-to-end generate() / stream() are exercised by the smoke tests run
 * against a real `kimi` binary — Unit tests deliberately avoid spawning
 * the child to stay hermetic, so command-shape tests pin the dialect
 * explicitly rather than relying on the `--help` probe.
 */
final class KimiCliBackendTest extends TestCase
{
    // ─── parser: legacy kimi-cli (content = block array) ───────────────

    public function test_parse_stream_json_extracts_final_assistant_text(): void
    {
        // Single-turn response (no tool use) — the minimal shape every
        // legacy `kimi --print --output-format stream-json` run produces.
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

    // ─── parser: new kimi-code (content = plain string) ────────────────

    public function test_parse_stream_json_handles_kimi_code_string_content(): void
    {
        // kimi-code's PromptJsonWriter emits `content` as a plain string.
        $out = json_encode([
            'role'    => 'assistant',
            'content' => 'Hello from kimi-code',
        ]) . "\n";

        $p = (new KimiCliBackend())->parseStreamJson($out);

        $this->assertSame('Hello from kimi-code', $p['text']);
        $this->assertSame(1, $p['turns']);
    }

    public function test_parse_stream_json_kimi_code_last_string_wins_over_tool_calls(): void
    {
        // kimi-code flushes a tool-calling assistant line (content absent,
        // tool_calls present) then the final answer line (string content).
        $lines = [
            json_encode([
                'role'       => 'assistant',
                'content'    => 'Let me check the time.',
                'tool_calls' => [[
                    'type'     => 'function',
                    'id'       => 'tool_X',
                    'function' => ['name' => 'Shell', 'arguments' => '{}'],
                ]],
            ]),
            json_encode([
                'role'         => 'tool',
                'tool_call_id' => 'tool_X',
                'content'      => '16:17',
            ]),
            json_encode([
                'role'    => 'assistant',
                'content' => 'It is 16:17.',
            ]),
            // kimi-code resume hint rides the NDJSON stream as a meta line;
            // it must NOT be folded into the surfaced text.
            json_encode([
                'role'       => 'meta',
                'type'       => 'session.resume_hint',
                'session_id' => 'abc-123',
                'command'    => 'kimi -r abc-123',
                'content'    => 'To resume this session: kimi -r abc-123',
            ]),
        ];

        $p = (new KimiCliBackend())->parseStreamJson(implode("\n", $lines) . "\n");

        $this->assertSame('It is 16:17.', $p['text']);
        $this->assertSame(2, $p['turns']);
        $this->assertSame(1, $p['tool_calls']);
        $this->assertStringNotContainsString('resume', $p['text']);
    }

    // ─── variant classifier (pure, hermetic) ──────────────────────────

    public function test_classify_variant_detects_legacy_from_print_flag(): void
    {
        $help = "Usage: kimi [options]\n  --print            Print mode\n  --output-format    stream-json\n";
        $this->assertSame(KimiCliBackend::VARIANT_LEGACY, KimiCliBackend::classifyVariantFromHelp($help));
    }

    public function test_classify_variant_detects_kimi_code_without_print_flag(): void
    {
        // Real kimi-code --help surface: no `--print`; print mode via --prompt.
        $help = "Usage: kimi [options]\n"
            . "  -p, --prompt <prompt>        Run one prompt non-interactively and print the response.\n"
            . "  --output-format <format>     (choices: \"text\", \"stream-json\")\n"
            . "  -m, --model <model>          LLM model alias.\n";
        $this->assertSame(KimiCliBackend::VARIANT_CODE, KimiCliBackend::classifyVariantFromHelp($help));
    }

    public function test_classify_variant_does_not_falsely_match_substring(): void
    {
        // A flag that merely contains "print" (e.g. --print-config) must NOT
        // be read as the legacy `--print` boolean.
        $help = "Usage: kimi\n  --prompt <p>\n  --no-print-banner\n";
        $this->assertSame(KimiCliBackend::VARIANT_CODE, KimiCliBackend::classifyVariantFromHelp($help));
    }

    public function test_resolve_variant_honours_explicit_pin_without_probing(): void
    {
        $this->assertSame(
            KimiCliBackend::VARIANT_LEGACY,
            (new KimiCliBackend(variant: KimiCliBackend::VARIANT_LEGACY))->resolveVariant(),
        );
        $this->assertSame(
            KimiCliBackend::VARIANT_CODE,
            (new KimiCliBackend(variant: KimiCliBackend::VARIANT_CODE))->resolveVariant(),
        );
    }

    // ─── command builder: legacy kimi-cli ──────────────────────────────

    public function test_legacy_build_command_emits_print_stream_json_and_prompt(): void
    {
        $cmd = $this->buildCommand($this->legacy(), [
            'prompt' => 'hello',
        ], []);

        $this->assertSame('kimi', $cmd[0]);
        $this->assertContains('--print', $cmd);
        $this->assertContains('--output-format=stream-json', $cmd);
        $this->assertContains('--max-steps-per-turn', $cmd);
        $this->assertContains('--prompt', $cmd);
        $this->assertContains('hello', $cmd);
    }

    public function test_legacy_build_command_includes_model_when_provided(): void
    {
        $cmd = $this->buildCommand($this->legacy(), [
            'prompt' => 'p',
            'model'  => 'kimi-code/kimi-for-coding',
        ], []);

        $mflag = array_search('--model', $cmd, true);
        $this->assertIsInt($mflag);
        $this->assertSame('kimi-code/kimi-for-coding', $cmd[$mflag + 1]);
    }

    public function test_legacy_build_command_omits_model_when_absent(): void
    {
        $cmd = $this->buildCommand($this->legacy(), ['prompt' => 'p'], []);
        $this->assertNotContains('--model', $cmd);
    }

    public function test_legacy_build_command_injects_mcp_config_file_when_readable(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'kimi-mcp-') . '.json';
        file_put_contents($tmp, json_encode(['mcpServers' => []]));

        try {
            $cmd = $this->buildCommand($this->legacy(), [
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

    public function test_legacy_build_command_omits_mcp_flag_when_path_missing(): void
    {
        $cmd = $this->buildCommand($this->legacy(), [
            'prompt'          => 'p',
            'mcp_config_file' => '/nope/does-not-exist.json',
        ], []);

        $this->assertNotContains('--mcp-config-file', $cmd);
    }

    public function test_legacy_build_command_respects_max_steps_per_turn_override(): void
    {
        $cmd = $this->buildCommand($this->legacy(), [
            'prompt'             => 'p',
            'max_steps_per_turn' => 50,
        ], []);

        $idx = array_search('--max-steps-per-turn', $cmd, true);
        $this->assertSame('50', $cmd[$idx + 1]);
    }

    // ─── command builder: new kimi-code ────────────────────────────────

    public function test_kimi_code_build_command_uses_prompt_without_print(): void
    {
        $cmd = $this->buildCommand($this->code(), ['prompt' => 'hello'], []);

        $this->assertSame('kimi', $cmd[0]);
        $this->assertContains('--prompt', $cmd);
        $this->assertContains('hello', $cmd);
        $this->assertContains('--output-format', $cmd);
        $this->assertContains('stream-json', $cmd);

        // kimi-code rejects unknown options; none of the legacy-only flags
        // (which it does not recognise) may appear.
        $this->assertNotContains('--print', $cmd);
        $this->assertNotContains('--max-steps-per-turn', $cmd);
        $this->assertNotContains('--yolo', $cmd);
        $this->assertNotContains('-w', $cmd);
        // value form, not the legacy `--output-format=stream-json` glued arg
        $this->assertNotContains('--output-format=stream-json', $cmd);
    }

    public function test_kimi_code_build_command_includes_model_when_provided(): void
    {
        $cmd = $this->buildCommand($this->code(), [
            'prompt' => 'p',
            'model'  => 'kimi-k2-turbo',
        ], []);

        $mflag = array_search('--model', $cmd, true);
        $this->assertIsInt($mflag);
        $this->assertSame('kimi-k2-turbo', $cmd[$mflag + 1]);
    }

    public function test_kimi_code_build_command_ignores_mcp_config_file(): void
    {
        // kimi-code has no per-run --mcp-config-file flag; passing one must
        // be silently dropped (not appended) rather than rejected by the CLI.
        $tmp = tempnam(sys_get_temp_dir(), 'kimi-mcp-') . '.json';
        file_put_contents($tmp, json_encode(['mcpServers' => []]));

        try {
            $cmd = $this->buildCommand($this->code(), [
                'prompt'          => 'p',
                'mcp_config_file' => $tmp,
            ], []);

            $this->assertNotContains('--mcp-config-file', $cmd);
            $this->assertNotContains($tmp, $cmd);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_name_is_kimi_cli(): void
    {
        $this->assertSame('kimi_cli', (new KimiCliBackend())->name());
    }

    // ─── helpers ────────────────────────────────────────────────────────

    private function legacy(): KimiCliBackend
    {
        return new KimiCliBackend(variant: KimiCliBackend::VARIANT_LEGACY);
    }

    private function code(): KimiCliBackend
    {
        return new KimiCliBackend(variant: KimiCliBackend::VARIANT_CODE);
    }

    /**
     * Reflection helper — buildCommand() is protected because it's an
     * internal seam, but it's the piece we pin most tightly against each
     * dialect's flag surface.
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
