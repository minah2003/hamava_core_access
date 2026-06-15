<?php

namespace Hamava\CoreAccess\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CoreModule extends Model
{
    use SoftDeletes;

    protected $table = 'core_modules';

    protected $guarded = [];

    protected $casts = [
        'is_enabled' => 'boolean',
        'requires_scope' => 'boolean',
        'metadata' => 'array',
    ];

    public function getTable()
    {
        return config('core-access.tables.modules', parent::getTable());
    }

    public function accessNodes(): HasMany
    {
        return $this->hasMany(CoreAccessNode::class, 'module_id');
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(CorePermission::class, 'module_id');
    }

    public function roles(): HasMany
    {
        return $this->hasMany(CoreRole::class, 'module_id');
    }

    public function teamScopes(): HasMany
    {
        return $this->hasMany(CoreTeamScope::class, 'module_id');
    }
}
