<?php

namespace Hamava\CoreAccess\Tests\Unit;

use Hamava\CoreAccess\Data\QueryAuthorizationContext;
use Hamava\CoreAccess\Models\CoreResourceGrant;
use Hamava\CoreAccess\Models\CoreTeamScope;
use Hamava\CoreAccess\Services\CoreQueryAuthorizationResolver;
use Hamava\CoreAccess\Services\TeamScopeResolver;
use Hamava\CoreAccess\Tests\TestCase;
use Illuminate\Support\Facades\DB;

class CoreQueryAuthorizationResolverTest extends TestCase
{
    public function test_allow_scope_from_team_without_capability_is_excluded(): void
    {
        $user = $this->user('query-scope-user');
        $module = $this->module('inventory');
        $targetPermission = $this->permission(
            'inventory.records.view',
            $module,
            requiresScope: true,
        );
        $unrelatedPermission = $this->permission(
            'inventory.records.delete',
            $module,
            requiresScope: true,
        );
        $targetRole = $this->role(
            'query_scope_viewer',
            $module,
            $targetPermission,
        );
        $unrelatedRole = $this->role(
            'query_scope_deleter',
            $module,
            $unrelatedPermission,
        );
        $capabilityTeam = $this->team('QUERY-SCOPE-A');
        $unrelatedTeam = $this->team('QUERY-SCOPE-B');

        $this->membership(
            $user,
            $capabilityTeam,
            $targetRole,
            $module,
        );
        $this->membership(
            $user,
            $unrelatedTeam,
            $unrelatedRole,
            $module,
        );

        $capabilityScope = $this->scope(
            $capabilityTeam,
            $module,
            'region',
            10,
        );
        $unrelatedScope = $this->scope(
            $unrelatedTeam,
            $module,
            'region',
            20,
        );

        $context = app(CoreQueryAuthorizationResolver::class)
            ->resolve(
                $user,
                $targetPermission->name,
                $module->code,
            );

        $this->assertTrue($context->capabilityGranted);
        $this->assertSame(
            [$capabilityScope->id],
            $context->allowScopes->pluck('id')->all(),
        );
        $this->assertContains(
            $capabilityScope->id,
            $context->allowScopes->pluck('id')->all(),
        );
        $this->assertNotContains(
            $unrelatedScope->id,
            $context->allowScopes->pluck('id')->all(),
        );
    }

    public function test_deny_scope_is_preserved_against_operator_global(): void
    {
        $user = $this->user('query-global-deny-scope');
        $module = $this->module('inventory');
        $targetPermission = $this->permission(
            'inventory.records.view',
            $module,
            requiresScope: true,
        );
        $globalPermission = $this->permission(
            'inventory.operator_global',
            $module,
        );
        $operatorRole = $this->role(
            'query_global_scope_operator',
            $module,
            $globalPermission,
        );
        $team = $this->team('QUERY-GLOBAL-SCOPE');

        $this->membership(
            $user,
            $team,
            $operatorRole,
            $module,
        );

        $denyScope = $this->scope(
            $team,
            $module,
            'all',
            null,
            'deny',
        );

        $context = app(CoreQueryAuthorizationResolver::class)
            ->resolve(
                $user,
                $targetPermission->name,
                $module->code,
            );

        $this->assertTrue($context->operatorGlobal);
        $this->assertSame(
            [$denyScope->id],
            $context->denyScopes->pluck('id')->all(),
        );
        $this->assertTrue($context->hasBaseGrant());
        $this->assertFalse($context->hasAnyAllowPath());
    }

