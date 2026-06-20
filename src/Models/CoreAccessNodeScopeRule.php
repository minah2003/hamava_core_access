<?php

namespace Hamava\CoreAccess\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoreAccessNodeScopeRule extends Model
{
    protected $table = 'core_access_node_scope_rules';

    protected $guarded = [];

    protected $casts = [
        'requires_entity' => 'boolean',
        'allow_include_children' => 'boolean',
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];

    public function getTable()
    {
        return config('core-access.tables.access_node_scope_rules', parent::getTable());
    }

    public function accessNode(): BelongsTo
    {
        return $this->belongsTo(CoreAccessNode::class, 'access_node_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForModule(Builder $query, string $moduleCode): Builder
    {
        return $query->whereHas('accessNode.module', fn (Builder $query) => $query->where('code', $moduleCode));
    }

    public function scopeForScopeType(Builder $query, string $scopeType): Builder
    {
        return $query->where('scope_type', $scopeType);
    }
}
