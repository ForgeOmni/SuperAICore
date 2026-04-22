<?php

namespace SuperAICore\Services;

use SuperAICore\Contracts\Backend;
use SuperAICore\Contracts\ProviderRepository;
use SuperAICore\Contracts\RoutingRepository;
use SuperAICore\Contracts\StreamingBackend;
use SuperAICore\Support\BackendState;
use Psr\Log\LoggerInterface;

/**
 * Central entry for all LLM calls.
 *
 * Resolution order (for a given prompt + options):
 *   1. explicit ['backend' => 'claude_cli'] override → use named backend + env creds
 *   2. explicit ['provider' => 'anthropic-my-key'] override → use provider's backend
 *   3. task_type + capability → RoutingRepository → ServiceConfig → Backend
 *   4. Active ProviderRepository for scope → Backend from provider's `backend` column
 *   5. Fall back to config('super-ai-core.default_backend')
 */
class Dispatcher
{
    public function __construct(
        protected BackendRegistry $backends,
        protected CostCalculator $costs,
        protected ?UsageTracker $usage = null,
        protected ?ProviderResolver $providers = null,
        protected ?RoutingRepository $routing = null,
        protected ?LoggerInterface $logger = null,
    ) {}

    /**
     * Dispatch a prompt to the appropriate backend.
     *
     * @param  array $options  {
     *   prompt: string, messages?: array, system?: string, max_tokens?: int, model?: string,
     *   backend?: string,        forced backend name
     *   provider_id?: int,       forced provider (overrides routing)
     *   task_type?: string,      for routing lookup
     *   capability?: string,     for routing lookup
     *   scope?: string,          global|user (default global)
     *   scope_id?: int,          user_id when scope=user
     *   user_id?: int,           for usage attribution
     *   stream?: bool,           when true and the resolved backend implements
     *                            StreamingBackend, calls stream() for live tee
     *                            log + Process Monitor row + onChunk callback;
     *                            falls back to generate() when not implemented.
     *   log_file?: string,       (stream only) tee path; auto-named when absent
     *   timeout?: int,           (stream only) hard timeout seconds
     *   idle_timeout?: int,      (stream only) idle timeout seconds
     *   mcp_mode?: 'inherit'|'empty'|'file',  (stream only) backends that
     *                                          load MCP servers honor this
     *   mcp_config_file?: string,             (stream only) when mcp_mode=file
     *   external_label?: string, (stream only) Process Monitor row label
     *   onChunk?: callable,      (stream only) fn(string $chunk, string $stream)
     *   metadata?: array,        attached to ai_usage_logs; (stream) also
     *                            stamped on the Process Monitor row
     * }
     * @return array|null  {text, model, usage, cost_usd, backend, duration_ms}
     *                     plus when stream=true: log_file, exit_code
     */
    public function dispatch(array $options): ?array
    {
        $start = microtime(true);

        // Resolve backend + provider_config
        [$backend, $providerConfig, $providerId, $serviceId] = $this->resolve($options);

        if (!$backend) {
            if ($this->logger) $this->logger->warning('Dispatcher: no backend resolved');
            return null;
        }

        // Engine gate — the /providers page lets an operator turn an engine
        // off; every provider that routes through that engine goes dark.
        if (!BackendState::isDispatcherBackendAllowed($backend->name())) {
            if ($this->logger) $this->logger->warning('Dispatcher: backend "' . $backend->name() . '" is disabled by operator');
            return null;
        }

        $callOptions = $options;
        $callOptions['provider_config'] = array_merge(
            $providerConfig ?? [],
            $options['provider_config'] ?? [],
        );

        // Streaming opt-in. When the caller asks for stream:true and the
        // resolved backend implements StreamingBackend, prefer stream() so
        // the host gets a live tee log + Process Monitor row + onChunk
        // callback. Backends that don't implement the contract fall back
        // to generate() silently — callers see the same envelope shape
        // either way (stream() bolts on log_file + exit_code; generate()
        // just doesn't carry them).
        if (!empty($options['stream']) && $backend instanceof StreamingBackend) {
            $result = $backend->stream($callOptions);
        } else {
            $result = $backend->generate($callOptions);
        }
        $durationMs = (int) round((microtime(true) - $start) * 1000);

        if (!$result) return null;

        // Compute cost. Backend name lets the calculator pick subscription
        // pricing entries (e.g. copilot:claude-sonnet-4-5) and emit $0 for
        // subscription-billed engines so dashboard totals stay correct.
        $modelId = $result['model'] ?? 'unknown';
        $usage = $result['usage'] ?? [];
        $inputTokens  = (int) ($usage['input_tokens']  ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);
        $cacheReadTokens  = (int) ($usage['cache_read_input_tokens']     ?? 0);
        $cacheWriteTokens = (int) ($usage['cache_creation_input_tokens'] ?? 0);

        $billingModel = $this->costs->billingModel($modelId, $backend->name());

        // Prefer the CLI's own `total_cost_usd` when provided — Claude CLI
        // emits this on its result event and it's authoritative because the
        // CLI knows whether the session is on a subscription or an API key
        // (our catalog can't). Falls back to the calculator for backends
        // that don't report a billed cost.
        if (isset($usage['total_cost_usd']) && $usage['total_cost_usd'] !== null) {
            $cost = (float) $usage['total_cost_usd'];
        } else {
            $cost = $this->costs->calculate(
                $modelId, $inputTokens, $outputTokens, $backend->name(),
                $cacheReadTokens, $cacheWriteTokens
            );
        }

        $shadowCost = $billingModel === CostCalculator::BILLING_SUBSCRIPTION
            ? $this->costs->shadowCalculate($modelId, $inputTokens, $outputTokens, $cacheReadTokens, $cacheWriteTokens)
            : $cost;

        $result['cost_usd'] = $cost;
        $result['shadow_cost_usd'] = $shadowCost;
        $result['backend'] = $backend->name();
        $result['billing_model'] = $billingModel;
        $result['duration_ms'] = $durationMs;

        // Record usage
        if ($this->usage) {
            $this->usage->record([
                'backend' => $backend->name(),
                'provider_id' => $providerId,
                'service_id' => $serviceId,
                'model' => $modelId,
                'task_type' => $options['task_type'] ?? null,
                'capability' => $options['capability'] ?? null,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'cost_usd' => $cost,
                'shadow_cost_usd' => $shadowCost,
                'billing_model' => $billingModel,
                'duration_ms' => $durationMs,
                'user_id' => $options['user_id'] ?? null,
                'metadata' => array_filter([
                    'origin_metadata'      => $options['metadata'] ?? null,
                    'cache_read_tokens'    => $cacheReadTokens ?: null,
                    'cache_write_tokens'   => $cacheWriteTokens ?: null,
                    'cost_source'          => isset($usage['total_cost_usd']) ? 'cli_envelope' : 'calculator',
                ]) ?: null,
            ]);
        }

        return $result;
    }

