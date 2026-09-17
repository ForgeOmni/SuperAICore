<?php

declare(strict_types=1);

namespace SuperAICore\Support;

/**
 * The posture this package is configured for.
 *
 * `workstation` is what it has always been: a developer's machine, one person,
 * every CLI engine on the PATH worth trying, and an admin UI behind the host's
 * own `auth` middleware. Every default in `config/super-ai-core.php` was chosen
 * for that, and they are the right defaults for it.
 *
 * `embedded` is the posture of a multi-tenant SaaS host that wants the
 * dispatcher, the provider registry and the usage ledger — and none of the
 * rest. Under it the package registers no routes, spawns no CLI process,
 * opens no PTY, shares no session and writes no local snapshots, because in
 * that setting each of those is either a privilege boundary the host defines
 * itself or a thing a web node should never do.
 *
 * A profile only supplies **defaults**. Every key it touches is still an
 * `env()` read, so a host that sets the variable gets what it asked for in
 * either profile — the profile decides what happens when nobody said.
 *
 * @since 1.2.0
 */
final class Profile
{
    public const WORKSTATION = 'workstation';
    public const EMBEDDED = 'embedded';

    /**
     * Capabilities a profile answers for. Read by `config/super-ai-core.php`
     * as the default of the matching `env()` call.
     */
    public const CAPABILITIES = [
        // Spawning a coding CLI through symfony/process.
        'cli_backends',
        // The package's own web routes and admin UI.
        'routes',
        // Long-lived PTY sessions, session sharing, process monitor:
        // developer tooling with an inbound surface.
        'interactive_tools',
        // Local git snapshots and worktree reverts.
        'workspace_writes',
        // Paths that are still settling.
        'experimental',
    ];

    private const DEFAULTS = [
        self::WORKSTATION => [
            'cli_backends' => true,
            'routes' => true,
            'interactive_tools' => true,
            'workspace_writes' => true,
            'experimental' => true,
        ],
        self::EMBEDDED => [
            'cli_backends' => false,
            'routes' => false,
            'interactive_tools' => false,
            'workspace_writes' => false,
            'experimental' => false,
        ],
    ];

    /**
     * The active profile: `AI_CORE_PROFILE`, then `super-ai-core.profile`,
     * then workstation.
     *
     * Read from the environment first because this is consulted while the
     * config file is being built, when `config()` cannot answer yet.
     */
    public static function current(): string
    {
        $value = getenv('AI_CORE_PROFILE');

        if ($value === false || $value === '') {
            $value = ConfigValue::get('super-ai-core.profile', self::WORKSTATION);
        }

        $value = strtolower(trim((string) $value));

        return $value === self::EMBEDDED ? self::EMBEDDED : self::WORKSTATION;
    }

    /** Whether the active profile allows a capability by default. */
    public static function allows(string $capability): bool
    {
        return self::allowsIn(self::current(), $capability);
    }

    public static function allowsIn(string $profile, string $capability): bool
    {
        $profile = $profile === self::EMBEDDED ? self::EMBEDDED : self::WORKSTATION;

        if (! in_array($capability, self::CAPABILITIES, true)) {
            throw new \InvalidArgumentException("Unknown profile capability '{$capability}'.");
        }

        return self::DEFAULTS[$profile][$capability];
    }

    public static function isEmbedded(): bool
    {
        return self::current() === self::EMBEDDED;
    }
}
