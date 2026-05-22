<?php

declare(strict_types=1);

namespace SuperAICore\Models;

use Illuminate\Database\Eloquent\Model;
use SuperAICore\Models\Concerns\HasConfigurablePrefix;

/**
 * 9Router-borrowed per-account credential row.
 *
 * @property int $id
 * @property int $provider_id
 * @property string $label
 * @property array|null $auth_payload         api_key / OAuth refresh / ...
 * @property int $priority                    lower = preferred
 * @property bool $is_active
 * @property \Carbon\Carbon|null $cooldown_until
 * @property string|null $cooldown_reason
 * @property \Carbon\Carbon|null $last_used_at
 * @property int $usage_count
 */
class AiProviderAccount extends Model
{
    use HasConfigurablePrefix;

    protected $table = 'ai_provider_accounts';

    protected $fillable = [
        'provider_id', 'label', 'auth_payload',
        'priority', 'is_active', 'cooldown_until', 'cooldown_reason',
        'last_used_at', 'usage_count',
    ];

    protected $casts = [
        'auth_payload'   => 'array',
        'is_active'      => 'boolean',
        'cooldown_until' => 'datetime',
        'last_used_at'   => 'datetime',
        'priority'       => 'integer',
        'usage_count'    => 'integer',
    ];

    public function provider()
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    public function isInCooldown(): bool
    {
        return $this->cooldown_until !== null && $this->cooldown_until->isFuture();
    }
}
