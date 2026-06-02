<?php

declare(strict_types=1);

namespace SuperAICore\SmartFlow;

/**
 * The outcome of one {@see BackendAgentRunner::run()} call.
 *
 * `value` is what the flow author actually receives from `$flow->agent()`:
 *   - a validated array        when a schema was supplied and a layer succeeded,
 *   - the raw string           when no schema was supplied,
 *   - the {@see Skip} sentinel  when a schema was supplied but every layer failed.
 *
 * `layer` records which rung of the structured-output ladder produced the value
 * ('native' | 'submitted' | 'extracted' | 'text' | 'none'). `backend` records
 * which CLI/API backend actually answered.
 */
final class AgentResult
{
    public function __construct(
        public readonly mixed $value,
        public readonly string $text,
        public readonly string $layer,
        public readonly string $backend,
        public readonly string $model,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly float $costUsd = 0.0,
        public readonly bool $valid = true,
        public readonly ?string $error = null,
        public readonly bool $fake = false,
    ) {}

    /**
     * Reconstruct a result from the JSON a `bin/flow-agent-runner.php` worker
     * prints. A skipped result is re-hydrated to the {@see Skip} sentinel.
     *
     * @param array<string, mixed> $data
     */
    public static function fromWorker(array $data): self
    {
        $skip = (bool) ($data['skip'] ?? false);
        return new self(
            value: $skip ? Skip::instance() : ($data['value'] ?? ''),
            text: (string) ($data['text'] ?? ''),
            layer: (string) ($data['layer'] ?? 'none'),
            backend: (string) ($data['backend'] ?? $data['provider'] ?? ''),
            model: (string) ($data['model'] ?? ''),
            inputTokens: (int) ($data['input_tokens'] ?? 0),
            outputTokens: (int) ($data['output_tokens'] ?? 0),
            costUsd: (float) ($data['cost_usd'] ?? 0.0),
            valid: (bool) ($data['valid'] ?? !$skip),
            error: $data['error'] ?? null,
            fake: (bool) ($data['fake'] ?? false),
        );
    }

    public function isSkip(): bool
    {
        return Skip::isSkip($this->value);
    }

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'value' => $this->isSkip() ? null : $this->value,
            'skip' => $this->isSkip(),
            'text' => $this->text,
            'layer' => $this->layer,
            'backend' => $this->backend,
            'model' => $this->model,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cost_usd' => round($this->costUsd, 6),
            'valid' => $this->valid,
            'error' => $this->error,
            'fake' => $this->fake,
        ];
    }
}
