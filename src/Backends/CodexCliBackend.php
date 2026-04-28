<?php

namespace SuperAICore\Backends;

use SuperAICore\Backends\Concerns\BuildsScriptedProcess;
use SuperAICore\Backends\Concerns\StreamableProcess;
use SuperAICore\Contracts\Backend;
use SuperAICore\Contracts\ScriptedSpawnBackend;
use SuperAICore\Contracts\StreamingBackend;
use SuperAICore\Models\AiProvider;
use SuperAICore\Services\CodexModelResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Spawns the `codex` CLI (OpenAI's codex-rs). Uses OPENAI_API_KEY env var.
 *
 * Token usage is extracted from `codex exec --json`, which emits a JSONL
 * event stream terminating in `turn.completed` (or `turn.failed`). The
 * `turn.completed.usage` object carries `input_tokens`, `output_tokens`,
 * and `cached_input_tokens`.
 */
class CodexCliBackend implements Backend, StreamingBackend, ScriptedSpawnBackend
{
    use StreamableProcess;
    use BuildsScriptedProcess;

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

        // Validate requested model against current codex login mode so a
        // stale DB setting (e.g. "gpt-5" on a ChatGPT-auth'd CLI) doesn't
        // fail the run — we substitute the closest compatible model.
        $model = CodexModelResolver::resolve($model, $this->binary);

        // `codex exec -` reads the prompt from stdin instead of the
        // trailing argv. Avoids cmd-line escaping / 8K length limits on
        // Windows for large or markdown-heavy prompts.
        $cmd = [$this->binary, 'exec', '-', '--json', '--full-auto', '--skip-git-repo-check'];
        if ($model) {
            $cmd[] = '--model';
            $cmd[] = $model;
        }

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
            $process->setInput($prompt);
            $process->run();

            if (!$process->isSuccessful()) {
                if ($this->logger) $this->logger->warning('CodexCliBackend failed: ' . $process->getErrorOutput());
                return null;
            }

            $parsed = $this->parseJsonl($process->getOutput());
            if (!$parsed || $parsed['text'] === '') return null;

            return [
                'text'        => $parsed['text'],
                'model'       => $model ?? 'codex-default',
                'usage'       => [
                    'input_tokens'         => $parsed['input_tokens'],
                    'output_tokens'        => $parsed['output_tokens'],
                    'cached_input_tokens'  => $parsed['cached_input_tokens'],
                ],
                'stop_reason' => $parsed['stop_reason'],
            ];
        } catch (\Throwable $e) {
            if ($this->logger) $this->logger->warning("CodexCliBackend error: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Streaming variant — codex's `exec --json` is already JSONL, so the
     * command shape matches generate(). Differences:
     *   - tee'd to a log file for live tailing
     *   - registered with the Process Monitor
     *   - configurable timeouts (host task runs need much longer than 300s)
     *   - chunks fanned to an optional `onChunk` callback for live UI
     *
     * @see Contracts\StreamingBackend for the full options spec.
     */
    public function stream(array $options): ?array
    {
        $providerConfig = $options['provider_config'] ?? [];
        $prompt = $options['prompt'] ?? '';
        if ($prompt === '') return null;

        $model = $options['model'] ?? $providerConfig['model'] ?? null;
        $model = CodexModelResolver::resolve($model, $this->binary);

        // `codex exec -` reads stdin — same rationale as generate().
        $cmd = [$this->binary, 'exec', '-', '--json', '--full-auto', '--skip-git-repo-check'];
        if ($model) {
            $cmd[] = '--model';
            $cmd[] = $model;
        }

        $env = [];
        if (!empty($providerConfig['api_key'])) {
            $env['OPENAI_API_KEY'] = $providerConfig['api_key'];
        }
        if (!empty($providerConfig['base_url'])) {
            $env['OPENAI_BASE_URL'] = $providerConfig['base_url'];
        }

        try {
            $process = new Process($cmd, null, $env);
            $process->setInput($prompt);
            $result = $this->runStreaming(
                process: $process,
                backend: $this->name(),
                commandSummary: $this->binary . ' exec --json --full-auto' . ($model ? " --model {$model}" : ''),
                logFile: $options['log_file'] ?? null,
                timeout: $options['timeout'] ?? null,
                idleTimeout: $options['idle_timeout'] ?? null,
                onChunk: $options['onChunk'] ?? null,
                externalLabel: $options['external_label'] ?? null,
                monitorMetadata: $options['metadata'] ?? [],
                cwd: $options['cwd'] ?? null,
            );
        } catch (\Throwable $e) {
            if ($this->logger) $this->logger->warning("CodexCliBackend stream error: {$e->getMessage()}");
            return null;
        }

        $parsed = $this->parseJsonl($result['captured']);
        if (!$parsed) {
            return [
                'text'        => '',
                'model'       => $model ?? 'codex-default',
                'usage'       => [],
                'log_file'    => $result['log_file'],
                'duration_ms' => $result['duration_ms'],
                'exit_code'   => $result['exit_code'],
            ];
        }

        return [
            'text'        => $parsed['text'],
            'model'       => $model ?? 'codex-default',
            'usage'       => [
                'input_tokens'        => $parsed['input_tokens'],
                'output_tokens'       => $parsed['output_tokens'],
                'cached_input_tokens' => $parsed['cached_input_tokens'],
            ],
            'stop_reason' => $parsed['stop_reason'],
            'log_file'    => $result['log_file'],
            'duration_ms' => $result['duration_ms'],
            'exit_code'   => $result['exit_code'],
        ];
    }

    /**
     * Parse the JSONL event stream codex-rs emits under `exec --json`.
     * Public for testing.
     *
     * Events observed (0.x schema, 2026-04):
     *   {"type":"thread.started","thread_id":"..."}
     *   {"type":"turn.started"}
     *   {"type":"item.completed","item":{"type":"agent_message","text":"..."}}
     *   {"type":"turn.completed","usage":{"input_tokens":N,"output_tokens":N,"cached_input_tokens":N}}
     *   {"type":"turn.failed","error":{"message":"..."}}
     *
     * @return array{text:string, input_tokens:int, output_tokens:int, cached_input_tokens:int, stop_reason:?string}|null
     */
    public function parseJsonl(string $output): ?array
    {
        $text = '';
        $inputTokens = 0;
        $outputTokens = 0;
        $cachedInputTokens = 0;
        $stopReason = null;
        $sawAny = false;

        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '{') continue;
            $event = json_decode($line, true);
            if (!is_array($event)) continue;
            $sawAny = true;

            $type = $event['type'] ?? '';

            switch ($type) {
                case 'item.completed':
                    $item = $event['item'] ?? [];
                    if (($item['type'] ?? '') === 'agent_message' && isset($item['text']) && is_string($item['text'])) {
                        $text .= $item['text'];
                    }
                    break;

                case 'turn.completed':
                    $stopReason = 'end_turn';
                    $usage = $event['usage'] ?? [];
                    $inputTokens       = (int) ($usage['input_tokens'] ?? 0);
                    $outputTokens      = (int) ($usage['output_tokens'] ?? 0);
                    $cachedInputTokens = (int) ($usage['cached_input_tokens'] ?? 0);
                    break;

                case 'turn.failed':
                case 'error':
                    $stopReason = 'error';
                    break;
            }
        }

        if (!$sawAny) return null;

        return [
            'text'                => trim($text),
            'input_tokens'        => $inputTokens,
            'output_tokens'       => $outputTokens,
            'cached_input_tokens' => $cachedInputTokens,
            'stop_reason'         => $stopReason,
        ];
    }

    // ─── ScriptedSpawnBackend ──────────────────────────────────────────

    /**
     * Codex scripted spawn. Stdin-pipes the prompt via codex's literal
     * `-` sentinel. Emits a companion `<logFile>-last.txt` so the host
     * can extract the final assistant message without re-parsing the
     * full JSONL stream.
     *
     * Consumes `engine_extra_args: string[]` — each string becomes a
     * separate `-c <kv>` pair. Hosts use this for provider-specific
     * `model_provider=...` / MCP `mcp_servers.<k>.*` entries that
     * depend on the selected AiProvider row. The legacy
     * `codex_extra_config_args` key is still accepted for backwards
     * compatibility.
     */
    public function prepareScriptedProcess(array $options): Process
    {
        $promptFile  = $options['prompt_file']  ?? throw new \InvalidArgumentException('prompt_file required');
        $logFile     = $options['log_file']     ?? throw new \InvalidArgumentException('log_file required');
        $projectRoot = $options['project_root'] ?? throw new \InvalidArgumentException('project_root required');
        $model       = $options['model']        ?? null;
        $env         = (array) ($options['env'] ?? []);

        $lastMessageFile = str_replace('.log', '-last.txt', $logFile);
        $configArgs = (array) ($options['engine_extra_args']
            ?? $options['codex_extra_config_args']
            ?? []);

        $resolvedModel = $model
            ? CodexModelResolver::resolve($model, app(\SuperAICore\Support\CliBinaryLocator::class)->find(AiProvider::BACKEND_CODEX))
            : null;

        $flags = [
            'exec',
            '--json',
            '--full-auto',
            '--skip-git-repo-check',
            '-C', $projectRoot,
            '-o', $lastMessageFile,
        ];
        if ($resolvedModel) {
            $flags[] = '-m';
            $flags[] = $resolvedModel;
        }
        foreach ($configArgs as $configArg) {
            $flags[] = '-c';
            $flags[] = (string) $configArg;
        }
        foreach ((array) ($options['extra_cli_flags'] ?? []) as $f) {
            $flags[] = (string) $f;
        }
        $flags[] = '-'; // stdin sentinel

        return $this->buildWrappedProcess(
            engineKey:      AiProvider::BACKEND_CODEX,
            promptFile:     $promptFile,
            logFile:        $logFile,
            projectRoot:    $projectRoot,
            cliFlagsString: $this->escapeFlags($flags),
            env:            $env,
            envUnsetExtras: [],
            timeout:        $options['timeout']      ?? null,
            idleTimeout:    $options['idle_timeout'] ?? null,
        );
    }

    /**
     * One-shot chat — codex `exec --ephemeral --sandbox read-only` with
     * tool surface disabled. Streams plain text.
     */
    public function streamChat(string $prompt, callable $onChunk, array $options = []): string
    {
        $cliPath = app(\SuperAICore\Support\CliBinaryLocator::class)->find(AiProvider::BACKEND_CODEX);

        $args = [
            $cliPath, 'exec', '--full-auto', '--skip-git-repo-check', '--ephemeral',
            '--sandbox', 'read-only',
            '--disable', 'plugins',
            '--disable', 'shell_tool',
            '--disable', 'multi_agent',
            '--disable', 'tool_call_mcp_elicitation',
            '--disable', 'tool_suggest',
            '--disable', 'skill_mcp_dependency_install',
            '-c', 'model_reasoning_effort="low"',
        ];
        $resolvedModel = !empty($options['model'])
            ? CodexModelResolver::resolve($options['model'], $cliPath)
            : null;
        if ($resolvedModel) {
            $args[] = '-m';
            $args[] = $resolvedModel;
        }
        $args[] = '-';

        $env = (array) ($options['env'] ?? []);
        $process = new Process($args, $options['cwd'] ?? null, $env);
        $process->setTimeout((int) ($options['timeout'] ?? 0));
        $process->setIdleTimeout((int) ($options['idle_timeout'] ?? 300));
        $process->setInput($prompt);

        $fullResponse = '';
        $process->start();
        $process->wait(function (string $type, string $data) use (&$fullResponse, $onChunk) {
            if ($type !== Process::OUT) return;
            $fullResponse .= $data;
            $onChunk($data);
        });

        $this->assertChatExit($process, $fullResponse, 'Codex');
        return $fullResponse;
    }
}