    public function test_resource_allow_is_exposed_without_allow_scope(): void
    {
        $user = $this->user('query-resource-allow');
        $module = $this->module('inventory');
        $targetPermission = $this->permission(
            'inventory.records.view',
            $module,
            requiresScope: true,
        );
        $role = $this->role(
            'query_resource_allow_viewer',
            $module,
            $targetPermission,
        );
        $team = $this->team('QUERY-RESOURCE-ALLOW');

        $this->membership(
            $user,
            $team,
            $role,
            $module,
        );

        $grant = CoreResourceGrant::query()->create([
            'principal_type' => 'user',
            'principal_id' => $user->id,
            'module_id' => $module->id,
            'capability_id' => $targetPermission->id,
            'resource_type' => 'record',
            'resource_id' => 15,
            'effect' => 'allow',
        ]);

        $context = app(CoreQueryAuthorizationResolver::class)
            ->resolve(
                $user,
                $targetPermission->name,
                $module->code,
            );

        $this->assertTrue($context->capabilityGranted);
        $this->assertTrue($context->requiresScope);
        $this->assertTrue($context->allowScopes->isEmpty());
        $this->assertSame(
            [$grant->id],
            $context->allowResourceGrants->pluck('id')->all(),
        );
        $this->assertTrue($context->hasAnyAllowPath());
    }

    public function test_resource_deny_is_preserved_against_operator_global(): void
    {
        $user = $this->user('query-resource-deny');
        $module = $this->module('inventory');
        $targetPermission = $this->permission(
            'inventory.records.view',
            $module,
            requiresScope: true,
        );
        $globalPermission = $this->permission(
            'inventory.operator_global',
            $module,
        );
        $operatorRole = $this->role(
            'query_resource_deny_operator',
            $module,
            $globalPermission,
        );
        $team = $this->team('QUERY-RESOURCE-DENY');

        $this->membership(
            $user,
            $team,
            $operatorRole,
            $module,
        );

        $grant = CoreResourceGrant::query()->create([
            'principal_type' => 'team',
            'principal_id' => $team->id,
            'module_id' => $module->id,
            'capability_id' => $targetPermission->id,
            'resource_type' => 'record',
            'resource_id' => 15,
            'effect' => 'deny',
        ]);

        $context = app(CoreQueryAuthorizationResolver::class)
            ->resolve(
                $user,
                $targetPermission->name,
                $module->code,
            );

        $this->assertTrue($context->operatorGlobal);
        $this->assertSame(
            [$grant->id],
            $context->denyResourceGrants->pluck('id')->all(),
        );
        $this->assertTrue($context->allowResourceGrants->isEmpty());
        $this->assertNotContains(
            $grant->id,
            $context->allowResourceGrants->pluck('id')->all(),
        );
    }

    public function test_inactive_permission_returns_denied_context(): void
    {
        $user = $this->user('query-inactive-permission');
        $module = $this->module('inventory');
        $permission = $this->permission(
            'inventory.records.view',
            $module,
            requiresScope: true,
            attributes: [
                'is_active' => false,
            ],
        );

        $context = app(CoreQueryAuthorizationResolver::class)
            ->resolve(
                $user,
                $permission->name,
                $module->code,
            );

        $this->assertDeniedProjection(
            $context,
            'Capability is not active.',
        );
    }

    public function test_disabled_module_returns_denied_context(): void
    {
        $user = $this->user('query-disabled-module');
        $module = $this->module(
            'inventory',
            attributes: [
                'is_enabled' => false,
            ],
        );
        $permission = $this->permission(
            'inventory.records.view',
            $module,
            requiresScope: true,
        );

        $context = app(CoreQueryAuthorizationResolver::class)
            ->resolve(
                $user,
                $permission->name,
                $module->code,
            );

        $this->assertDeniedProjection(
            $context,
            'Module is not enabled.',
        );
    }

    public function test_repeated_resolve_does_not_query_resource_grants_again(): void
    {
        $user = $this->user('query-cache');
        $module = $this->module('inventory');
        $targetPermission = $this->permission(
            'inventory.records.view',
            $module,
            requiresScope: true,
        );
        $role = $this->role(
            'query_cache_viewer',
            $module,
            $targetPermission,
        );
        $team = $this->team('QUERY-CACHE');

        $this->membership(
            $user,
            $team,
            $role,
            $module,
        );

        $grant = CoreResourceGrant::query()->create([
            'principal_type' => 'user',
            'principal_id' => $user->id,
            'module_id' => $module->id,
            'capability_id' => $targetPermission->id,
            'resource_type' => 'record',
            'resource_code' => 'record-15',
            'effect' => 'allow',
        ]);

        $resolver = app(CoreQueryAuthorizationResolver::class);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $first = $resolver->resolve(
            $user,
            $targetPermission->name,
            $module->code,
        );
        $queryCountAfterFirstResolve = $this->resourceGrantQueryCount();

        $second = $resolver->resolve(
            $user,
            $targetPermission->name,
            $module->code,
        );
        $queryCountAfterSecondResolve = $this->resourceGrantQueryCount();

        DB::disableQueryLog();

        $this->assertGreaterThan(
            0,
            $queryCountAfterFirstResolve,
        );
        $this->assertSame(
            $queryCountAfterFirstResolve,
            $queryCountAfterSecondResolve,
        );
        $this->assertSame(
            [$grant->id],
            $first->allowResourceGrants->pluck('id')->all(),
        );
        $this->assertSame(
            [$grant->id],
            $second->allowResourceGrants->pluck('id')->all(),
        );
    }

