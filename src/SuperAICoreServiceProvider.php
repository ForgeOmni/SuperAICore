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

        // P0-1 SnapshotDiffService — wraps SuperAgent GitShadowStore +
        // shells `git --git-dir=<shadow.git> diff` for per-file diff
        // envelopes. SuperAgentBackend reads this binding when a dispatch
        // resolves a project root, snapshots before + after, and writes
        // the resulting envelope onto `ai_usage_logs.file_diff_summary`.
        $this->app->singleton(\SuperAICore\Services\SnapshotDiffService::class, function ($app) {
            try {
                $logger = $app->make(\Psr\Log\LoggerInterface::class);
            } catch (\Throwable) {
                $logger = null;
            }
            return new \SuperAICore\Services\SnapshotDiffService($logger);
        });

        // P1-4 RemindersResolver — synthetic system-prompt blocks rendered
        // from `super-ai-core.reminders.*` rules and matched against
        // dispatch options/metadata. Opt-in: empty rule list = no-op.
        $this->app->singleton(\SuperAICore\Services\RemindersResolver::class, function () {
            $rules = (array) (config('super-ai-core.reminders.rules') ?? []);
            return new \SuperAICore\Services\RemindersResolver($rules);
        });

        // P1-6 PermissionEvaluator — opencode-style {permission, pattern,
        // action} ruleset evaluator. `findLast` wildcard match, default
        // action 'ask'. Used by SuperAgentBackend to translate per-agent
        // configuration into allowed_tools / denied_tools at construction.
        $this->app->singleton(\SuperAICore\Services\PermissionEvaluator::class);

        // P3-9 PtyService — long-lived shell sessions (proc_open + file
        // log + cursor poll). Phase 1: HTTP long-poll only; Phase 2 will
        // upgrade to WebSocket via Reverb without changing the wire shape.
        $this->app->singleton(\SuperAICore\Services\PtyService::class, function ($app) {
            try {
                $logger = $app->make(\Psr\Log\LoggerInterface::class);
            } catch (\Throwable) {
                $logger = null;
            }
            return new \SuperAICore\Services\PtyService($logger);
        });

        // P3-10 ShareSessionService — host-side queue that pushes
        // session events to a configured remote share endpoint.
        $this->app->singleton(\SuperAICore\Services\ShareSessionService::class, function ($app) {
            try {
                $logger = $app->make(\Psr\Log\LoggerInterface::class);
            } catch (\Throwable) {
                $logger = null;
            }
            return new \SuperAICore\Services\ShareSessionService($logger);
        });

        // P2-8 SubagentPermissionDeriver — when a parent agent dispatches
        // a sub-agent via SuperAgent's AgentTool, this service forwards
        // the parent's `denied_tools` so the child inherits the deny set
        // and can never elevate.
        $this->app->singleton(\SuperAICore\Services\SubagentPermissionDeriver::class, function ($app) {
            return new \SuperAICore\Services\SubagentPermissionDeriver(
                $app->make(\SuperAICore\Services\PermissionEvaluator::class),
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

        // Tracing singleton — magic-trace-style ring buffer + Chrome Trace
        // Event JSON writer. Dispatcher emits llm/cache/provider events on
        // every call; auto-dumps on null result, provider rotation, etc.
        // See config('super-ai-core.tracing.*') for tuning and SuperTeam
        // .claude/refs/ref-trace-format.md for the cross-repo wire spec.
        $this->app->singleton(\SuperAICore\Tracing\TraceCollector::class, function () {
            return \SuperAICore\Tracing\TraceCollector::getInstance();
        });

        $this->app->singleton(Dispatcher::class, function ($app) {
            return new Dispatcher(
                $app->make(BackendRegistry::class),
                $app->make(CostCalculator::class),
                $app->make(UsageTracker::class),
                $app->make(ProviderResolver::class),
                $app->make(RoutingRepository::class),
                $app->bound('log') ? $app->make('log') : null,
                $app->make(\SuperAICore\Tracing\TraceCollector::class),
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
                $app->make(BackendRegistry::class),
            );
        });

        // ── SDK 0.9.8 companion bindings (0.9.1) ────────────────────
        // GoalStore SPI — durable backing for SuperAgent's thread-goal
        // primitive. Bound as a contract so hosts that already keep
        // goals in their own table can swap in their own implementation
        // without a fork. GoalManager auto-resolves the bound store via
        // constructor injection.
        $this->app->bind(
            \SuperAgent\Goals\Contracts\GoalStore::class,
            \SuperAICore\Goals\EloquentGoalStore::class,
        );
        $this->app->singleton(\SuperAgent\Goals\GoalManager::class);

        // Three-tier approval gate (Auto/Suggest/Never). No-arg
        // constructor wraps the existing `DestructiveCommandScanner`
        // so the safety floor stays consistent with shell-tool sites.
        $this->app->singleton(\SuperAICore\Runner\ApprovalGate::class);

        // ── SDK 1.0.0 companion bindings ─────────────────────────────
        // P0-3 — `/model auto` heuristic wrapped for host dispatch.
        // Built from `super-ai-core.auto_model.*` so hosts can rebind
        // Pro/Flash to their preferred models without touching the SDK.
        $this->app->singleton(\SuperAICore\Services\AutoModelRouter::class, function ($app) {
            $cfg = (array) config('super-ai-core.auto_model', []);
            $catalog = null;
            if (!empty($cfg['score_catalog_path']) && class_exists(\SuperAgent\Evals\ScoreCatalog::class)) {
                try {
                    $catalog = new \SuperAgent\Evals\ScoreCatalog((string) $cfg['score_catalog_path']);
                } catch (\Throwable) {
                    $catalog = null;
                }
            }
            return new \SuperAICore\Services\AutoModelRouter(
                proModel:                   $cfg['pro_model'] ?? null,
                flashModel:                 $cfg['flash_model'] ?? null,
                longContextThresholdTokens: (int) ($cfg['long_context_tokens'] ?? 32_000),
                toolChainThreshold:         (int) ($cfg['tool_chain_threshold'] ?? 3),
                proKeywords:                isset($cfg['pro_keywords']) ? (array) $cfg['pro_keywords'] : null,
                scoreCatalog:               $catalog,
            );
        });

        // P0-4 — `CacheAwareCompressor` factory. Hosts call ->build() when
        // constructing their own ContextManager so summary boundaries land
        // AFTER the pinned cache prefix.
        $this->app->singleton(\SuperAICore\Services\CompressionStrategyFactory::class, function ($app) {
            $cfg = (array) config('super-ai-core.compression', []);
            return new \SuperAICore\Services\CompressionStrategyFactory(
                cacheAware: (bool) ($cfg['cache_aware'] ?? true),
                pinHead:    (int)  ($cfg['pin_head']    ?? 4),
                pinSystem:  (bool) ($cfg['pin_system']  ?? true),
            );
        });

        // P2-8 — Security primitive helper for free-form text injected
        // into system-role prompts. The SDK's GoalManager already wraps
        // goal objectives; this helper covers the other sites
        // (ad-hoc memory, workspace plugins, host UI form input).
        $this->app->singleton(\SuperAICore\Services\UntrustedInputHelper::class, function ($app) {
            return new \SuperAICore\Services\UntrustedInputHelper(
                enabled: (bool) config('super-ai-core.security.untrusted_input_enabled', true),
            );
        });

        // P2-10 — Per-process token-bucket rate limiter pool. Provider
        // name is the bucket key; falls back to 'default' when no
        // explicit entry exists.
        $this->app->singleton(\SuperAICore\Services\RateLimiterRegistry::class, function ($app) {
            return new \SuperAICore\Services\RateLimiterRegistry(
                config: (array) config('super-ai-core.rate_limits', []),
            );
        });

        // P2-11 — AdHoc memory pool + Conversation Fork seam.
        $this->app->singleton(\SuperAICore\Services\AdHocMemoryRegistry::class);
        $this->app->singleton(\SuperAICore\Services\ConversationForkService::class);

        // P2-12 — DeepSeek FIM standalone helper. API key resolves from
        // `super-ai-core.deepseek.api_key` or DEEPSEEK_API_KEY env var.
        $this->app->singleton(\SuperAICore\Services\DeepSeekFimService::class, function ($app) {
            return new \SuperAICore\Services\DeepSeekFimService(
                apiKey: config('super-ai-core.deepseek.api_key'),
            );
        });

        // ── Cross-layer cooperative modes ────────────────────────────
        // CrossLayerDispatcher is the single seam every CLI-layer mode
        // routes through. Bound as a singleton so `setModes()` wiring
        // sticks; the three modes are bound lazily so cyclic ctor deps
        // are avoided (mode → dispatcher → mode is fine because
        // `setModes()` injects after construction).
        $this->app->singleton(\SuperAICore\Modes\CrossLayerDispatcher::class, function ($app) {
            return new \SuperAICore\Modes\CrossLayerDispatcher(
                coreDispatcher: $app->make(Dispatcher::class),
                logger:         $app->bound('log') ? $app->make('log') : null,
            );
        });

        $this->app->singleton(\SuperAICore\Modes\CliAutoMode::class, function ($app) {
            return new \SuperAICore\Modes\CliAutoMode(
                dispatcher: $app->make(\SuperAICore\Modes\CrossLayerDispatcher::class),
                logger:     $app->bound('log') ? $app->make('log') : null,
                config:     (array) config('super-ai-core.cli_auto', []),
            );
        });
        $this->app->singleton(\SuperAICore\Modes\CliSmartOrchestrator::class, function ($app) {
            return new \SuperAICore\Modes\CliSmartOrchestrator(
                dispatcher: $app->make(\SuperAICore\Modes\CrossLayerDispatcher::class),
                logger:     $app->bound('log') ? $app->make('log') : null,
                config:     (array) config('super-ai-core.cli_smart', []),
            );
        });
        $this->app->singleton(\SuperAICore\Modes\CliSquadOrchestrator::class, function ($app) {
            return new \SuperAICore\Modes\CliSquadOrchestrator(
                dispatcher: $app->make(\SuperAICore\Modes\CrossLayerDispatcher::class),
                logger:     $app->bound('log') ? $app->make('log') : null,
                config:     (array) config('super-ai-core.cli_squad', []),
            );
        });
        // P2-7 — Plan mode orchestrator (opencode plan agent + plan_exit
        // port). Registered with CliModeRouter so callers can dispatch
        // `mode: 'plan'` through the same cross-mode seam every other
        // orchestrator uses.
        $this->app->singleton(\SuperAICore\Modes\CliPlanOrchestrator::class, function ($app) {
            return new \SuperAICore\Modes\CliPlanOrchestrator(
                dispatcher: $app->make(\SuperAICore\Modes\CrossLayerDispatcher::class),
                logger:     $app->bound('log') ? $app->make('log') : null,
                config:     (array) config('super-ai-core.modes.plan', []),
            );
        });

        // Squad TeamRegistry — single source of truth for YAML team
        // definitions. The bundled team library lives in SuperAgent
        // SDK; hosts can layer additional directories via
        // `super-ai-core.squad_team_dirs` config. Same 3-tier pattern
        // as `ModelCatalog` (bundled / directories / runtime).
        $this->app->singleton(\SuperAgent\Squad\TeamRegistry::class, function ($app) {
            $registry = new \SuperAgent\Squad\TeamRegistry();
            foreach ((array) config('super-ai-core.squad_team_dirs', []) as $dir) {
                if (is_string($dir) && $dir !== '') {
                    $registry->addDirectory($dir);
                }
            }
            return $registry;
        });

        // CliModeRouter — host implementation of SDK's ModeRouter
        // contract. Routes mode names through the three CLI
        // orchestrators AND leaf provider tags (`cli:*` / `sdk:*`)
        // through the cross-layer dispatcher. Registered with the
        // SDK via SquadDispatcherBridge so any SDK code path that
        // recurses cross-mode picks this up automatically.
        $this->app->singleton(\SuperAICore\Modes\CliModeRouter::class, function ($app) {
            $router = new \SuperAICore\Modes\CliModeRouter(
                crossLayer:   $app->make(\SuperAICore\Modes\CrossLayerDispatcher::class),
                teamRegistry: $app->make(\SuperAgent\Squad\TeamRegistry::class),
                logger:       $app->bound('log') ? $app->make('log') : new \Psr\Log\NullLogger(),
            );
            $router->register($app->make(\SuperAICore\Modes\CliAutoMode::class));
            $router->register($app->make(\SuperAICore\Modes\CliSmartOrchestrator::class));
            $router->register($app->make(\SuperAICore\Modes\CliSquadOrchestrator::class));
            $router->register($app->make(\SuperAICore\Modes\CliPlanOrchestrator::class));
            return $router;
        });

        // Reverse bridge — SDK exposes two opt-in SPIs (since 1.0+):
        //   - SquadDispatcherRegistry: default squad dispatcher
        //   - ModeRouterRegistry: cross-mode router
        // Installing both lets SDK-internal cross-mode recursion
        // reach our CLI three-mode set, and SDK-internal squad runs
        // route per-step dispatches through CLI backends. Loose
        // coupling: SuperAgent doesn't know SuperAICore exists; we
        // just implement its SPIs when the operator opts in.
        $this->app->singleton(\SuperAICore\Modes\SquadDispatcherBridge::class, function ($app) {
            return new \SuperAICore\Modes\SquadDispatcherBridge(
                dispatcher: $app->make(\SuperAICore\Modes\CrossLayerDispatcher::class),
                modeRouter: $app->make(\SuperAICore\Modes\CliModeRouter::class),
            );
        });

        // Workspace-shared plugin registry. Reads/writes
        // `<base_path>/.superaicore/workspace-plugins.json` so a team
        // can check the manifest into the repo and onboard new hires
        // with `git clone` instead of a per-machine doc.
        $this->app->singleton(\SuperAICore\Plugins\WorkspacePluginRegistry::class, function ($app) {
            return new \SuperAICore\Plugins\WorkspacePluginRegistry(
                workspaceRoot: function_exists('base_path') ? base_path() : getcwd(),
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'super-ai-core');

        // Cross-layer mode wiring — after register() so DI is settled.
        // setModes() lets the dispatcher recurse on `auto` / `smart` /
        // `squad` provider tags without each mode having to know about
        // the other two at construction time.
        $this->app->resolving(\SuperAICore\Modes\CrossLayerDispatcher::class, function ($dispatcher, $app) {
            $dispatcher->setModes(
                $app->make(\SuperAICore\Modes\CliAutoMode::class),
                $app->make(\SuperAICore\Modes\CliSmartOrchestrator::class),
                $app->make(\SuperAICore\Modes\CliSquadOrchestrator::class),
            );
        });

        // Reverse bridge install — defaults ON, gated by config so an
        // operator can opt out without rebuilding the container.
        if ((bool) config('super-ai-core.modes.bridge_sdk_squad', true)) {
            try {
                $this->app->make(\SuperAICore\Modes\SquadDispatcherBridge::class)->install();
            } catch (\Throwable) {
                // Container resolution failure (early boot, missing SDK
                // class) — silent degrade is correct here; the bridge
                // is purely additive.
            }
        }

        // P2-9 — Apply `super-ai-core.agents.max_depth` to SDK's static
        // AgentDepthGuard so every sub-agent spawn under this host honors
        // the same recursion cap. The SDK reads either the env var or
        // `superagent.agents.max_depth`; we don't own the latter, so
        // forward via setMax() when the host has an explicit value.
        if (class_exists(\SuperAgent\Swarm\AgentDepthGuard::class)) {
            $cap = config('super-ai-core.agents.max_depth');
            if (is_int($cap) && $cap > 0) {
                \SuperAgent\Swarm\AgentDepthGuard::setMax($cap);
            }
        }

        // Register artisan commands. Historically these lived only on the
        // standalone `bin/superaicore` console — keep the set narrow here so
        // we don't leak every internal Symfony command into `php artisan`.
        if ($this->app->runningInConsole()) {
            $this->commands([
                \SuperAICore\Console\Commands\ClaudeMcpSyncCommand::class,
                \SuperAICore\Console\Commands\McpSyncBackendsCommand::class,
                \SuperAICore\Console\Commands\ApiStatusCommand::class,
                \SuperAICore\Console\Commands\KimiSyncCommand::class,

                // Plugin marketplace install (0.9.1+ — borrowed from
                // ruflo's `.claude-plugin/marketplace.json` schema).
                // Copies a plugin tree into the user-scope Claude
                // plugins dir so SkillRegistry / AgentRegistry pick it
                // up immediately. Idempotent + drift-detecting.
                \SuperAICore\Console\Commands\PluginsInstallCommand::class,

                // Multi-engine hooks fanout (0.9.1+ — borrowed from
                // ruflo's `.claude-plugin/hooks/hooks.json` pattern).
                // One source manifest → Claude `.claude/settings.json`
                // + Copilot `~/.copilot/config.json`. Sibling of the
                // Copilot-only `copilot:sync-hooks` (still available on
                // the standalone `bin/superaicore` console).
                \SuperAICore\Console\Commands\HooksSyncCommand::class,

                // 0.9.0 — file-driven provider creation for CI / container
                // bootstrap; secret-safe via stdin or env-var reference.
                \SuperAICore\Console\Commands\ProviderAddCommand::class,
                // 0.9.0 — multi-account quick swap (jcode `/account` style),
                // also auto-fired by SuperAgentBackend on QuotaExceeded when
                // super-ai-core.auto_rotate is enabled.
                \SuperAICore\Console\Commands\ProviderRotateCommand::class,

                // 1.0+ — magic-trace-style Dispatcher trace ring dump.
                // Writes the in-memory ring of llm/tool/cache/provider
                // events to a Chrome Trace Event JSON file viewable in
                // chrome://tracing, ui.perfetto.dev, or the bundled
                // trace-viewer.html template. See SuperTeam
                // .claude/refs/ref-trace-format.md.
                \SuperAICore\Console\Commands\DispatcherDumpTraceCommand::class,
                // 0.9.2 — inspect TaskRunner reliability fallback policy,
                // workload profiles, limits, cooldowns, and chain resolution.
                \SuperAICore\Console\Commands\FallbackPolicyCommand::class,

                // Skill telemetry / ranking / evolution (0.8.1+ — borrowed
                // from OpenSpace's skill_engine; FIX-mode only, never
                // auto-applied. See SkillEvolver.php docblock.)
                \SuperAICore\Console\Commands\SkillTrackStartCommand::class,
                \SuperAICore\Console\Commands\SkillTrackStopCommand::class,
                \SuperAICore\Console\Commands\SkillStatsCommand::class,
                \SuperAICore\Console\Commands\SkillRankCommand::class,
                \SuperAICore\Console\Commands\SkillEvolveCommand::class,
                \SuperAICore\Console\Commands\SkillCandidatesCommand::class,
                // P0-3 — shadow-git snapshot retention. Schedule via
                // app/Console/Kernel.php in the host app:
                //   $schedule->command('super-ai-core:snapshot-prune')->daily();
                \SuperAICore\Console\Commands\SnapshotPruneCommand::class,
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
