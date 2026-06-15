<?php

namespace Hamava\CoreAccess\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class CoreTeamMember extends Model
{
    protected $table = 'core_team_members';

    protected $guarded = [];

    protected $casts = [
        'is_primary' => 'boolean',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'metadata' => 'array',
    ];

    public function getTable()
    {
        return config('core-access.tables.team_members', parent::getTable());
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(CoreTeam::class, 'team_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo($this->userModel(), 'user_id');
    }

    public function roles(): HasMany
    {
        return $this->hasMany(CoreTeamMemberRole::class, 'team_member_id');
    }

    public function scopeActive(Builder $query, ?Carbon $date = null): Builder
    {
        $date ??= today();

        return $query
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date));
    }

    private function userModel(): string
    {
        return config('core-access.user_model') ?: config('auth.providers.users.model');
    }
}
