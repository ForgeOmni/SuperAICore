<?php

namespace SuperAICore\Services;

/**
 * Unified short-name routing — ai-dispatch parity (`config.json` `models`).
 *
 * Resolves a single target token (`opus`, `kimi`, `gemini-pro`, a full
 * model id, or a raw backend name) to an ordered pool of
 * `{backend, model}` candidates that `superaicore send` tries in order.
 *
 * Precedence (mirrors ai-dispatch's model-resolution order):
 *   1. user config  — `super-ai-core.dispatch.aliases` (candidate arrays win
 *      wholesale over the seed for that alias)
 *   2. built-in registry — BUILTIN below
 *   3. backend passthrough — target is a dispatcher backend name
 *   4. inference — model-id substring → engine CLI; otherwise the default
 *      backend carries the token as a model id
 *
 * Unlike the per-engine ModelResolvers (which map a family alias to a model
 * WITHIN an already-chosen backend), the AliasRouter picks the backend AND
 * the model in one step.
 */
class AliasRouter
{
    /**
     * Seed alias pool. Family aliases (`opus`, `pro`, …) stay symbolic —
     * each engine's own resolver / CLI turns them into full ids at spawn
     * time, so this table doesn't rot when models rev.
     *
     * @var array<string, list<array{backend: string, model: ?string}>>
     */
    public const BUILTIN = [
        'claude'       => [['backend' => 'claude_cli',  'model' => null]],
        'fable'        => [['backend' => 'claude_cli',  'model' => 'fable']],
        'opus'         => [['backend' => 'claude_cli',  'model' => 'opus']],
        'sonnet'       => [['backend' => 'claude_cli',  'model' => 'sonnet']],
        'haiku'        => [['backend' => 'claude_cli',  'model' => 'haiku']],
        'codex'        => [['backend' => 'codex_cli',   'model' => null]],
        'gemini'       => [['backend' => 'gemini_cli',  'model' => null]],
        'gemini-pro'   => [['backend' => 'gemini_cli',  'model' => 'pro']],
        'gemini-flash' => [['backend' => 'gemini_cli',  'model' => 'flash']],
        'copilot'      => [['backend' => 'copilot_cli', 'model' => null]],
        'kimi'         => [['backend' => 'kimi_cli',    'model' => null]],
        'qwen'         => [['backend' => 'qwen_cli',    'model' => null]],
        'cursor'       => [['backend' => 'cursor_cli',  'model' => null]],
        'composer'     => [['backend' => 'cursor_cli',  'model' => null]],
        'grok'         => [['backend' => 'grok_cli',    'model' => null]],
        'kiro'         => [['backend' => 'kiro_cli',    'model' => null]],
        'superagent'   => [['backend' => 'superagent',  'model' => null]],
    ];

    /**
     * Backend names accepted as passthrough targets even when no
     * BackendRegistry is injected (standalone unit tests, docs tooling).
     */
    protected const KNOWN_BACKENDS = [
        'anthropic_api', 'openai_api', 'gemini_api', 'superagent', 'squad',
        'claude_cli', 'codex_cli', 'gemini_cli', 'copilot_cli', 'kiro_cli',
        'kimi_cli', 'qwen_cli', 'cursor_cli', 'grok_cli',
    ];

    /**
     * Model-id substring → engine backend, tried in order. ORDER MATTERS:
     * `resolve()` returns on the first substring hit, so a brand needle must
     * precede any generic needle it could contain. In particular `grok` must
     * come before `composer` — `grok-composer-2.5-fast` (a real Grok CLI id)
     * contains `composer` and would otherwise misroute to `cursor_cli`.
     */
    protected const INFERENCE = [
        'claude'   => 'claude_cli',
        'codex'    => 'codex_cli',
        'gpt'      => 'codex_cli',
        'gemini'   => 'gemini_cli',
        'kimi'     => 'kimi_cli',
        'qwen'     => 'qwen_cli',
        'grok'     => 'grok_cli',
        'composer' => 'cursor_cli',
    ];

