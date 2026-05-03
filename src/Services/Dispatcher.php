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

        // Compute the idempotency key BEFORE dispatch so backends that
        // forward it to the SDK (SuperAgentBackend → Agent::run options
        // → AgentResult::$idempotencyKey on 0.9.1+) observe the same key
        // that UsageRecorder later writes. Pre-computation is free — the
        // same inputs flow into resolveIdempotencyKey().
        $idempotencyKey = $this->resolveIdempotencyKey($options, $backend->name());
        if ($idempotencyKey !== null) {
            $callOptions['idempotency_key'] = $idempotencyKey;
        }

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

        // Record usage. Surfacing the inserted row id on the result lets
        // downstream callers (notably TaskRunner) attach the id to their
        // own envelope without re-querying — handy for "patch this row
        // with extra metadata once Phase C consolidation finishes" flows
        // and for skipping double-record on hosts that still call
        // UsageRecorder themselves.
        //
        // Phase D: also auto-generate an idempotency_key when caller
        // didn't supply one. The key is derived from `external_label`
        // (typically `task:42` or `ppt:job:7:strategist` — stable
        // across the duplicate dispatches that come from a host's
        // accidental double-record). Hosts that want stronger
        // guarantees pass `idempotency_key` explicitly. Hosts that
        // want NO dedup (rare — every call legitimately distinct) can
        // pass `idempotency_key => false` to skip auto-gen.
        // 0.9.0 — Anthropic prompt cache TTL is 5 minutes; if a follow-up
        // call to the same session arrives after the window closes, the
        // user pays the full input price for the entire prefix again. We
        // can't prevent the miss, but we can flag it so the dashboard
        // surfaces an "unexpected cold cache" badge — borrowed in spirit
        // from jcode, which warns interactively in its TUI when the cache
        // ages past the threshold. Silently skipped when the call carries
        // no `session_id` or runs through a non-Anthropic backend.
        // Computed BEFORE the usage write (0.9.1) so the warning lands on
        // the row metadata, which lets /processes + /usage render badges
        // without re-deriving the heuristic.
        $coldWarning = $this->detectCacheCold($options, $backend->name(), $cacheReadTokens);
        if ($coldWarning !== null) {
            $result['cache_warning'] = $coldWarning;
        }

        if ($this->usage) {
            // Prefer the key the backend echoed off `AgentResult::$idempotencyKey`
            // (SDK 0.9.1+ round-trip) — it's authoritative because the SDK
            // is where the dedup observation actually happened. Fall back
            // to the pre-computed key from $callOptions, which matches
            // for deterministic derivation paths (the common case).
            $idempotencyKey = null;
            if (isset($result['idempotency_key']) && is_string($result['idempotency_key']) && $result['idempotency_key'] !== '') {
                $idempotencyKey = $result['idempotency_key'];
            } elseif (isset($callOptions['idempotency_key']) && is_string($callOptions['idempotency_key'])) {
                $idempotencyKey = $callOptions['idempotency_key'];
            }

            // SDK 0.9.6+ — record reasoning channel size and deprecation
            // notice on the usage row metadata so dashboards can render
            // a "thinking" badge / "X days until <model> retires" banner
            // without re-querying the SDK. `thinking_chars` is a cheap
            // proxy for reasoning depth; full text stays on the envelope.
            $thinkingChars = isset($result['thinking']) && is_string($result['thinking'])
                ? mb_strlen($result['thinking'])
                : 0;
            $deprecationMeta = isset($result['deprecation']) && is_array($result['deprecation'])
                ? $result['deprecation']
                : null;
            // Optional rate-limit envelope (SDK populates when the upstream
            // provider response carried `x-ratelimit-*` headers). Passive —
            // omitted when the SDK didn't surface it, so legacy tests
            // comparing exact metadata shapes stay green.
            $rateLimitMeta = isset($result['rate_limit']) && is_array($result['rate_limit'])
                ? $result['rate_limit']
                : null;

            // 0.9.7 — pull `usage_source` to the top-level metadata so
            // `/usage` can group on it without JSON path nesting. Default
            // 'user' keeps existing rows queryable as one bucket;
            // SuperAgent's `AmbientWorker` tags background dedup/staleness
            // ticks with 'ambient', and hosts can introduce other sources
            // (e.g. 'eval' for offline benchmarks).
            $usageSource = $this->resolveUsageSource($options);

            $usageLogId = $this->usage->record([
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
                    'thinking_chars'       => $thinkingChars ?: null,
                    // array_filter's default callback drops null + false +
                    // 0 + '' but KEEPS [] — explicitly null these out so
                    // empty SDK envelopes don't leak `deprecation: []` /
                    // `rate_limit: []` rows into the dashboard.
                    'deprecation'          => $deprecationMeta ?: null,
                    'rate_limit'           => $rateLimitMeta ?: null,
                    'cache_warning'        => $coldWarning,
                    'usage_source'         => $usageSource,
                ]) ?: null,
                'idempotency_key' => $idempotencyKey,
            ]);
            if ($usageLogId !== null) {
                $result['usage_log_id'] = $usageLogId;
            }
        }

        return $result;
    }

    /**
     * Detect a likely-cold Anthropic prompt cache. Returns null when the
     * heuristic doesn't apply (non-Anthropic backend, no session_id,
     * usage repository unreachable, this call already consumed cache
     * reads), 'cache_likely_cold' when the previous same-session call
     * was longer ago than the configured threshold (default 270s — leaves
     * 30s headroom under Anthropic's 5-minute TTL).
     *
     * Cheap: one indexed query against ai_usage_logs.metadata->'session_id'
     * filtered by created_at >= now-threshold. Hosts that don't surface a
     * session_id pay the cost of the field-presence guard only.
     */
    protected function detectCacheCold(array $options, string $backendName, int $cacheReadTokens): ?string
    {
        // The 5-minute TTL only applies to Anthropic's family. Other
        // providers either have no prompt cache (Gemini, OpenAI Chat) or
        // a server-driven one that doesn't time out the same way (OpenAI
        // Responses with prompt_cache_key, DeepSeek auto-cache).
        $anthropicBackends = ['anthropic_api', 'claude_cli', 'superagent'];
        if (!in_array($backendName, $anthropicBackends, true)) return null;

        // Cache reads on this call → cache was warm; nothing to warn about.
        if ($cacheReadTokens > 0) return null;

        $sessionId = $options['metadata']['session_id'] ?? $options['session_id'] ?? null;
        if (!is_string($sessionId) || $sessionId === '') return null;

        $thresholdSeconds = (int) (function_exists('config')
            ? (config('super-ai-core.cache_cold_warning.threshold_seconds') ?? 270)
            : 270);
        if ($thresholdSeconds <= 0) return null;

        // Defer to the usage repository if the host registered one — it's
        // the only seam that knows the table prefix + driver. Falls back
        // to null when the repo doesn't expose the optional finder, which
        // keeps backward compatibility with older repository implementations.
        if ($this->usage === null || !method_exists($this->usage, 'findLatestForSession')) {
            return null;
        }
        try {
            $previous = $this->usage->findLatestForSession($sessionId, $anthropicBackends);
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->debug('Dispatcher: cache-cold lookup failed: ' . $e->getMessage());
            }
            return null;
        }
        if (!is_array($previous) || !isset($previous['created_at'])) return null;

        $previousAt = strtotime((string) $previous['created_at']);
        if ($previousAt === false) return null;
        $gapSeconds = time() - $previousAt;
        if ($gapSeconds <= $thresholdSeconds) return null;

        return 'cache_likely_cold';
    }

    /**
     * Resolve the usage source for this dispatch. Precedence:
     *
     *   1. `options['usage_source']`              — explicit override
     *   2. `options['metadata']['usage_source']`  — Dispatcher-canonical
     *      (matches the shape SuperAgent's `AmbientWorker` tags via its
     *      `tagUsage` callback — `usage_source: 'ambient'`)
     *   3. 'user'                                  — sensible default so
     *      every row is bucketable on `/usage`
     *
     * Constrained to a small allowlist so a typo doesn't leak into the
     * dashboard as a phantom source bucket.
     *
     * @param  array<string,mixed> $options
     */
    protected function resolveUsageSource(array $options): string
    {
        $candidates = [
            $options['usage_source']             ?? null,
            $options['metadata']['usage_source'] ?? null,
        ];
        foreach ($candidates as $c) {
            if (is_string($c) && $c !== '') {
                $clean = mb_strtolower(preg_replace('/[^a-z0-9_-]+/i', '', $c) ?? '');
                if ($clean === '') continue;
                return mb_substr($clean, 0, 32);
            }
        }
        return 'user';
    }

    /**
     * Pick or build an idempotency key for this dispatch.
     *
     * Precedence:
     *   1. Explicit `options['idempotency_key']` — caller knows best
     *      (e.g. their internal job id). `false` opts out of auto-gen.
     *   2. Auto-derived from `external_label` when present:
     *      `{backend}:{external_label}` — stable across the duplicate
     *      dispatches that come from a host's accidental double-record,
     *      distinct across legitimately separate runs (each task has
     *      its own external_label).
     *   3. Otherwise null — no dedup, every record() inserts a row.
     */
    protected function resolveIdempotencyKey(array $options, string $backendName): ?string
    {
        if (array_key_exists('idempotency_key', $options)) {
            $explicit = $options['idempotency_key'];
            if ($explicit === false) return null;          // opt-out
            if ($explicit === null || $explicit === '') return null;
            return mb_substr((string) $explicit, 0, 80);
        }

        $label = $options['external_label'] ?? null;
        if ($label === null || $label === '') return null;
        return mb_substr($backendName . ':' . $label, 0, 80);
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
