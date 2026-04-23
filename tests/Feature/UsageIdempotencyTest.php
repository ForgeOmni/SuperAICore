<?php

namespace SuperAICore\Tests\Feature;

use Illuminate\Support\Carbon;
use SuperAICore\Contracts\Backend;
use SuperAICore\Models\AiUsageLog;
use SuperAICore\Repositories\EloquentUsageRepository;
use SuperAICore\Services\BackendRegistry;
use SuperAICore\Services\CostCalculator;
use SuperAICore\Services\Dispatcher;
use SuperAICore\Services\UsageTracker;
use SuperAICore\Tests\TestCase;

/**
 * Phase D — `idempotency_key` dedup window.
 *
 * Verifies the migration column + index, the repository's lookup
 * within the configured window, and the Dispatcher's auto-key
 * derivation from `external_label`.
 */
class UsageIdempotencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->runPackageMigrations();
    }

    public function test_migration_creates_column_and_index(): void
    {
        $table = \SuperAICore\Support\TablePrefix::apply('ai_usage_logs');
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn($table, 'idempotency_key'));
    }

    public function test_record_without_key_writes_two_rows(): void
    {
        $repo = new EloquentUsageRepository();
        $base = $this->validRow();

        $id1 = $repo->record($base);
        $id2 = $repo->record($base);

        $this->assertNotSame($id1, $id2, 'no key → no dedup');
        $this->assertSame(2, AiUsageLog::count());
    }

    public function test_record_with_same_key_within_window_returns_existing_id(): void
    {
        $repo = new EloquentUsageRepository();
        $row = $this->validRow(['idempotency_key' => 'task:42']);

        $id1 = $repo->record($row);
        $id2 = $repo->record($row);
        $id3 = $repo->record($row);

        $this->assertSame($id1, $id2);
        $this->assertSame($id1, $id3);
        $this->assertSame(1, AiUsageLog::count(), 'only the first record() should have inserted');
    }

    public function test_distinct_keys_are_not_deduped(): void
    {
        $repo = new EloquentUsageRepository();

        $id1 = $repo->record($this->validRow(['idempotency_key' => 'task:42']));
        $id2 = $repo->record($this->validRow(['idempotency_key' => 'task:43']));

        $this->assertNotSame($id1, $id2);
        $this->assertSame(2, AiUsageLog::count());
    }

    public function test_record_outside_window_inserts_new_row(): void
    {
        $repo = new EloquentUsageRepository();
        $row = $this->validRow(['idempotency_key' => 'task:42']);

        // Travel back to insert the first row well outside the dedup window.
        Carbon::setTestNow(now()->subSeconds(EloquentUsageRepository::IDEMPOTENCY_WINDOW_SECONDS + 30));
        $id1 = $repo->record($row);
        Carbon::setTestNow();  // back to "now"

        $id2 = $repo->record($row);

        $this->assertNotSame($id1, $id2, 'window has expired — second call must insert');
        $this->assertSame(2, AiUsageLog::count());
    }

    public function test_dispatcher_auto_generates_key_from_external_label(): void
    {
        $registry = new BackendRegistry(null, []);
        $registry->register($this->stubBackend('stub_idem', 'ok', 10, 5, 'claude-sonnet-4-5-20241022'));

        $dispatcher = new Dispatcher(
            $registry,
            new CostCalculator(),
            new UsageTracker(new EloquentUsageRepository()),
        );

        // Two dispatches with the same external_label simulate a host
        // that double-records the same logical run.
        $r1 = $dispatcher->dispatch(['prompt' => 'p', 'backend' => 'stub_idem', 'external_label' => 'task:42']);
        $r2 = $dispatcher->dispatch(['prompt' => 'p', 'backend' => 'stub_idem', 'external_label' => 'task:42']);

        $this->assertSame($r1['usage_log_id'], $r2['usage_log_id'], 'Dispatcher should auto-dedup same external_label calls');
        $this->assertSame(1, AiUsageLog::count(), 'only one row should land on disk');

        $row = AiUsageLog::first();
        $this->assertSame('stub_idem:task:42', $row->idempotency_key);
    }

    public function test_dispatcher_no_external_label_no_auto_key(): void
    {
        $registry = new BackendRegistry(null, []);
        $registry->register($this->stubBackend('stub_nolabel', 'ok', 10, 5, 'claude-sonnet-4-5-20241022'));

        $dispatcher = new Dispatcher(
            $registry,
            new CostCalculator(),
            new UsageTracker(new EloquentUsageRepository()),
        );

        $r1 = $dispatcher->dispatch(['prompt' => 'p', 'backend' => 'stub_nolabel']);
        $r2 = $dispatcher->dispatch(['prompt' => 'p', 'backend' => 'stub_nolabel']);

        $this->assertNotSame($r1['usage_log_id'], $r2['usage_log_id'], 'no label = no auto-dedup');
        $this->assertSame(2, AiUsageLog::count());

        $rows = AiUsageLog::all();
        foreach ($rows as $row) {
            $this->assertNull($row->idempotency_key);
        }
    }

    public function test_explicit_idempotency_key_overrides_auto_gen(): void
    {
        $registry = new BackendRegistry(null, []);
        $registry->register($this->stubBackend('stub_explicit', 'ok', 10, 5, 'claude-sonnet-4-5-20241022'));

        $dispatcher = new Dispatcher(
            $registry,
            new CostCalculator(),
            new UsageTracker(new EloquentUsageRepository()),
        );

        $dispatcher->dispatch([
            'prompt' => 'p', 'backend' => 'stub_explicit',
            'external_label' => 'task:42',
            'idempotency_key' => 'job-uuid-9876',  // host-supplied wins
        ]);

        $this->assertSame('job-uuid-9876', AiUsageLog::first()->idempotency_key);
    }

    public function test_explicit_false_disables_auto_gen(): void
    {
        $registry = new BackendRegistry(null, []);
        $registry->register($this->stubBackend('stub_optout', 'ok', 10, 5, 'claude-sonnet-4-5-20241022'));

        $dispatcher = new Dispatcher(
            $registry,
            new CostCalculator(),
            new UsageTracker(new EloquentUsageRepository()),
        );

        // Two dispatches with external_label set, but caller explicitly
        // opts out of auto-dedup. Both should land as separate rows.
        $r1 = $dispatcher->dispatch([
            'prompt' => 'p', 'backend' => 'stub_optout',
            'external_label' => 'task:42',
            'idempotency_key' => false,
        ]);
        $r2 = $dispatcher->dispatch([
            'prompt' => 'p', 'backend' => 'stub_optout',
            'external_label' => 'task:42',
            'idempotency_key' => false,
        ]);

        $this->assertNotSame($r1['usage_log_id'], $r2['usage_log_id']);
        $this->assertSame(2, AiUsageLog::count());
        $this->assertNull(AiUsageLog::first()->idempotency_key);
    }

    public function test_dispatcher_forwards_idempotency_key_to_backend_options(): void
    {
        // SDK 0.9.1 contract: backends that wrap Agent::run() can forward the
        // key so AgentResult echoes it back. We can't touch the SDK here, but
        // we can verify Dispatcher places the key onto $options before the
        // backend's generate() fires — which is the enabling plumbing.
        $captured = null;
        $registry = new BackendRegistry(null, []);
        $registry->register(new class($captured) implements Backend {
            public function __construct(public ?array &$captured) {}
            public function name(): string { return 'stub_fwd'; }
            public function isAvailable(array $providerConfig = []): bool { return true; }
            public function generate(array $options): ?array
            {
                $this->captured = $options;
                return ['text' => 'ok', 'model' => 'claude-sonnet-4-5-20241022', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1], 'stop_reason' => null];
            }
        });

        $dispatcher = new Dispatcher(
            $registry,
            new CostCalculator(),
            new UsageTracker(new EloquentUsageRepository()),
        );
        $dispatcher->dispatch([
            'prompt' => 'p',
            'backend' => 'stub_fwd',
            'idempotency_key' => 'task:42',
        ]);

        $this->assertSame('task:42', $captured['idempotency_key']);
    }

    public function test_dispatcher_prefers_echoed_key_from_result_envelope(): void
    {
        // When the backend rewrites the key on the envelope (e.g. the SDK
        // normalised it), the Dispatcher's usage write follows the envelope
        // so the ai_usage_logs row binds the authoritative observed value.
        $registry = new BackendRegistry(null, []);
        $registry->register(new class implements Backend {
            public function name(): string { return 'stub_echo'; }
            public function isAvailable(array $providerConfig = []): bool { return true; }
            public function generate(array $options): ?array
            {
                return [
                    'text' => 'ok',
                    'model' => 'claude-sonnet-4-5-20241022',
                    'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
                    'stop_reason' => null,
                    'idempotency_key' => 'sdk-normalised',
                ];
            }
        });

        $dispatcher = new Dispatcher(
            $registry,
            new CostCalculator(),
            new UsageTracker(new EloquentUsageRepository()),
        );
        $dispatcher->dispatch([
            'prompt' => 'p', 'backend' => 'stub_echo',
            'idempotency_key' => 'raw-client-key',
        ]);

        $this->assertSame('sdk-normalised', AiUsageLog::first()->idempotency_key);
    }

    public function test_key_truncated_to_80_chars(): void
    {
        $registry = new BackendRegistry(null, []);
        $registry->register($this->stubBackend('stub_long', 'ok', 10, 5, 'claude-sonnet-4-5-20241022'));

        $dispatcher = new Dispatcher(
            $registry,
            new CostCalculator(),
            new UsageTracker(new EloquentUsageRepository()),
        );

        $longKey = str_repeat('a', 200);
        $dispatcher->dispatch([
            'prompt' => 'p', 'backend' => 'stub_long',
            'idempotency_key' => $longKey,
        ]);

        $stored = AiUsageLog::first()->idempotency_key;
        $this->assertSame(80, mb_strlen($stored));
    }

    // ─── helpers ───

    private function validRow(array $overrides = []): array
    {
        return array_merge([
            'backend'       => 'claude_cli',
            'model'         => 'claude-sonnet-4-5-20241022',
            'input_tokens'  => 100,
            'output_tokens' => 50,
            'cost_usd'      => 0.001,
        ], $overrides);
    }

    private function stubBackend(string $name, string $text, int $inputTokens, int $outputTokens, string $model): Backend
    {
        return new class($name, $text, $inputTokens, $outputTokens, $model) implements Backend {
            public function __construct(
                private string $name,
                private string $text,
                private int $inputTokens,
                private int $outputTokens,
                private string $model,
            ) {}
            public function name(): string { return $this->name; }
            public function isAvailable(array $providerConfig = []): bool { return true; }
            public function generate(array $options): ?array
            {
                return [
                    'text' => $this->text,
                    'model' => $this->model,
                    'usage' => ['input_tokens' => $this->inputTokens, 'output_tokens' => $this->outputTokens],
                    'stop_reason' => null,
                ];
            }
        };
    }
}
