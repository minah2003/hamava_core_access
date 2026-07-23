<?php

namespace Hamava\CoreAccess\Services;

use Hamava\CoreAccess\Models\CorePermission;
use Hamava\CoreAccess\Models\CoreResourceGrant;
use Hamava\CoreAccess\Models\CoreTeamMember;
use Hamava\CoreAccess\Models\CoreTeamMemberRole;
use Hamava\CoreAccess\Models\CoreTeamRole;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

final class CoreCapabilityAssignmentResolver
{
    /**
     * Return active role assignments for the supplied memberships.
     *
     * @param  Collection<int, CoreTeamMember>  $memberships
     * @return Collection<int, array<string, mixed>>
     */
    public function active(
        Collection $memberships,
        ?string $moduleCode = null,
    ): Collection {
        return $this->effective($memberships)
            ->filter(
                fn (array $assignment): bool => $this
                    ->assignmentIsActiveForModule(
                        $assignment,
                        $moduleCode,
                    )
                    && (bool) $assignment['role']?->is_active
            )
            ->values();
    }

    /**
     * Return active assignments whose role grants the requested capability.
     *
     * @param  Collection<int, CoreTeamMember>  $memberships
     * @return Collection<int, array<string, mixed>>
     */
    public function grantingCapability(
        Collection $memberships,
        string $capability,
        string $moduleCode,
    ): Collection {
        return $this->active($memberships, $moduleCode)
            ->filter(
                fn (array $assignment): bool => (
                    $assignment['role']?->permissions ?? collect()
                )->contains(
                    fn (CorePermission $permission): bool => (bool) $permission->is_active
                        && $permission->name === $capability
                )
            )
            ->values();
    }

    /**
     * Return active assignments whose role grants operator-global access.
     *
     * @param  Collection<int, CoreTeamMember>  $memberships
     * @return Collection<int, array<string, mixed>>
     */
    public function grantingOperatorGlobal(
        Collection $memberships,
        string $moduleCode,
    ): Collection {
        $globalPermissions = collect(
            config('core-access.operator_global_permissions', []),
        )
            ->push("{$moduleCode}.operator_global")
            ->filter()
            ->unique()
            ->values();

        return $this->active($memberships, $moduleCode)
            ->filter(
                fn (array $assignment): bool => (
                    $assignment['role']?->permissions ?? collect()
                )->contains(
                    fn (CorePermission $permission): bool => (bool) $permission->is_active
                        && $globalPermissions->contains(
                            $permission->name,
                        )
                )
            )
            ->values();
    }

    /**
     * Merge assignment sets without returning the same assignment twice.
     *
     * @param  Collection<int, array<string, mixed>>  ...$assignmentSets
     * @return Collection<int, array<string, mixed>>
     */
    public function merge(
        Collection ...$assignmentSets,
    ): Collection {
        return collect($assignmentSets)
            ->flatMap(
                fn (Collection $assignments): Collection => $assignments
            )
            ->unique(
                fn (array $assignment): string => implode(':', [
                    $assignment['source'],
                    $assignment['membership_id'],
                    $assignment['member_role_assignment_id'] ?? '',
                    $assignment['team_role_id'] ?? '',
                ])
            )
            ->values();
    }

    /**
     * Build the principals represented by the user and effective assignments.
     *
     * @param  Collection<int, array<string, mixed>>  $assignments
     * @return Collection<int, array{type: string, id: int|string}>
     */
    public function principals(
        Authenticatable $user,
        Collection $assignments,
    ): Collection {
        return collect([
            [
                'type' => 'user',
                'id' => $user->getAuthIdentifier(),
            ],
            ...$assignments
                ->pluck('team_id')
                ->filter(fn (mixed $id): bool => filled($id))
                ->unique()
                ->map(
                    fn (mixed $id): array => [
                        'type' => 'team',
                        'id' => $id,
                    ]
                )
                ->all(),
            ...$assignments
                ->pluck('membership_id')
                ->filter(fn (mixed $id): bool => filled($id))
                ->unique()
                ->map(
                    fn (mixed $id): array => [
                        'type' => 'team_member',
                        'id' => $id,
                    ]
                )
                ->all(),
            ...$assignments
                ->pluck('role_id')
                ->filter(fn (mixed $id): bool => filled($id))
                ->unique()
                ->map(
                    fn (mixed $id): array => [
                        'type' => 'role',
                        'id' => $id,
                    ]
                )
                ->all(),
        ])
            ->filter(
                fn (array $principal): bool => filled($principal['id'])
            )
            ->unique(
                fn (array $principal): string => implode(':', [
                    $principal['type'],
                    (string) $principal['id'],
                ])
            )
            ->values();
    }

    /**
     * Determine whether a resource grant targets one of the principals.
     *
     * @param  Collection<int, array<string, mixed>>  $assignments
     */
    public function grantAppliesTo(
        CoreResourceGrant $grant,
        Authenticatable $user,
        Collection $assignments,
    ): bool {
        return $this->principals($user, $assignments)
            ->contains(
                fn (array $principal): bool => $grant->principal_type
                    === $principal['type']
                    && (string) $grant->principal_id
                        === (string) $principal['id']
            );
    }

    /**
     * Combine member-specific and team-level role assignments.
     *
     * @param  Collection<int, CoreTeamMember>  $memberships
     * @return Collection<int, array<string, mixed>>
     */
    private function effective(
        Collection $memberships,
    ): Collection {
        return $memberships
            ->flatMap(function (CoreTeamMember $membership): Collection {
                $memberAssignments = collect(
                    $membership->roles
                        ->map(
                            fn (CoreTeamMemberRole $assignment): array => $this
                                ->normalizeMemberRoleAssignment(
                                    $membership,
                                    $assignment,
                                )
                        )
                        ->all(),
                );

                $teamAssignments = collect(
                    ($membership->team?->teamRoles ?? collect())
                        ->map(
                            fn (CoreTeamRole $assignment): array => $this
                                ->normalizeTeamRoleAssignment(
                                    $membership,
                                    $assignment,
                                )
                        )
                        ->all(),
                );

                return $memberAssignments->merge($teamAssignments);
            })
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeMemberRoleAssignment(
        CoreTeamMember $membership,
        CoreTeamMemberRole $assignment,
    ): array {
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
    private function normalizeTeamRoleAssignment(
        CoreTeamMember $membership,
        CoreTeamRole $assignment,
    ): array {
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
    private function assignmentIsActiveForModule(
        array $assignment,
        ?string $moduleCode = null,
    ): bool {
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
}
