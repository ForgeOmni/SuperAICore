<?php

namespace ForgeOmni\AiCore\Backends;

use ForgeOmni\AiCore\Contracts\Backend;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

class AnthropicApiBackend implements Backend
{
    public function __construct(
        protected ?LoggerInterface $logger = null,
        protected ?Client $http = null,
    ) {
        $this->http ??= new Client(['timeout' => 60]);
    }

    public function name(): string
    {
        return 'anthropic_api';
    }

    public function isAvailable(array $providerConfig = []): bool
    {
        return !empty($providerConfig['api_key']) || !empty(getenv('ANTHROPIC_API_KEY'));
    }

    public function generate(array $options): ?array
    {
        $providerConfig = $options['provider_config'] ?? [];
        $apiKey = $providerConfig['api_key'] ?? getenv('ANTHROPIC_API_KEY');
        if (!$apiKey) {
            $this->log('warning', 'AnthropicApiBackend: no api_key');
            return null;
        }

        $baseUrl = rtrim($providerConfig['base_url'] ?? 'https://api.anthropic.com', '/');
        $model = $options['model'] ?? $providerConfig['model'] ?? 'claude-sonnet-4-5-20241022';
        $maxTokens = $options['max_tokens'] ?? 500;

        $messages = $options['messages'] ?? [
            ['role' => 'user', 'content' => $options['prompt'] ?? ''],
        ];

        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => $messages,
        ];
        if (!empty($options['system'])) {
            $body['system'] = $options['system'];
        }

        try {
            $response = $this->http->post("{$baseUrl}/v1/messages", [
                'headers' => [
                    'x-api-key' => $apiKey,
                    'anthropic-version' => $providerConfig['api_version'] ?? '2023-06-01',
                    'content-type' => 'application/json',
                ],
                'json' => $body,
            ]);

            $data = json_decode((string) $response->getBody(), true);
            $text = '';
            foreach ($data['content'] ?? [] as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $text .= $block['text'];
                }
            }
            if ($text === '') return null;

            $usage = $data['usage'] ?? [];
            return [
                'text' => $text,
                'model' => $data['model'] ?? $model,
                'usage' => [
                    'input_tokens' => $usage['input_tokens'] ?? 0,
                    'output_tokens' => $usage['output_tokens'] ?? 0,
                ],
                'stop_reason' => $data['stop_reason'] ?? null,
            ];
        } catch (\Throwable $e) {
            $this->log('warning', "AnthropicApiBackend error: {$e->getMessage()}");
            return null;
        }
    }

    protected function log(string $level, string $msg): void
    {
        if ($this->logger) $this->logger->{$level}($msg);
    }
}
