<?php

namespace SuperAICore\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Generic LLM call usage tracking — written by Dispatcher.
 *
 * @property int $id
 * @property string $backend
 * @property int|null $provider_id
 * @property int|null $service_id
 * @property string $model
 * @property string|null $task_type
 * @property string|null $capability
 * @property int $input_tokens
 * @property int $output_tokens
 * @property float $cost_usd
 * @property int|null $duration_ms
 * @property int|null $user_id
 * @property array|null $metadata
 */
class AiUsageLog extends Model
{
    protected $table = 'ai_usage_logs';

    protected $fillable = [
        'backend', 'provider_id', 'service_id', 'model',
        'task_type', 'capability',
        'input_tokens', 'output_tokens', 'cost_usd', 'duration_ms',
        'user_id', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'cost_usd' => 'decimal:6',
        'duration_ms' => 'integer',
    ];

    public function provider()
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    public function service()
    {
        return $this->belongsTo(AiService::class, 'service_id');
    }
}
