<?php

use SuperAICore\Support\TablePrefix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI Goals — thread-scoped objectives the agent pursues across turns,
 * with optional token budgets and per-status accounting.
 *
 * Mirrors codex's `thread_goal` schema; serves as the persistent
 * backing store for SuperAgent's GoalStore SPI so a session resumed
 * after a process restart still finds its active goal.
 */
return new class extends Migration {
    public function up(): void
    {
        $table = TablePrefix::apply('ai_goals');
        if (Schema::hasTable($table)) return;

        Schema::create($table, function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('thread_id', 120)->index();
            $t->text('objective');
            // active | complete | paused | budget_limited
            $t->string('status', 20)->default('active')->index();
            $t->unsignedBigInteger('token_budget')->nullable();
            $t->unsignedBigInteger('tokens_used')->default(0);
            $t->json('metadata')->nullable();
            $t->timestamps();

            // One active goal per thread — partial uniqueness via a
            // composite index that's looked up on every create_goal.
            $t->index(['thread_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TablePrefix::apply('ai_goals'));
    }
};
