<?php

declare(strict_types=1);

namespace SuperAICore\Models;

use Illuminate\Database\Eloquent\Model;
use SuperAICore\Models\Concerns\HasConfigurablePrefix;

/**
 * claude-octopus-borrowed PR/CI watcher row.
 *
 * @property int $id
 * @property string $owner
 * @property string $repo
 * @property string|null $pr_filter
 * @property string $action  ask_user|spawn_squad|webhook|log
 * @property array|null $action_payload
 * @property int $max_retries
 * @property int $cooldown_seconds
 * @property bool $is_active
 * @property \Carbon\Carbon|null $last_polled_at
 * @property string|null $last_etag
 */
class AiPrWatcher extends Model
{
    use HasConfigurablePrefix;

    public const ACTION_ASK_USER    = 'ask_user';
    public const ACTION_SPAWN_SQUAD = 'spawn_squad';
    public const ACTION_WEBHOOK     = 'webhook';
    public const ACTION_LOG         = 'log';

    public const ACTIONS = [
        self::ACTION_ASK_USER,
        self::ACTION_SPAWN_SQUAD,
        self::ACTION_WEBHOOK,
        self::ACTION_LOG,
    ];

    protected $table = 'ai_pr_watchers';

    protected $fillable = [
        'owner', 'repo', 'pr_filter', 'action', 'action_payload',
        'max_retries', 'cooldown_seconds', 'is_active',
        'last_polled_at', 'last_etag',
    ];

    protected $casts = [
        'action_payload'   => 'array',
        'is_active'        => 'boolean',
        'last_polled_at'   => 'datetime',
        'max_retries'      => 'integer',
        'cooldown_seconds' => 'integer',
    ];

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
