<?php

namespace SuperAICore\Backends;

use SuperAICore\Backends\Concerns\BuildsScriptedProcess;
use SuperAICore\Backends\Concerns\LargeArgvSafeSpawn;
use SuperAICore\Backends\Concerns\StreamableProcess;
use SuperAICore\Contracts\Backend;
use SuperAICore\Contracts\ScriptedSpawnBackend;
use SuperAICore\Contracts\StreamingBackend;
use SuperAICore\Models\AiProvider;
use SuperAICore\Services\AntigravityModelResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Spawns Google's Antigravity CLI (`agy`) in headless print mode.
 *
 * agy (Go single binary, verified v1.1.4 live on 2026-07-19) is Google's
 * replacement for the retired gemini-cli consumer tiers: gemini-cli
 * individual OAuth (free / AI Pro / AI Ultra) stopped serving 2026-06-18
 * with an IneligibleTierError pointing at the Antigravity suite. The
 * official installer (`curl -fsSL https://antigravity.google/cli/install.sh
 * | bash`) drops the binary into `~/.local/bin/agy`.
 *
 * Auth: Google-account OAuth via the interactive TUI (`agy` with no args).
 * Credentials land in the SHARED `~/.gemini/oauth_creds.json` (agy state
 * lives in `~/.gemini/antigravity-cli/`). Subscription-billed → the cost
 * calculator books `antigravity_cli:*` rows at $0.
 *
 * ── Headless surface (verified 1.1.4) ──
 *   - `-p/--print <PROMPT>` (alias `--prompt`) — single prompt, prints the
 *     response as PLAIN TEXT on stdout (no `--output-format`, no JSON /
 *     stream-json; token usage is not reported)
 *   - `--dangerously-skip-permissions` — auto-approve tools (headless)
 *   - `--model <display name>` — accepts ONLY the full display name as
 *     `agy models` prints it (`Gemini 3.5 Flash (Low)`). An unknown value
 *     dumps the model list to stdout AND exits 0 — which would become the
 *     "answer" — so AntigravityModelResolver is strict and unknown input
 *     drops the flag entirely.
 *   - `--print-timeout <dur>` — server-side print wait cap (default 5m)
 *   - `-c/--continue`, `--conversation <id>` — resume surfaces
 *   - `--add-dir`, `--mode accept-edits|plan`, `--sandbox`
 */
class AntigravityCliBackend implements Backend, StreamingBackend, ScriptedSpawnBackend
{
    use StreamableProcess;
    use BuildsScriptedProcess;
    use LargeArgvSafeSpawn;

    public function __construct(
        protected string $binary = 'agy',
        protected int $timeout = 300,
        protected ?LoggerInterface $logger = null,
    ) {}

    public function name(): string
    {
        return 'antigravity_cli';
    }

    public function isAvailable(array $providerConfig = []): bool
    {
        $process = new Process(['which', $this->binary]);
        $process->run();
        if ($process->isSuccessful()) {
            return true;
        }
        // The official installer targets ~/.local/bin, which non-login PHP
        // processes (fpm, queue workers, cron) often don't have on PATH.
        $home = getenv('HOME') ?: '';
        return $home !== ''
            && !str_contains($this->binary, '/')
            && is_file($home . '/.local/bin/' . $this->binary);
    }

    public function generate(array $options): ?array
    {
        $providerConfig = $options['provider_config'] ?? [];
        $prompt = $options['prompt'] ?? '';
        if ($prompt === '' && !empty($options['messages'])) {
            $prompt = $this->messagesToPrompt($options['messages']);
        }
        if ($prompt === '') return null;

        $model = AntigravityModelResolver::resolve(
            $options['model'] ?? $providerConfig['model'] ?? null,
        );
        $cmd = $this->buildCommand($prompt, $model, $options);

        try {
            // Prompt travels on argv (`-p <text>`), so guard the Windows
            // 8K cmd-line limit the same way the Kimi backend does.
            $process = $this->buildLargeArgvSafeProcess($cmd, $options['cwd'] ?? null);
            $process->setTimeout($this->timeout);
            $process->run();

            if (!$process->isSuccessful()) {
                if ($this->logger) {
                    $this->logger->warning('AntigravityCliBackend failed: ' . $process->getErrorOutput());
                }
                return null;
            }

            $text = trim($process->getOutput());
            if ($text === '') return null;

            return $this->buildEnvelope($text, $model);
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->warning("AntigravityCliBackend error: {$e->getMessage()}");
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

        $model = AntigravityModelResolver::resolve(
            $options['model'] ?? $providerConfig['model'] ?? null,
        );
        $cmd = $this->buildCommand($prompt, $model, $options);

        try {
            $process = $this->buildLargeArgvSafeProcess($cmd, $options['cwd'] ?? null);
            $result = $this->runStreaming(
                process:         $process,
                backend:         $this->name(),
                commandSummary:  $this->binary . ' -p --dangerously-skip-permissions' . ($model !== null ? " --model \"{$model}\"" : ''),
                logFile:         $options['log_file']     ?? null,
                timeout:         $options['timeout']      ?? null,
                idleTimeout:     $options['idle_timeout'] ?? null,
                onChunk:         $options['onChunk']      ?? null,
                externalLabel:   $options['external_label'] ?? null,
                monitorMetadata: $options['metadata']     ?? [],
                cwd:             $options['cwd']          ?? null,
            );
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->warning("AntigravityCliBackend stream error: {$e->getMessage()}");
            }
            return null;
        }

        return $this->buildEnvelope(trim($result['captured']), $model, [
            'log_file'    => $result['log_file'],
            'duration_ms' => $result['duration_ms'],
            'exit_code'   => $result['exit_code'],
        ]);
    }

