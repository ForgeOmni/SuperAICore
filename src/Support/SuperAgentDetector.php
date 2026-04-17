<?php

namespace SuperAICore\Support;

/**
 * Single source of truth for "is the forgeomni/superagent SDK installed?".
 *
 * When the SDK is absent the SuperAgent backend is hidden everywhere:
 * registry, validation rules, UI cards, and provider backend dropdowns.
 */
class SuperAgentDetector
{
    public static function isAvailable(): bool
    {
        return class_exists(\SuperAgent\Agent::class);
    }
}
