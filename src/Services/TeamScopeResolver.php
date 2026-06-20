<?php

namespace Hamava\CoreAccess\Services;

use Hamava\CoreAccess\Data\ResourceDescriptor;
use Hamava\CoreAccess\Models\CoreModule;
use Hamava\CoreAccess\Models\CoreTeamMember;
use Hamava\CoreAccess\Models\CoreTeamScope;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class TeamScopeResolver
{
    /**
     * @return EloquentCollection<int, CoreTeamMember>
     */
    public function activeMemberships(Authenticatable $user): EloquentCollection
    {
        return CoreTeamMember::query()
            ->with(['team', 'roles.role.permissions', 'roles.module'])
            ->where('user_id', $user->getAuthIdentifier())
            ->active()
            ->whereHas('team', fn ($query) => $query->where('is_active', true))
            ->get();
    }

    /**
     * @param  array<int|string>  $teamIds
     * @return EloquentCollection<int, CoreTeamScope>
     */
    public function activeScopesForTeams(array $teamIds, ?string $moduleCode = null): EloquentCollection
    {
        $moduleId = $moduleCode ? CoreModule::query()->where('code', $moduleCode)->value('id') : null;

        return CoreTeamScope::query()
            ->with(['team', 'module'])
            ->whereIn('team_id', $teamIds)
            ->when($moduleId, fn ($query) => $query->where('module_id', $moduleId))
            ->active()
            ->get();
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
                && $this->matchesAccessNode($scope, $accessNodeId)
                && $this->matches($scope, $resource))
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
        if ($scope->asset_category && $resource->attribute('asset_category') !== $scope->asset_category) {
            return false;
        }

        if ($scope->asset_type && $resource->attribute('asset_type') !== $scope->asset_type) {
            return false;
        }

        if ($scope->scope_type === 'all') {
            return true;
        }

        if ($scope->scope_type === 'asset_category') {
            return $scope->scope_code
                ? $resource->attribute('asset_category') === $scope->scope_code
                : (bool) $scope->asset_category;
        }

        if ($scope->scope_type === 'asset_type') {
            return $scope->scope_code
                ? $resource->attribute('asset_type') === $scope->scope_code
                : (bool) $scope->asset_type;
        }

        $includeAncestors = (bool) $scope->include_children;
        $ids = $resource->idsFor($scope->scope_type, $includeAncestors);
        $codes = $resource->codesFor($scope->scope_type, $includeAncestors);

        if ($scope->scope_id !== null && in_array((string) $scope->scope_id, array_map('strval', $ids), true)) {
            return true;
        }

        if ($scope->scope_code && in_array($scope->scope_code, $codes, true)) {
            return true;
        }

        return $scope->scope_id === null && $scope->scope_code === null;
    }

    private function matchesAccessNode(CoreTeamScope $scope, ?int $accessNodeId): bool
    {
        if ($scope->access_node_id === null) {
            return true;
        }

        return $accessNodeId !== null && (int) $scope->access_node_id === $accessNodeId;
    }
}
