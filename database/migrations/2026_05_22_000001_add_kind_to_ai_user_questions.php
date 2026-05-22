<?php

use SuperAICore\Support\TablePrefix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pi-style dialog `kind` for ask_user.
 *
 * Pi (pi.dev/docs/latest/extensions §Extension UI) defines four dialog
 * methods: select, confirm, input, editor. We add a `kind` discriminator
 * so the /processes/questions UI renders the right widget per call.
 *
 *   - select  : pre-defined options (existing behaviour)
 *   - confirm : yes/no
 *   - input   : single-line text
 *   - editor  : multi-line text
 */
return new class extends Migration {
    public function up(): void
    {
        $table = TablePrefix::apply('ai_user_questions');
        if (!Schema::hasTable($table)) return;
        if (Schema::hasColumn($table, 'kind')) return;

        Schema::table($table, function (Blueprint $t) {
            $t->string('kind', 16)->default('select')->after('agent_label');
        });
    }

    public function down(): void
    {
        $table = TablePrefix::apply('ai_user_questions');
        if (!Schema::hasTable($table)) return;
        if (!Schema::hasColumn($table, 'kind')) return;

        Schema::table($table, function (Blueprint $t) {
            $t->dropColumn('kind');
        });
    }
};
