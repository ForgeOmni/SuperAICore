<?php

namespace SuperAICore\Backends;

use SuperAICore\Backends\Concerns\BuildsScriptedProcess;
use SuperAICore\Backends\Concerns\LargeArgvSafeSpawn;
use SuperAICore\Backends\Concerns\StreamableProcess;
use SuperAICore\Contracts\Backend;
use SuperAICore\Contracts\ScriptedSpawnBackend;
use SuperAICore\Contracts\StreamingBackend;
use SuperAICore\Services\GrokModelResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Spawns xAI's `grok` CLI — the "Grok Build" agentic TUI in headless mode
 * (verified against grok 0.2.8).
 *
 * Auth is owned by the binary: `grok login` (grok.com OAuth) caches
 * credentials under `~/.grok/`. The default `builtin` provider type leaves
 * env untouched and rides that login state. Billing is by the grok.com
 * subscription — no per-token meter on this channel — so the cost
 * calculator treats `grok_cli:*` rows as $0 ("Subscription engines").
 *
 * This is DISTINCT from the metered xAI API provider (SDK `GrokProvider`,
 * `AiProvider::TYPE_GROK`, `XAI_API_KEY`, `grok-4.3`). Same brand, different
 * channel: this backend is the subscription CLI (`grok-build` model).
 *
 * Invocation surface used here:
 *   - `-p` / `--single <PROMPT>`   headless single-turn (print + exit)
 *   - `--prompt-file <PATH>`       headless prompt from a file (scripted spawn)
 *   - `--output-format`            plain | json | streaming-json
 *   - `-m/--model <id>`            grok-build (+ account-exposed SKUs)
 *   - `--effort low|…|max`         effort dial (passed through from options)
 *   - `--reasoning-effort <e>`     reasoning-model effort
 *   - `--always-approve`           auto-approve tools (headless)
 *   - `--max-turns <n>` / `--cwd`  turn cap / working directory
 *   - `-w/--worktree`              isolated git worktree (via extra flags)
 */
class GrokCliBackend implements Backend, StreamingBackend, ScriptedSpawnBackend
{
    use StreamableProcess;
    use BuildsScriptedProcess;
    use LargeArgvSafeSpawn;

    /** Effort levels the CLI accepts (`--effort`). */
    public const EFFORT_LEVELS = ['low', 'medium', 'high', 'xhigh', 'max'];

    public function __construct(
        protected string $binary = 'grok',
        protected int $timeout = 300,
        protected bool $alwaysApprove = true,
        protected ?LoggerInterface $logger = null,
    ) {}

