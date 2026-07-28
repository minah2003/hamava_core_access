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
                fn (CoreTeamScope $scope): bool => $this
                    ->isUnconditionalAllScope($scope)
            )
        ) {
            return false;
        }

        if ($this->operatorGlobal || ! $this->requiresScope) {
            return true;
        }

        return $this->allowScopes->contains(
            fn (CoreTeamScope $scope): bool => $this->scopeIsUsable($scope)
        )
            || $this->allowResourceGrants->isNotEmpty();
    }

    private function isUnconditionalAllScope(CoreTeamScope $scope): bool
    {
        return $this->scopeIsUsable($scope)
            && $scope->scope_type === 'all'
            && ! $this->hasScopeValue($scope->asset_category)
            && ! $this->hasScopeValue($scope->asset_type);
    }

    private function scopeIsUsable(CoreTeamScope $scope): bool
    {
        if (! $this->hasScopeValue($scope->scope_type)) {
            return false;
        }

        return match ($scope->scope_type) {
            'all' => true,
            'asset_category' => $this->hasScopeValue($scope->scope_code)
                || $this->hasScopeValue($scope->asset_category),
            'asset_type' => $this->hasScopeValue($scope->scope_code)
                || $this->hasScopeValue($scope->asset_type),
            default => $this->hasScopeValue($scope->scope_id)
                || $this->hasScopeValue($scope->scope_code),
        };
    }

    private function hasScopeValue(mixed $value): bool
    {
        return $value !== null
            && (! is_string($value) || trim($value) !== '');
    }
}
