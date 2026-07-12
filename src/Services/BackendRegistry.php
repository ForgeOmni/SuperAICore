<?php

namespace SuperAICore\Services;

use SuperAICore\Backends\AnthropicApiBackend;
use SuperAICore\Backends\ClaudeCliBackend;
use SuperAICore\Backends\CodexCliBackend;
use SuperAICore\Backends\CopilotCliBackend;
use SuperAICore\Backends\CursorCliBackend;
use SuperAICore\Backends\GeminiApiBackend;
use SuperAICore\Backends\GeminiCliBackend;
use SuperAICore\Backends\GrokCliBackend;
use SuperAICore\Backends\KimiCliBackend;
use SuperAICore\Backends\KiroCliBackend;
use SuperAICore\Backends\QwenCliBackend;
use SuperAICore\Backends\OpenAiApiBackend;
use SuperAICore\Backends\SquadBackend;
use SuperAICore\Backends\SuperAgentBackend;
use SuperAICore\Contracts\Backend;
use SuperAICore\Support\SuperAgentDetector;
use Psr\Log\LoggerInterface;

class BackendRegistry
{
    /** @var Backend[] */
    protected array $backends = [];

    public function __construct(
        ?LoggerInterface $logger = null,
        array $config = [],
        ?callable $superagentAvailable = null,
    ) {
        $config = $config ?: (\SuperAICore\Support\ConfigValue::get('super-ai-core.backends') ?? []);
        // Injectable for tests; prod callers pass nothing and get the real detector.
        $sdkAvailable = $superagentAvailable ?? [SuperAgentDetector::class, 'isAvailable'];

        if ($config['anthropic_api']['enabled'] ?? true) {
            $this->register(new AnthropicApiBackend($logger));
        }
        if ($config['openai_api']['enabled'] ?? true) {
            $this->register(new OpenAiApiBackend($logger));
        }
        // SuperAgent backend is hidden entirely when the forgeomni/superagent
        // SDK is not installed, regardless of config — avoids a dead option.
        // Resolve via container so the optional SnapshotDiffService /
        // RemindersResolver / PermissionEvaluator / SubagentPermissionDeriver
        // dependencies wire automatically. Falls back to a bare constructor
        // when the container isn't bound (early CLI / unit tests).
        if (($config['superagent']['enabled'] ?? true) && $sdkAvailable()) {
            $backend = null;
            if (function_exists('app')) {
                try {
                    $backend = app(SuperAgentBackend::class);
                } catch (\Throwable) {
                    $backend = null;
                }
            }
            $this->register($backend ?? new SuperAgentBackend($logger));
        }
        // SDK 1.0.0 Squad — multi-agent peer collaboration with per-step
        // model tiering and checkpointing. Requires the SDK like
        // SuperAgentBackend; hidden when the SDK isn't installed or the
        // operator turned it off.
        if (($config['squad']['enabled'] ?? true) && $sdkAvailable()) {
            $this->register(new SquadBackend($logger));
        }
        if ($config['claude_cli']['enabled'] ?? true) {
            $this->register(new ClaudeCliBackend(
                $config['claude_cli']['binary'] ?? 'claude',
                $config['claude_cli']['timeout'] ?? 300,
                $logger,
            ));
        }
        if ($config['codex_cli']['enabled'] ?? true) {
            $this->register(new CodexCliBackend(
                $config['codex_cli']['binary'] ?? 'codex',
                $config['codex_cli']['timeout'] ?? 300,
                $logger,
            ));
        }
        if ($config['gemini_cli']['enabled'] ?? true) {
            $this->register(new GeminiCliBackend(
                $config['gemini_cli']['binary'] ?? 'gemini',
                $config['gemini_cli']['timeout'] ?? 300,
                $logger,
            ));
        }
        if ($config['copilot_cli']['enabled'] ?? true) {
            $this->register(new CopilotCliBackend(
                $config['copilot_cli']['binary'] ?? 'copilot',
                $config['copilot_cli']['timeout'] ?? 300,
                $config['copilot_cli']['allow_all_tools'] ?? true,
                $logger,
            ));
        }
        if ($config['kiro_cli']['enabled'] ?? true) {
            $this->register(new KiroCliBackend(
                $config['kiro_cli']['binary'] ?? 'kiro-cli',
                $config['kiro_cli']['timeout'] ?? 300,
                $config['kiro_cli']['trust_all_tools'] ?? true,
                $logger,
            ));
        }
        if ($config['kimi_cli']['enabled'] ?? true) {
            $this->register(new KimiCliBackend(
                $config['kimi_cli']['binary'] ?? 'kimi',
                $config['kimi_cli']['timeout'] ?? 300,
                $config['kimi_cli']['max_steps_per_turn'] ?? 500,
                $logger,
                $config['kimi_cli']['variant'] ?? KimiCliBackend::VARIANT_AUTO,
            ));
        }
        // QwenLM/qwen-code v0.16.0 (2026-05-21). Default model qwen3.7-max
        // (1M context, $2.50/$7.50 per 1M, native Anthropic protocol).
        if ($config['qwen_cli']['enabled'] ?? true) {
            $this->register(new QwenCliBackend(
                $config['qwen_cli']['binary'] ?? 'qwen',
                $config['qwen_cli']['timeout'] ?? 300,
                $logger,
            ));
        }
        // Cursor Composer CLI (`cursor-agent`). Subscription engine — owns its
        // own login (~/.cursor). Default model composer-2.5.
        if ($config['cursor_cli']['enabled'] ?? true) {
            $this->register(new CursorCliBackend(
                $config['cursor_cli']['binary'] ?? 'cursor-agent',
                $config['cursor_cli']['timeout'] ?? 300,
                $config['cursor_cli']['force'] ?? true,
                $logger,
            ));
        }
        // Grok Build CLI (`grok`). Subscription engine — grok.com login
        // (~/.grok). Default model grok-4.5 (grok-build on older accounts).
        // Distinct from the metered xAI API provider routed through the
        // superagent backend.
        if ($config['grok_cli']['enabled'] ?? true) {
            $this->register(new GrokCliBackend(
                $config['grok_cli']['binary'] ?? 'grok',
                $config['grok_cli']['timeout'] ?? 300,
                $config['grok_cli']['always_approve'] ?? true,
                $logger,
            ));
        }
        if ($config['gemini_api']['enabled'] ?? true) {
            $this->register(new GeminiApiBackend($logger));
        }
    }

