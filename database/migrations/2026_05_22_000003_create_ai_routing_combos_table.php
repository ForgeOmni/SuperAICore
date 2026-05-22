<?php

use SuperAICore\Support\TablePrefix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 9Router-borrowed named routing combos.
 *
 * A "combo" is an ordered list of {provider_tag, model_id} entries that
 * the dispatcher walks top-down until one succeeds. Encapsulates user
 * routing preferences as a saveable, sharable unit:
 *
 *   premium-coding  = [cc/claude-opus-4-7, glm/glm-5.1, kr/claude-sonnet-4.5]
 *   cheap-research  = [gemini-cli/gemini-2.5-flash, kimi-cli/kimi-k2-instruct]
 *
 * Combos sit above tier_map (band→provider static mapping) and below
 * fallback_chain (task-type→default chain). When --combo=NAME is passed
 * to smart/squad/auto, the combo's entries override the tier mapping.
 *
 * `entries` is a JSON list of `{provider: 'cc', model: 'claude-opus-4-7'}`.
 * The provider tag matches BackendRegistry keys.
 */
return new class extends Migration {
    public function up(): void
    {
        $table = TablePrefix::apply('ai_routing_combos');
        if (Schema::hasTable($table)) return;

        Schema::create($table, function (Blueprint $t) {
            $t->id();
            $t->string('name', 80)->unique();
            $t->string('display_name', 120)->nullable();
            $t->text('description')->nullable();
            $t->json('entries');
            $t->json('metadata')->nullable();
            $t->boolean('is_active')->default(true);
            $t->integer('user_id')->nullable()->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TablePrefix::apply('ai_routing_combos'));
    }
};
