<?php

namespace SuperAICore\Backends;

use SuperAICore\Contracts\Backend;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Spawns the `claude` CLI binary with env vars set per provider config.
 *
 * Supports:
 *   - Direct Anthropic login (ANTHROPIC_API_KEY)
 *   - Anthropic proxy (ANTHROPIC_BASE_URL + key)
 *   - AWS Bedrock (CLAUDE_CODE_USE_BEDROCK=1 + AWS_* vars)
 *   - Google Vertex (CLAUDE_CODE_USE_VERTEX=1 + project vars)
 *   - Built-in (local claude login — no env vars)
 */
class ClaudeCliBackend implements Backend
{
    public function __construct(
        protected string $binary = 'claude',
        protected int $timeout = 300,
        protected ?LoggerInterface $logger = null,
    ) {}

    public function name(): string
    {
        return 'claude_cli';
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
        if ($prompt === '' && !empty($options['messages'])) {
            $prompt = $this->messagesToPrompt($options['messages']);
        }

        $model = $options['model'] ?? $providerConfig['model'] ?? null;

        // --output-format=json gives us a single-line JSON envelope with
        // the assistant's text (.result), per-turn token usage (.usage),
        // and per-model breakdown (.modelUsage). Everything the
        // CostCalculator downstream needs for real USD attribution —
        // as opposed to the $0 placeholder this backend used to emit.
        $cmd = [$this->binary, '--print', '--output-format=json'];
        if ($model) {
            $cmd[] = '--model';
            $cmd[] = $model;
        }
        if (!empty($options['system'])) {
            $cmd[] = '--system-prompt';
            $cmd[] = $options['system'];
        }
        $cmd[] = $prompt;

        try {
            $env = $this->buildEnv($providerConfig);
            $process = new Process($cmd, null, $env);
            $process->setTimeout($this->timeout);
            $process->run();

            if (!$process->isSuccessful()) {
                if ($this->logger) $this->logger->warning('ClaudeCliBackend failed: ' . $process->getErrorOutput());
                return null;
            }

            $parsed = $this->parseJson($process->getOutput());
            if (!$parsed || $parsed['text'] === '') return null;

            return [
                'text'        => $parsed['text'],
                'model'       => $parsed['model'] ?? $model ?? 'claude-cli-default',
                'usage'       => [
                    'input_tokens'  => $parsed['input_tokens'],
                    'output_tokens' => $parsed['output_tokens'],
                    'cache_read_input_tokens'     => $parsed['cache_read_input_tokens'],
                    'cache_creation_input_tokens' => $parsed['cache_creation_input_tokens'],
                    'total_cost_usd' => $parsed['total_cost_usd'],
                ],
                'stop_reason' => $parsed['stop_reason'],
            ];
        } catch (\Throwable $e) {
            if ($this->logger) $this->logger->warning("ClaudeCliBackend error: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Parse the one-shot JSON envelope Claude Code emits under
     * `--output-format=json`. Public for testing — callers that already
     * have captured output can reuse the parser without spawning a process.
     *
     * Model selection strategy: the final "main" model is the modelUsage
     * key with the highest cost (cheap side-calls like haiku for internal
     * routing inflate token counts but not cost). Falls back to the first
     * key when cost isn't present.
     *
     * @return array{text:string, model:?string, input_tokens:int, output_tokens:int, cache_read_input_tokens:int, cache_creation_input_tokens:int, total_cost_usd:float, stop_reason:?string}|null
     */
    public function parseJson(string $output): ?array
    {
        $output = trim($output);
        if ($output === '' || $output[0] !== '{') return null;

        $data = json_decode($output, true);
        if (!is_array($data) || ($data['type'] ?? '') !== 'result') return null;

        $usage = $data['usage'] ?? [];
        $modelUsage = $data['modelUsage'] ?? [];

        return [
            'text'                         => is_string($data['result'] ?? null) ? $data['result'] : '',
            'model'                        => $this->pickPrimaryModel($modelUsage),
            'input_tokens'                 => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens'                => (int) ($usage['output_tokens'] ?? 0),
            'cache_read_input_tokens'      => (int) ($usage['cache_read_input_tokens'] ?? 0),
            'cache_creation_input_tokens'  => (int) ($usage['cache_creation_input_tokens'] ?? 0),
            'total_cost_usd'               => (float) ($data['total_cost_usd'] ?? 0.0),
            'stop_reason'                  => $data['stop_reason'] ?? null,
        ];
    }

    private function pickPrimaryModel(array $modelUsage): ?string
    {
        if (!$modelUsage) return null;
        $best = null;
        $bestCost = -1.0;
        foreach ($modelUsage as $model => $stats) {
            $cost = (float) ($stats['costUSD'] ?? 0);
            if ($cost > $bestCost) {
                $best = $model;
                $bestCost = $cost;
            }
        }
        return $best ?: array_key_first($modelUsage);
    }

    /**
     * Build env vars for claude CLI invocation based on provider type.
     */
    protected function buildEnv(array $providerConfig): array
    {
        $env = [];
        $type = $providerConfig['type'] ?? 'builtin';

        switch ($type) {
            case 'anthropic':
                if (!empty($providerConfig['api_key'])) {
                    $env['ANTHROPIC_API_KEY'] = $providerConfig['api_key'];
                }
                break;

            case 'anthropic-proxy':
                if (!empty($providerConfig['api_key'])) {
                    $env['ANTHROPIC_API_KEY'] = $providerConfig['api_key'];
                }
                if (!empty($providerConfig['base_url'])) {
                    $env['ANTHROPIC_BASE_URL'] = $providerConfig['base_url'];
                }
                break;

            case 'bedrock':
                $env['CLAUDE_CODE_USE_BEDROCK'] = '1';
                $extra = $providerConfig['extra_config'] ?? [];
                if (!empty($extra['region'])) $env['AWS_REGION'] = $extra['region'];
                if (!empty($extra['access_key_id'])) $env['AWS_ACCESS_KEY_ID'] = $extra['access_key_id'];
                if (!empty($extra['secret_access_key'])) $env['AWS_SECRET_ACCESS_KEY'] = $extra['secret_access_key'];
                break;

            case 'vertex':
                $env['CLAUDE_CODE_USE_VERTEX'] = '1';
                $extra = $providerConfig['extra_config'] ?? [];
                if (!empty($extra['project_id'])) $env['ANTHROPIC_VERTEX_PROJECT_ID'] = $extra['project_id'];
                if (!empty($extra['region'])) $env['CLOUD_ML_REGION'] = $extra['region'];
                break;

            case 'builtin':
            default:
                // Use local Claude Code login — no extra env
                break;
        }

        return $env;
    }

    protected function messagesToPrompt(array $messages): string
    {
        $parts = [];
        foreach ($messages as $m) {
            $role = strtoupper($m['role'] ?? 'user');
            $content = is_string($m['content'] ?? '') ? $m['content'] : json_encode($m['content']);
            $parts[] = "{$role}: {$content}";
        }
        return implode("\n\n", $parts);
    }
}
