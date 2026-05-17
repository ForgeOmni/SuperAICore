<?php

declare(strict_types=1);

namespace SuperAICore\Modes;

/**
 * Bridges SuperAICore's `CrossLayerDispatcher` into SuperAgent SDK's
 * `Squad\SquadDispatcherRegistry` SPI. Optional: when installed, every
 * SDK code path that builds an in-process default squad dispatcher
 * (today: `AutoMode\AutoModeAgent::runSquad`, by extension the bundled
 * `superagent auto --squad` CLI) routes its steps through our
 * cross-layer dispatcher — so SDK-internal squad runs can land on
 * `cli:claude_cli` / `cli:codex_cli` / etc. without per-call config.
 *
 * Loose coupling guarantee:
 *
 *   - SuperAgent does NOT depend on SuperAICore. The SPI is a single
 *     callable slot; SuperAICore is the only known implementer today
 *     but any host (jcode, codex, custom apps) can install its own.
 *   - SuperAICore depends on SuperAgent (it already requires the SDK
 *     as a hard composer dep). The bridge is `class_exists()`-gated
 *     so a host that vendor-pins to a pre-registry SDK release still
 *     boots — install() degrades to a no-op.
 *   - The bridge is opt-in. SuperAICore's ServiceProvider installs it
 *     by default in `boot()`, but operators can disable via
 *     `super-ai-core.modes.bridge_sdk_squad = false` if they want SDK
 *     squad runs to stay self-contained.
 *
 * Idempotency: install() / uninstall() are safe to call repeatedly.
 * Each install() replaces whatever dispatcher was registered before
 * (SDK contract — `set()` is replace-semantics, not stack).
 */
final class SquadDispatcherBridge
{
    private const SQUAD_REGISTRY = '\\SuperAgent\\Squad\\SquadDispatcherRegistry';
    private const MODE_REGISTRY  = '\\SuperAgent\\Modes\\ModeRouterRegistry';

    public function __construct(
        private CrossLayerDispatcher $dispatcher,
        private ?CliModeRouter $modeRouter = null,
    ) {}

    /**
     * Register two SDK extension points at once:
     *
     *   1. **SquadDispatcherRegistry** (since 1.0+) — SDK's default
     *      squad dispatcher; lets `AutoModeAgent::runSquad()` and
     *      friends route through our cross-layer dispatcher.
     *   2. **ModeRouterRegistry** (new) — SDK's cross-mode router;
     *      lets any SDK code path that recurses on a `SubTask.mode`
     *      reach the full CLI + SDK mode set through our router
     *      (which also knows `cli:*` / `sdk:*` leaf tags).
     *
     * Each is `class_exists()`-gated so the bridge degrades to a no-op
     * on SDK builds without one (or both) of the SPIs. Calling
     * `install()` twice is safe — both registries are replace-semantics.
     */
    public function install(): void
    {
        if (class_exists(self::SQUAD_REGISTRY)) {
            $r = self::SQUAD_REGISTRY;
            $r::set($this->dispatcher->squadAdapter());
        }
        if (class_exists(self::MODE_REGISTRY) && $this->modeRouter !== null) {
            $r = self::MODE_REGISTRY;
            $r::set($this->modeRouter);
        }
    }

    /**
     * Drop both registrations. SDK falls back to its own internal
     * defaults. Useful for tests and for hosts that want to disable
     * the bridge mid-process.
     */
    public function uninstall(): void
    {
        if (class_exists(self::SQUAD_REGISTRY)) {
            $r = self::SQUAD_REGISTRY;
            $r::clear();
        }
        if (class_exists(self::MODE_REGISTRY)) {
            $r = self::MODE_REGISTRY;
            $r::clear();
        }
    }

    /**
     * Whether the SDK exposes either SPI on this composer install.
     * Hosts use this to decide whether to mention cross-layer SDK
     * routes in their UI / logs.
     */
    public function isAvailable(): bool
    {
        return class_exists(self::SQUAD_REGISTRY) || class_exists(self::MODE_REGISTRY);
    }
}
