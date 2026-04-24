<?php

namespace SuperAICore\Backends;

use SuperAICore\Backends\Concerns\BuildsScriptedProcess;
use SuperAICore\Backends\Concerns\StreamableProcess;
use SuperAICore\Contracts\Backend;
use SuperAICore\Contracts\ScriptedSpawnBackend;
use SuperAICore\Contracts\StreamingBackend;
use SuperAICore\Models\AiProvider;
use SuperAICore\Services\KiroModelResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Spawns AWS's `kiro-cli` (Kiro CLI 2.0+) in headless mode.
 *
 * Two auth channels are supported, matching AiProvider::BACKEND_TYPES[kiro]:
 *   - `builtin`:  host has already run `kiro-cli login`; no env injection
 *                 (keychain/token-store carries the session)
 *   - `kiro-api`: DB-stored key is injected as KIRO_API_KEY — setting that
 *                 env var makes kiro-cli skip its browser login flow entirely
 *                 (Pro / Pro+ / Power subscribers only)
 *
 * Uses `chat --no-interactive --trust-all-tools` so the binary prints its
 * response to stdout without interactive slash commands. Output is plain
 * text (the `--format json` flag applies only to `--list-models` /
 * `--list-sessions`, not to the chat body itself), so we parse the tail
 * summary line
 *
 *   ▸ Credits: 0.39 • Time: 22s
 *
 * to capture usage. When a model is supplied, it is passed through via
 * `--model <id>` — Kiro accepts any of the slugs returned by `kiro-cli
 * chat --list-models` (claude-sonnet-4.6, deepseek-3.2, minimax-m2.5,
 * glm-5, qwen3-coder-next, auto, …). Omitting --model lets Kiro's
 * router pick based on the subscription tier.
 */
class KiroCliBackend implements Backend, StreamingBackend, ScriptedSpawnBackend
{
    use StreamableProcess;
    use BuildsScriptedProcess;

    public function __construct(
        protected string $binary = 'kiro-cli',
        protected int $timeout = 300,
        protected bool $trustAllTools = true,
        protected ?LoggerInterface $logger = null,
    ) {}

    public function name(): string
    {
        return 'kiro_cli';
    }

    public function isAvailable(array $providerConfig = []): bool
    {
        $process = new Process(['which', $this->binary]);
        $process->run();
        if (!$process->isSuccessful()) return false;

        // kiro-api type further requires KIRO_API_KEY at dispatch time —
        // we can't verify the key itself without a live call, but we can
        // short-circuit when the stored provider has no key.
        $type = $providerConfig['type'] ?? 'builtin';
        if ($type === AiProvider::TYPE_KIRO_API && empty($providerConfig['api_key'])) {
            return false;
        }
        return true;
    }

