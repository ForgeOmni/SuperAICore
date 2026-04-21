<?php

use SuperAICore\Support\TablePrefix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add shadow_cost_usd + billing_model so subscription-billed calls (Copilot,
 * Claude Code builtin, Gemini builtin) can still surface an estimated USD
 * cost on the Cost Analytics dashboard. Existing cost_usd keeps the real
 * billed amount ($0 for subscription) so invoice reconciliation stays
 * honest; shadow_cost_usd is the "what would this have cost on pay-as-you-go"
 * number that makes subscription throughput comparable across engines.
 */
return new class extends Migration {
    public function up(): void
    {
        $table = TablePrefix::apply('ai_usage_logs');
        if (!Schema::hasTable($table)) return;

        Schema::table($table, function (Blueprint $table) {
            if (!Schema::hasColumn($table->getTable(), 'shadow_cost_usd')) {
                $table->decimal('shadow_cost_usd', 12, 6)->nullable()->after('cost_usd');
            }
            if (!Schema::hasColumn($table->getTable(), 'billing_model')) {
                $table->string('billing_model', 20)->nullable()->after('shadow_cost_usd');
            }
        });
    }

    public function down(): void
    {
        $table = TablePrefix::apply('ai_usage_logs');
        if (!Schema::hasTable($table)) return;

        Schema::table($table, function (Blueprint $table) {
            if (Schema::hasColumn($table->getTable(), 'billing_model')) {
                $table->dropColumn('billing_model');
            }
            if (Schema::hasColumn($table->getTable(), 'shadow_cost_usd')) {
                $table->dropColumn('shadow_cost_usd');
            }
        });
    }
};
