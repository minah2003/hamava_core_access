<?php

namespace Hamava\CoreAccess\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Permission\Models\Role;

class CoreRole extends Role
{
    protected $casts = [
        'is_system' => 'boolean',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function module(): BelongsTo
    {
        return $this->belongsTo(CoreModule::class, 'module_id');
    }

    public function memberRoleAssignments(): HasMany
    {
        return $this->hasMany(CoreTeamMemberRole::class, 'role_id');
    }

    public function teamRoleAssignments(): HasMany
    {
        return $this->hasMany(CoreTeamRole::class, 'role_id');
    }
}
