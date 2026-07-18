<?php

namespace SuperAICore\Services;

use SuperAICore\Contracts\Backend;
use SuperAICore\Contracts\ProviderRepository;
use SuperAICore\Contracts\RoutingRepository;
use SuperAICore\Contracts\StreamingBackend;
use SuperAICore\Support\BackendState;
use SuperAICore\Support\FailureClassifier;
use SuperAICore\Arrow\ArrowSerializer;
use SuperAICore\Tracing\TraceCollector;
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
        protected ?TraceCollector $tracer = null,
    ) {}

    /**
     * Lazy resolver for the dispatcher-wide trace collector.
     *
     * Hosts that wire a TraceCollector via constructor get exactly that
     * instance; hosts that don't (the common case) fall back to the
     * process-global singleton bootstrapped by SuperAICoreServiceProvider.
     */
    protected function tracer(): TraceCollector
    {
        if ($this->tracer === null) {
            $this->tracer = TraceCollector::getInstance();
        }
        return $this->tracer;
    }

    /**
     * Pull a stable trace `tid` (lane label) off the dispatch options.
     * Prefers explicit session_id; falls back to external_label or 'default'.
     */
    protected function traceTid(array $options): string
    {
        $sessionId = $options['metadata']['session_id']
            ?? $options['session_id']
            ?? $options['external_label']
            ?? null;
        if (is_string($sessionId) && $sessionId !== '') {
            return 'session:' . $sessionId;
        }
        return 'session:default';
    }

    /**
     * Check whether an auto-dump for the given trigger is enabled. The
     * trigger-level config keys live under `super-ai-core.tracing.dump_on.*`;
     * absent / non-Laravel hosts default to "on" so the operator still gets
     * a crash dump even when nobody published the config.
     */
    protected function shouldDumpOn(string $trigger): bool
    {
        $val = \SuperAICore\Support\ConfigValue::get('super-ai-core.tracing.dump_on.' . $trigger);
        if ($val === null) return true;
        return (bool) $val;
    }

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
        $startMicros = (int) ($start * 1_000_000);
        $tracer = $this->tracer();
        $tid = $this->traceTid($options);

        // Resolve backend + provider_config
        [$backend, $providerConfig, $providerId, $serviceId] = $this->resolve($options);

        if (!$backend) {
            if ($this->logger) $this->logger->warning('Dispatcher: no backend resolved');
            $tracer->emitInstant(
                name: 'dispatcher.no_backend',
                category: 'error',
                tid: $tid,
                args: [
                    'task_type'  => $options['task_type']  ?? null,
                    'capability' => $options['capability'] ?? null,
                ],
            );
            if ($this->shouldDumpOn('error')) {
                $tracer->dump('error', 'no backend resolved');
            }
            return null;
        }

        // Engine gate — the /providers page lets an operator turn an engine
        // off; every provider that routes through that engine goes dark.
        if (!BackendState::isDispatcherBackendAllowed($backend->name())) {
            if ($this->logger) $this->logger->warning('Dispatcher: backend "' . $backend->name() . '" is disabled by operator');
            $tracer->emitInstant(
                name: 'provider.disabled',
                category: 'provider',
                tid: $tid,
                args: ['backend' => $backend->name()],
            );
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

        if (!$result) {
            $tracer->emitDuration(
                name: 'llm.dispatch',
                category: 'llm',
                tid: $tid,
                startMicros: $startMicros,
                durationMicros: $durationMs * 1000,
                args: [
                    'backend' => $backend->name(),
                    'model'   => $options['model'] ?? null,
                    'status'  => 'null_result',
                ],
            );
            // 9Router-borrowed: when round-robin picked an account but
            // the backend failed, cool down THIS account so the next
            // dispatch picks a different one. Backend-level errors that
            // look like quota / rate-limit fall into this bucket — even
            // without a specific exception type we get fast rotation.
            $this->cooldownActiveAccount(
                $callOptions['provider_config'] ?? [],
                reason: 'backend_returned_null',
            );
            if ($this->shouldDumpOn('error')) {
                $tracer->dump('error', 'backend returned null', [
                    'backend' => $backend->name(),
                    'model'   => $options['model'] ?? null,
                ]);
            }
            return null;
        }

        // Quota / rate-limit cooldown on a NON-NULL failure envelope. The
        // non-streaming path returns null on a CLI failure (cooled down
        // above), but the streaming path returns a populated envelope with a
        // non-zero exit_code even when the run died on "usage limit reached".
        // Policy: a quota failure means this account is out of quota — cool it
        // down so the next dispatch rotates off it, matching the null path.
        // Positive-only (never cools on a generic non-zero exit), so a bad
        // prompt or a tool error doesn't burn an account.
        $cooldownReason = $this->quotaCooldownReason($result);
        if ($cooldownReason !== null) {
            $this->cooldownActiveAccount(
                $callOptions['provider_config'] ?? [],
                reason: $cooldownReason,
            );
        }

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
            $tracer->emitInstant(
                name: 'llm.cache_cold',
                category: 'llm',
                tid: $tid,
                args: [
                    'backend' => $backend->name(),
                    'model'   => $modelId,
                    'warning' => $coldWarning,
                ],
            );
        }

        // Successful LLM call — emit a duration event covering the whole
        // dispatch envelope (backend call + cost calc + cache heuristic).
        $tracer->emitDuration(
            name: 'llm.dispatch',
            category: 'llm',
            tid: $tid,
            startMicros: $startMicros,
            durationMicros: $durationMs * 1000,
            args: [
                'backend'             => $backend->name(),
                'model'               => $modelId,
                'input_tokens'        => $inputTokens,
                'output_tokens'       => $outputTokens,
                'cache_read_tokens'   => $cacheReadTokens,
                'cache_write_tokens'  => $cacheWriteTokens,
                'cost_usd'            => $cost,
                'shadow_cost_usd'     => $shadowCost,
                'billing_model'       => $billingModel,
                'provider_id'         => $providerId,
                'service_id'          => $serviceId,
            ],
        );

        // Optional Arrow payload conversion — Wave 3 / AC-5.
        // When the caller passes `output_format: 'arrow'` AND `tabular`
        // (or the result happens to carry a `rows` field already), the
        // result envelope gets a base64-encoded Arrow IPC stream the next
        // consumer can hand directly to Perspective / pyarrow.
        //
        // Off by default. Adds zero overhead when not requested.
        if (($options['output_format'] ?? null) === 'arrow') {
            $rows = $options['tabular'] ?? $result['rows'] ?? null;
            if (is_array($rows)) {
                try {
                    $cli = ArrowSerializer::detectExternalCli();
                    $bytes = $cli !== null
                        ? ArrowSerializer::fromRowsViaCli($rows, $cli)
                        : ArrowSerializer::fromRows($rows);
                    $result['arrow'] = base64_encode($bytes);
                    $result['arrow_row_count'] = count($rows);
                    $result['arrow_via'] = $cli !== null ? 'cli' : 'inline';
                } catch (\Throwable $arrowErr) {
                    // Never block dispatch on Arrow serialization failure —
                    // log and continue with the standard envelope. Caller
                    // can detect absence of `result['arrow']` to fall back.
                    if ($this->logger) {
                        $this->logger->warning('Dispatcher: arrow serialization failed: ' . $arrowErr->getMessage());
                    }
                }
            }
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
                    // Cross-mode breadcrumb (v1.0.1+). Carries the
                    // mode_stack the result traversed (e.g. ['squad',
                    // 'smart', 'auto']) so /processes + /usage can
                    // render a "squad → smart → auto" badge. Pulled
                    // off the dispatch envelope when present, falls
                    // back to options metadata for caller-supplied
                    // overrides.
                    'mode_stack'           => $options['metadata']['mode_stack']
                                                 ?? ($result['mode_stack'] ?? null),
                    'has_cross_mode'       => $options['metadata']['has_cross_mode']
                                                 ?? ($result['has_cross_mode'] ?? null),
                ]) ?: null,
                'idempotency_key' => $idempotencyKey,
                // P0-1 — shadow-git snapshots + per-file diff envelope. All
                // three are nullable; backends that don't run through
                // SuperAgentBackend / GitShadowStore (CLI engines without
                // PHP-side checkpointing) leave them null.
                'pre_snapshot'      => isset($result['pre_snapshot']) && is_string($result['pre_snapshot'])
                                         ? substr($result['pre_snapshot'], 0, 64) : null,
                'post_snapshot'     => isset($result['post_snapshot']) && is_string($result['post_snapshot'])
                                         ? substr($result['post_snapshot'], 0, 64) : null,
                'file_diff_summary' => isset($result['file_diff_summary']) && is_array($result['file_diff_summary'])
                                         ? $result['file_diff_summary'] : null,
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

        $thresholdSeconds = (int) (\SuperAICore\Support\ConfigValue::get('super-ai-core.cache_cold_warning.threshold_seconds') ?? 270);
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
    /**
     * Resolve a dispatch options array to the concrete backend + provider
     * config + provider/service ids. Public so the OpenAI-compat proxy
     * and any other host that needs to make routing decisions without
     * actually executing a dispatch can re-use the same precedence chain.
     *
     * Returns [Backend|null, providerConfig, providerId|null, serviceId|null].
     */
    public function resolve(array $options): array
    {
        return $this->resolveInternal($options);
    }

    protected function resolveInternal(array $options): array
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
                $providerConfig = $this->applyAccountRoundRobin($provider);
                return [
                    $this->backends->get($this->backendForProvider($provider)),
                    $providerConfig,
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
                $providerConfig = $this->applyAccountRoundRobin($provider);
                return [
                    $this->backends->get($this->backendForProvider($provider)),
                    $providerConfig,
                    $provider['id'],
                    null,
                ];
            }
        }

        // 5. Default backend from config + env credentials
        $defaultBackend = \SuperAICore\Support\ConfigValue::get('super-ai-core.default_backend', 'anthropic_api');
        return [$this->backends->get($defaultBackend), [], null, null];
    }

    /**
     * 9Router-borrowed multi-account round-robin. When the provider has
     * one or more rows in ai_provider_accounts, pick the next eligible
     * account via AccountRoundRobin and merge its credentials onto the
     * provider config so the backend sees the right keys.
     *
     * Falls back silently to the provider's own credentials when:
     *   - the migration hasn't been run yet
     *   - no active accounts exist
     *   - all accounts are in cooldown
     *
     * @param array<string,mixed> $provider The provider row from ProviderRepository
     * @return array<string,mixed>          Provider config with auth merged
     */
    protected function applyAccountRoundRobin(array $provider): array
    {
        if (empty($provider['id']) || !class_exists(\SuperAICore\Models\AiProviderAccount::class)) {
            return $provider;
        }
        try {
            $picker = function_exists('app')
                ? app(\SuperAICore\Services\AccountRoundRobin::class)
                : new \SuperAICore\Services\AccountRoundRobin();
            $account = $picker->pick((int) $provider['id']);
            if ($account === null) return $provider;
            return $picker->applyToConfig($account, $provider);
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->debug('Account round-robin skipped: ' . $e->getMessage());
            }
            return $provider;
        }
    }

    /**
     * Cooldown the in-use account when the backend reports a quota / rate
     * limit error. Called from dispatch() error-handling paths. Wrapped
     * in try/catch so a missing migration doesn't cascade into a dispatch
     * failure on top of the original error.
     */
    /**
     * Classify a NON-NULL result envelope as a quota / rate-limit failure
     * that should cool down the active account. Returns the cooldown reason
     * (`quota_exceeded` | `rate_limited`) or null when the envelope is a
     * success or an unrelated failure.
     *
     * Only the streaming path reaches dispatch() with a populated failure
     * envelope (generate() returns null on failure, handled earlier), so a
     * successful turn — which carries a zero/absent `exit_code` — is ignored
     * cheaply. Classification is positive-only via `FailureClassifier`: we
     * cool down solely when the failure text (envelope fields + the tee'd log
     * tail) names a quota / rate-limit condition, never on a bare non-zero
     * exit, so a validation error or tool failure never burns an account.
     *
     * @param array<string,mixed> $result
     */
    protected function quotaCooldownReason(array $result): ?string
    {
        $exit = $result['exit_code'] ?? null;
        if ($exit === null || (int) $exit === 0) return null;

        $parts = [];
        foreach (['error', 'text', 'stop_reason'] as $k) {
            if (isset($result[$k]) && is_string($result[$k]) && $result[$k] !== '') {
                $parts[] = $result[$k];
            }
        }
        $log = $result['log_file'] ?? null;
        if (is_string($log) && $log !== '' && is_file($log)) {
            $tail = $this->tailFile($log, 8192);
            if ($tail !== '') $parts[] = $tail;
        }
        $haystack = implode("\n", $parts);
        if (trim($haystack) === '') return null;

        return match (FailureClassifier::classify($haystack)['class'] ?? null) {
            'quota'      => 'quota_exceeded',
            'rate_limit' => 'rate_limited',
            default      => null,
        };
    }

    /** Read the last $maxBytes of a file (for failure classification). */
    private function tailFile(string $path, int $maxBytes): string
    {
        $size = @filesize($path);
        if ($size === false) return '';
        $fh = @fopen($path, 'rb');
        if ($fh === false) return '';
        try {
            if ($size > $maxBytes) {
                @fseek($fh, -$maxBytes, SEEK_END);
            }
            $data = @stream_get_contents($fh);
            return is_string($data) ? $data : '';
        } finally {
            @fclose($fh);
        }
    }

    protected function cooldownActiveAccount(array $providerConfig, string $reason = 'quota_exceeded'): void
    {
        $accountId = $providerConfig['_account_id'] ?? null;
        if (!$accountId || !class_exists(\SuperAICore\Models\AiProviderAccount::class)) return;

        try {
            $picker = function_exists('app')
                ? app(\SuperAICore\Services\AccountRoundRobin::class)
                : new \SuperAICore\Services\AccountRoundRobin();
            $picker->cooldown((int) $accountId, $reason);
        } catch (\Throwable) {
            // Cooldown is best-effort; never fail dispatch on it.
        }
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
