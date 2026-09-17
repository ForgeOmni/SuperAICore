<?php

namespace SuperAICore\Contracts;

use SuperAICore\Support\QuotaDecision;

/**
 * Whether a scope may spend right now.
 *
 * Nothing in this package stops a scope from spending: the usage ledger is
 * written *after* a call, so the first sign of a runaway loop or a tenant on a
 * free plan is the invoice. A host knows what a scope is allowed — a daily cap,
 * a plan tier, a prepaid balance — and this is where the dispatcher asks.
 *
 * Bind an implementation to this interface to enable it; unbound, the
 * dispatcher asks nobody and behaves exactly as before.
 *
 *     $this->app->bind(QuotaPolicy::class, MyPlanQuota::class);
 *
 * The decision is advisory in one direction only: a refusal stops the
 * dispatch, but an approval is not a reservation — two concurrent calls can
 * both be told yes. A host that needs a hard ceiling debits in `allows()` and
 * reconciles from the usage row.
 *
 * @since 1.2.0
 */
interface QuotaPolicy
{
    /**
     * @param string $scope    The scope the credentials resolved to ('global',
     *                         'user', or whatever the host named).
     * @param int|null $scopeId Its id, or null for an unkeyed scope.
     * @param array<string,mixed> $context {
     *   backend: string,        resolved backend name
     *   model: ?string,         requested model, when the caller named one
     *   task_type: ?string,
     *   capability: ?string,
     *   user_id: ?int,
     *   estimated_cost_usd: ?float,  when the caller supplied one
     * }
     */
    public function allows(string $scope, ?int $scopeId, array $context): QuotaDecision;
}
