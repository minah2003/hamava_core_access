<?php

namespace Hamava\CoreAccess\Services;

use Hamava\CoreAccess\Data\ResourceDescriptor;
use Hamava\CoreAccess\Models\CoreTeamMember;
use Hamava\CoreAccess\Models\CoreTeamScope;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class TeamScopeResolver
{
    public function __construct(
        private readonly CoreAccessContext $context,
    ) {}

    /**
     * @return EloquentCollection<int, CoreTeamMember>
     */
    public function activeMemberships(
        Authenticatable $user,
    ): EloquentCollection {
        return $this->context->memberships($user);
    }

    /**
     * @param  array<int|string>  $teamIds
     * @return EloquentCollection<int, CoreTeamScope>
     */
    public function activeScopesForTeams(
        array $teamIds,
        ?string $moduleCode = null,
    ): EloquentCollection {
        return $this->context->scopes(
            $teamIds,
            $moduleCode,
        );
    }

    /**
     * @param  array<int|string>  $teamIds
     * @return EloquentCollection<int, CoreTeamScope>
     */
    public function matchingScopesForTeams(
        array $teamIds,
        string $moduleCode,
        ResourceDescriptor $resource,
        ?string $effect = null,
        ?int $accessNodeId = null,
    ): EloquentCollection {
        return $this->activeScopesForTeams($teamIds, $moduleCode)
            ->filter(fn (CoreTeamScope $scope): bool => ($effect === null || $scope->effect === $effect)
                && $this->scopeIsUsable($scope)
                && $this->matchesAccessNode($scope, $accessNodeId)
                && $this->matches($scope, $resource))
            ->values();
    }

    /**
     * @param  array<int|string>  $teamIds
     * @return EloquentCollection<int, CoreTeamScope>
     */
    public function activePageEntryScopesForTeams(
        array $teamIds,
        string $moduleCode,
        ?string $effect = null,
        ?int $accessNodeId = null,
    ): EloquentCollection {
        return $this->activeScopesForTeams($teamIds, $moduleCode)
            ->filter(fn (CoreTeamScope $scope): bool => ($effect === null || $scope->effect === $effect)
                && $this->scopeIsUsable($scope)
                && $this->matchesAccessNode($scope, $accessNodeId))
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function summarizeForUser(Authenticatable $user, ?string $moduleCode = null): Collection
    {
        $memberships = $this->activeMemberships($user);

        return $this->activeScopesForTeams($memberships->pluck('team_id')->all(), $moduleCode)
            ->map(fn (CoreTeamScope $scope): array => [
                'id' => $scope->id,
                'team_code' => $scope->team?->code,
                'module' => $scope->module?->code,
                'access_node_id' => $scope->access_node_id,
                'scope_type' => $scope->scope_type,
                'scope_id' => $scope->scope_id,
                'scope_code' => $scope->scope_code,
                'asset_category' => $scope->asset_category,
                'asset_type' => $scope->asset_type,
                'include_children' => $scope->include_children,
                'effect' => $scope->effect,
            ])
            ->values();
    }

    public function matches(CoreTeamScope $scope, ResourceDescriptor $resource): bool
    {
        if (! $this->scopeIsUsable($scope)) {
            return false;
        }

        if ($this->hasScopeValue($scope->asset_category) && $resource->attribute('asset_category') !== $scope->asset_category) {
            return false;
        }

        if ($this->hasScopeValue($scope->asset_type) && $resource->attribute('asset_type') !== $scope->asset_type) {
            return false;
        }

        if ($scope->scope_type === 'all') {
            return true;
        }

        if ($scope->scope_type === 'asset_category') {
            $category = $this->firstScopeValue($scope->scope_code, $scope->asset_category);

            return $this->hasScopeValue($category)
                && $resource->attribute('asset_category') === $category;
        }

        if ($scope->scope_type === 'asset_type') {
            $type = $this->firstScopeValue($scope->scope_code, $scope->asset_type);

            return $this->hasScopeValue($type)
                && $resource->attribute('asset_type') === $type;
        }

        $includeAncestors = (bool) $scope->include_children;
        $ids = $resource->idsFor($scope->scope_type, $includeAncestors);
        $codes = $resource->codesFor($scope->scope_type, $includeAncestors);

        if ($this->hasScopeValue($scope->scope_id) && in_array((string) $scope->scope_id, array_map('strval', $ids), true)) {
            return true;
        }

        if ($this->hasScopeValue($scope->scope_code) && in_array((string) $scope->scope_code, $codes, true)) {
            return true;
        }

        return false;
    }

    public function isUnconditionalAllScope(CoreTeamScope $scope): bool
    {
        return $this->scopeIsUsable($scope)
            && $scope->scope_type === 'all'
            && ! $this->hasScopeValue($scope->asset_category)
            && ! $this->hasScopeValue($scope->asset_type);
    }

    private function matchesAccessNode(CoreTeamScope $scope, ?int $accessNodeId): bool
    {
        if ($scope->access_node_id === null) {
            return true;
        }

        return $accessNodeId !== null && (int) $scope->access_node_id === $accessNodeId;
    }

    private function hasScopeValue(mixed $value): bool
    {
        return $value !== null
            && (! is_string($value) || trim($value) !== '');
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

    private function firstScopeValue(mixed ...$values): mixed
    {
        foreach ($values as $value) {
            if ($this->hasScopeValue($value)) {
                return $value;
            }
        }

        return null;
    }
}
