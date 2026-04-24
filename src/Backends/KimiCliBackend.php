<?php

namespace SuperAICore\Backends;

use SuperAICore\Backends\Concerns\BuildsScriptedProcess;
use SuperAICore\Backends\Concerns\StreamableProcess;
use SuperAICore\Contracts\Backend;
use SuperAICore\Contracts\ScriptedSpawnBackend;
use SuperAICore\Contracts\StreamingBackend;
use SuperAICore\Models\AiProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Spawns Moonshot AI's `kimi` CLI (MoonshotAI/kimi-cli) in headless mode.
 *
 * Authentication model (MVP-1): only `moonshot-builtin` — `kimi login`
 * has already populated `~/.kimi/credentials/kimi-code.json` with the
 * OAuth token. No env injection, same as Claude / Gemini / Kiro builtin.
 * A direct-HTTP `api_key` channel against api.moonshot.ai is intentionally
 * NOT exposed here — that path routes through the `superagent` backend
 * via the SDK's KimiProvider under separate provider types.
 *
 * Headless surface (verified against kimi v1.38.0, 2026-04-22):
 *   - `--print` is a boolean flag (no value); implicitly enables `--yolo`
 *   - `--output-format stream-json` → NDJSON on stdout, one line per event
 *   - `--prompt "..."` (or `-p`) delivers the user message
 *   - `--work-dir <dir>` (`-w`) overrides cwd independently of env
 *   - `--max-steps-per-turn <N>` caps the agentic loop (default 500)
 *   - `--mcp-config-file <path>` repeatable, per-run MCP injection
 *
 * Stream-json event shapes (three observed on a Shell tool-use turn):
 *   1. `{"role":"assistant","content":[{"type":"think","think":"..."},
 *        {"type":"text","text":"..."}], "tool_calls":[{ "type":"function",
 *        "id":"tool_XXX","function":{"name":"Shell","arguments":"{...}"}}]}`
 *   2. `{"role":"tool","content":[{"type":"text","text":"..."}],
 *        "tool_call_id":"tool_XXX"}`
 *   3. `{"role":"assistant","content":[{"type":"text","text":"final"}]}`
 *
 * **Usage is NOT reported** in stream-json. We emit zero token counts in
 * the envelope; `CostCalculator` treats Kimi as subscription-billed and
 * returns $0. A char-count shadow-cost estimator is an MVP-2 follow-up.
 *
 * The resume-session hint (`To resume this session: kimi -r <uuid>`) is
 * emitted on stderr, not stdout — it does not pollute the NDJSON stream.
 */
class KimiCliBackend implements Backend, StreamingBackend, ScriptedSpawnBackend
{
    use StreamableProcess;
    use BuildsScriptedProcess;

    public function __construct(
        protected string $binary = 'kimi',
        protected int $timeout = 300,
        protected int $maxStepsPerTurn = 500,
        protected ?LoggerInterface $logger = null,
    ) {}

