<?php

namespace Hamava\CoreAccess\Data;

use Hamava\CoreAccess\Models\CoreResourceGrant;
use Hamava\CoreAccess\Models\CoreTeamScope;
use Illuminate\Support\Collection;

final class QueryAuthorizationContext
{
    /**
     * @param  Collection<int, CoreTeamScope>  $allowScopes
     * @param  Collection<int, CoreTeamScope>  $denyScopes
     * @param  Collection<int, CoreResourceGrant>  $allowResourceGrants
     * @param  Collection<int, CoreResourceGrant>  $denyResourceGrants
     */
    public function __construct(
        public readonly string $moduleCode,
        public readonly string $capability,
        public readonly bool $capabilityGranted,
        public readonly bool $operatorGlobal,
        public readonly bool $requiresScope,
        public readonly Collection $allowScopes,
        public readonly Collection $denyScopes,
        public readonly Collection $allowResourceGrants,
        public readonly Collection $denyResourceGrants,
        public readonly ?string $denialReason = null,
    ) {}

    public static function denied(
        string $moduleCode,
        string $capability,
        string $reason,
    ): self {
        return new self(
            moduleCode: $moduleCode,
            capability: $capability,
            capabilityGranted: false,
            operatorGlobal: false,
            requiresScope: true,
            allowScopes: collect(),
            denyScopes: collect(),
            allowResourceGrants: collect(),
            denyResourceGrants: collect(),
            denialReason: $reason,
        );
    }

    public function hasBaseGrant(): bool
    {
        return $this->capabilityGranted
            || $this->operatorGlobal;
    }

    public function hasAnyAllowPath(): bool
    {
        if (! $this->hasBaseGrant()) {
            return false;
        }

        if (
            $this->denyScopes->contains(
                fn (CoreTeamScope $scope): bool => $scope->scope_type === 'all'
            )
        ) {
            return false;
        }

        if ($this->operatorGlobal || ! $this->requiresScope) {
            return true;
        }

        return $this->allowScopes->isNotEmpty()
            || $this->allowResourceGrants->isNotEmpty();
    }
}
