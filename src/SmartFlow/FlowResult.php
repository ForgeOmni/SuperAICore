<?php

declare(strict_types=1);

namespace SuperAICore\SmartFlow;

/**
 * The outcome of a whole flow run: the value the flow body returned, plus the
 * ledger summary, budget snapshot, and resume coordinates.
 */
final class FlowResult
{
    public function __construct(
        public readonly string $name,
        public readonly string $runId,
        public readonly string $status,           // 'completed' | 'failed'
        public readonly mixed $value,
        public readonly array $ledger,            // CallLedger::summary()
        public readonly array $budget,            // Budget::toArray()
        public readonly string $flowSignature,
        public readonly bool $fake = false,
        public readonly ?string $error = null,
        public readonly ?string $ledgerPath = null,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->status === 'completed';
    }

    public function costUsd(): float
    {
        return (float) ($this->ledger['cost_usd'] ?? 0.0);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'run_id' => $this->runId,
            'status' => $this->status,
            'fake' => $this->fake,
            'error' => $this->error,
            'flow_signature' => $this->flowSignature,
            'cost_usd' => $this->costUsd(),
            'ledger' => $this->ledger,
            'budget' => $this->budget,
            'ledger_path' => $this->ledgerPath,
            'value' => $this->value,
        ];
    }
}
