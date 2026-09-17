<?php

declare(strict_types=1);

namespace SuperAICore\Support;

/**
 * Which parts of this package's web surface are registered.
 *
 * `route.enabled` was one switch over 81 routes, and the default middleware
 * is `['web', 'auth']` — which in a product means *any signed-in account*,
 * including a driver, a customer or a contractor, can reach the provider
 * registry, the cost dashboard, the process monitor and an
 * OpenAI-compatible proxy that dispatches on the host's credentials. A host
 * cannot narrow that from outside the package, and it should not have to
 * choose between all of it and none of it.
 *
 * Each group below is now a separate switch, defaulting to what the file
 * did before, so nothing changes for a host that sets none of them. The
 * `embedded` profile turns them all off — see {@see Profile}.
 *
 * `route.gate` is the other half: when set to an ability name, every group
 * additionally carries `can:<ability>`, so the host authorises the package's
 * UI with its own policy rather than with "is logged in".
 *
 * @since 1.2.0
 */
final class RouteGroups
{
    /** group => what it exposes, for the config comments and for tests. */
    public const GROUPS = [
        'locale' => 'Locale switcher for the package UI.',
        'agents' => 'Agent catalogue browser (reads .claude/agents).',
        'providers' => 'Provider registry: credentials, activation, CLI status, model lists.',
        'services' => 'Capabilities, AI services and routing rules.',
        'integrations' => 'Integration installer: system tools, CLI installs, OAuth starts.',
        'usage' => 'Usage log UI.',
        'costs' => 'Cost dashboard and savings view.',
        'processes' => 'Process monitor, kill, log tail, and the HITL question queue.',
        'openai_proxy' => 'OpenAI-compatible /v1 endpoints that dispatch on this host.',
        'routing_combos' => 'Named routing combos.',
        'sessions' => 'Session tree, fork and switch.',
        'revert' => 'Worktree revert to a pre-dispatch snapshot.',
        'pty' => 'Long-lived PTY shell sessions.',
        'share' => 'Session sharing links.',
        'usage_api' => 'Headless usage API.',
        'traces' => 'Dispatcher trace dumps.',
        'resume' => 'Cross-harness session resume.',
    ];

    public static function enabled(string $group): bool
    {
        if (! isset(self::GROUPS[$group])) {
            throw new \InvalidArgumentException("Unknown route group '{$group}'.");
        }

        $configured = ConfigValue::get("super-ai-core.route.groups.{$group}");

        if ($configured === null) {
            // No opinion recorded: follow the profile, which is what the
            // single `route.enabled` switch used to decide alone.
            return Profile::allows('routes');
        }

        return (bool) $configured;
    }

    /**
     * Middleware for the package's route group: the configured stack, plus
     * `can:<ability>` when the host named a gate.
     *
     * @return list<string>
     */
    public static function middleware(): array
    {
        $middleware = ConfigValue::get('super-ai-core.route.middleware', ['web', 'auth']);
        $middleware = is_array($middleware) ? array_values($middleware) : ['web', 'auth'];

        $gate = ConfigValue::get('super-ai-core.route.gate');

        if (is_string($gate) && $gate !== '') {
            $middleware[] = 'can:' . $gate;
        }

        return $middleware;
    }
}
