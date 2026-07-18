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
        // grok 0.2.102 `--effort` accepts exactly high|medium|low (the
        // grok-4.5 three-level dial). xhigh/max are NOT accepted.
        $this->assertSame(
            ['low', 'medium', 'high'],
            GrokCliBackend::EFFORT_LEVELS,
        );
    }

    public function test_effort_clamps_xhigh_and_max_to_high(): void
    {
        $b = new ExposedGrokCliBackend();
        // Cross-engine `xhigh`/`max` clamp up to grok's `high` so the strongest
        // reasoning is still requested — never passed through verbatim (the CLI
        // would reject them and fail the dispatch).
        $this->assertSame('high', $b->normalize('max'));
        $this->assertSame('high', $b->normalize('xhigh'));
        $this->assertSame('high', $b->normalize('  MAX '));
    }

    public function test_effort_passes_through_valid_levels_and_drops_the_rest(): void
    {
        $b = new ExposedGrokCliBackend();
        $this->assertSame('low', $b->normalize('low'));
        $this->assertSame('medium', $b->normalize('MEDIUM'));
        $this->assertSame('high', $b->normalize('high'));
        // off / none / minimal / unknown / blank → no flag (null), never an
        // error-triggering value.
        $this->assertNull($b->normalize('off'));
        $this->assertNull($b->normalize('none'));
        $this->assertNull($b->normalize('minimal'));
        $this->assertNull($b->normalize('bogus'));
        $this->assertNull($b->normalize(''));
        $this->assertNull($b->normalize(null));
    }

    public function test_append_session_flags_builds_resume(): void
    {
        $b = new ExposedGrokCliBackend();
        $cmd = ['grok', '-p', 'x'];
        $b->sessionFlags($cmd, ['resume_session_id' => 'sess-42']);
        $this->assertContains('--resume', $cmd);
        $this->assertContains('sess-42', $cmd);
    }

    public function test_append_session_flags_continue_and_fork(): void
    {
        $b = new ExposedGrokCliBackend();
        $cmd = [];
        $b->sessionFlags($cmd, ['continue_session' => true, 'fork_session' => true]);
        $this->assertContains('--continue', $cmd);
        $this->assertContains('--fork-session', $cmd);
        $this->assertNotContains('--resume', $cmd);
    }

    public function test_resume_wins_over_continue(): void
    {
        $b = new ExposedGrokCliBackend();
        $cmd = [];
        $b->sessionFlags($cmd, ['resume_session_id' => 'sess-9', 'continue_session' => true]);
        $this->assertContains('--resume', $cmd);
        $this->assertNotContains('--continue', $cmd);
    }

    public function test_rich_result_json_surfaces_session_cache_turns_and_thinking(): void
    {
        $raw = json_encode([
            'type'           => 'result',
            'result'         => 'done',
            'model'          => 'grok-4.5',
            'sessionId'      => 'sess-abc',
            'stopReason'     => 'end_turn',
            'num_turns'      => 4,
            'total_cost_usd' => 0.0123,
            'thought'        => 'let me think…',
            'usage'          => [
                'input_tokens'            => 500,
                'output_tokens'           => 60,
                'cache_read_input_tokens' => 400,
            ],
        ]);
        $p = $this->backend()->parseAgentOutput($raw);

        $this->assertSame('done', $p['text']);
        $this->assertSame('sess-abc', $p['session_id']);
        $this->assertSame('end_turn', $p['stop_reason']);
        $this->assertSame(4, $p['turns']);
        $this->assertSame(400, $p['cache_read_input_tokens']);
        $this->assertSame('let me think…', $p['thinking']);
        $this->assertEqualsWithDelta(0.0123, $p['cost_usd'], 0.00001);
    }

    public function test_envelope_surfaces_session_and_cache_but_not_cost(): void
    {
        $b = new ExposedGrokCliBackend();
        $parsed = $this->backend()->parseAgentOutput(json_encode([
            'type' => 'result', 'result' => 'ok', 'model' => 'grok-4.5',
            'sessionId' => 'sess-1', 'num_turns' => 2, 'total_cost_usd' => 5.0,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 2, 'cache_read_input_tokens' => 3],
        ]));
        $env = $b->envelope($parsed, 'grok-4.5', 'end_turn');

        $this->assertSame('sess-1', $env['session_id']);
        $this->assertSame(2, $env['turns']);
        $this->assertSame(3, $env['usage']['cache_read_input_tokens']);
        // Subscription channel — cost must NOT leak into the envelope or it
        // would double-count against the $0 CostCalculator booking.
        $this->assertArrayNotHasKey('cost_usd', $env);
    }
}

/** Exposes GrokCliBackend's protected seams for unit assertions. */
final class ExposedGrokCliBackend extends GrokCliBackend
{
    public function normalize(?string $e): ?string
    {
        return $this->normalizeEffort($e);
    }

    /** @param string[] $cmd */
    public function sessionFlags(array &$cmd, array $options): void
    {
        $this->appendSessionFlags($cmd, $options);
    }

    public function envelope(array $parsed, ?string $model, string $defaultStop): array
    {
        return $this->buildEnvelope($parsed, $model, $defaultStop);
    }
}
