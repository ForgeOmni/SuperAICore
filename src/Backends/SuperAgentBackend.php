<?php

namespace SuperAICore\Backends;

use SuperAICore\Contracts\Backend;
use Psr\Log\LoggerInterface;
use SuperAgent\Agent;

/**
 * Delegate to forgeomni/superagent SDK — in-process LLM + tool-use loop.
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
        $config = array_filter([
            'provider' => $providerConfig['provider'] ?? 'anthropic',
            'api_key' => $providerConfig['api_key'] ?? null,
            'model' => $options['model'] ?? $providerConfig['model'] ?? null,
            'base_url' => $providerConfig['base_url'] ?? null,
            'max_tokens' => $options['max_tokens'] ?? 500,
        ]);

        try {
            $agent = new Agent($config);
            if (!empty($options['system'])) {
                $agent->withSystemPrompt($options['system']);
            }

            $result = $agent->run($options['prompt'] ?? '', ['max_turns' => 1]);
            $text = $result->text();
            if ($text === '') return null;

            $usage = method_exists($result, 'totalUsage') ? $result->totalUsage() : null;

            return [
                'text' => $text,
                'model' => $config['model'] ?? 'unknown',
                'usage' => $usage ? [
                    'input_tokens' => method_exists($usage, 'getInputTokens') ? $usage->getInputTokens() : 0,
                    'output_tokens' => method_exists($usage, 'getOutputTokens') ? $usage->getOutputTokens() : 0,
                ] : ['input_tokens' => 0, 'output_tokens' => 0],
                'stop_reason' => null,
            ];
        } catch (\Throwable $e) {
            if ($this->logger) $this->logger->warning("SuperAgentBackend error: {$e->getMessage()}");
            return null;
        }
    }
}
