<?php

namespace SuperAICore\Backends;

use SuperAICore\Contracts\Backend;
use SuperAICore\Contracts\StreamableTextBackend;
use Generator;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

class OpenAiApiBackend implements Backend, StreamableTextBackend
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

    /**
     * 9Router-borrowed real streaming. Calls /v1/chat/completions with
     * stream=true, parses OpenAI's SSE delta format, and yields canonical
     * envelopes:
     *
     *   ['type' => 'text', 'delta' => '...']
     *   ['type' => 'usage', 'input_tokens' => N, 'output_tokens' => M]
     *   ['type' => 'stop',  'reason' => 'stop' | 'length' | ...]
     *
     * The OpenAI proxy consumes these directly; non-OpenAI clients get
     * the same canonical shape so backend swaps are transparent.
     */
    public function generateStream(array $options): Generator
    {
        $providerConfig = $options['provider_config'] ?? [];
        $apiKey = $providerConfig['api_key'] ?? getenv('OPENAI_API_KEY');
        if (!$apiKey) {
            yield ['type' => 'stop', 'reason' => 'error'];
            return;
        }

        $baseUrl = rtrim($providerConfig['base_url'] ?? 'https://api.openai.com', '/');
        $model = $options['model'] ?? $providerConfig['model'] ?? 'gpt-4o-mini';
        $maxTokens = $options['max_tokens'] ?? 4096;

        $messages = $options['messages'] ?? [
            ['role' => 'user', 'content' => $options['prompt'] ?? ''],
        ];
        if (!empty($options['system'])) {
            array_unshift($messages, ['role' => 'system', 'content' => $options['system']]);
        }

        $response = $this->http->post("{$baseUrl}/v1/chat/completions", [
            'headers' => [
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type'  => 'application/json',
                'Accept'        => 'text/event-stream',
            ],
            'json' => [
                'model'      => $model,
                'max_tokens' => $maxTokens,
                'messages'   => $messages,
                'stream'     => true,
                // Include usage in the final SSE frame (post-2024 default for newer models)
                'stream_options' => ['include_usage' => true],
            ],
            'stream' => true,
        ]);

        $stream = $response->getBody();
        $buffer = '';
        while (!$stream->eof()) {
            $chunk = $stream->read(2048);
            if ($chunk === '' || $chunk === false) {
                usleep(10_000);
                continue;
            }
            $buffer .= $chunk;
            while (($nlPos = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $nlPos), "\r");
                $buffer = substr($buffer, $nlPos + 1);
                if ($line === '' || !str_starts_with($line, 'data:')) continue;
                $data = trim(substr($line, 5));
                if ($data === '') continue;
                if ($data === '[DONE]') {
                    yield ['type' => 'stop', 'reason' => 'end_turn'];
                    return;
                }
                $parsed = json_decode($data, true);
                if (!is_array($parsed)) continue;
                yield from $this->translateOpenAiEvent($parsed);
            }
        }
    }

    /** @return Generator<int, array<string,mixed>> */
    private function translateOpenAiEvent(array $data): Generator
    {
        // Usage frame (when stream_options.include_usage=true; choices is empty)
        $choices = $data['choices'] ?? [];
        if ($choices === [] && isset($data['usage'])) {
            yield [
                'type'          => 'usage',
                'input_tokens'  => (int) ($data['usage']['prompt_tokens']     ?? 0),
                'output_tokens' => (int) ($data['usage']['completion_tokens'] ?? 0),
            ];
            return;
        }
        $choice = $choices[0] ?? null;
        if ($choice === null) return;

        $delta = $choice['delta'] ?? [];
        if (isset($delta['content']) && $delta['content'] !== null) {
            yield ['type' => 'text', 'delta' => (string) $delta['content']];
        }
        if (!empty($delta['tool_calls'])) {
            $first = $delta['tool_calls'][0];
            yield [
                'type'   => 'tool_use_delta',
                'index'  => (int) ($first['index'] ?? 0),
                'name'   => (string) ($first['function']['name'] ?? ''),
                'delta'  => (string) ($first['function']['arguments'] ?? ''),
            ];
        }
        if (!empty($choice['finish_reason'])) {
            yield ['type' => 'stop', 'reason' => (string) $choice['finish_reason']];
        }
    }
}
