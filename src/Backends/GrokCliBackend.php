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
 * (flag surface verified against grok 0.2.102).
 *
 * Auth is owned by the binary: `grok login` (grok.com OAuth) caches
 * credentials under `~/.grok/`. The default `builtin` provider type leaves
 * env untouched and rides that login state. Billing is by the grok.com
 * subscription — no per-token meter on this channel — so the cost
 * calculator treats `grok_cli:*` rows as $0 ("Subscription engines").
 *
 * This is DISTINCT from the metered xAI API provider (SDK `GrokProvider`,
 * `AiProvider::TYPE_GROK`, `XAI_API_KEY`, `grok-4.5` usage-billed). Same
 * brand, different channel: this backend is the subscription CLI (grok CLI
 * 0.2.93 routes `grok-4.5` + `grok-composer-2.5-fast` on the Build plan;
 * `grok-build` on older accounts — all $0/token here).
 *
 * Invocation surface used here:
 *   - `-p` / `--single <PROMPT>`   headless single-turn (print + exit)
 *   - `--prompt-file <PATH>`       headless prompt from a file (scripted spawn)
 *   - `--output-format`            plain | json | streaming-json
 *   - `-m/--model <id>`            grok-4.5 (default) / grok-composer-2.5-fast
 *                                  (+ account-exposed SKUs; grok-build on
 *                                  older accounts)
 *   - `--effort low|medium|high`   effort dial (`--reasoning-effort` alias);
 *                                  grok-4.5's dial is three-level, so the
 *                                  cross-engine `xhigh`/`max` clamp to `high`
 *                                  and `off`/`none`/`minimal` send nothing
 *                                  (0.2.102 `--effort` rejects anything else)
 *   - `--always-approve`           auto-approve tools (headless)
 *   - `-r/--resume <id>`           resume a prior session (from a previous
 *                                  envelope's `session_id`); `-c/--continue`
 *                                  for the most recent session in `--cwd`,
 *                                  `--session-id <uuid>` to name a new one,
 *                                  `--fork-session` to branch on resume
 *   - `--max-turns <n>` / `--cwd`  turn cap / working directory
 *   - `-w/--worktree`              isolated git worktree (via extra flags)
 *
 * Headless JSON (`--output-format json`) carries `sessionId`, `stopReason`,
 * `num_turns`, `total_cost_usd`, `usage.cache_read_input_tokens` and a
 * `thought` reasoning channel; the envelope surfaces each when present so
 * `grok_cli` rows reach parity with the other CLI backends.
 */
class GrokCliBackend implements Backend, StreamingBackend, ScriptedSpawnBackend
{
    use StreamableProcess;
    use BuildsScriptedProcess;
    use LargeArgvSafeSpawn;

    /**
     * Effort levels grok's `--effort` / `--reasoning-effort` dial accepts.
     * grok-4.5 reasons with a three-level dial (verified against grok CLI
     * 0.2.102: `--effort bogus` → "use one of: high, medium, low"). The
     * cross-engine `xhigh`/`max` convention clamps up to `high`; `off` /
     * `none` / `minimal` send no flag (see normalizeEffort()).
     */
    public const EFFORT_LEVELS = ['low', 'medium', 'high'];

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
        $this->appendSessionFlags($cmd, $options);

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

            return $this->buildEnvelope($parsed, $model, 'end_turn');
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
        $this->appendSessionFlags($cmd, $options);

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

        $parsed = $this->parseAgentOutput($result['captured'])
            ?? $this->emptyParsed();

        return $this->buildEnvelope(
            $parsed,
            $model,
            $result['exit_code'] === 0 ? 'end_turn' : 'error',
            [
                'log_file'    => $result['log_file'],
                'duration_ms' => $result['duration_ms'],
                'exit_code'   => $result['exit_code'],
            ],
        );
    }

    /**
     * Canonical envelope from a parsed grok result. `turns` / `session_id` /
     * `thinking` are surfaced only when the CLI reported them (parity with the
     * other CLI backends); `cache_read_input_tokens` always rides `usage`.
     *
     * `cost_usd` is deliberately NOT surfaced even though grok's headless JSON
     * carries `total_cost_usd`: `grok_cli` is the subscription channel that
     * `CostCalculator` books at $0, and the Dispatcher prefers a non-zero
     * envelope `cost_usd` over its own calc — surfacing it would double-count
     * subscription usage against the metered total.
     *
     * @param array{text:string,model:?string,input_tokens:int,output_tokens:int,cache_read_input_tokens:int,cost_usd:float,turns:int,session_id:?string,thinking:string,stop_reason:?string} $parsed
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    protected function buildEnvelope(array $parsed, ?string $model, string $defaultStop, array $extra = []): array
    {
        $env = [
            'text'  => $parsed['text'],
            'model' => $parsed['model'] ?? $model ?? 'grok-4.5',
            'usage' => [
                'input_tokens'            => $parsed['input_tokens'],
                'output_tokens'           => $parsed['output_tokens'],
                'cache_read_input_tokens' => $parsed['cache_read_input_tokens'],
            ],
            'stop_reason' => $parsed['stop_reason'] ?? $defaultStop,
        ];
        if ($parsed['turns'] > 0)                                              $env['turns']      = $parsed['turns'];
        if ($parsed['session_id'] !== null && $parsed['session_id'] !== '')    $env['session_id'] = $parsed['session_id'];
        if ($parsed['thinking'] !== '')                                        $env['thinking']   = $parsed['thinking'];

        return $extra === [] ? $env : array_merge($env, $extra);
    }

    /**
     * Zero-value parsed result — the shape `scanEvents()` returns, used as a
     * fallback so `stream()` never dereferences null when the captured buffer
     * had no parseable content.
     *
     * @return array{text:string,model:?string,input_tokens:int,output_tokens:int,cache_read_input_tokens:int,cost_usd:float,turns:int,session_id:?string,thinking:string,stop_reason:?string}
     */
    private function emptyParsed(): array
    {
        return [
            'text' => '', 'model' => null, 'input_tokens' => 0, 'output_tokens' => 0,
            'cache_read_input_tokens' => 0, 'cost_usd' => 0.0, 'turns' => 0,
            'session_id' => null, 'thinking' => '', 'stop_reason' => null,
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
     * Append a single `--effort` flag when effort is supplied via options or
     * the provider's extra_config. `--effort` and `--reasoning-effort` are
     * aliases for one dial, so we emit exactly one — passing both would have
     * grok reject the duplicate. Precedence: explicit `effort`, then
     * `reasoning_effort`, from options first and extra_config second. The
     * value is normalized/clamped (see normalizeEffort); an out-of-range
     * value sends no flag rather than failing the dispatch.
     *
     * @param string[]            $cmd
     * @param array<string,mixed> $options
     * @param array<string,mixed> $providerConfig
     */
    protected function appendEffortFlags(array &$cmd, array $options, array $providerConfig): void
    {
        $extra = is_array($providerConfig['extra_config'] ?? null) ? $providerConfig['extra_config'] : [];
        $raw = $options['effort']
            ?? $options['reasoning_effort']
            ?? $extra['effort']
            ?? $extra['reasoning_effort']
            ?? null;
        $effort = $this->normalizeEffort(is_string($raw) ? $raw : null);
        if ($effort !== null) { $cmd[] = '--effort'; $cmd[] = $effort; }
    }

    /**
     * Normalize a caller effort onto grok-4.5's three-level dial. `low` /
     * `medium` / `high` pass through; the cross-engine `xhigh` / `max` clamp
     * up to `high` so the strongest reasoning is still requested; everything
     * else (`off` / `none` / `minimal` / blank / unknown) returns null so the
     * caller sends no `--effort` flag — grok 0.2.102 rejects any other value
     * and would fail the whole dispatch on `--effort max`.
     */
    protected function normalizeEffort(?string $effort): ?string
    {
        if ($effort === null) return null;
        return match (strtolower(trim($effort))) {
            'low', 'medium', 'high' => strtolower(trim($effort)),
            'xhigh', 'max'          => 'high',
            default                 => null,
        };
    }

    /**
     * Append grok session flags from the dispatch options — the same
     * `resume_session_id` convention the claude / codex backends use, plus
     * grok's own `--continue` / `--session-id` / `--fork-session`:
     *   - resume_session_id: string → `--resume <id>` (re-open a prior session
     *                                 from an earlier envelope's `session_id`)
     *   - continue_session:  bool   → `--continue` (most recent in `--cwd`)
     *   - session_id:        string → `--session-id <uuid>` (name a NEW one —
     *                                 grok rejects it on an existing session)
     *   - fork_session:      bool   → `--fork-session` (branch on resume)
     * `resume_session_id` wins over `continue_session`, which wins over a
     * fresh `session_id`. No-op when the caller passes none of them.
     *
     * @param string[]            $cmd
     * @param array<string,mixed> $options
     */
    protected function appendSessionFlags(array &$cmd, array $options): void
    {
        $resume = $options['resume_session_id'] ?? null;
        if (is_string($resume) && $resume !== '') {
            $cmd[] = '--resume';
            $cmd[] = $resume;
        } elseif (!empty($options['continue_session'])) {
            $cmd[] = '--continue';
        } elseif (isset($options['session_id']) && is_string($options['session_id']) && $options['session_id'] !== '') {
            $cmd[] = '--session-id';
            $cmd[] = $options['session_id'];
        }
        if (!empty($options['fork_session'])) {
            $cmd[] = '--fork-session';
        }
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
        } else {
            // builtin = use `grok login` (grok.com OAuth, cached in ~/.grok).
            // The user supplied no key, so scrub any stale inherited
            // XAI_API_KEY / GROK_API_KEY (false = unset in child) so a
            // leftover/invalid key can't override the login and fail.
            $env['XAI_API_KEY']  = false;
            $env['GROK_API_KEY'] = false;
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
     * @return array{text:string,model:?string,input_tokens:int,output_tokens:int,cache_read_input_tokens:int,cost_usd:float,turns:int,session_id:?string,thinking:string,stop_reason:?string}|null
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
            'text'                    => $text,
            'model'                   => null,
            'input_tokens'            => 0,
            'output_tokens'           => 0,
            'cache_read_input_tokens' => 0,
            'cost_usd'                => 0.0,
            'turns'                   => 0,
            'session_id'              => null,
            'thinking'                => '',
            'stop_reason'             => 'end_turn',
        ];
    }

    /**
     * Reduce Claude-Code-shaped events to the canonical envelope. Captures the
     * full grok headless `json` shape (verified against grok 0.2.102):
     * `sessionId`, `stopReason`, `num_turns`, `total_cost_usd`, the
     * `usage.cache_read_input_tokens` tier and the `thought` reasoning
     * channel — each defaulting to empty so a leaner/older shape still parses.
     *
     * @param array<int,array<string,mixed>> $events
     * @return array{text:string,model:?string,input_tokens:int,output_tokens:int,cache_read_input_tokens:int,cost_usd:float,turns:int,session_id:?string,thinking:string,stop_reason:?string}
     */
    protected function scanEvents(array $events): array
    {
        $text = '';
        $model = null;
        $input = 0;
        $output = 0;
        $cacheRead = 0;
        $cost = 0.0;
        $turns = 0;
        $session = null;
        $thinking = '';
        $stop = null;

        foreach ($events as $ev) {
            // `sessionId` (headless) / `session_id` (init events) can ride any
            // event in the stream — capture the first non-empty one.
            $session ??= $this->firstString($ev, ['sessionId', 'session_id']);

            $type = $ev['type'] ?? null;

            if ($type === 'result' || isset($ev['result'])) {
                if (isset($ev['result']) && is_string($ev['result']) && $ev['result'] !== '') {
                    $text = $ev['result'];
                }
                $usage = $ev['usage'] ?? [];
                if (is_array($usage)) {
                    $input     = (int) ($usage['input_tokens']  ?? $usage['inputTokens']  ?? $usage['prompt_tokens']     ?? $input);
                    $output    = (int) ($usage['output_tokens'] ?? $usage['outputTokens'] ?? $usage['completion_tokens'] ?? $output);
                    $cacheRead = (int) ($usage['cache_read_input_tokens'] ?? $usage['cacheReadInputTokens'] ?? $cacheRead);
                }
                $cost   = (float) ($ev['total_cost_usd'] ?? $ev['cost_usd'] ?? $cost);
                $turns  = (int) ($ev['num_turns'] ?? $ev['turns'] ?? $turns);
                $stop   = $ev['stopReason'] ?? $ev['stop_reason'] ?? $ev['subtype'] ?? $stop;
                $model ??= $ev['model'] ?? null;
                $thought = $ev['thought'] ?? null;
                if (is_string($thought) && $thought !== '') $thinking = $thought;
                continue;
            }

            if ($type === 'assistant') {
                $msg = $ev['message'] ?? $ev;
                $model ??= $msg['model'] ?? null;
                $turn = '';
                foreach ((array) ($msg['content'] ?? []) as $block) {
                    if (!is_array($block)) continue;
                    $bt = $block['type'] ?? '';
                    if ($bt === 'text' && isset($block['text'])) {
                        $turn .= (string) $block['text'];
                    } elseif ($bt === 'thinking' && isset($block['thinking'])) {
                        $thinking .= (string) $block['thinking'];
                    }
                }
                if ($turn !== '') $text = $turn;
                $usage = $msg['usage'] ?? null;
                if (is_array($usage)) {
                    $input     = (int) ($usage['input_tokens']  ?? $input);
                    $output    = (int) ($usage['output_tokens'] ?? $output);
                    $cacheRead = (int) ($usage['cache_read_input_tokens'] ?? $cacheRead);
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
            'text'                    => trim($text),
            'model'                   => $model,
            'input_tokens'            => $input,
            'output_tokens'           => $output,
            'cache_read_input_tokens' => $cacheRead,
            'cost_usd'                => $cost,
            'turns'                   => $turns,
            'session_id'              => $session,
            'thinking'                => trim($thinking),
            'stop_reason'             => $stop ? (string) $stop : null,
        ];
    }

    /**
     * First non-empty string value among `$keys` on `$ev`, else null.
     *
     * @param array<string,mixed> $ev
     * @param string[]            $keys
     */
    private function firstString(array $ev, array $keys): ?string
    {
        foreach ($keys as $k) {
            $v = $ev[$k] ?? null;
            if (is_string($v) && $v !== '') return $v;
        }
        return null;
    }
}
