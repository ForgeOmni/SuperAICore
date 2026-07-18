<?php

namespace SuperAICore\Tests\Unit;

use SuperAICore\Services\BackendRegistry;
use SuperAICore\Services\CostCalculator;
use SuperAICore\Services\Dispatcher;
use SuperAICore\Tests\TestCase;

/**
 * The streaming dispatch path returns a NON-NULL failure envelope (non-zero
 * exit_code) on a quota hit, so the null-result cooldown never fired for it.
 * `quotaCooldownReason()` closes that gap — positive-only: it cools the
 * account down when the failure classifies as quota / rate-limit, and never
 * on a generic failure (which would burn accounts on a bad prompt).
 */
class DispatcherQuotaCooldownTest extends TestCase
{
    private function reasonFor(array $result): ?string
    {
        $dispatcher = new class(
            new BackendRegistry(null, []),
            new CostCalculator(['stub' => ['input' => 1.0, 'output' => 1.0]]),
        ) extends Dispatcher {
            public function exposeReason(array $r): ?string
            {
                return $this->quotaCooldownReason($r);
            }
        };

        return $dispatcher->exposeReason($result);
    }

    public function test_successful_envelope_never_cools_down(): void
    {
        $this->assertNull($this->reasonFor(['text' => 'ok', 'exit_code' => 0]));
        // A generate()-shaped envelope carries no exit_code at all.
        $this->assertNull($this->reasonFor(['text' => 'ok']));
    }

    public function test_quota_failure_cools_down_as_quota_exceeded(): void
    {
        $this->assertSame(
            'quota_exceeded',
            $this->reasonFor(['text' => 'Error: quota exceeded for this account', 'exit_code' => 1]),
        );
    }

    public function test_rate_limit_failure_cools_down_as_rate_limited(): void
    {
        $this->assertSame(
            'rate_limited',
            $this->reasonFor(['text' => '', 'error' => 'HTTP 429 too many requests', 'exit_code' => 1]),
        );
    }

    public function test_generic_failure_does_not_cool_down(): void
    {
        // Non-zero exit but no quota/rate-limit signal → account untouched.
        $this->assertNull($this->reasonFor(['text' => 'invalid syntax near line 3', 'exit_code' => 1]));
        // Non-zero exit with no diagnostic text at all → nothing to classify.
        $this->assertNull($this->reasonFor(['text' => '', 'exit_code' => 1]));
    }
}
