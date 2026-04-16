<?php

namespace SuperAICore\Contracts;

interface RoutingRepository
{
    /**
     * Resolve the service to use for (task_type, capability).
     * @return array|null  service row
     */
    public function resolve(string $taskType, string $capabilitySlug): ?array;

    /** @return array[] */
    public function listAll(): array;

    public function create(array $data): int;

    public function update(int $id, array $data): bool;

    public function delete(int $id): bool;
}