    public function name(): string
    {
        return 'grok_cli';
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

        $model = GrokModelResolver::resolve($options['model'] ?? $providerConfig['model'] ?? null);

        $cmd = [$this->binary, '-p', $prompt, '--output-format', 'json'];
        if ($this->alwaysApprove) $cmd[] = '--always-approve';
        if ($model) { $cmd[] = '--model'; $cmd[] = $model; }
        $this->appendEffortFlags($cmd, $options, $providerConfig);

        try {
            $env = $this->buildEnv($providerConfig);
            $process = new Process($cmd, $options['cwd'] ?? null, $env ?: null);
            $process->setTimeout($this->timeout);
            $process->run();

            if (!$process->isSuccessful()) {
                if ($this->logger) $this->logger->warning('GrokCliBackend failed: ' . $process->getErrorOutput());
                return null;
            }

            $parsed = $this->parseAgentOutput($process->getOutput());
            if (!$parsed || $parsed['text'] === '') return null;

            return [
                'text'  => $parsed['text'],
                'model' => $parsed['model'] ?? $model ?? 'grok-build',
                'usage' => [
                    'input_tokens'  => $parsed['input_tokens'],
                    'output_tokens' => $parsed['output_tokens'],
                ],
                'stop_reason' => $parsed['stop_reason'] ?? 'end_turn',
            ];
        } catch (\Throwable $e) {
            if ($this->logger) $this->logger->warning("GrokCliBackend error: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Streaming variant — `grok ... --output-format streaming-json` emits one
     * Claude-Code-shaped event per line. Tee'd to a log + Process Monitor row
     * + chunk callback via the StreamableProcess trait.
     *
     * @see Contracts\StreamingBackend
     */
    public function stream(array $options): ?array
    {
        $providerConfig = $options['provider_config'] ?? [];
        $prompt = $options['prompt'] ?? '';
        if ($prompt === '' && !empty($options['messages'])) {
            $prompt = $this->messagesToPrompt($options['messages']);
        }
        if ($prompt === '') return null;

        $model = GrokModelResolver::resolve($options['model'] ?? $providerConfig['model'] ?? null);

        $cmd = [$this->binary, '-p', $prompt, '--output-format', 'streaming-json'];
        if ($this->alwaysApprove) $cmd[] = '--always-approve';
        if ($model) { $cmd[] = '--model'; $cmd[] = $model; }
        if (!empty($options['cwd'])) { $cmd[] = '--cwd'; $cmd[] = (string) $options['cwd']; }
        $this->appendEffortFlags($cmd, $options, $providerConfig);

        try {
            $env = $this->buildEnv($providerConfig);
            $process = new Process($cmd, $options['cwd'] ?? null, $env ?: null);
            $result = $this->runStreaming(
                process:         $process,
                backend:         $this->name(),
                commandSummary:  $this->binary . ' -p --output-format=streaming-json' . ($model ? " --model {$model}" : ''),
                logFile:         $options['log_file']     ?? null,
                timeout:         $options['timeout']      ?? null,
                idleTimeout:     $options['idle_timeout'] ?? null,
                onChunk:         $options['onChunk']      ?? null,
                externalLabel:   $options['external_label'] ?? null,
                monitorMetadata: $options['metadata']     ?? [],
                cwd:             $options['cwd']           ?? null,
            );
        } catch (\Throwable $e) {
            if ($this->logger) $this->logger->warning("GrokCliBackend stream error: {$e->getMessage()}");
            return null;
        }

        $parsed = $this->parseAgentOutput($result['captured']);

        return [
            'text'  => $parsed['text'],
            'model' => $parsed['model'] ?? $model ?? 'grok-build',
            'usage' => [
                'input_tokens'  => $parsed['input_tokens'],
                'output_tokens' => $parsed['output_tokens'],
            ],
            'stop_reason' => $parsed['stop_reason'] ?? ($result['exit_code'] === 0 ? 'end_turn' : 'error'),
            'log_file'    => $result['log_file'],
            'duration_ms' => $result['duration_ms'],
            'exit_code'   => $result['exit_code'],
        ];
    }

    public function streamChat(string $prompt, callable $onChunk, array $options = []): string
    {
        $cliPath = app(\SuperAICore\Support\CliBinaryLocator::class)->find('grok');
        $args = [$cliPath, '-p', $prompt, '--output-format', 'plain'];
        if ($this->alwaysApprove) $args[] = '--always-approve';
        $model = GrokModelResolver::resolve($options['model'] ?? null);
        if ($model) { $args[] = '--model'; $args[] = $model; }

        $env = array_merge(['NO_COLOR' => '1', 'TERM' => 'dumb'], (array) ($options['env'] ?? []));
        $process = $this->buildLargeArgvSafeProcess($args, $options['cwd'] ?? null, $env);
        $process->setTimeout((int) ($options['timeout'] ?? 0));
        $process->setIdleTimeout((int) ($options['idle_timeout'] ?? 300));

        $fullResponse = '';
        $process->start();
        $process->wait(function (string $type, string $data) use (&$fullResponse, $onChunk) {
            if ($type !== Process::OUT) return;
            $clean = $this->stripAnsi($data);
            $fullResponse .= $clean;
            $onChunk($clean);
        });

        $this->assertChatExit($process, $fullResponse, 'Grok');
        return trim($fullResponse);
    }

    public function prepareScriptedProcess(array $options): Process
    {
        $promptFile  = $options['prompt_file']  ?? throw new \InvalidArgumentException('prompt_file required');
        $logFile     = $options['log_file']     ?? throw new \InvalidArgumentException('log_file required');
        $projectRoot = $options['project_root'] ?? throw new \InvalidArgumentException('project_root required');

        $model = GrokModelResolver::resolve($options['model'] ?? null);

        // Grok reads the prompt straight from a file via --prompt-file, so we
        // don't pipe stdin — cleaner + avoids argv length limits.
        $flags = ['--prompt-file', $promptFile, '--output-format', 'streaming-json', '--cwd', $projectRoot];
        if ($this->alwaysApprove) $flags[] = '--always-approve';
        if ($model) { $flags[] = '--model'; $flags[] = $model; }
        $effort = $this->normalizeEffort($options['effort'] ?? null);
        if ($effort) { $flags[] = '--effort'; $flags[] = $effort; }
        foreach ((array) ($options['extra_cli_flags'] ?? []) as $f) $flags[] = (string) $f;

        return $this->buildWrappedProcess(
            engineKey:      'grok',
            promptFile:     $promptFile,
            logFile:        $logFile,
            projectRoot:    $projectRoot,
            cliFlagsString: $this->escapeFlags($flags),
            env:            (array) ($options['env'] ?? []),
            envUnsetExtras: [],
            timeout:        $options['timeout']      ?? null,
            idleTimeout:    $options['idle_timeout'] ?? null,
            stdinMode:      'devnull', // prompt comes via --prompt-file
        );
    }

    /**
     * Append `--effort`/`--reasoning-effort` when supplied via options or the
     * provider's extra_config. Invalid levels are dropped (CLI rejects them).
     *
     * @param string[]            $cmd
     * @param array<string,mixed> $options
     * @param array<string,mixed> $providerConfig
     */
    protected function appendEffortFlags(array &$cmd, array $options, array $providerConfig): void
    {
        $extra = is_array($providerConfig['extra_config'] ?? null) ? $providerConfig['extra_config'] : [];
        $effort = $this->normalizeEffort($options['effort'] ?? $extra['effort'] ?? null);
        if ($effort) { $cmd[] = '--effort'; $cmd[] = $effort; }

        $reasoning = $options['reasoning_effort'] ?? $extra['reasoning_effort'] ?? null;
        if (is_string($reasoning) && $reasoning !== '') {
            $cmd[] = '--reasoning-effort';
            $cmd[] = $reasoning;
        }
    }

    protected function normalizeEffort(?string $effort): ?string
    {
        if ($effort === null) return null;
        $effort = strtolower(trim($effort));
        return in_array($effort, self::EFFORT_LEVELS, true) ? $effort : null;
    }

    /**
     * Grok auth is the grok.com login under ~/.grok/ for the `builtin` type;
     * other types may carry an xAI key, exported as both env names the
     * ecosystem reads.
     *
     * @return array<string,string>
     */
    protected function buildEnv(array $providerConfig): array
    {
        $env = [];
        $type = $providerConfig['type'] ?? 'builtin';
        if ($type !== 'builtin' && !empty($providerConfig['api_key'])) {
            $env['XAI_API_KEY']  = (string) $providerConfig['api_key'];
            $env['GROK_API_KEY'] = (string) $providerConfig['api_key'];
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

    /**
     * Parse grok headless output. Like the Cursor backend, tolerant of the
     * single-object `json` shape, the `streaming-json` NDJSON shape (Claude-
     * Code-style `assistant`/`result` events), and a plain-text fallback —
     * so an upstream format tweak degrades gracefully.
     *
     * Public for testing without spawning a process.
     *
     * @return array{text:string,model:?string,input_tokens:int,output_tokens:int,stop_reason:?string}|null
     */
    public function parseAgentOutput(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') return null;

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            if (array_is_list($decoded)) {
                $scanned = $this->scanEvents($decoded);
                if ($scanned['text'] !== '') return $scanned;
            } else {
                $single = $this->scanEvents([$decoded]);
                if ($single['text'] !== '') return $single;
            }
        }

        $events = [];
        foreach (preg_split('/\r\n|\n|\r/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '{') continue;
            $ev = json_decode($line, true);
            if (is_array($ev)) $events[] = $ev;
        }
        if ($events !== []) {
            $scanned = $this->scanEvents($events);
            if ($scanned['text'] !== '') return $scanned;
        }

        $text = trim($this->stripAnsi($raw));
        if ($text === '' || $text[0] === '{') return null;
        return [
            'text'          => $text,
            'model'         => null,
            'input_tokens'  => 0,
            'output_tokens' => 0,
            'stop_reason'   => 'end_turn',
        ];
    }

    /**
     * Reduce Claude-Code-shaped events to the canonical envelope.
     *
     * @param array<int,array<string,mixed>> $events
     * @return array{text:string,model:?string,input_tokens:int,output_tokens:int,stop_reason:?string}
     */
    protected function scanEvents(array $events): array
    {
        $text = '';
        $model = null;
        $input = 0;
        $output = 0;
        $stop = null;

        foreach ($events as $ev) {
            $type = $ev['type'] ?? null;

            if ($type === 'result' || isset($ev['result'])) {
                if (isset($ev['result']) && is_string($ev['result']) && $ev['result'] !== '') {
                    $text = $ev['result'];
                }
                $usage = $ev['usage'] ?? [];
                if (is_array($usage)) {
                    $input  = (int) ($usage['input_tokens']  ?? $usage['inputTokens']  ?? $usage['prompt_tokens']     ?? $input);
                    $output = (int) ($usage['output_tokens'] ?? $usage['outputTokens'] ?? $usage['completion_tokens'] ?? $output);
                }
                $stop = $ev['stop_reason'] ?? $ev['subtype'] ?? $stop;
                $model ??= $ev['model'] ?? null;
                continue;
            }

            if ($type === 'assistant') {
                $msg = $ev['message'] ?? $ev;
                $model ??= $msg['model'] ?? null;
                $turn = '';
                foreach ((array) ($msg['content'] ?? []) as $block) {
                    if (is_array($block) && ($block['type'] ?? '') === 'text' && isset($block['text'])) {
                        $turn .= (string) $block['text'];
                    }
                }
                if ($turn !== '') $text = $turn;
                $usage = $msg['usage'] ?? null;
                if (is_array($usage)) {
                    $input  = (int) ($usage['input_tokens']  ?? $input);
                    $output = (int) ($usage['output_tokens'] ?? $output);
                }
                continue;
            }

            foreach (['response', 'text', 'content'] as $k) {
                if ($text === '' && isset($ev[$k]) && is_string($ev[$k]) && $ev[$k] !== '') {
                    $text = $ev[$k];
                }
            }
        }

        return [
            'text'          => trim($text),
            'model'         => $model,
            'input_tokens'  => $input,
            'output_tokens' => $output,
            'stop_reason'   => $stop ? (string) $stop : null,
        ];
    }
}
