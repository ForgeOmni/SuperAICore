<?php

declare(strict_types=1);

namespace SuperAICore\Tests\Feature;

use SuperAICore\Contracts\ScopedUsageRepository;
use SuperAICore\Repositories\EloquentUsageRepository;
use SuperAICore\Tests\TestCase;

/**
 * Usage rows carry the scope whose credentials paid for the call (1.2.0), and
 * can be summed per scope without aggregating over a JSON column.
 */
class ScopedUsageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->runPackageMigrations();
    }

    public function test_the_repository_answers_per_scope(): void
    {
        $repo = new EloquentUsageRepository();

        $repo->record($this->row(['scope' => 'business', 'scope_id' => 42, 'cost_usd' => 1.00]));
        $repo->record($this->row(['scope' => 'business', 'scope_id' => 42, 'cost_usd' => 0.50]));
        $repo->record($this->row(['scope' => 'business', 'scope_id' => 99, 'cost_usd' => 7.00]));
        $repo->record($this->row(['scope' => 'global', 'scope_id' => null, 'cost_usd' => 0.25]));

        $this->assertInstanceOf(ScopedUsageRepository::class, $repo);

        $tenant = $repo->summaryForScope('business', 42);
        $this->assertSame(2, $tenant['total_runs']);
        $this->assertEqualsWithDelta(1.50, $tenant['total_cost_usd'], 0.0001);

        $other = $repo->summaryForScope('business', 99);
        $this->assertEqualsWithDelta(7.00, $other['total_cost_usd'], 0.0001);

        $everyBusiness = $repo->summaryForScope('business');
        $this->assertSame(3, $everyBusiness['total_runs'], 'a null id means every row in the scope');

        $this->assertSame(4, $repo->summary()['total_runs'], 'the unscoped answer is unchanged');
    }

    public function test_rows_for_one_scope_come_back_whole(): void
    {
        $repo = new EloquentUsageRepository();
        $repo->record($this->row(['scope' => 'business', 'scope_id' => 42, 'model' => 'claude-sonnet-5']));
        $repo->record($this->row(['scope' => 'business', 'scope_id' => 99]));

        $rows = $repo->allForScope('business', 42);

        $this->assertCount(1, $rows);
        $this->assertSame('claude-sonnet-5', $rows[0]['model']);
        $this->assertSame(42, (int) $rows[0]['scope_id']);
    }

    public function test_recent_can_be_filtered_by_scope(): void
    {
        $repo = new EloquentUsageRepository();
        $repo->record($this->row(['scope' => 'business', 'scope_id' => 42]));
        $repo->record($this->row(['scope' => 'business', 'scope_id' => 99]));

        $this->assertCount(1, $repo->recent(50, ['scope' => 'business', 'scope_id' => 42]));
        $this->assertCount(2, $repo->recent(50));
    }

    public function test_a_row_without_a_scope_is_still_valid(): void
    {
        // Every row written before 1.2.0 has no scope, and a global dispatch
        // legitimately has no id.
        $repo = new EloquentUsageRepository();
        $id = $repo->record($this->row(['scope' => null, 'scope_id' => null]));

        $this->assertGreaterThan(0, $id);
        $this->assertSame(1, $repo->summary()['total_runs']);
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'backend' => 'anthropic_api',
            'model' => 'claude-sonnet-5',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'cost_usd' => 0.10,
            'duration_ms' => 120,
            'user_id' => 7,
        ], $overrides);
    }
}
