<?php

namespace SuperAICore\Backends;

use SuperAICore\Backends\Concerns\StreamableProcess;
use SuperAICore\Contracts\Backend;
use SuperAICore\Contracts\StreamingBackend;
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
class ClaudeCliBackend implements Backend, StreamingBackend
{
    use StreamableProcess;

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
     * Long-running variant of generate() — spawns claude with streaming
     * NDJSON output (`--output-format=stream-json --verbose`), tees every
     * chunk to a log file, optionally pipes them to a host-supplied
     * `onChunk` callback for live UI updates, and registers the subprocess
     * with the Process Monitor.
     *
     * Differences from generate():
     *   - `--output-format=stream-json` instead of `=json` so the host can
     *     tail the log file as the model emits events (instead of seeing
     *     one giant blob at the very end).
     *   - `--mcp-config <empty.json> --strict-mcp-config` when
     *     `mcp_mode === 'empty'` — prevents claude from auto-spawning
     *     every globally-registered MCP server at startup, which can
     *     otherwise keep claude alive after its final stream event and
     *     block the parent from observing exit. `mcp_mode === 'file'`
     *     uses an explicit `mcp_config_file`. Default `'inherit'` lets
     *     claude pick up its global MCP set as usual.
     *   - Configurable timeout / idle_timeout — production task runs
     *     need much longer than the 300s `generate()` default.
     *
     * @see Contracts\StreamingBackend for the full options spec.
     */
    public function stream(array $options): ?array
    {
        $providerConfig = $options['provider_config'] ?? [];
        $prompt = $options['prompt'] ?? '';
        if ($prompt === '' && !empty($options['messages'])) {
            $prompt = $this->messagesToPrompt($options['messages']);
        }
        if ($prompt === '') return null;

        $model = $options['model'] ?? $providerConfig['model'] ?? null;

        // Stream-json mode emits one JSON event per line — `system_init`,
        // `assistant_*` deltas, then a terminal `result` event with the
        // final usage envelope. Parser walks the captured log at the end.
        $cmd = [$this->binary, '-p', '--output-format=stream-json', '--verbose'];
        if ($model) {
            $cmd[] = '--model';
            $cmd[] = $model;
        }
        if (!empty($options['system'])) {
            $cmd[] = '--system-prompt';
            $cmd[] = $options['system'];
        }

        // MCP injection — see method doc.
        $mcpMode = $options['mcp_mode'] ?? 'inherit';
        $mcpTempFile = null;
        if ($mcpMode === 'empty') {
            $mcpTempFile = tempnam(sys_get_temp_dir(), 'sac-mcp-empty-') . '.json';
            file_put_contents($mcpTempFile, '{"mcpServers":{}}');
            $cmd[] = '--mcp-config';
            $cmd[] = $mcpTempFile;
            $cmd[] = '--strict-mcp-config';
        } elseif ($mcpMode === 'file' && !empty($options['mcp_config_file'])) {
            $cmd[] = '--mcp-config';
            $cmd[] = (string) $options['mcp_config_file'];
            $cmd[] = '--strict-mcp-config';
        }

        $cmd[] = $prompt;

        try {
            $env = $this->buildEnv($providerConfig);
            $process = new Process($cmd, null, $env);

            $result = $this->runStreaming(
                process: $process,
                backend: $this->name(),
                commandSummary: $this->binary . ' -p --output-format=stream-json' . ($model ? " --model {$model}" : ''),
                logFile: $options['log_file'] ?? null,
                timeout: $options['timeout'] ?? null,
                idleTimeout: $options['idle_timeout'] ?? null,
                onChunk: $options['onChunk'] ?? null,
                externalLabel: $options['external_label'] ?? null,
                monitorMetadata: $options['metadata'] ?? [],
            );
        } catch (\Throwable $e) {
            if ($mcpTempFile && file_exists($mcpTempFile)) @unlink($mcpTempFile);
            if ($this->logger) $this->logger->warning("ClaudeCliBackend stream error: {$e->getMessage()}");
            return null;
        } finally {
            if ($mcpTempFile && file_exists($mcpTempFile)) @unlink($mcpTempFile);
        }

        $parsed = $this->parseStreamJson($result['captured']);
        if (!$parsed) {
            if ($this->logger) $this->logger->warning('ClaudeCliBackend stream produced no parsable result event');
            return [
                'text'        => '',
                'model'       => $model ?? 'claude-cli-default',
                'usage'       => [],
                'log_file'    => $result['log_file'],
                'duration_ms' => $result['duration_ms'],
                'exit_code'   => $result['exit_code'],
            ];
        }

        return [
            'text'        => $parsed['text'],
            'model'       => $parsed['model'] ?? $model ?? 'claude-cli-default',
            'usage'       => [
                'input_tokens'                => $parsed['input_tokens'],
                'output_tokens'               => $parsed['output_tokens'],
                'cache_read_input_tokens'     => $parsed['cache_read_input_tokens'],
                'cache_creation_input_tokens' => $parsed['cache_creation_input_tokens'],
                'total_cost_usd'              => $parsed['total_cost_usd'],
            ],
            'stop_reason' => $parsed['stop_reason'],
            'log_file'    => $result['log_file'],
            'duration_ms' => $result['duration_ms'],
            'exit_code'   => $result['exit_code'],
        ];
    }

    /**
     * Walk a captured NDJSON stream (the body of a `--output-format=stream-json`
     * run) and pull the LAST `result` event's usage envelope. Multiple result
     * events can occur in long sessions (e.g. Anthropic streaming mid-pass
     * recoveries); we always trust the final one as authoritative.
     *
     * Public for testing — host parsers that already capture the same NDJSON
     * shape (PPT pipeline, etc.) can reuse this without spawning a process.
     *
     * @return array{text:string, model:?string, input_tokens:int, output_tokens:int, cache_read_input_tokens:int, cache_creation_input_tokens:int, total_cost_usd:float, stop_reason:?string, num_turns:int, session_id:?string}|null
     */
    public function parseStreamJson(string $output): ?array
    {
        $last = null;
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '{') continue;
            try {
                $event = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (($event['type'] ?? null) === 'result') {
                $last = $event;
            }
        }

        if (!$last) return null;

        $usage = $last['usage'] ?? [];
        $modelUsage = $last['modelUsage'] ?? [];

        return [
            'text'                         => is_string($last['result'] ?? null) ? $last['result'] : '',
            'model'                        => $this->pickPrimaryModel($modelUsage),
            'input_tokens'                 => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens'                => (int) ($usage['output_tokens'] ?? 0),
            'cache_read_input_tokens'      => (int) ($usage['cache_read_input_tokens'] ?? 0),
            'cache_creation_input_tokens'  => (int) ($usage['cache_creation_input_tokens'] ?? 0),
            'total_cost_usd'               => (float) ($last['total_cost_usd'] ?? 0.0),
            'stop_reason'                  => $last['stop_reason'] ?? null,
            'num_turns'                    => (int) ($last['num_turns'] ?? 0),
            'session_id'                   => $last['session_id'] ?? null,
        ];
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
