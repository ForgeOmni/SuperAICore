<?php

declare(strict_types=1);

namespace SuperAICore\Services\OAuth;

/**
 * Registry of per-backend OAuth refreshers. The command resolves the
 * right refresher for an account by backend name; missing backends
 * return null and the command falls back to CLI global refresh.
 */
final class OAuthRefresherRegistry
{
    /** @var array<string, OAuthRefresherInterface> */
    private array $refreshers = [];

    public function __construct()
    {
        $this->register(new Refreshers\ClaudeRefresher());
        $this->register(new Refreshers\CodexRefresher());
        $this->register(new Refreshers\CopilotRefresher());
        $this->register(new Refreshers\KiroRefresher());
    }

    public function register(OAuthRefresherInterface $refresher): void
    {
        $this->refreshers[$refresher->backendName()] = $refresher;
    }

    public function for(string $backendName): ?OAuthRefresherInterface
    {
        return $this->refreshers[$backendName] ?? null;
    }
}