    public function generate(array $options): ?array
    {
        $providerConfig = $options['provider_config'] ?? [];
        $prompt = $options['prompt'] ?? '';
        if ($prompt === '' && !empty($options['messages'])) {
            $prompt = $this->messagesToPrompt($options['messages']);
        }

        $rawModel = $options['model'] ?? $providerConfig['model'] ?? null;
        $model = \SuperAICore\Services\KiroModelResolver::resolve($rawModel);

        $cmd = [$this->binary, 'chat', '--no-interactive'];
        if ($this->trustAllTools) {
            $cmd[] = '--trust-all-tools';
        }
        if ($model !== null && $model !== '') {
            $cmd[] = '--model';
            $cmd[] = $model;
        }
        // Positional prompt — Kiro's headless mode reads the last non-flag
        // argument as the user message.
        $cmd[] = $prompt;

        try {
            $env = $this->buildEnv($providerConfig);
            $process = new Process($cmd, null, $env);
            $process->setTimeout($this->timeout);
            $process->run();

            if (!$process->isSuccessful()) {
                if ($this->logger) $this->logger->warning('KiroCliBackend failed: ' . $process->getErrorOutput());
                return null;
            }

            $parsed = $this->parseOutput($process->getOutput());
            if (!$parsed || $parsed['text'] === '') return null;

            return [
                'text'  => $parsed['text'],
                'model' => $model ?? 'kiro-default',
                'usage' => [
                    // Kiro is a subscription engine billed in credits, not
                    // tokens. We surface credits under its own key so the
                    // cost calculator's subscription path doesn't multiply
                    // it by a token rate, and leave token fields at 0.
                    'input_tokens'  => 0,
                    'output_tokens' => 0,
                    'credits'       => $parsed['credits'],
                    'duration_s'    => $parsed['duration_s'],
                ],
                'stop_reason' => 'end_turn',
            ];
        } catch (\Throwable $e) {
            if ($this->logger) $this->logger->warning("KiroCliBackend error: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Streaming variant. Kiro emits plain text (no structured stream),
     * so chunks delivered during the run are partial human-readable
     * output. Tee them to disk for live tailing, parse the final summary
     * line at exit. Same args as generate(), with usage envelope expanded
     * with log_file / duration_ms / exit_code.
     *
     * MCP injection: Kiro 2.x supports MCP via `~/.kiro/mcp.json`. The
     * `mcp_mode` knob is currently a no-op for Kiro because the CLI
     * doesn't expose a per-invocation `--mcp-config` flag — operators
     * who need an empty MCP set should rename the file out before
     * dispatching. Honored here as a forward-compatible stub.
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

        $rawModel = $options['model'] ?? $providerConfig['model'] ?? null;
        $model = \SuperAICore\Services\KiroModelResolver::resolve($rawModel);

        $cmd = [$this->binary, 'chat', '--no-interactive'];
        if ($this->trustAllTools) {
            $cmd[] = '--trust-all-tools';
        }
        if ($model !== null && $model !== '') {
            $cmd[] = '--model';
            $cmd[] = $model;
        }
        $cmd[] = $prompt;

        try {
            $env = $this->buildEnv($providerConfig);
            $process = new Process($cmd, null, $env);
            $result = $this->runStreaming(
                process: $process,
                backend: $this->name(),
                commandSummary: $this->binary . ' chat --no-interactive' . ($model ? " --model {$model}" : ''),
                logFile: $options['log_file'] ?? null,
                timeout: $options['timeout'] ?? null,
                idleTimeout: $options['idle_timeout'] ?? null,
                onChunk: $options['onChunk'] ?? null,
                externalLabel: $options['external_label'] ?? null,
                monitorMetadata: $options['metadata'] ?? [],
                cwd: $options['cwd'] ?? null,
            );
        } catch (\Throwable $e) {
            if ($this->logger) $this->logger->warning("KiroCliBackend stream error: {$e->getMessage()}");
            return null;
        }

        $parsed = $this->parseOutput($result['captured']);
        if (!$parsed || $parsed['text'] === '') {
            return [
                'text'        => '',
                'model'       => $model ?? 'kiro-default',
                'usage'       => [],
                'log_file'    => $result['log_file'],
                'duration_ms' => $result['duration_ms'],
                'exit_code'   => $result['exit_code'],
            ];
        }

        return [
            'text'  => $parsed['text'],
            'model' => $model ?? 'kiro-default',
            'usage' => [
                'input_tokens'  => 0,
                'output_tokens' => 0,
                'credits'       => $parsed['credits'],
                'duration_s'    => $parsed['duration_s'],
            ],
            'stop_reason' => 'end_turn',
            'log_file'    => $result['log_file'],
            'duration_ms' => $result['duration_ms'],
            'exit_code'   => $result['exit_code'],
        ];
    }

    /**
     * Parse kiro-cli's plain-text headless output.
     *
     * Shape (as of Kiro CLI 2.x, verified in docs/changelog 2026-04):
     *
     *   <response body — may span many lines, may include tool-event banners
     *   like "✓ Successfully read directory" interleaved>
     *
     *   ▸ Credits: 0.39 • Time: 22s
     *
     * We strip the trailing summary line from the response body and return
     * the parsed credits + duration. Tool-event banner lines starting with
     * `✓ ` or `✗ ` are considered informational and are LEFT in the body —
     * host apps that want to suppress them can post-process.
     *
     * Public for testing.
     *
     * @return array{text:string, credits:float, duration_s:int}|null
     */
    public function parseOutput(string $output): ?array
    {
        $trimmed = trim($output);
        if ($trimmed === '') return null;

        $credits = 0.0;
        $duration = 0;
        $body = $trimmed;

        // The marker glyph varies across Kiro CLI builds — ▸ (U+25B8) on
        // current versions, sometimes a plain `>` when stdout is piped or
        // the terminal is ASCII. Separator between Credits and Time is
        // either `•` (U+2022) or `·` (U+00B7). Matched loosely so that
        // older builds and unusual terminals still produce usage data.
        if (preg_match(
            '/(?:^|\n)\s*(?:\x{25B8}|>)?\s*Credits:\s*([0-9]+(?:\.[0-9]+)?)\s*(?:\x{00B7}|\x{2022}|\|)\s*Time:\s*([0-9]+)\s*s\s*$/u',
            $trimmed,
            $m,
            PREG_OFFSET_CAPTURE
        )) {
            $credits  = (float) $m[1][0];
            $duration = (int)   $m[2][0];
            $body     = rtrim(substr($trimmed, 0, $m[0][1]));
        }

        return [
            'text'       => $body,
            'credits'    => $credits,
            'duration_s' => $duration,
        ];
    }

    /**
     * Delegate env injection to ProviderEnvBuilder — the registry decides
     * which env var(s) each type flows. For `builtin`, envKey is null so
     * we return an empty map (CLI's own session carries the request).
     * Falls back to the old hardcoded KIRO_API_KEY injection when the
     * container isn't booted (unit-test path).
     */
    protected function buildEnv(array $providerConfig): array
    {
        if (function_exists('app')) {
            try {
                return app(\SuperAICore\Services\ProviderEnvBuilder::class)
                    ->buildEnvFromConfig($providerConfig);
            } catch (\Throwable) {
                // fall through to legacy path
            }
        }
        $env = [];
        $type = $providerConfig['type'] ?? 'builtin';
        if ($type === AiProvider::TYPE_KIRO_API && !empty($providerConfig['api_key'])) {
            $env['KIRO_API_KEY'] = $providerConfig['api_key'];
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

    // ─── ScriptedSpawnBackend ──────────────────────────────────────────

    /**
     * Kiro scripted spawn. `kiro-cli chat` takes the prompt as a
     * positional argv argument (not stdin, not `-p`). Inlines the prompt
     * file and invokes the trait with `stdinMode: 'devnull'`.
     */
    public function prepareScriptedProcess(array $options): Process
    {
        $promptFile  = $options['prompt_file']  ?? throw new \InvalidArgumentException('prompt_file required');
        $logFile     = $options['log_file']     ?? throw new \InvalidArgumentException('log_file required');
        $projectRoot = $options['project_root'] ?? throw new \InvalidArgumentException('project_root required');

        $promptText = @file_get_contents($promptFile);
        if ($promptText === false) {
            throw new \RuntimeException("Kiro: cannot read prompt file {$promptFile}");
        }

        $resolvedModel = !empty($options['model']) && class_exists(KiroModelResolver::class)
            ? KiroModelResolver::resolve($options['model'])
            : null;

        $flags = ['chat', '--no-interactive', '--trust-all-tools'];
        if ($resolvedModel) {
            $flags[] = '--model';
            $flags[] = $resolvedModel;
        }
        foreach ((array) ($options['extra_cli_flags'] ?? []) as $f) {
            $flags[] = (string) $f;
        }
        $flags[] = $promptText;

        return $this->buildWrappedProcess(
            engineKey:      AiProvider::BACKEND_KIRO,
            promptFile:     $promptFile,
            logFile:        $logFile,
            projectRoot:    $projectRoot,
            cliFlagsString: $this->escapeFlags($flags),
            env:            array_merge(['NO_COLOR' => '1', 'TERM' => 'dumb'], (array) ($options['env'] ?? [])),
            envUnsetExtras: [],
            timeout:        $options['timeout']      ?? null,
            idleTimeout:    $options['idle_timeout'] ?? null,
            stdinMode:      'devnull',
        );
    }

    /**
     * One-shot chat — Kiro: `kiro-cli chat --no-interactive <prompt>`.
     */
    public function streamChat(string $prompt, callable $onChunk, array $options = []): string
    {
        $cliPath = app(\SuperAICore\Support\CliBinaryLocator::class)->find(AiProvider::BACKEND_KIRO);
        $args = [$cliPath, 'chat', '--no-interactive'];

        $resolvedModel = !empty($options['model']) && class_exists(KiroModelResolver::class)
            ? KiroModelResolver::resolve($options['model'])
            : null;
        if ($resolvedModel) {
            $args[] = '--model';
            $args[] = $resolvedModel;
        }
        $args[] = $prompt;

        $env = array_merge(['NO_COLOR' => '1', 'TERM' => 'dumb'], (array) ($options['env'] ?? []));
        $process = new Process($args, $options['cwd'] ?? null, $env);
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

        $this->assertChatExit($process, $fullResponse, 'Kiro');
        return $fullResponse;
    }
}
