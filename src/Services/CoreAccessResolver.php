<?php

namespace Hamava\CoreAccess\Services;

use Hamava\CoreAccess\Data\AccessDecision;
use Hamava\CoreAccess\Data\ResourceDescriptor;
use Hamava\CoreAccess\Models\CoreModule;
use Hamava\CoreAccess\Models\CorePermission;
use Hamava\CoreAccess\Models\CoreResourceGrant;
use Hamava\CoreAccess\Models\CoreTeamMember;
use Hamava\CoreAccess\Models\CoreTeamMemberRole;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

class CoreAccessResolver
{
    public function __construct(private readonly TeamScopeResolver $scopes) {}

    /**
     * @param  array<string, mixed>|object|ResourceDescriptor|null  $resource
     */
    public function check(
        ?Authenticatable $user,
        string $capability,
        array|ResourceDescriptor|null $resource = null,
        ?string $moduleCode = null,
    ): AccessDecision {
        if (! $user || (method_exists($user, 'isActive') && ! $user->isActive())) {
            return AccessDecision::deny('User is not active.');
        }

        $moduleCode ??= str($capability)->before('.')->toString();
        $descriptor = ResourceDescriptor::from($resource, $moduleCode);
        $permission = CorePermission::query()->where('name', $capability)->first();

        if (! $permission) {
            return AccessDecision::deny('Capability is not defined.');
        }

        $memberships = $this->scopes->activeMemberships($user);

        if ($memberships->isEmpty()) {
            return AccessDecision::deny('User has no active team memberships.');
        }

        $module = CoreModule::query()->where('code', $moduleCode)->first();

        if (! $module) {
            return AccessDecision::deny('Module is not defined.');
        }

        $roleAssignments = $this->roleAssignmentsWithCapability($memberships, $capability, $moduleCode);
        $operatorGlobalAssignments = $this->operatorGlobalAssignments($memberships, $moduleCode);
        $hasOperatorGlobal = $operatorGlobalAssignments->isNotEmpty();

        if ($roleAssignments->isEmpty() && ! $hasOperatorGlobal) {
            return AccessDecision::deny('No active membership role grants this capability.');
        }

        $effectiveAssignments = $roleAssignments->isNotEmpty() ? $roleAssignments : $operatorGlobalAssignments;
        $matched = $this->matchedFromAssignments($effectiveAssignments);

        $grantDeny = $descriptor
            ? $this->matchingResourceGrants($user, $effectiveAssignments, $module->id, $permission->id, $descriptor, 'deny')
            : collect();

        if ($grantDeny->isNotEmpty()) {
            $matched['resource_grant_ids'] = $grantDeny->pluck('id')->all();

            return AccessDecision::deny('Denied by explicit resource grant.', $matched);
        }

        if ($descriptor) {
            $denyScopes = $this->scopes->matchingScopesForTeams($effectiveAssignments->pluck('membership.team_id')->all(), $moduleCode, $descriptor, 'deny');

            if ($denyScopes->isNotEmpty()) {
                $matched['scope_ids'] = $denyScopes->pluck('id')->all();

                return AccessDecision::deny('Denied by team scope.', $matched);
            }
        }

        if ($hasOperatorGlobal) {
            return AccessDecision::allow('Allowed by operator-global capability.', $matched);
        }

        $requiresScope = (bool) $permission->requires_scope;

        if (! $requiresScope) {
            return AccessDecision::allow('Allowed by team membership role capability.', $matched);
        }

        if (! $descriptor) {
            return AccessDecision::deny('Capability requires a resource scope.', $matched);
        }

        $grantAllow = $this->matchingResourceGrants($user, $roleAssignments, $module->id, $permission->id, $descriptor, 'allow');

        if ($grantAllow->isNotEmpty()) {
            $matched['resource_grant_ids'] = $grantAllow->pluck('id')->all();

            return AccessDecision::allow('Allowed by explicit resource grant.', $matched);
        }

        $allowScopes = $this->scopes->matchingScopesForTeams($roleAssignments->pluck('membership.team_id')->all(), $moduleCode, $descriptor, 'allow');

        if ($allowScopes->isEmpty()) {
            return AccessDecision::deny('No matching scope for resource.', $matched);
        }

        $matched['scope_ids'] = $allowScopes->pluck('id')->all();

        return AccessDecision::allow('Allowed by team scope and role capability.', $matched);
    }

