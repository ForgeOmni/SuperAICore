<?php

namespace SuperAICore\Backends;

use SuperAICore\Backends\Concerns\BuildsScriptedProcess;
use SuperAICore\Backends\Concerns\LargeArgvSafeSpawn;
use SuperAICore\Backends\Concerns\StreamableProcess;
use SuperAICore\Contracts\Backend;
use SuperAICore\Contracts\ScriptedSpawnBackend;
use SuperAICore\Contracts\StreamingBackend;
use SuperAICore\Models\AiProvider;
use SuperAICore\Services\CursorModelResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Spawns Cursor's `cursor-agent` CLI — the headless "Composer" agent
 * (verified against cursor-agent 2026.07.16; json + stream-json shapes
 * re-captured live 2026-07-19 — unchanged, camelCase usage keys and all).
 *
 * Auth is owned by the binary: `cursor-agent login` (browser OAuth) writes
 * `~/.cursor/agent-cli-state.json`; headless runners can instead export
 * `CURSOR_API_KEY`. The default `builtin` provider type leaves env empty and
 * rides the local login state, exactly like the Copilot backend.
 *
 * Billing is by Cursor subscription — there's no per-token meter on this
 * channel — so the cost calculator treats `cursor:*` rows as $0 and the
 * dashboard groups them under "Subscription engines".
 *
 * Invocation surface used here:
 *   - `-p` / `--print`          non-interactive (print + exit)
 *   - `--output-format`         text | json | stream-json
 *   - `--force`                 auto-approve tools (required headless, else it
 *                               blocks on per-tool confirmation)
 *   - `--model <id>`            composer-2.5 / composer-2.5-fast / proxied SKUs
 *   - `--workspace <path>`      working directory for the run
 *   - `--mode plan|ask`         read-only postures (used by streamChat)
 *   - `-w/--worktree`           isolated git worktree (passed through extra flags)
 * The prompt is a trailing positional argument.
 */
class CursorCliBackend implements Backend, StreamingBackend, ScriptedSpawnBackend
{
    use StreamableProcess;
    use BuildsScriptedProcess;
    use LargeArgvSafeSpawn;

    public function __construct(
        protected string $binary = 'cursor-agent',
        protected int $timeout = 300,
        protected bool $force = true,
        protected ?LoggerInterface $logger = null,
    ) {}

