<?php

namespace SuperAICore\Models;

use SuperAICore\Models\Concerns\HasConfigurablePrefix;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per Claude Code Skill tool invocation.
 *
 * Lifecycle:
 *   PreToolUse hook  → INSERT row, status='in_progress'
 *   Stop hook        → UPDATE same session's open rows, status='completed'
 *   Periodic sweep   → mark stale 'in_progress' rows as 'orphaned'
 *
 * @property int $id
 * @property string $skill_name
 * @property string|null $host_app
 * @property string|null $session_id
 * @property string $status
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $completed_at
 * @property int|null $duration_ms
 * @property string|null $transcript_path
 * @property string|null $error_summary
 * @property string|null $cwd
 * @property array|null $metadata
 */
class SkillExecution extends Model
{
    use HasConfigurablePrefix;

    protected $table = 'skill_executions';

    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED   = 'completed';
    public const STATUS_FAILED      = 'failed';
    public const STATUS_ORPHANED    = 'orphaned';
    public const STATUS_INTERRUPTED = 'interrupted';

    protected $fillable = [
        'skill_name',
        'host_app',
        'session_id',
        'status',
        'started_at',
        'completed_at',
        'duration_ms',
        'transcript_path',
        'error_summary',
        'cwd',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'duration_ms' => 'integer',
    ];
}
