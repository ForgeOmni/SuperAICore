<?php

use SuperAICore\Support\TablePrefix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Skill telemetry — one row per Claude Code Skill tool invocation.
 *
 * Written by hooks (PreToolUse logs `in_progress`; Stop hook closes the row).
 * Used by SkillRanker to boost recently-successful skills, and by SkillEvolver
 * to discover failing skills that need a FIX.
 */
return new class extends Migration {
    public function up(): void
    {
        $table = TablePrefix::apply('skill_executions');
        if (Schema::hasTable($table)) return;

        Schema::create($table, function (Blueprint $table) {
            $table->id();
            $table->string('skill_name', 120)->index();
            $table->string('host_app', 60)->nullable()->index(); // e.g. "super-team"
            $table->string('session_id', 80)->nullable()->index();
            $table->string('status', 20)->default('in_progress')->index();
            // status: in_progress | completed | failed | orphaned | interrupted
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('transcript_path', 500)->nullable();
            $table->text('error_summary')->nullable();
            $table->string('cwd', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['skill_name', 'status']);
            $table->index(['skill_name', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TablePrefix::apply('skill_executions'));
    }
};
