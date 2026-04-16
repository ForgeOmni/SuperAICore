<?php

namespace ForgeOmni\AiCore\Services;

use ForgeOmni\AiCore\Backends\AnthropicApiBackend;
use ForgeOmni\AiCore\Backends\ClaudeCliBackend;
use ForgeOmni\AiCore\Backends\CodexCliBackend;
use ForgeOmni\AiCore\Backends\OpenAiApiBackend;
use ForgeOmni\AiCore\Backends\SuperAgentBackend;
use ForgeOmni\AiCore\Contracts\Backend;
use Psr\Log\LoggerInterface;

class BackendRegistry
{
    /** @var Backend[] */
    protected array $backends = [];

    public function __construct(?LoggerInterface $logger = null, array $config = [])
    {
        $config = $config ?: (function_exists('config') ? (config('ai-core.backends') ?? []) : []);

        if ($config['anthropic_api']['enabled'] ?? true) {
            $this->register(new AnthropicApiBackend($logger));
        }
        if ($config['openai_api']['enabled'] ?? true) {
            $this->register(new OpenAiApiBackend($logger));
        }
        if ($config['superagent']['enabled'] ?? true) {
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
