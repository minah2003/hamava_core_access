<?php

namespace Hamava\CoreAccess\Tests\Unit;

use Hamava\CoreAccess\Services\CoreAccessContext;
use Hamava\CoreAccess\Services\CoreNavigationResolver;
use Hamava\CoreAccess\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

class CoreNavigationResolverTest extends TestCase
{
    public function test_navigation_uses_permission_name_prefix_when_node_has_no_attached_permission(): void
    {
        $user = $this->user('prefix-navigation');

        $module = $this->module(
            'inventory',
            requiresScope: false,
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

        /*
         * Deliberately do not attach this permission to the node.
         */
        $permission = $this->permission(
            'inventory.records.view',
            $module,
        );

        $role = $this->role(
            'prefix_viewer',
            $module,
            $permission,
        );

        $team = $this->team('PREFIX-NAV');

        $this->membership(
            $user,
            $team,
            $role,
            $module,
        );

        $flat = $this->flattenCodes(
            app(CoreNavigationResolver::class)
                ->forUser($user, $module->code)
        );

        $this->assertContains($root->code, $flat);
        $this->assertContains($records->code, $flat);
    }

    public function test_module_node_uses_configured_global_module_permission(): void
    {
        $user = $this->user('module-navigation');

        $module = $this->module(
            'inventory',
            requiresScope: false,
        );

        $root = $this->node(
            $module,
            'inventory',
            type: 'module',
        );

        $permission = $this->permission(
            'inventory.module.view',
            $module,
            attributes: [
                'module_id' => null,
            ],
        );

        $role = $this->role(
            'module_viewer',
            $module,
            $permission,
        );

        $team = $this->team('MODULE-NAV');

        $this->membership(
            $user,
            $team,
            $role,
            $module,
        );

        $flat = $this->flattenCodes(
            app(CoreNavigationResolver::class)
                ->forUser($user, $module->code)
        );

        $this->assertContains(
            $root->code,
            $flat,
        );
    }

    public function test_navigation_query_count_does_not_grow_linearly_with_node_count(): void
    {
        [
            $smallUser,
            $smallModuleCode,
            $smallExpectedNodeCount,
        ] = $this->navigationFixture(
            'navsmall',
            5,
        );

        [
            $largeUser,
            $largeModuleCode,
            $largeExpectedNodeCount,
        ] = $this->navigationFixture(
            'navlarge',
            50,
        );

        $resolver = app(CoreNavigationResolver::class);

        $smallQueryCount = $this->navigationQueryCount(
            $resolver,
            $smallUser,
            $smallModuleCode,
            $smallExpectedNodeCount,
        );

        /*
         * Give the large measurement a fresh request-local snapshot.
         */
        app(CoreAccessContext::class)->flush();

        $largeQueryCount = $this->navigationQueryCount(
            $resolver,
            $largeUser,
            $largeModuleCode,
            $largeExpectedNodeCount,
        );

        $this->assertLessThanOrEqual(
            $smallQueryCount + 2,
            $largeQueryCount,
            sprintf(
                'Navigation queries grew with node count: small=%d, large=%d.',
                $smallQueryCount,
                $largeQueryCount,
            ),
        );

        $this->assertLessThanOrEqual(
            25,
            $largeQueryCount,
            sprintf(
                'Navigation used too many queries for 50 nodes: %d.',
                $largeQueryCount,
            ),
        );
    }

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

    /**
     * @return array{0: Authenticatable, 1: string, 2: int}
     */
    private function navigationFixture(
        string $moduleCode,
        int $childCount,
    ): array {
        $user = $this->user(
            "{$moduleCode}-user",
        );

        $module = $this->module(
            $moduleCode,
            requiresScope: false,
        );

        $root = $this->node(
            $module,
            $moduleCode,
            type: 'module',
        );

        $permissions = [];

        for ($index = 1; $index <= $childCount; $index++) {
            $node = $this->node(
                $module,
                "{$moduleCode}.page-{$index}",
                $root,
            );

            $permissions[] = $this->permission(
                "{$node->code}.view",
                $module,
                node: $node,
            );
        }

        $role = $this->role(
            "{$moduleCode}_viewer",
            $module,
            ...$permissions,
        );

        $team = $this->team(
            strtoupper($moduleCode).'-TEAM',
        );

        $this->membership(
            $user,
            $team,
            $role,
            $module,
        );

        return [
            $user,
            $module->code,
            $childCount + 1,
        ];
    }

    private function navigationQueryCount(
        CoreNavigationResolver $resolver,
        Authenticatable $user,
        string $moduleCode,
        int $expectedNodeCount,
    ): int {
        DB::disableQueryLog();
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $navigation = $resolver->forUser(
                $user,
                $moduleCode,
            );

            $queryCount = count(
                DB::getQueryLog(),
            );
        } finally {
            DB::disableQueryLog();
        }

        $this->assertCount(
            $expectedNodeCount,
            $this->flattenCodes($navigation),
        );

        return $queryCount;
    }

    private function flattenCodes($items): array
    {
        return collect($items)
            ->flatMap(fn (array $item) => array_merge([$item['code']], $this->flattenCodes($item['children'] ?? [])))
            ->values()
            ->all();
    }
}
