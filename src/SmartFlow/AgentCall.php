<?php

declare(strict_types=1);

namespace SuperAICore\SmartFlow;

/**
 * An immutable description of a single cross-CLI agent invocation — the
 * normalized form of `$flow->agent($prompt, $opts)`. It is serializable so the
 * same call can be dispatched in-process or shipped over stdin to a
 * `bin/flow-agent-runner.php` worker for true-parallel execution.
 *
 * `backend` is the cross-CLI dimension: it names a registered execution backend
 * (`claude_cli`, `codex_cli`, `gemini_cli`, `copilot_cli`, `superagent`,
 * `anthropic_api`, …) — so one flow can route its planner to one CLI and its
 * reviewers to another. `schema` (when present) requests structured output and
 * drives the three-layer extraction ladder. `role` names a persona template;
 * explicit backend/model/system override whatever the persona supplies.
 * `providerConfig` carries optional credentials/endpoint config for API
 * backends (CLI backends authenticate themselves and ignore it).
 */
final class AgentCall
{
    /**
     * @param array<string, mixed> $providerConfig
     */
    public function __construct(
        public readonly string $prompt,
        public readonly string $label = 'agent',
        public readonly ?string $role = null,
        public readonly ?string $backend = null,
        public readonly ?string $model = null,
        public readonly ?string $system = null,
        public readonly ?array $schema = null,
        public readonly ?float $temperature = null,
        public readonly int $maxTokens = 4096,
        public readonly array $providerConfig = [],
        public readonly string $phase = '',
        public readonly ?Delegation $delegation = null,
    ) {}

    /**
     * Build a call from a raw prompt + the loose opts array accepted by
     * {@see Flow::agent()}. Unknown keys are ignored. `provider` is accepted as
     * an alias for `backend` to ease porting flows from the upstream SDK.
     *
     * @param array<string, mixed> $opts
     */
    public static function fromOpts(string $prompt, array $opts = [], string $defaultLabel = 'agent'): self
    {
        $backend = $opts['backend'] ?? $opts['provider'] ?? null;

        return new self(
            prompt: $prompt,
            label: (string) ($opts['label'] ?? $defaultLabel),
            role: isset($opts['role']) ? (string) $opts['role'] : null,
            backend: $backend !== null ? (string) $backend : null,
            model: isset($opts['model']) ? (string) $opts['model'] : null,
            system: isset($opts['system']) ? (string) $opts['system'] : null,
            schema: isset($opts['schema']) && is_array($opts['schema']) ? $opts['schema'] : null,
            temperature: isset($opts['temperature']) ? (float) $opts['temperature'] : null,
            maxTokens: (int) ($opts['max_tokens'] ?? 4096),
            providerConfig: isset($opts['provider_config']) && is_array($opts['provider_config']) ? $opts['provider_config'] : [],
            phase: (string) ($opts['phase'] ?? ''),
            delegation: Delegation::fromOpts($opts),
        );
    }

    /** True when this call delegates a sub-flow to superagent rather than calling a backend. */
    public function isDelegation(): bool
    {
        return $this->delegation !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'prompt' => $this->prompt,
            'label' => $this->label,
            'role' => $this->role,
            'backend' => $this->backend,
            'model' => $this->model,
            'system' => $this->system,
            'schema' => $this->schema,
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
            'provider_config' => $this->providerConfig,
            'phase' => $this->phase,
            'delegation' => $this->delegation?->toArray(),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            prompt: (string) ($data['prompt'] ?? ''),
            label: (string) ($data['label'] ?? 'agent'),
            role: $data['role'] ?? null,
            backend: $data['backend'] ?? $data['provider'] ?? null,
            model: $data['model'] ?? null,
            system: $data['system'] ?? null,
            schema: isset($data['schema']) && is_array($data['schema']) ? $data['schema'] : null,
            temperature: isset($data['temperature']) ? (float) $data['temperature'] : null,
            maxTokens: (int) ($data['max_tokens'] ?? 4096),
            providerConfig: isset($data['provider_config']) && is_array($data['provider_config']) ? $data['provider_config'] : [],
            phase: (string) ($data['phase'] ?? ''),
            delegation: isset($data['delegation']) && is_array($data['delegation']) ? Delegation::fromArray($data['delegation']) : null,
        );
    }
}
