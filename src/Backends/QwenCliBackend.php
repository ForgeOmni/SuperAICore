<?php

namespace SuperAICore\Backends;

use SuperAICore\Backends\Concerns\BuildsScriptedProcess;
use SuperAICore\Backends\Concerns\StreamableProcess;
use SuperAICore\Contracts\Backend;
use SuperAICore\Contracts\ScriptedSpawnBackend;
use SuperAICore\Contracts\StreamingBackend;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Spawns Alibaba's `qwen` CLI (QwenLM/qwen-code v0.16.0, 2026-05-21).
 *
 * Qwen Code is a fork of gemini-cli adapted for the Qwen model family.
 * Its argv surface mirrors gemini-cli closely (`--prompt`, `--model`,
 * `--output-format=json`, `--yolo`), so this backend's command shape is
 * nearly identical to GeminiCliBackend.
 *
 * Authentication:
 *   - **OAuth removed 2026-04-15** (Qwen OAuth free tier discontinued).
 *     This backend only supports API key authentication via env var.
 *     Users on Alibaba Cloud Coding Plan use the upstream `qwen` CLI's
 *     subscription path; this adapter is API-key only.
 *   - `DASHSCOPE_API_KEY` (preferred) or `QWEN_API_KEY` env var.
 *   - Custom base URL via `DASHSCOPE_BASE_URL` for non-default regions.
 *
 * Model recommendations:
 *   - `qwen3.7-max` — 1M context, $2.50/$7.50 per 1M; supports Anthropic
 *     API protocol natively (drop-in for Claude Code in fallback chains).
 *   - `qwen3-coder-plus` — coding-optimised, cheaper.
 *   - `qwen3.7-plus` — vision variant.
 *
 * Stream-json events: qwen-code v0.16.0 emits gemini-cli-shaped JSON
 * objects on stdout when `--output-format=stream-json` is passed, one
 * per line. Parsing is the same as Gemini's.
 */
class QwenCliBackend implements Backend, StreamingBackend, ScriptedSpawnBackend
{
    use StreamableProcess;
    use BuildsScriptedProcess;

    public function __construct(
        protected string $binary = 'qwen',
        protected int $timeout = 300,
        protected ?LoggerInterface $logger = null,
    ) {}

    public function name(): string
    {
        return 'qwen_cli';
    }

    public function isAvailable(array $providerConfig = []): bool
    {
        // 1. Binary must exist on PATH
        $process = new Process(['which', $this->binary]);
        $process->run();
        if (!$process->isSuccessful()) return false;

        // 2. Auth must be configured. Qwen OAuth was EOL'd 2026-04-15;
        //    only API key (env or providerConfig) is supported. Returning
        //    false here surfaces a clean "not configured" routing miss
        //    instead of a 401 mid-dispatch.
        if (!empty($providerConfig['api_key'])) return true;
        if (getenv('DASHSCOPE_API_KEY')) return true;
        if (getenv('QWEN_API_KEY')) return true;
        return false;
    }

