<?php

namespace SuperAICore\Models;

use SuperAICore\Models\Concerns\HasConfigurablePrefix;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $pid
 * @property string $backend
 * @property string|null $command
 * @property string|null $external_id
 * @property string|null $external_label
 * @property string|null $output_dir
 * @property string|null $log_file
 * @property string $status       running | finished | failed | killed
 * @property int|null $user_id
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $ended_at
 * @property array|null $metadata
 */
class AiProcess extends Model
{
    const STATUS_RUNNING  = 'running';
    const STATUS_FINISHED = 'finished';
    const STATUS_FAILED   = 'failed';
    const STATUS_KILLED   = 'killed';

    use HasConfigurablePrefix;

    protected $table = 'ai_processes';

    protected $fillable = [
        'pid', 'backend', 'command',
        'external_id', 'external_label',
        'output_dir', 'log_file',
        'status', 'user_id',
        'started_at', 'ended_at', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function scopeRunning($q)
    {
        return $q->where('status', self::STATUS_RUNNING);
    }

    /**
     * Is the OS pid still alive?
     */
    public function isAlive(): bool
    {
        if (!$this->pid) return false;
        return posix_kill($this->pid, 0);
    }
}
