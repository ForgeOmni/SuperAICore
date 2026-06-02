<?php

declare(strict_types=1);

namespace SuperAICore\SmartFlow;

use Symfony\Component\Yaml\Yaml;

/**
 * Reusable role/persona templates ("角色 roles — persona prompt 注入身份，可复用模板").
 * A persona bundles a system prompt with an optional default backend (CLI)/model
 * and temperature, so a flow can say `['role' => 'reviewer']` instead of
 * repeating a paragraph of instructions and a backend id at every call site.
 * Pinning a `backend` per persona is what makes a flow genuinely cross-CLI.
 *
 * Personas are merged from three sources, later winning over earlier:
 *   1. built-in defaults below,
 *   2. `resources/flows/personas/*.yaml` (one persona per file, or a map),
 *   3. `config('super-ai-core.smartflow.personas')`.
 *
 * Shape of a persona: `['system' => string, 'backend' => ?string,
 * 'model' => ?string, 'temperature' => ?float, 'description' => ?string]`.
 */
final class PersonaRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $personas;

    /** @param array<string, array<string, mixed>> $personas */
    public function __construct(array $personas = [])
    {
        $this->personas = array_merge(self::defaults(), $personas);
    }

    /**
     * Build a registry from the persona YAML directory + config overrides.
     */
    public static function load(?string $dir = null): self
    {
        $registry = new self();
        $dir ??= self::defaultDir();

        if ($dir !== null && is_dir($dir)) {
            foreach (glob(rtrim($dir, '/\\') . '/*.yaml') ?: [] as $file) {
                try {
                    $parsed = Yaml::parseFile($file);
                } catch (\Throwable) {
                    continue;
                }
                if (!is_array($parsed)) {
                    continue;
                }
                // A file may hold one persona (with an `id`) or a map of id => persona.
                if (isset($parsed['id'])) {
                    $registry->register((string) $parsed['id'], $parsed);
                } else {
                    foreach ($parsed as $id => $def) {
                        if (is_array($def)) {
                            $registry->register((string) $id, $def);
                        }
                    }
                }
            }
        }

        $fromConfig = Cfg::get('super-ai-core.smartflow.personas', []);
        if (is_array($fromConfig)) {
            foreach ($fromConfig as $id => $def) {
                if (is_array($def)) {
                    $registry->register((string) $id, $def);
                }
            }
        }

        return $registry;
    }

    /** @param array<string, mixed> $def */
    public function register(string $id, array $def): void
    {
        // Accept `provider` as an alias for `backend` so personas ported from
        // the upstream SDK still pin the right CLI.
        if (isset($def['provider']) && !isset($def['backend'])) {
            $def['backend'] = $def['provider'];
        }
        $this->personas[$id] = array_merge($this->personas[$id] ?? [], $def);
    }

    /** @return array<string, mixed>|null */
    public function get(string $id): ?array
    {
        return $this->personas[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->personas[$id]);
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return $this->personas;
    }

    public static function defaultDir(): ?string
    {
        $dir = dirname(__DIR__, 2) . '/resources/flows/personas';
        return is_dir($dir) ? $dir : null;
    }

    /**
     * Built-in personas. None pin a backend by default — the flow (or config)
     * decides which CLI each role runs on, so a persona stays reusable across
     * different cross-CLI routings.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function defaults(): array
    {
        return [
            'planner' => [
                'system' => 'You are a meticulous planner. Decompose the goal into concrete, ordered, independently-checkable steps. State assumptions explicitly. Prefer the simplest plan that fully covers the goal.',
                'description' => 'Decomposes a goal into an ordered plan.',
            ],
            'builder' => [
                'system' => 'You are a senior engineer. Implement exactly what the plan asks — no more, no less. Match existing conventions, keep changes minimal and correct, and explain any non-obvious decision in one line.',
                'description' => 'Implements per a plan.',
            ],
            'reviewer' => [
                'system' => 'You are a sharp reviewer. Find real problems — correctness, edge cases, omissions. Be concrete and cite specifics. Do not invent issues.',
                'description' => 'Critically reviews an artifact.',
            ],
            'researcher' => [
                'system' => 'You are a careful researcher. Gather and organize relevant facts, note uncertainty, and distinguish evidence from inference.',
                'description' => 'Gathers and organizes information.',
            ],
            'writer' => [
                'system' => 'You are a clear, engaging writer. Match the requested tone and audience. Lead with the point; cut anything that does not earn its place.',
                'description' => 'Drafts prose / copy.',
            ],
            'critic' => [
                'system' => 'You are an adversarial critic. Try hard to refute the claim under review. Default to "not convincing" unless the evidence is strong.',
                'description' => 'Adversarial verifier for council/gates.',
            ],
            'chair' => [
                'system' => 'You are the chair. Synthesize the inputs into one decisive, well-justified verdict. Resolve disagreements explicitly.',
                'description' => 'Synthesizes multiple inputs into one verdict.',
            ],
        ];
    }
}
