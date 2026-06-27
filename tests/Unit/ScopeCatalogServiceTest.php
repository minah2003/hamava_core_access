<?php

namespace Hamava\CoreAccess\Tests\Unit;

use Hamava\CoreAccess\Models\CoreAccessNodeScopeRule;
use Hamava\CoreAccess\Models\CoreScopeEntityProvider;
use Hamava\CoreAccess\Services\ScopeCatalogService;
use Hamava\CoreAccess\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
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

    public function test_scope_entity_provider_query_supports_with_trashed(): void
    {
        $this->createScopeEntityProvidersTable();

        $provider = CoreScopeEntityProvider::query()->create([
            'module_code' => 'inventory',
            'scope_type' => 'region',
            'provider_type' => 'table',
            'is_active' => true,
        ]);

        $provider->delete();

        $this->assertSame(0, CoreScopeEntityProvider::query()->count());
        $this->assertSame(1, CoreScopeEntityProvider::query()->withTrashed()->count());
        $this->assertTrue(CoreScopeEntityProvider::query()->withTrashed()->first()->trashed());
    }

    public function test_access_node_scope_rule_query_supports_with_trashed(): void
    {
        $this->createAccessNodeScopeRulesTable();
        $module = $this->module('inventory');
        $node = $this->node($module, 'inventory.records');

        $rule = CoreAccessNodeScopeRule::query()->create([
            'access_node_id' => $node->id,
            'scope_type' => 'region',
            'requires_entity' => true,
            'allow_include_children' => true,
            'is_active' => true,
        ]);

        $rule->delete();

        $this->assertSame(0, CoreAccessNodeScopeRule::query()->count());
        $this->assertSame(1, CoreAccessNodeScopeRule::query()->withTrashed()->count());
        $this->assertTrue(CoreAccessNodeScopeRule::query()->withTrashed()->first()->trashed());
    }

    private function createScopeEntityProvidersTable(): void
    {
        Schema::create('core_scope_entity_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('module_code');
            $table->string('scope_type');
            $table->string('provider_type')->default('table');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function createAccessNodeScopeRulesTable(): void
    {
        Schema::create('core_access_node_scope_rules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('access_node_id');
            $table->string('scope_type');
            $table->boolean('requires_entity')->default(false);
            $table->boolean('allow_include_children')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }
}
