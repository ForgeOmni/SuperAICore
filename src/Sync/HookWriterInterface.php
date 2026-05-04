<?php

namespace SuperAICore\Sync;

/**
 * One hook-writer per CLI engine. The fanout service iterates registered
 * writers; each one decides whether it can render the host's Claude-style
 * hooks block into its target engine's native config shape.
 *
 * Engines that don't have a hook concept (Codex / Gemini / Kimi as of
 * 2026-05) simply aren't registered. Adding a new engine = implementing
 * this interface and registering the writer in a ServiceProvider.
 */
interface HookWriterInterface
{
    /** Stable key matching the engine identifier in EngineCatalog (e.g. 'claude', 'copilot'). */
    public function engineKey(): string;

    /**
     * True when the target config dir / file is reachable. Returning false
     * makes the fanout service emit `status: unavailable` for this engine
     * without attempting to write — so a host without Copilot installed
     * doesn't fail the whole sync.
     */
    public function isAvailable(): bool;

    /**
     * Apply the given Claude-style hooks block to this engine's config.
     * Pass `null` (or `[]`) to request removal of any previously-written block.
     *
     * @param  array<string, array<int, array<string, mixed>>>|null $hooks
     * @return array{status:string, path:string}
     */
    public function sync(?array $hooks): array;
}
