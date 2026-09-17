<?php

namespace SuperAICore\Contracts;

/**
 * A usage store that can answer per scope.
 *
 * Deliberately a second interface rather than extra parameters on
 * {@see UsageRepository}: that one is implemented by hosts, and widening its
 * methods would break every implementation on upgrade. A host that wants
 * per-tenant reporting implements this too; callers check `instanceof` and
 * fall back to the unscoped answer.
 *
 * @since 1.2.0
 */
interface ScopedUsageRepository extends UsageRepository
{
    /**
     * Same shape as {@see UsageRepository::summary()}, restricted to one
     * scope. A null `$scopeId` means "every row in this scope".
     *
     * @return array{total_runs:int,total_input_tokens:int,total_output_tokens:int,total_cost_usd:float,by_model:array,by_backend:array}
     */
    public function summaryForScope(
        string $scope,
        ?int $scopeId = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null
    ): array;

    /**
     * Raw rows for one scope, for a host's own aggregation.
     *
     * @return array[]
     */
    public function allForScope(
        string $scope,
        ?int $scopeId = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null
    ): array;
}
