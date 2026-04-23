<?php

namespace SuperAICore\Backends;

use Psr\Log\LoggerInterface;
use SuperAgent\Agent;
use SuperAgent\MCP\MCPManager;
use SuperAgent\Providers\ProviderRegistry;
use SuperAICore\Contracts\Backend;

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
 *                              support it (Kimi/Qwen/GLM/MiniMax); routed
 *                              through createWithRegion() because Agent's
 *                              internal config allowlist skips 'region'.
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
 * Envelope additions on success:
 *   - usage.cache_read_input_tokens, usage.cache_creation_input_tokens
 *   - cost_usd:   SDK's own turn-summed cost. Dispatcher prefers this
 *                 over its own CostCalculator when non-zero.
 *   - turns:      number of assistant turns actually consumed.
 */
class SuperAgentBackend implements Backend
{
    public function __construct(protected ?LoggerInterface $logger = null) {}

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

            $result = $agent->run((string) ($options['prompt'] ?? ''));
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

            // SDK 0.8.9+ AgentTool productivity (only emitted when the caller
            // opted into `load_tools: ['agent']` or similar — AgentTool isn't
            // in the default set). Key is omitted when no sub-agent ran, so
            // existing envelope shape stays byte-exact for callers that don't
            // dispatch sub-agents through the SDK path.
            $subagents = $this->extractSubagentProductivity($result->messages ?? []);
            if ($subagents !== []) {
                $envelope['subagents'] = $subagents;
            }

            return $envelope;
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
     * Build and configure the Agent. Protected so tests can subclass and
     * swap in an Agent backed by a canned LLMProvider.
     *
     * @param array<string,mixed> $options
     * @param array<string,mixed> $providerConfig
     */
    protected function buildAgent(array $options, array $providerConfig): Agent
    {
        $providerName = (string) ($providerConfig['provider'] ?? 'anthropic');
        $region       = $providerConfig['region'] ?? null;

        $llmConfig = [];
        foreach ([
            'api_key'  => $providerConfig['api_key']  ?? null,
            'model'    => $options['model']           ?? $providerConfig['model']    ?? null,
            'base_url' => $providerConfig['base_url'] ?? null,
        ] as $k => $v) {
            if ($v !== null && $v !== '') {
                $llmConfig[$k] = $v;
            }
        }

        $agentConfig = [
            'max_tokens' => (int) ($options['max_tokens'] ?? 500),
            'max_turns'  => max(1, (int) ($options['max_turns'] ?? 1)),
        ];

        // Short-circuit SDK's ToolLoader when the caller didn't explicitly
        // opt in — ToolLoader's per-class `config()` lookups spam stderr in
        // non-Laravel contexts ("Config unavailable for …"), and we already
        // add MCP tools below via attachMcpTools(). `tools: []` is handled
        // first inside Agent::initializeTools() and skips ToolLoader entirely.
        if (array_key_exists('load_tools', $options)) {
            $agentConfig['load_tools'] = $options['load_tools'];
        } else {
            $agentConfig['tools'] = [];
        }

        // Region-aware providers (Kimi/Qwen/GLM/MiniMax): Agent's internal
        // config allowlist skips `region`, so we build the provider
        // explicitly and hand the instance in.
        if ($region !== null && $region !== '') {
            $agentConfig['provider'] = ProviderRegistry::createWithRegion(
                $providerName,
                (string) $region,
                $llmConfig,
            );
        } else {
            $agentConfig['provider'] = $providerName;
            $agentConfig += $llmConfig;
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

        return $agent;
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
