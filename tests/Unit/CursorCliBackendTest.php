<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Backends\CursorCliBackend;

final class CursorCliBackendTest extends TestCase
{
    private function backend(): CursorCliBackend
    {
        return new CursorCliBackend();
    }

    public function test_name_is_cursor_cli(): void
    {
        $this->assertSame('cursor_cli', $this->backend()->name());
    }

    public function test_parses_single_object_json_result(): void
    {
        $raw = json_encode([
            'type'    => 'result',
            'subtype' => 'success',
            'result'  => 'Hello from Composer.',
            'model'   => 'composer-2.5-fast',
            'usage'   => ['input_tokens' => 120, 'output_tokens' => 8],
        ]);
        $parsed = $this->backend()->parseAgentOutput($raw);

        $this->assertSame('Hello from Composer.', $parsed['text']);
        $this->assertSame('composer-2.5-fast', $parsed['model']);
        $this->assertSame(120, $parsed['input_tokens']);
        $this->assertSame(8, $parsed['output_tokens']);
    }

    public function test_parses_stream_json_ndjson(): void
    {
        $lines = [
            json_encode(['type' => 'system', 'subtype' => 'init']),
            json_encode(['type' => 'assistant', 'message' => [
                'model'   => 'composer-2.5',
                'content' => [['type' => 'text', 'text' => 'partial']],
            ]]),
            json_encode(['type' => 'assistant', 'message' => [
                'model'   => 'composer-2.5',
                'content' => [['type' => 'text', 'text' => 'final answer']],
            ]]),
            json_encode(['type' => 'result', 'usage' => ['input_tokens' => 50, 'output_tokens' => 12]]),
        ];
        $parsed = $this->backend()->parseAgentOutput(implode("\n", $lines));

        // Last assistant turn wins; result usage is authoritative.
        $this->assertSame('final answer', $parsed['text']);
        $this->assertSame('composer-2.5', $parsed['model']);
        $this->assertSame(50, $parsed['input_tokens']);
        $this->assertSame(12, $parsed['output_tokens']);
    }

    public function test_plain_text_fallback(): void
    {
        $parsed = $this->backend()->parseAgentOutput("just plain text\nsecond line");
        $this->assertSame("just plain text\nsecond line", $parsed['text']);
    }

    public function test_empty_returns_null(): void
    {
        $this->assertNull($this->backend()->parseAgentOutput('   '));
    }
}
