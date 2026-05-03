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
use SuperAICore\Services\CliProcessBuilderRegistry;
use SuperAICore\Services\CostCalculator;
use SuperAICore\Services\Dispatcher;
use SuperAICore\Services\EngineCatalog;
use SuperAICore\Services\ProcessSourceRegistry;
use SuperAICore\Services\ProviderResolver;
use SuperAICore\Services\UsageRecorder;
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
        $this->app->singleton(EngineCatalog::class);
        $this->app->singleton(CostCalculator::class);
        $this->app->singleton(\SuperAICore\Support\CliBinaryLocator::class);

        // Embedding-provider factory (0.9.7) — resolves `super-ai-core.embeddings.*`
        // into an SDK `EmbeddingProvider` (Ollama / callable / host-supplied).
        // Returned `?EmbeddingProvider` is shared by `SemanticSkillReranker`,
        // `SuperAgentBackend`, and any host-side `SemanticSkillRouter`
        // construction so the same instance + cache wins everywhere.
        $this->app->singleton(\SuperAICore\Services\EmbeddingProviderFactory::class);

        // Cross-harness session resume (0.9.7) — wraps SDK's HarnessImporter
        // family so /processes Resume dropdown gets a single seam.
        $this->app->singleton(\SuperAICore\Services\HarnessSessionResolver::class);

        // Browser-screenshot store (0.9.7) — backs the `latest_screenshot_url`
        // surface on `/processes` rows. SuperAgentBackend pumps base64 PNGs
        // emitted by SDK 0.9.7's `FirefoxBridgeTool` (tool name `browser`)
        // into this store keyed by the dispatch process_id; the Process
        // Monitor reaper purges on FINISHED/KILLED. Ctor args read from
        // `super-ai-core.browser_screenshots.{disk,dir}` so hosts can move
        // them to S3 / per-pod tmpfs without a code change.
        $this->app->singleton(\SuperAICore\Services\BrowserScreenshotStore::class, function () {
            return new \SuperAICore\Services\BrowserScreenshotStore(
                disk: (string) (config('super-ai-core.browser_screenshots.disk') ?? 'local'),
                dir:  (string) (config('super-ai-core.browser_screenshots.dir')  ?? 'super-ai-core/browser-screenshots'),
            );
        });
        $this->app->singleton(CliProcessBuilderRegistry::class, function ($app) {
            return new CliProcessBuilderRegistry($app->make(EngineCatalog::class));
        });

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

        $this->app->singleton(UsageRecorder::class, function ($app) {
            return new UsageRecorder(
                $app->make(UsageTracker::class),
                $app->make(CostCalculator::class),
            );
        });

        // Provider-type registry + env builder (0.6.2+). The registry seeds
        // from bundled defaults and merges `super-ai-core.provider_types`
        // overrides on top so hosts can relabel or extend without a fork.
        $this->app->singleton(\SuperAICore\Services\ProviderTypeRegistry::class, function ($app) {
            $overrides = (array) config('super-ai-core.provider_types', []);
            return new \SuperAICore\Services\ProviderTypeRegistry($overrides);
        });
        $this->app->singleton(\SuperAICore\Services\ProviderEnvBuilder::class, function ($app) {
            return new \SuperAICore\Services\ProviderEnvBuilder(
                $app->make(\SuperAICore\Services\ProviderTypeRegistry::class),
            );
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

        // Phase C: AgentSpawn\Pipeline — three-phase spawn-plan
        // emulation (Phase 1 preamble, Phase 2 fanout, Phase 3
        // consolidation re-call) for backends without a native sub-agent
        // primitive. TaskRunner activates it when host passes
        // spawn_plan_dir.
        $this->app->singleton(\SuperAICore\AgentSpawn\Pipeline::class, function ($app) {
            return new \SuperAICore\AgentSpawn\Pipeline(
                $app->make(CapabilityRegistry::class),
                $app->make(Dispatcher::class),
                $app->make(EngineCatalog::class),
                $app->bound('log') ? $app->make('log') : null,
            );
        });

        // Phase B: TaskRunner — one-call task execution wrapper around
        // Dispatcher. Hosts that adopted Phase A's stream:true flag can
        // now collapse their executeTask() / executeClaude() bodies to
        // a single $runner->run() call.
        $this->app->singleton(\SuperAICore\Runner\TaskRunner::class, function ($app) {
            return new \SuperAICore\Runner\TaskRunner(
                $app->make(Dispatcher::class),
                $app->make(\SuperAICore\AgentSpawn\Pipeline::class),
                $app->bound('log') ? $app->make('log') : null,
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'super-ai-core');

        // Register artisan commands. Historically these lived only on the
        // standalone `bin/superaicore` console — keep the set narrow here so
        // we don't leak every internal Symfony command into `php artisan`.
        if ($this->app->runningInConsole()) {
            $this->commands([
                \SuperAICore\Console\Commands\ClaudeMcpSyncCommand::class,
                \SuperAICore\Console\Commands\McpSyncBackendsCommand::class,
                \SuperAICore\Console\Commands\ApiStatusCommand::class,
                \SuperAICore\Console\Commands\KimiSyncCommand::class,

                // 0.9.0 — file-driven provider creation for CI / container
                // bootstrap; secret-safe via stdin or env-var reference.
                \SuperAICore\Console\Commands\ProviderAddCommand::class,
                // 0.9.0 — multi-account quick swap (jcode `/account` style),
                // also auto-fired by SuperAgentBackend on QuotaExceeded when
                // super-ai-core.auto_rotate is enabled.
                \SuperAICore\Console\Commands\ProviderRotateCommand::class,

                // Skill telemetry / ranking / evolution (0.8.1+ — borrowed
                // from OpenSpace's skill_engine; FIX-mode only, never
                // auto-applied. See SkillEvolver.php docblock.)
                \SuperAICore\Console\Commands\SkillTrackStartCommand::class,
                \SuperAICore\Console\Commands\SkillTrackStopCommand::class,
                \SuperAICore\Console\Commands\SkillStatsCommand::class,
                \SuperAICore\Console\Commands\SkillRankCommand::class,
                \SuperAICore\Console\Commands\SkillEvolveCommand::class,
                \SuperAICore\Console\Commands\SkillCandidatesCommand::class,
            ]);
        }

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
