<?php

namespace SuperAICore\Backends;

use SuperAICore\Contracts\Backend;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Spawns the `codex` CLI (OpenAI's codex-rs). Uses OPENAI_API_KEY env var.
 */
class CodexCliBackend implements Backend
{
    public function __construct(
        protected string $binary = 'codex',
        protected int $timeout = 300,
        protected ?LoggerInterface $logger = null,
    ) {}

    public function name(): string
    {
        return 'codex_cli';
    }

    public function isAvailable(array $providerConfig = []): bool
    {
        $process = new Process(['which', $this->binary]);
        $process->run();
        return $process->isSuccessful();
    }

    public function generate(array $options): ?array
    {
        $providerConfig = $options['provider_config'] ?? [];
        $prompt = $options['prompt'] ?? '';
        $model = $options['model'] ?? $providerConfig['model'] ?? null;

        $cmd = [$this->binary, 'exec'];  // one-shot mode
        if ($model) {
            $cmd[] = '--model';
            $cmd[] = $model;
        }
        $cmd[] = $prompt;

        $env = [];
        if (!empty($providerConfig['api_key'])) {
            $env['OPENAI_API_KEY'] = $providerConfig['api_key'];
        }
        if (!empty($providerConfig['base_url'])) {
            $env['OPENAI_BASE_URL'] = $providerConfig['base_url'];
        }

        try {
            $process = new Process($cmd, null, $env);
            $process->setTimeout($this->timeout);
            $process->run();

            if (!$process->isSuccessful()) {
                if ($this->logger) $this->logger->warning('CodexCliBackend failed: ' . $process->getErrorOutput());
                return null;
            }

            $text = trim($process->getOutput());
            if ($text === '') return null;

            return [
                'text' => $text,
                'model' => $model ?? 'codex-default',
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
                'stop_reason' => null,
            ];
        } catch (\Throwable $e) {
            if ($this->logger) $this->logger->warning("CodexCliBackend error: {$e->getMessage()}");
            return null;
        }
    }
}
