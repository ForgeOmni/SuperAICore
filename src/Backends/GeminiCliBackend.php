<?php

namespace SuperAICore\Backends;

use SuperAICore\Contracts\Backend;
use SuperAICore\Services\GeminiModelResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Spawns Google's `gemini` CLI (open-source at github.com/google-gemini/gemini-cli).
 *
 * Auth modes honoured (same precedence the CLI itself uses):
 *   1. GEMINI_API_KEY / GOOGLE_API_KEY env — direct Google AI Studio
 *   2. Vertex AI: GOOGLE_CLOUD_PROJECT + GOOGLE_CLOUD_LOCATION + GOOGLE_GENAI_USE_VERTEXAI=true
 *   3. `gemini` local login (OAuth with a Google account) — no env vars needed
 */
class GeminiCliBackend implements Backend
{
    public function __construct(
        protected string $binary = 'gemini',
        protected int $timeout = 300,
        protected ?LoggerInterface $logger = null,
    ) {}

    public function name(): string
    {
        return 'gemini_cli';
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
        $model = GeminiModelResolver::resolve($options['model'] ?? $providerConfig['model'] ?? null);

        // gemini-cli one-shot mode: `gemini -p "prompt"` or pipe via stdin.
        // `--model` selects the model when the account supports several.
        $cmd = [$this->binary];
        if ($model) {
            $cmd[] = '--model';
            $cmd[] = $model;
        }
        $cmd[] = '-p';
        $cmd[] = $prompt;

        $env = [];
        if (!empty($providerConfig['api_key'])) {
            $env['GEMINI_API_KEY'] = $providerConfig['api_key'];
        }
        // Vertex AI passthrough
        $extra = $providerConfig['extra_config'] ?? [];
        if (!empty($extra['project_id'])) {
            $env['GOOGLE_CLOUD_PROJECT'] = $extra['project_id'];
            $env['GOOGLE_GENAI_USE_VERTEXAI'] = 'true';
        }
        if (!empty($extra['region'])) {
            $env['GOOGLE_CLOUD_LOCATION'] = $extra['region'];
        }

        try {
            $process = new Process($cmd, null, $env);
            $process->setTimeout($this->timeout);
            $process->run();

            if (!$process->isSuccessful()) {
                if ($this->logger) $this->logger->warning('GeminiCliBackend failed: ' . $process->getErrorOutput());
                return null;
            }

            $text = trim($process->getOutput());
            if ($text === '') return null;

            return [
                'text' => $text,
                'model' => $model ?? 'gemini-default',
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
                'stop_reason' => null,
            ];
        } catch (\Throwable $e) {
            if ($this->logger) $this->logger->warning("GeminiCliBackend error: {$e->getMessage()}");
            return null;
        }
    }
}
