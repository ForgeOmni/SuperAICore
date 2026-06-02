<?php

declare(strict_types=1);

namespace SuperAICore\SmartFlow;

/**
 * A request to hand a *sub-flow* off to the SuperAgent SDK's (cross-model)
 * SmartFlow — the federation seam that lets a SuperAICore cross-CLI flow
 * delegate part of its work to superagent, which then fans out across model
 * providers on its own.
 *
 * Two modes mirror the two ways superagent can dispatch:
 *
 *   - mode 'named'  — run one of superagent's OWN registered flows by name
 *                     (research-trio, code-review-council, …). SuperAICore hands
 *                     over the goal and superagent decides the internal fan-out
 *                     ("自行分发"). `provider`/`model` still let SuperAICore steer
 *                     which model tier superagent uses.
 *   - mode 'spec'   — run a flow whose structure SuperAICore AUTHORED inline
 *                     (a SuperAgent-SmartFlow spec: provider-based steps). Here
 *                     superagent is purely the executor ("按照本项目的指示分发").
 *
 * It is serializable so a delegated call can also run inside a parallel
 * {@see ProcessPool} worker.
 */
final class Delegation
{
    public const MODE_NAMED = 'named';
    public const MODE_SPEC = 'spec';

    /**
     * @param array<string, mixed>      $args  args passed to the sub-flow
     * @param array<string, mixed>|null $spec  inline SDK flow spec (mode 'spec')
     */
    public function __construct(
        public readonly string $mode,
        public readonly string $name = '',
        public readonly array $args = [],
        public readonly ?array $spec = null,
        public readonly ?string $provider = null,
        public readonly ?string $model = null,
        public readonly ?int $concurrency = null,
        public readonly ?float $budgetUsd = null,
    ) {}

    /**
     * Build a Delegation from the loose opts accepted by {@see Flow::agent()} /
     * {@see Flow::delegate()}. Returns null when no delegation was requested.
     *
     * Recognized opts:
     *   delegate | subflow : string  → named SDK flow (mode 'named')
     *   spec               : array|string → inline SDK flow spec (mode 'spec')
     *   flow_args          : array   → args for the sub-flow
     *   delegate_provider  : string  → default model provider for the sub-flow
     *   delegate_model     : string  → default model for the sub-flow
     *   delegate_concurrency : int
     *   delegate_budget_usd  : float
     *
     * @param array<string, mixed> $opts
     */
    public static function fromOpts(array $opts): ?self
    {
        $spec = $opts['spec'] ?? null;
        $name = (string) ($opts['delegate'] ?? $opts['subflow'] ?? '');

        if ($spec === null && $name === '') {
            return null;
        }

        $parsedSpec = null;
        if (is_array($spec)) {
            $parsedSpec = $spec;
        } elseif (is_string($spec) && trim($spec) !== '') {
            // A YAML/JSON string spec — decode lazily here so callers can pass
            // either shape. JSON first (it's a strict subset of YAML anyway).
            $decoded = json_decode($spec, true);
            $parsedSpec = is_array($decoded) ? $decoded : ['__yaml__' => $spec];
        }

        $mode = $parsedSpec !== null ? self::MODE_SPEC : self::MODE_NAMED;

        return new self(
            mode: $mode,
            name: $name !== '' ? $name : (string) ($parsedSpec['name'] ?? 'inline'),
            args: is_array($opts['flow_args'] ?? null) ? $opts['flow_args'] : [],
            spec: $parsedSpec,
            provider: isset($opts['delegate_provider']) ? (string) $opts['delegate_provider'] : null,
            model: isset($opts['delegate_model']) ? (string) $opts['delegate_model'] : null,
            concurrency: isset($opts['delegate_concurrency']) ? (int) $opts['delegate_concurrency'] : null,
            budgetUsd: isset($opts['delegate_budget_usd']) ? (float) $opts['delegate_budget_usd'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'name' => $this->name,
            'args' => $this->args,
            'spec' => $this->spec,
            'provider' => $this->provider,
            'model' => $this->model,
            'concurrency' => $this->concurrency,
            'budget_usd' => $this->budgetUsd,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            mode: (string) ($data['mode'] ?? self::MODE_NAMED),
            name: (string) ($data['name'] ?? ''),
            args: is_array($data['args'] ?? null) ? $data['args'] : [],
            spec: is_array($data['spec'] ?? null) ? $data['spec'] : null,
            provider: $data['provider'] ?? null,
            model: $data['model'] ?? null,
            concurrency: isset($data['concurrency']) ? (int) $data['concurrency'] : null,
            budgetUsd: isset($data['budget_usd']) ? (float) $data['budget_usd'] : null,
        );
    }

    public function label(): string
    {
        return 'superagent:flow:' . ($this->name !== '' ? $this->name : $this->mode);
    }
}
