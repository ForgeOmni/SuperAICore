<?php

declare(strict_types=1);

namespace SuperAICore\SmartFlow;

/**
 * The outcome of a {@see Flow::gate()} acceptance checkpoint.
 *
 * `passed` records whether the check returned truthy. When it failed but a
 * fallback/relay supplied a substitute, `relayed` is true and `value` carries
 * that substitute.
 */
final class GateResult
{
    public function __construct(
        public readonly string $name,
        public readonly bool $passed,
        public readonly string $reason = '',
        public readonly mixed $value = null,
        public readonly bool $relayed = false,
    ) {}

    public function ok(): bool
    {
        return $this->passed || $this->relayed;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'passed' => $this->passed,
            'relayed' => $this->relayed,
            'reason' => $this->reason,
            'value' => $this->value,
        ];
    }
}