    public function __construct(
        protected ?BackendRegistry $backends = null,
        protected ?array $configAliases = null,
        protected ?string $defaultBackend = null,
    ) {}

    /**
     * @return array{
     *   requested: string,
     *   source: 'config'|'builtin'|'backend'|'inference'|'default',
     *   candidates: list<array{backend: string, model: ?string}>,
     * }
     */
    public function resolve(string $target): array
    {
        $requested = trim($target);
        $key = mb_strtolower($requested);

        $config = $this->configAliases();
        if (isset($config[$key])) {
            return ['requested' => $requested, 'source' => 'config', 'candidates' => $config[$key]];
        }

        if (isset(self::BUILTIN[$key])) {
            return ['requested' => $requested, 'source' => 'builtin', 'candidates' => self::BUILTIN[$key]];
        }

        if ($this->isBackendName($key)) {
            return ['requested' => $requested, 'source' => 'backend', 'candidates' => [['backend' => $key, 'model' => null]]];
        }

        foreach (self::INFERENCE as $needle => $backend) {
            if (str_contains($key, $needle)) {
                return [
                    'requested' => $requested,
                    'source' => 'inference',
                    // Preserve the caller's casing — model ids are the
                    // engine's business, not ours to normalise.
                    'candidates' => [['backend' => $backend, 'model' => $requested]],
                ];
            }
        }

        return [
            'requested' => $requested,
            'source' => 'default',
            'candidates' => [['backend' => $this->defaultBackend(), 'model' => $requested !== '' ? $requested : null]],
        ];
    }

    /**
     * Every alias visible to `superaicore aliases` / the dispatch SKILL —
     * config entries override the built-in seed per key.
     *
     * @return array<string, list<array{backend: string, model: ?string}>>
     */
    public function all(): array
    {
        $all = array_merge(self::BUILTIN, $this->configAliases());
        ksort($all);
        return $all;
    }

    /** @return array<string, list<array{backend: string, model: ?string}>> */
    protected function configAliases(): array
    {
        $raw = $this->configAliases
            ?? \SuperAICore\Support\ConfigValue::get('super-ai-core.dispatch.aliases')
            ?? [];

        $out = [];
        foreach ((array) $raw as $alias => $candidates) {
            $normalised = $this->normaliseCandidates($candidates);
            if ($normalised !== []) {
                $out[mb_strtolower(trim((string) $alias))] = $normalised;
            }
        }
        return $out;
    }

    /**
     * Accepts a single candidate map, a list of candidate maps, or the
     * compact `'backend:model'` / `'backend'` string forms.
     *
     * @return list<array{backend: string, model: ?string}>
     */
    protected function normaliseCandidates(mixed $candidates): array
    {
        if (is_string($candidates)) {
            $candidates = [$candidates];
        }
        if (!is_array($candidates)) return [];
        if (isset($candidates['backend'])) {
            $candidates = [$candidates];
        }

        $out = [];
        foreach ($candidates as $candidate) {
            if (is_string($candidate)) {
                [$backend, $model] = array_pad(explode(':', $candidate, 2), 2, null);
                $candidate = ['backend' => $backend, 'model' => $model];
            }
            if (!is_array($candidate)) continue;
            $backend = trim((string) ($candidate['backend'] ?? ''));
            if ($backend === '') continue;
            $model = $candidate['model'] ?? null;
            $out[] = [
                'backend' => $backend,
                'model' => is_string($model) && trim($model) !== '' ? trim($model) : null,
            ];
        }
        return $out;
    }

    protected function isBackendName(string $key): bool
    {
        if ($this->backends !== null) {
            return $this->backends->get($key) !== null
                // A disabled-but-known backend name should still route as a
                // backend (and then fail with a clear "not registered"),
                // not fall through to model inference.
                || in_array($key, self::KNOWN_BACKENDS, true);
        }
        return in_array($key, self::KNOWN_BACKENDS, true);
    }

    protected function defaultBackend(): string
    {
        if ($this->defaultBackend !== null) return $this->defaultBackend;
        return (string) \SuperAICore\Support\ConfigValue::get('super-ai-core.default_backend', 'anthropic_api');
    }
}
