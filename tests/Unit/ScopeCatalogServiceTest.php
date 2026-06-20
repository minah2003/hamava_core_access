<?php

namespace Hamava\CoreAccess\Tests\Unit;

use Hamava\CoreAccess\Services\ScopeCatalogService;
use Hamava\CoreAccess\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

class ScopeCatalogServiceTest extends TestCase
{
    public function test_scope_catalog_returns_empty_definition_when_new_tables_do_not_exist(): void
    {
        $module = $this->module('inventory');
        $node = $this->node($module, 'inventory.records');
        $service = app(ScopeCatalogService::class);

        $this->assertFalse(Schema::hasTable('core_access_node_scope_rules'));
        $this->assertFalse(Schema::hasTable('core_scope_entity_providers'));
        $this->assertCount(0, $service->scopeRulesForAccessNode($node));
        $this->assertNull($service->entityProvider($module->code, 'region'));

        $this->assertSame([
            'module_code' => 'inventory',
            'access_node_id' => $node->id,
            'access_node_code' => 'inventory.records',
            'scope_types' => [],
            'entity_required' => false,
            'allow_include_children' => false,
            'asset_category_mode' => 'hidden',
            'forced_asset_category' => null,
            'asset_type_mode' => 'hidden',
            'rules' => [],
        ], $service->definitionForAccessNode($module->code, $node));
    }
}
