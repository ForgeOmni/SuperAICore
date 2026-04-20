<?php

namespace SuperAICore\Services;

use SuperAICore\Backends\AnthropicApiBackend;
use SuperAICore\Backends\ClaudeCliBackend;
use SuperAICore\Backends\CodexCliBackend;
use SuperAICore\Backends\CopilotCliBackend;
use SuperAICore\Backends\GeminiApiBackend;
use SuperAICore\Backends\GeminiCliBackend;
use SuperAICore\Backends\OpenAiApiBackend;
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
        $config = $config ?: (function_exists('config') ? (config('super-ai-core.backends') ?? []) : []);
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
        if (($config['superagent']['enabled'] ?? true) && $sdkAvailable()) {
            $this->register(new SuperAgentBackend($logger));
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
}
