<?php

use SuperAICore\Support\TablePrefix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIX-mode evolution candidates — never auto-applied.
 *
 * Created by SkillEvolver when a skill fails or its success rate drops.
 * Each candidate is a proposed diff that a human reviews before merging.
 */
return new class extends Migration {
    public function up(): void
    {
        $table = TablePrefix::apply('skill_evolution_candidates');
        if (Schema::hasTable($table)) return;

        Schema::create($table, function (Blueprint $table) {
            $table->id();
            $table->string('skill_name', 120)->index();
            $table->string('trigger_type', 30)->index();
            // trigger_type: failure | metric_degradation | manual
            $table->unsignedBigInteger('execution_id')->nullable()->index();
            $table->string('status', 20)->default('pending')->index();
            // status: pending | reviewing | applied | rejected | superseded
            $table->text('rationale')->nullable();
            $table->longText('proposed_diff')->nullable();
            $table->longText('proposed_body')->nullable();
            $table->longText('llm_prompt')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reviewed_by', 80)->nullable();
            $table->timestamps();

            $table->index(['skill_name', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TablePrefix::apply('skill_evolution_candidates'));
    }
};
