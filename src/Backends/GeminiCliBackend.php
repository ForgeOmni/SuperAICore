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
 *
 * Token usage is extracted from `gemini --output-format=json`, whose
 * single-blob response includes per-model `stats.models.<id>.tokens`.
 * We pick the model whose role is "main" (vs. "utility_router" side
 * calls that inflate counts but don't represent the user-facing answer).
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

        $cmd = [$this->binary, '--output-format=json', '--yolo'];
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

            $parsed = $this->parseJson($process->getOutput());
            if (!$parsed || $parsed['text'] === '') return null;

            return [
                'text'        => $parsed['text'],
                'model'       => $parsed['model'] ?? $model ?? 'gemini-default',
                'usage'       => [
                    'input_tokens'         => $parsed['input_tokens'],
                    'output_tokens'        => $parsed['output_tokens'],
                    'cached_input_tokens'  => $parsed['cached_input_tokens'],
                    'thoughts_tokens'      => $parsed['thoughts_tokens'],
                ],
                'stop_reason' => null,
            ];
        } catch (\Throwable $e) {
            if ($this->logger) $this->logger->warning("GeminiCliBackend error: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Parse the single-blob JSON gemini-cli emits under `--output-format=json`.
     * Public for testing.
     *
     * Token naming quirk: Gemini reports `input` and `candidates` rather
     * than the OpenAI-style `input`/`output`. We normalize to our
     * `input_tokens`/`output_tokens` contract.
     *
     * @return array{text:string, model:?string, input_tokens:int, output_tokens:int, cached_input_tokens:int, thoughts_tokens:int}|null
     */
    public function parseJson(string $output): ?array
    {
        $output = trim($output);
        if ($output === '' || $output[0] !== '{') return null;

        $data = json_decode($output, true);
        if (!is_array($data) || !isset($data['response'])) return null;

        $models = $data['stats']['models'] ?? [];
        $primary = $this->pickPrimaryModel($models);
        $tokens = $primary !== null ? ($models[$primary]['tokens'] ?? []) : [];

        return [
            'text'                => is_string($data['response']) ? $data['response'] : '',
            'model'               => $primary,
            'input_tokens'        => (int) ($tokens['input'] ?? $tokens['prompt'] ?? 0),
            'output_tokens'       => (int) ($tokens['candidates'] ?? 0),
            'cached_input_tokens' => (int) ($tokens['cached'] ?? 0),
            'thoughts_tokens'     => (int) ($tokens['thoughts'] ?? 0),
        ];
    }

    /**
     * Pick the main model by scanning `stats.models[x].roles.main`. Falls
     * back to the model with the highest output (candidates) count, then
     * the first key.
     */
    private function pickPrimaryModel(array $models): ?string
    {
        if (!$models) return null;
        foreach ($models as $id => $stats) {
            if (isset($stats['roles']['main'])) {
                return $id;
            }
        }
        $best = null;
        $bestOut = -1;
        foreach ($models as $id => $stats) {
            $out = (int) ($stats['tokens']['candidates'] ?? 0);
            if ($out > $bestOut) {
                $best = $id;
                $bestOut = $out;
            }
        }
        return $best ?: array_key_first($models);
    }
}