    public function test_inactive_user_returns_denied_context(): void
    {
        $user = $this->user(
            'query-inactive-user',
            active: false,
        );

        $context = app(CoreQueryAuthorizationResolver::class)
            ->resolve(
                $user,
                'inventory.records.view',
                'inventory',
            );

        $this->assertDeniedProjection(
            $context,
            'User is not active.',
        );
    }

    public function test_undefined_capability_returns_denied_context(): void
    {
        $user = $this->user('query-missing-capability');

        $context = app(CoreQueryAuthorizationResolver::class)
            ->resolve(
                $user,
                'inventory.records.view',
                'inventory',
            );

        $this->assertDeniedProjection(
            $context,
            'Capability is not defined.',
        );
    }

    public function test_undefined_module_returns_denied_context(): void
    {
        $user = $this->user('query-missing-module');
        $module = $this->module('inventory');
        $permission = $this->permission(
            'inventory.records.view',
            $module,
            requiresScope: true,
        );

        $context = app(CoreQueryAuthorizationResolver::class)
            ->resolve(
                $user,
                $permission->name,
                'missing-module',
            );

        $this->assertDeniedProjection(
            $context,
            'Module is not defined.',
        );
    }

    public function test_permission_belonging_to_another_module_returns_denied_context(): void
    {
        $user = $this->user('query-module-mismatch');
        $inventoryModule = $this->module(
            'inventory',
            requiresScope: false,
        );
        $billingModule = $this->module(
            'billing',
            requiresScope: false,
        );
        $permission = $this->permission(
            'inventory.records.view',
            $billingModule,
        );
        $role = $this->role(
            'query_module_mismatch_role',
            $inventoryModule,
            $permission,
        );
        $team = $this->team('QUERY-MODULE-MISMATCH');

        $this->membership(
            $user,
            $team,
            $role,
            $inventoryModule,
        );

        $context = app(CoreQueryAuthorizationResolver::class)
            ->resolve(
                $user,
                $permission->name,
                $inventoryModule->code,
            );

        $this->assertDeniedProjection(
            $context,
            'Capability does not belong to the resolved module.',
        );
    }

    public function test_resource_allow_for_unrelated_principal_is_excluded(): void
    {
        $user = $this->user('query-unrelated-grant');
        $otherUser = $this->user('query-unrelated-other');
        $module = $this->module('inventory');
        $targetPermission = $this->permission(
            'inventory.records.view',
            $module,
            requiresScope: true,
        );
        $role = $this->role(
            'query_unrelated_grant_viewer',
            $module,
            $targetPermission,
        );
        $team = $this->team('QUERY-UNRELATED-GRANT');

        $this->membership(
            $user,
            $team,
            $role,
            $module,
        );

        $grant = CoreResourceGrant::query()->create([
            'principal_type' => 'user',
            'principal_id' => $otherUser->id,
            'module_id' => $module->id,
            'capability_id' => $targetPermission->id,
            'resource_type' => 'record',
            'resource_id' => 15,
            'effect' => 'allow',
        ]);

        $context = app(CoreQueryAuthorizationResolver::class)
            ->resolve(
                $user,
                $targetPermission->name,
                $module->code,
            );

        $this->assertTrue($context->capabilityGranted);
        $this->assertTrue($context->allowResourceGrants->isEmpty());
        $this->assertNotContains(
            $grant->id,
            $context->allowResourceGrants->pluck('id')->all(),
        );
        $this->assertFalse($context->hasAnyAllowPath());
    }

