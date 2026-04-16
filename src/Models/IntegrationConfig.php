<?php

namespace ForgeOmni\AiCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Integration config key-value store — used for MCP server OAuth sessions,
 * 3rd-party tool settings, etc.
 *
 * @property string $integration_key
 * @property string $field_key
 * @property string|null $value
 * @property bool $is_secret
 */
class IntegrationConfig extends Model
{
    protected $table = 'integration_configs';

    protected $fillable = ['integration_key', 'field_key', 'value', 'is_secret'];

    protected $casts = ['is_secret' => 'boolean'];

    public static function getValue(string $integrationKey, string $fieldKey): ?string
    {
        $record = static::where('integration_key', $integrationKey)
            ->where('field_key', $fieldKey)
            ->first();
        if (!$record || $record->value === null) return null;
        return $record->is_secret ? Crypt::decryptString($record->value) : $record->value;
    }

    public static function setValue(string $integrationKey, string $fieldKey, ?string $value, bool $isSecret = false): void
    {
        static::updateOrCreate(
            ['integration_key' => $integrationKey, 'field_key' => $fieldKey],
            [
                'value' => ($value && $isSecret) ? Crypt::encryptString($value) : $value,
                'is_secret' => $isSecret,
            ]
        );
    }

    public static function getAll(string $integrationKey): array
    {
        return static::where('integration_key', $integrationKey)
            ->get()
            ->mapWithKeys(function ($r) {
                $v = ($r->is_secret && $r->value) ? Crypt::decryptString($r->value) : $r->value;
                return [$r->field_key => $v];
            })
            ->toArray();
    }

    public static function isConfigured(string $integrationKey): bool
    {
        return static::where('integration_key', $integrationKey)
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->exists();
    }
}
