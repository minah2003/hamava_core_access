<?php

namespace Hamava\CoreAccess\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CoreTeam extends Model
{
    use SoftDeletes;

    protected $table = 'core_teams';

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function getTable()
    {
        return config('core-access.tables.teams', parent::getTable());
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo($this->userModel(), 'manager_user_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(CoreTeamMember::class, 'team_id');
    }

    public function scopes(): HasMany
    {
        return $this->hasMany(CoreTeamScope::class, 'team_id');
    }

    private function userModel(): string
    {
        return config('core-access.user_model') ?: config('auth.providers.users.model');
    }
}
