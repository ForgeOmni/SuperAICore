<?php

namespace SuperAICore\Support;

/**
 * Shared failure-text classifier for dispatch paths (ai-dispatch parity).
 *
 * Same taxonomy the TaskRunner fallback uses — driven by
 * `super-ai-core.task_fallback.failure_classes` with the identical
 * hard-coded default so standalone (non-Laravel) CLI runs classify the
 * same way a Laravel host does.
 *
 * Classes: quota · rate_limit · auth · tool_policy · validation · network.
 * Anything unmatched is null → treated as a runtime failure, which fails
 * closed (no candidate fall-through) so fallback never hides real bugs.
 */
final class FailureClassifier
{
    public const DEFAULT_CLASSES = [
        'quota' => ['quota', 'quota_exceeded', 'insufficient_quota', 'usage_not_included', 'billing', 'budget'],
        'rate_limit' => ['rate limit', 'rate_limit', 'too many requests', '429', 'limit reached'],
        'auth' => ['unauthorized', 'forbidden', 'invalid api key', 'not signed in', 'login required'],
        'tool_policy' => ['permission denied', 'policy', 'not allowed', 'approval required'],
        'validation' => ['invalid prompt', 'missing required', 'validation'],
        'network' => ['timeout', 'connection refused', 'could not resolve', 'network'],
    ];

    /**
     * Failure classes that are safe to fall through to the next routing
     * candidate: the failure says "this engine can't take the call right
     * now", not "the task itself is broken".
     */
    public const RETRYABLE_CLASSES = ['quota', 'rate_limit', 'auth', 'network'];

    /**
     * @param  array<string, string[]>|null $classes  pattern table override;
     *         null loads `super-ai-core.task_fallback.failure_classes` when
     *         a Laravel config() is available, else DEFAULT_CLASSES.
     * @return array{class: ?string, matched_pattern: ?string}
     */
    public static function classify(string $haystack, ?array $classes = null): array
    {
        $haystack = mb_strtolower($haystack);
        if (trim($haystack) === '') {
            return ['class' => null, 'matched_pattern' => null];
        }

        $classes ??= ConfigValue::get('super-ai-core.task_fallback.failure_classes') ?? self::DEFAULT_CLASSES;

        foreach ($classes as $class => $patterns) {
            foreach ((array) $patterns as $pattern) {
                $pattern = mb_strtolower(trim((string) $pattern));
                if ($pattern !== '' && str_contains($haystack, $pattern)) {
                    return ['class' => (string) $class, 'matched_pattern' => $pattern];
                }
            }
        }

        return ['class' => null, 'matched_pattern' => null];
    }

    /** @param string[]|null $retryOn class-name allowlist override */
    public static function isRetryable(?string $class, ?array $retryOn = null): bool
    {
        if ($class === null) return false;
        $retryOn ??= ConfigValue::get('super-ai-core.dispatch.retry_on_classes') ?? self::RETRYABLE_CLASSES;
        return in_array($class, array_map('strval', $retryOn), true);
    }
}
