<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI Processes — tracks long-running backend invocations spawned by
 * SuperAICore (or by a host app calling SuperAICore's Process::register).
 *
 * Process Monitor UI only lists rows from this table, not arbitrary
 * system processes, so it shows ONLY SuperAICore-initiated work.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('ai_processes')) return;

        Schema::create('ai_processes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('pid')->index();
            $table->string('backend', 30)->index();              // claude | codex | superagent
            $table->string('command', 500)->nullable();           // full command line
            $table->string('external_id', 120)->nullable()->index(); // host's task/job id
            $table->string('external_label', 255)->nullable();    // human-readable label
            $table->string('output_dir', 500)->nullable();
            $table->string('log_file', 500)->nullable();
            $table->string('status', 20)->default('running');     // running | finished | failed | killed
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'started_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_processes');
    }
};
