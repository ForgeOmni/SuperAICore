<?php

declare(strict_types=1);

namespace SuperAICore\Support;

/**
 * A {@see \SuperAICore\Contracts\QuotaPolicy} answer.
 *
 * Carries a reason and a machine-readable code because a refusal has to reach
 * an end user as something better than "request failed" — a plan limit, a
 * prepaid balance and a per-minute cap call for three different messages, and
 * only the host can write them.
 *
 * @since 1.2.0
 */
final class QuotaDecision
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason = null,
        public readonly ?string $code = null,
        public readonly array $meta = [],
    ) {
    }

    public static function allow(): self
    {
        return new self(true);
    }

    /**
     * @param string $code  A stable identifier the host routes on, e.g.
     *                      'daily_cap', 'plan_limit', 'balance_exhausted'.
     */
    public static function deny(string $reason, string $code = 'quota_exceeded', array $meta = []): self
    {
        return new self(false, $reason, $code, $meta);
    }

    public function denied(): bool
    {
        return ! $this->allowed;
    }

    /** @return array<string,mixed> the shape the dispatcher returns to the caller */
    public function toArray(): array
    {
        return array_filter([
            'quota_denied' => true,
            'error' => $this->reason,
            'error_code' => $this->code,
            'meta' => $this->meta ?: null,
        ], static fn ($v) => $v !== null);
    }
}
