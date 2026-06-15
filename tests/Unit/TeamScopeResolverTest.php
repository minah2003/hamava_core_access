<?php

namespace Hamava\CoreAccess\Tests\Unit;

use Hamava\CoreAccess\Data\ResourceDescriptor;
use Hamava\CoreAccess\Models\CoreTeamScope;
use Hamava\CoreAccess\Services\TeamScopeResolver;
use Hamava\CoreAccess\Tests\TestCase;

class TeamScopeResolverTest extends TestCase
{
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
