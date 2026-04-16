<?php

namespace ForgeOmni\AiCore\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-task-type model/effort overrides.
 *
 * @property int $id
 * @property string $scope         global | user | project
 * @property int|null $scope_id
 * @property int|null $provider_id  null = built-in / no-provider settings
 * @property string $backend       claude | codex | superagent
 * @property string $task_type     Task type key or "default"
 * @property string|null $model
 * @property string|null $effort
 */
class AiModelSetting extends Model
{
    protected $table = 'ai_model_settings';

    protected $fillable = [
        'scope', 'scope_id', 'provider_id', 'backend',
        'task_type', 'model', 'effort',
    ];

    /**
     * Get all settings for a scope + provider as [task_type => ['model' => ..., 'effort' => ...]].
     */
    public static function getForScope(
        string $scope = 'global',
        ?int $scopeId = null,
        ?int $providerId = null,
        string $backend = AiProvider::BACKEND_CLAUDE,
    ): array {
        return static::where('scope', $scope)
            ->where('scope_id', $scopeId)
            ->where('provider_id', $providerId)
            ->where('backend', $backend)
            ->get()
            ->keyBy('task_type')
            ->map(fn ($row) => ['model' => $row->model, 'effort' => $row->effort])
            ->toArray();
    }

    public static function saveForScope(
        array $settings,
        string $scope = 'global',
        ?int $scopeId = null,
        ?int $providerId = null,
        string $backend = AiProvider::BACKEND_CLAUDE,
    ): void {
        foreach ($settings as $taskType => $values) {
            $model = $values['model'] ?? null;
            $effort = $values['effort'] ?? null;

            if (empty($model) && empty($effort)) {
                static::where('scope', $scope)
                    ->where('scope_id', $scopeId)
                    ->where('provider_id', $providerId)
                    ->where('backend', $backend)
                    ->where('task_type', $taskType)
                    ->delete();
                continue;
            }

            static::updateOrCreate(
                [
                    'scope' => $scope, 'scope_id' => $scopeId,
                    'provider_id' => $providerId, 'backend' => $backend,
                    'task_type' => $taskType,
                ],
                ['model' => $model ?: null, 'effort' => $effort ?: null]
            );
        }
    }
}
