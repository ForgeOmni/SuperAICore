<?php

use SuperAICore\Support\TablePrefix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * claude-octopus-borrowed PR / CI reaction engine.
 *
 * A "watcher" row maps an owner/repo (+ optional PR-number filter) to
 * an action policy. The `super-ai-core:gh-watch` daemon polls the
 * GitHub API on a fixed interval; new PR comments and failed CI runs
 * fire `SuperAgent\Hooks\PrWatchHook` which the host can listen to
 * (e.g. spawn a squad, post to ask_user, write a TODO file).
 *
 *   - owner / repo            : github.com/<owner>/<repo>
 *   - pr_filter               : null = all PRs; otherwise `author:NAME` /
 *                                `label:foo` / `number:123` filter spec
 *   - action                  : 'ask_user' | 'spawn_squad' | 'webhook' | 'log'
 *   - action_payload          : JSON config matching `action` (e.g. squad
 *                                team name for spawn_squad; URL for webhook)
 *   - max_retries / cooldown  : per-event retry budget (Octopus default:
 *                                3 retries/30min for CI, 2 retries/60min
 *                                for reviews)
 *   - last_polled_at          : daemon cursor; the daemon uses this +
 *                                each PR's updated_at to detect what's new
 *   - last_etag               : conditional-GET cache key (saves API quota)
 */
return new class extends Migration {
    public function up(): void
    {
        $table = TablePrefix::apply('ai_pr_watchers');
        if (Schema::hasTable($table)) return;

        Schema::create($table, function (Blueprint $t) {
            $t->id();
            $t->string('owner', 80);
            $t->string('repo', 120);
            $t->string('pr_filter', 200)->nullable();
            $t->string('action', 40);
            $t->json('action_payload')->nullable();
            $t->integer('max_retries')->default(3);
            $t->integer('cooldown_seconds')->default(1800);
            $t->boolean('is_active')->default(true);
            $t->timestamp('last_polled_at')->nullable();
            $t->string('last_etag', 80)->nullable();
            $t->timestamps();

            $t->index(['owner', 'repo', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TablePrefix::apply('ai_pr_watchers'));
    }
};
