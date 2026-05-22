<?php

declare(strict_types=1);

namespace SuperAICore\Models;

use Illuminate\Database\Eloquent\Model;
use SuperAICore\Models\Concerns\HasConfigurablePrefix;

/**
 * One row per branch in a session tree (Pi /tree model).
 *
 * @property int $id
 * @property string $session_id
 * @property string $branch_id
 * @property string|null $parent_branch_id
 * @property string|null $fork_from_entry_id
 * @property string|null $summary
 * @property array|null $summary_details
 * @property bool $is_active
 * @property string|null $display_name
 */
class AiSessionBranch extends Model
{
    use HasConfigurablePrefix;

    protected $table = 'ai_session_branches';

    protected $fillable = [
        'session_id', 'branch_id', 'parent_branch_id',
        'fork_from_entry_id', 'summary', 'summary_details',
        'is_active', 'display_name',
    ];

    protected $casts = [
        'summary_details' => 'array',
        'is_active'       => 'boolean',
    ];

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
