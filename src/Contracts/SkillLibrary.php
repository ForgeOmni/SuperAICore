<?php

namespace SuperAICore\Contracts;

/**
 * A host-supplied catalog of skills + agents that should be bridged into
 * every CLI backend's native surface (skill dirs, instruction files,
 * prompt preambles).
 *
 * SuperAICore stays generic: it knows WHERE each CLI keeps its skills and
 * HOW to install them safely + WHEN to re-sync, but it does not know what
 * a "SuperTeam skill" is. The host implements this contract (e.g.
 * SuperTeam's `SuperTeamSkillLibrary` wrapping its `.claude/skills` +
 * `.claude/agents` library) and binds it in the container:
 *
 *     $this->app->singleton(SkillLibrary::class, SuperTeamSkillLibrary::class);
 *
 * {@see \SuperAICore\Services\CliSkillBridge} resolves the bound instance
 * (if any) and fans the catalog out to each backend. When nothing is
 * bound, the bridge is a silent no-op — SuperAICore carries no host
 * assumptions.
 */
interface SkillLibrary
{
    /**
     * The skills to bridge.
     *
     * @return array<int,array{name:string,description:string}>
     *         `name` is the canonical skill id (e.g. "research"); the
     *         per-backend wrapper name is derived by the bridge.
     */
    public function skills(): array;

    /**
     * The agent roles available for fan-out (used in instruction digests
     * and agent-loader hints). May be empty.
     *
     * @return array<int,array{name:string,description?:string}>
     */
    public function agents(): array;

    /**
     * Full SKILL.md content to drop into a backend's NATIVE skill dir
     * (codex/gemini/grok/cursor/qwen). Typically a thin wrapper whose body
     * shells out to the host's loader command so the source stays the
     * single source of truth and never gets duplicated.
     *
     * `$backend` lets the host tune the wrapper per CLI (front-matter
     * conventions differ slightly). Return '' to skip this skill for this
     * backend.
     */
    public function skillWrapper(string $backend, string $skillName): string;

    /**
     * Markdown digest written into an instruction file for backends that
     * have NO native skill dir (copilot/kimi/kiro auto-load an AGENTS.md
     * / custom-instructions file). Should tell the model how to load any
     * skill/agent on demand (the loader commands) and list what's
     * available. Return '' to skip instruction-file bridging.
     */
    public function instructionsDigest(string $backend): string;

    /**
     * Stable fingerprint of the whole library (skill set + agent set +
     * wrapper-affecting config). The bridge stores it per backend and
     * re-syncs only when it changes — this is what makes lazy
     * on-dispatch sync cheap. Any change to the library MUST change this.
     */
    public function fingerprint(): string;
}