    /**
     * Resolve [Backend, providerConfig, providerId, serviceId] for the given options.
     *
     * @return array{0: ?Backend, 1: array, 2: ?int, 3: ?int}
     */
    protected function resolve(array $options): array
    {
        // 1. Explicit backend override
        if (!empty($options['backend'])) {
            $backend = $this->backends->get($options['backend']);
            return [$backend, [], null, null];
        }

        // 2. Explicit provider_id override
        if (!empty($options['provider_id']) && $this->providers) {
            $provider = $this->providers->findById($options['provider_id']);
            if ($provider) {
                return [
                    $this->backends->get($this->backendForProvider($provider)),
                    $provider,
                    $provider['id'],
                    null,
                ];
            }
        }

        // 3. Routing lookup by task_type + capability
        if (!empty($options['task_type']) && !empty($options['capability']) && $this->routing) {
            $service = $this->routing->resolve($options['task_type'], $options['capability']);
            if ($service) {
                $providerConfig = [
                    'api_key' => $service['api_key'] ?? null,
                    'base_url' => $service['base_url'] ?? null,
                    'model' => $service['model'] ?? null,
                ];
                $backendName = $service['backend'] ?? $this->inferBackendFromProtocol($service['protocol'] ?? '');
                return [$this->backends->get($backendName), $providerConfig, null, $service['id'] ?? null];
            }
        }

        // 4. Active provider for scope
        if ($this->providers) {
            $scope = $options['scope'] ?? 'global';
            $scopeId = $options['scope_id'] ?? null;
            $provider = $this->providers->findActive($scope, $scopeId);
            if ($provider) {
                return [
                    $this->backends->get($this->backendForProvider($provider)),
                    $provider,
                    $provider['id'],
                    null,
                ];
            }
        }

        // 5. Default backend from config + env credentials
        $defaultBackend = function_exists('config')
            ? config('super-ai-core.default_backend', 'anthropic_api')
            : 'anthropic_api';
        return [$this->backends->get($defaultBackend), [], null, null];
    }

    protected function backendForProvider(array $provider): string
    {
        $engine = $provider['backend'] ?? null;
        $type = $provider['type'] ?? '';

        // builtin → always the engine's CLI adapter
        if ($type === 'builtin') {
            return match ($engine) {
                'claude' => 'claude_cli',
                'codex' => 'codex_cli',
                'gemini' => 'gemini_cli',
                default => 'claude_cli',
            };
        }

        // External credentials → HTTP adapter for that engine
        if ($engine === 'gemini' || in_array($type, ['google-ai'], true)) {
            // Vertex AI on the Gemini engine currently routes through the CLI,
            // which handles ADC token exchange; google-ai goes direct HTTP.
            return $type === 'vertex' ? 'gemini_cli' : 'gemini_api';
        }
        if ($engine === 'superagent') {
            return 'superagent';
        }

        // Claude / Codex engines: pick API or CLI based on type
        return match ($type) {
            'anthropic', 'anthropic-proxy', 'bedrock', 'vertex' => 'anthropic_api',
            'openai', 'openai-compatible' => 'openai_api',
            default => $engine === 'codex' ? 'openai_api' : 'anthropic_api',
        };
    }

    protected function inferBackendFromProtocol(string $protocol): string
    {
        return match ($protocol) {
            'anthropic' => 'anthropic_api',
            'openai' => 'openai_api',
            'gemini', 'google-ai' => 'gemini_api',
            'superagent' => 'superagent',
            default => 'anthropic_api',
        };
    }
}
