<?php

namespace SuperAICore\Repositories;

use SuperAICore\Contracts\ProviderRepository;
use SuperAICore\Models\AiProvider;

class EloquentProviderRepository implements ProviderRepository
{
    public function findActive(string $scope = 'global', ?int $scopeId = null, ?string $backend = null): ?array
    {
        $p = AiProvider::getActiveForScope($scope, $scopeId, $backend);
        return $p ? $p->toProviderConfig() : null;
    }

    public function listForScope(string $scope = 'global', ?int $scopeId = null, ?string $backend = null): array
    {
        return AiProvider::getForScope($scope, $scopeId, $backend)
            ->map(fn ($p) => $p->toProviderConfig())
            ->all();
    }

    public function findById(int $id): ?array
    {
        $p = AiProvider::find($id);
        return $p ? $p->toProviderConfig() : null;
    }

    public function create(array $data): int
    {
        return AiProvider::create($data)->id;
    }

    public function update(int $id, array $data): bool
    {
        $p = AiProvider::find($id);
        return $p ? (bool) $p->update($data) : false;
    }

    public function delete(int $id): bool
    {
        return (bool) AiProvider::where('id', $id)->delete();
    }

    public function activate(int $id): bool
    {
        $p = AiProvider::find($id);
        if (!$p) return false;
        $p->activate();
        return true;
    }

    public function deactivate(int $id): bool
    {
        $p = AiProvider::find($id);
        if (!$p) return false;
        $p->deactivate();
        return true;
    }
}
