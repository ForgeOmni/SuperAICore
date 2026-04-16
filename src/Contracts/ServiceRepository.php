<?php

namespace SuperAICore\Contracts;

/**
 * AI Services = capability-specific endpoints (e.g., vision via moonshot).
 */
interface ServiceRepository
{
    public function findById(int $id): ?array;

    /** @return array[] */
    public function listActive(?string $capabilitySlug = null): array;

    public function create(array $data): int;

    public function update(int $id, array $data): bool;

    public function delete(int $id): bool;

    public function toggle(int $id): bool;
}
