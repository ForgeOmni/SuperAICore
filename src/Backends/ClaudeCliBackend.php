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
 * Spawns the `claude` CLI binary with env vars set per provider config.
 *
 * Supports:
 *   - Direct Anthropic login (ANTHROPIC_API_KEY)
 *   - Anthropic proxy (ANTHROPIC_BASE_URL + key)
 *   - AWS Bedrock (CLAUDE_CODE_USE_BEDROCK=1 + AWS_* vars)
 *   - Google Vertex (CLAUDE_CODE_USE_VERTEX=1 + project vars)
 *   - Built-in (local claude login — no env vars)
 */
class ClaudeCliBackend implements Backend, StreamingBackend, ScriptedSpawnBackend
{
    use StreamableProcess;
    use BuildsScriptedProcess;

    /**
     * Env marker names to wipe before spawning a child `claude`. See
     * `buildEnv()` for context — PHP-FPM workers inheriting a parent
     * Claude Code session's vars trip the CLI's recursion guards.
     */
    public const CLAUDE_SESSION_ENV_MARKERS = [
        'CLAUDECODE',
        'CLAUDE_CODE_ENTRYPOINT',
        'CLAUDE_CODE_SSE_PORT',
        'CLAUDE_CODE_EXECPATH',
        'CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS',
    ];

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

        // Permission mode — `bypassPermissions` skips claude's interactive
        // approval prompts for Write / Edit / Bash. Required in headless
        // mode; without it, claude blocks waiting for input that never
        // arrives and produces no output files. Hosts that want claude to
        // ask (e.g. interactive REPL wrappers) can pass `'default'`,
        // `'plan'`, etc. or omit the option to leave claude's default.
        if (!empty($options['permission_mode'])) {
            $cmd[] = '--permission-mode';
            $cmd[] = (string) $options['permission_mode'];
        }

        // Allowed tools — comma-separated allowlist passed to claude's
        // `--allowedTools` flag. When omitted, claude uses its default
        // tool set. Pass an explicit list (e.g.
        // `'Read,Glob,Grep,Write,WebSearch,WebFetch,Agent'`) when you
        // want to restrict the tool surface — claude's `default`
        // permission mode then auto-allows just those tools.
        if (!empty($options['allowed_tools'])) {
            $tools = is_array($options['allowed_tools'])
                ? implode(',', $options['allowed_tools'])
                : (string) $options['allowed_tools'];
            $cmd[] = '--allowedTools';
            $cmd[] = $tools;
        }

        // Session id — propagate caller's id for traceability across the
        // host's log files + claude's session store. Claude auto-generates
        // one when omitted.
        if (!empty($options['session_id'])) {
            $cmd[] = '--session-id';
            $cmd[] = (string) $options['session_id'];
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
                cwd: $options['cwd'] ?? null,
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
     *
     * Always unsets every `CLAUDECODE` / `CLAUDE_CODE_*` marker the
     * parent claude session may have set. When claude is spawned from a
     * process that itself was launched by Claude Code (e.g. a Laravel
     * dev server started from a `claude` shell), those env vars trip
     * claude's parent-recursion guards and cause the child to refuse
     * authentication with the message "Not logged in · Please run
     * /login". `false` tells Symfony Process to actively REMOVE each
     * var from the child env (vs `''` which would set it to empty
     * string — claude's checks are truthy/falsy so empty also works,
     * but `false` is the correct semantic).
     *
     * Markers observed leaking (Claude Code 2.x):
     *   - CLAUDECODE                              recursion sentinel
     *   - CLAUDE_CODE_ENTRYPOINT                  "cli" / "ide" hint
     *   - CLAUDE_CODE_SSE_PORT                    parent IPC port
     *   - CLAUDE_CODE_EXECPATH                    parent binary path
     *   - CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS    experimental gate
     *
     * `CLAUDE_CODE_USE_BEDROCK` / `CLAUDE_CODE_USE_VERTEX` are NOT in
     * the unset list because the bedrock/vertex provider-type branches
     * below set them intentionally — letting them through.
     */
    protected function buildEnv(array $providerConfig): array
    {
        $env = [
            'CLAUDECODE'                            => false,
            'CLAUDE_CODE_ENTRYPOINT'                => false,
            'CLAUDE_CODE_SSE_PORT'                  => false,
            'CLAUDE_CODE_EXECPATH'                  => false,
            'CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS'  => false,
        ];
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
                // macOS fallback: extract the OAuth token from Keychain
                // and inject it as ANTHROPIC_API_KEY when we can reach it.
                //
                // Why: claude's native `builtin` login talks to macOS
                // Keychain via the native Security framework API, which
                // respects audit-session boundaries. Processes spawned
                // from PHP-FPM workers (web UI → nohup → task:execute
                // → claude) live in a different audit session from the
                // interactive shell where the user ran `claude login`;
                // claude's native keychain call silently fails there and
                // the CLI returns `"Not logged in · Please run /login"`
                // with `apiKeySource:"none"`.
                //
                // The `security` CLI escapes this restriction (it's a
                // privileged macOS tool) and returns the same OAuth
                // token claude stored. We read it here and pass it
                // through as `ANTHROPIC_API_KEY` — claude honors that
                // env var as an authenticated session.
                //
                // Silent fallback: if the keychain lookup fails (non-macOS,
                // token not present, user never logged in), we leave env
                // empty and let claude's native path handle it. In
                // keychain-accessible contexts this is redundant but
                // harmless; in PHP-FPM contexts it's the only path that
                // works for OAuth-based builtin auth.
                $oauthToken = $this->readBuiltinOauthToken();
                if ($oauthToken) {
                    $env['ANTHROPIC_API_KEY'] = $oauthToken;
                }
                break;
        }

        return $env;
    }

