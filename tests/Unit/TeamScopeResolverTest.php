<?php

namespace Hamava\CoreAccess\Tests\Unit;

use Hamava\CoreAccess\Data\ResourceDescriptor;
use Hamava\CoreAccess\Models\CoreTeamScope;
use Hamava\CoreAccess\Services\TeamScopeResolver;
use Hamava\CoreAccess\Tests\TestCase;

class TeamScopeResolverTest extends TestCase
{
    public function test_all_scope_without_scope_identifier_matches_resource(): void
    {
        $resolver = app(TeamScopeResolver::class);
        $scope = new CoreTeamScope([
            'scope_type' => 'all',
        ]);
        $resource = ResourceDescriptor::make('inventory', 'record', 1, null, ['region_id' => 10]);

        $this->assertTrue($resolver->matches($scope, $resource));
    }

    public function test_user_scope_without_identifier_does_not_match_user_resource(): void
    {
        $resolver = app(TeamScopeResolver::class);
        $scope = new CoreTeamScope([
            'scope_type' => 'user',
        ]);
        $resource = ResourceDescriptor::make('inventory', 'user', 15, 'operator-15', [
            'user_id' => 15,
            'user_code' => 'operator-15',
        ]);

        $this->assertFalse($resolver->matches($scope, $resource));
    }

    public function test_site_scope_without_identifier_does_not_match_site_resource(): void
    {
        $resolver = app(TeamScopeResolver::class);
        $scope = new CoreTeamScope([
            'scope_type' => 'site',
        ]);
        $resource = ResourceDescriptor::make('inventory', 'site', 25, 'north-site', [
            'site_id' => 25,
            'site_code' => 'north-site',
        ]);

        $this->assertFalse($resolver->matches($scope, $resource));
    }

    public function test_process_group_scope_without_identifier_does_not_match_ticket_resource(): void
    {
        $resolver = app(TeamScopeResolver::class);
        $scope = new CoreTeamScope([
            'scope_type' => 'process_group',
        ]);
        $resource = ResourceDescriptor::make('ticketing', 'ticket', 35, 'ticket-35', [
            'process_group_id' => 45,
            'process_group_code' => 'field-work',
        ]);

        $this->assertFalse($resolver->matches($scope, $resource));
    }

    public function test_asset_category_scope_without_category_or_code_does_not_match(): void
    {
        $resolver = app(TeamScopeResolver::class);
        $scope = new CoreTeamScope([
            'scope_type' => 'asset_category',
        ]);
        $resource = ResourceDescriptor::make('inventory', 'asset', 1, 'asset-1', [
            'asset_category' => 'network',
        ]);

        $this->assertFalse($resolver->matches($scope, $resource));
    }

    public function test_asset_type_scope_without_type_or_code_does_not_match(): void
    {
        $resolver = app(TeamScopeResolver::class);
        $scope = new CoreTeamScope([
            'scope_type' => 'asset_type',
        ]);
        $resource = ResourceDescriptor::make('inventory', 'asset', 1, 'asset-1', [
            'asset_type' => 'olt',
        ]);

        $this->assertFalse($resolver->matches($scope, $resource));
    }

    public function test_module_wide_scope_matches_with_or_without_access_node(): void
    {
        $resolver = app(TeamScopeResolver::class);
        $module = $this->module('inventory');
        $team = $this->team('OPS');
        $node = $this->node($module, 'inventory.records');
        $scope = $this->scope($team, $module, 'region', 10);
        $resource = ResourceDescriptor::make('inventory', 'record', 1, null, ['region_id' => 10]);

        $matchesWithoutNode = $resolver->matchingScopesForTeams([$team->id], $module->code, $resource, 'allow');
        $matchesWithNode = $resolver->matchingScopesForTeams([$team->id], $module->code, $resource, 'allow', $node->id);

        $this->assertSame([$scope->id], $matchesWithoutNode->pluck('id')->all());
        $this->assertSame([$scope->id], $matchesWithNode->pluck('id')->all());
    }

    public function test_node_specific_scope_matches_only_the_same_access_node(): void
    {
        $resolver = app(TeamScopeResolver::class);
        $module = $this->module('inventory');
        $team = $this->team('OPS');
        $node = $this->node($module, 'inventory.records');
        $scope = $this->scope($team, $module, 'region', 10, 'allow', $node);
        $resource = ResourceDescriptor::make('inventory', 'record', 1, null, ['region_id' => 10]);

        $matches = $resolver->matchingScopesForTeams([$team->id], $module->code, $resource, 'allow', $node->id);
        $matchesWithoutNode = $resolver->matchingScopesForTeams([$team->id], $module->code, $resource, 'allow');

        $this->assertSame([$scope->id], $matches->pluck('id')->all());
        $this->assertCount(0, $matchesWithoutNode);
    }

    public function test_node_specific_scope_does_not_match_another_access_node_in_same_module(): void
    {
        $resolver = app(TeamScopeResolver::class);
        $module = $this->module('inventory');
        $team = $this->team('OPS');
        $recordsNode = $this->node($module, 'inventory.records');
        $assetsNode = $this->node($module, 'inventory.assets');
        $this->scope($team, $module, 'region', 10, 'allow', $recordsNode);
        $resource = ResourceDescriptor::make('inventory', 'record', 1, null, ['region_id' => 10]);

        $matches = $resolver->matchingScopesForTeams([$team->id], $module->code, $resource, 'allow', $assetsNode->id);

        $this->assertCount(0, $matches);
    }

    public function test_scope_matching_supports_ids_codes_and_ancestors(): void
    {
        $resolver = app(TeamScopeResolver::class);

        $ancestorScope = new CoreTeamScope([
            'scope_type' => 'region',
            'scope_id' => 10,
            'include_children' => true,
        ]);

        $directScope = new CoreTeamScope([
            'scope_type' => 'region',
            'scope_code' => 'north',
            'include_children' => false,
        ]);

        $resource = ResourceDescriptor::make('inventory', 'record', 1, null, [
            'region_id' => 20,
            'region_ancestor_ids' => [10],
            'region_code' => 'north',
        ]);

        $this->assertTrue($resolver->matches($ancestorScope, $resource));
        $this->assertTrue($resolver->matches($directScope, $resource));

        $ancestorScope->include_children = false;

        $this->assertFalse($resolver->matches($ancestorScope, $resource));
    }
}
