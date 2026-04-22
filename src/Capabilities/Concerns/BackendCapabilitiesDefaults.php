<?php

namespace SuperAICore\Capabilities\Concerns;

use SuperAICore\AgentSpawn\SpawnPlan;

/**
 * Drop-in defaults for {@see \SuperAICore\Contracts\BackendCapabilities}
 * methods added after Phase E's contract freeze.
 *
 * Why this exists: PHP interfaces can't carry default implementations,
 * so any new method added to `BackendCapabilities` post-freeze
 * technically breaks hosts that implement their own custom Capabilities.
 * To avoid that breakage, hosts that implement the interface should
 * `use BackendCapabilitiesDefaults;` — they'll inherit empty / no-op
 * defaults for any future-added method without having to write their
 * own. Bundled `*Capabilities` classes do NOT use the trait (they
 * provide real implementations); it exists exclusively for downstream
 * extension safety.
 *
 * As of Phase E this trait covers:
 *   - `spawnPreamble()` — returns ''
 *   - `consolidationPrompt()` — returns ''
 *
 * If SuperAICore adds another `BackendCapabilities` method in a future
 * release, the maintainer is expected to add a no-op default for it
 * here in the same release that adds the method, so hosts that adopted
 * this trait get the new method "for free" with safe semantics.
 */
trait BackendCapabilitiesDefaults
{
    public function spawnPreamble(string $outputDir): string
    {
        return '';
    }

    public function consolidationPrompt(SpawnPlan $plan, array $report, string $outputDir): string
    {
        return '';
    }
}
