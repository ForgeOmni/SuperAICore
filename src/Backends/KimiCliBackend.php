<?php

namespace SuperAICore\Backends;

use SuperAICore\Backends\Concerns\BuildsScriptedProcess;
use SuperAICore\Backends\Concerns\LargeArgvSafeSpawn;
use SuperAICore\Backends\Concerns\StreamableProcess;
use SuperAICore\Contracts\Backend;
use SuperAICore\Contracts\ScriptedSpawnBackend;
use SuperAICore\Contracts\StreamingBackend;
use SuperAICore\Models\AiProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Spawns Moonshot AI's `kimi` CLI in headless mode — across BOTH the
 * legacy `MoonshotAI/kimi-cli` and the new `@moonshot-ai/kimi-code` that
 * replaces it. Both publish the same `kimi` binary, so during the
 * transition a host may have either one installed; this backend probes
 * which dialect is present and adapts its argv + stream-json parsing.
 *
 * Authentication model: only `moonshot-builtin` — `kimi login` has already
 * populated `credentials/kimi-code.json` under the CLI's state dir
 * (`$KIMI_CODE_HOME`/`~/.kimi-code/` for kimi-code, `~/.kimi/` legacy) with
 * the OAuth token. No env injection, same as Claude / Gemini / Kiro builtin.
 * A direct-HTTP `api_key` channel against api.moonshot.ai is intentionally
 * NOT exposed here — that path routes through the `superagent` backend
 * via the SDK's KimiProvider under separate provider types.
 *
 * ── Headless surface, legacy kimi-cli (verified against kimi v1.38.0) ──
 *   - `--print` is a boolean flag (no value); implicitly enables `--yolo`
 *   - `--output-format stream-json` → NDJSON on stdout, one line per event
 *   - `--prompt "..."` (or `-p`) delivers the user message
 *   - `--work-dir <dir>` (`-w`) overrides cwd independently of env
 *   - `--max-steps-per-turn <N>` caps the agentic loop (default 500)
 *   - `--mcp-config-file <path>` repeatable, per-run MCP injection
 *   - assistant `content` is an ARRAY of typed blocks (`text` / `think`)
 *   - resume hint goes to stderr (does not pollute the NDJSON stream)
 *
 * ── Headless surface, new kimi-code (verified v0.6.0; re-verified live
 *    against v0.27.0 on 2026-07-19 — contract unchanged) ──
 *   - NO `--print`: print mode is triggered by passing `--prompt`; unknown
 *     options are hard-rejected, and `--yolo`/`--auto`/`--plan` may NOT be
 *     combined with `--prompt` ("error: Cannot combine --prompt with
 *     --yolo"); prompt mode itself runs under the `auto` permission
 *     policy, so tools execute without approval anyway
 *   - `--output-format <text|stream-json>` (only valid in prompt mode)
 *   - NO `--max-steps-per-turn`, NO per-run `--mcp-config-file`, NO `-w`
 *     (the step budget and MCP servers are config-driven — user scope
 *     `~/.kimi-code/{config.toml,mcp.json}`, project scope
 *     `.kimi-code/mcp.json`; cwd is the process cwd, plus repeatable
 *     `--add-dir` for extra workspace dirs)
 *   - assistant `content` is a plain STRING; tool use arrives as an
 *     assistant line with an OpenAI-style `tool_calls` array (bare
 *     Claude-style names, e.g. `"name":"Bash"`) followed by
 *     `{"role":"tool","tool_call_id":…,"content":…}` result lines
 *   - a `{"role":"meta","type":"session.resume_hint", …}` NDJSON line
 *     carries the resume hint (`kimi -r <session_id>`; `-r`/`--resume` is
 *     a hidden alias of `-S`/`--session`)
 *
 * **Usage is NOT reported** in stream-json (either dialect). We emit zero
 * token counts in the envelope; `CostCalculator` treats Kimi as
 * subscription-billed and returns $0.
 */
class KimiCliBackend implements Backend, StreamingBackend, ScriptedSpawnBackend
{
    use StreamableProcess;
    use BuildsScriptedProcess;
    use LargeArgvSafeSpawn;

    /** Legacy MoonshotAI/kimi-cli (≤ v1.x): headless via `--print`. */
    public const VARIANT_LEGACY = 'kimi-cli';

    /** New @moonshot-ai/kimi-code (≥ v0.6): headless via `--prompt`. */
    public const VARIANT_CODE = 'kimi-code';

    /** Probe the installed `kimi` binary and pick the dialect at runtime. */
    public const VARIANT_AUTO = 'auto';

    /**
     * Detected variants keyed by resolved binary string, so the one-shot
     * `--help` probe runs at most once per binary per process.
     *
     * @var array<string,string>
     */
    protected static array $variantCache = [];

    /**
     * @param  string  $variant  One of `VARIANT_LEGACY` / `VARIANT_CODE` to
     *                           pin a dialect, or `VARIANT_AUTO` (default) to
     *                           detect it from the installed binary.
     */
    public function __construct(
        protected string $binary = 'kimi',
        protected int $timeout = 300,
        protected int $maxStepsPerTurn = 500,
        protected ?LoggerInterface $logger = null,
        protected string $variant = self::VARIANT_AUTO,
    ) {}

    public function name(): string
    {
        return 'kimi_cli';
    }

    public function isAvailable(array $providerConfig = []): bool
    {
        $process = new Process(['which', $this->binary]);
        $process->run();
        if ($process->isSuccessful()) {
            return true;
        }
        // The kimi-code installer's target (~/.kimi-code/bin) is added to
        // PATH via shell rc files, which non-login PHP processes (fpm,
        // queue workers, cron) don't source — probe it directly.
        return !str_contains($this->binary, '/')
            && is_file(\SuperAICore\Support\KimiRuntime::codeHome() . '/bin/' . $this->binary);
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
            // LargeArgvSafeSpawn handles Windows cmd-line truncation for
            // CLIs that don't read stdin — Kimi's `--prompt <text>` is
            // argv-only, so on Windows + long prompts it would otherwise
            // hit the 8K cmd.exe limit.
            $process = $this->buildLargeArgvSafeProcess($cmd, $options['cwd'] ?? null);
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
            // LargeArgvSafeSpawn handles Windows cmd-line truncation for
            // CLIs that don't read stdin — Kimi's `--prompt <text>` is
            // argv-only, so on Windows + long prompts it would otherwise
            // hit the 8K cmd.exe limit.
            $process = $this->buildLargeArgvSafeProcess($cmd, $options['cwd'] ?? null);
            $model = $options['model']
                ?? $providerConfig['model']
                ?? 'kimi-code/kimi-for-coding';

            $printFlag = $this->resolveVariant() === self::VARIANT_LEGACY ? '--print ' : '';

            $result = $this->runStreaming(
                process:         $process,
                backend:         $this->name(),
                commandSummary:  $this->binary . " {$printFlag}--output-format=stream-json -p" . ($model ? " --model {$model}" : ''),
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
     * Resolve which CLI dialect to target. An explicit constructor/config
     * variant wins; `auto` probes the installed binary once (cached).
     *
     * @return string  VARIANT_LEGACY | VARIANT_CODE
     */
    public function resolveVariant(): string
    {
        if ($this->variant === self::VARIANT_LEGACY || $this->variant === self::VARIANT_CODE) {
            return $this->variant;
        }
        return $this->detectVariant();
    }

    /**
     * One-shot `--help` probe to tell legacy kimi-cli (which advertises a
     * `--print` flag) apart from the new kimi-code (no `--print`; headless
     * mode is `--prompt`-driven). Result is cached per binary for the
     * process lifetime. On an unreadable/failed probe we default to the new
     * kimi-code dialect — it is the going-forward replacement.
     */
    protected function detectVariant(): string
    {
        if (array_key_exists($this->binary, self::$variantCache)) {
            return self::$variantCache[$this->binary];
        }

        $variant = self::VARIANT_CODE;
        try {
            $probe = new Process([$this->binary, '--help']);
            $probe->setTimeout(10);
            $probe->run();
            $help = $probe->getOutput() . "\n" . $probe->getErrorOutput();
            if (trim($help) !== '') {
                $variant = self::classifyVariantFromHelp($help);
            } elseif ($this->logger) {
                $this->logger->debug('KimiCliBackend: empty --help probe output, assuming kimi-code.');
            }
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->debug("KimiCliBackend: variant probe failed, assuming kimi-code: {$e->getMessage()}");
            }
        }

        return self::$variantCache[$this->binary] = $variant;
    }

    /**
     * Pure classifier (no I/O) so it can be unit-tested against captured
     * `--help` text. Legacy kimi-cli exposes a `--print` flag; kimi-code
     * does not (its headless surface is `--prompt` + `--output-format`), so
     * `--print` is the stable discriminator.
     *
     * @return string  VARIANT_LEGACY | VARIANT_CODE
     */
    public static function classifyVariantFromHelp(string $help): string
    {
        return preg_match('/(^|\s)--print\b/', $help) === 1
            ? self::VARIANT_LEGACY
            : self::VARIANT_CODE;
    }

    /** Reset the per-binary variant cache (test seam / hot-reload). */
    public static function forgetVariantCache(): void
    {
        self::$variantCache = [];
    }

    /**
     * Assemble the argv for one headless invocation, dispatching on the
     * detected CLI dialect.
     *
     * @param  array<string,mixed> $options
     * @param  array<string,mixed> $providerConfig
     * @return list<string>
     */
    protected function buildCommand(array $options, array $providerConfig, string $prompt): array
    {
        $model = $options['model'] ?? $providerConfig['model'] ?? null;
        $model = ($model !== null && $model !== '') ? (string) $model : null;

        if ($this->resolveVariant() === self::VARIANT_LEGACY) {
            return $this->buildLegacyCommand($options, $prompt, $model);
        }

        if ($this->logger && !empty($options['mcp_config_file'])) {
            $this->logger->debug(
                'KimiCliBackend: kimi-code has no --mcp-config-file flag; '
                . 'MCP servers are config.toml-driven. Ignoring mcp_config_file.',
            );
        }

        return $this->buildKimiCodeCommand($prompt, $model);
    }

    /**
     * Legacy MoonshotAI/kimi-cli (verified against kimi v1.38.0): `--print`
     * triggers headless mode, with `--max-steps-per-turn` and per-run
     * `--mcp-config-file`. Long-form `--prompt` keeps the command readable in
     * logs + Process Monitor.
     *
     * @param  array<string,mixed> $options
     * @return list<string>
     */
    protected function buildLegacyCommand(array $options, string $prompt, ?string $model): array
    {
        $cmd = [
            $this->binary,
            '--print',
            '--output-format=stream-json',
            '--max-steps-per-turn', (string) ($options['max_steps_per_turn'] ?? $this->maxStepsPerTurn),
        ];

        if ($model !== null) {
            $cmd[] = '--model';
            $cmd[] = $model;
        }

        $mcpConfigFile = $options['mcp_config_file'] ?? null;
        if (is_string($mcpConfigFile) && $mcpConfigFile !== '' && is_file($mcpConfigFile)) {
            $cmd[] = '--mcp-config-file';
            $cmd[] = $mcpConfigFile;
        }

        $cmd[] = '--prompt';
        $cmd[] = $prompt;

        return $cmd;
    }

    /**
     * New @moonshot-ai/kimi-code (v0.6+): print mode is triggered by
     * `--prompt` alone — `--print` is gone, and `--yolo`/`--auto`/`--plan`
     * are rejected alongside `--prompt`. There is no `--max-steps-per-turn`
     * or per-run `--mcp-config-file` flag (both config.toml-driven), and
     * unknown options are hard-rejected, so we send only the supported subset.
     *
     * @return list<string>
     */
    protected function buildKimiCodeCommand(string $prompt, ?string $model): array
    {
        $cmd = [
            $this->binary,
            '--prompt', $prompt,
            '--output-format', 'stream-json',
        ];

        if ($model !== null) {
            $cmd[] = '--model';
            $cmd[] = $model;
        }

        return $cmd;
    }

    /**
     * Parse Kimi's NDJSON stream into a single envelope.
     *
     * Walks every line, ignoring non-JSON lines (Kimi occasionally emits a
     * trailing blank line). Concatenates the user-visible text from
     * `role=assistant` messages, picking the LAST assistant message as the
     * authoritative answer — mid-run assistant turns often carry partial text
     * or tool-request narration which isn't the final response. Tool results
     * (`role=tool`) and the kimi-code resume hint (`role=meta`) are captured
     * only as a trace and not folded into `text`. Public for unit testing.
     *
     * Tolerant of BOTH stream-json dialects: legacy kimi-cli puts `content`
     * as an array of typed blocks; kimi-code puts it as a plain string.
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
                $turnText = $this->extractAssistantText($event['content'] ?? null);
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
     * Pull the user-visible assistant text out of one message's `content`,
     * accepting both wire shapes seen across the CLI transition:
     *   - kimi-code (≥0.6): `content` is a plain string.
     *   - kimi-cli (legacy): `content` is an array of typed blocks; only
     *     `type=text` blocks surface (CoT `type=think` stays internal).
     */
    protected function extractAssistantText(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return '';
        }
        $text = '';
        foreach ($content as $block) {
            if (!is_array($block)) continue;
            if (($block['type'] ?? null) === 'text' && isset($block['text'])) {
                $text .= (string) $block['text'];
            }
        }
        return $text;
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

        if ($this->resolveVariant() === self::VARIANT_LEGACY) {
            $flags = ['--print', '--output-format', 'stream-json', '-w', $projectRoot, '--prompt', $promptText];
        } else {
            // kimi-code: print mode is `--prompt`-driven; there is no `-w`
            // flag, but buildWrappedProcess already cd's into $projectRoot.
            $flags = ['--prompt', $promptText, '--output-format', 'stream-json'];
        }
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
        if ($this->resolveVariant() === self::VARIANT_LEGACY) {
            $args = [$cliPath, '--print', '--output-format', 'stream-json', '--prompt', $prompt];
        } else {
            $args = [$cliPath, '--prompt', $prompt, '--output-format', 'stream-json'];
        }
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
                    // Tolerant of both dialects: kimi-code emits `content` as a
                    // string (one flushed step), legacy as a block array.
                    $chunk = $this->extractAssistantText($event['content'] ?? null);
                    if ($chunk !== '') {
                        $fullResponse .= $chunk;
                        $onChunk($chunk);
                    }
                }
            }
        });

        $this->assertChatExit($process, $fullResponse, 'Kimi');
        return $fullResponse;
    }
}
