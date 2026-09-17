<?php

declare(strict_types=1);

namespace SuperAICore\Tests\Feature;

use SuperAICore\Contracts\Backend;
use SuperAICore\Contracts\QuotaPolicy;
use SuperAICore\Services\BackendRegistry;
use SuperAICore\Services\CostCalculator;
use SuperAICore\Services\Dispatcher;
use SuperAICore\Support\QuotaDecision;
use SuperAICore\Tests\TestCase;

/**
 * The spend gate (1.2.0).
 *
 * The usage ledger is written after a call, so without this the first sign of
 * a runaway loop or a tenant past its plan is the invoice.
 */
class QuotaPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->runPackageMigrations();
    }

    public function test_no_policy_bound_means_nothing_changes(): void
    {
        $result = $this->dispatcher()->dispatch(['prompt' => 'ping', 'backend' => 'stub']);

        $this->assertSame('ok', $result['text']);
    }

    public function test_a_denial_stops_the_call_before_the_backend_is_touched(): void
    {
        $backend = $this->stubBackend();
        $policy = $this->policy(fn () => QuotaDecision::deny('Daily cap reached.', 'daily_cap'));

        $result = $this->dispatcher($backend, $policy)->dispatch([
            'prompt' => 'ping',
            'backend' => 'stub',
            'scopes' => [['business', 42]],
        ]);

        $this->assertTrue($result['quota_denied']);
        $this->assertSame('daily_cap', $result['error_code']);
        $this->assertSame('Daily cap reached.', $result['error']);
        $this->assertSame(0, $backend->calls, 'the provider must not have been called');
    }

    public function test_the_policy_is_told_which_scope_is_spending(): void
    {
        $seen = [];
        $policy = $this->policy(function (string $scope, ?int $scopeId, array $context) use (&$seen) {
            $seen = [$scope, $scopeId, $context['backend'], $context['task_type']];

            return QuotaDecision::allow();
        });

        $this->dispatcher($this->stubBackend(), $policy)->dispatch([
            'prompt' => 'ping',
            'backend' => 'stub',
            'task_type' => 'summarise',
            'scopes' => [['business', 42], ['global', null]],
        ]);

        $this->assertSame(['business', 42, 'stub', 'summarise'], $seen);
    }

    public function test_a_policy_that_throws_does_not_take_the_dispatcher_down(): void
    {
        // A broken quota implementation should not stop every call in the
        // host; the usage ledger still records what was spent.
        $policy = $this->policy(fn () => throw new \RuntimeException('quota service down'));

        $result = $this->dispatcher($this->stubBackend(), $policy)->dispatch([
            'prompt' => 'ping',
            'backend' => 'stub',
        ]);

        $this->assertSame('ok', $result['text']);
    }

    private function dispatcher(?object $backend = null, ?QuotaPolicy $policy = null): Dispatcher
    {
        $registry = new BackendRegistry(null, []);
        $registry->register($backend ?? $this->stubBackend());

        return new Dispatcher($registry, new CostCalculator(), null, null, null, null, null, $policy);
    }

    private function policy(callable $decide): QuotaPolicy
    {
        return new class($decide) implements QuotaPolicy {
            public function __construct(private $decide)
            {
            }

            public function allows(string $scope, ?int $scopeId, array $context): QuotaDecision
            {
                return ($this->decide)($scope, $scopeId, $context);
            }
        };
    }

    private function stubBackend(): object
    {
        return new class implements Backend {
            public int $calls = 0;

            public function name(): string
            {
                return 'stub';
            }

            public function isAvailable(array $providerConfig = []): bool
            {
                return true;
            }

            public function generate(array $options): ?array
            {
                $this->calls++;

                return [
                    'text' => 'ok',
                    'model' => 'claude-sonnet-5',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                    'stop_reason' => null,
                ];
            }
        };
    }
}
