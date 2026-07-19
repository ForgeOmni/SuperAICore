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
 * Auth modes we inject via env:
 *   1. GEMINI_API_KEY / GOOGLE_API_KEY env — direct Google AI Studio
 *   2. Vertex AI: GOOGLE_CLOUD_PROJECT + GOOGLE_CLOUD_LOCATION + GOOGLE_GENAI_USE_VERTEXAI=true
 *   3. `gemini` local login (OAuth with a Google account) — no env vars needed
 *
 * CAVEAT (verified against gemini-cli 0.29.5 source): in non-interactive
 * mode the CLI resolves auth as `settings.security.auth.selectedType`
 * FIRST, env second. On a machine where the user once completed
 * interactive OAuth login, `~/.gemini/settings.json` pins
 * `selectedType: "oauth-personal"` and a `GEMINI_API_KEY` we inject is
 * silently ignored — billing rides the OAuth account, not the supplied
 * key. generate()/stream() log a warning when they detect this.
 *
 * Token usage is extracted from `gemini --output-format=json`, whose
 * single-blob response includes per-model `stats.models.<id>.tokens`.
 * Older builds tagged the answering model `roles: "main"`; 0.29.x dropped
 * the roles key and instead routes every prompt through a
 * `gemini-2.5-flash-lite` classifier side-call, so pickPrimaryModel()
 * excludes `*flash-lite*` utility ids before falling back to
 * max-candidates.
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

    /**
     * Native-skill probe results per binary, so the one-shot
     * `skills --help` spawn runs at most once per process.
     *
     * @var array<string,bool>
     */
    protected static array $nativeSkillsCache = [];

    /**
     * Does this gemini build ship the native skill protocol (`gemini
     * skills` command group + ~/.gemini/skills auto-discovery)? Introduced
     * in the 0.29 line; older builds exit non-zero on the unknown command.
     */
    public static function supportsNativeSkills(string $binary = 'gemini'): bool
    {
        if (!array_key_exists($binary, self::$nativeSkillsCache)) {
            try {
                $probe = new Process([$binary, 'skills', '--help']);
                $probe->setTimeout(10);
                $probe->run();
                self::$nativeSkillsCache[$binary] = $probe->isSuccessful();
            } catch (\Throwable) {
                self::$nativeSkillsCache[$binary] = false;
            }
        }
        return self::$nativeSkillsCache[$binary];
    }

    /** Reset the native-skill probe cache (test seam). */
    public static function forgetNativeSkillsCache(): void
    {
        self::$nativeSkillsCache = [];
    }

    /**
     * `--help` text per binary for feature sniffing (one spawn per process).
     *
     * @var array<string,string>
     */
    protected static array $helpCache = [];

    /**
     * Does this gemini build have the `--skip-trust` flag? gemini-cli ≥0.51
     * enforces workspace folder trust EVEN IN HEADLESS MODE: in an untrusted
     * cwd, `--yolo` is silently downgraded to `default` approval ("Approval
     * mode overridden…", observed live on 0.51.0), which stalls or denies
     * every tool call in a non-interactive run. `--skip-trust` trusts the
     * workspace for the session; older builds reject the unknown flag, so it
     * must be gated on this probe.
     */
    public static function supportsSkipTrust(string $binary = 'gemini'): bool
    {
        if (!array_key_exists($binary, self::$helpCache)) {
            try {
                $probe = new Process([$binary, '--help']);
                $probe->setTimeout(10);
                $probe->run();
                self::$helpCache[$binary] = $probe->getOutput() . "\n" . $probe->getErrorOutput();
            } catch (\Throwable) {
                self::$helpCache[$binary] = '';
            }
        }
        return str_contains(self::$helpCache[$binary], '--skip-trust');
    }

    /** Reset the --help probe cache (test seam). */
    public static function forgetHelpCache(): void
    {
        self::$helpCache = [];
    }

    /**
     * The non-interactive base flags: `--yolo`, plus `--skip-trust` on
     * builds that gate yolo behind folder trust (≥0.51).
     *
     * @return list<string>
     */
    public static function yoloFlags(string $binary = 'gemini'): array
    {
        return self::supportsSkipTrust($binary)
            ? ['--yolo', '--skip-trust']
            : ['--yolo'];
    }

    public function generate(array $options): ?array
    {
        $providerConfig = $options['provider_config'] ?? [];
        $prompt = $options['prompt'] ?? '';
        $model = GeminiModelResolver::resolve($options['model'] ?? $providerConfig['model'] ?? null);

        // Pi-style progressive-disclosure skill index. gemini-cli ≥0.29 has
        // a NATIVE skill protocol (`gemini skills`, auto-discovery of
        // ~/.gemini/skills/*/SKILL.md — where CliSkillBridge installs our
        // packs — plus an `activate_skill` tool), so on those builds we skip
        // the XML prepend to avoid exposing every skill twice. Older builds
        // keep the prepend. Honors --no-skills via $options['skills_disabled'].
        if (empty($options['skills_disabled']) && !self::supportsNativeSkills($this->binary)) {
            $skillXml = (new \SuperAICore\Services\SkillIndexBuilder())->buildFromConfig();
            if ($skillXml !== '') {
                $prompt = $skillXml . "\n\n" . $prompt;
            }
        }

        // `gemini --prompt ""` (empty value) tells gemini-cli to read the
        // actual prompt from stdin — same idiom GeminiSkillRunner uses.
        // Avoids Windows cmd-line escaping / 8K length issues for large
        // prompts (see SuperAICore CHANGELOG 0.8.8).
        $cmd = [$this->binary, '--output-format=json', ...self::yoloFlags($this->binary)];
        if ($model) {
            $cmd[] = '--model';
            $cmd[] = $model;
        }
        $cmd[] = '--prompt';
        $cmd[] = '';

        $env = [];
        if (!empty($providerConfig['api_key'])) {
            $env['GEMINI_API_KEY'] = $providerConfig['api_key'];
            $this->warnIfSettingsOverrideApiKey();
        } elseif (($providerConfig['type'] ?? 'builtin') === 'builtin' && empty($providerConfig['extra_config']['project_id'])) {
            // builtin = use the local `gemini` OAuth login (~/.gemini). The
            // user supplied no key, so scrub any stale inherited GEMINI_API_KEY
            // / GOOGLE_API_KEY (false = unset in child) and flip
            // GOOGLE_GENAI_USE_GCA so the CLI reaches for the OAuth login file
            // instead of an invalid/leftover console key (mirrors the same
            // fallback in prepareScriptedProcess()).
            $env['GEMINI_API_KEY'] = false;
            $env['GOOGLE_API_KEY'] = false;
            $env['GOOGLE_GENAI_USE_GCA'] = 'true';
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
            $process->setInput($prompt);
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

        // Stdin pipe — see generate() above for rationale.
        $cmd = [$this->binary, '--output-format=json', ...self::yoloFlags($this->binary)];
        if ($model) {
            $cmd[] = '--model';
            $cmd[] = $model;
        }
        $cmd[] = '--prompt';
        $cmd[] = '';

        $env = [];
        if (!empty($providerConfig['api_key'])) {
            $env['GEMINI_API_KEY'] = $providerConfig['api_key'];
            $this->warnIfSettingsOverrideApiKey();
        } elseif (($providerConfig['type'] ?? 'builtin') === 'builtin' && empty($providerConfig['extra_config']['project_id'])) {
            // builtin = use the local `gemini` OAuth login (~/.gemini). The
            // user supplied no key, so scrub any stale inherited GEMINI_API_KEY
            // / GOOGLE_API_KEY (false = unset in child) and flip
            // GOOGLE_GENAI_USE_GCA so the CLI reaches for the OAuth login file
            // instead of an invalid/leftover console key (mirrors the same
            // fallback in prepareScriptedProcess()).
            $env['GEMINI_API_KEY'] = false;
            $env['GOOGLE_API_KEY'] = false;
            $env['GOOGLE_GENAI_USE_GCA'] = 'true';
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
            $process->setInput($prompt);
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
    /**
     * gemini-cli (≥0.29, verified in 0.29.5 source) resolves non-interactive
     * auth as `settings.security.auth.selectedType` FIRST and env second, so
     * an injected GEMINI_API_KEY is silently ignored once the user has ever
     * completed interactive OAuth login. We can't override the setting
     * per-spawn without editing the user's file — log loudly instead.
     */
    private function warnIfSettingsOverrideApiKey(): void
    {
        if (!$this->logger) return;
        $home = getenv('HOME') ?: '';
        $file = $home !== '' ? $home . '/.gemini/settings.json' : '';
        if ($file === '' || !is_file($file)) return;
        $j = json_decode((string) @file_get_contents($file), true);
        if (!is_array($j)) return;
        // v2 nested key first; legacy flat key as fallback.
        $selected = $j['security']['auth']['selectedType'] ?? $j['selectedAuthType'] ?? null;
        if (is_string($selected) && $selected !== '' && $selected !== 'gemini-api-key') {
            $this->logger->warning(
                'GeminiCliBackend: provider api_key injected as GEMINI_API_KEY, but '
                . "~/.gemini/settings.json pins auth selectedType={$selected}, which the CLI "
                . 'prefers in non-interactive mode — the key may be ignored and billing/quota '
                . 'will ride that login instead.',
            );
        }
    }

    private function pickPrimaryModel(array $models): ?string
    {
        if (!$models) return null;
        // Pre-0.29 builds tagged the answering model with roles.main.
        foreach ($models as $id => $stats) {
            if (isset($stats['roles']['main'])) {
                return $id;
            }
        }
        // 0.29.x dropped `roles` and routes every prompt through a
        // flash-lite classifier side-call whose token counts can rival the
        // real answer's — exclude known router/utility ids before the
        // max-candidates fallback (unless they're all that's left).
        $candidates = array_filter(
            $models,
            static fn ($id) => !str_contains((string) $id, 'flash-lite'),
            ARRAY_FILTER_USE_KEY,
        ) ?: $models;

        $best = null;
        $bestOut = -1;
        foreach ($candidates as $id => $stats) {
            $out = (int) ($stats['tokens']['candidates'] ?? 0);
            if ($out > $bestOut) {
                $best = $id;
                $bestOut = $out;
            }
        }
        return $best ?: array_key_first($candidates);
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

        // Gemini CLI env auto-fallback: when the caller passed neither
        // GEMINI_API_KEY nor GOOGLE_API_KEY, flip `GOOGLE_GENAI_USE_GCA`
        // so the CLI reaches for gcloud ADC / OAuth login file instead
        // of failing with "no API key". Was previously in host's
        // `applyBackendSpecificEnv` — lives here so any host that
        // doesn't carry that post-processor still gets the fallback.
        if (empty($env['GEMINI_API_KEY']) && empty($env['GOOGLE_API_KEY'])) {
            $env['GOOGLE_GENAI_USE_GCA'] = 'true';
        }

        $resolvedModel = $model ? GeminiModelResolver::resolve($model) : null;

        // Non-interactive mode: `--prompt ''` + `--yolo` auto-approves
        // tool calls so the stdin pipe isn't blocked waiting on prompts.
        $flags = ['--prompt', '', ...self::yoloFlags($this->binary), '-o', 'stream-json'];
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
        $args = [$cliPath, '--output-format=json', ...self::yoloFlags($cliPath)];

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
                // gemini ≥0.29 emits `response` as a plain STRING (same shape
                // parseJson() handles); older builds nested it as
                // response.text / response.candidates[0].content.parts[*].text.
                // The string check must come first — the old offset lookups
                // silently miss on a string and returned '' for every run.
                $resp = $data['response'] ?? null;
                $text = is_string($resp)
                    ? $resp
                    : ($resp['text']
                        ?? $resp['candidates'][0]['content']['parts'][0]['text']
                        ?? '');
                if (is_string($text) && $text !== '') {
                    $fullResponse = $text;
                    $onChunk($text);
                }
            }
        }

        $this->assertChatExit($process, $fullResponse, 'Gemini');
        return $fullResponse;
    }
}
