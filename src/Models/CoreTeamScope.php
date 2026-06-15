<?php

namespace Hamava\CoreAccess\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class CoreTeamScope extends Model
{
    protected $table = 'core_team_scopes';

    protected $guarded = [];

    protected $casts = [
        'include_children' => 'boolean',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'metadata' => 'array',
    ];

    public function getTable()
    {
        return config('core-access.tables.team_scopes', parent::getTable());
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(CoreTeam::class, 'team_id');
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(CoreModule::class, 'module_id');
    }

    public function accessNode(): BelongsTo
    {
        return $this->belongsTo(CoreAccessNode::class, 'access_node_id');
    }

    public function scopeActive(Builder $query, ?Carbon $date = null): Builder
    {
        $date ??= today();

        return $query
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date));
    }
}
