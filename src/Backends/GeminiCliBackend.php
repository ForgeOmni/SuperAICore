<?php

namespace SuperAICore\Backends;

use SuperAICore\Backends\Concerns\BuildsScriptedProcess;
use SuperAICore\Backends\Concerns\StreamableProcess;
use SuperAICore\Contracts\Backend;
use SuperAICore\Contracts\ScriptedSpawnBackend;
use SuperAICore\Contracts\StreamingBackend;
use SuperAICore\Models\AiProvider;
use SuperAICore\Services\GeminiModelResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Spawns Google's `gemini` CLI (open-source at github.com/google-gemini/gemini-cli).
 *
 * Auth modes honoured (same precedence the CLI itself uses):
 *   1. GEMINI_API_KEY / GOOGLE_API_KEY env — direct Google AI Studio
 *   2. Vertex AI: GOOGLE_CLOUD_PROJECT + GOOGLE_CLOUD_LOCATION + GOOGLE_GENAI_USE_VERTEXAI=true
 *   3. `gemini` local login (OAuth with a Google account) — no env vars needed
 *
 * Token usage is extracted from `gemini --output-format=json`, whose
 * single-blob response includes per-model `stats.models.<id>.tokens`.
 * We pick the model whose role is "main" (vs. "utility_router" side
 * calls that inflate counts but don't represent the user-facing answer).
 */
class GeminiCliBackend implements Backend, StreamingBackend, ScriptedSpawnBackend
{
    use StreamableProcess;
    use BuildsScriptedProcess;

    public function __construct(
        protected string $binary = 'gemini',
        protected int $timeout = 300,
        protected ?LoggerInterface $logger = null,
    ) {}

    public function name(): string
    {
        return 'gemini_cli';
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
        $model = GeminiModelResolver::resolve($options['model'] ?? $providerConfig['model'] ?? null);

        $cmd = [$this->binary, '--output-format=json', '--yolo'];
        if ($model) {
            $cmd[] = '--model';
            $cmd[] = $model;
        }
        $cmd[] = '-p';
        $cmd[] = $prompt;

        $env = [];
        if (!empty($providerConfig['api_key'])) {
            $env['GEMINI_API_KEY'] = $providerConfig['api_key'];
        }
        // Vertex AI passthrough
        $extra = $providerConfig['extra_config'] ?? [];
        if (!empty($extra['project_id'])) {
            $env['GOOGLE_CLOUD_PROJECT'] = $extra['project_id'];
            $env['GOOGLE_GENAI_USE_VERTEXAI'] = 'true';
        }
        if (!empty($extra['region'])) {
            $env['GOOGLE_CLOUD_LOCATION'] = $extra['region'];
        }

        try {
            $process = new Process($cmd, null, $env);
            $process->setTimeout($this->timeout);
            $process->run();

            if (!$process->isSuccessful()) {
                if ($this->logger) $this->logger->warning('GeminiCliBackend failed: ' . $process->getErrorOutput());
                return null;
            }

            $parsed = $this->parseJson($process->getOutput());
            if (!$parsed || $parsed['text'] === '') return null;

            return [
                'text'        => $parsed['text'],
                'model'       => $parsed['model'] ?? $model ?? 'gemini-default',
                'usage'       => [
                    'input_tokens'         => $parsed['input_tokens'],
                    'output_tokens'        => $parsed['output_tokens'],
                    'cached_input_tokens'  => $parsed['cached_input_tokens'],
                    'thoughts_tokens'      => $parsed['thoughts_tokens'],
                ],
                'stop_reason' => null,
            ];
        } catch (\Throwable $e) {
            if ($this->logger) $this->logger->warning("GeminiCliBackend error: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Streaming variant — gemini's `--output-format=json` is single-blob
     * (no NDJSON streaming mode in current CLI), so chunks delivered
     * during the run are partial JSON. We tee them all to disk for live
     * tailing, then parse the assembled blob at exit.
     *
     * @see Contracts\StreamingBackend for the full options spec.
     */
    public function stream(array $options): ?array
    {
        $providerConfig = $options['provider_config'] ?? [];
        $prompt = $options['prompt'] ?? '';
        if ($prompt === '') return null;

        $model = GeminiModelResolver::resolve($options['model'] ?? $providerConfig['model'] ?? null);

        $cmd = [$this->binary, '--output-format=json', '--yolo'];
        if ($model) {
            $cmd[] = '--model';
            $cmd[] = $model;
        }
        $cmd[] = '-p';
        $cmd[] = $prompt;

        $env = [];
        if (!empty($providerConfig['api_key'])) {
            $env['GEMINI_API_KEY'] = $providerConfig['api_key'];
        }
        $extra = $providerConfig['extra_config'] ?? [];
        if (!empty($extra['project_id'])) {
            $env['GOOGLE_CLOUD_PROJECT'] = $extra['project_id'];
            $env['GOOGLE_GENAI_USE_VERTEXAI'] = 'true';
        }
        if (!empty($extra['region'])) {
            $env['GOOGLE_CLOUD_LOCATION'] = $extra['region'];
        }

        try {
            $process = new Process($cmd, null, $env);
            $result = $this->runStreaming(
                process: $process,
                backend: $this->name(),
                commandSummary: $this->binary . ' --output-format=json -p' . ($model ? " --model {$model}" : ''),
                logFile: $options['log_file'] ?? null,
                timeout: $options['timeout'] ?? null,
                idleTimeout: $options['idle_timeout'] ?? null,
                onChunk: $options['onChunk'] ?? null,
                externalLabel: $options['external_label'] ?? null,
                monitorMetadata: $options['metadata'] ?? [],
                cwd: $options['cwd'] ?? null,
            );
        } catch (\Throwable $e) {
            if ($this->logger) $this->logger->warning("GeminiCliBackend stream error: {$e->getMessage()}");
            return null;
        }

        $parsed = $this->parseJson($result['captured']);
        if (!$parsed) {
            return [
                'text'        => '',
                'model'       => $model ?? 'gemini-default',
                'usage'       => [],
                'log_file'    => $result['log_file'],
                'duration_ms' => $result['duration_ms'],
                'exit_code'   => $result['exit_code'],
            ];
        }

        return [
            'text'        => $parsed['text'],
            'model'       => $parsed['model'] ?? $model ?? 'gemini-default',
            'usage'       => [
                'input_tokens'        => $parsed['input_tokens'],
                'output_tokens'       => $parsed['output_tokens'],
                'cached_input_tokens' => $parsed['cached_input_tokens'],
                'thoughts_tokens'     => $parsed['thoughts_tokens'],
            ],
            'stop_reason' => null,
            'log_file'    => $result['log_file'],
            'duration_ms' => $result['duration_ms'],
            'exit_code'   => $result['exit_code'],
        ];
    }

    /**
     * Parse the single-blob JSON gemini-cli emits under `--output-format=json`.
     * Public for testing.
     *
     * Token naming quirk: Gemini reports `input` and `candidates` rather
     * than the OpenAI-style `input`/`output`. We normalize to our
     * `input_tokens`/`output_tokens` contract.
     *
     * @return array{text:string, model:?string, input_tokens:int, output_tokens:int, cached_input_tokens:int, thoughts_tokens:int}|null
     */
    public function parseJson(string $output): ?array
    {
        $output = trim($output);
        if ($output === '') return null;

        // Gemini CLI prepends preamble noise to stdout before the JSON blob
        // depending on flags and environment. Observed prefixes: "YOLO mode
        // is enabled. All tool calls will be automatically approved." (often
        // twice), "MCP issues detected. Run /mcp list for status.", deprecation
        // warnings, etc. These may or may not have a trailing newline before
        // the JSON opening brace. A strict `$output[0] !== '{'` check dropped
        // the whole result (→ text='' → TaskRunner flagged success=false →
        // Pipeline's spawn-plan handoff was skipped while _spawn_plan.json
        // sat orphaned in the output dir — see RUN 65, 2026-04-22). Locate
        // the first `{` and decode from there; json_decode itself rejects
        // the case where the `{` is inside a preamble sentence rather than
        // starting a real object.
        if ($output[0] !== '{') {
            $start = strpos($output, '{');
            if ($start === false) return null;
            $output = substr($output, $start);
        }

        $data = json_decode($output, true);
        if (!is_array($data) || !isset($data['response'])) return null;

        $models = $data['stats']['models'] ?? [];
        $primary = $this->pickPrimaryModel($models);
        $tokens = $primary !== null ? ($models[$primary]['tokens'] ?? []) : [];

        return [
            'text'                => is_string($data['response']) ? $data['response'] : '',
            'model'               => $primary,
            'input_tokens'        => (int) ($tokens['input'] ?? $tokens['prompt'] ?? 0),
            'output_tokens'       => (int) ($tokens['candidates'] ?? 0),
            'cached_input_tokens' => (int) ($tokens['cached'] ?? 0),
            'thoughts_tokens'     => (int) ($tokens['thoughts'] ?? 0),
        ];
    }

    /**
     * Pick the main model by scanning `stats.models[x].roles.main`. Falls
     * back to the model with the highest output (candidates) count, then
     * the first key.
     */
    private function pickPrimaryModel(array $models): ?string
    {
        if (!$models) return null;
        foreach ($models as $id => $stats) {
            if (isset($stats['roles']['main'])) {
                return $id;
            }
        }
        $best = null;
        $bestOut = -1;
        foreach ($models as $id => $stats) {
            $out = (int) ($stats['tokens']['candidates'] ?? 0);
            if ($out > $bestOut) {
                $best = $id;
                $bestOut = $out;
            }
        }
        return $best ?: array_key_first($models);
    }

    // ─── ScriptedSpawnBackend ──────────────────────────────────────────

    /**
     * Gemini-specific scripted spawn. Gemini CLI is invoked with an
     * empty `--prompt ''` arg plus `--yolo` (non-interactive tool
     * approval), streams NDJSON (stream-json) output. Prompt flows on
     * stdin like Claude/Codex. Capability transform is applied to the
     * prompt file before spawn (handled by `buildWrappedProcess`).
     *
     * Hosts can defer the `superteam:gemini-sync` artisan call (lazy
     * `.gemini/commands/*.toml` refresh) via the `pre_spawn_hook`
     * option — the backend doesn't run it itself because it's host-
     * specific.
     */
    public function prepareScriptedProcess(array $options): Process
    {
        $promptFile  = $options['prompt_file']  ?? throw new \InvalidArgumentException('prompt_file required');
        $logFile     = $options['log_file']     ?? throw new \InvalidArgumentException('log_file required');
        $projectRoot = $options['project_root'] ?? throw new \InvalidArgumentException('project_root required');
        $model       = $options['model']        ?? null;
        $env         = (array) ($options['env'] ?? []);

        $resolvedModel = $model ? GeminiModelResolver::resolve($model) : null;

        // Non-interactive mode: `--prompt ''` + `--yolo` auto-approves
        // tool calls so the stdin pipe isn't blocked waiting on prompts.
        $flags = ['--prompt', '', '--yolo', '-o', 'stream-json'];
        if ($resolvedModel) {
            $flags[] = '--model';
            $flags[] = $resolvedModel;
        }
        foreach ((array) ($options['extra_cli_flags'] ?? []) as $f) {
            $flags[] = (string) $f;
        }

        return $this->buildWrappedProcess(
            engineKey:      AiProvider::BACKEND_GEMINI,
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
     * One-shot chat — Gemini: prompt as argv `-p <string>`, single JSON
     * blob output (not streamed line-by-line; accumulate and parse).
     */
    public function streamChat(string $prompt, callable $onChunk, array $options = []): string
    {
        $cliPath = app(\SuperAICore\Support\CliBinaryLocator::class)->find(AiProvider::BACKEND_GEMINI);
        $args = [$cliPath, '--output-format=json', '--yolo'];

        $resolvedModel = !empty($options['model']) ? GeminiModelResolver::resolve($options['model']) : null;
        if ($resolvedModel) {
            $args[] = '--model';
            $args[] = $resolvedModel;
        }
        $args[] = '-p';
        $args[] = $prompt;

        $env = (array) ($options['env'] ?? []);
        $process = new Process($args, $options['cwd'] ?? null, $env);
        $process->setTimeout((int) ($options['timeout'] ?? 0));
        $process->setIdleTimeout((int) ($options['idle_timeout'] ?? 300));

        $buffer = '';
        $fullResponse = '';
        $process->start();
        $process->wait(function (string $type, string $data) use (&$buffer) {
            if ($type === Process::OUT) $buffer .= $data;
        });

        // Parse single JSON blob (skip preamble noise like "YOLO mode
        // is enabled." / "MCP issues detected." lines before first `{`).
        $buffer = trim($buffer);
        $startPos = strpos($buffer, '{');
        if ($startPos !== false) {
            $jsonPart = substr($buffer, $startPos);
            $data = json_decode($jsonPart, true);
            if (is_array($data)) {
                // gemini JSON: response.text OR response.candidates[0].content.parts[*].text
                $text = $data['response']['text']
                    ?? $data['response']['candidates'][0]['content']['parts'][0]['text']
                    ?? '';
                if (is_string($text) && $text !== '') {
                    $fullResponse = $text;
                    $onChunk($text);
                }
            }
        }

        if ($process->getExitCode() !== 0 && $fullResponse === '') {
            $stderr = $process->getErrorOutput();
            if ($this->logger) {
                $this->logger->error("GeminiCliBackend chat failed (exit {$process->getExitCode()}): {$stderr}");
            }
            throw new \RuntimeException("Gemini chat failed (exit {$process->getExitCode()})");
        }

        return $fullResponse;
    }
}
