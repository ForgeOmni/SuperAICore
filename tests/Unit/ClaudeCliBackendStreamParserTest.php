<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Backends\ClaudeCliBackend;

/**
 * Covers the NDJSON walker added in Phase A — `parseStreamJson()` reads
 * a `--output-format=stream-json` capture and pulls the LAST `result`
 * event's usage envelope. The single-shot `--output-format=json` parser
 * (`parseJson`) is covered by ClaudeCliBackendTest.
 */
final class ClaudeCliBackendStreamParserTest extends TestCase
{
    public function test_parses_stream_json_terminal_result_event(): void
    {
        $backend = new ClaudeCliBackend();

        // Modeled on a real claude -p --output-format=stream-json --verbose
        // capture: system_init line, a couple of assistant chunks, then the
        // terminal result event we want to extract.
        $stream = implode("\n", [
            json_encode(['type' => 'system', 'subtype' => 'init', 'session_id' => 'abc-123']),
            json_encode(['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => 'partial']]]]),
            json_encode(['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => ' more']]]]),
            json_encode([
                'type'           => 'result',
                'subtype'        => 'success',
                'is_error'       => false,
                'duration_ms'    => 4521,
                'num_turns'      => 2,
                'session_id'     => 'abc-123',
                'result'         => 'partial more',
                'stop_reason'    => 'end_turn',
                'total_cost_usd' => 0.0123,
                'usage' => [
                    'input_tokens'                 => 18,
                    'output_tokens'                => 11,
                    'cache_read_input_tokens'      => 442245,
                    'cache_creation_input_tokens'  => 70877,
                ],
                'modelUsage' => [
                    'claude-sonnet-4-5-20241022' => ['costUSD' => 0.012, 'outputTokens' => 11],
                ],
            ]),
        ]);

        $parsed = $backend->parseStreamJson($stream);

        $this->assertNotNull($parsed);
        $this->assertSame('partial more', $parsed['text']);
        $this->assertSame('claude-sonnet-4-5-20241022', $parsed['model']);
        $this->assertSame(18, $parsed['input_tokens']);
        $this->assertSame(11, $parsed['output_tokens']);
        $this->assertSame(442245, $parsed['cache_read_input_tokens']);
        $this->assertSame(70877, $parsed['cache_creation_input_tokens']);
        $this->assertSame(0.0123, $parsed['total_cost_usd']);
        $this->assertSame('end_turn', $parsed['stop_reason']);
        $this->assertSame(2, $parsed['num_turns']);
        $this->assertSame('abc-123', $parsed['session_id']);
    }

    public function test_picks_last_result_event_when_multiple_present(): void
    {
        $backend = new ClaudeCliBackend();

        $stream = implode("\n", [
            json_encode(['type' => 'result', 'result' => 'first', 'usage' => ['input_tokens' => 1]]),
            json_encode(['type' => 'system', 'subtype' => 'compact_boundary']),
            json_encode(['type' => 'result', 'result' => 'last',  'usage' => ['input_tokens' => 99]]),
        ]);

        $parsed = $backend->parseStreamJson($stream);

        $this->assertNotNull($parsed);
        $this->assertSame('last', $parsed['text']);
        $this->assertSame(99, $parsed['input_tokens']);
    }

    public function test_returns_null_when_no_result_event(): void
    {
        $backend = new ClaudeCliBackend();
        $stream = json_encode(['type' => 'system', 'subtype' => 'init']);

        $this->assertNull($backend->parseStreamJson($stream));
    }

    public function test_skips_malformed_lines_gracefully(): void
    {
        $backend = new ClaudeCliBackend();
        $stream = implode("\n", [
            'not json at all',
            '{ broken json',
            '',
            json_encode(['type' => 'result', 'result' => 'ok', 'usage' => ['input_tokens' => 5]]),
            'trailing junk',
        ]);

        $parsed = $backend->parseStreamJson($stream);

        $this->assertNotNull($parsed);
        $this->assertSame('ok', $parsed['text']);
        $this->assertSame(5, $parsed['input_tokens']);
    }

    public function test_handles_crlf_line_endings(): void
    {
        $backend = new ClaudeCliBackend();
        $stream = json_encode(['type' => 'system'])
            . "\r\n"
            . json_encode(['type' => 'result', 'result' => 'ok', 'usage' => []])
            . "\r\n";

        $parsed = $backend->parseStreamJson($stream);
        $this->assertNotNull($parsed);
        $this->assertSame('ok', $parsed['text']);
    }
}
