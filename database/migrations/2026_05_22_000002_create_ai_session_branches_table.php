<?php

use SuperAICore\Support\TablePrefix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pi-style session tree.
 *
 * Pi (pi.dev/docs/latest/sessions) models a conversation as a tree, not a
 * line: each entry has an `id` and `parentId`. Navigating back via /tree
 * to an old entry and continuing creates a new branch. Switching between
 * branches triggers an automatic summary of the abandoned path so context
 * isn't lost.
 *
 * `ai_session_branches` is the relational projection of that tree for
 * SuperAICore's `/processes`-page sessions. Each row is a branch root:
 *
 *   - `session_id`        : the parent session (matches ai_usage_logs.metadata.session_id)
 *   - `branch_id`         : 8-hex pi-style branch id (also used as the
 *                           parentId of every entry pushed under this branch)
 *   - `parent_branch_id`  : forking branch (null = trunk)
 *   - `fork_from_entry_id`: pi entry id we forked from (the message that
 *                           the user clicked /tree on)
 *   - `summary`           : auto-generated BranchSummaryEntry text when
 *                           this branch is abandoned (null while active)
 *   - `summary_details`   : JSON metadata (token count, reason, etc.)
 *   - `is_active`         : exactly one active branch per session;
 *                           navigating elsewhere swaps the flag
 */
return new class extends Migration {
    public function up(): void
    {
        $table = TablePrefix::apply('ai_session_branches');
        if (Schema::hasTable($table)) return;

        Schema::create($table, function (Blueprint $t) {
            $t->id();
            $t->string('session_id', 80)->index();
            $t->string('branch_id', 16);
            $t->string('parent_branch_id', 16)->nullable();
            $t->string('fork_from_entry_id', 16)->nullable();
            $t->text('summary')->nullable();
            $t->json('summary_details')->nullable();
            $t->boolean('is_active')->default(false);
            $t->string('display_name', 120)->nullable();
            $t->timestamps();

            $t->unique(['session_id', 'branch_id']);
            $t->index(['session_id', 'is_active']);
            $t->index(['session_id', 'parent_branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TablePrefix::apply('ai_session_branches'));
    }
};
