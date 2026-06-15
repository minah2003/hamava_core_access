<?php

namespace Hamava\CoreAccess\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class CoreTeamMemberRole extends Model
{
    protected $table = 'core_team_member_roles';

    protected $guarded = [];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
        'metadata' => 'array',
    ];

    public function getTable()
    {
        return config('core-access.tables.team_member_roles', parent::getTable());
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(CoreTeamMember::class, 'team_member_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(CoreRole::class, 'role_id');
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(CoreModule::class, 'module_id');
    }

    public function scopeActive(Builder $query, ?Carbon $date = null): Builder
    {
        $date ??= today();

        return $query
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date));
    }
}