    /**
     * Return the Claude Code OAuth access token from macOS Keychain, or
     * null when the platform isn't macOS / the token isn't present /
     * the security CLI isn't accessible.
     */
    protected function readBuiltinOauthToken(): ?string
    {
        if (PHP_OS_FAMILY !== 'Darwin') return null;

        // `security find-generic-password -s "Claude Code-credentials"
        //  -w` prints the password payload (JSON blob) to stdout.
        // 2>/dev/null swallows "not found" / ACL errors silently.
        $json = @shell_exec('security find-generic-password -s "Claude Code-credentials" -w 2>/dev/null');
        if (!$json) return null;

        $creds = json_decode(trim($json), true);
        $token = $creds['claudeAiOauth']['accessToken'] ?? null;
        return (is_string($token) && $token !== '') ? $token : null;
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

    // ─── ScriptedSpawnBackend ──────────────────────────────────────────

    /**
     * Claude-specific flag composition for scripted spawn:
     *   - `-p --output-format stream-json --verbose` (async task mode)
     *   - `--session-id <uuid>` (either supplied by caller or generated)
     *   - `--permission-mode bypassPermissions` (default — caller may override)
     *   - `--allowedTools "Read,Glob,Grep,Write,WebSearch,WebFetch,Agent"` (default)
     *   - `--mcp-config <empty.json> --strict-mcp-config` when
     *     `disable_mcp === true` (prevents Claude from auto-loading every
     *     globally-registered MCP server at startup)
     *   - `--model <x>` when model supplied
     */
    public function prepareScriptedProcess(array $options): Process
    {
        $promptFile  = $options['prompt_file']  ?? throw new \InvalidArgumentException('prompt_file required');
        $logFile     = $options['log_file']     ?? throw new \InvalidArgumentException('log_file required');
        $projectRoot = $options['project_root'] ?? throw new \InvalidArgumentException('project_root required');
        $model       = $options['model']        ?? null;
        $env         = (array) ($options['env'] ?? []);

        $sessionId      = (string) ($options['session_id'] ?? $this->uuid());
        $permissionMode = (string) ($options['permission_mode'] ?? 'bypassPermissions');
        $allowedTools   = $options['allowed_tools'] ?? 'Read,Glob,Grep,Write,WebSearch,WebFetch,Agent';
        if (is_array($allowedTools)) {
            $allowedTools = implode(',', $allowedTools);
        }

        $flags = [
            '-p',
            '--session-id', $sessionId,
            '--permission-mode', $permissionMode,
            '--allowedTools', $allowedTools,
            '--output-format', 'stream-json',
            '--verbose',
        ];
        if ($model) {
            $flags[] = '--model';
            $flags[] = $model;
        }

        // MCP handling: 'empty' → write a minimal mcp.json and pass
        // --strict-mcp-config. Also supports legacy `disable_mcp => true`
        // from the host migration. 'file' uses a caller-supplied config.
        $mcpMode = $options['mcp_mode'] ?? (($options['disable_mcp'] ?? false) ? 'empty' : 'inherit');
        if ($mcpMode === 'empty') {
            $emptyMcp = dirname($logFile) . '/mcp-empty.json';
            if (!file_exists($emptyMcp)) {
                @file_put_contents($emptyMcp, '{"mcpServers":{}}');
            }
            $flags[] = '--mcp-config';
            $flags[] = $emptyMcp;
            $flags[] = '--strict-mcp-config';
        } elseif ($mcpMode === 'file' && !empty($options['mcp_config_file'])) {
            $flags[] = '--mcp-config';
            $flags[] = (string) $options['mcp_config_file'];
            $flags[] = '--strict-mcp-config';
        }

        foreach ((array) ($options['extra_cli_flags'] ?? []) as $f) {
            $flags[] = (string) $f;
        }

        return $this->buildWrappedProcess(
            engineKey:       AiProvider::BACKEND_CLAUDE,
            promptFile:      $promptFile,
            logFile:         $logFile,
            projectRoot:     $projectRoot,
            cliFlagsString:  $this->escapeFlags($flags),
            env:             $env,
            envUnsetExtras:  self::CLAUDE_SESSION_ENV_MARKERS,
            timeout:         $options['timeout']      ?? null,
            idleTimeout:     $options['idle_timeout'] ?? null,
        );
    }

    /**
     * One-shot chat — Claude CLI: stdin-pipe prompt, stream-json output,
     * read-only tool allowlist (Read/Glob/Grep), empty MCP config,
     * bypass permissions.
     */
    public function streamChat(string $prompt, callable $onChunk, array $options = []): string
    {
        $cliPath = app(\SuperAICore\Support\CliBinaryLocator::class)->find(AiProvider::BACKEND_CLAUDE);
        $allowedTools = $options['allowed_tools'] ?? ['Read', 'Glob', 'Grep'];
        if (is_array($allowedTools)) {
            $allowedTools = implode(',', $allowedTools);
        }

        $args = [
            $cliPath, '-p',
            '--output-format', 'stream-json', '--verbose',
            '--permission-mode', 'bypassPermissions',
            '--tools', (string) $allowedTools,
            '--mcp-config', '{"mcpServers":{}}',
            '--strict-mcp-config',
        ];

        $resolvedModel = null;
        if (!empty($options['model']) && class_exists(\SuperAICore\Services\ClaudeModelResolver::class)) {
            $resolvedModel = \SuperAICore\Services\ClaudeModelResolver::resolve($options['model']);
        }
        if ($resolvedModel) {
            $args[] = '--model';
            $args[] = $resolvedModel;
        }

        $env = (array) ($options['env'] ?? []);
        foreach (self::CLAUDE_SESSION_ENV_MARKERS as $marker) {
            if (!array_key_exists($marker, $env)) $env[$marker] = false;
        }

        $process = new Process($args, $options['cwd'] ?? null, $env);
        $process->setTimeout((int) ($options['timeout'] ?? 0));
        $process->setIdleTimeout((int) ($options['idle_timeout'] ?? 300));
        $process->setInput($prompt);

        $fullResponse = '';
        $buffer = '';

        $process->start();
        $process->wait(function (string $type, string $data) use (&$buffer, &$fullResponse, $onChunk) {
            if ($type !== Process::OUT) return;
            $buffer .= $data;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                if ($line === '') continue;
                $this->emitClaudeStreamChunk($line, $fullResponse, $onChunk);
            }
        });
        if (trim($buffer) !== '') {
            $this->emitClaudeStreamChunk(trim($buffer), $fullResponse, $onChunk);
        }

        if ($process->getExitCode() !== 0 && $fullResponse === '') {
            $stderr = $process->getErrorOutput();
            if ($this->logger) {
                $this->logger->error("ClaudeCliBackend chat failed (exit {$process->getExitCode()}): {$stderr}");
            }
            throw new \RuntimeException("Claude chat failed (exit {$process->getExitCode()})");
        }

        return $fullResponse;
    }