    public function streamChat(string $prompt, callable $onChunk, array $options = []): string
    {
        $cliPath = app(\SuperAICore\Support\CliBinaryLocator::class)->find(AiProvider::BACKEND_ANTIGRAVITY);
        $model = AntigravityModelResolver::resolve($options['model'] ?? null);
        $args = [$cliPath, '-p', $prompt, '--dangerously-skip-permissions'];
        if ($model !== null) {
            $args[] = '--model';
            $args[] = $model;
        }

        $env = (array) ($options['env'] ?? []);
        $process = new Process($args, $options['cwd'] ?? null, $env);
        $process->setTimeout((int) ($options['timeout'] ?? 0));
        $process->setIdleTimeout((int) ($options['idle_timeout'] ?? 300));

        $fullResponse = '';
        $process->start();
        $process->wait(function (string $type, string $data) use (&$fullResponse, $onChunk) {
            if ($type !== Process::OUT) return;
            // Plain-text stream — forward chunks as they arrive.
            $fullResponse .= $data;
            $onChunk($data);
        });

        $this->assertChatExit($process, $fullResponse, 'Antigravity');
        return trim($fullResponse);
    }

    // ─── ScriptedSpawnBackend ──────────────────────────────────────────

    public function prepareScriptedProcess(array $options): Process
    {
        $promptFile  = $options['prompt_file']  ?? throw new \InvalidArgumentException('prompt_file required');
        $logFile     = $options['log_file']     ?? throw new \InvalidArgumentException('log_file required');
        $projectRoot = $options['project_root'] ?? throw new \InvalidArgumentException('project_root required');

        $promptText = @file_get_contents($promptFile);
        if ($promptText === false) {
            throw new \RuntimeException("Antigravity: cannot read prompt file {$promptFile}");
        }

        $flags = ['-p', $promptText, '--dangerously-skip-permissions'];
        $model = AntigravityModelResolver::resolve($options['model'] ?? null);
        if ($model !== null) {
            $flags[] = '--model';
            $flags[] = $model;
        }
        foreach ((array) ($options['extra_cli_flags'] ?? []) as $f) {
            $flags[] = (string) $f;
        }

        return $this->buildWrappedProcess(
            engineKey:      AiProvider::BACKEND_ANTIGRAVITY,
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

    /**
     * Assemble one headless invocation. `--print-timeout` mirrors our
     * process timeout so agy's server-side print wait (default 5m) never
     * undercuts a caller who asked for longer.
     *
     * @param  array<string,mixed> $options
     * @return list<string>
     */
    protected function buildCommand(string $prompt, ?string $model, array $options): array
    {
        $cmd = [$this->binary, '-p', $prompt, '--dangerously-skip-permissions'];

        $timeout = (int) ($options['timeout'] ?? $this->timeout);
        if ($timeout > 0) {
            $cmd[] = '--print-timeout';
            $cmd[] = $timeout . 's';
        }

        if ($model !== null) {
            $cmd[] = '--model';
            $cmd[] = $model;
        }

        // Resume surfaces — parity with the other CLI backends' option
        // names. agy resumes by conversation id or most-recent.
        if (!empty($options['resume_session_id'])) {
            $cmd[] = '--conversation';
            $cmd[] = (string) $options['resume_session_id'];
        } elseif (!empty($options['continue_session'])) {
            $cmd[] = '--continue';
        }

        return $cmd;
    }

    /**
     * Canonical envelope from plain-text output. agy reports no token
     * usage in print mode; the subscription channel books $0 anyway.
     *
     * @param  array<string,mixed> $extra
     * @return array<string,mixed>
     */
    protected function buildEnvelope(string $text, ?string $model, array $extra = []): array
    {
        $env = [
            'text'  => $text,
            'model' => $model ?? 'antigravity-default',
            'usage' => [
                'input_tokens'  => 0,
                'output_tokens' => 0,
            ],
            'stop_reason' => null,
        ];
        return $extra === [] ? $env : array_merge($env, $extra);
    }

    /**
     * Flatten `{role, content}` messages into one prompt string — same
     * fallback shape the other CLI backends use.
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
}
