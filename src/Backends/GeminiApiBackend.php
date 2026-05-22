<?php

namespace SuperAICore\Backends;

use SuperAICore\Contracts\Backend;
use SuperAICore\Contracts\StreamableTextBackend;
use SuperAICore\Services\GeminiModelResolver;
use Generator;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

/**
 * HTTP adapter for the Google Generative Language API (Gemini).
 *
 * Endpoint shape: POST {base_url}/v1beta/models/{model}:generateContent?key={api_key}
 * Used when a provider under the "Gemini" engine has type google-ai (direct
 * Google AI Studio). Vertex AI Gemini access goes through the CLI backend
 * today to reuse its ADC token plumbing.
 */
class GeminiApiBackend implements Backend, StreamableTextBackend
{
    public function __construct(
        protected ?LoggerInterface $logger = null,
        protected ?Client $http = null,
    ) {
        $this->http ??= new Client(['timeout' => 60]);
    }

    public function name(): string
    {
        return 'gemini_api';
    }

    public function isAvailable(array $providerConfig = []): bool
    {
        return !empty($providerConfig['api_key'])
            || !empty(getenv('GEMINI_API_KEY'))
            || !empty(getenv('GOOGLE_API_KEY'));
    }

    public function generate(array $options): ?array
    {
        $providerConfig = $options['provider_config'] ?? [];
        $apiKey = $providerConfig['api_key']
            ?? getenv('GEMINI_API_KEY')
            ?: getenv('GOOGLE_API_KEY');
        if (!$apiKey) {
            $this->log('warning', 'GeminiApiBackend: no api_key');
            return null;
        }

        $baseUrl = rtrim($providerConfig['base_url'] ?? 'https://generativelanguage.googleapis.com', '/');
        $model = GeminiModelResolver::resolve($options['model'] ?? $providerConfig['model'] ?? null)
            ?? GeminiModelResolver::defaultFor('pro');
        $maxTokens = $options['max_tokens'] ?? 500;

        // Gemini expects `contents: [{ role, parts: [{text}] }]`
        $contents = [];
        if (!empty($options['messages'])) {
            foreach ($options['messages'] as $m) {
                $role = ($m['role'] ?? 'user') === 'assistant' ? 'model' : 'user';
                $contents[] = [
                    'role' => $role,
                    'parts' => [['text' => $m['content'] ?? '']],
                ];
            }
        } else {
            $contents[] = [
                'role' => 'user',
                'parts' => [['text' => $options['prompt'] ?? '']],
            ];
        }

        $body = [
            'contents' => $contents,
            'generationConfig' => ['maxOutputTokens' => $maxTokens],
        ];
        if (!empty($options['system'])) {
            $body['systemInstruction'] = ['parts' => [['text' => $options['system']]]];
        }

        try {
            $response = $this->http->post(
                "{$baseUrl}/v1beta/models/{$model}:generateContent",
                [
                    'query' => ['key' => $apiKey],
                    'headers' => ['content-type' => 'application/json'],
                    'json' => $body,
                ]
            );

            $data = json_decode((string) $response->getBody(), true);

            $text = '';
            foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
                if (isset($part['text'])) $text .= $part['text'];
            }
            if ($text === '') return null;

            $usage = $data['usageMetadata'] ?? [];
            return [
                'text' => $text,
                'model' => $data['modelVersion'] ?? $model,
                'usage' => [
                    'input_tokens'  => $usage['promptTokenCount']     ?? 0,
                    'output_tokens' => $usage['candidatesTokenCount'] ?? 0,
                ],
                'stop_reason' => $data['candidates'][0]['finishReason'] ?? null,
            ];
        } catch (\Throwable $e) {
            $this->log('warning', "GeminiApiBackend error: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * 9Router-borrowed real streaming. Calls Gemini's streamGenerateContent
     * endpoint (SSE variant) and yields canonical envelopes:
     *
     *   ['type' => 'text', 'delta' => '...']
     *   ['type' => 'usage', 'input_tokens' => N, 'output_tokens' => M]
     *   ['type' => 'stop',  'reason' => 'STOP' | 'MAX_TOKENS' | ...]
     *
     * Gemini's SSE-style endpoint is /v1beta/models/{m}:streamGenerateContent?alt=sse
     * — must explicitly request alt=sse, otherwise the response is a single
     * JSON array (no streaming benefit).
     */
    public function generateStream(array $options): Generator
    {
        $providerConfig = $options['provider_config'] ?? [];
        $apiKey = $providerConfig['api_key']
            ?? getenv('GEMINI_API_KEY')
            ?: getenv('GOOGLE_API_KEY');
        if (!$apiKey) {
            $this->log('warning', 'GeminiApiBackend stream: no api_key');
            yield ['type' => 'stop', 'reason' => 'error'];
            return;
        }

        $baseUrl = rtrim($providerConfig['base_url'] ?? 'https://generativelanguage.googleapis.com', '/');
        $model = GeminiModelResolver::resolve($options['model'] ?? $providerConfig['model'] ?? null)
            ?? GeminiModelResolver::defaultFor('pro');
        $maxTokens = $options['max_tokens'] ?? 4096;

        $contents = [];
        if (!empty($options['messages'])) {
            foreach ($options['messages'] as $m) {
                $role = ($m['role'] ?? 'user') === 'assistant' ? 'model' : 'user';
                $contents[] = ['role' => $role, 'parts' => [['text' => $m['content'] ?? '']]];
            }
        } else {
            $contents[] = ['role' => 'user', 'parts' => [['text' => $options['prompt'] ?? '']]];
        }
        $body = [
            'contents' => $contents,
            'generationConfig' => ['maxOutputTokens' => $maxTokens],
        ];
        if (!empty($options['system'])) {
            $body['systemInstruction'] = ['parts' => [['text' => $options['system']]]];
        }

        $response = $this->http->post(
            "{$baseUrl}/v1beta/models/{$model}:streamGenerateContent",
            [
                'query'  => ['alt' => 'sse', 'key' => $apiKey],
                'headers'=> ['content-type' => 'application/json', 'accept' => 'text/event-stream'],
                'json'   => $body,
                'stream' => true,
            ]
        );

        $stream = $response->getBody();
        $buffer = '';
        while (!$stream->eof()) {
            $chunk = $stream->read(2048);
            if ($chunk === '' || $chunk === false) { usleep(10_000); continue; }
            $buffer .= $chunk;
            while (($nlPos = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $nlPos), "\r");
                $buffer = substr($buffer, $nlPos + 1);
                if ($line === '' || !str_starts_with($line, 'data:')) continue;
                $data = trim(substr($line, 5));
                if ($data === '' || $data === '[DONE]') continue;
                $parsed = json_decode($data, true);
                if (!is_array($parsed)) continue;
                yield from $this->translateGeminiEvent($parsed);
            }
        }
        // No explicit DONE sentinel — emit terminal stop if upstream didn't.
        yield ['type' => 'stop', 'reason' => 'end_turn'];
    }

    /** @return Generator<int, array<string,mixed>> */
    private function translateGeminiEvent(array $data): Generator
    {
        $candidate = $data['candidates'][0] ?? null;
        if ($candidate !== null) {
            $parts = $candidate['content']['parts'] ?? [];
            foreach ($parts as $part) {
                if (isset($part['text']) && $part['text'] !== '') {
                    $isThought = !empty($part['thought']);
                    yield [
                        'type'  => $isThought ? 'thinking' : 'text',
                        'delta' => (string) $part['text'],
                    ];
                } elseif (isset($part['functionCall'])) {
                    $fc = $part['functionCall'];
                    yield [
                        'type'      => 'tool_use_delta',
                        'name'      => (string) ($fc['name'] ?? ''),
                        'arguments' => $fc['args'] ?? [],
                    ];
                }
            }
            if (!empty($candidate['finishReason'])) {
                yield ['type' => 'stop', 'reason' => (string) $candidate['finishReason']];
            }
        }
        if (isset($data['usageMetadata'])) {
            $u = $data['usageMetadata'];
            yield [
                'type'          => 'usage',
                'input_tokens'  => (int) ($u['promptTokenCount']     ?? 0),
                'output_tokens' => (int) ($u['candidatesTokenCount'] ?? 0),
            ];
        }
    }

    protected function log(string $level, string $msg): void
    {
        if ($this->logger) $this->logger->{$level}($msg);
    }
}
