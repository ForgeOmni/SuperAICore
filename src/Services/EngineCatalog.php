<?php

namespace SuperAICore\Services;

use SuperAICore\Models\AiProvider;
use SuperAICore\Support\EngineDescriptor;
use SuperAICore\Support\ProcessSpec;

/**
 * Single source of truth for execution-engine metadata.
 *
 * Host apps and Blade templates ask the catalog for labels / icons /
 * dispatcher backends / available models instead of duplicating arrays.
 * Adding a new CLI engine here propagates everywhere automatically:
 *
 *   - providers UI label & icon
 *   - process monitor keyword scan
 *   - BackendState dispatcher→engine mapping
 *   - host-app dropdowns / model pickers
 *
 * Defaults can be overridden via `super-ai-core.engines` (full replacement
 * per engine — partial override merges shallowly with the seeded entry).
 *
 * Resolve as a singleton:
 *
 *     app(EngineCatalog::class)->all()
 *     app(EngineCatalog::class)->get('copilot')
 *     app(EngineCatalog::class)->modelsFor('copilot')
 */
class EngineCatalog
{
    /** @var array<string, EngineDescriptor> */
    protected array $engines = [];

    public function __construct(?array $overrides = null)
    {
        $overrides = $overrides ?? (function_exists('config')
            ? (config('super-ai-core.engines') ?? [])
            : []);

        foreach ($this->seed() as $key => $defaults) {
            $merged = array_merge($defaults, $overrides[$key] ?? []);
            $this->engines[$key] = new EngineDescriptor(
                key:                $key,
                label:              (string) $merged['label'],
                icon:               (string) $merged['icon'],
                dispatcherBackends: (array)  $merged['dispatcher_backends'],
                providerTypes:      AiProvider::typesForBackend($key),
                availableModels:    (array)  $merged['available_models'],
                isCli:              (bool)   $merged['is_cli'],
                cliBinary:          $merged['cli_binary'] ?? null,
                defaultModel:       $merged['default_model'] ?? null,
                billingModel:       (string) ($merged['billing_model'] ?? 'usage'),
                processSpec:        $this->resolveProcessSpec($merged['process_spec'] ?? null),
            );
        }

        // Allow hosts to inject brand-new engines that aren't in the seed.
        foreach ($overrides as $key => $cfg) {
            if (isset($this->engines[$key])) continue;
            if (!is_array($cfg)) continue;
            $this->engines[$key] = new EngineDescriptor(
                key:                $key,
                label:              (string) ($cfg['label'] ?? ucfirst($key)),
                icon:               (string) ($cfg['icon'] ?? 'plug'),
                dispatcherBackends: (array)  ($cfg['dispatcher_backends'] ?? []),
                providerTypes:      AiProvider::typesForBackend($key),
                availableModels:    (array)  ($cfg['available_models'] ?? []),
                isCli:              (bool)   ($cfg['is_cli'] ?? false),
                cliBinary:          $cfg['cli_binary'] ?? null,
                defaultModel:       $cfg['default_model'] ?? null,
                billingModel:       (string) ($cfg['billing_model'] ?? 'usage'),
                processSpec:        $this->resolveProcessSpec($cfg['process_spec'] ?? null),
            );
        }
    }

    /** @return array<string, EngineDescriptor> */
    public function all(): array
    {
        return $this->engines;
    }

    public function get(string $key): ?EngineDescriptor
    {
        return $this->engines[$key] ?? null;
    }

    /** @return string[] */
    public function keys(): array
    {
        return array_keys($this->engines);
    }

    /**
     * Map every dispatcher backend name → engine key.
     * Replaces the hand-maintained BackendState::DISPATCHER_TO_ENGINE constant.
     *
     * @return array<string,string>
     */
    public function dispatcherToEngineMap(): array
    {
        $map = [];
        foreach ($this->engines as $key => $engine) {
            foreach ($engine->dispatcherBackends as $backend) {
                $map[$backend] = $key;
            }
        }
        return $map;
    }

    /**
     * Process-monitor scan keywords — engine keys plus their CLI binaries
     * so `ps aux` rows match regardless of how the binary was launched.
     *
     * @return string[]
     */
    public function processScanKeywords(): array
    {
        $kw = [];
        foreach ($this->engines as $engine) {
            $kw[] = $engine->key;
            if ($engine->cliBinary && !in_array($engine->cliBinary, $kw, true)) {
                $kw[] = $engine->cliBinary;
            }
        }
        return $kw;
    }

    /** @return string[] */
    public function modelsFor(string $key): array
    {
        return $this->get($key)?->availableModels ?? [];
    }

