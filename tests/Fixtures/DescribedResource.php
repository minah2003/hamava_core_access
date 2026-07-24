<?php

namespace Hamava\CoreAccess\Tests\Fixtures;

use Hamava\CoreAccess\Contracts\DescribesCoreResource;
use Hamava\CoreAccess\Data\ResourceDescriptor;

final class DescribedResource implements DescribesCoreResource
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        private readonly string $moduleCode,
        private readonly string $resourceType,
        private readonly int|string|null $resourceId = null,
        private readonly ?string $resourceCode = null,
        private readonly array $attributes = [],
    ) {}

    public function toCoreResourceDescriptor(): ResourceDescriptor
    {
        return ResourceDescriptor::make(
            module_code: $this->moduleCode,
            resource_type: $this->resourceType,
            resource_id: $this->resourceId,
            resource_code: $this->resourceCode,
            attributes: $this->attributes,
        );
    }
}
