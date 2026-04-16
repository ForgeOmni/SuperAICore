<?php

namespace ForgeOmni\AiCore\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property int $sort_order
 * @property array|null $pre_process
 */
class AiCapability extends Model
{
    protected $table = 'ai_capabilities';

    protected $fillable = [
        'slug', 'name', 'description', 'pre_process', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'pre_process' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function services()
    {
        return $this->hasMany(AiService::class, 'capability_id');
    }

    public function routings()
    {
        return $this->hasMany(AiServiceRouting::class, 'capability_id');
    }

    public static function findBySlug(string $slug): ?self
    {
        return static::where('slug', $slug)->first();
    }
}
