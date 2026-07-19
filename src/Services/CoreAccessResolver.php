<?php

namespace Hamava\CoreAccess\Services;

use Hamava\CoreAccess\Data\AccessDecision;
use Hamava\CoreAccess\Data\ResourceDescriptor;
use Hamava\CoreAccess\Models\CoreModule;
use Hamava\CoreAccess\Models\CorePermission;
use Hamava\CoreAccess\Models\CoreResourceGrant;
use Hamava\CoreAccess\Models\CoreTeamMember;
use Hamava\CoreAccess\Models\CoreTeamMemberRole;
use Hamava\CoreAccess\Models\CoreTeamRole;
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
        if (! $this->userIsActive($user)) {
            return AccessDecision::deny('User is not active.');
        }

        $moduleCode ??= str($capability)->before('.')->toString();
        $descriptor = ResourceDescriptor::from($resource, $moduleCode);
        $permission = CorePermission::query()->where('name', $capability)->first();

        if (! $permission) {
            return AccessDecision::deny('Capability is not defined.');
        }

        if (! $permission->is_active) {
            return AccessDecision::deny('Capability is not active.');
        }

        $permission->loadMissing('accessNode');
        $accessNodeId = $permission->access_node_id !== null ? (int) $permission->access_node_id : null;

        $module = CoreModule::query()
            ->where('code', $moduleCode)
            ->first();

        if (! $module) {
            return AccessDecision::deny('Module is not defined.');
        }

        if (! $module->is_enabled) {
            return AccessDecision::deny('Module is not enabled.');
        }

        $memberships = $this->scopes->activeMemberships($user);

        if ($memberships->isEmpty()) {
            return AccessDecision::deny('User has no active team memberships.');
        }

        $roleAssignments = $this->roleAssignmentsWithCapability($memberships, $capability, $moduleCode);
        $operatorGlobalAssignments = $this->operatorGlobalAssignments($memberships, $moduleCode);
        $hasOperatorGlobal = $operatorGlobalAssignments->isNotEmpty();

        if ($roleAssignments->isEmpty() && ! $hasOperatorGlobal) {
            return AccessDecision::deny('No active membership role grants this capability.');
        }

        $effectiveAssignments = $this->mergeAssignments($roleAssignments, $operatorGlobalAssignments);
        $matched = $this->matchedFromAssignments($effectiveAssignments);

        $grantDeny = $descriptor
            ? $this->matchingResourceGrants($user, $effectiveAssignments, $module->id, $permission->id, $descriptor, 'deny')
            : collect();

        if ($grantDeny->isNotEmpty()) {
            $matched['resource_grant_ids'] = $grantDeny->pluck('id')->all();

            return AccessDecision::deny('Denied by explicit resource grant.', $matched);
        }

        if ($descriptor) {
            $denyScopes = $this->scopes->matchingScopesForTeams($effectiveAssignments->pluck('team_id')->all(), $moduleCode, $descriptor, 'deny', $accessNodeId);

            if ($denyScopes->isNotEmpty()) {
                $matched['scope_ids'] = $denyScopes->pluck('id')->all();

                return AccessDecision::deny('Denied by team scope.', $matched);
            }
        }

        if ($hasOperatorGlobal) {
            return AccessDecision::allow('Allowed by operator-global capability.', $matched);
        }

        $requiresScope = (bool) $permission->requires_scope || (bool) $permission->accessNode?->requires_scope;

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

        $allowScopes = $this->scopes->matchingScopesForTeams($roleAssignments->pluck('team_id')->all(), $moduleCode, $descriptor, 'allow', $accessNodeId);

        if ($allowScopes->isEmpty()) {
            return AccessDecision::deny('No matching scope for resource.', $matched);
        }

        $matched['scope_ids'] = $allowScopes->pluck('id')->all();

        return AccessDecision::allow('Allowed by team scope and role capability.', $matched);
    }

    /**
     * @phpstan-assert-if-true Authenticatable $user
     */
    private function userIsActive(?Authenticatable $user): bool
    {
        return $user !== null
            && (! method_exists($user, 'isActive') || $user->isActive());
    }

    public function can(?Authenticatable $user, string $capability, array|ResourceDescriptor|null $resource = null): bool
    {
        return $this->check($user, $capability, $resource)->allowed;
    }

    public function canEnterAccessNode(?Authenticatable $user, string $capability, ?string $moduleCode = null): AccessDecision
    {
        if (! $this->userIsActive($user)) {
            return AccessDecision::deny('User is not active.');
        }

        $permission = CorePermission::query()->where('name', $capability)->first();

        if (! $permission) {
            return AccessDecision::deny('Capability is not defined.');
        }

        if (! $permission->is_active) {
            return AccessDecision::deny('Capability is not active.');
        }

        $permission->loadMissing('accessNode', 'module');
        $moduleCode ??= $permission->module?->code ?? str($capability)->before('.')->toString();
        $accessNodeId = $permission->access_node_id !== null ? (int) $permission->access_node_id : null;

        $module = CoreModule::query()->where('code', $moduleCode)->first();

        if (! $module) {
            return AccessDecision::deny('Module is not defined.');
        }

        if (! $module->is_enabled) {
            return AccessDecision::deny('Module is not enabled.');
        }

        $memberships = $this->scopes->activeMemberships($user);

        if ($memberships->isEmpty()) {
            return AccessDecision::deny('User has no active team memberships.');
        }

        $roleAssignments = $this->roleAssignmentsWithCapability($memberships, $capability, $moduleCode);
        $operatorGlobalAssignments = $this->operatorGlobalAssignments($memberships, $moduleCode);

        if ($roleAssignments->isEmpty() && $operatorGlobalAssignments->isEmpty()) {
            return AccessDecision::deny('No active membership role grants this capability.');
        }

        if ($operatorGlobalAssignments->isNotEmpty()) {
            return AccessDecision::allow(
                'Allowed by operator-global capability.',
                $this->matchedFromAssignments($this->mergeAssignments($roleAssignments, $operatorGlobalAssignments)),
            );
        }

        $matched = $this->matchedFromAssignments($roleAssignments);
        $requiresScope = (bool) $permission->requires_scope || (bool) $permission->accessNode?->requires_scope;

        if (! $requiresScope) {
            return AccessDecision::allow('Allowed by team membership role capability.', $matched);
        }

        $teamIds = $roleAssignments->pluck('team_id')->all();
        $denyScopes = $this->scopes
            ->activePageEntryScopesForTeams($teamIds, $moduleCode, 'deny', $accessNodeId)
            ->where('scope_type', 'all')
            ->values();

        if ($denyScopes->isNotEmpty()) {
            $matched['scope_ids'] = $denyScopes->pluck('id')->all();

            return AccessDecision::deny('Denied by team scope.', $matched);
        }

        $allowScopes = $this->scopes->activePageEntryScopesForTeams($teamIds, $moduleCode, 'allow', $accessNodeId);

        if ($allowScopes->isEmpty()) {
            return AccessDecision::deny('No matching scope for access node.', $matched);
        }

        $matched['scope_ids'] = $allowScopes->pluck('id')->all();

        return AccessDecision::allow('Allowed by team scope and role capability.', $matched);
    }

    /**
     * @return Collection<int, string>
     */
    public function capabilities(
        ?Authenticatable $user,
        ?string $moduleCode = null,
    ): Collection {
        if (! $this->userIsActive($user)) {
            return collect();
        }

        if ($moduleCode !== null) {
            $module = CoreModule::query()
                ->where('code', $moduleCode)
                ->first();

            if (! $module || ! $module->is_enabled) {
                return collect();
            }

            $enabledModuleIds = collect([$module->getKey()]);
        } else {
            $enabledModuleIds = CoreModule::query()
                ->where('is_enabled', true)
                ->pluck('id');
        }

        return $this->activeEffectiveRoleAssignments(
            $this->scopes->activeMemberships($user),
            $moduleCode,
        )
            ->flatMap(
                fn (array $assignment) => $assignment['role']?->permissions ?? collect()
            )
            ->filter(
                fn (CorePermission $permission): bool => (bool) $permission->is_active
                    && (
                        $permission->module_id === null
                        || $enabledModuleIds->contains(
                            $permission->module_id
                        )
                    )
            )
            ->when(
                $moduleCode,
                fn (Collection $permissions) => $permissions->filter(
                    fn (CorePermission $permission): bool => str_starts_with(
                        $permission->name,
                        "{$moduleCode}.",
                    )
                )
            )
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
        if (! $this->userIsActive($user)) {
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
     * @return Collection<int, array<string, mixed>>
     */
    private function roleAssignmentsWithCapability(Collection $memberships, string $capability, string $moduleCode): Collection
    {
        return $this->activeEffectiveRoleAssignments($memberships, $moduleCode)
            ->filter(
                fn (array $assignment): bool => ($assignment['role']?->permissions ?? collect())
                    ->contains(
                        fn (CorePermission $permission): bool => (bool) $permission->is_active
                            && $permission->name === $capability
                    ))
            ->values();
    }

    /**
     * @param  Collection<int, CoreTeamMember>  $memberships
     * @return Collection<int, array<string, mixed>>
     */
    private function activeEffectiveRoleAssignments(Collection $memberships, ?string $moduleCode = null): Collection
    {
        return $this->effectiveRoleAssignments($memberships)
            ->filter(fn (array $assignment): bool => $this->assignmentIsActiveForModule($assignment, $moduleCode)
                && (bool) ($assignment['role']?->is_active))
            ->values();
    }

    /**
     * @param  Collection<int, CoreTeamMember>  $memberships
     * @return Collection<int, array<string, mixed>>
     */
    private function effectiveRoleAssignments(Collection $memberships): Collection
    {
        return $memberships
            ->flatMap(function (CoreTeamMember $membership): Collection {
                $memberAssignments = collect($membership->roles
                    ->map(fn (CoreTeamMemberRole $assignment): array => $this->normalizeMemberRoleAssignment($membership, $assignment))
                    ->all());

                $teamAssignments = collect(($membership->team?->teamRoles ?? collect())
                    ->map(fn (CoreTeamRole $assignment): array => $this->normalizeTeamRoleAssignment($membership, $assignment))
                    ->all());

                return $memberAssignments->merge($teamAssignments);
            })
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeMemberRoleAssignment(CoreTeamMember $membership, CoreTeamMemberRole $assignment): array
    {
        return [
            'source' => 'member',
            'assignment' => $assignment,
            'membership' => $membership,
            'role' => $assignment->role,
            'module' => $assignment->module,
            'team_id' => $membership->team_id,
            'membership_id' => $membership->id,
            'role_id' => $assignment->role_id,
            'module_id' => $assignment->module_id,
            'module_code' => $assignment->module?->code,
            'team_role_id' => null,
            'member_role_assignment_id' => $assignment->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeTeamRoleAssignment(CoreTeamMember $membership, CoreTeamRole $assignment): array
    {
        return [
            'source' => 'team',
            'assignment' => $assignment,
            'membership' => $membership,
            'role' => $assignment->role,
            'module' => $assignment->module,
            'team_id' => $membership->team_id,
            'membership_id' => $membership->id,
            'role_id' => $assignment->role_id,
            'module_id' => $assignment->module_id,
            'module_code' => $assignment->module?->code,
            'team_role_id' => $assignment->id,
            'member_role_assignment_id' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $assignment
     */
    private function assignmentIsActiveForModule(array $assignment, ?string $moduleCode = null): bool
    {
        $model = $assignment['assignment'];

        if ($model->valid_from && $model->valid_from->gt(today())) {
            return false;
        }

        if ($model->valid_to && $model->valid_to->lt(today())) {
            return false;
        }

        if (! $moduleCode || ! $assignment['module_id']) {
            return true;
        }

        return $assignment['module_code'] === $moduleCode;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $assignments
     * @return array<string, list<int|string>>
     */
    private function matchedFromAssignments(Collection $assignments): array
    {
        return [
            'team_ids' => $assignments->pluck('team_id')->filter()->unique()->values()->all(),
            'membership_ids' => $assignments->pluck('membership_id')->filter()->unique()->values()->all(),
            'role_ids' => $assignments->pluck('role_id')->filter()->unique()->values()->all(),
            'team_role_ids' => $assignments->pluck('team_role_id')->filter()->unique()->values()->all(),
            'member_role_assignment_ids' => $assignments->pluck('member_role_assignment_id')->filter()->unique()->values()->all(),
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
                $roles = $this->activeEffectiveRoleAssignments(collect([$membership]), $moduleCode)
                    ->filter(
                        fn (array $assignment): bool => ($assignment['role']?->permissions ?? collect())
                            ->contains(
                                fn (CorePermission $permission): bool => (bool) $permission->is_active
                                    && str_starts_with(
                                        $permission->name,
                                        "{$moduleCode}.",
                                    )
                            ))
                    ->map(fn (array $assignment) => $assignment['role']->display_name ?: $assignment['role']->name)
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
     * @return Collection<int, array<string, mixed>>
     */
    private function operatorGlobalAssignments(Collection $memberships, string $moduleCode): Collection
    {
        $globalPermissions = collect(config('core-access.operator_global_permissions', []))
            ->push("{$moduleCode}.operator_global")
            ->filter()
            ->unique()
            ->values();

        return $this->activeEffectiveRoleAssignments($memberships, $moduleCode)
            ->filter(
                fn (array $assignment): bool => ($assignment['role']?->permissions ?? collect())
                    ->contains(
                        fn (CorePermission $permission): bool => (bool) $permission->is_active
                            && $globalPermissions->contains(
                                $permission->name
                            )
                    ))
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function mergeAssignments(Collection ...$assignmentSets): Collection
    {
        return collect($assignmentSets)
            ->flatMap(fn (Collection $assignments): Collection => $assignments)
            ->unique(fn (array $assignment): string => implode(':', [
                $assignment['source'],
                $assignment['membership_id'],
                $assignment['member_role_assignment_id'] ?? '',
                $assignment['team_role_id'] ?? '',
            ]))
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $assignments
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
            ...$assignments->pluck('team_id')->unique()->map(fn ($id) => ['team', $id])->all(),
            ...$assignments->pluck('membership_id')->unique()->map(fn ($id) => ['team_member', $id])->all(),
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
