<?php

namespace SuperAICore\Backends;

use SuperAICore\Contracts\Backend;
use SuperAICore\Services\GeminiModelResolver;
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
class GeminiApiBackend implements Backend
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

    protected function log(string $level, string $msg): void
    {
        if ($this->logger) $this->logger->{$level}($msg);
    }
}
