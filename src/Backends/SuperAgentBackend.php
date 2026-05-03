<?php

namespace SuperAICore\Backends;

use Psr\Log\LoggerInterface;
use SuperAgent\Agent;
use SuperAgent\Exceptions\Provider\ContextWindowExceededException;
use SuperAgent\Exceptions\Provider\CyberPolicyException;
use SuperAgent\Exceptions\Provider\InvalidPromptException;
use SuperAgent\Exceptions\Provider\QuotaExceededException;
use SuperAgent\Exceptions\Provider\ServerOverloadedException;
use SuperAgent\Exceptions\Provider\UsageNotIncludedException;
use SuperAgent\Exceptions\ProviderException;
use SuperAgent\MCP\MCPManager;
use SuperAgent\Memory\Embeddings\EmbeddingProvider;
use SuperAgent\Providers\ProviderRegistry;
use SuperAICore\Contracts\Backend;
use SuperAICore\Services\BrowserScreenshotStore;
use SuperAICore\Services\EmbeddingProviderFactory;
use SuperAICore\Services\ProviderTypeRegistry;

/**
 * In-process LLM + tool-use loop via forgeomni/superagent.
 *
 * Defaults preserve the pre-0.8.8 one-shot behaviour (max_turns=1, no tool
 * auto-load, no MCP). Callers opt into the richer surface explicitly:
 *
 *   - max_turns: int           run a real agentic loop (default 1)
 *   - max_cost_usd: float      hard budget cap enforced by the Agent engine
 *   - load_tools: bool|array   pass-through to SDK's tool-loader (default false)
 *   - allowed_tools: string[]  filter tool surface
 *   - denied_tools: string[]   filter tool surface
 *   - mcp_config_file: string  path to a `.mcp.json` to load + auto-connect
 *                              (same shape claude:mcp-sync writes)
 *   - provider_config.region   intl/cn/us/hk/code split for providers that
 *                              support it (Kimi/Qwen/GLM/MiniMax); folded
 *                              into the host-config shape and resolved by
 *                              the per-provider adapter inside the SDK
 *                              (0.9.2 `ProviderRegistry::createForHost`).
 *                              Note: `region: 'code'` on Kimi/Qwen routes
 *                              through OAuth bearer (KimiCodeCredentials /
 *                              QwenCodeCredentials) before falling back
 *                              to `api_key`.
 *
 * SDK 0.9.0 forwarded options (all optional, all additive):
 *
 *   - extra_body: array              vendor-specific fields deep-merged at
 *                                    the top level of every
 *                                    ChatCompletionsProvider request body
 *                                    (Kimi / Qwen / GLM / MiniMax / OpenAI
 *                                    / OpenRouter). Escape hatch for wire
 *                                    fields SuperAgent has not yet exposed
 *                                    as capability adapters.
 *   - features: array                routed through FeatureDispatcher.
 *                                    Useful keys:
 *                                      - prompt_cache_key.session_id — Kimi
 *                                        session-level prompt cache (silent
 *                                        skip on non-Kimi providers)
 *                                      - thinking.* — CoT dispatch with
 *                                        graceful fallback
 *                                      - dashscope_cache_control — Qwen
 *                                        Anthropic-style cache markers
 *   - loop_detection: bool|array     opt into 0.9.0 LoopDetector wrapping
 *                                    via AgentFactory::maybeWrapWithLoopDetection.
 *                                    `true` uses defaults; pass an array to
 *                                    override thresholds (TOOL_LOOP /
 *                                    STAGNATION / FILE_READ_LOOP /
 *                                    CONTENT_LOOP / THOUGHT_LOOP).
 *
 * SDK 0.9.7 surface (jcode-style companion-tools wave):
 *
 *   - embedding_provider: EmbeddingProvider
 *                                    Pre-built SDK 0.9.7
 *                                    `Memory\Embeddings\EmbeddingProvider`
 *                                    instance. When omitted, the backend
 *                                    pulls the container singleton built
 *                                    by `EmbeddingProviderFactory` from
 *                                    `super-ai-core.embeddings.*` config
 *                                    so SuperAICore's `SemanticSkillReranker`
 *                                    and any host-side `SemanticSkillRouter`
 *                                    share one embedder + one cache. The
 *                                    instance is stuffed into Agent's
 *                                    forwarded `options` bag (under the
 *                                    same key) so future SDK consumers can
 *                                    pick it up via `Agent::getOptions()`
 *                                    without per-call wiring.
 *   - process_id: string             Used to key `BrowserScreenshotStore`
 *                                    when a `browser` tool emits a base64
 *                                    PNG. Falls back to
 *                                    `metadata.session_id` then
 *                                    `external_label` when omitted.
 *
 * Tool injection (config-driven, opt-in):
 *
 *   - `super-ai-core.tools.agent_grep_enabled`  → adds 'agent_grep' to
 *     `load_tools` when the caller didn't pass an explicit list. The tool
 *     ships in the SDK's BuiltinToolRegistry classMap (lazy-loaded by
 *     ToolLoader); flipping the flag is a no-op unless the caller actually
 *     drives the agentic loop with tools.
 *   - `super-ai-core.tools.browser_enabled`     → instantiates SDK 0.9.7
 *     `FirefoxBridgeTool` (`browser`) and registers it via
 *     `Agent::addTool()`. Not in the BuiltinToolRegistry classMap, so this
 *     is the only way to surface it from `load_tools`. Requires
 *     `SUPERAGENT_BROWSER_BRIDGE_PATH` env or `launcherArgv` to actually
 *     run a browser; without that, every action returns an explanatory
 *     error so the agent learns to ask for setup help instead of looping.
 *
 * Envelope additions (0.9.7):
 *   - latest_screenshot_url: string  When the `browser` tool emitted a
 *                                    base64 PNG during the run,
 *                                    `BrowserScreenshotStore` persists
 *                                    the latest frame and the URL is
 *                                    surfaced here so the Process Monitor
 *                                    row's `latest_screenshot_url` and
 *                                    `/usage` row metadata can render it
 *                                    inline. Key omitted when no browser
 *                                    activity occurred — envelope shape
 *                                    stays byte-identical for callers
 *                                    that don't drive a browser.
 *
 * Envelope additions on success:
 *   - usage.cache_read_input_tokens, usage.cache_creation_input_tokens
 *   - cost_usd:   SDK's own turn-summed cost. Dispatcher prefers this
 *                 over its own CostCalculator when non-zero.
 *   - turns:      number of assistant turns actually consumed.
 *   - thinking:   reasoning channel content concatenated across the
 *                 conversation (SDK 0.9.6+). Present only when the
 *                 backend actually emitted reasoning text — Anthropic
 *                 native thinking, OpenAI-compat `delta.reasoning_content`
 *                 (DeepSeek V4-thinking, Kimi-thinking, Qwen-reasoning,
 *                 GLM-thinking, OpenAI o-series). Omitted otherwise so
 *                 the envelope shape stays byte-identical for non-thinking
 *                 conversations.
 *   - deprecation: { model, deprecated_until, replaced_by, days_left }
 *                 (SDK 0.9.6+). Present when the resolved model has a
 *                 retirement date in `ModelCatalog`. Dispatcher writes
 *                 this onto the usage row metadata so dashboards can
 *                 surface "you have N days to migrate to <replaced_by>".
 */
