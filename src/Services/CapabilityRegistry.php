<?php

namespace SuperAICore\Services;

use SuperAICore\Capabilities\ClaudeCapabilities;
use SuperAICore\Capabilities\CodexCapabilities;
use SuperAICore\Capabilities\CopilotCapabilities;
use SuperAICore\Capabilities\GeminiCapabilities;
use SuperAICore\Capabilities\SuperAgentCapabilities;
use SuperAICore\Contracts\BackendCapabilities;
use SuperAICore\Models\AiProvider;

/**
 * Look up BackendCapabilities by backend key. Registered as a singleton
 * by SuperAICoreServiceProvider. Host apps and aicore internals resolve
 * it via the container:
 *
 *     app(CapabilityRegistry::class)->for('gemini')->transformPrompt($p)
 */
class CapabilityRegistry
{
    /** @var array<string,BackendCapabilities> */
    protected array $caps = [];

    public function __construct()
    {
        $this->register(new ClaudeCapabilities());
        $this->register(new CodexCapabilities());
        $this->register(new GeminiCapabilities());
        $this->register(new CopilotCapabilities());
        $this->register(new SuperAgentCapabilities());
    }

    public function register(BackendCapabilities $cap): void
    {
        $this->caps[$cap->key()] = $cap;
    }

    /**
     * Resolve capabilities for a backend key. Falls back to Claude's
     * capabilities (the canonical tool vocabulary) when the key is unknown,
     * so callers never get a null.
     */
    public function for(string $backend): BackendCapabilities
    {
        return $this->caps[$backend] ?? $this->caps[AiProvider::BACKEND_CLAUDE];
    }

    /** @return array<string,BackendCapabilities> */
    public function all(): array
    {
        return $this->caps;
    }
}
