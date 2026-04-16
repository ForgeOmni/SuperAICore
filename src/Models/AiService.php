<?php

namespace ForgeOmni\AiCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * AI Service — capability-specific endpoint configuration.
 *
 * @property int $id
 * @property string $name
 * @property int $capability_id
 * @property string $protocol       anthropic | openai | superagent | minimax
 * @property string $base_url
 * @property string|null $api_key   Encrypted
 * @property string $model
 * @property array|null $extra_config
 * @property bool $is_active
 * @property int $sort_order
 */
class AiService extends Model
{
    const PROTOCOL_ANTHROPIC  = 'anthropic';
    const PROTOCOL_OPENAI     = 'openai';
    const PROTOCOL_MINIMAX    = 'minimax';
    const PROTOCOL_SUPERAGENT = 'superagent';

    protected $table = 'ai_services';

    protected $fillable = [
        'name', 'capability_id', 'protocol', 'base_url',
        'api_key', 'model', 'extra_config', 'is_active', 'sort_order',
    ];

    protected $hidden = ['api_key'];

    protected $casts = [
        'extra_config' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function setApiKeyAttribute(?string $value): void
    {
        $this->attributes['api_key'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getDecryptedApiKeyAttribute(): ?string
    {
        return $this->attributes['api_key']
            ? Crypt::decryptString($this->attributes['api_key'])
            : null;
    }

    public function getMaskedApiKeyAttribute(): ?string
    {
        $key = $this->decrypted_api_key;
        if (!$key) return null;
        if (strlen($key) <= 8) return str_repeat('*', strlen($key));
        return substr($key, 0, 7) . '...' . substr($key, -4);
    }

    public function hasApiKey(): bool
    {
        return !empty($this->attributes['api_key']);
    }

    public function capability()
    {
        return $this->belongsTo(AiCapability::class, 'capability_id');
    }
}
