<?php

namespace SuperAICore\Backends;

use SuperAICore\Contracts\Backend;
use SuperAICore\Contracts\StreamableTextBackend;
use SuperAICore\Services\ClaudeModelResolver;
use Generator;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

class AnthropicApiBackend implements Backend, StreamableTextBackend
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
        // Resolve family aliases (opus/sonnet/haiku) to the current full ID
        // so stale configs don't accidentally target a retired model.
        $model = ClaudeModelResolver::resolve($options['model'] ?? $providerConfig['model'] ?? null)
            ?? ClaudeModelResolver::defaultFor('sonnet');
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

    /**
     * 9Router-borrowed real streaming. Calls `/v1/messages` with stream=true,
     * parses Anthropic's SSE event stream, and yields canonical envelopes:
     *
     *   ['type' => 'text', 'delta' => '...']
     *   ['type' => 'usage', 'input_tokens' => N, 'output_tokens' => M]
     *   ['type' => 'stop',  'reason' => 'end_turn']
     *
     * The OpenAI-compat proxy consumes these and forwards as OpenAI chat
     * completion chunks. Anthropic-specific event types (thinking, tool_use)
     * are passed through as 'thinking' / 'tool_use_delta' for hosts that
     * want them; the OpenAI proxy ignores them.
     */
    public function generateStream(array $options): Generator
    {
        $providerConfig = $options['provider_config'] ?? [];
        $apiKey = $providerConfig['api_key'] ?? getenv('ANTHROPIC_API_KEY');
        if (!$apiKey) {
            $this->log('warning', 'AnthropicApiBackend stream: no api_key');
            yield ['type' => 'stop', 'reason' => 'error'];
            return;
        }

        $baseUrl = rtrim($providerConfig['base_url'] ?? 'https://api.anthropic.com', '/');
        $model = ClaudeModelResolver::resolve($options['model'] ?? $providerConfig['model'] ?? null)
            ?? ClaudeModelResolver::defaultFor('sonnet');
        $maxTokens = $options['max_tokens'] ?? 4096;

        $messages = $options['messages'] ?? [
            ['role' => 'user', 'content' => $options['prompt'] ?? ''],
        ];

        $body = [
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'messages'   => $messages,
            'stream'     => true,
        ];
        if (!empty($options['system'])) {
            $body['system'] = $options['system'];
        }

        $response = $this->http->post("{$baseUrl}/v1/messages", [
            'headers' => [
                'x-api-key'         => $apiKey,
                'anthropic-version' => $providerConfig['api_version'] ?? '2023-06-01',
                'content-type'      => 'application/json',
                'accept'            => 'text/event-stream',
            ],
            'json'   => $body,
            'stream' => true,
        ]);

        $stream = $response->getBody();
        $buffer = '';
        $currentEvent = null;
        while (!$stream->eof()) {
            $chunk = $stream->read(2048);
            if ($chunk === '' || $chunk === false) {
                usleep(10_000); // 10ms backoff on empty read
                continue;
            }
            $buffer .= $chunk;
            // SSE frames separated by blank line; events separated by \n\n
            while (($nlPos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $nlPos);
                $buffer = substr($buffer, $nlPos + 1);
                $line = rtrim($line, "\r");
                if ($line === '') {
                    $currentEvent = null;
                    continue;
                }
                if (str_starts_with($line, 'event:')) {
                    $currentEvent = trim(substr($line, 6));
                    continue;
                }
                if (str_starts_with($line, 'data:')) {
                    $data = trim(substr($line, 5));
                    if ($data === '') continue;
                    $parsed = json_decode($data, true);
                    if (!is_array($parsed)) continue;
                    yield from $this->translateAnthropicEvent($currentEvent ?? '', $parsed);
                }
            }
        }
    }

    /** @return Generator<int, array<string,mixed>> */
    private function translateAnthropicEvent(string $event, array $data): Generator
    {
        // event types per https://docs.anthropic.com/en/api/messages-streaming
        $type = $data['type'] ?? $event;
        switch ($type) {
            case 'content_block_delta':
                $delta = $data['delta'] ?? [];
                if (($delta['type'] ?? '') === 'text_delta') {
                    yield ['type' => 'text', 'delta' => (string) ($delta['text'] ?? '')];
                } elseif (($delta['type'] ?? '') === 'thinking_delta') {
                    yield ['type' => 'thinking', 'delta' => (string) ($delta['thinking'] ?? '')];
                } elseif (($delta['type'] ?? '') === 'input_json_delta') {
                    yield ['type' => 'tool_use_delta', 'delta' => (string) ($delta['partial_json'] ?? '')];
                }
                break;
            case 'message_delta':
                $usage = $data['usage'] ?? [];
                if (!empty($usage)) {
                    yield [
                        'type' => 'usage',
                        'input_tokens'  => (int) ($usage['input_tokens'] ?? 0),
                        'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
                    ];
                }
                $stop = $data['delta']['stop_reason'] ?? null;
                if ($stop !== null) {
                    yield ['type' => 'stop', 'reason' => (string) $stop];
                }
                break;
            case 'message_stop':
                yield ['type' => 'stop', 'reason' => 'end_turn'];
                break;
            // content_block_start / content_block_stop / message_start /
            // ping events: structural, no text payload — drop silently.
        }
    }

    protected function log(string $level, string $msg): void
    {
        if ($this->logger) $this->logger->{$level}($msg);
    }
}
