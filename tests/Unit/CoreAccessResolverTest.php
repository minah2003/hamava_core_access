<?php

namespace Hamava\CoreAccess\Tests\Unit;

use Hamava\CoreAccess\Data\ResourceDescriptor;
use Hamava\CoreAccess\Models\CoreResourceGrant;
use Hamava\CoreAccess\Services\CoreAccessResolver;
use Hamava\CoreAccess\Tests\TestCase;

class CoreAccessResolverTest extends TestCase
{
    public function test_team_level_role_grants_capability_to_all_active_team_members(): void
    {
        $first = $this->user('team-role-first');
        $second = $this->user('team-role-second');
        $module = $this->module('inventory');
        $permission = $this->permission('inventory.records.view', $module);
        $role = $this->role('inventory_viewer', $module, $permission);
        $team = $this->team('TEAM-ROLE');

        $this->teamMembership($first, $team);
        $this->teamMembership($second, $team);
        $this->teamRole($team, $role, $module);

        $resolver = app(CoreAccessResolver::class);

        $this->assertTrue($resolver->can($first, $permission->name));
        $this->assertTrue($resolver->can($second, $permission->name));
    }

    public function test_team_level_role_respects_module_assignment(): void
    {
        $user = $this->user('team-role-module');
        $inventory = $this->module('inventory');
        $billing = $this->module('billing');
        $inventoryPermission = $this->permission('inventory.records.view', $inventory);
        $billingPermission = $this->permission('billing.invoices.view', $billing);
        $role = $this->role('cross_module_viewer', $inventory, $inventoryPermission, $billingPermission);
        $team = $this->team('MODULE-ROLE');

        $this->teamMembership($user, $team);
        $this->teamRole($team, $role, $inventory);

        $resolver = app(CoreAccessResolver::class);

        $this->assertTrue($resolver->can($user, $inventoryPermission->name));
        $this->assertFalse($resolver->can($user, $billingPermission->name));
    }

    public function test_team_level_role_respects_validity_window(): void
    {
        $user = $this->user('team-role-dates');
        $module = $this->module('inventory');
        $permission = $this->permission('inventory.records.view', $module);
        $role = $this->role('date_viewer', $module, $permission);
        $team = $this->team('DATE-ROLE');

        $this->teamMembership($user, $team);
        $this->teamRole($team, $role, $module, ['valid_from' => today()->addDay()]);
        $this->teamRole($team, $role, $module, ['valid_to' => today()->subDay()]);

        $resolver = app(CoreAccessResolver::class);

        $this->assertFalse($resolver->can($user, $permission->name));

        $this->teamRole($team, $role, $module, [
            'valid_from' => today()->subDay(),
            'valid_to' => today()->addDay(),
        ]);

        $this->assertTrue($resolver->can($user, $permission->name));
    }

    public function test_member_specific_role_still_grants_capability(): void
    {
        $user = $this->user('member-role');
        $module = $this->module('inventory');
        $permission = $this->permission('inventory.records.view', $module);
        $role = $this->role('member_viewer', $module, $permission);
        $team = $this->team('MEMBER-ROLE');

        $this->membership($user, $team, $role, $module);

        $decision = app(CoreAccessResolver::class)->check($user, $permission->name);

        $this->assertTrue($decision->allowed);
        $this->assertNotEmpty($decision->matched['member_role_assignment_ids']);
    }

    public function test_effective_capabilities_are_union_of_team_and_member_roles(): void
    {
        $user = $this->user('union-role');
        $module = $this->module('inventory');
        $view = $this->permission('inventory.records.view', $module);
        $edit = $this->permission('inventory.records.edit', $module);
        $teamRole = $this->role('team_viewer', $module, $view);
        $memberRole = $this->role('member_editor', $module, $edit);
        $team = $this->team('UNION-ROLE');

        $this->membership($user, $team, $memberRole, $module);
        $this->teamRole($team, $teamRole, $module);

        $this->assertSame(
            ['inventory.records.edit', 'inventory.records.view'],
            app(CoreAccessResolver::class)->capabilities($user, 'inventory')->all(),
        );
    }

    public function test_operator_global_permission_can_be_granted_through_team_role(): void
    {
        $user = $this->user('team-global');
        $module = $this->module('inventory');
        $edit = $this->permission('inventory.records.edit', $module, true);
        $global = $this->permission('inventory.operator_global', $module);
        $role = $this->role('team_operator', $module, $global);
        $team = $this->team('TEAM-GLOBAL');

        $this->teamMembership($user, $team);
        $this->teamRole($team, $role, $module);

        $decision = app(CoreAccessResolver::class)->check(
            $user,
            $edit->name,
            ResourceDescriptor::make('inventory', 'record', 15, null, ['region_id' => 999]),
        );

        $this->assertTrue($decision->allowed);
        $this->assertSame('Allowed by operator-global capability.', $decision->reason);
        $this->assertNotEmpty($decision->matched['team_role_ids']);
    }

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

