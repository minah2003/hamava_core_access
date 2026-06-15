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

    private function flattenCodes($items): array
    {
        return collect($items)
            ->flatMap(fn (array $item) => array_merge([$item['code']], $this->flattenCodes($item['children'] ?? [])))
            ->values()
            ->all();
    }
}
