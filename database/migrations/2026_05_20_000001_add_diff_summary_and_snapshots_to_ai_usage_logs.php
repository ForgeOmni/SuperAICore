<?php

use SuperAICore\Support\TablePrefix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds three opencode-inspired columns to `ai_usage_logs`:
 *
 *   - `pre_snapshot`  (string|null): shadow-git commit captured BEFORE the
 *                     backend ran. Lets `POST /usage/{id}/revert` restore
 *                     the worktree to its pre-call state.
 *   - `post_snapshot` (string|null): shadow-git commit captured AFTER the
 *                     backend ran. Used as the `to` side of the per-file
 *                     diff and as the "unrevert" target.
 *   - `file_diff_summary` (json|null): structured `{additions, deletions,
 *                     files, diffs:[{file, additions, deletions, status,
 *                     patch}]}` produced by SnapshotDiffService. Surfaces
 *                     on the /processes detail page as a per-file diff
 *                     banner + collapsible patch view.
 *
 * All three columns are nullable so pre-migration rows and dispatches
 * that don't run through SuperAgentBackend (claude_cli / codex_cli /
 * gemini_cli — they don't own a shadow repo for the project worktree)
 * stay byte-identical.
 */
return new class extends Migration {
    public function up(): void
    {
        $table = TablePrefix::apply('ai_usage_logs');
        if (!Schema::hasTable($table)) return;

        Schema::table($table, function (Blueprint $table) {
            if (!Schema::hasColumn($table->getTable(), 'pre_snapshot')) {
                $table->string('pre_snapshot', 64)->nullable()->after('shadow_cost_usd');
            }
            if (!Schema::hasColumn($table->getTable(), 'post_snapshot')) {
                $table->string('post_snapshot', 64)->nullable()->after('pre_snapshot');
            }
            if (!Schema::hasColumn($table->getTable(), 'file_diff_summary')) {
                $table->json('file_diff_summary')->nullable()->after('post_snapshot');
            }
        });
    }

    public function down(): void
    {
        $table = TablePrefix::apply('ai_usage_logs');
        if (!Schema::hasTable($table)) return;

        Schema::table($table, function (Blueprint $table) {
            foreach (['file_diff_summary', 'post_snapshot', 'pre_snapshot'] as $col) {
                if (Schema::hasColumn($table->getTable(), $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
