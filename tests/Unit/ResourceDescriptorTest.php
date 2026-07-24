<?php

namespace Hamava\CoreAccess\Tests\Unit;

use Hamava\CoreAccess\Data\ResourceDescriptor;
use Hamava\CoreAccess\Services\CoreAccessResolver;
use Hamava\CoreAccess\Tests\Fixtures\DescribedResource;
use Hamava\CoreAccess\Tests\TestCase;

class ResourceDescriptorTest extends TestCase
{
    public function test_it_creates_descriptor_from_described_resource(): void
    {
        $resource = new DescribedResource(
            moduleCode: 'inventory',
            resourceType: 'record',
            resourceId: 15,
            resourceCode: 'REC-15',
            attributes: [
                'region_id' => 10,
                'city_id' => 20,
            ],
        );

        $descriptor = ResourceDescriptor::from($resource);

        $this->assertInstanceOf(
            ResourceDescriptor::class,
            $descriptor,
        );

        $this->assertSame(
            'inventory',
            $descriptor->module_code,
        );

        $this->assertSame(
            'record',
            $descriptor->resource_type,
        );

        $this->assertSame(
            15,
            $descriptor->resource_id,
        );

        $this->assertSame(
            'REC-15',
            $descriptor->resource_code,
        );

        $this->assertSame(
            [
                'region_id' => 10,
                'city_id' => 20,
            ],
            $descriptor->attributes,
        );
    }

    public function test_resolver_accepts_described_resource(): void
    {
        $user = $this->user('described-resource');
        $module = $this->module('inventory');

        $permission = $this->permission(
            'inventory.records.edit',
            $module,
            requiresScope: true,
        );

        $role = $this->role(
            'inventory_editor',
            $module,
            $permission,
        );

        $team = $this->team(
            'DESCRIBED-RESOURCE',
        );

        $this->membership(
            $user,
            $team,
            $role,
            $module,
        );

        $this->scope(
            $team,
            $module,
            'region',
            10,
        );

        $resource = new DescribedResource(
            moduleCode: 'inventory',
            resourceType: 'record',
            resourceId: 15,
            resourceCode: 'REC-15',
            attributes: [
                'region_id' => 10,
            ],
        );

        $resolver = app(CoreAccessResolver::class);

        $decision = $resolver->check(
            $user,
            $permission->name,
            $resource,
        );

        $this->assertTrue($decision->allowed);

        $this->assertSame(
            'Allowed by team scope and role capability.',
            $decision->reason,
        );

        $this->assertTrue(
            $resolver->can(
                $user,
                $permission->name,
                $resource,
            )
        );
    }
}
