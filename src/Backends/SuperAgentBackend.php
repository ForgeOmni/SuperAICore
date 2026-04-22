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
 *   - provider_config.region   intl/cn/us/hk split for providers that
 *                              support it (Kimi/Qwen/GLM/MiniMax); routed
 *                              through createWithRegion() because Agent's
 *                              internal config allowlist skips 'region'
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

            return [
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
}
