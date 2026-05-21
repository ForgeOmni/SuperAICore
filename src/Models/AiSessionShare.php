<?php

declare(strict_types=1);

namespace SuperAICore\Models;

use Illuminate\Database\Eloquent\Model;
use SuperAICore\Models\Concerns\HasConfigurablePrefix;

/**
 * Session-share row (opencode `share/share-next.ts` analogue).
 *
 * @property int $id
 * @property string $session_id
 * @property string $share_id
 * @property string $secret
 * @property string|null $remote_url
 * @property string|null $share_url
 * @property string $status         active|revoked|failed
 * @property array|null $metadata
 */
class AiSessionShare extends Model
{
    use HasConfigurablePrefix;

    public const STATUS_ACTIVE  = 'active';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_FAILED  = 'failed';

    protected $table = 'ai_session_shares';

    protected $fillable = [
        'session_id', 'share_id', 'secret', 'remote_url',
        'share_url', 'status', 'metadata', 'synced_at',
    ];

    protected $casts = [
        'metadata'  => 'array',
        'synced_at' => 'datetime',
    ];
}
