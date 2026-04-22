<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Backends\ClaudeCliBackend;
use SuperAICore\Backends\CodexCliBackend;
use SuperAICore\Backends\CopilotCliBackend;
use SuperAICore\Backends\GeminiCliBackend;
use SuperAICore\Backends\KiroCliBackend;
use SuperAICore\Contracts\StreamingBackend;

/**
 * Phase A regression: every CLI backend is expected to implement the
 * StreamingBackend contract introduced in this phase. If a new CLI is
 * added without implementing it, the dashboard / Process Monitor /
 * task runner will silently degrade to one-shot generate() — this test
 * surfaces that miss at CI time.
 */
final class StreamingBackendContractTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string}>
     */
    public static function cliBackendProvider(): iterable
    {
        yield 'claude'  => [ClaudeCliBackend::class];
        yield 'codex'   => [CodexCliBackend::class];
        yield 'gemini'  => [GeminiCliBackend::class];
        yield 'kiro'    => [KiroCliBackend::class];
        yield 'copilot' => [CopilotCliBackend::class];
    }

    /**
     * @dataProvider cliBackendProvider
     */
    public function test_implements_streaming_backend(string $class): void
    {
        $instance = new $class();
        $this->assertInstanceOf(
            StreamingBackend::class,
            $instance,
            "{$class} must implement StreamingBackend so live tee + Process Monitor + onChunk work."
        );
    }

    /**
     * @dataProvider cliBackendProvider
     */
    public function test_stream_method_returns_null_on_empty_prompt(string $class): void
    {
        $instance = new $class();
        $this->assertNull($instance->stream(['prompt' => '']));
    }
}
