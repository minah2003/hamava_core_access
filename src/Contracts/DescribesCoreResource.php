<?php

namespace Hamava\CoreAccess\Contracts;

use Hamava\CoreAccess\Data\ResourceDescriptor;

interface DescribesCoreResource
{
    public function toCoreResourceDescriptor(): ResourceDescriptor;
}
