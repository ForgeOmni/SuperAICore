<?php

declare(strict_types=1);

namespace SuperAICore\Support;

use SuperAICore\Runner\TaskRunner;
use SuperAICore\Services\McpManager;
use SuperAICore\Tracing\TraceCollector;

/**
 * Process-wide state, and how to leave none of it behind.
 *
 * This package was written for a host with one workspace: statics cache what
 * the machine looks like and what just happened, and the process ends when the
 * developer is done. A queue worker serving many tenants ends when it is
 * restarted, and until then the statics keep whatever they learned from the
 * last tenant's run.
 *
 * Two kinds, and only one is a problem:
 *
 *   **Machine facts** — which CLIs are installed, which models each supports,
 *   the system tool catalogue. The same for every tenant, expensive to probe
 *   again, and holding nobody's data. Deliberately kept.
 *
 *   **Accumulated** — provider cooldowns learned from one tenant's failures,
 *   the trace ring buffer of the last run, an MCP project root pointing at one
 *   tenant's workspace. This is what {@see resetPerTenant()} clears.
 *
 *     RuntimeState::resetPerTenant();
 *     $result = $dispatcher->dispatch($options);
 *
 * Never called automatically: a workstation would pay for it on every dispatch
 * to solve a problem it does not have, and only the host knows where one
 * tenant's work ends.
 *
 * @since 1.2.0
 */
final class RuntimeState
{
    /** Clear everything that accumulated about one tenant's work. */
    public static function resetPerTenant(): void
    {
        // A provider taken out of rotation by one tenant's bad key would
        // otherwise stay out for the next tenant, whose key was fine.
        TaskRunner::resetCooldowns();

        // Diagnostics for the run that just ended.
        TraceCollector::setInstance(null);

        // Points at one tenant's workspace.
        McpManager::setProjectRootOverride(null);
    }

    /**
     * What {@see resetPerTenant()} clears and what it keeps — the inventory a
     * host can assert against when this package adds a static.
     *
     * @return array{cleared: list<string>, kept: array<string,string>}
     */
    public static function inventory(): array
    {
        return [
            'cleared' => [
                TaskRunner::class . '::$cooldowns',
                TaskRunner::class . '::$cooldownFailures',
                TraceCollector::class . '::$instance',
                McpManager::class . '::$projectRootOverride',
            ],
            'kept' => [
                'SuperAICore\Services\CodexModelResolver' => 'What the local Codex CLI supports.',
                'SuperAICore\Services\KiroModelResolver' => 'What the local Kiro CLI supports.',
                'SuperAICore\Services\CliStatusDetector' => 'Which CLIs are installed on this machine.',
                'SuperAICore\Services\SystemToolManager' => 'System tool catalogue.',
                'SuperAICore\Backends\GeminiCliBackend' => 'Probed CLI help / native skills.',
                'SuperAICore\Backends\KimiCliBackend' => 'Which kimi dialect this machine has.',
                'SuperAICore\Services\McpManager' => 'MCP server catalogue (the registry, not the project root).',
            ],
        ];
    }
}
