<?php

namespace Hamava\CoreAccess\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CoreScopeEntityProvider extends Model
{
    protected $table = 'core_scope_entity_providers';

    protected $guarded = [];

    protected $casts = [
        'label_columns' => 'array',
        'search_columns' => 'array',
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];

    public function getTable()
    {
        return config('core-access.tables.scope_entity_providers', parent::getTable());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForModule(Builder $query, string $moduleCode): Builder
    {
        return $query->where('module_code', $moduleCode);
    }

    public function scopeForScopeType(Builder $query, string $scopeType): Builder
    {
        return $query->where('scope_type', $scopeType);
    }
}
