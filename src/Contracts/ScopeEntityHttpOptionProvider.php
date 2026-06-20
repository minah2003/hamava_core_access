<?php

namespace Hamava\CoreAccess\Contracts;

use Hamava\CoreAccess\Models\CoreScopeEntityProvider;

interface ScopeEntityHttpOptionProvider
{
    /**
     * @param  array<string, mixed>  $filters
     * @return list<array{id: mixed, code: string, label: string}>
     */
    public function options(CoreScopeEntityProvider $provider, ?string $search = null, array $filters = [], int $limit = 50): array;
}
