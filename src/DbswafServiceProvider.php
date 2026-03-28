<?php

namespace Dabashan\BtLaravelWaf;

use Dabashan\BtLaravelWaf\Middleware\DbswafProtection;
use Dabashan\BtLaravelWaf\Middleware\DbswafMonitor;
use Dabashan\BtLaravelWaf\Services\CcProtection;
use Dabashan\BtLaravelWaf\Services\MonitorService;
use Illuminate\Support\ServiceProvider;

class DbswafServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/dbswaf.php', 'dbswaf');
        $this->mergeConfigFrom(__DIR__ . '/../config/dbswaf_monitor.php', 'dbswaf_monitor');

        $this->app->singleton(CcProtection::class, function ($app) {
            return new CcProtection();
        });

        $this->app->singleton(MonitorService::class, function ($app) {
            return new MonitorService();
        });

        $this->app->alias(CcProtection::class, 'dbswaf.cc');
        $this->app->alias(MonitorService::class, 'dbswaf.monitor');
    }

    public function boot(): void
    {
        if (!config('dbswaf.enabled', true)) {
            return;
        }

        $router = $this->app->make('router');

        $router->aliasMiddleware('dbswaf.protect', DbswafProtection::class);
        $router->aliasMiddleware('dbswaf.monitor', DbswafMonitor::class);
        $router->aliasMiddleware('dbswaf.internal', \Dabashan\BtLaravelWaf\Middleware\InternalOnly::class);

        $router->pushMiddlewareToGroup('web', DbswafProtection::class);
        $router->pushMiddlewareToGroup('api', DbswafProtection::class);

        if (config('dbswaf_monitor.enabled', false)) {
            $router->pushMiddlewareToGroup('web', DbswafMonitor::class);
            $router->pushMiddlewareToGroup('api', DbswafMonitor::class);
        }

        $this->loadRoutesFrom(__DIR__ . '/../routes/dbswaf_api.php');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'dbswaf');

        $this->publishes([
            __DIR__ . '/../config/dbswaf.php'         => config_path('dbswaf.php'),
            __DIR__ . '/../config/dbswaf_monitor.php' => config_path('dbswaf_monitor.php'),
        ], 'dbswaf-config');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/dbswaf'),
        ], 'dbswaf-views');

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'dbswaf-migrations');
    }

    public function provides(): array
    {
        return [
            CcProtection::class,
            MonitorService::class,
            'dbswaf.cc',
            'dbswaf.monitor',
        ];
    }
}
