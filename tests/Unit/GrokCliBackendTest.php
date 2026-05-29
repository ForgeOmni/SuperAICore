<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Backends\GrokCliBackend;

final class GrokCliBackendTest extends TestCase
{
    private function backend(): GrokCliBackend
    {
        return new GrokCliBackend();
    }

    public function test_name_is_grok_cli(): void
    {
        $this->assertSame('grok_cli', $this->backend()->name());
    }

    public function test_parses_single_object_json(): void
    {
        $raw = json_encode([
            'type'   => 'result',
            'result' => 'Built it.',
            'model'  => 'grok-build',
            'usage'  => ['input_tokens' => 200, 'output_tokens' => 15],
        ]);
        $parsed = $this->backend()->parseAgentOutput($raw);

        $this->assertSame('Built it.', $parsed['text']);
        $this->assertSame('grok-build', $parsed['model']);
        $this->assertSame(200, $parsed['input_tokens']);
        $this->assertSame(15, $parsed['output_tokens']);
    }

    public function test_parses_streaming_json_with_openai_style_usage_keys(): void
    {
        $lines = [
            json_encode(['type' => 'assistant', 'message' => [
                'model'   => 'grok-build',
                'content' => [['type' => 'text', 'text' => 'answer']],
            ]]),
            json_encode(['type' => 'result', 'usage' => ['prompt_tokens' => 33, 'completion_tokens' => 9]]),
        ];
        $parsed = $this->backend()->parseAgentOutput(implode("\n", $lines));

        $this->assertSame('answer', $parsed['text']);
        $this->assertSame(33, $parsed['input_tokens']);
        $this->assertSame(9, $parsed['output_tokens']);
    }

    public function test_plain_text_fallback(): void
    {
        $parsed = $this->backend()->parseAgentOutput('plain grok reply');
        $this->assertSame('plain grok reply', $parsed['text']);
    }

    public function test_effort_levels_constant_matches_cli(): void
    {
        $this->assertSame(
            ['low', 'medium', 'high', 'xhigh', 'max'],
            GrokCliBackend::EFFORT_LEVELS,
        );
    }
}