    public function generate(array $options): ?array
    {
        $providerConfig = $options['provider_config'] ?? [];
        $prompt = $options['prompt'] ?? '';
        $model = $options['model'] ?? $providerConfig['model'] ?? 'qwen3.7-max';

        // `qwen --prompt ""` reads from stdin — same idiom as gemini.
        $cmd = [$this->binary, '--output-format=json', '--yolo'];
        if ($model) {
            $cmd[] = '--model';
            $cmd[] = $model;
        }
        $cmd[] = '--prompt';
        $cmd[] = '';

        $env = [];
        $apiKey = $providerConfig['api_key'] ?? null;
        if ($apiKey) {
            // qwen-code looks at both names; set both for forward-compat.
            $env['DASHSCOPE_API_KEY'] = $apiKey;
            $env['QWEN_API_KEY']      = $apiKey;
        }
        if (!empty($providerConfig['base_url'])) {
            $env['DASHSCOPE_BASE_URL'] = (string) $providerConfig['base_url'];
        }
        // Region override: qwen-code reads DASHSCOPE_REGION to pick host.
        $extra = $providerConfig['extra_config'] ?? [];
        if (!empty($extra['region'])) {
            $env['DASHSCOPE_REGION'] = (string) $extra['region'];
        }

        try {
            $process = new Process($cmd, $options['cwd'] ?? null, $env);
            $process->setTimeout($this->timeout);
            $process->setInput($prompt);
            $process->run();

            if (!$process->isSuccessful()) {
                if ($this->logger) {
                    $this->logger->warning('QwenCliBackend failed: ' . $process->getErrorOutput());
                }
                return null;
            }

            $parsed = $this->parseJson($process->getOutput());
            if (!$parsed || $parsed['text'] === '') return null;

            return [
                'text'        => $parsed['text'],
                'model'       => $parsed['model'] ?? $model ?? 'qwen3.7-max',
                'usage'       => [
                    'input_tokens'  => $parsed['input_tokens']  ?? 0,
                    'output_tokens' => $parsed['output_tokens'] ?? 0,
                ],
                'stop_reason' => $parsed['stop_reason'] ?? null,
            ];
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->warning("QwenCliBackend error: {$e->getMessage()}");
            }
            return null;
        }
    }

    public function stream(array $options): ?array
    {
        $providerConfig = $options['provider_config'] ?? [];
        $prompt = $options['prompt'] ?? '';
        $model = $options['model'] ?? $providerConfig['model'] ?? 'qwen3.7-max';

        $cmd = [$this->binary, '--output-format=stream-json', '--yolo'];
        if ($model) {
            $cmd[] = '--model';
            $cmd[] = $model;
        }
        $cmd[] = '--prompt';
        $cmd[] = '';

        $env = [];
        if (!empty($providerConfig['api_key'])) {
            $env['DASHSCOPE_API_KEY'] = $providerConfig['api_key'];
            $env['QWEN_API_KEY']      = $providerConfig['api_key'];
        }
        if (!empty($providerConfig['base_url'])) {
            $env['DASHSCOPE_BASE_URL'] = (string) $providerConfig['base_url'];
        }

        $process = new Process($cmd, $options['cwd'] ?? null, $env);
        $process->setInput($prompt);

        try {
            $result = $this->runStreaming(
                process:         $process,
                backend:         $this->name(),
                commandSummary:  $this->binary . ' --output-format=stream-json --prompt' . ($model ? " --model {$model}" : ''),
                logFile:         $options['log_file']    ?? null,
                timeout:         $options['timeout']     ?? null,
                idleTimeout:     $options['idle_timeout'] ?? null,
                onChunk:         $options['onChunk']     ?? null,
                externalLabel:   $options['external_label'] ?? null,
                monitorMetadata: $options['metadata']    ?? [],
                cwd:             $options['cwd']         ?? null,
            );
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->warning("QwenCliBackend stream error: {$e->getMessage()}");
            }
            return null;
        }

        $parsed = $this->parseStreamJson($result['captured']);

        return [
            'text'        => $parsed['text'],
            'model'       => $parsed['model'] ?? $model ?? 'qwen3.7-max',
            'usage'       => [
                'input_tokens'  => $parsed['input_tokens']  ?? 0,
                'output_tokens' => $parsed['output_tokens'] ?? 0,
            ],
            'stop_reason' => $parsed['stop_reason'] ?? null,
            'log_file'    => $result['log_file'],
            'duration_ms' => $result['duration_ms'],
            'exit_code'   => $result['exit_code'],
        ];
    }

    public function prepareScriptedProcess(array $options): Process
    {
        $promptFile  = $options['prompt_file']  ?? throw new \InvalidArgumentException('prompt_file required');
        $logFile     = $options['log_file']     ?? throw new \InvalidArgumentException('log_file required');
        $projectRoot = $options['project_root'] ?? throw new \InvalidArgumentException('project_root required');

        $flags = ['--output-format=stream-json', '--yolo', '--workspace', $projectRoot];
        if (!empty($options['model'])) {
            $flags[] = '--model';
            $flags[] = (string) $options['model'];
        }
        foreach ((array) ($options['extra_cli_flags'] ?? []) as $f) $flags[] = (string) $f;

        return $this->buildWrappedProcess(
            engineKey:      'qwen',
            promptFile:     $promptFile,
            logFile:        $logFile,
            projectRoot:    $projectRoot,
            cliFlagsString: $this->escapeFlags($flags),
            env:            (array) ($options['env'] ?? []),
            envUnsetExtras: [],
            timeout:        $options['timeout']      ?? null,
            idleTimeout:    $options['idle_timeout'] ?? null,
            stdinMode:      'file',  // prompt comes via promptFile
        );
    }

    /**
     * Parse `--output-format=json` single-blob response (qwen-code v0.16.0
     * format follows gemini-cli: `{response: "...", stats: {...}, model}`).
     */
    protected function parseJson(string $raw): ?array
    {
        $data = json_decode(trim($raw), true);
        if (!is_array($data)) return null;
        $text = $data['response'] ?? '';
        $model = $data['stats']['models'] ?? null;
        $main = null;
        if (is_array($model)) {
            // Pick the model with role "main" (vs router/auxiliary)
            foreach ($model as $modelId => $stats) {
                if (!is_array($stats)) continue;
                $main ??= $modelId;
                if (($stats['role'] ?? '') === 'main') { $main = $modelId; break; }
            }
        }
        $tokens = $data['stats']['models'][$main]['tokens'] ?? [];
        return [
            'text'           => (string) $text,
            'model'          => (string) ($main ?? 'qwen3.7-max'),
            'input_tokens'   => (int) ($tokens['prompt'] ?? $tokens['input'] ?? 0),
            'output_tokens'  => (int) ($tokens['response'] ?? $tokens['output'] ?? 0),
            'stop_reason'    => (string) ($data['stop_reason'] ?? 'end_turn'),
        ];
    }

    /**
     * Parse NDJSON stream-json events.
     *
     * VERIFIED against qwen-code v0.16.0:
     *   packages/cli/src/nonInteractive/io/BaseJsonOutputAdapter.ts +
     *   packages/cli/src/nonInteractive/io/StreamJsonOutputAdapter.ts
     *
     * The outer envelope is one of:
     *   - {type: 'system', subtype, ...}                  — session events
     *   - {type: 'user', message: {...}}                  — user turn
     *   - {type: 'assistant', message: {role, model,
     *        content: [blocks], stop_reason, usage}}      — finalised assistant turn
     *   - {type: 'tool_use'} / {type: 'tool_result'}      — tool lifecycle
     *   - {type: 'result', subtype, is_error, usage,
     *        duration_ms, duration_api_ms, ...}           — terminal result
     *
     * Inside an assistant 'message.content' block:
     *   - {type: 'text', text: '...'}
     *   - {type: 'thinking', thinking: '...'}
     *   - {type: 'tool_use', id, name, input}
     *
     * In partial-messages stream mode there are also finer events:
     *   - content_block_start / content_block_delta / content_block_stop
     *   - message_start / message_stop / tool_progress
     *
     * We pick the final `assistant` envelope's text (last wins) and the
     * `result` envelope's usage for token counts. Partial-message events
     * are folded into running text but the assistant envelope still wins.
     */
    protected function parseStreamJson(string $output): array
    {
        $text = '';
        $partialText = '';
        $inputTokens = 0;
        $outputTokens = 0;
        $model = null;
        $stopReason = null;

        $lines = preg_split('/\r\n|\n|\r/', trim($output)) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '{') continue;
            $event = json_decode($line, true);
            if (!is_array($event)) continue;
            $type = $event['type'] ?? null;

            // Final assistant message — last wins, replaces partial text
            if ($type === 'assistant') {
                $msg = $event['message'] ?? [];
                $model ??= $msg['model'] ?? null;
                $stopReason = $msg['stop_reason'] ?? $stopReason;
                $turnText = '';
                foreach ((array) ($msg['content'] ?? []) as $block) {
                    if (!is_array($block)) continue;
                    if (($block['type'] ?? '') === 'text' && isset($block['text'])) {
                        $turnText .= (string) $block['text'];
                    }
                }
                if ($turnText !== '') $text = $turnText;
                // Per-turn usage on the assistant envelope itself
                $usage = $msg['usage'] ?? null;
                if (is_array($usage)) {
                    $inputTokens  = (int) ($usage['input_tokens']  ?? $inputTokens);
                    $outputTokens = (int) ($usage['output_tokens'] ?? $outputTokens);
                }
                continue;
            }

            // Terminal result frame — most authoritative usage
            if ($type === 'result') {
                $usage = $event['usage'] ?? null;
                if (is_array($usage)) {
                    $inputTokens  = (int) ($usage['input_tokens']  ?? $inputTokens);
                    $outputTokens = (int) ($usage['output_tokens'] ?? $outputTokens);
                }
                $stopReason = $event['stop_reason'] ?? $stopReason;
                continue;
            }

            // Partial-messages stream events (Anthropic-shape passthrough)
            if ($type === 'content_block_delta') {
                $delta = $event['delta'] ?? [];
                if (($delta['type'] ?? '') === 'text_delta' && isset($delta['text'])) {
                    $partialText .= (string) $delta['text'];
                }
            }
        }

        // If we never saw a final assistant envelope but accumulated
        // partial deltas, fall back to those.
        if ($text === '' && $partialText !== '') {
            $text = $partialText;
        }

        return [
            'text'          => $text,
            'model'         => $model,
            'input_tokens'  => $inputTokens,
            'output_tokens' => $outputTokens,
            'stop_reason'   => $stopReason,
        ];
    }
}
