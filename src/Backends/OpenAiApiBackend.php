<?php

namespace SuperAICore\Backends;

use SuperAICore\Contracts\Backend;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

class OpenAiApiBackend implements Backend
{
    public function __construct(
        protected ?LoggerInterface $logger = null,
        protected ?Client $http = null,
    ) {
        $this->http ??= new Client(['timeout' => 60]);
    }

    public function name(): string
    {
        return 'openai_api';
    }

    public function isAvailable(array $providerConfig = []): bool
    {
        return !empty($providerConfig['api_key']) || !empty(getenv('OPENAI_API_KEY'));
    }

    public function generate(array $options): ?array
    {
        $providerConfig = $options['provider_config'] ?? [];
        $apiKey = $providerConfig['api_key'] ?? getenv('OPENAI_API_KEY');
        if (!$apiKey) return null;

        $baseUrl = rtrim($providerConfig['base_url'] ?? 'https://api.openai.com', '/');
        $model = $options['model'] ?? $providerConfig['model'] ?? 'gpt-4o-mini';
        $maxTokens = $options['max_tokens'] ?? 500;

        $messages = $options['messages'] ?? [
            ['role' => 'user', 'content' => $options['prompt'] ?? ''],
        ];
        if (!empty($options['system'])) {
            array_unshift($messages, ['role' => 'system', 'content' => $options['system']]);
        }

        try {
            $response = $this->http->post("{$baseUrl}/v1/chat/completions", [
                'headers' => [
                    'Authorization' => "Bearer {$apiKey}",
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    'max_tokens' => $maxTokens,
                    'messages' => $messages,
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);
            $text = $data['choices'][0]['message']['content'] ?? '';
            if ($text === '') return null;

            $usage = $data['usage'] ?? [];
            return [
                'text' => $text,
                'model' => $data['model'] ?? $model,
                'usage' => [
                    'input_tokens' => $usage['prompt_tokens'] ?? 0,
                    'output_tokens' => $usage['completion_tokens'] ?? 0,
                ],
                'stop_reason' => $data['choices'][0]['finish_reason'] ?? null,
            ];
        } catch (\Throwable $e) {
            if ($this->logger) $this->logger->warning("OpenAiApiBackend error: {$e->getMessage()}");
            return null;
        }
    }
}
