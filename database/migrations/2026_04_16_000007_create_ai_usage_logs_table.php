<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic LLM call usage log — written by Dispatcher per call.
 *
 * Replaces SuperTeam's embed-usage-in-TaskResult.metadata pattern so
 * any host (including non-task-based apps like Shopify Autopilot) can
 * record LLM usage uniformly.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('ai_usage_logs')) return;

        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->string('backend', 30)->index();
            $table->unsignedBigInteger('provider_id')->nullable()->index();
            $table->unsignedBigInteger('service_id')->nullable()->index();
            $table->string('model', 100)->index();
            $table->string('task_type', 60)->nullable()->index();
            $table->string('capability', 60)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
