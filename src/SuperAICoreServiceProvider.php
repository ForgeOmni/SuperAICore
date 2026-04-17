<?php

namespace SuperAICore;

use SuperAICore\Contracts\ProviderRepository;
use SuperAICore\Contracts\RoutingRepository;
use SuperAICore\Contracts\ServiceRepository;
use SuperAICore\Contracts\UsageRepository;
use SuperAICore\Repositories\EloquentProviderRepository;
use SuperAICore\Repositories\EloquentRoutingRepository;
use SuperAICore\Repositories\EloquentServiceRepository;
use SuperAICore\Repositories\EloquentUsageRepository;
use SuperAICore\Services\BackendRegistry;
use SuperAICore\Services\CapabilityRegistry;
use SuperAICore\Services\CostCalculator;
use SuperAICore\Services\Dispatcher;
use SuperAICore\Services\ProcessSourceRegistry;
use SuperAICore\Services\ProviderResolver;
use SuperAICore\Services\UsageTracker;
use SuperAICore\Sources\AiProcessSource;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SuperAICoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/super-ai-core.php', 'super-ai-core');

        // Repository bindings — host apps can override these to use different storage
        $this->app->bind(ProviderRepository::class, EloquentProviderRepository::class);
        $this->app->bind(ServiceRepository::class, EloquentServiceRepository::class);
        $this->app->bind(RoutingRepository::class, EloquentRoutingRepository::class);
        $this->app->bind(UsageRepository::class, EloquentUsageRepository::class);

        // Core singleton services (McpManager is all-static, no binding)
        $this->app->singleton(BackendRegistry::class);
        $this->app->singleton(CapabilityRegistry::class);
        $this->app->singleton(CostCalculator::class);

        // Process Monitor — host apps register their own ProcessSources in
        // their ServiceProvider's boot(); we seed the built-in AiProcess
        // source here so the page works out of the box.
        $this->app->singleton(ProcessSourceRegistry::class, function () {
            $registry = new ProcessSourceRegistry();
            $registry->register(new AiProcessSource());
            return $registry;
        });

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
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'super-ai-core');

        if (config('super-ai-core.views_enabled', true)) {
            $this->loadViewsFrom(__DIR__ . '/../resources/views', 'super-ai-core');
        }

        if (config('super-ai-core.route.enabled', true)) {
            Route::group([
                'prefix' => config('super-ai-core.route.prefix', 'super-ai-core'),
                'middleware' => config('super-ai-core.route.middleware', ['web', 'auth']),
                'as' => 'super-ai-core.',
            ], function () {
                $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
            });
        }

        // Publishing hooks
        $this->publishes([
            __DIR__ . '/../config/super-ai-core.php' => config_path('super-ai-core.php'),
        ], 'super-ai-core-config');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/super-ai-core'),
        ], 'super-ai-core-views');

        $this->publishes([
            __DIR__ . '/../resources/lang' => lang_path('vendor/super-ai-core'),
        ], 'super-ai-core-lang');

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'super-ai-core-migrations');
    }
}
