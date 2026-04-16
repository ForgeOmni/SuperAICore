<?php

namespace ForgeOmni\AiCore\Services;

use ForgeOmni\AiCore\Contracts\ProviderRepository;

/**
 * Thin wrapper around ProviderRepository with scope resolution logic.
 * Priority: user scope > global scope > null.
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
        if (!$this->repo) return null;
        if ($userId) {
            $user = $this->repo->findActive('user', $userId, $backend);
            if ($user) return $user;
        }
        return $this->repo->findActive('global', null, $backend);
    }
}
