<?php

namespace SuperAICore\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SuperAICore\Backends\AnthropicApiBackend;

/**
 * Covers the per-model request surface SuperAgent 1.1.10 codified for the
 * Claude 5 generation — adaptive thinking, the `output_config.effort` dial,
 * and the sampling params those models reject.
 */
final class AnthropicApiBackendTest extends TestCase
{
    /** @var list<array<string,mixed>> */
    private array $transactions = [];

    /**
     * Run a generate() call against a stubbed Anthropic response and return
     * the JSON body the backend actually sent.
     *
     * @param  array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function capture(array $options): array
    {
        $this->transactions = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'model'   => $options['model'] ?? 'claude-opus-5',
                'content' => [['type' => 'text', 'text' => 'ok']],
                'usage'   => ['input_tokens' => 3, 'output_tokens' => 1],
            ])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->transactions));

        $backend = new AnthropicApiBackend(null, new Client(['handler' => $stack]));
        $backend->generate($options + [
            'prompt'          => 'hi',
            'provider_config' => ['api_key' => 'sk-test'],
        ]);

        $request = $this->transactions[0]['request'];

        return json_decode((string) $request->getBody(), true);
    }

    public function test_opus_5_gets_adaptive_thinking_never_a_token_budget(): void
    {
        $body = $this->capture([
            'model'                  => 'claude-opus-5',
            'thinking'               => true,
            // Even an explicit budget must not reach the wire — Opus 5 400s
            // on `budget_tokens`; the intent to think becomes `adaptive`.
            'thinking_budget_tokens' => 12000,
        ]);

        $this->assertSame(['type' => 'adaptive'], $body['thinking']);
        $this->assertArrayNotHasKey('budget_tokens', $body['thinking']);
    }

    public function test_older_claude_4_model_keeps_the_fixed_thinking_budget(): void
    {
        $body = $this->capture([
            'model'                  => 'claude-opus-4-20250514',
            'thinking'               => true,
            'thinking_budget_tokens' => 12000,
        ]);

        $this->assertSame('enabled', $body['thinking']['type']);
        $this->assertSame(12000, $body['thinking']['budget_tokens']);
    }

    /**
     * Opus 5 rejects `thinking: {type:"disabled"}` above `high` effort, so
     * "off" must omit the key entirely rather than send the disabled form.
     */
    public function test_thinking_off_omits_the_key_entirely(): void
    {
        $body = $this->capture([
            'model'    => 'claude-opus-5',
            'thinking' => false,
            'effort'   => 'max',
        ]);

        $this->assertArrayNotHasKey('thinking', $body);
        $this->assertSame(['effort' => 'max'], $body['output_config']);
    }

    public function test_thinking_is_absent_when_the_caller_never_asked(): void
    {
        $body = $this->capture(['model' => 'claude-opus-5']);

        $this->assertArrayNotHasKey('thinking', $body);
        $this->assertArrayNotHasKey('output_config', $body);
    }

    public function test_effort_dial_is_normalised_and_gated_by_model(): void
    {
        $this->assertSame(
            ['effort' => 'low'],
            $this->capture(['model' => 'claude-opus-5', 'effort' => 'minimal'])['output_config'],
        );
        // `reasoning_effort` is accepted as an alias of `effort`.
        $this->assertSame(
            ['effort' => 'max'],
            $this->capture(['model' => 'claude-opus-5', 'reasoning_effort' => 'highest'])['output_config'],
        );
        // Haiku has no effort dial — a stray value must not reach the wire.
        $this->assertArrayNotHasKey(
            'output_config',
            $this->capture(['model' => 'claude-haiku-4-5-20251001', 'effort' => 'high']),
        );
        // Unknown level yields nothing rather than a 400.
        $this->assertArrayNotHasKey(
            'output_config',
            $this->capture(['model' => 'claude-opus-5', 'effort' => 'turbo']),
        );
    }

    public function test_sampling_params_are_dropped_on_claude_5_and_kept_elsewhere(): void
    {
        $opus5 = $this->capture([
            'model'       => 'claude-opus-5',
            'temperature' => 0.7,
            'top_p'       => 0.9,
            'top_k'       => 40,
        ]);
        $this->assertArrayNotHasKey('temperature', $opus5);
        $this->assertArrayNotHasKey('top_p', $opus5);
        $this->assertArrayNotHasKey('top_k', $opus5);

        $haiku = $this->capture([
            'model'       => 'claude-haiku-4-5-20251001',
            'temperature' => 0.7,
        ]);
        $this->assertSame(0.7, $haiku['temperature']);
    }

    public function test_max_tokens_is_clamped_to_the_model_output_ceiling(): void
    {
        // Opus 5 tops out at 128K output; asking for more would 400.
        $body = $this->capture(['model' => 'claude-opus-5', 'max_tokens' => 500000]);

        $this->assertSame(131072, $body['max_tokens']);
    }

    public function test_family_alias_is_resolved_before_the_request(): void
    {
        $body = $this->capture(['model' => 'opus', 'thinking' => true]);

        $this->assertSame('claude-opus-5', $body['model']);
        $this->assertSame(['type' => 'adaptive'], $body['thinking']);
    }
}
