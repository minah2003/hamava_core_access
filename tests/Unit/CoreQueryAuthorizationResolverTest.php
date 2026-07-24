<?php

namespace Hamava\CoreAccess\Tests\Unit;

use Hamava\CoreAccess\Data\QueryAuthorizationContext;
use Hamava\CoreAccess\Models\CoreResourceGrant;
use Hamava\CoreAccess\Services\CoreQueryAuthorizationResolver;
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
