<?php

declare(strict_types=1);

namespace SuperAICore\SmartFlow;

/**
 * Content-addressed signatures for resume ("断点续跑，不烧 token"). Each agent
 * call gets a signature derived purely from what the flow author *declared*
 * (label, prompt, schema, role, declared backend/model) — not from runtime
 * results. On a resumed run the engine recomputes signatures in order and reuses
 * cached ledger values for the longest unchanged prefix; the first call whose
 * signature differs (and everything after it) runs live. Same flow + same args +
 * unchanged code ⇒ every signature matches ⇒ a fully cached, zero-cost replay.
 *
 * The flow-level signature additionally folds in the flow name + args so two
 * runs of different flows never collide.
 */
final class FlowSignature
{
    public static function forCall(AgentCall $call): string
    {
        $material = [
            'label' => $call->label,
            'prompt' => self::normalize($call->prompt),
            'schema' => $call->schema,
            'role' => $call->role,
            'backend' => $call->backend,
            'model' => $call->model,
            'system' => self::normalize((string) $call->system),
            'delegation' => $call->delegation?->toArray(),
        ];

        return substr(hash('sha256', self::encode($material)), 0, 16);
    }

    /**
     * @param array<string, mixed> $args
     */
    public static function forFlow(string $flowName, array $args): string
    {
        return substr(hash('sha256', self::encode(['flow' => $flowName, 'args' => $args])), 0, 16);
    }

    private static function normalize(string $text): string
    {
        // Collapse insignificant whitespace so cosmetic edits don't bust the cache.
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private static function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: serialize($value);
    }
}
