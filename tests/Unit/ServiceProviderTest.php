<?php

namespace Hamava\CoreAccess\Tests\Unit;

use Hamava\CoreAccess\Middleware\CoreCan;
use Hamava\CoreAccess\Middleware\CoreNodeCan;
use Hamava\CoreAccess\Services\CoreAccessContext;
use Hamava\CoreAccess\Services\CoreAccessResolver;
use Hamava\CoreAccess\Services\CoreCapabilityAssignmentResolver;
use Hamava\CoreAccess\Services\CoreNavigationResolver;
use Hamava\CoreAccess\Services\CoreQueryAuthorizationResolver;
use Hamava\CoreAccess\Services\ScopeCatalogService;
use Hamava\CoreAccess\Services\ScopeEntityOptionProvider;
use Hamava\CoreAccess\Services\TeamScopeResolver;
use Hamava\CoreAccess\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function test_provider_registers_config_aliases_and_middleware(): void
    {
        $accessResolver = app(CoreAccessResolver::class);
        $navigationResolver = app(CoreNavigationResolver::class);

        $this->assertSame(
            'core_modules',
            config('core-access.tables.modules'),
        );

        $this->assertSame(
            $accessResolver,
            app('hamava.core-access'),
        );

        $this->assertSame(
            $navigationResolver,
            app('hamava.core-navigation'),
        );

        $this->assertSame(
            CoreCan::class,
            app('router')->getMiddleware()['core.can'] ?? null,
        );

        $this->assertSame(
            CoreNodeCan::class,
            app('router')->getMiddleware()['core.node'] ?? null,
        );
    }

    public function test_request_aware_services_are_scoped(): void
    {
        $context = app(CoreAccessContext::class);
        $scopeResolver = app(TeamScopeResolver::class);
        $accessResolver = app(CoreAccessResolver::class);
        $navigationResolver = app(CoreNavigationResolver::class);
        $queryAuthorizationResolver = app(CoreQueryAuthorizationResolver::class);

        /*
         * Within one request/application scope, every scoped binding
         * must resolve to the same object instance.
         */
        $this->assertSame(
            $context,
            app(CoreAccessContext::class),
        );

        $this->assertSame(
            $scopeResolver,
            app(TeamScopeResolver::class),
        );

        $this->assertSame(
            $accessResolver,
            app(CoreAccessResolver::class),
        );

        $this->assertSame(
            $navigationResolver,
            app(CoreNavigationResolver::class),
        );

        $this->assertSame(
            $queryAuthorizationResolver,
            app(CoreQueryAuthorizationResolver::class),
        );

        /*
         * Simulate the boundary between two HTTP requests or queue jobs.
         */
        $this->app->forgetScopedInstances();

        $newContext = app(CoreAccessContext::class);
        $newScopeResolver = app(TeamScopeResolver::class);
        $newAccessResolver = app(CoreAccessResolver::class);
        $newNavigationResolver = app(CoreNavigationResolver::class);
        $newQueryAuthorizationResolver = app(CoreQueryAuthorizationResolver::class);

        $this->assertNotSame(
            $context,
            $newContext,
        );

        $this->assertNotSame(
            $scopeResolver,
            $newScopeResolver,
        );

        $this->assertNotSame(
            $accessResolver,
            $newAccessResolver,
        );

        $this->assertNotSame(
            $navigationResolver,
            $newNavigationResolver,
        );

        $this->assertNotSame(
            $queryAuthorizationResolver,
            $newQueryAuthorizationResolver,
        );

        /*
         * Aliases must resolve to the instances belonging to the
         * current scope, not instances retained from the old scope.
         */
        $this->assertSame(
            $newAccessResolver,
            app('hamava.core-access'),
        );

        $this->assertSame(
            $newNavigationResolver,
            app('hamava.core-navigation'),
        );
    }

    public function test_stateless_services_remain_singletons(): void
    {
        $assignmentResolver = app(CoreCapabilityAssignmentResolver::class);
        $catalogService = app(ScopeCatalogService::class);
        $optionProvider = app(ScopeEntityOptionProvider::class);

        $this->assertSame(
            $assignmentResolver,
            app(CoreCapabilityAssignmentResolver::class),
        );

        $this->assertSame(
            $catalogService,
            app(ScopeCatalogService::class),
        );

        $this->assertSame(
            $optionProvider,
            app(ScopeEntityOptionProvider::class),
        );

        /*
         * Clearing scoped instances must not recreate singleton services.
         */
        $this->app->forgetScopedInstances();

        $this->assertSame(
            $assignmentResolver,
            app(CoreCapabilityAssignmentResolver::class),
        );

        $this->assertSame(
            $catalogService,
            app(ScopeCatalogService::class),
        );

        $this->assertSame(
            $optionProvider,
            app(ScopeEntityOptionProvider::class),
        );
    }
}
