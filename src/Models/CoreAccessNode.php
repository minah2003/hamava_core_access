<?php

namespace Hamava\CoreAccess\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CoreAccessNode extends Model
{
    use SoftDeletes;

    protected $table = 'core_access_nodes';

    protected $guarded = [];

    protected $casts = [
        'requires_scope' => 'boolean',
        'is_visible_in_navigation' => 'boolean',
        'metadata' => 'array',
    ];

    public function getTable()
    {
        return config('core-access.tables.access_nodes', parent::getTable());
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(CoreModule::class, 'module_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(CorePermission::class, 'access_node_id');
    }

    public function scopeRules(): HasMany
    {
        return $this->hasMany(CoreAccessNodeScopeRule::class, 'access_node_id');
    }
}
