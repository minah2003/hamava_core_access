<?php

namespace Hamava\CoreAccess\Tests\Unit;

use Hamava\CoreAccess\Middleware\CoreCan;
use Hamava\CoreAccess\Services\CoreAccessResolver;
use Hamava\CoreAccess\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function test_provider_registers_config_bindings_and_middleware_alias(): void
    {
        $this->assertSame('core_modules', config('core-access.tables.modules'));
        $this->assertInstanceOf(CoreAccessResolver::class, app('hamava.core-access'));
        $this->assertSame(CoreCan::class, app('router')->getMiddleware()['core.can'] ?? null);
    }
}
