<?php

declare(strict_types=1);

namespace SuperAICore\SmartFlow;

/**
 * Tracks spend across a whole flow run — the budget primitive ("token + 钱包警戒线").
 * A single Budget is shared by every agent() call in a flow (and, conceptually,
 * by parallel sub-calls): the pool is global, not per-call.
 *
 * Either ceiling may be null (= unbounded on that axis). `assertWithin()` is
 * called *before* an agent runs with that call's *estimated* cost so an
 * over-budget call is refused up front; `record()` is called *after* with the
 * call's actual usage.
 */
final class Budget
{
    private float $spentUsd = 0.0;
    private int $spentTokens = 0;
    private int $calls = 0;

    public function __construct(
        public readonly ?float $totalUsd = null,
        public readonly ?int $totalTokens = null,
    ) {}

    public function record(float $usd, int $tokens): void
    {
        $this->spentUsd += max(0.0, $usd);
        $this->spentTokens += max(0, $tokens);
        $this->calls++;
    }

    public function spentUsd(): float
    {
        return $this->spentUsd;
    }

    public function spentTokens(): int
    {
        return $this->spentTokens;
    }

    public function calls(): int
    {
        return $this->calls;
    }

    /** Remaining USD, or null when unbounded. */
    public function remainingUsd(): ?float
    {
        return $this->totalUsd === null ? null : max(0.0, $this->totalUsd - $this->spentUsd);
    }

    /** Remaining tokens, or null when unbounded. */
    public function remainingTokens(): ?int
    {
        return $this->totalTokens === null ? null : max(0, $this->totalTokens - $this->spentTokens);
    }

    public function isExhausted(): bool
    {
        if ($this->totalUsd !== null && $this->spentUsd >= $this->totalUsd) {
            return true;
        }
        if ($this->totalTokens !== null && $this->spentTokens >= $this->totalTokens) {
            return true;
        }
        return false;
    }

    /**
     * Throw if recording (estimatedUsd, estimatedTokens) on top of current spend
     * would breach either ceiling.
     *
     * @throws BudgetExceededException
     */
    public function assertWithin(float $estimatedUsd = 0.0, int $estimatedTokens = 0, string $label = 'agent'): void
    {
        if ($this->totalUsd !== null && ($this->spentUsd + $estimatedUsd) > $this->totalUsd) {
            throw new BudgetExceededException(sprintf(
                'Budget exceeded at "%s": $%.4f spent + $%.4f estimated > $%.4f ceiling.',
                $label,
                $this->spentUsd,
                $estimatedUsd,
                $this->totalUsd
            ));
        }
        if ($this->totalTokens !== null && ($this->spentTokens + $estimatedTokens) > $this->totalTokens) {
            throw new BudgetExceededException(sprintf(
                'Token budget exceeded at "%s": %d spent + %d estimated > %d ceiling.',
                $label,
                $this->spentTokens,
                $estimatedTokens,
                $this->totalTokens
            ));
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'total_usd' => $this->totalUsd,
            'total_tokens' => $this->totalTokens,
            'spent_usd' => round($this->spentUsd, 6),
            'spent_tokens' => $this->spentTokens,
            'calls' => $this->calls,
            'remaining_usd' => $this->remainingUsd(),
            'remaining_tokens' => $this->remainingTokens(),
        ];
    }
}