    public function test_resource_allow_without_assignment_does_not_create_base_grant(): void
    {
        $user = $this->user('query-no-base-grant');
        $module = $this->module('inventory');
        $permission = $this->permission(
            'inventory.records.view',
            $module,
            requiresScope: true,
        );

        CoreResourceGrant::query()->create([
            'principal_type' => 'user',
            'principal_id' => $user->id,
            'module_id' => $module->id,
            'capability_id' => $permission->id,
            'resource_type' => 'record',
            'resource_id' => 15,
            'effect' => 'allow',
        ]);

        $context = app(CoreQueryAuthorizationResolver::class)
            ->resolve(
                $user,
                $permission->name,
                $module->code,
            );

        $this->assertDeniedProjection(
            $context,
            'No active membership role grants this capability.',
        );
    }

    public function test_scoped_capability_without_allow_scope_or_resource_allow_has_no_allow_path(): void
    {
        [$user, $module, $permission] = $this->scopedCapabilityFixture(
            'no-allow-path',
        );

        $context = app(CoreQueryAuthorizationResolver::class)
            ->resolve(
                $user,
                $permission->name,
                $module->code,
            );

        $this->assertTrue($context->hasBaseGrant());
        $this->assertTrue($context->capabilityGranted);
        $this->assertTrue($context->allowScopes->isEmpty());
        $this->assertTrue($context->allowResourceGrants->isEmpty());
        $this->assertFalse($context->hasAnyAllowPath());
    }

    public function test_matching_allow_and_deny_scopes_are_both_retained(): void
    {
        [$user, $module, $permission, $team] = $this
            ->scopedCapabilityFixture('matching-scope-effects');

        $allowScope = $this->scope(
            $team,
            $module,
            'region',
            10,
        );

        $denyScope = $this->scope(
            $team,
            $module,
            'region',
            10,
            'deny',
        );

        $context = app(CoreQueryAuthorizationResolver::class)
            ->resolve(
                $user,
                $permission->name,
                $module->code,
            );

        $this->assertSame(
            [$allowScope->id],
            $context->allowScopes->pluck('id')->all(),
        );
        $this->assertSame(
            [$denyScope->id],
            $context->denyScopes->pluck('id')->all(),
        );
        $this->assertTrue($context->hasAnyAllowPath());
    }

    public function test_malformed_scopes_are_excluded_from_query_authorization(): void
    {
        [$user, $module, $permission, $team] = $this
            ->scopedCapabilityFixture('malformed-scopes');

        $malformedScopes = collect([
            [
                'scope_type' => 'region',
                'scope_id' => ' ',
                'scope_code' => "\t",
            ],
            [
                'scope_type' => 'asset_category',
                'scope_code' => ' ',
                'asset_category' => "\t",
            ],
            [
                'scope_type' => 'asset_type',
                'scope_code' => "\r\n",
                'asset_type' => ' ',
            ],
            [
                'scope_type' => ' ',
                'scope_id' => 10,
            ],
        ])->map(
            fn (array $attributes): CoreTeamScope => CoreTeamScope::query()
                ->create(array_merge([
                    'team_id' => $team->id,
                    'module_id' => $module->id,
                    'effect' => 'allow',
                ], $attributes))
        );

        $rawScopes = app(TeamScopeResolver::class)
            ->activeScopesForTeams(
                [$team->id],
                $module->code,
            );

        $context = app(CoreQueryAuthorizationResolver::class)
            ->resolve(
                $user,
                $permission->name,
                $module->code,
            );

        $this->assertEqualsCanonicalizing(
            $malformedScopes->pluck('id')->all(),
            $rawScopes->pluck('id')->all(),
        );
        $this->assertTrue($context->allowScopes->isEmpty());
        $this->assertFalse($context->hasAnyAllowPath());
    }

