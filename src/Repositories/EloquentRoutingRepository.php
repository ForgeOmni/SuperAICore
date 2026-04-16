<?php

namespace SuperAICore\Repositories;

use SuperAICore\Contracts\RoutingRepository;
use SuperAICore\Models\AiServiceRouting;

class EloquentRoutingRepository implements RoutingRepository
{
    public function resolve(string $taskType, string $capabilitySlug): ?array
    {
        $service = AiServiceRouting::resolve($taskType, $capabilitySlug);
        if (!$service) return null;

        return [
            'id' => $service->id,
            'name' => $service->name,
            'capability_id' => $service->capability_id,
            'protocol' => $service->protocol,
            'base_url' => $service->base_url,
            'api_key' => $service->decrypted_api_key,
            'model' => $service->model,
            'extra_config' => $service->extra_config,
        ];
    }

    public function listAll(): array
    {
        return AiServiceRouting::with(['capability', 'service'])
            ->orderByDesc('priority')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'task_type' => $r->task_type,
                'capability_id' => $r->capability_id,
                'service_id' => $r->service_id,
                'priority' => $r->priority,
                'is_active' => $r->is_active,
            ])
            ->all();
    }

    public function create(array $data): int
    {
        return AiServiceRouting::create($data)->id;
    }

    public function update(int $id, array $data): bool
    {
        $r = AiServiceRouting::find($id);
        return $r ? (bool) $r->update($data) : false;
    }

    public function delete(int $id): bool
    {
        return (bool) AiServiceRouting::where('id', $id)->delete();
    }
}
