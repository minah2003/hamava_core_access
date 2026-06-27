<?php

namespace Hamava\CoreAccess\Tests\Unit;

use Hamava\CoreAccess\Models\CoreScopeEntityProvider;
use Hamava\CoreAccess\Services\ScopeEntityOptionProvider;
use Hamava\CoreAccess\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ScopeEntityOptionProviderTest extends TestCase
{
    public function test_scope_entity_option_provider_returns_empty_when_provider_table_does_not_exist(): void
    {
        $this->createScopeEntityProvidersTable();

        CoreScopeEntityProvider::query()->create([
            'module_code' => 'inventory',
            'scope_type' => 'region',
            'provider_type' => 'table',
            'table_name' => 'missing_regions',
            'id_column' => 'id',
            'code_column' => 'code',
            'label_columns' => ['name'],
            'search_columns' => ['code', 'name'],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $options = app(ScopeEntityOptionProvider::class)->options('inventory', 'region');

        $this->assertSame([], $options);
    }

    public function test_table_scope_entity_option_provider_returns_searchable_filtered_options(): void
    {
        $this->createScopeEntityProvidersTable();

        Schema::create('regions', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->string('country_code');
            $table->boolean('is_active')->default(true);
        });

        DB::table('regions')->insert([
            ['id' => 1, 'code' => 'TEH', 'name' => 'Tehran', 'country_code' => 'IR', 'is_active' => true],
            ['id' => 2, 'code' => 'TBZ', 'name' => 'Tabriz', 'country_code' => 'IR', 'is_active' => true],
            ['id' => 3, 'code' => 'TEH-OLD', 'name' => 'Old Tehran', 'country_code' => 'IR', 'is_active' => false],
            ['id' => 4, 'code' => 'DXB', 'name' => 'Dubai', 'country_code' => 'AE', 'is_active' => true],
        ]);

        CoreScopeEntityProvider::query()->create([
            'module_code' => 'inventory',
            'scope_type' => 'region',
            'provider_type' => 'table',
            'table_name' => 'regions',
            'id_column' => 'id',
            'code_column' => 'code',
            'label_columns' => ['name'],
            'search_columns' => ['code', 'name'],
            'status_column' => 'is_active',
            'metadata' => ['filter_columns' => ['country' => 'country_code']],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $options = app(ScopeEntityOptionProvider::class)->options('inventory', 'region', 'teh', ['country' => 'IR']);

        $this->assertSame([
            ['id' => 1, 'code' => 'TEH', 'label' => 'TEH - Tehran'],
        ], $options);
    }

    public function test_table_scope_entity_option_provider_supports_distinct_metadata(): void
    {
        $this->createScopeEntityProvidersTable();

        Schema::create('assets', function (Blueprint $table): void {
            $table->id();
            $table->string('asset_type');
            $table->boolean('is_active')->default(true);
        });

        DB::table('assets')->insert([
            ['id' => 1, 'asset_type' => 'splitter', 'is_active' => true],
            ['id' => 2, 'asset_type' => 'splitter', 'is_active' => true],
            ['id' => 3, 'asset_type' => 'olt', 'is_active' => true],
            ['id' => 4, 'asset_type' => 'inactive', 'is_active' => false],
        ]);

        CoreScopeEntityProvider::query()->create([
            'module_code' => 'inventory',
            'scope_type' => 'asset_type',
            'provider_type' => 'table',
            'table_name' => 'assets',
            'id_column' => 'asset_type',
            'code_column' => 'asset_type',
            'label_columns' => ['asset_type'],
            'search_columns' => ['asset_type'],
            'status_column' => 'is_active',
            'metadata' => ['distinct' => true],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $options = app(ScopeEntityOptionProvider::class)->options('inventory', 'asset_type');

        $this->assertSame([
            ['id' => 'olt', 'code' => 'olt', 'label' => 'olt'],
            ['id' => 'splitter', 'code' => 'splitter', 'label' => 'splitter'],
        ], $options);
    }

    private function createScopeEntityProvidersTable(): void
    {
        Schema::create('core_scope_entity_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('module_code');
            $table->string('scope_type');
            $table->string('provider_type')->default('table');
            $table->string('table_name')->nullable();
            $table->string('id_column')->default('id');
            $table->string('code_column')->nullable();
            $table->json('label_columns')->nullable();
            $table->json('search_columns')->nullable();
            $table->string('status_column')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }
}
