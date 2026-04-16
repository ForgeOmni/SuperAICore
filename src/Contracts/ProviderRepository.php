<?php

namespace SuperAICore\Contracts;

/**
 * Abstract storage for AI providers (credentials + backend config).
 * Host app implements this over Eloquent / Doctrine / JSON files / etc.
 */
interface ProviderRepository
{
    /** @return array|null associative array (id, name, backend, type, api_key, base_url, extra_config, is_active) */
    public function findActive(string $scope = 'global', ?int $scopeId = null, ?string $backend = null): ?array;

    /** @return array[] */
    public function listForScope(string $scope = 'global', ?int $scopeId = null, ?string $backend = null): array;

    public function findById(int $id): ?array;

    /** @return int inserted id */
    public function create(array $data): int;

    public function update(int $id, array $data): bool;

    public function delete(int $id): bool;

    public function activate(int $id): bool;

    public function deactivate(int $id): bool;
}