    public function test_zero_scope_identifier_remains_a_valid_allow_path(): void
    {
        [$user, $module, $permission, $team] = $this
            ->scopedCapabilityFixture('zero-scope');

        $scope = $this->scope(
            $team,
            $module,
            'region',
            0,
        );

        $context = app(CoreQueryAuthorizationResolver::class)
            ->resolve(
                $user,
                $permission->name,
                $module->code,
            );

        $this->assertSame(
            [$scope->id],
            $context->allowScopes->pluck('id')->all(),
        );
        $this->assertTrue($context->hasAnyAllowPath());
    }

    public function test_explicit_all_scope_remains_an_intentional_allow_wildcard(): void
    {
        [$user, $module, $permission, $team] = $this
            ->scopedCapabilityFixture('all-scope');

        $scope = $this->scope(
            $team,
            $module,
            'all',
            null,
        );

        $context = app(CoreQueryAuthorizationResolver::class)
            ->resolve(
                $user,
                $permission->name,
                $module->code,
            );

        $this->assertSame(
            [$scope->id],
            $context->allowScopes->pluck('id')->all(),
        );
        $this->assertTrue($context->hasAnyAllowPath());
    }

    public function test_narrowed_all_deny_does_not_block_every_query_row(): void
    {
        $user = $this->user('query-narrowed-all-deny');
        $module = $this->module('inventory');
        $permission = $this->permission(
            'inventory.records.view',
            $module,
            requiresScope: true,
        );
        $operatorPermission = $this->permission(
            'inventory.operator_global',
            $module,
        );
        $role = $this->role(
            'query_narrowed_all_operator',
            $module,
            $operatorPermission,
        );
        $team = $this->team('QUERY-NARROWED-ALL');

        $this->membership(
            $user,
            $team,
            $role,
            $module,
        );

        $denyScope = $this->scope(
            $team,
            $module,
            'all',
            null,
            'deny',
        );
        $denyScope->update([
            'asset_category' => 'network',
        ]);

        $context = app(CoreQueryAuthorizationResolver::class)
            ->resolve(
                $user,
                $permission->name,
                $module->code,
            );

        $this->assertTrue($context->operatorGlobal);
        $this->assertSame(
            [$denyScope->id],
            $context->denyScopes->pluck('id')->all(),
        );
        $this->assertTrue($context->hasAnyAllowPath());
    }

    public function test_denied_context_creates_empty_projections(): void
    {
        $context = QueryAuthorizationContext::denied(
            'inventory',
            'inventory.records.view',
            'Denied for test.',
        );

        $this->assertDeniedProjection(
            $context,
            'Denied for test.',
        );
    }

    private function assertDeniedProjection(
        QueryAuthorizationContext $context,
        string $reason,
    ): void {
        $this->assertFalse($context->hasBaseGrant());
        $this->assertFalse($context->hasAnyAllowPath());
        $this->assertFalse($context->capabilityGranted);
        $this->assertFalse($context->operatorGlobal);
        $this->assertTrue($context->allowScopes->isEmpty());
        $this->assertTrue($context->denyScopes->isEmpty());
        $this->assertTrue($context->allowResourceGrants->isEmpty());
        $this->assertTrue($context->denyResourceGrants->isEmpty());
        $this->assertSame(
            $reason,
            $context->denialReason,
        );
    }

    /**
     * @return array{0: mixed, 1: mixed, 2: mixed, 3: mixed}
     */
    private function scopedCapabilityFixture(string $suffix): array
    {
        $user = $this->user("query-{$suffix}");
        $module = $this->module('inventory');
        $permission = $this->permission(
            'inventory.records.view',
            $module,
            requiresScope: true,
        );
        $role = $this->role(
            "query_{$suffix}_viewer",
            $module,
            $permission,
        );
        $team = $this->team(
            'QUERY-'.str($suffix)->upper()->replace('_', '-'),
        );

        $this->membership(
            $user,
            $team,
            $role,
            $module,
        );

        return [
            $user,
            $module,
            $permission,
            $team,
        ];
    }

    private function resourceGrantQueryCount(): int
    {
        return collect(DB::getQueryLog())
            ->filter(
                fn (array $query): bool => str_contains(
                    $query['query'],
                    'core_resource_grants',
                )
            )
            ->count();
    }
}
