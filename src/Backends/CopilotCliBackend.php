<?php

namespace SuperAICore\Backends;

use SuperAICore\Backends\Concerns\BuildsScriptedProcess;
use SuperAICore\Backends\Concerns\StreamableProcess;
use SuperAICore\Contracts\Backend;
use SuperAICore\Contracts\ScriptedSpawnBackend;
use SuperAICore\Contracts\StreamingBackend;
use SuperAICore\Models\AiProvider;
use SuperAICore\Services\CopilotModelResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Spawns GitHub's `copilot` CLI (github/copilot-cli, GA 2026-02).
 *
 * Auth is delegated entirely to the official binary — the CLI handles
 * OAuth device flow, keychain storage, and session-token refresh. Headless
 * runners can pre-set COPILOT_GITHUB_TOKEN / GH_TOKEN / GITHUB_TOKEN and
 * `copilot` will auto-pick the right one. We pass through `provider_config`
 * the same way the other CLI backends do, but the default (`builtin`) just
 * lets the local `copilot login` state carry the request.
 */
class CopilotCliBackend implements Backend, StreamingBackend, ScriptedSpawnBackend
{
    use StreamableProcess;
    use BuildsScriptedProcess;

    public function __construct(
        protected string $binary = 'copilot',
        protected int $timeout = 300,
        protected bool $allowAllTools = true,
        protected ?LoggerInterface $logger = null,
    ) {}

    public function name(): string
    {
        return 'copilot_cli';
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
        // Translate Claude-CLI dashes → copilot dots and fall back to the
        // latest routable sibling when an exact version isn't wired up yet.
        $model = CopilotModelResolver::resolve($model);

        // JSONL mode lets us extract:
        //   - the actual response text (assembled from assistant.message events)
        //   - the model the router picked (session.tools_updated.data.model)
        //   - output_tokens (sum of assistant.message.outputTokens)
        //   - premium_requests (from terminal `result` event — subscription metric)
        //
        // Copilot does NOT report input_tokens (billing is request-based),
        // so we leave that field 0 and let the cost calculator's subscription
        // pricing handle the $0 contribution to USD totals.
        $cmd = [$this->binary, '-p', $prompt, '--output-format=json'];
        if ($this->allowAllTools) {
            $cmd[] = '--allow-all-tools';
        }
        if ($model) {
            $cmd[] = '--model';
            $cmd[] = $model;
        }

        try {
            $env = $this->buildEnv($providerConfig);
            $process = new Process($cmd, null, $env);
            $process->setTimeout($this->timeout);
            $process->run();

            if (!$process->isSuccessful()) {
                if ($this->logger) $this->logger->warning('CopilotCliBackend failed: ' . $process->getErrorOutput());
                return null;
            }

            $parsed = $this->parseJsonl($process->getOutput());
            if (!$parsed || $parsed['text'] === '') return null;

            return [
                'text'  => $parsed['text'],
                'model' => $parsed['model'] ?? $model ?? 'copilot-default',
                'usage' => [
                    'input_tokens'     => 0,
                    'output_tokens'    => $parsed['output_tokens'],
                    'premium_requests' => $parsed['premium_requests'],
                ],
                'stop_reason' => $parsed['exit_code'] === 0 ? 'end_turn' : 'error',
            ];
        } catch (\Throwable $e) {
            if ($this->logger) $this->logger->warning("CopilotCliBackend error: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Streaming variant — `copilot -p ... --output-format=json` is already
     * JSONL (one event per line), so chunks delivered during the run are
     * naturally complete events that hosts can parse incrementally if
     * desired. Tee'd to a log file + Process Monitor row + chunk callback.
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
        $model = CopilotModelResolver::resolve($model);

        $cmd = [$this->binary, '-p', $prompt, '--output-format=json'];
        if ($this->allowAllTools) {
            $cmd[] = '--allow-all-tools';
        }
        if ($model) {
            $cmd[] = '--model';
            $cmd[] = $model;
        }

        try {
            $env = $this->buildEnv($providerConfig);
            $process = new Process($cmd, null, $env);
            $result = $this->runStreaming(
                process: $process,
                backend: $this->name(),
                commandSummary: $this->binary . ' -p --output-format=json' . ($model ? " --model {$model}" : ''),
                logFile: $options['log_file'] ?? null,
                timeout: $options['timeout'] ?? null,
                idleTimeout: $options['idle_timeout'] ?? null,
                onChunk: $options['onChunk'] ?? null,
                externalLabel: $options['external_label'] ?? null,
                monitorMetadata: $options['metadata'] ?? [],
                cwd: $options['cwd'] ?? null,
            );
        } catch (\Throwable $e) {
            if ($this->logger) $this->logger->warning("CopilotCliBackend stream error: {$e->getMessage()}");
            return null;
        }

        $parsed = $this->parseJsonl($result['captured']);
        if (!$parsed) {
            return [
                'text'        => '',
                'model'       => $model ?? 'copilot-default',
                'usage'       => [],
                'log_file'    => $result['log_file'],
                'duration_ms' => $result['duration_ms'],
                'exit_code'   => $result['exit_code'],
            ];
        }

        return [
            'text'  => $parsed['text'],
            'model' => $parsed['model'] ?? $model ?? 'copilot-default',
            'usage' => [
                'input_tokens'     => 0,
                'output_tokens'    => $parsed['output_tokens'],
                'premium_requests' => $parsed['premium_requests'],
            ],
            'stop_reason' => $parsed['exit_code'] === 0 ? 'end_turn' : 'error',
            'log_file'    => $result['log_file'],
            'duration_ms' => $result['duration_ms'],
            'exit_code'   => $result['exit_code'],
        ];
    }

    /**
     * Parse Copilot's JSONL stream (one event per line) into a flat result.
     *
     * Public for testing — callers that already have a captured JSONL string
     * can reuse the parser without spawning a process.
     *
     * @return array{text:string, model:?string, output_tokens:int, premium_requests:int, exit_code:int}|null
     */
    public function parseJsonl(string $output): ?array
    {
        $text = '';
        $model = null;
        $outputTokens = 0;
        $premiumRequests = 0;
        $exitCode = 0;
        $sawAny = false;

        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '{') continue;
            $event = json_decode($line, true);
            if (!is_array($event)) continue;
            $sawAny = true;

            $type = $event['type'] ?? '';
            $data = $event['data'] ?? [];

            switch ($type) {
                case 'session.tools_updated':
                    if (!empty($data['model']) && is_string($data['model'])) {
                        $model = $data['model'];
                    }
                    break;

                case 'assistant.message':
                    if (!empty($data['content']) && is_string($data['content'])) {
                        $text .= $data['content'];
                    }
                    if (isset($data['outputTokens']) && is_numeric($data['outputTokens'])) {
                        $outputTokens += (int) $data['outputTokens'];
                    }
                    break;

                case 'result':
                    $exitCode = (int) ($event['exitCode'] ?? 0);
                    $usage = $event['usage'] ?? [];
                    if (isset($usage['premiumRequests']) && is_numeric($usage['premiumRequests'])) {
                        $premiumRequests = (int) $usage['premiumRequests'];
                    }
                    break;
            }
        }

        if (!$sawAny) return null;

        return [
            'text'             => trim($text),
            'model'            => $model,
            'output_tokens'    => $outputTokens,
            'premium_requests' => $premiumRequests,
            'exit_code'        => $exitCode,
        ];
    }