    /**
     * Dropdown options shaped `['' => placeholder, '<id>' => '<display>', ...]`.
     *
     * Per-engine ModelResolver (Claude/Codex/Gemini/SuperAgent) drives the list
     * when available — that keeps family aliases ("sonnet", "pro") alongside
     * full catalog entries. Engines without a resolver (copilot, host-registered
     * CLIs) fall back to the EngineDescriptor's `availableModels`.
     *
     * Host pickers should call this instead of hand-maintaining per-backend
     * match statements — new engines then appear in every dropdown for free.
     */
    public function modelOptions(string $key, bool $withPlaceholder = true, string $placeholder = '— 继承默认 —'): array
    {
        $engine = $this->get($key);
        if (!$engine) {
            return $withPlaceholder ? ['' => $placeholder] : [];
        }

        $options = $withPlaceholder ? ['' => $placeholder] : [];

        $fromResolver = $this->resolverOptions($key);
        if ($fromResolver !== null) {
            return $options + $fromResolver;
        }

        // Fallback: engine's own availableModels list (copilot, host engines).
        foreach ($engine->availableModels as $m) {
            $options[$m] = $m;
        }
        return $options;
    }

    /**
     * Same data as `modelOptions()` but shaped as a sequential list of
     * `['id' => ..., 'name' => ...]` entries — the format task create/show
     * JS expects when rendering the model picker.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function modelAliases(string $key): array
    {
        $opts = $this->modelOptions($key, withPlaceholder: false);
        $out = [];
        foreach ($opts as $id => $name) {
            $out[] = ['id' => (string) $id, 'name' => (string) $name];
        }
        return $out;
    }

    /**
     * Resolver-driven option body (no placeholder prefix) for engines that
     * ship a dedicated ModelResolver. Returns null when none applies so the
     * caller can fall back to EngineDescriptor::availableModels.
     *
     * Matches the exact shape team-side hand-rolled before: family aliases
     * first ("sonnet (claude-sonnet-4-6)"), then the full catalog.
     *
     * @return array<string,string>|null
     */
    protected function resolverOptions(string $key): ?array
    {
        switch ($key) {
            case 'claude':
                if (!class_exists(ClaudeModelResolver::class)) return null;
                $out = [];
                foreach (ClaudeModelResolver::families() as $family) {
                    $full = ClaudeModelResolver::defaultFor($family);
                    $out[$family] = ucfirst($family) . ($full ? " ({$full})" : '');
                }
                foreach (ClaudeModelResolver::catalog() as $m) {
                    $out[$m['slug']] = $m['display_name'];
                }
                return $out;

            case 'gemini':
                if (!class_exists(GeminiModelResolver::class)) return null;
                $out = [];
                foreach (array_keys(GeminiModelResolver::ALIASES) as $family) {
                    $full = GeminiModelResolver::defaultFor($family);
                    $out[$family] = ucfirst($family) . ($full ? " ({$full})" : '');
                }
                foreach (GeminiModelResolver::catalog() as $m) {
                    $out[$m['slug']] = $m['display_name'];
                }
                return $out;

            case 'codex':
                if (!class_exists(CodexModelResolver::class)) return null;
                $catalog = CodexModelResolver::catalog();
                if (empty($catalog)) return null; // let caller fall back
                $out = [];
                foreach ($catalog as $m) {
                    $out[$m['slug']] = $m['display_name'];
                }
                return $out;

            case 'superagent':
                $resolver = '\\SuperAgent\\Providers\\ModelResolver';
                if (!class_exists($resolver)) return null;
                $families = $resolver::allFamilies();
                $out = [];
                foreach ($families as $family => $fullId) {
                    $out[$family] = ucfirst($family) . " ({$fullId})";
                }
                return $out;

            case 'copilot':
                if (!class_exists(CopilotModelResolver::class)) return null;
                $out = [];
                foreach (CopilotModelResolver::families() as $family) {
                    $full = CopilotModelResolver::defaultFor($family);
                    $out[$family] = ucfirst($family) . ($full ? " ({$full})" : '');
                }
                foreach (CopilotModelResolver::catalog() as $m) {
                    $out[$m['slug']] = $m['display_name'];
                }
                return $out;
        }
        return null;
    }

