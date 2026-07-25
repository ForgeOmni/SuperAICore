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
    /**
     * `output_config.effort` levels Anthropic's GA effort dial accepts.
     * Mirrors SuperAgent's `AnthropicProvider::reasoningEffortFragment()`
     * so a host can pass the same vocabulary to either path.
     */
    private const EFFORT_LEVELS = [
        'low' => 'low', 'minimal' => 'low',
        'medium' => 'medium', 'mid' => 'medium',
        'high' => 'high',
        'xhigh' => 'xhigh',
        'max' => 'max', 'highest' => 'max',
    ];

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

        $body = $this->buildBody($model, $messages, $maxTokens, $options);

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

        $body = $this->buildBody($model, $messages, $maxTokens, $options) + ['stream' => true];

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

    /**
     * Assemble the `/v1/messages` body, applying the per-model request
     * surface SuperAgent 1.1.10 codified for the Claude 5 generation.
     *
     * Three model-dependent rules, all driven off the SDK so they stay
     * correct as the catalog moves:
     *
     *   - **Thinking** — Opus 5 / Fable 5 / Sonnet 5 and Opus 4.6–4.8 take
     *     `thinking: {type:"adaptive"}` and 400 on `budget_tokens`; older
     *     Claude 4 models take the fixed budget. `ThinkingConfig` picks the
     *     right shape. On Opus 5, `type:"disabled"` is rejected above `high`
     *     effort — so "off" omits the key entirely rather than sending it.
     *   - **Effort** — `output_config.effort` (low…max) is emitted only for
     *     models whose catalog entry advertises the dial, so a stray
     *     `effort` on Haiku can't 400 the request.
     *   - **Sampling params** — `temperature` / `top_p` / `top_k` were
     *     removed on the Claude 5 generation and Opus 4.7/4.8; they are
     *     forwarded only where they're still accepted.
     *
     * `max_tokens` is clamped to the model's published output ceiling when
     * the catalog knows it (Opus 5: 128K), turning a 400 into a capped run.
     *
     * @param  array<int, array<string, mixed>> $messages
     * @param  array<string, mixed>             $options
     * @return array<string, mixed>
     */
    private function buildBody(string $model, array $messages, int $maxTokens, array $options): array
    {
        $caps = $this->capabilities($model);

        $maxOutput = (int) ($caps['max_output'] ?? 0);
        if ($maxOutput > 0 && $maxTokens > $maxOutput) {
            $this->log('info', "AnthropicApiBackend: max_tokens {$maxTokens} clamped to {$model}'s {$maxOutput}");
            $maxTokens = $maxOutput;
        }

        $body = [
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'messages'   => $messages,
        ];
        if (!empty($options['system'])) {
            $body['system'] = $options['system'];
        }

        $thinking = $this->thinkingParameter($model, $options);
        if ($thinking !== null) {
            $body['thinking'] = $thinking;
        }

        $effort = $this->effortLevel($options);
        if ($effort !== null && $this->supportsEffort($model, $caps)) {
            $body['output_config'] = ['effort' => $effort];
        }

        if (!$this->rejectsSamplingParams($model)) {
            foreach (['temperature', 'top_p', 'top_k'] as $param) {
                if (isset($options[$param])) {
                    $body[$param] = $options[$param];
                }
            }
        }

        return $body;
    }

    /**
     * Build the `thinking` fragment via SuperAgent's `ThinkingConfig`, which
     * owns the adaptive-vs-budget decision per model. Returns null when the
     * caller didn't ask for thinking, when the model can't think, or when
     * thinking was explicitly turned off — in the last case we omit the key
     * rather than sending `type:"disabled"`, which Opus 5 rejects above
     * `high` effort.
     *
     * Accepted `$options['thinking']`: `true` / `'adaptive'` / `'enabled'`
     * (with optional `thinking_budget_tokens`), or `false` / `'off'`.
     *
     * @param  array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    private function thinkingParameter(string $model, array $options): ?array
    {
        if (!array_key_exists('thinking', $options) || $options['thinking'] === null) {
            return null;
        }
        $want = $options['thinking'];
        if ($want === false || (is_string($want) && in_array(strtolower($want), ['off', 'disabled', 'false'], true))) {
            return null;
        }
        if (!class_exists(\SuperAgent\Thinking\ThinkingConfig::class)) {
            return null;
        }
        try {
            $budget = isset($options['thinking_budget_tokens'])
                ? (int) $options['thinking_budget_tokens']
                : null;
            $config = $budget !== null
                ? \SuperAgent\Thinking\ThinkingConfig::enabled($budget)
                : \SuperAgent\Thinking\ThinkingConfig::adaptive();

            return $config->toApiParameter($model);
        } catch (\Throwable $e) {
            $this->log('warning', "AnthropicApiBackend: thinking config failed — {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Normalise `effort` / `reasoning_effort` to a level the API accepts.
     * Unknown values yield null (there is no effort-off on Anthropic).
     *
     * @param array<string, mixed> $options
     */
    private function effortLevel(array $options): ?string
    {
        $raw = $options['effort'] ?? $options['reasoning_effort'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        return self::EFFORT_LEVELS[strtolower(trim($raw))] ?? null;
    }

    /**
     * Does this model take `output_config.effort`? Prefers the SDK catalog
     * (refreshable via `superagent models update`, so new models work
     * without a release here) and falls back to the id families the SDK
     * hardcodes.
     *
     * @param array<string, mixed> $caps
     */
    private function supportsEffort(string $model, array $caps): bool
    {
        if (isset($caps['effort_control']) || isset($caps['reasoning_effort'])) {
            return (bool) ($caps['effort_control'] ?? $caps['reasoning_effort']);
        }
        return $this->matchesAny($model, [
            'claude-fable', 'fable-5', 'claude-opus-5', 'opus-5',
            'claude-sonnet-5', 'sonnet-5',
            'opus-4-5', 'opus-4-6', 'opus-4-7', 'opus-4-8', 'sonnet-4-6',
        ]);
    }

    /**
     * The Claude 5 generation (Fable 5, Opus 5, Sonnet 5) and Opus 4.7/4.8
     * 400 on `temperature` / `top_p` / `top_k`. Mirrors the SDK's
     * `AnthropicProvider::modelRejectsSamplingParams()`, which is protected.
     */
    private function rejectsSamplingParams(string $model): bool
    {
        return $this->matchesAny($model, [
            'claude-fable', 'fable-5', 'claude-opus-5', 'opus-5',
            'claude-sonnet-5', 'sonnet-5', 'opus-4-7', 'opus-4-8',
        ]);
    }

    /** @param list<string> $needles */
    private function matchesAny(string $model, array $needles): bool
    {
        $model = strtolower($model);
        foreach ($needles as $needle) {
            if (str_contains($model, $needle)) return true;
        }
        return false;
    }

    /**
     * Capability block from SuperAgent's ModelCatalog (bundled
     * resources/models.json + `~/.superagent/models.json` override).
     * Empty array when the SDK or the model is unknown.
     *
     * @return array<string, mixed>
     */
    private function capabilities(string $model): array
    {
        if (!class_exists(\SuperAgent\Providers\ModelCatalog::class)) {
            return [];
        }
        try {
            return \SuperAgent\Providers\ModelCatalog::capabilitiesFor($model);
        } catch (\Throwable) {
            return [];
        }
    }

    protected function log(string $level, string $msg): void
    {
        if ($this->logger) $this->logger->{$level}($msg);
    }
}