    /**
     * Map provider_config to the GitHub-token env vars the CLI inspects.
     * The `builtin` type leaves env empty and relies on local `copilot login`.
     */
    protected function buildEnv(array $providerConfig): array
    {
        $env = [];
        $type = $providerConfig['type'] ?? 'builtin';

        if ($type !== 'builtin' && !empty($providerConfig['api_key'])) {
            $env['COPILOT_GITHUB_TOKEN'] = $providerConfig['api_key'];
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
     * Copilot scripted spawn — Copilot CLI passes the prompt as `-p
     * <text>` (argv, not stdin), so we inline the prompt file's body
     * and invoke the trait with `stdinMode: 'devnull'`.
     */
    public function prepareScriptedProcess(array $options): Process
    {
        $promptFile  = $options['prompt_file']  ?? throw new \InvalidArgumentException('prompt_file required');
        $logFile     = $options['log_file']     ?? throw new \InvalidArgumentException('log_file required');
        $projectRoot = $options['project_root'] ?? throw new \InvalidArgumentException('project_root required');

        $promptText = @file_get_contents($promptFile);
        if ($promptText === false) {
            throw new \RuntimeException("Copilot: cannot read prompt file {$promptFile}");
        }

        $resolvedModel = !empty($options['model']) ? CopilotModelResolver::resolve($options['model']) : null;

        $flags = ['-p', $promptText, '--allow-all-tools', '--output-format', 'json'];
        if ($resolvedModel) {
            $flags[] = '--model';
            $flags[] = $resolvedModel;
        }
        foreach ((array) ($options['extra_cli_flags'] ?? []) as $f) {
            $flags[] = (string) $f;
        }

        return $this->buildWrappedProcess(
            engineKey:      AiProvider::BACKEND_COPILOT,
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
     * One-shot chat — Copilot CLI: `copilot -p "<prompt>" -s` plain text.
     */
    public function streamChat(string $prompt, callable $onChunk, array $options = []): string
    {
        $cliPath = app(\SuperAICore\Support\CliBinaryLocator::class)->find(AiProvider::BACKEND_COPILOT);
        $args = [$cliPath, '-p', $prompt, '-s'];

        $resolvedModel = !empty($options['model']) ? CopilotModelResolver::resolve($options['model']) : null;
        if ($resolvedModel) {
            $args[] = '--model';
            $args[] = $resolvedModel;
        }

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

        $this->assertChatExit($process, $fullResponse, 'Copilot');
        return $fullResponse;
    }
}