    public function name(): string
    {
        return 'cursor_cli';
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

        $model = CursorModelResolver::resolve($options['model'] ?? $providerConfig['model'] ?? null);

        $cmd = [$this->binary, '-p', '--output-format', 'json'];
        if ($this->force) $cmd[] = '--force';
        if ($model) { $cmd[] = '--model'; $cmd[] = $model; }
        $cmd[] = $prompt; // trailing positional

        try {
            $env = $this->buildEnv($providerConfig);
            $process = new Process($cmd, $options['cwd'] ?? null, $env ?: null);
            $process->setTimeout($this->timeout);
            $process->run();

            if (!$process->isSuccessful()) {
                if ($this->logger) $this->logger->warning('CursorCliBackend failed: ' . $process->getErrorOutput());
                return null;
            }

            $parsed = $this->parseAgentOutput($process->getOutput());
            if (!$parsed || $parsed['text'] === '') return null;

            return [
                'text'  => $parsed['text'],
                'model' => $parsed['model'] ?? $model ?? 'composer-2.5',
                'usage' => [
                    'input_tokens'  => $parsed['input_tokens'],
                    'output_tokens' => $parsed['output_tokens'],
                ],
                'stop_reason' => $parsed['stop_reason'] ?? 'end_turn',
            ];
        } catch (\Throwable $e) {
            if ($this->logger) $this->logger->warning("CursorCliBackend error: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Streaming variant — `cursor-agent ... --output-format stream-json`
     * emits one Claude-Code-shaped event per line. Tee'd to a log + Process
     * Monitor row + chunk callback via the StreamableProcess trait.
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

        $model = CursorModelResolver::resolve($options['model'] ?? $providerConfig['model'] ?? null);

        $cmd = [$this->binary, '-p', '--output-format', 'stream-json'];
        if ($this->force) $cmd[] = '--force';
        if ($model) { $cmd[] = '--model'; $cmd[] = $model; }
        if (!empty($options['cwd'])) { $cmd[] = '--workspace'; $cmd[] = (string) $options['cwd']; }
        $cmd[] = $prompt;

        try {
            $env = $this->buildEnv($providerConfig);
            $process = new Process($cmd, $options['cwd'] ?? null, $env ?: null);
            $result = $this->runStreaming(
                process:         $process,
                backend:         $this->name(),
                commandSummary:  $this->binary . ' -p --output-format=stream-json' . ($model ? " --model {$model}" : ''),
                logFile:         $options['log_file']     ?? null,
                timeout:         $options['timeout']      ?? null,
                idleTimeout:     $options['idle_timeout'] ?? null,
                onChunk:         $options['onChunk']      ?? null,
                externalLabel:   $options['external_label'] ?? null,
                monitorMetadata: $options['metadata']     ?? [],
                cwd:             $options['cwd']           ?? null,
            );
        } catch (\Throwable $e) {
            if ($this->logger) $this->logger->warning("CursorCliBackend stream error: {$e->getMessage()}");
            return null;
        }

        // parseAgentOutput() returns null when the captured buffer is empty or
        // unparseable (a run that timed out / crashed before emitting valid
        // output). Guard the null before dereferencing, like every other
        // streaming backend — otherwise the envelope comes back with
        // text/tokens = null, violating its own contract and feeding nulls
        // into the usage/cost path.
        $parsed = $this->parseAgentOutput($result['captured']) ?? [
            'text' => '', 'model' => null, 'input_tokens' => 0,
            'output_tokens' => 0, 'stop_reason' => null,
        ];

        return [
            'text'  => $parsed['text'],
            'model' => $parsed['model'] ?? $model ?? 'composer-2.5',
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
        $cliPath = app(\SuperAICore\Support\CliBinaryLocator::class)->find('cursor');
        $args = [$cliPath, '-p', '--output-format', 'text', '--force'];
        $model = CursorModelResolver::resolve($options['model'] ?? null);
        if ($model) { $args[] = '--model'; $args[] = $model; }
        $args[] = $prompt;

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

        $this->assertChatExit($process, $fullResponse, 'Cursor');
        return trim($fullResponse);
    }

    public function prepareScriptedProcess(array $options): Process
    {
        $promptFile  = $options['prompt_file']  ?? throw new \InvalidArgumentException('prompt_file required');
        $logFile     = $options['log_file']     ?? throw new \InvalidArgumentException('log_file required');
        $projectRoot = $options['project_root'] ?? throw new \InvalidArgumentException('project_root required');

        $promptText = @file_get_contents($promptFile);
        if ($promptText === false) {
            throw new \RuntimeException("Cursor: cannot read prompt file {$promptFile}");
        }

        $model = CursorModelResolver::resolve($options['model'] ?? null);

        $flags = ['-p', '--output-format', 'stream-json', '--workspace', $projectRoot];
        if ($this->force) $flags[] = '--force';
        if ($model) { $flags[] = '--model'; $flags[] = $model; }
        foreach ((array) ($options['extra_cli_flags'] ?? []) as $f) $flags[] = (string) $f;
        $flags[] = $promptText; // trailing positional prompt

        return $this->buildWrappedProcess(
            engineKey:      'cursor',
            promptFile:     $promptFile,
            logFile:        $logFile,
            projectRoot:    $projectRoot,
            cliFlagsString: $this->escapeFlags($flags),
            env:            (array) ($options['env'] ?? []),
            envUnsetExtras: [],
            timeout:        $options['timeout']      ?? null,
            idleTimeout:    $options['idle_timeout'] ?? null,
            stdinMode:      'devnull', // prompt is an argv positional, not stdin
        );
    }

    /**
     * Map provider_config to Cursor's auth env. The `builtin` type leaves
     * env empty and relies on local `cursor-agent login` state; any other
     * type with an api_key exports it as CURSOR_API_KEY.
     *
     * @return array<string,string>
     */
    protected function buildEnv(array $providerConfig): array
    {
        $env = [];
        $type = $providerConfig['type'] ?? 'builtin';
        if ($type !== 'builtin' && !empty($providerConfig['api_key'])) {
            $env['CURSOR_API_KEY'] = (string) $providerConfig['api_key'];
        } else {
            // builtin = use `cursor-agent login` (browser OAuth). The user
            // supplied no key, so scrub any stale inherited CURSOR_API_KEY
            // (false = unset in child) so a leftover/invalid key can't
            // override the login and fail.
            $env['CURSOR_API_KEY'] = false;
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
     * Parse cursor-agent headless output. Tolerant of three shapes so a
     * format tweak upstream degrades gracefully rather than dropping text:
     *   1. `--output-format json` single object — `result`/`response`/`text`
     *      or `message.content[].text`.
     *   2. `--output-format stream-json` NDJSON — Claude-Code-shaped
     *      `assistant` / `result` events (last assistant text wins; result
     *      usage is authoritative).
     *   3. Plain text fallback — ANSI-stripped stdout when nothing parses.
     *
     * Public for testing without spawning a process.
     *
     * @return array{text:string,model:?string,input_tokens:int,output_tokens:int,stop_reason:?string}|null
     */
    public function parseAgentOutput(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') return null;

        // 1. Whole-output JSON (single object or a list of events).
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            // A bare list of events → treat like NDJSON below.
            if (array_is_list($decoded)) {
                return $this->scanEvents($decoded);
            }
            $single = $this->scanEvents([$decoded]);
            if ($single && $single['text'] !== '') return $single;
        }

        // 2. NDJSON stream-json.
        $events = [];
        foreach (preg_split('/\r\n|\n|\r/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '{') continue;
            $ev = json_decode($line, true);
            if (is_array($ev)) $events[] = $ev;
        }
        if ($events !== []) {
            $scanned = $this->scanEvents($events);
            if ($scanned && $scanned['text'] !== '') return $scanned;
        }

        // 3. Plain text fallback.
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
     * Reduce a list of Claude-Code-shaped events to the canonical envelope.
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

            // Terminal result frame — most authoritative.
            if ($type === 'result' || isset($ev['result'])) {
                if (isset($ev['result']) && is_string($ev['result']) && $ev['result'] !== '') {
                    $text = $ev['result'];
                }
                $usage = $ev['usage'] ?? [];
                if (is_array($usage)) {
                    $input  = (int) ($usage['input_tokens']  ?? $usage['inputTokens']  ?? $input);
                    $output = (int) ($usage['output_tokens'] ?? $usage['outputTokens'] ?? $output);
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
                if ($turn !== '') $text = $turn; // last assistant turn wins
                $usage = $msg['usage'] ?? null;
                if (is_array($usage)) {
                    $input  = (int) ($usage['input_tokens']  ?? $input);
                    $output = (int) ($usage['output_tokens'] ?? $output);
                }
                continue;
            }

            // Single-object json shapes without a `type` discriminator.
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