    /**
     * Built-in engine seeds. Models lists are authoritative for the SDK's
     * "what does this CLI route?" question — when adding/retiring a model,
     * just edit here. Hosts can override per engine via config.
     *
     * @return array<string,array<string,mixed>>
     */
    protected function seed(): array
    {
        return [
            'claude' => [
                'label'               => 'Claude Code',
                'icon'                => 'robot',
                'dispatcher_backends' => ['claude_cli', 'anthropic_api'],
                'is_cli'              => true,
                'cli_binary'          => 'claude',
                'default_model'       => 'claude-sonnet-4-6',
                'billing_model'       => 'usage',
                'available_models'    => [
                    'claude-opus-4-6',
                    'claude-opus-4-20250514',
                    'claude-sonnet-4-6',
                    'claude-sonnet-4-5-20241022',
                    'claude-haiku-4-5-20251001',
                ],
                'process_spec' => new ProcessSpec(
                    binary:           'claude',
                    versionArgs:      ['--version'],
                    authStatusArgs:   ['auth', 'status'],
                    promptFlag:       '--print',
                    outputFormatFlag: '--output-format=json',
                    modelFlag:        '--model',
                ),
            ],
            'codex' => [
                'label'               => 'Codex',
                'icon'                => 'code-slash',
                'dispatcher_backends' => ['codex_cli', 'openai_api'],
                'is_cli'              => true,
                'cli_binary'          => 'codex',
                'default_model'       => 'gpt-5.1-codex',
                'billing_model'       => 'usage',
                'available_models'    => [
                    'gpt-5',
                    'gpt-5.1',
                    'gpt-5.1-codex',
                    'gpt-5.1-codex-mini',
                    'gpt-5-mini',
                    'gpt-4.1',
                    'gpt-4o',
                    'gpt-4o-mini',
                ],
                'process_spec' => new ProcessSpec(
                    binary:           'codex',
                    versionArgs:      ['--version'],
                    authStatusArgs:   ['login', 'status'],
                    promptFlag:       null,
                    outputFormatFlag: '--json',
                    modelFlag:        '--model',
                    defaultFlags:     ['exec'],
                ),
            ],
            'gemini' => [
                'label'               => 'Gemini',
                'icon'                => 'stars',
                'dispatcher_backends' => ['gemini_cli', 'gemini_api'],
                'is_cli'              => true,
                'cli_binary'          => 'gemini',
                'default_model'       => 'gemini-2.5-pro',
                'billing_model'       => 'usage',
                'available_models'    => [
                    'gemini-3-pro-preview',
                    'gemini-2.5-pro',
                    'gemini-2.5-flash',
                    'gemini-2.5-flash-lite',
                ],
                'process_spec' => new ProcessSpec(
                    binary:           'gemini',
                    versionArgs:      ['--version'],
                    authStatusArgs:   null,
                    promptFlag:       '--prompt',
                    outputFormatFlag: '--output-format=json',
                    modelFlag:        '--model',
                ),
            ],
            'copilot' => [
                'label'               => 'GitHub Copilot',
                'icon'                => 'github',
                'dispatcher_backends' => ['copilot_cli'],
                'is_cli'              => true,
                'cli_binary'          => 'copilot',
                // Copilot CLI routes models server-side based on subscription.
                // Identifiers use DOT separators (not Claude CLI's dashes).
                // Authoritative catalog lives in CopilotModelResolver; this
                // list is a projection so EngineDescriptor.availableModels
                // stays populated for legacy callers. Last verified 2026-04-19
                // against copilot CLI 1.0.32.
                'default_model'       => 'claude-sonnet-4.6',
                'billing_model'       => 'subscription',
                'available_models'    => [
                    'claude-sonnet-4.6',
                    'claude-sonnet-4.5',
                    'claude-sonnet-4',
                    'claude-opus-4.6',
                    'claude-opus-4.5',
                    'claude-haiku-4.5',
                    'gpt-5.1',
                    'gpt-5.1-codex',
                    'gpt-5.1-codex-mini',
                    'gpt-5',
                    'gpt-5-mini',
                    'gpt-4.1',
                    'gemini-3-pro-preview',
                ],
                'process_spec' => new ProcessSpec(
                    binary:           'copilot',
                    versionArgs:      ['--version'],
                    authStatusArgs:   null,
                    promptFlag:       '-p',
                    outputFormatFlag: '--output-format=json',
                    modelFlag:        '--model',
                    defaultFlags:     ['--allow-all-tools'],
                ),
            ],
            'superagent' => [
                'label'               => 'SuperAgent SDK',
                'icon'                => 'cpu',
                'dispatcher_backends' => ['superagent'],
                'is_cli'              => false,
                'cli_binary'          => null,
                'default_model'       => null,
                'billing_model'       => 'usage',
                'available_models'    => [],
                'process_spec'        => null,
            ],
        ];
    }

    /**
     * Accept either a ProcessSpec instance (seed) or an array (host config).
     */
    protected function resolveProcessSpec(mixed $value): ?ProcessSpec
    {
        if ($value instanceof ProcessSpec) return $value;
        if (is_array($value)) return ProcessSpec::fromArray($value);
        return null;
    }
}
