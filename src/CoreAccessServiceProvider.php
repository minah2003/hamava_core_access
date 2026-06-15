<?php

namespace Hamava\CoreAccess;

use Hamava\CoreAccess\Middleware\CoreCan;
use Hamava\CoreAccess\Services\CoreAccessResolver;
use Hamava\CoreAccess\Services\CoreNavigationResolver;
use Hamava\CoreAccess\Services\TeamScopeResolver;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class CoreAccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/core-access.php', 'core-access');

        $this->app->singleton(TeamScopeResolver::class);
        $this->app->singleton(CoreAccessResolver::class);
        $this->app->singleton(CoreNavigationResolver::class);

        $this->app->alias(CoreAccessResolver::class, 'hamava.core-access');
        $this->app->alias(CoreNavigationResolver::class, 'hamava.core-navigation');
    }

    public function boot(Router $router): void
    {
        $this->publishes([
            __DIR__.'/../config/core-access.php' => config_path('core-access.php'),
        ], 'hamava-core-access-config');

        $router->aliasMiddleware('core.can', CoreCan::class);
    }
}
