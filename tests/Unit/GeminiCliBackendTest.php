<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Backends\GeminiCliBackend;

final class GeminiCliBackendTest extends TestCase
{
    public function test_parses_real_json_envelope_and_selects_main_role(): void
    {
        $backend = new GeminiCliBackend();

        // Shape modelled on actual `gemini -p ... --output-format=json`
        // output. Two models show up (one routes utility calls, one is
        // the "main" answering model) — we must pick the one tagged
        // `roles.main` regardless of ordering.
        $json = json_encode([
            'session_id' => 'abc',
            'response'   => 'Hi.',
            'stats' => [
                'models' => [
                    'gemini-2.5-flash-lite' => [
                        'tokens' => ['input' => 2814, 'candidates' => 45, 'total' => 2975, 'cached' => 0, 'thoughts' => 116],
                        'roles'  => ['utility_router' => ['totalRequests' => 1]],
                    ],
                    'gemini-3-flash-preview' => [
                        'tokens' => ['input' => 32538, 'candidates' => 7, 'total' => 32596, 'cached' => 10, 'thoughts' => 51],
                        'roles'  => ['main' => ['totalRequests' => 1]],
                    ],
                ],
            ],
        ]);

        $parsed = $backend->parseJson($json);

        $this->assertNotNull($parsed);
        $this->assertSame('Hi.', $parsed['text']);
        $this->assertSame('gemini-3-flash-preview', $parsed['model']);
        $this->assertSame(32538, $parsed['input_tokens']);
        $this->assertSame(7,     $parsed['output_tokens']);
        $this->assertSame(10,    $parsed['cached_input_tokens']);
        $this->assertSame(51,    $parsed['thoughts_tokens']);
    }

    public function test_falls_back_to_highest_output_when_no_main_role(): void
    {
        $backend = new GeminiCliBackend();

        $json = json_encode([
            'response' => 'ok',
            'stats' => [
                'models' => [
                    'small' => ['tokens' => ['input' => 10, 'candidates' => 2]],
                    'big'   => ['tokens' => ['input' => 20, 'candidates' => 50]],
                ],
            ],
        ]);

        $parsed = $backend->parseJson($json);

        $this->assertSame('big', $parsed['model']);
        $this->assertSame(50, $parsed['output_tokens']);
    }

    public function test_uses_prompt_token_field_when_input_missing(): void
    {
        // Older gemini-cli builds only emitted `prompt` not `input`
        $backend = new GeminiCliBackend();

        $json = json_encode([
            'response' => 'ok',
            'stats' => [
                'models' => [
                    'x' => ['tokens' => ['prompt' => 99, 'candidates' => 3]],
                ],
            ],
        ]);

        $parsed = $backend->parseJson($json);

        $this->assertSame(99, $parsed['input_tokens']);
    }

    public function test_returns_null_when_no_response_field(): void
    {
        $backend = new GeminiCliBackend();
        $this->assertNull($backend->parseJson(''));
        $this->assertNull($backend->parseJson('{"session_id":"x"}'));
        $this->assertNull($backend->parseJson('plain text'));
    }

    public function test_tolerates_missing_stats(): void
    {
        $backend = new GeminiCliBackend();

        $parsed = $backend->parseJson('{"response":"hello"}');

        $this->assertSame('hello', $parsed['text']);
        $this->assertNull($parsed['model']);
        $this->assertSame(0, $parsed['input_tokens']);
        $this->assertSame(0, $parsed['output_tokens']);
    }

    public function test_isavailable_only_checks_binary_in_path(): void
    {
        $backend = new GeminiCliBackend(binary: '__definitely_not_a_real_binary__');
        $this->assertFalse($backend->isAvailable());
    }
}
