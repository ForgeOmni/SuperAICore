<?php

declare(strict_types=1);

namespace SuperAICore\Models;

use Illuminate\Database\Eloquent\Model;
use SuperAICore\Models\Concerns\HasConfigurablePrefix;

/**
 * Mid-run HITL question row (opencode `tool/question.ts` + pi Extension UI).
 *
 * `kind` discriminates the four pi-style dialog renderers:
 *   - select  : button-row of pre-defined options (existing behaviour)
 *   - confirm : yes/no
 *   - input   : single-line text field
 *   - editor  : multi-line text editor
 *
 * @property int $id
 * @property string|null $session_id
 * @property string|null $process_id
 * @property string|null $agent_label
 * @property string $kind            select|confirm|input|editor
 * @property string $question
 * @property array|null $options
 * @property array|null $metadata
 * @property string|null $answer
 * @property string $status          pending|answered|cancelled|timed_out
 * @property \Illuminate\Support\Carbon|null $answered_at
 */
class AiUserQuestion extends Model
{
    use HasConfigurablePrefix;

    public const STATUS_PENDING    = 'pending';
    public const STATUS_ANSWERED   = 'answered';
    public const STATUS_CANCELLED  = 'cancelled';
    public const STATUS_TIMED_OUT  = 'timed_out';

    public const KIND_SELECT  = 'select';
    public const KIND_CONFIRM = 'confirm';
    public const KIND_INPUT   = 'input';
    public const KIND_EDITOR  = 'editor';

    public const KINDS = [
        self::KIND_SELECT,
        self::KIND_CONFIRM,
        self::KIND_INPUT,
        self::KIND_EDITOR,
    ];

    protected $table = 'ai_user_questions';

    protected $fillable = [
        'session_id', 'process_id', 'agent_label', 'kind',
        'question', 'options', 'metadata',
        'answer', 'status', 'answered_at',
    ];

    protected $casts = [
        'options'     => 'array',
        'metadata'    => 'array',
        'answered_at' => 'datetime',
    ];

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