    public function can(?Authenticatable $user, string $capability, array|ResourceDescriptor|null $resource = null): bool
    {
        return $this->check($user, $capability, $resource)->allowed;
    }

    /**
     * @return Collection<int, string>
     */
    public function capabilities(?Authenticatable $user, ?string $moduleCode = null): Collection
    {
        if (! $user) {
            return collect();
        }

        return $this->scopes->activeMemberships($user)
            ->flatMap(fn (CoreTeamMember $membership) => $membership->roles
                ->filter(fn (CoreTeamMemberRole $assignment) => $this->assignmentIsActiveForModule($assignment, $moduleCode))
                ->flatMap(fn (CoreTeamMemberRole $assignment) => $assignment->role?->permissions ?? collect()))
            ->when($moduleCode, fn (Collection $permissions) => $permissions->filter(fn ($permission) => str_starts_with($permission->name, "{$moduleCode}.")))
            ->pluck('name')
            ->unique()
            ->sort()
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function visibleModules(?Authenticatable $user): Collection
    {
        if (! $user || (method_exists($user, 'isActive') && ! $user->isActive())) {
            return collect();
        }

        $memberships = $this->scopes->activeMemberships($user);
        $capabilities = $this->capabilities($user);

        return CoreModule::query()
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(function (CoreModule $module) use ($capabilities, $memberships): bool {
                $hasCapability = $capabilities->contains(fn (string $capability): bool => str_starts_with($capability, "{$module->code}."));

                if (! $hasCapability) {
                    return false;
                }

                if (! $module->requires_scope) {
                    return true;
                }

                return $this->scopes->activeScopesForTeams($memberships->pluck('team_id')->all(), $module->code)
                    ->where('effect', 'allow')
                    ->isNotEmpty();
            })
            ->map(fn (CoreModule $module): array => [
                'id' => $module->id,
                'code' => $module->code,
                'name' => $module->name,
                'name_fa' => $module->name_fa,
                'base_url' => $module->base_url,
                'dashboard_url' => $module->dashboard_url,
                'icon' => $module->icon,
                'color' => $module->color,
                'visible' => true,
                'granted_by' => $this->grantedBy($memberships, $module->code),
                'scopes' => $this->scopes->summarizeForUser($user, $module->code)->all(),
            ])
            ->values();
    }

    /**
     * @param  Collection<int, CoreTeamMember>  $memberships
     * @return Collection<int, CoreTeamMemberRole>
     */
    private function roleAssignmentsWithCapability(Collection $memberships, string $capability, string $moduleCode): Collection
    {
        return $memberships
            ->flatMap(fn (CoreTeamMember $membership) => $membership->roles->map(function (CoreTeamMemberRole $assignment) use ($membership) {
                $assignment->setRelation('membership', $membership);

                return $assignment;
            }))
            ->filter(fn (CoreTeamMemberRole $assignment): bool => $this->assignmentIsActiveForModule($assignment, $moduleCode)
                && (bool) $assignment->role?->is_active
                && $assignment->role->permissions->contains('name', $capability))
            ->values();
    }

    private function assignmentIsActiveForModule(CoreTeamMemberRole $assignment, ?string $moduleCode = null): bool
    {
        if ($assignment->valid_from && $assignment->valid_from->gt(today())) {
            return false;
        }

        if ($assignment->valid_to && $assignment->valid_to->lt(today())) {
            return false;
        }

        if (! $moduleCode || ! $assignment->module_id) {
            return true;
        }

        return $assignment->module?->code === $moduleCode;
    }

    /**
     * @param  Collection<int, CoreTeamMemberRole>  $assignments
     * @return array<string, list<int|string>>
     */
    private function matchedFromAssignments(Collection $assignments): array
    {
        return [
            'team_ids' => $assignments->pluck('membership.team_id')->filter()->unique()->values()->all(),
            'membership_ids' => $assignments->pluck('team_member_id')->filter()->unique()->values()->all(),
            'role_ids' => $assignments->pluck('role_id')->filter()->unique()->values()->all(),
            'scope_ids' => [],
            'resource_grant_ids' => [],
        ];
    }

    /**
     * @param  Collection<int, CoreTeamMember>  $memberships
     * @return list<array<string, mixed>>
     */
    private function grantedBy(Collection $memberships, string $moduleCode): array
    {
        return $memberships
            ->map(function (CoreTeamMember $membership) use ($moduleCode): ?array {
                $roles = $membership->roles
                    ->filter(fn (CoreTeamMemberRole $assignment) => $this->assignmentIsActiveForModule($assignment, $moduleCode)
                        && (bool) $assignment->role?->is_active
                        && $assignment->role->permissions->contains(fn ($permission) => str_starts_with($permission->name, "{$moduleCode}.")))
                    ->map(fn (CoreTeamMemberRole $assignment) => $assignment->role->display_name ?: $assignment->role->name)
                    ->unique()
                    ->values();

                if ($roles->isEmpty()) {
                    return null;
                }

                return [
                    'team_id' => $membership->team_id,
                    'team_code' => $membership->team?->code,
                    'roles' => $roles->all(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, CoreTeamMember>  $memberships
     * @return Collection<int, CoreTeamMemberRole>
     */
    private function operatorGlobalAssignments(Collection $memberships, string $moduleCode): Collection
    {
        $globalPermissions = collect(config('core-access.operator_global_permissions', []))
            ->push("{$moduleCode}.operator_global")
            ->filter()
            ->unique()
            ->values();

        return $memberships
            ->flatMap(fn (CoreTeamMember $membership) => $membership->roles->map(function (CoreTeamMemberRole $assignment) use ($membership) {
                $assignment->setRelation('membership', $membership);

                return $assignment;
            }))
            ->filter(fn (CoreTeamMemberRole $assignment) => $this->assignmentIsActiveForModule($assignment)
                && (bool) $assignment->role?->is_active
                && $assignment->role->permissions->contains(fn ($permission): bool => $globalPermissions->contains($permission->name)))
            ->values();
    }

    /**
     * @param  Collection<int, CoreTeamMemberRole>  $assignments
     * @return Collection<int, CoreResourceGrant>
     */
    private function matchingResourceGrants(
        Authenticatable $user,
        Collection $assignments,
        int|string $moduleId,
        int|string $capabilityId,
        ResourceDescriptor $resource,
        string $effect,
    ): Collection {
        $principalPairs = collect([
            ['user', $user->getAuthIdentifier()],
            ...$assignments->pluck('membership.team_id')->unique()->map(fn ($id) => ['team', $id])->all(),
            ...$assignments->pluck('team_member_id')->unique()->map(fn ($id) => ['team_member', $id])->all(),
            ...$assignments->pluck('role_id')->unique()->map(fn ($id) => ['role', $id])->all(),
        ]);

        return CoreResourceGrant::query()
            ->where('module_id', $moduleId)
            ->where('effect', $effect)
            ->where(fn ($query) => $query->whereNull('capability_id')->orWhere('capability_id', $capabilityId))
            ->where('resource_type', $resource->resource_type)
            ->where(function ($query) use ($resource): void {
                $query
                    ->when($resource->resource_id !== null, fn ($q) => $q->orWhere('resource_id', $resource->resource_id))
                    ->when($resource->resource_code, fn ($q) => $q->orWhere('resource_code', $resource->resource_code));
            })
            ->active()
            ->get()
            ->filter(fn (CoreResourceGrant $grant): bool => $principalPairs->contains(fn (array $pair): bool => $grant->principal_type === $pair[0]
                && (string) $grant->principal_id === (string) $pair[1]))
            ->values();
    }
}
