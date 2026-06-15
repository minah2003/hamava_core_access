<?php

namespace Hamava\CoreAccess\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Permission;

class CorePermission extends Permission
{
    protected $casts = [
        'is_active' => 'boolean',
        'requires_scope' => 'boolean',
        'metadata' => 'array',
    ];

    public function module(): BelongsTo
    {
        return $this->belongsTo(CoreModule::class, 'module_id');
    }

    public function accessNode(): BelongsTo
    {
        return $this->belongsTo(CoreAccessNode::class, 'access_node_id');
    }
}
