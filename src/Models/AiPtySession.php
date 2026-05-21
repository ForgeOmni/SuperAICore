<?php

declare(strict_types=1);

namespace SuperAICore\Models;

use Illuminate\Database\Eloquent\Model;
use SuperAICore\Models\Concerns\HasConfigurablePrefix;

/**
 * Long-lived shell session row (opencode `pty/` analogue, Phase 1).
 *
 * @property int $id
 * @property string|null $title
 * @property string $command
 * @property string|null $cwd
 * @property int|null $pid
 * @property string $status       running|exited|killed
 * @property int|null $exit_code
 * @property string|null $log_path
 * @property int $cursor          bytes written to log_path so far
 * @property array|null $metadata
 */
class AiPtySession extends Model
{
    use HasConfigurablePrefix;

    public const STATUS_RUNNING = 'running';
    public const STATUS_EXITED  = 'exited';
    public const STATUS_KILLED  = 'killed';

    protected $table = 'ai_pty_sessions';

    protected $fillable = [
        'title', 'command', 'cwd', 'pid', 'status',
        'exit_code', 'log_path', 'cursor', 'metadata', 'exited_at',
    ];

    protected $casts = [
        'metadata'  => 'array',
        'cursor'    => 'integer',
        'pid'       => 'integer',
        'exit_code' => 'integer',
        'exited_at' => 'datetime',
    ];
}
