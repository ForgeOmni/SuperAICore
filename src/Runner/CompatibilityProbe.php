<?php

namespace SuperAICore\Runner;

use SuperAICore\Contracts\BackendCapabilities;
use SuperAICore\Registry\Skill;
use SuperAICore\Translator\SkillBodyTranslator;

/**
 * Static compatibility check for a skill against a target backend.
 *
 * Reads the skill body and `allowed-tools` frontmatter, answers:
 *   - compatible:    tools referenced are native or mapped 1:1
 *   - degraded:      some tools have no mapping but the skill can likely
 *                    still run (falls back to the canonical name and lets
 *                    the target CLI decide)
 *   - incompatible:  the skill asks for primitives the backend cannot
 *                    express at all (e.g. Agent-tool sub-agent spawn on
 *                    gemini, which has no sub-agent surface)
 */
final class CompatibilityProbe
{
    public const COMPATIBLE   = 'compatible';
    public const DEGRADED     = 'degraded';
    public const INCOMPATIBLE = 'incompatible';

    public function __construct(private readonly BackendCapabilities $target) {}

    /**
     * @return array{status:string, reasons:string[]}
     */
    public function probe(Skill $skill): array
    {
        $reasons = [];
        $status = self::COMPATIBLE;
        $key = $this->target->key();
        $body = $skill->body;

        if ($this->usesTool($body, 'Agent') && !$this->target->supportsSubAgents()) {
            $reasons[] = "uses Agent tool, not available on {$key}";
            $status = self::INCOMPATIBLE;
        }

        // Non-empty map = backend remaps canonical names; anything canonical
        // not present in the map is therefore a gap on this backend.
        // Empty map = canonical names ARE native (per BackendCapabilities contract).
        $map = $this->target->toolNameMap();
        if ($map) {
            foreach (SkillBodyTranslator::CANONICAL_TOOLS as $tool) {
                if ($tool === 'Agent') {
                    continue;
                }
                if (isset($map[$tool])) {
                    continue;
                }
                if ($this->usesTool($body, $tool)) {
                    $reasons[] = "tool '{$tool}' has no mapping on {$key}";
                    if ($status !== self::INCOMPATIBLE) {
                        $status = self::DEGRADED;
                    }
                }
            }
        }

        return ['status' => $status, 'reasons' => $reasons];
    }

    private function usesTool(string $body, string $tool): bool
    {
        return (bool) preg_match('/\b' . preg_quote($tool, '/') . '\b/', $body);
    }
}
