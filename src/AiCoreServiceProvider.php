<?php

namespace ForgeOmni\AiCore;

use ForgeOmni\AiCore\Contracts\ProviderRepository;
use ForgeOmni\AiCore\Contracts\RoutingRepository;
use ForgeOmni\AiCore\Contracts\ServiceRepository;
use ForgeOmni\AiCore\Contracts\UsageRepository;
use ForgeOmni\AiCore\Repositories\EloquentProviderRepository;
use ForgeOmni\AiCore\Repositories\EloquentRoutingRepository;
use ForgeOmni\AiCore\Repositories\EloquentServiceRepository;
use ForgeOmni\AiCore\Repositories\EloquentUsageRepository;
use ForgeOmni\AiCore\Services\BackendRegistry;
use ForgeOmni\AiCore\Services\CostCalculator;
use ForgeOmni\AiCore\Services\Dispatcher;
use ForgeOmni\AiCore\Services\ProviderResolver;
use ForgeOmni\AiCore\Services\UsageTracker;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AiCoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/ai-core.php', 'ai-core');

        // Repository bindings — host apps can override these to use different storage
        $this->app->bind(ProviderRepository::class, EloquentProviderRepository::class);
        $this->app->bind(ServiceRepository::class, EloquentServiceRepository::class);
        $this->app->bind(RoutingRepository::class, EloquentRoutingRepository::class);
        $this->app->bind(UsageRepository::class, EloquentUsageRepository::class);

        // Core singleton services (McpManager is all-static, no binding)
        $this->app->singleton(BackendRegistry::class);
        $this->app->singleton(CostCalculator::class);

        $this->app->singleton(UsageTracker::class, function ($app) {
            return new UsageTracker($app->make(UsageRepository::class));
        });

        $this->app->singleton(ProviderResolver::class, function ($app) {
            return new ProviderResolver($app->make(ProviderRepository::class));
        });

        $this->app->singleton(Dispatcher::class, function ($app) {
            return new Dispatcher(
                $app->make(BackendRegistry::class),
                $app->make(CostCalculator::class),
                $app->make(UsageTracker::class),
                $app->make(ProviderResolver::class),
                $app->make(RoutingRepository::class),
                $app->bound('log') ? $app->make('log') : null,
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'ai-core');

        if (config('ai-core.views_enabled', true)) {
            $this->loadViewsFrom(__DIR__ . '/../resources/views', 'ai-core');
        }

        if (config('ai-core.route.enabled', true)) {
            Route::group([
                'prefix' => config('ai-core.route.prefix', 'ai-core'),
                'middleware' => config('ai-core.route.middleware', ['web', 'auth']),
                'as' => 'ai-core.',
            ], function () {
                $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
            });
        }

        // Publishing hooks
        $this->publishes([
            __DIR__ . '/../config/ai-core.php' => config_path('ai-core.php'),
        ], 'ai-core-config');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/ai-core'),
        ], 'ai-core-views');

        $this->publishes([
            __DIR__ . '/../resources/lang' => lang_path('vendor/ai-core'),
        ], 'ai-core-lang');

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'ai-core-migrations');
    }
}
