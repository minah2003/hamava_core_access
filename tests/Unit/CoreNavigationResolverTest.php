<?php

namespace Hamava\CoreAccess\Tests\Unit;

use Hamava\CoreAccess\Services\CoreNavigationResolver;
use Hamava\CoreAccess\Tests\TestCase;

class CoreNavigationResolverTest extends TestCase
{
    public function test_navigation_includes_accessible_nodes_and_their_parents(): void
    {
        $user = $this->user('navigator');
        $module = $this->module('inventory', requiresScope: false);
        $root = $this->node($module, 'inventory', type: 'module');
        $records = $this->node($module, 'inventory.records', $root);
        $reports = $this->node($module, 'inventory.reports', $root);

        $permission = $this->permission('inventory.records.view', $module, node: $records);
        $role = $this->role('inventory_viewer', $module, $permission);
        $team = $this->team('NAV');

        $this->membership($user, $team, $role, $module);

        $flat = $this->flattenCodes(app(CoreNavigationResolver::class)->forUser($user, 'inventory'));

        $this->assertContains($root->code, $flat);
        $this->assertContains($records->code, $flat);
        $this->assertNotContains($reports->code, $flat);
    }

    public function test_navigation_excludes_node_with_inactive_permission(): void
    {
        $user = $this->user('inactive-nav');
        $module = $this->module(
            'inventory',
            requiresScope: false,
        );
        $root = $this->node($module, 'inventory', type: 'module');
        $records = $this->node(
            $module,
            'inventory.records',
            $root,
        );

        $permission = $this->permission(
            'inventory.records.view',
            $module,
            node: $records,
            attributes: ['is_active' => false],
        );

        $role = $this->role('viewer', $module, $permission);
        $team = $this->team();

        $this->membership($user, $team, $role, $module);

        $flat = $this->flattenCodes(
            app(CoreNavigationResolver::class)
                ->forUser($user, 'inventory')
        );

        $this->assertNotContains($records->code, $flat);
    }

    public function test_navigation_shows_only_nodes_with_matching_node_specific_scope(): void
    {
        $user = $this->user('scoped-navigator');
        $module = $this->module('inventory');
        $root = $this->node($module, 'inventory', type: 'module');
        $records = $this->node($module, 'inventory.records', $root);
        $assets = $this->node($module, 'inventory.assets', $root);

        $recordsPermission = $this->permission('inventory.records.view', $module, true, $records);
        $assetsPermission = $this->permission('inventory.assets.view', $module, true, $assets);
        $role = $this->role('inventory_scoped_viewer', $module, $recordsPermission, $assetsPermission);
        $team = $this->team('SCOPED-NAV');

        $this->membership($user, $team, $role, $module);
        $this->scope($team, $module, 'region', 10, 'allow', $records);

        $flat = $this->flattenCodes(app(CoreNavigationResolver::class)->forUser($user, 'inventory'));

        $this->assertContains($root->code, $flat);
        $this->assertContains($records->code, $flat);
        $this->assertNotContains($assets->code, $flat);
    }

    public function test_navigation_is_empty_for_disabled_module(): void
    {
        $user = $this->user('disabled-navigation');

        $module = $this->module(
            'inventory',
            requiresScope: false,
            attributes: [
                'is_enabled' => false,
            ],
        );

        $root = $this->node(
            $module,
            'inventory',
            type: 'module',
        );

        $records = $this->node(
            $module,
            'inventory.records',
            $root,
        );

        $permission = $this->permission(
            'inventory.records.view',
            $module,
            node: $records,
        );

        $role = $this->role(
            'viewer',
            $module,
            $permission,
        );

        $team = $this->team();

        $this->membership(
            $user,
            $team,
            $role,
            $module,
        );

        $navigation = app(CoreNavigationResolver::class)
            ->forUser($user, 'inventory');

        $this->assertSame([], $navigation->all());
    }

    private function flattenCodes($items): array
    {
        return collect($items)
            ->flatMap(fn (array $item) => array_merge([$item['code']], $this->flattenCodes($item['children'] ?? [])))
            ->values()
            ->all();
    }
}
