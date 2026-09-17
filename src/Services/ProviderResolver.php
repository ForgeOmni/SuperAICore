<?php

namespace SuperAICore\Services;

use SuperAICore\Contracts\ProviderRepository;

/**
 * Thin wrapper around ProviderRepository with scope resolution logic.
 *
 * The chain used to be exactly user → global, which is the right chain for a
 * workstation where "scope" means "this developer". A host with tenants has
 * at least one level in between — a business, a workspace, an organisation —
 * and its own order of precedence over it. {@see resolveChain()} takes that
 * order as an argument; `resolveForUser()` is the two-element case and keeps
 * its behaviour exactly.
 *
 * Scope names are free-form strings, already stored per row on `ai_providers`
 * (`scope` / `scope_id`), so a host needs no schema change to introduce one.
 */
class ProviderResolver
{
    public function __construct(protected ?ProviderRepository $repo = null) {}

    public function findActive(string $scope = 'global', ?int $scopeId = null, ?string $backend = null): ?array
    {
        if (!$this->repo) return null;
        return $this->repo->findActive($scope, $scopeId, $backend);
    }

    public function findById(int $id): ?array
    {
        if (!$this->repo) return null;
        return $this->repo->findById($id);
    }

    /**
     * Resolve for a user: try user-scope active first, fall back to global.
     */
    public function resolveForUser(?int $userId, ?string $backend = null): ?array
    {
        return $this->resolveChain(
            $userId ? [['user', $userId], ['global', null]] : [['global', null]],
            $backend
        );
    }

    /**
     * First active provider along an ordered chain of scopes.
     *
     *     $resolver->resolveChain([
     *         ['business', $businessId],   // this tenant's own key
     *         ['user', $userId],           // or this operator's
     *         ['global', null],            // or the platform's
     *     ]);
     *
     * Each element is `[scope, scopeId]`; a null id is allowed and means the
     * scope is not keyed (as `global` is not). Stops at the first hit, so
     * order is the precedence. An empty chain resolves nothing rather than
     * silently falling back to global — a host that passes no scopes is
     * saying it has none, and answering with the platform's credentials
     * would be a guess with someone else's key.
     *
     * @param list<array{0:string,1:?int}> $scopes
     *
     * @since 1.2.0
     */
    public function resolveChain(array $scopes, ?string $backend = null): ?array
    {
        if (!$this->repo) return null;

        foreach ($scopes as $entry) {
            $scope = (string) ($entry[0] ?? '');
            if ($scope === '') {
                continue;
            }

            $scopeId = isset($entry[1]) ? (int) $entry[1] : null;
            $found = $this->repo->findActive($scope, $scopeId, $backend);

            if ($found) {
                return $found;
            }
        }

        return null;
    }
}
