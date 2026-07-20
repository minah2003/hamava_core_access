<?php

namespace Hamava\CoreAccess\Traits;

use Hamava\CoreAccess\Data\ResourceDescriptor;
use Hamava\CoreAccess\Services\CoreAccessResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * @deprecated since 0.2.0. Scheduled for removal in 1.0.0.
 *
 * This trait scans the full model table and performs authorization checks
 * in PHP. Use a domain-specific query service that translates resolved
 * access scopes into SQL predicates.
 */
trait ScopedByCoreTeam
{
    /**
     * @deprecated since 0.2.0. Use a domain-specific SQL query scope or
     * query service instead. Scheduled for removal in 1.0.0.
     */
    public function scopeVisibleToCore(
        Builder $query,
        mixed $user,
        string $capability,
    ): Builder {
        $modelClass = $query->getModel()::class;

        return $query->where(
            function (Builder $builder) use (
                $modelClass,
                $user,
                $capability,
            ): void {
                $builder->whereRaw('1 = 0');

                $modelClass::query()->each(
                    function ($model) use (
                        $builder,
                        $user,
                        $capability,
                    ): void {
                        $descriptor = method_exists(
                            $model,
                            'toCoreResourceDescriptor',
                        )
                            ? $model->toCoreResourceDescriptor()
                            : ResourceDescriptor::make(
                                module_code: str($capability)
                                    ->before('.')
                                    ->toString(),
                                resource_type: $model->getTable(),
                                resource_id: $model->getKey(),
                            );

                        $decision = app(CoreAccessResolver::class)
                            ->check(
                                $user,
                                $capability,
                                $descriptor,
                            );

                        if ($decision->allowed) {
                            $builder->orWhereKey(
                                $model->getKey(),
                            );
                        }
                    }
                );
            }
        );
    }
}
