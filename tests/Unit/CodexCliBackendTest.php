<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Backends\CodexCliBackend;

final class CodexCliBackendTest extends TestCase
{
    public function test_parses_real_jsonl_envelope(): void
    {
        $backend = new CodexCliBackend();

        // Slice modelled on actual `codex exec --json --full-auto ... -` output.
        $jsonl = implode("\n", [
            '{"type":"thread.started","thread_id":"abc"}',
            '{"type":"turn.started"}',
            '{"type":"item.completed","item":{"id":"item_0","type":"agent_message","text":"Hi."}}',
            '{"type":"turn.completed","usage":{"input_tokens":47803,"cached_input_tokens":7040,"output_tokens":6}}',
        ]);

        $parsed = $backend->parseJsonl($jsonl);

        $this->assertNotNull($parsed);
        $this->assertSame('Hi.', $parsed['text']);
        $this->assertSame(47803, $parsed['input_tokens']);
        $this->assertSame(6,     $parsed['output_tokens']);
        $this->assertSame(7040,  $parsed['cached_input_tokens']);
        $this->assertSame('end_turn', $parsed['stop_reason']);
    }

    public function test_concatenates_multiple_agent_messages(): void
    {
        $backend = new CodexCliBackend();
        $jsonl = implode("\n", [
            '{"type":"item.completed","item":{"type":"agent_message","text":"part one "}}',
            '{"type":"item.completed","item":{"type":"agent_message","text":"part two"}}',
            '{"type":"turn.completed","usage":{"input_tokens":100,"output_tokens":12}}',
        ]);

        $parsed = $backend->parseJsonl($jsonl);

        $this->assertSame('part one part two', $parsed['text']);
        $this->assertSame(12, $parsed['output_tokens']);
    }

    public function test_turn_failed_sets_error_stop_reason(): void
    {
        $backend = new CodexCliBackend();
        $jsonl = implode("\n", [
            '{"type":"thread.started","thread_id":"x"}',
            '{"type":"turn.failed","error":{"message":"model not supported"}}',
        ]);

        $parsed = $backend->parseJsonl($jsonl);

        $this->assertNotNull($parsed);
        $this->assertSame('', $parsed['text']);
        $this->assertSame('error', $parsed['stop_reason']);
    }

    public function test_skips_non_agent_message_items(): void
    {
        // reasoning, tool_use, etc. — we only accumulate agent_message text
        $backend = new CodexCliBackend();
        $jsonl = implode("\n", [
            '{"type":"item.completed","item":{"type":"reasoning","text":"ignore"}}',
            '{"type":"item.completed","item":{"type":"agent_message","text":"real answer"}}',
            '{"type":"turn.completed","usage":{"input_tokens":1,"output_tokens":2}}',
        ]);

        $parsed = $backend->parseJsonl($jsonl);

        $this->assertSame('real answer', $parsed['text']);
    }

    public function test_returns_null_when_no_events(): void
    {
        $backend = new CodexCliBackend();
        $this->assertNull($backend->parseJsonl(''));
        $this->assertNull($backend->parseJsonl("stderr line\nanother"));
    }

    public function test_isavailable_only_checks_binary_in_path(): void
    {
        $backend = new CodexCliBackend(binary: '__definitely_not_a_real_binary__');
        $this->assertFalse($backend->isAvailable());
    }
}