    public function test_deny_scope_with_matching_access_node_overrides_allow_scope(): void
    {
        $user = $this->user('node-deny');
        $module = $this->module('inventory');
        $node = $this->node($module, 'inventory.records.edit');
        $permission = $this->permission('inventory.records.edit', $module, true, $node);
        $role = $this->role('inventory_editor', $module, $permission);
        $team = $this->team('NODE-DENY');

        $this->membership($user, $team, $role, $module);
        $this->scope($team, $module, 'region', 10);
        $this->scope($team, $module, 'region', 10, 'deny', $node);

        $decision = app(CoreAccessResolver::class)->check(
            $user,
            $permission->name,
            ResourceDescriptor::make('inventory', 'record', 15, null, ['region_id' => 10]),
        );

        $this->assertFalse($decision->allowed);
        $this->assertSame('Denied by team scope.', $decision->reason);
    }

    public function test_deny_scope_with_different_access_node_does_not_block_another_node(): void
    {
        $user = $this->user('node-allow');
        $module = $this->module('inventory');
        $recordsNode = $this->node($module, 'inventory.records.edit');
        $assetsNode = $this->node($module, 'inventory.assets.edit');
        $permission = $this->permission('inventory.assets.edit', $module, true, $assetsNode);
        $role = $this->role('inventory_asset_editor', $module, $permission);
        $team = $this->team('NODE-ALLOW');

        $this->membership($user, $team, $role, $module);
        $this->scope($team, $module, 'region', 10);
        $this->scope($team, $module, 'region', 10, 'deny', $recordsNode);

        $decision = app(CoreAccessResolver::class)->check(
            $user,
            $permission->name,
            ResourceDescriptor::make('inventory', 'record', 15, null, ['region_id' => 10]),
        );

        $this->assertTrue($decision->allowed);
        $this->assertSame('Allowed by team scope and role capability.', $decision->reason);
    }

    public function test_can_enter_access_node_allows_scoped_page_with_same_node_scope(): void
    {
        [$user, $module, $permission, $node, , $team] = $this->createScopedPageAccess('same-node');

        $this->scope($team, $module, 'region', 10, 'allow', $node);

        $decision = app(CoreAccessResolver::class)->canEnterAccessNode($user, $permission->name);

        $this->assertTrue($decision->allowed);
        $this->assertSame('Allowed by team scope and role capability.', $decision->reason);
    }

    public function test_can_enter_access_node_denies_scoped_page_with_another_node_scope(): void
    {
        [$user, $module, $permission, , , $team] = $this->createScopedPageAccess('other-node');
        $otherNode = $this->node($module, 'inventory.assets');

        $this->scope($team, $module, 'region', 10, 'allow', $otherNode);

        $decision = app(CoreAccessResolver::class)->canEnterAccessNode($user, $permission->name);

        $this->assertFalse($decision->allowed);
        $this->assertSame('No matching scope for access node.', $decision->reason);
    }

    public function test_can_enter_access_node_allows_scoped_page_with_module_wide_scope(): void
    {
        [$user, $module, $permission, , , $team] = $this->createScopedPageAccess('module-wide');

        $this->scope($team, $module, 'region', 10);

        $decision = app(CoreAccessResolver::class)->canEnterAccessNode($user, $permission->name);

        $this->assertTrue($decision->allowed);
        $this->assertSame('Allowed by team scope and role capability.', $decision->reason);
    }

    public function test_can_enter_access_node_deny_all_scope_for_same_node_blocks_page_entry(): void
    {
        [$user, $module, $permission, $node, , $team] = $this->createScopedPageAccess('deny-all');

        $this->scope($team, $module, 'region', 10, 'allow', $node);
        $this->scope($team, $module, 'all', null, 'deny', $node);

        $decision = app(CoreAccessResolver::class)->canEnterAccessNode($user, $permission->name);

        $this->assertFalse($decision->allowed);
        $this->assertSame('Denied by team scope.', $decision->reason);
    }

    public function test_can_enter_access_node_entity_deny_scope_does_not_block_page_entry(): void
    {
        [$user, $module, $permission, $node, , $team] = $this->createScopedPageAccess('entity-deny');

        $this->scope($team, $module, 'region', 10, 'allow', $node);
        $this->scope($team, $module, 'region', 10, 'deny', $node);

        $decision = app(CoreAccessResolver::class)->canEnterAccessNode($user, $permission->name);

        $this->assertTrue($decision->allowed);
        $this->assertSame('Allowed by team scope and role capability.', $decision->reason);
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

    private function createScopedPageAccess(string $suffix): array
    {
        $user = $this->user("page-{$suffix}");
        $module = $this->module('inventory');
        $node = $this->node($module, "inventory.records.{$suffix}");
        $permission = $this->permission("inventory.records.{$suffix}.view", $module, true, $node);
        $role = $this->role("page_{$suffix}_viewer", $module, $permission);
        $team = $this->team("PAGE-{$suffix}");

        $this->membership($user, $team, $role, $module);

        return [$user, $module, $permission, $node, $role, $team];
    }
}
