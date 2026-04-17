<?php

use SuperAICore\Support\TablePrefix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $table = TablePrefix::apply('ai_model_settings');
        if (Schema::hasTable($table)) return;

        Schema::create($table, function (Blueprint $table) {
            $table->id();
            $table->string('scope', 20)->default('global');
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->unsignedBigInteger('provider_id')->nullable()->comment('FK to ai_providers; NULL = builtin');
            $table->string('backend', 20)->default('claude');
            $table->string('task_type', 60)->comment('Task type key or "default"');
            $table->string('model', 60)->nullable();
            $table->string('effort', 10)->nullable();
            $table->timestamps();

            $table->unique(['scope', 'scope_id', 'provider_id', 'backend', 'task_type'], 'sac_ai_model_settings_unique');
            $table->index(['scope', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TablePrefix::apply('ai_model_settings'));
    }
};
