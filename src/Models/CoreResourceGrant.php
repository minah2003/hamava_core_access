<?php

namespace Hamava\CoreAccess\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class CoreResourceGrant extends Model
{
    protected $table = 'core_resource_grants';

    protected $guarded = [];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
        'metadata' => 'array',
    ];

    public function getTable()
    {
        return config('core-access.tables.resource_grants', parent::getTable());
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(CoreModule::class, 'module_id');
    }

    public function capability(): BelongsTo
    {
        return $this->belongsTo(CorePermission::class, 'capability_id');
    }

    public function scopeActive(Builder $query, ?Carbon $date = null): Builder
    {
        $date ??= today();

        return $query
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date));
    }
}
