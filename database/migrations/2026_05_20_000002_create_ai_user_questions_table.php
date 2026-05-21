<?php

use SuperAICore\Support\TablePrefix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mid-run HITL question table — backs `AskUserTool`.
 *
 * The model emits an `ask_user` tool call; the tool inserts a row here
 * and polls until either an operator POSTs `/api/questions/{id}/answer`
 * or the timeout fires. Modeled on opencode `tool/question.ts` but using
 * a persistent DB row instead of an in-process Deferred so the answering
 * UI doesn't need to share the agent's process.
 *
 *   - `question`: the model-facing prompt
 *   - `options` : JSON list of `{label, description}` choices the UI
 *                 renders as buttons (empty array = free-form input)
 *   - `metadata`: caller context (session_id, process_id, etc.) so the
 *                 UI can route the question to the right panel
 *   - `answer`  : populated by the UI's POST
 *   - `status`  : pending / answered / cancelled / timed_out
 */
return new class extends Migration {
    public function up(): void
    {
        $table = TablePrefix::apply('ai_user_questions');
        if (Schema::hasTable($table)) return;

        Schema::create($table, function (Blueprint $table) {
            $table->id();
            $table->string('session_id', 80)->nullable()->index();
            $table->string('process_id', 80)->nullable()->index();
            $table->string('agent_label', 80)->nullable();
            $table->text('question');
            $table->json('options')->nullable();
            $table->json('metadata')->nullable();
            $table->text('answer')->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TablePrefix::apply('ai_user_questions'));
    }
};
