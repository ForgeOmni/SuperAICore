<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Support\FailureClassifier;

final class FailureClassifierTest extends TestCase
{
    public function test_classifies_each_default_class(): void
    {
        $cases = [
            'quota' => 'You exceeded your current quota',
            'rate_limit' => 'HTTP 429 Too Many Requests',
            'auth' => 'Not signed in — login required',
            'tool_policy' => 'Bash permission denied by policy',
            'validation' => 'invalid prompt: missing required argument',
            'network' => 'curl: connection refused',
        ];
        foreach ($cases as $expected => $haystack) {
            $this->assertSame(
                $expected,
                FailureClassifier::classify($haystack, FailureClassifier::DEFAULT_CLASSES)['class'],
                "haystack: {$haystack}",
            );
        }
    }

    public function test_unmatched_runtime_error_returns_null_class(): void
    {
        $result = FailureClassifier::classify('segmentation fault (core dumped)', FailureClassifier::DEFAULT_CLASSES);
        $this->assertNull($result['class']);
    }

    public function test_retryable_covers_transient_classes_only(): void
    {
        foreach (['quota', 'rate_limit', 'auth', 'network'] as $class) {
            $this->assertTrue(FailureClassifier::isRetryable($class, FailureClassifier::RETRYABLE_CLASSES));
        }
        foreach (['tool_policy', 'validation', null] as $class) {
            $this->assertFalse(FailureClassifier::isRetryable($class, FailureClassifier::RETRYABLE_CLASSES));
        }
    }
}
