<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Backends\CopilotCliBackend;

final class CopilotCliBackendTest extends TestCase
{
    public function test_parses_real_jsonl_envelope(): void
    {
        $backend = new CopilotCliBackend();

        // Minimal slice modelled on actual `copilot -p ... --output-format=json` output.
        $jsonl = implode("\n", [
            '{"type":"session.tools_updated","data":{"model":"gpt-5.4"},"id":"a","timestamp":"2026-04-18T14:13:16Z"}',
            '{"type":"user.message","data":{"content":"hi"},"id":"b","timestamp":"2026-04-18T14:13:16Z"}',
            '{"type":"assistant.message","data":{"messageId":"m1","content":"OK","outputTokens":41,"toolRequests":[]},"id":"c","timestamp":"2026-04-18T14:13:18Z"}',
            '{"type":"result","timestamp":"2026-04-18T14:13:18Z","sessionId":"s","exitCode":0,"usage":{"premiumRequests":1,"totalApiDurationMs":2120}}',
        ]);

        $parsed = $backend->parseJsonl($jsonl);

        $this->assertNotNull($parsed);
        $this->assertSame('OK',      $parsed['text']);
        $this->assertSame('gpt-5.4', $parsed['model']);
        $this->assertSame(41,        $parsed['output_tokens']);
        $this->assertSame(1,         $parsed['premium_requests']);
        $this->assertSame(0,         $parsed['exit_code']);
    }

    public function test_concatenates_multiple_assistant_messages(): void
    {
        $backend = new CopilotCliBackend();
        $jsonl = implode("\n", [
            '{"type":"assistant.message","data":{"content":"part one ","outputTokens":5}}',
            '{"type":"assistant.message","data":{"content":"part two","outputTokens":7}}',
            '{"type":"result","exitCode":0,"usage":{"premiumRequests":1}}',
        ]);

        $parsed = $backend->parseJsonl($jsonl);

        $this->assertSame('part one part two', $parsed['text']);
        $this->assertSame(12, $parsed['output_tokens']);
    }

    public function test_returns_null_when_output_has_no_json_events(): void
    {
        $backend = new CopilotCliBackend();
        $this->assertNull($backend->parseJsonl(''));
        $this->assertNull($backend->parseJsonl("plain text\nno json"));
    }

    public function test_non_zero_exit_code_propagates(): void
    {
        $backend = new CopilotCliBackend();
        $jsonl = implode("\n", [
            '{"type":"assistant.message","data":{"content":"oops","outputTokens":2}}',
            '{"type":"result","exitCode":1,"usage":{"premiumRequests":1}}',
        ]);

        $parsed = $backend->parseJsonl($jsonl);
        $this->assertSame(1, $parsed['exit_code']);
    }

    public function test_isavailable_only_checks_binary_in_path(): void
    {
        // Forcing a binary that won't resolve via `which` should report false
        // regardless of any other state.
        $backend = new CopilotCliBackend(binary: '__definitely_not_a_real_binary__');
        $this->assertFalse($backend->isAvailable());
    }
}
