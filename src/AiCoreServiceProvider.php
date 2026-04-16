<?php

namespace ForgeOmni\AiCore;

use ForgeOmni\AiCore\Services\Dispatcher;
use ForgeOmni\AiCore\Services\CostCalculator;
use ForgeOmni\AiCore\Services\McpManager;
use ForgeOmni\AiCore\Services\ProviderResolver;
use ForgeOmni\AiCore\Services\UsageTracker;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AiCoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/ai-core.php', 'ai-core');

        // Singleton services
        $this->app->singleton(CostCalculator::class);
        $this->app->singleton(ProviderResolver::class);
        $this->app->singleton(Dispatcher::class);
        $this->app->singleton(UsageTracker::class);
        $this->app->singleton(McpManager::class);
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

        // Commands are available via standalone `bin/ai-core` CLI.
        // For Artisan integration, host apps can wrap them in Illuminate\Console\Command
        // subclasses or run `php vendor/bin/ai-core <cmd>` from composer scripts.

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
