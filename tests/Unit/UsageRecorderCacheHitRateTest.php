<?php

namespace SuperAICore\Tests\Unit;

use SuperAICore\Contracts\UsageRepository;
use SuperAICore\Services\CostCalculator;
use SuperAICore\Services\UsageRecorder;
use SuperAICore\Services\UsageTracker;
use SuperAICore\Tests\TestCase;

/**
 * cache_hit_rate is computed at record() time so dashboards can groupby
 * model / backend / day and average the rate without re-deriving the
 * denominator on every read. The denominator is the GROSS prompt
 * (uncached input + cache reads); the input_tokens we receive has
 * already had cached_tokens subtracted by the SDK, so we add them back.
 */
class UsageRecorderCacheHitRateTest extends TestCase
{
    public function test_cache_hit_rate_recorded_when_cache_read_present(): void
    {
        $captured = $this->captureRecord([
            'backend'           => 'superagent',
            'model'             => 'deepseek-v4-pro',
            'input_tokens'      => 200,    // uncached slice
            'output_tokens'     => 50,
            'cache_read_tokens' => 800,    // gross = 1000; rate = 0.8
        ]);

        $this->assertSame(800, $captured['metadata']['cache_read_tokens']);
        $this->assertSame(0.8, $captured['metadata']['cache_hit_rate']);
    }

    public function test_cache_hit_rate_accepts_legacy_alias(): void
    {
        // DeepSeek V3 / R1 wires emit `cache_hit_tokens` (legacy alias).
        // The recorder accepts both to spare hosts a translation layer.
        $captured = $this->captureRecord([
            'backend'         => 'superagent',
            'model'           => 'deepseek-reasoner',
            'input_tokens'    => 100,
            'output_tokens'   => 25,
            'cache_hit_tokens' => 900,   // gross = 1000; rate = 0.9
        ]);

        $this->assertSame(900, $captured['metadata']['cache_read_tokens']);
        $this->assertSame(0.9, $captured['metadata']['cache_hit_rate']);
    }

    public function test_explicit_zero_cache_read_does_not_shadow_legacy_alias(): void
    {
        // Regression: `??` let an explicit `cache_read_tokens => 0` (as a
        // normalised SDK Usage object emits) mask a non-zero `cache_hit_tokens`
        // raw alias — 0 is not null, so the coalesce never fell through and the
        // cache slice was silently dropped. First NON-ZERO wins.
        $captured = $this->captureRecord([
            'backend'           => 'superagent',
            'model'             => 'deepseek-v4-pro',
            'input_tokens'      => 200,
            'output_tokens'     => 50,
            'cache_read_tokens' => 0,      // explicit zero
            'cache_hit_tokens'  => 800,    // raw provider alias — must win
        ]);

        $this->assertSame(800, $captured['metadata']['cache_read_tokens']);
        $this->assertSame(0.8, $captured['metadata']['cache_hit_rate']);
    }

    public function test_cache_hit_rate_absent_when_no_cache_read(): void
    {
        // No cache slice — don't stamp 0.0 on the metadata, the
        // dashboard should distinguish "no cache activity" from
        // "0% hit rate on a cache-eligible request".
        $captured = $this->captureRecord([
            'backend'        => 'superagent',
            'model'          => 'deepseek-v4-flash',
            'input_tokens'   => 500,
            'output_tokens'  => 100,
        ]);

        $this->assertArrayNotHasKey('cache_hit_rate', $captured['metadata'] ?? []);
        $this->assertArrayNotHasKey('cache_read_tokens', $captured['metadata'] ?? []);
    }

    public function test_cache_hit_rate_zero_input_zero_cache_safe(): void
    {
        // Pathological row (test_connection ping with neither tokens
        // nor cache hits). Don't divide by zero.
        $captured = $this->captureRecord([
            'backend'        => 'superagent',
            'model'          => 'deepseek-v4-flash',
            'input_tokens'   => 0,
            'output_tokens'  => 0,
        ]);

        $this->assertArrayNotHasKey('cache_hit_rate', $captured['metadata'] ?? []);
    }

    /**
     * Capture the row that UsageRecorder forwards to UsageTracker so
     * we can assert on the metadata payload without standing up a real
     * repository.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function captureRecord(array $data): array
    {
        $repo = new CapturingUsageRepository();
        $recorder = new UsageRecorder(new UsageTracker($repo), new CostCalculator());
        $recorder->record($data);
        return $repo->captured;
    }
}

/**
 * Minimal in-memory UsageRepository that just stashes the most-recent
 * record() payload. All other methods are stubs.
 */
class CapturingUsageRepository implements UsageRepository
{
    public array $captured = [];
    public function record(array $data): int { $this->captured = $data; return 1; }
    public function summary(?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): array { return []; }
    public function recent(int $limit = 50, array $filters = []): array { return []; }
    public function purgeOlderThan(\DateTimeInterface $cutoff): int { return 0; }
    public function all(?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): array { return []; }
    public function findLatestForSession(string $sessionId, array $backends): ?array { return null; }
}
