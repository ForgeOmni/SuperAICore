<?php

use SuperAICore\Support\TablePrefix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 9Router-borrowed multi-account round-robin.
 *
 * `ai_providers` currently holds one account per provider row. Real
 * users have multiple Claude Pro / GitHub Copilot / Codex Pro
 * subscriptions across accounts, and 9Router's killer feature is
 * stacking those subscriptions: a single "claude_cli" provider with
 * three rotating accounts = 3× the rate-limit headroom.
 *
 * `ai_provider_accounts` adds the per-account dimension. The scheduler
 * picks the next active account when a dispatch hits the provider;
 * quota_exceeded markers + cooldown_until rows let us suspend an
 * account temporarily without dropping the provider entirely.
 *
 *   - `provider_id`     : FK to ai_providers
 *   - `label`           : human-readable ("personal", "work", "alt-1")
 *   - `auth_payload`    : JSON — api_key OR OAuth refresh token, depending on provider type
 *   - `priority`        : lower = preferred (default 100)
 *   - `is_active`       : operator can toggle
 *   - `cooldown_until`  : timestamp — scheduler skips while in cooldown
 *   - `cooldown_reason` : last quota/rate-limit error code
 *   - `last_used_at`    : for round-robin LRU
 *   - `usage_count`     : cumulative dispatch count (rolls forward across cooldowns)
 */
return new class extends Migration {
    public function up(): void
    {
        $table = TablePrefix::apply('ai_provider_accounts');
        if (Schema::hasTable($table)) return;

        Schema::create($table, function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('provider_id')->index();
            $t->string('label', 80);
            $t->json('auth_payload')->nullable();
            $t->integer('priority')->default(100);
            $t->boolean('is_active')->default(true);
            $t->timestamp('cooldown_until')->nullable()->index();
            $t->string('cooldown_reason', 80)->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->bigInteger('usage_count')->default(0);
            $t->timestamps();

            $t->unique(['provider_id', 'label']);
            $t->index(['provider_id', 'is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TablePrefix::apply('ai_provider_accounts'));
    }
};
