<?php

namespace SuperAICore\Repositories;

use SuperAICore\Contracts\ServiceRepository;
use SuperAICore\Models\AiCapability;
use SuperAICore\Models\AiService;

class EloquentServiceRepository implements ServiceRepository
{
    public function findById(int $id): ?array
    {
        $s = AiService::find($id);
        return $s ? $this->toArray($s) : null;
    }

    public function listActive(?string $capabilitySlug = null): array
    {
        $q = AiService::where('is_active', true)->orderBy('sort_order');
        if ($capabilitySlug) {
            $cap = AiCapability::where('slug', $capabilitySlug)->first();
            if (!$cap) return [];
            $q->where('capability_id', $cap->id);
        }
        return $q->get()->map(fn ($s) => $this->toArray($s))->all();
    }

    public function create(array $data): int
    {
        return AiService::create($data)->id;
    }

    public function update(int $id, array $data): bool
    {
        $s = AiService::find($id);
        return $s ? (bool) $s->update($data) : false;
    }

    public function delete(int $id): bool
    {
        return (bool) AiService::where('id', $id)->delete();
    }

    public function toggle(int $id): bool
    {
        $s = AiService::find($id);
        if (!$s) return false;
        $s->update(['is_active' => !$s->is_active]);
        return true;
    }

    protected function toArray(AiService $s): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'capability_id' => $s->capability_id,
            'protocol' => $s->protocol,
            'base_url' => $s->base_url,
            'api_key' => $s->decrypted_api_key,
            'model' => $s->model,
            'extra_config' => $s->extra_config,
            'is_active' => $s->is_active,
        ];
    }
}
