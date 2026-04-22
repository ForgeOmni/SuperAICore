<?php

use SuperAICore\Support\TablePrefix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `idempotency_key` so the recorder can dedupe accidental
 * double-record calls within a short window (default 60s).
 *
 * Driver: hosts that haven't fully migrated to TaskRunner sometimes call
 * `UsageRecorder::record()` themselves on top of `Dispatcher::dispatch()`
 * — both write a row, double-counting the run on dashboards. Phase D
 * gives `Dispatcher::dispatch()` an auto-generated key
 * (`{backend}:{external_label}`), so when the same logical call arrives
 * twice within 60s, the repository returns the existing row id instead
 * of inserting a duplicate. Hosts that want stronger guarantees can pass
 * an explicit `idempotency_key` (e.g. their internal job id).
 *
 * The `(idempotency_key, created_at)` composite index covers the
 * "find a matching row in the last N seconds" lookup the repository
 * runs on every record() with a key set.
 *
 * Nullable + non-unique by design — old rows + non-keyed callers
 * (test_connection probes, ad-hoc scripts) coexist fine.
 */
return new class extends Migration {
    public function up(): void
    {
        $table = TablePrefix::apply('ai_usage_logs');
        if (!Schema::hasTable($table)) return;

        Schema::table($table, function (Blueprint $table) {
            if (!Schema::hasColumn($table->getTable(), 'idempotency_key')) {
                $table->string('idempotency_key', 80)->nullable()->after('billing_model');
                $table->index(['idempotency_key', 'created_at'], 'ai_usage_logs_idem_created_idx');
            }
        });
    }

    public function down(): void
    {
        $table = TablePrefix::apply('ai_usage_logs');
        if (!Schema::hasTable($table)) return;

        Schema::table($table, function (Blueprint $table) {
            if (Schema::hasColumn($table->getTable(), 'idempotency_key')) {
                // Drop the index first — some drivers refuse a column drop
                // while a covering index still references it.
                try { $table->dropIndex('ai_usage_logs_idem_created_idx'); } catch (\Throwable) {}
                $table->dropColumn('idempotency_key');
            }
        });
    }
};
