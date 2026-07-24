<?php

namespace Hamava\CoreAccess\Services;

use Hamava\CoreAccess\Data\QueryAuthorizationContext;
use Hamava\CoreAccess\Models\CoreResourceGrant;
use Illuminate\Contracts\Auth\Authenticatable;

final class CoreQueryAuthorizationResolver
{
    public function __construct(
        private readonly CoreAccessContext $context,
        private readonly TeamScopeResolver $scopes,
        private readonly CoreCapabilityAssignmentResolver $assignments,
    ) {}

    public function resolve(
        ?Authenticatable $user,
        string $capability,
        ?string $moduleCode = null,
    ): QueryAuthorizationContext {
        $moduleCode ??= str($capability)
            ->before('.')
            ->toString();

        if (! $this->userIsActive($user)) {
            return QueryAuthorizationContext::denied(
                $moduleCode,
                $capability,
                'User is not active.',
            );
        }

        $permission = $this->context->permission($capability);

        if (! $permission) {
            return QueryAuthorizationContext::denied(
                $moduleCode,
                $capability,
                'Capability is not defined.',
            );
        }

        if (! $permission->is_active) {
            return QueryAuthorizationContext::denied(
                $moduleCode,
                $capability,
                'Capability is not active.',
            );
        }

        $module = $this->context->module($moduleCode);

        if (! $module) {
            return QueryAuthorizationContext::denied(
                $moduleCode,
                $capability,
                'Module is not defined.',
            );
        }

        if (! $module->is_enabled) {
            return QueryAuthorizationContext::denied(
                $moduleCode,
                $capability,
                'Module is not enabled.',
            );
        }

        if (
            $permission->module_id !== null
            && (string) $permission->module_id
                !== (string) $module->getKey()
        ) {
            return QueryAuthorizationContext::denied(
                $moduleCode,
                $capability,
                'Capability does not belong to the resolved module.',
            );
        }

        $memberships = $this->context->memberships($user);

        $capabilityAssignments = $this->assignments
            ->grantingCapability(
                $memberships,
                $capability,
                $moduleCode,
            );

        $operatorGlobalAssignments = $this->assignments
            ->grantingOperatorGlobal(
                $memberships,
                $moduleCode,
            );

        if (
            $capabilityAssignments->isEmpty()
            && $operatorGlobalAssignments->isEmpty()
        ) {
            return QueryAuthorizationContext::denied(
                $moduleCode,
                $capability,
                'No active membership role grants this capability.',
            );
        }

        $effectiveAssignments = $this->assignments->merge(
            $capabilityAssignments,
            $operatorGlobalAssignments,
        );

        $accessNodeId = $permission->access_node_id !== null
            ? (int) $permission->access_node_id
            : null;

        $allowTeamIds = $capabilityAssignments
            ->pluck('team_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $denyTeamIds = $effectiveAssignments
            ->pluck('team_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $allowScopes = $this->scopes
            ->activePageEntryScopesForTeams(
                $allowTeamIds,
                $moduleCode,
                'allow',
                $accessNodeId,
            );

        $denyScopes = $this->scopes
            ->activePageEntryScopesForTeams(
                $denyTeamIds,
                $moduleCode,
                'deny',
                $accessNodeId,
            );

        $resourceGrants = $this->context->resourceGrants(
            $user,
            $effectiveAssignments,
            $module->getKey(),
            $permission->getKey(),
        );

        $denyResourceGrants = $resourceGrants
            ->where('effect', 'deny')
            ->filter(
                fn (CoreResourceGrant $grant): bool => $this->assignments
                    ->grantAppliesTo(
                        $grant,
                        $user,
                        $effectiveAssignments,
                    )
            )
            ->values();

        $allowResourceGrants = $capabilityAssignments->isEmpty()
            ? collect()
            : $resourceGrants
                ->where('effect', 'allow')
                ->filter(
                    fn (CoreResourceGrant $grant): bool => $this->assignments
                        ->grantAppliesTo(
                            $grant,
                            $user,
                            $capabilityAssignments,
                        )
                )
                ->values();

        $requiresScope = (bool) $permission->requires_scope
            || (bool) $permission->accessNode?->requires_scope;

        return new QueryAuthorizationContext(
            moduleCode: $moduleCode,
            capability: $capability,
            capabilityGranted: $capabilityAssignments->isNotEmpty(),
            operatorGlobal: $operatorGlobalAssignments->isNotEmpty(),
            requiresScope: $requiresScope,
            allowScopes: $allowScopes,
            denyScopes: $denyScopes,
            allowResourceGrants: $allowResourceGrants,
            denyResourceGrants: $denyResourceGrants,
        );
    }

    /**
     * @phpstan-assert-if-true Authenticatable $user
     */
    private function userIsActive(?Authenticatable $user): bool
    {
        return $user !== null
            && (! method_exists($user, 'isActive') || $user->isActive());
    }
}
