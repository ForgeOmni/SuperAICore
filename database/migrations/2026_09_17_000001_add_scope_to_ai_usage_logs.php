<?php

use SuperAICore\Support\TablePrefix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `scope` / `scope_id` so a usage row says whose credentials paid for it.
 *
 * `ai_providers` has carried a scope since the first migration, and the
 * dispatcher resolves a provider through it — but the row it then writes
 * recorded only `user_id`. A host billing a tenant therefore had to infer the
 * tenant from the user, which is wrong the moment one person works for two of
 * them, and a host that scopes providers by anything other than a user could
 * not group its own spend at all. The alternative was aggregating over the
 * JSON `metadata` column, which on MySQL 5.7 — still in production for some
 * hosts — is a full scan per report.
 *
 * Nullable by design: every existing row predates the column, and a
 * global-scope dispatch legitimately has no id.
 *
 * @since 1.2.0
 */
return new class extends Migration {
    public function up(): void
    {
        $table = TablePrefix::apply('ai_usage_logs');
        if (!Schema::hasTable($table)) return;

        Schema::table($table, function (Blueprint $table) {
            if (!Schema::hasColumn($table->getTable(), 'scope')) {
                $table->string('scope', 40)->nullable()->after('user_id');
            }
            if (!Schema::hasColumn($table->getTable(), 'scope_id')) {
                $table->unsignedBigInteger('scope_id')->nullable()->after('scope');
            }
        });

        Schema::table($table, function (Blueprint $table) {
            // The report this exists for: one tenant's spend over a window.
            try {
                $table->index(['scope', 'scope_id', 'created_at'], 'ai_usage_logs_scope_created_idx');
            } catch (\Throwable) {
                // Index already present (re-run, or a host that added it by hand).
            }
        });
    }

    public function down(): void
    {
        $table = TablePrefix::apply('ai_usage_logs');
        if (!Schema::hasTable($table)) return;

        Schema::table($table, function (Blueprint $table) {
            try { $table->dropIndex('ai_usage_logs_scope_created_idx'); } catch (\Throwable) {}

            foreach (['scope_id', 'scope'] as $column) {
                if (Schema::hasColumn($table->getTable(), $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