    /**
     * Parse one stream-json line from Claude's chat output — shared
     * between streaming task spawn and chat one-shot. Emits any
     * text deltas via `$onChunk` and appends to `$fullResponse`.
     */
    protected function emitClaudeStreamChunk(string $line, string &$fullResponse, callable $onChunk): void
    {
        if ($line === '' || $line[0] !== '{') return;
        $event = json_decode($line, true);
        if (!is_array($event)) return;

        $type = $event['type'] ?? null;
        if ($type === 'assistant' && isset($event['message']['content'])) {
            foreach ((array) $event['message']['content'] as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $text = (string) ($block['text'] ?? '');
                    if ($text !== '') {
                        $fullResponse .= $text;
                        $onChunk($text);
                    }
                }
            }
        } elseif ($type === 'result' && isset($event['result']) && is_string($event['result'])) {
            // Final `result` event — some Claude builds emit the full
            // text here instead of (or in addition to) per-turn deltas.
            // Append only the tail we didn't already stream.
            $final = (string) $event['result'];
            if ($final !== '' && !str_ends_with($fullResponse, $final)) {
                $delta = str_starts_with($final, $fullResponse)
                    ? substr($final, strlen($fullResponse))
                    : $final;
                if ($delta !== '') {
                    $fullResponse .= $delta;
                    $onChunk($delta);
                }
            }
        }
    }

    protected function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