    public function name(): string
    {
        return 'kimi_cli';
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
        if ($prompt === '') return null;

        $cmd = $this->buildCommand($options, $providerConfig, $prompt);

        try {
            $process = new Process($cmd, $options['cwd'] ?? null);
            $process->setTimeout($this->timeout);
            $process->run();

            if (!$process->isSuccessful()) {
                if ($this->logger) {
                    $this->logger->warning(
                        'KimiCliBackend failed: ' . $process->getErrorOutput(),
                    );
                }
                return null;
            }

            $parsed = $this->parseStreamJson($process->getOutput());
            if ($parsed['text'] === '') return null;

            return [
                'text'  => $parsed['text'],
                'model' => $options['model']
                    ?? $providerConfig['model']
                    ?? 'kimi-code/kimi-for-coding',
                'usage' => [
                    'input_tokens'  => 0,
                    'output_tokens' => 0,
                ],
                'stop_reason' => null,
            ];
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->warning("KimiCliBackend error: {$e->getMessage()}");
            }
            return null;
        }
    }

    public function stream(array $options): ?array
    {
        $providerConfig = $options['provider_config'] ?? [];
        $prompt = $options['prompt'] ?? '';
        if ($prompt === '' && !empty($options['messages'])) {
            $prompt = $this->messagesToPrompt($options['messages']);
        }
        if ($prompt === '') return null;

        $cmd = $this->buildCommand($options, $providerConfig, $prompt);

        try {
            $process = new Process($cmd, $options['cwd'] ?? null);
            $model = $options['model']
                ?? $providerConfig['model']
                ?? 'kimi-code/kimi-for-coding';

            $result = $this->runStreaming(
                process:         $process,
                backend:         $this->name(),
                commandSummary:  $this->binary . ' --print --output-format=stream-json -p' . ($model ? " --model {$model}" : ''),
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
                $this->logger->warning("KimiCliBackend stream error: {$e->getMessage()}");
            }
            return null;
        }

        $parsed = $this->parseStreamJson($result['captured']);

        return [
            'text'        => $parsed['text'],
            'model'       => $options['model']
                ?? $providerConfig['model']
                ?? 'kimi-code/kimi-for-coding',
            'usage'       => [
                'input_tokens'  => 0,
                'output_tokens' => 0,
            ],
            'stop_reason' => null,
            'log_file'    => $result['log_file'],
            'duration_ms' => $result['duration_ms'],
            'exit_code'   => $result['exit_code'],
        ];
    }

    /**
     * Assemble the argv for one headless invocation.
     *
     * @param  array<string,mixed> $options
     * @param  array<string,mixed> $providerConfig
     * @return list<string>
     */
    protected function buildCommand(array $options, array $providerConfig, string $prompt): array
    {
        $cmd = [
            $this->binary,
            '--print',
            '--output-format=stream-json',
            '--max-steps-per-turn', (string) ($options['max_steps_per_turn'] ?? $this->maxStepsPerTurn),
        ];

        $model = $options['model'] ?? $providerConfig['model'] ?? null;
        if ($model !== null && $model !== '') {
            $cmd[] = '--model';
            $cmd[] = (string) $model;
        }

        $mcpConfigFile = $options['mcp_config_file'] ?? null;
        if (is_string($mcpConfigFile) && $mcpConfigFile !== '' && is_file($mcpConfigFile)) {
            $cmd[] = '--mcp-config-file';
            $cmd[] = $mcpConfigFile;
        }

        // Kimi's prompt flag is `--prompt` / `-p` / `-c`. Long form keeps
        // the command readable in logs + Process Monitor.
        $cmd[] = '--prompt';
        $cmd[] = $prompt;

        return $cmd;
    }

    /**
     * Parse Kimi's NDJSON stream into a single envelope.
     *
     * Walks every line, ignoring non-JSON lines (Kimi occasionally emits a
     * trailing blank line). Concatenates `text` blocks from `role=assistant`
     * messages, picking the LAST assistant message as the authoritative
     * answer — mid-run assistant turns often carry partial text or tool-
     * request narration which isn't the final response. Tool execution
     * results (`role=tool`) are captured only as a trace and not folded
     * into `text`. Public for unit testing.
     *
     * @return array{text:string, turns:int, tool_calls:int}
     */
    public function parseStreamJson(string $output): array
    {
        $text = '';
        $turns = 0;
        $toolCalls = 0;

        $lines = preg_split('/\r\n|\n|\r/', trim($output)) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '{') continue;
            $event = json_decode($line, true);
            if (!is_array($event) || !isset($event['role'])) continue;

            if ($event['role'] === 'assistant') {
                $turns++;
                $turnText = '';
                foreach ((array) ($event['content'] ?? []) as $block) {
                    if (!is_array($block)) continue;
                    if (($block['type'] ?? null) === 'text' && isset($block['text'])) {
                        $turnText .= (string) $block['text'];
                    }
                }
                // Last assistant wins — replaces earlier partial text.
                if ($turnText !== '') {
                    $text = $turnText;
                }
                if (!empty($event['tool_calls']) && is_array($event['tool_calls'])) {
                    $toolCalls += count($event['tool_calls']);
                }
            }
        }

        return [
            'text'       => $text,
            'turns'      => $turns,
            'tool_calls' => $toolCalls,
        ];
    }

    /**
     * Fallback: flatten an array of {role, content} messages to a single
     * prompt string. Matches the shape other CLI backends use when the
     * caller passes `messages` instead of `prompt`.
     *
     * @param  array<int,array{role:string,content:string}> $messages
     */
    protected function messagesToPrompt(array $messages): string
    {
        $lines = [];
        foreach ($messages as $m) {
            $role = (string) ($m['role'] ?? 'user');
            $content = (string) ($m['content'] ?? '');
            if ($content === '') continue;
            $lines[] = strtoupper($role) . ': ' . $content;
        }
        return implode("\n\n", $lines);
    }

    // ─── ScriptedSpawnBackend ──────────────────────────────────────────

    public function prepareScriptedProcess(array $options): Process
    {
        $promptFile  = $options['prompt_file']  ?? throw new \InvalidArgumentException('prompt_file required');
        $logFile     = $options['log_file']     ?? throw new \InvalidArgumentException('log_file required');
        $projectRoot = $options['project_root'] ?? throw new \InvalidArgumentException('project_root required');

        $promptText = @file_get_contents($promptFile);
        if ($promptText === false) {
            throw new \RuntimeException("Kimi: cannot read prompt file {$promptFile}");
        }

        $flags = ['--print', '--output-format', 'stream-json', '-w', $projectRoot, '--prompt', $promptText];
        if (!empty($options['model'])) {
            $flags[] = '--model';
            $flags[] = (string) $options['model'];
        }
        foreach ((array) ($options['extra_cli_flags'] ?? []) as $f) {
            $flags[] = (string) $f;
        }

        return $this->buildWrappedProcess(
            engineKey:      AiProvider::BACKEND_KIMI,
            promptFile:     $promptFile,
            logFile:        $logFile,
            projectRoot:    $projectRoot,
            cliFlagsString: $this->escapeFlags($flags),
            env:            (array) ($options['env'] ?? []),
            envUnsetExtras: [],
            timeout:        $options['timeout']      ?? null,
            idleTimeout:    $options['idle_timeout'] ?? null,
            stdinMode:      'devnull',
        );
    }

    public function streamChat(string $prompt, callable $onChunk, array $options = []): string
    {
        $cliPath = app(\SuperAICore\Support\CliBinaryLocator::class)->find(AiProvider::BACKEND_KIMI);
        $args = [$cliPath, '--print', '--output-format', 'stream-json', '--prompt', $prompt];
        if (!empty($options['model'])) {
            $args[] = '--model';
            $args[] = (string) $options['model'];
        }

        $env = (array) ($options['env'] ?? []);
        $process = new Process($args, $options['cwd'] ?? null, $env);
        $process->setTimeout((int) ($options['timeout'] ?? 0));
        $process->setIdleTimeout((int) ($options['idle_timeout'] ?? 300));

        $fullResponse = '';
        $buffer = '';
        $process->start();
        $process->wait(function (string $type, string $data) use (&$buffer, &$fullResponse, $onChunk) {
            if ($type !== Process::OUT) return;
            $buffer .= $data;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                if ($line === '' || $line[0] !== '{') continue;
                $event = json_decode($line, true);
                if (!is_array($event)) continue;
                if (($event['role'] ?? '') === 'assistant') {
                    foreach ((array) ($event['content'] ?? []) as $block) {
                        if (($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                            $fullResponse .= $block['text'];
                            $onChunk($block['text']);
                        }
                    }
                }
            }
        });

        $this->assertChatExit($process, $fullResponse, 'Kimi');
        return $fullResponse;
    }
}