    public function register(Backend $backend): void
    {
        $this->backends[$backend->name()] = $backend;
    }

    public function get(string $name): ?Backend
    {
        return $this->backends[$name] ?? null;
    }

    /** @return Backend[] */
    public function all(): array
    {
        return $this->backends;
    }

    public function names(): array
    {
        return array_keys($this->backends);
    }

    /**
     * Resolve the first registered backend for an engine key that
     * implements `ScriptedSpawnBackend` — the contract hosts use for
     * async task runs and one-shot chat.
     *
     * Engine → backend mapping comes from `EngineCatalog`'s
     * `dispatcher_backends` (e.g. `'claude' → ['claude_cli','anthropic_api']`);
     * the CLI dispatcher is always first so it wins by construction.
     *
     * Returns null when the engine has no CLI backend registered
     * (e.g. engine enabled=false in config, or superagent-only engines
     * that don't implement scripted spawn).
     */
    public function forEngine(string $engineKey): ?\SuperAICore\Contracts\ScriptedSpawnBackend
    {
        $catalog = app(\SuperAICore\Services\EngineCatalog::class);
        $engine = $catalog->get($engineKey);
        if (!$engine) return null;

        foreach ($engine->dispatcherBackends as $backendName) {
            $backend = $this->get($backendName);
            if ($backend instanceof \SuperAICore\Contracts\ScriptedSpawnBackend) {
                return $backend;
            }
        }
        return null;
    }
}