class SuperAgentBackend implements Backend
{
    public function __construct(
        protected ?LoggerInterface $logger = null,
        protected ?EmbeddingProviderFactory $embeddingFactory = null,
        protected ?BrowserScreenshotStore $screenshotStore = null,
    ) {}

    public function name(): string
    {
        return 'superagent';
    }

    public function isAvailable(array $providerConfig = []): bool
    {
        return class_exists(Agent::class);
    }

    public function generate(array $options): ?array
    {
        if (!$this->isAvailable()) return null;

        $providerConfig = $options['provider_config'] ?? [];
        $mcpManager = null;

        try {
            $agent = $this->buildAgent($options, $providerConfig);
            $mcpManager = $this->attachMcpTools($agent, $options);

            if (!empty($options['system'])) {
                $agent->withSystemPrompt((string) $options['system']);
            }

            // SDK 0.9.1+ per-call options. The SDK merges these into the
            // agent's stored options before dispatch (pre-0.9.1 silently
            // dropped them on the non-auto path), which lets hosts:
            //   - carry an `idempotency_key` through to `AgentResult` for
            //     dedup on write (UsageRecorder reads it back off the result
            //     so the round-trip stays consistent even when the Dispatcher
            //     runs on a different PHP process than the write-through).
            //   - propagate a W3C `traceparent` / `tracestate` onto OpenAI
            //     Responses-API logs via `client_metadata`, correlating
            //     host-side traces with provider-side logs.
            $perCallOptions = $this->buildPerCallOptions($options);
            $result = $agent->run((string) ($options['prompt'] ?? ''), $perCallOptions);

            $text = $result->text();
            if ($text === '') return null;

            $usage = method_exists($result, 'totalUsage') ? $result->totalUsage() : null;
            $model = $options['model'] ?? $providerConfig['model'] ?? 'unknown';

            $envelope = [
                'text'  => $text,
                'model' => $model,
                'usage' => [
                    'input_tokens'                => $usage?->inputTokens            ?? 0,
                    'output_tokens'               => $usage?->outputTokens           ?? 0,
                    'cache_read_input_tokens'     => $usage?->cacheReadInputTokens   ?? 0,
                    'cache_creation_input_tokens' => $usage?->cacheCreationInputTokens ?? 0,
                ],
                'cost_usd'    => (float) ($result->totalCostUsd ?? 0.0),
                'turns'       => method_exists($result, 'turns') ? $result->turns() : 1,
                'stop_reason' => null,
            ];

            // 0.9.1: echo the idempotency key back off AgentResult so the
            // Dispatcher's usage write binds the same key the SDK observed.
            if (property_exists($result, 'idempotencyKey') && $result->idempotencyKey !== null) {
                $envelope['idempotency_key'] = $result->idempotencyKey;
            }

            // SDK 0.8.9+ AgentTool productivity (only emitted when the caller
            // opted into `load_tools: ['agent']` or similar — AgentTool isn't
            // in the default set). Key is omitted when no sub-agent ran, so
            // existing envelope shape stays byte-exact for callers that don't
            // dispatch sub-agents through the SDK path.
            $subagents = $this->extractSubagentProductivity($result->messages ?? []);
            if ($subagents !== []) {
                $envelope['subagents'] = $subagents;
            }

            // SDK 0.9.6+ reasoning channel — `delta.reasoning_content` and
            // Anthropic native `thinking` blocks both surface as
            // ContentBlock(type='thinking') prepended to the assistant
            // turn. Concatenate across the message history so callers can
            // render or hide the agent's internal monologue deliberately.
            // Key omitted when no reasoning text appeared — envelope shape
            // stays unchanged for non-thinking conversations.
            $thinking = $this->extractThinking($result->messages ?? []);
            if ($thinking !== '') {
                $envelope['thinking'] = $thinking;
            }

            // SDK 0.9.6+ deprecation surfacing — `ModelCatalog::deprecation()`
            // returns retirement metadata for models the catalog flagged.
            // The SDK separately writes a one-shot `error_log` warning
            // (silenced by `SUPERAGENT_SUPPRESS_DEPRECATION=1`); we read
            // the same source so the dashboard shows the same notice.
            $deprecation = $this->resolveDeprecation($model);
            if ($deprecation !== null) {
                $envelope['deprecation'] = $deprecation;
            }

            // SDK 0.9.7 — when the agent invoked the `browser` tool
            // (FirefoxBridgeTool) and a screenshot came back, persist the
            // latest frame in BrowserScreenshotStore keyed by the dispatch
            // process_id and surface the URL so /processes can render it.
            // Key omitted when no browser activity occurred.
            $screenshotUrl = $this->persistLatestScreenshot($result->messages ?? [], $options);
            if ($screenshotUrl !== null) {
                $envelope['latest_screenshot_url'] = $screenshotUrl;
            }

            return $envelope;
        } catch (ContextWindowExceededException $e) {
            $this->logProviderError($e, 'context_window_exceeded');
            return null;
        } catch (QuotaExceededException $e) {
            $this->logProviderError($e, 'quota_exceeded');
            return null;
        } catch (UsageNotIncludedException $e) {
            $this->logProviderError($e, 'usage_not_included');
            return null;
        } catch (CyberPolicyException $e) {
            $this->logProviderError($e, 'cyber_policy');
            return null;
        } catch (ServerOverloadedException $e) {
            $this->logProviderError($e, 'server_overloaded');
            return null;
        } catch (InvalidPromptException $e) {
            $this->logProviderError($e, 'invalid_prompt');
            return null;
        } catch (ProviderException $e) {
            $this->logProviderError($e, 'provider_error');
            return null;
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->warning('SuperAgentBackend error: ' . $e->getMessage());
            }
            return null;
        } finally {
            // MCP stdio servers spawn child processes; disconnect so they
            // don't linger past this generate() call.
            $mcpManager?->disconnectAll();
        }
    }

    /**
     * Build per-call options forwarded to `Agent::run($prompt, $options)`.
     * Extracted so tests can assert the exact shape without a running SDK.
     *
     * Requires SDK 0.9.1+ for the option-merge fix (pre-0.9.1 silently
     * dropped the second arg on the non-auto path).
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    protected function buildPerCallOptions(array $options): array
    {
        $perCall = [];

        if (isset($options['idempotency_key']) && is_string($options['idempotency_key']) && $options['idempotency_key'] !== '') {
            $perCall['idempotency_key'] = $options['idempotency_key'];
        }

        // Trace context — either a full W3C traceparent string or a TraceContext
        // instance the caller already built. Host middleware typically forwards
        // the inbound `traceparent` header here.
        if (isset($options['traceparent']) && is_string($options['traceparent']) && $options['traceparent'] !== '') {
            $perCall['traceparent'] = $options['traceparent'];
        }
        if (isset($options['tracestate']) && is_string($options['tracestate']) && $options['tracestate'] !== '') {
            $perCall['tracestate'] = $options['tracestate'];
        }
        if (isset($options['trace_context'])) {
            $perCall['trace_context'] = $options['trace_context'];
        }

        return $perCall;
    }

    /**
     * Log a classified `ProviderException` with a stable error-class tag so
     * operators can grep telemetry for a specific failure mode. Current
     * contract (Backend::generate) still returns null on failure — the
     * classification only enriches the log; routing logic reading the class
     * tag is future work. Override in tests to assert the classification.
     */
    protected function logProviderError(\Throwable $e, string $code): void
    {
        if ($this->logger) {
            $this->logger->warning('SuperAgentBackend error [' . $code . ']: ' . $e->getMessage(), [
                'error_class' => $code,
                'retryable'   => method_exists($e, 'isRetryable') ? $e->isRetryable() : null,
            ]);
        }
    }

    /**
     * Build and configure the Agent. Protected so tests can subclass and
     * swap in an Agent backed by a canned LLMProvider.
     *
     * @param array<string,mixed> $options
     * @param array<string,mixed> $providerConfig
     */
    protected function buildAgent(array $options, array $providerConfig): Agent
    {
        $providerName = (string) ($providerConfig['provider'] ?? $this->resolveSdkProvider($providerConfig) ?? 'anthropic');

        // 0.9.2 host-config adapter: one shape covers every provider key.
        // The SDK's per-key adapter (default for ChatCompletions-style;
        // dedicated one for `bedrock` that splits AWS credentials, etc.)
        // owns the constructor-shape mapping. New SDK provider keys ship
        // with their own adapter and work here without backend changes.
        //
        // Descriptor-declared HTTP header knobs (0.9.1 `http_headers` /
        // `env_http_headers`) ride through `extra` — the default adapter
        // passes them straight to the provider constructor.
        $headerKnobs = $this->resolveHttpHeaderKnobs($providerConfig);
        $extra = [];
        foreach ($headerKnobs as $k => $v) {
            if ($v !== [] && $v !== null) {
                $extra[$k] = $v;
            }
        }

        $hostConfig = [
            'api_key'  => $providerConfig['api_key']  ?? null,
            'base_url' => $providerConfig['base_url'] ?? null,
            'model'    => $options['model']           ?? $providerConfig['model']    ?? null,
            'region'   => $providerConfig['region']   ?? null,
            'extra'    => $extra,
        ];

        $agentConfig = [
            'max_tokens' => (int) ($options['max_tokens'] ?? 500),
            'max_turns'  => max(1, (int) ($options['max_turns'] ?? 1)),
            'provider'   => $this->makeProvider($providerName, $hostConfig),
        ];

        // Short-circuit SDK's ToolLoader when the caller didn't explicitly
        // opt in — ToolLoader's per-class `config()` lookups spam stderr in
        // non-Laravel contexts ("Config unavailable for …"), and we already
        // add MCP tools below via attachMcpTools(). `tools: []` is handled
        // first inside Agent::initializeTools() and skips ToolLoader entirely.
        //
        // 0.9.7 — when the caller didn't pass an explicit `load_tools`,
        // honour the `super-ai-core.tools.*_enabled` flags by promoting
        // `agent_grep` (in the SDK's BuiltinToolRegistry classMap, so
        // ToolLoader resolves it) into the load list. `browser` isn't in
        // the classMap and is added directly to the agent below via
        // `addTool()` — see `attachBrowserTool()`.
        if (array_key_exists('load_tools', $options)) {
            $agentConfig['load_tools'] = $options['load_tools'];
        } else {
            $autoLoad = $this->configuredAutoLoadTools();
            if ($autoLoad !== []) {
                $agentConfig['load_tools'] = $autoLoad;
            } else {
                $agentConfig['tools'] = [];
            }
        }

        if (isset($options['max_cost_usd']) && (float) $options['max_cost_usd'] > 0) {
            $agentConfig['max_budget_usd'] = (float) $options['max_cost_usd'];
        }

        // SDK 0.9.0 forwarded options — `extra_body`, `features` (incl.
        // `prompt_cache_key`), and `loop_detection`. Kept in Agent's
        // internal `options` bag (consumed by the provider + engine via
        // `withOptions()`), so downstream providers and harnesses pick
        // them up without per-backend glue.
        $forwardedOptions = [];
        if (isset($options['extra_body']) && is_array($options['extra_body'])) {
            $forwardedOptions['extra_body'] = $options['extra_body'];
        }
        if (isset($options['features']) && is_array($options['features'])) {
            $forwardedOptions['features'] = $options['features'];
        }
        // Convenience shim: let callers pass `prompt_cache_key` directly
        // instead of nesting it under `features.prompt_cache_key.session_id`.
        // Accept either a string (treated as session_id) or a full array
        // (passed through untouched).
        if (isset($options['prompt_cache_key'])
            && !isset($forwardedOptions['features']['prompt_cache_key'])) {
            $pck = $options['prompt_cache_key'];
            $forwardedOptions['features'] ??= [];
            $forwardedOptions['features']['prompt_cache_key'] = is_array($pck)
                ? $pck
                : ['session_id' => (string) $pck];
        }
        if (array_key_exists('loop_detection', $options)) {
            $forwardedOptions['loop_detection'] = $options['loop_detection'];
        }

        // SDK 0.9.7 forward — `embedding_provider`. SDK consumers that
        // build a `SemanticSkillRouter` via `Agent::getOptions()` get the
        // same instance the host app's container holds, so SuperAICore's
        // `SemanticSkillReranker` and the SDK's own router share one
        // embedder + cache. Caller can pass an explicit instance to
        // override; else we resolve via `EmbeddingProviderFactory`.
        $embedder = $this->resolveEmbeddingProvider($options);
        if ($embedder instanceof EmbeddingProvider) {
            $forwardedOptions['embedding_provider'] = $embedder;
        }

        if ($forwardedOptions !== []) {
            $agentConfig['options'] = $forwardedOptions;
        }

        $agent = $this->makeAgent($agentConfig);

        if (!empty($options['allowed_tools']) && is_array($options['allowed_tools'])) {
            $agent->withAllowedTools(array_values($options['allowed_tools']));
        }
        if (!empty($options['denied_tools']) && is_array($options['denied_tools'])) {
            $agent->withDeniedTools(array_values($options['denied_tools']));
        }

        // 0.9.7 — `browser` tool isn't in BuiltinToolRegistry::classMap so
        // it can't ride `load_tools`; register it directly when the flag
        // is on. Pre-existing `addTool()` for MCP runs after this in
        // generate(), so MCP tools win on name collision (none today).
        $this->attachBrowserTool($agent, $options);

        return $agent;
    }

    /**
     * Auto-load list seeded by `super-ai-core.tools.*_enabled` flags. Only
     * fires on the implicit path — when the caller passes their own
     * `load_tools`, we leave it untouched. Returns [] when no flag is on
     * (preserves the pre-0.9.7 behaviour of `tools: []` short-circuit).
     *
     * @return list<string>
     */
    protected function configuredAutoLoadTools(): array
    {
        if (!function_exists('config')) return [];
        $tools = [];
        if ((bool) config('super-ai-core.tools.agent_grep_enabled', false)) {
            $tools[] = 'agent_grep';
        }
        return $tools;
    }

    /**
     * Add SDK 0.9.7's `FirefoxBridgeTool` (`browser`) to the agent when
     * the operator opted in via `super-ai-core.tools.browser_enabled`.
     * Silent no-op when SDK FirefoxBridgeTool isn't on the classpath
     * (host pinned to pre-0.9.7) or when the flag is off.
     *
     * The tool itself has tight degrade-on-missing-launcher semantics:
     * when `SUPERAGENT_BROWSER_BRIDGE_PATH` isn't set, every action
     * returns an error string explaining the missing setup so the agent
     * stops looping. Callers that want to suppress the tool entirely
     * leave the flag off.
     *
     * @param array<string,mixed> $options
     */
    protected function attachBrowserTool(Agent $agent, array $options): void
    {
        if (!function_exists('config')) return;
        if (!(bool) config('super-ai-core.tools.browser_enabled', false)) return;

        $cls = '\\SuperAgent\\Tools\\Builtin\\FirefoxBridgeTool';
        if (!class_exists($cls)) return;

        try {
            $launcher = $options['browser_launcher_argv'] ?? null;
            $tool = is_array($launcher) && $launcher !== []
                ? new $cls($launcher)
                : new $cls();
            $agent->addTool($tool);
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->debug('SuperAgentBackend: skipping browser tool wiring: ' . $e->getMessage());
            }
        }
    }

    /**
     * Resolve the EmbeddingProvider for this dispatch. Caller-supplied
     * instance wins; otherwise the container singleton built from
     * `super-ai-core.embeddings.*` config is shared with
     * `SemanticSkillReranker`.
     *
     * Returns null when the SDK 0.9.7 Memory\Embeddings package is
     * unavailable or no embedder is configured — caller can degrade.
     *
     * @param array<string,mixed> $options
     */
    protected function resolveEmbeddingProvider(array $options): ?EmbeddingProvider
    {
        if (!interface_exists(EmbeddingProvider::class)) return null;

        $explicit = $options['embedding_provider'] ?? null;
        if ($explicit instanceof EmbeddingProvider) return $explicit;

        $factory = $this->embeddingFactory;
        if ($factory === null && function_exists('app')) {
            try {
                $factory = app(EmbeddingProviderFactory::class);
            } catch (\Throwable) {
                return null;
            }
        }
        return $factory?->make();
    }

    /**
     * Concrete Agent factory — kept as its own seam so tests can swap in
     * an anonymous LLMProvider without touching ProviderRegistry.
     */
    protected function makeAgent(array $agentConfig): Agent
    {
        return new Agent($agentConfig);
    }

    /**
     * Build the SDK `LLMProvider` instance for this provider_config. Routes
     * through 0.9.2's `createForHost()` so per-provider constructor-shape
     * differences (Bedrock's split AWS creds, Azure's auto-detected base
     * URL, LMStudio's synthetic auth, future provider keys) are owned by
     * the SDK adapter, not by this backend.
     *
     * Kept as its own seam so tests can substitute a fake provider without
     * having to register it in `ProviderRegistry`.
     *
     * @param array<string,mixed> $hostConfig
     */
    protected function makeProvider(string $providerName, array $hostConfig): \SuperAgent\Contracts\LLMProvider
    {
        return ProviderRegistry::createForHost($providerName, $hostConfig);
    }

    /**
     * Pick the SDK `ProviderRegistry` key for this provider_config. Returns
     * null when the type is unknown (or the service container isn't booted,
     * e.g. early in a CLI run) — callers fall back to the legacy default.
     *
     * The UI type doesn't always match the SDK key: `anthropic-proxy` and
     * `openai-compatible` are BYO-base-url wrappers whose SDK key is the
     * base provider (`anthropic` / `openai`). Descriptors declare the
     * mapping so the two stay in sync.
     *
     * @param array<string,mixed> $providerConfig
     */
    protected function resolveSdkProvider(array $providerConfig): ?string
    {
        $type = $providerConfig['type'] ?? null;
        if (!is_string($type) || $type === '') return null;

        $descriptor = $this->lookupDescriptor($type);
        if ($descriptor === null) return null;

        return $descriptor->sdkProvider ?? $descriptor->type;
    }

    /**
     * Project the descriptor's `http_headers` + `env_http_headers` onto the
     * llmConfig keys that `ChatCompletionsProvider` recognises (0.9.1+).
     * Returns empty arrays when there's no descriptor or nothing to inject.
     *
     * @param array<string,mixed> $providerConfig
     * @return array{http_headers: array<string,string>, env_http_headers: array<string,string>}
     */
    protected function resolveHttpHeaderKnobs(array $providerConfig): array
    {
        $type = $providerConfig['type'] ?? null;
        if (!is_string($type) || $type === '') {
            return ['http_headers' => [], 'env_http_headers' => []];
        }
        $descriptor = $this->lookupDescriptor($type);
        if ($descriptor === null) {
            return ['http_headers' => [], 'env_http_headers' => []];
        }
        return [
            'http_headers'     => $descriptor->httpHeaders,
            'env_http_headers' => $descriptor->envHttpHeaders,
        ];
    }

    /**
     * Fetch a provider-type descriptor from the DI container. Returns null
     * when the container isn't booted (early CLI, unit tests without a
     * Laravel app) so callers can gracefully fall back.
     */
    protected function lookupDescriptor(string $type): ?\SuperAICore\Support\ProviderTypeDescriptor
    {
        if (!function_exists('app')) return null;
        try {
            /** @var ProviderTypeRegistry $registry */
            $registry = app(ProviderTypeRegistry::class);
            return $registry->get($type);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Load MCP servers from a host-written `.mcp.json` and register their
     * tools with the Agent. Returns the manager so the caller can
     * disconnectAll() in finally{} — stdio servers spawn subprocesses.
     *
     * @param array<string,mixed> $options
     */
    protected function attachMcpTools(Agent $agent, array $options): ?MCPManager
    {
        $file = $options['mcp_config_file'] ?? null;
        if (!is_string($file) || $file === '' || !is_file($file)) {
            return null;
        }

        $manager = new MCPManager();
        $manager->loadFromJsonFile($file);
        $manager->autoConnect();

        foreach ($manager->getTools() as $tool) {
            $agent->addTool($tool);
        }

        return $manager;
    }

    /**
     * Concatenate every `thinking`-type content block emitted across the
     * AgentResult message trail. Returns '' when the SDK never produced
     * reasoning text (pre-0.9.6 SDK, providers without a reasoning channel,
     * or `delta.reasoning_content` simply not emitted on this turn).
     *
     * Reads two shapes:
     *   - native `ContentBlock` instances with `type === 'thinking'` and
     *     `text` populated (Anthropic's native channel + 0.9.6 OpenAI-compat
     *     `delta.reasoning_content` rebroadcast)
     *   - bare arrays `['type' => 'thinking', 'text' => '…']` for hosts
     *     that hand-build messages outside the SDK's normal path
     *
     * Never throws — malformed blocks are skipped silently.
     *
     * @param  array<int, object|array<string,mixed>> $messages  AgentResult::$messages
     */
    protected function extractThinking(array $messages): string
    {
        $parts = [];
        foreach ($messages as $msg) {
            if (!is_object($msg)) continue;
            // AssistantMessage carries the thinking block; tool result / system
            // messages don't (and would never be reached here anyway since the
            // SDK only emits thinking on assistant turns).
            $blocks = $msg->content ?? null;
            if (!is_array($blocks)) continue;
            foreach ($blocks as $block) {
                $type = is_object($block) ? ($block->type ?? null)
                      : (is_array($block) ? ($block['type'] ?? null) : null);
                if ($type !== 'thinking') continue;
                $text = is_object($block) ? ($block->text ?? '')
                      : (is_array($block) ? ($block['text'] ?? '') : '');
                if (!is_string($text) || $text === '') continue;
                $parts[] = $text;
            }
        }
        return implode("\n\n", $parts);
    }

    /**
     * Lookup catalog deprecation metadata for the resolved model. Returns
     * null when ModelCatalog isn't available (SDK pinned below 0.9.6 or
     * the user removed the bundled `models.json`), when the catalog has no
     * row for this id, or when the row carries no `deprecated_until` field
     * (i.e. the model is current).
     *
     * Shape: `{model, deprecated_until, replaced_by, days_left}`.
     * `days_left` is negative once the deprecation window has lapsed —
     * callers can use that to escalate the warning level.
     *
     * @return array{model:string, deprecated_until:string, replaced_by:?string, days_left:int}|null
     */
    protected function resolveDeprecation(string $model): ?array
    {
        $catalog = '\\SuperAgent\\Providers\\ModelCatalog';
        if (!class_exists($catalog) || !method_exists($catalog, 'deprecation')) {
            return null;
        }
        try {
            $info = $catalog::deprecation($model);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($info) || !isset($info['deprecated_until'])) {
            return null;
        }
        return [
            'model'             => $model,
            'deprecated_until'  => (string) $info['deprecated_until'],
            'replaced_by'       => isset($info['replaced_by']) ? (string) $info['replaced_by'] : null,
            'days_left'         => (int) ($info['days_left'] ?? 0),
        ];
    }

    /**
     * Persist the latest base64 PNG emitted by SDK 0.9.7's `browser` tool
     * (FirefoxBridgeTool) into `BrowserScreenshotStore` and return the
     * URL so the envelope can carry it onto `/processes` and `/usage`.
     * Returns null when no browser screenshot fired this run (the common
     * case — most dispatches don't drive a browser).
     *
     * Identification strategy: a `tool_use` block with `toolName === 'browser'`
     * is followed in the trail by a `tool_result` block carrying the same
     * `toolUseId`. The result content is JSON-encoded
     * `{format,base64,bytes}` (see FirefoxBridgeTool::execute case
     * 'screenshot'). We pair them up and keep the LAST successful one —
     * a long agent run might take many screenshots and only the most
     * recent is operationally interesting.
     *
     * Process-id key precedence (must agree with the host's
     * ProcessSource so `BrowserScreenshotStore::latest($pid)` lines up):
     *   1. options['process_id']
     *   2. options['metadata']['session_id']
     *   3. options['external_label']
     *   4. random 16-hex (last resort — orphaned but at least keyed)
     *
     * @param  array<int, object|array<string,mixed>> $messages  AgentResult::$messages
     * @param  array<string,mixed>                    $options   dispatch options
     */
    protected function persistLatestScreenshot(array $messages, array $options): ?string
    {
        $store = $this->resolveScreenshotStore();
        if ($store === null) return null;

        $browserUseIds = [];
        $latestB64 = null;
        foreach ($messages as $msg) {
            if (!is_object($msg)) continue;
            $blocks = $msg->content ?? null;
            if (!is_array($blocks)) continue;
            foreach ($blocks as $block) {
                if (!is_object($block)) continue;
                $type = $block->type ?? null;
                if ($type === 'tool_use') {
                    $name = $block->toolName ?? null;
                    $useId = $block->toolUseId ?? null;
                    if ($name === 'browser' && is_string($useId) && $useId !== '') {
                        $browserUseIds[$useId] = true;
                    }
                    continue;
                }
                if ($type !== 'tool_result') continue;
                $useId = $block->toolUseId ?? null;
                if (!is_string($useId) || !isset($browserUseIds[$useId])) continue;
                if (($block->isError ?? false) === true) continue;

                $raw = $block->content ?? null;
                if (!is_string($raw) || $raw === '') continue;
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) continue;
                $b64 = $decoded['base64'] ?? null;
                if (!is_string($b64) || $b64 === '') continue;
                $latestB64 = $b64;
            }
        }
        if ($latestB64 === null) return null;

        $key = $this->resolveScreenshotKey($options);
        try {
            return $store->store($key, $latestB64);
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->debug('SuperAgentBackend: screenshot store failed: ' . $e->getMessage());
            }
            return null;
        }
    }

    protected function resolveScreenshotStore(): ?BrowserScreenshotStore
    {
        if ($this->screenshotStore !== null) return $this->screenshotStore;
        if (!function_exists('app')) return null;
        try {
            return app(BrowserScreenshotStore::class);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Round-trip key for `BrowserScreenshotStore`. The host's ProcessSource
     * has to pick the same one to render the frame back; precedence is
     * picked so that the natural Dispatcher inputs (`external_label`,
     * `metadata.session_id`) hit before we fall back to randomness.
     *
     *   1. options['process_id']             — host-owned explicit hook
     *   2. options['external_label']         — Dispatcher's canonical id
     *   3. options['metadata']['session_id'] — same session id used by
     *                                          cache-cold detection
     *   4. options['session_id']             — bare-call fallback
     *   5. random hex                        — orphaned but at least keyed
     *
     * @param array<string,mixed> $options
     */
    protected function resolveScreenshotKey(array $options): string
    {
        if (isset($options['process_id']) && is_string($options['process_id']) && $options['process_id'] !== '') {
            return $options['process_id'];
        }
        $label = $options['external_label'] ?? null;
        if (is_string($label) && $label !== '') return $label;

        $sid = $options['metadata']['session_id'] ?? null;
        if (is_string($sid) && $sid !== '') return $sid;
        if (isset($options['session_id']) && is_string($options['session_id']) && $options['session_id'] !== '') {
            return $options['session_id'];
        }
        try {
            return bin2hex(random_bytes(8));
        } catch (\Throwable) {
            return 'unknown-' . dechex((int) (microtime(true) * 1000));
        }
    }

    /**
     * Extract SDK 0.8.9+ `AgentTool` productivity info from the Agent's
     * message trail. 0.8.9's `AgentTool` attaches `filesWritten` /
     * `toolCallsByName` / `productivityWarning` / sharpened `status`
     * (`completed` | `completed_empty`) to every sub-agent dispatch
     * result; we surface the list upward so `Dispatcher` callers that
     * opted into SDK sub-agent dispatch can detect a child that produced
     * only prose (`completed_empty`) or called tools without writing
     * (advisory `productivityWarning`) without scraping narratives.
     *
     * Pre-0.8.9 or callers that don't dispatch sub-agents see an empty
     * list here and thus no `subagents` key in the envelope — the shape
     * stays byte-compatible. The helper never throws: malformed JSON or
     * unexpected message types get skipped silently.
     *
     * @param  array<int, object> $messages  `AgentResult::$messages`
     * @return list<array{agentId:string,status:string,filesWritten:list<string>,toolCallsByName:array<string,int>,productivityWarning:?string,totalToolUseCount:int}>
     */
    protected function extractSubagentProductivity(array $messages): array
    {
        $out = [];
        foreach ($messages as $msg) {
            if (!$msg instanceof \SuperAgent\Messages\ToolResultMessage) continue;
            foreach ($msg->content as $block) {
                if (!is_object($block) || ($block->type ?? null) !== 'tool_result') continue;
                $raw = $block->content ?? null;
                if (!is_string($raw) || $raw === '' || $raw[0] !== '{') continue;
                $d = json_decode($raw, true);
                if (!is_array($d) || !isset($d['agentId'])) continue;
                // Require at least one of the 0.8.9 productivity fields so
                // unrelated AgentTool-shaped results from a pre-0.8.9 SDK
                // (or a third-party tool that happens to emit `agentId`)
                // don't false-match.
                if (!array_key_exists('filesWritten', $d)
                    && !array_key_exists('productivityWarning', $d)
                    && !array_key_exists('toolCallsByName', $d)) {
                    continue;
                }
                $out[] = [
                    'agentId'             => (string) $d['agentId'],
                    'status'              => (string) ($d['status'] ?? 'completed'),
                    'filesWritten'        => array_values(array_map('strval', (array) ($d['filesWritten'] ?? []))),
                    'toolCallsByName'     => array_map('intval', (array) ($d['toolCallsByName'] ?? [])),
                    'productivityWarning' => isset($d['productivityWarning']) && $d['productivityWarning'] !== null
                        ? (string) $d['productivityWarning'] : null,
                    'totalToolUseCount'   => (int) ($d['totalToolUseCount'] ?? 0),
                ];
            }
        }
        return $out;
    }
}
