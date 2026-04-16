<?php

namespace ForgeOmni\AiCore\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * task_type + capability → service routing.
 *
 * @property int $id
 * @property string $task_type      specific type or '*' wildcard
 * @property int $capability_id
 * @property int $service_id
 * @property int $priority          higher = more preferred
 * @property bool $is_active
 */
class AiServiceRouting extends Model
{
    protected $table = 'ai_service_routing';

    protected $fillable = [
        'task_type', 'capability_id', 'service_id', 'priority', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'priority'  => 'integer',
    ];

    public function capability()
    {
        return $this->belongsTo(AiCapability::class, 'capability_id');
    }

    public function service()
    {
        return $this->belongsTo(AiService::class, 'service_id');
    }

    /**
     * Resolve the AiService for a given task type + capability slug.
     * Priority: exact task_type match > wildcard '*' > null.
     */
    public static function resolve(string $taskType, string $capabilitySlug): ?AiService
    {
        $capability = AiCapability::where('slug', $capabilitySlug)
            ->where('is_active', true)
            ->first();
        if (!$capability) return null;

        $routing = static::where('capability_id', $capability->id)
            ->where('is_active', true)
            ->whereIn('task_type', [$taskType, '*'])
            ->orderByRaw("CASE WHEN task_type = ? THEN 0 ELSE 1 END", [$taskType])
            ->orderByDesc('priority')
            ->first();

        if (!$routing) return null;
        $service = $routing->service;
        return ($service && $service->is_active) ? $service : null;
    }
}
