<?php

declare(strict_types=1);

namespace SuperAICore\Services;

use SuperAgent\Memory\AdHocMemoryProvider;

/**
 * Per-process pool of SDK 0.9.8 `AdHocMemoryProvider` instances keyed
 * by session/thread id. Each thread gets its own provider so adding a
 * "for the next turn" fact in chat A doesn't leak into chat B.
 *
 * UI integration: the SuperAICore chat page exposes a small "Inject
 * fact for next turn" textarea. The host controller calls
 * `forSession($id)->push($text, $ttlSeconds)`; on the next dispatch
 * the host wires the provider into the agent's Memory chain (or the
 * SuperAgentBackend reads it off this registry and renders the inbox
 * block ahead of the prompt — depending on where the host wires it in).
 *
 * Memory is process-local by design — entries die on shutdown. Durable
 * facts belong in `MEMORY.md` / `BuiltinMemoryProvider`, not here.
 */
final class AdHocMemoryRegistry
{
    /** @var array<string, AdHocMemoryProvider> */
    private array $providers = [];

    public function forSession(string $sessionId): ?AdHocMemoryProvider
    {
        if (!class_exists(AdHocMemoryProvider::class)) return null;
        return $this->providers[$sessionId] ??= new AdHocMemoryProvider();
    }

    /**
     * Convenience: push a note onto the named session's provider in
     * one call. Returns the provider-assigned id or null when the SDK
     * class isn't available.
     */
    public function push(string $sessionId, string $content, int $ttlSeconds = 0, bool $untrusted = true, string $kind = 'note'): ?int
    {
        $p = $this->forSession($sessionId);
        if ($p === null) return null;
        return $p->push($content, $ttlSeconds, $untrusted, $kind);
    }

    public function clear(string $sessionId): void
    {
        if (isset($this->providers[$sessionId])) {
            $this->providers[$sessionId]->clear();
        }
    }

    public function forget(string $sessionId): void
    {
        unset($this->providers[$sessionId]);
    }
}
