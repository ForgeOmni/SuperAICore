<?php

declare(strict_types=1);

namespace SuperAICore\Models;

use Illuminate\Database\Eloquent\Model;
use SuperAICore\Models\Concerns\HasConfigurablePrefix;

/**
 * 9Router-borrowed routing combo (saveable provider chain).
 *
 * @property int $id
 * @property string $name              kebab-case key, e.g. "premium-coding"
 * @property string|null $display_name human label
 * @property string|null $description
 * @property array $entries            [{provider, model}, ...] in fallback order
 * @property array|null $metadata
 * @property bool $is_active
 */
class AiRoutingCombo extends Model
{
    use HasConfigurablePrefix;

    protected $table = 'ai_routing_combos';

    protected $fillable = [
        'name', 'display_name', 'description',
        'entries', 'metadata', 'is_active', 'user_id',
    ];

    protected $casts = [
        'entries'   => 'array',
        'metadata'  => 'array',
        'is_active' => 'boolean',
    ];

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    /**
     * Resolve a combo name to its ordered entries, or [] if unknown.
     *
     * @return list<array{provider:string, model:?string}>
     */
    public static function resolveEntries(string $name): array
    {
        $combo = self::query()->where('name', $name)->active()->first();
        if ($combo === null) return [];
        $entries = $combo->entries ?? [];
        if (!is_array($entries)) return [];
        return array_values(array_filter($entries, fn ($e) => is_array($e) && !empty($e['provider'])));
    }
}
