<?php

namespace Hamava\CoreAccess\Traits;

use Hamava\CoreAccess\Data\ResourceDescriptor;
use Hamava\CoreAccess\Services\CoreAccessResolver;
use Illuminate\Database\Eloquent\Builder;

trait ScopedByCoreTeam
{
    public function scopeVisibleToCore(Builder $query, mixed $user, string $capability): Builder
    {
        $modelClass = $query->getModel()::class;

        return $query->where(function (Builder $builder) use ($modelClass, $user, $capability): void {
            $builder->whereRaw('1 = 0');

            $modelClass::query()->each(function ($model) use ($builder, $user, $capability): void {
                $descriptor = method_exists($model, 'toCoreResourceDescriptor')
                    ? $model->toCoreResourceDescriptor()
                    : ResourceDescriptor::make(
                        module_code: str($capability)->before('.')->toString(),
                        resource_type: $model->getTable(),
                        resource_id: $model->getKey(),
                    );

                if (app(CoreAccessResolver::class)->check($user, $capability, $descriptor)->allowed) {
                    $builder->orWhereKey($model->getKey());
                }
            });
        });
    }
}
