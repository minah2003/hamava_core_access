<?php

namespace Hamava\CoreAccess\Tests\Unit;

use Hamava\CoreAccess\Data\ResourceDescriptor;
use Hamava\CoreAccess\Models\CoreResourceGrant;
use Hamava\CoreAccess\Services\CoreAccessResolver;
use Hamava\CoreAccess\Tests\TestCase;

class CoreAccessResolverTest extends TestCase
{
    public function test_scoped_role_allows_matching_resource_and_denies_non_matching_resource(): void
    {
        [$user] = $this->createScopedAccess();

        $allowed = app(CoreAccessResolver::class)->check(
            $user,
            'inventory.records.edit',
            ResourceDescriptor::make('inventory', 'record', 15, null, ['region_id' => 10]),
        );

        $denied = app(CoreAccessResolver::class)->check(
            $user,
            'inventory.records.edit',
            ResourceDescriptor::make('inventory', 'record', 15, null, ['region_id' => 99]),
        );

        $this->assertTrue($allowed->allowed);
        $this->assertSame('Allowed by team scope and role capability.', $allowed->reason);
        $this->assertFalse($denied->allowed);
        $this->assertSame('No matching scope for resource.', $denied->reason);
    }

    public function test_explicit_resource_deny_overrides_matching_scope(): void
    {
        [$user, $module, $permission] = $this->createScopedAccess();

        CoreResourceGrant::query()->create([
            'principal_type' => 'user',
            'principal_id' => $user->id,
            'module_id' => $module->id,
            'capability_id' => $permission->id,
            'resource_type' => 'record',
            'resource_id' => 15,
            'effect' => 'deny',
        ]);

        $decision = app(CoreAccessResolver::class)->check(
            $user,
            'inventory.records.edit',
            ResourceDescriptor::make('inventory', 'record', 15, null, ['region_id' => 10]),
        );

        $this->assertFalse($decision->allowed);
        $this->assertSame('Denied by explicit resource grant.', $decision->reason);
    }

    public function test_operator_global_permission_bypasses_resource_scope(): void
    {
        $user = $this->user('global');
        $module = $this->module('inventory');
        $edit = $this->permission('inventory.records.edit', $module, true);
        $global = $this->permission('inventory.operator_global', $module);
        $role = $this->role('inventory_operator', $module, $global);
        $team = $this->team('GLOBAL');

        $this->membership($user, $team, $role, $module);

        $decision = app(CoreAccessResolver::class)->check(
            $user,
            $edit->name,
            ResourceDescriptor::make('inventory', 'record', 15, null, ['region_id' => 999]),
        );

        $this->assertTrue($decision->allowed);
        $this->assertSame('Allowed by operator-global capability.', $decision->reason);
    }

    private function createScopedAccess(): array
    {
        $user = $this->user('scoped');
        $module = $this->module('inventory');
        $permission = $this->permission('inventory.records.edit', $module, true);
        $role = $this->role('inventory_editor', $module, $permission);
        $team = $this->team('REGION-OPS');

        $this->membership($user, $team, $role, $module);
        $this->scope($team, $module, 'region', 10);

        return [$user, $module, $permission, $role, $team];
    }
}
