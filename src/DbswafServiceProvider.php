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

        // pushMiddlewareToGroup() 在 Laravel 9/10/11/12 的 Router 中均可用。
        // Laravel 11/12 新应用推荐在 bootstrap/app.php 的 withMiddleware() 中注册，
        // 但包开发者通过 ServiceProvider 调用此方法依然是官方支持的做法。
        // 为避免 Laravel Octane 持久进程中重复追加，使用 hasMiddlewareInGroup 做去重保护。
        $laravelVersion = (int) $this->app->version();
        if ($laravelVersion >= 11) {
            // Laravel 11/12: 使用 middlewareGroup 检测避免重复注册
            $this->appendMiddlewareIfAbsent($router, 'web', DbswafProtection::class);
            $this->appendMiddlewareIfAbsent($router, 'api', DbswafProtection::class);
        } else {
            // Laravel 9/10: pushMiddlewareToGroup 本身不做去重，直接调用即可
            $router->pushMiddlewareToGroup('web', DbswafProtection::class);
            $router->pushMiddlewareToGroup('api', DbswafProtection::class);
        }

        if (config('dbswaf_monitor.enabled', false)) {
            if ($laravelVersion >= 11) {
                $this->appendMiddlewareIfAbsent($router, 'web', DbswafMonitor::class);
                $this->appendMiddlewareIfAbsent($router, 'api', DbswafMonitor::class);
            } else {
                $router->pushMiddlewareToGroup('web', DbswafMonitor::class);
                $router->pushMiddlewareToGroup('api', DbswafMonitor::class);
            }
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

    /**
     * 向中间件分组追加中间件，如已存在则跳过（防止 Octane 持久进程重复注册）
     */
    protected function appendMiddlewareIfAbsent(\Illuminate\Routing\Router $router, string $group, string $middleware): void
    {
        $groups = $router->getMiddlewareGroups();
        if (isset($groups[$group]) && in_array($middleware, $groups[$group], true)) {
            return;
        }
        $router->pushMiddlewareToGroup($group, $middleware);
    }
}
