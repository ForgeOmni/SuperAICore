<?php

namespace SuperAICore\Models;

use SuperAICore\Models\Concerns\HasConfigurablePrefix;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id            UUID
 * @property string $thread_id
 * @property string $objective     free-form user data — wrap before
 *                                 rendering into prompts
 * @property string $status        active | complete | paused | budget_limited
 * @property int|null $token_budget
 * @property int $tokens_used
 * @property array|null $metadata
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class AiGoal extends Model
{
    const STATUS_ACTIVE         = 'active';
    const STATUS_COMPLETE       = 'complete';
    const STATUS_PAUSED         = 'paused';
    const STATUS_BUDGET_LIMITED = 'budget_limited';

    use HasConfigurablePrefix;

    protected $table = 'ai_goals';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'thread_id', 'objective', 'status',
        'token_budget', 'tokens_used', 'metadata',
    ];

    protected $casts = [
        'token_budget' => 'integer',
        'tokens_used'  => 'integer',
        'metadata'     => 'array',
    ];
}
