<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('ai_providers')) return;

        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 20)->default('global');
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('backend', 20)->default('claude')->comment('claude | codex | superagent');
            $table->string('name', 100);
            $table->string('type', 30)->comment('builtin | anthropic | anthropic-proxy | bedrock | vertex | openai | openai-compatible');
            $table->string('base_url', 500)->nullable();
            $table->text('api_key')->nullable()->comment('Encrypted');
            $table->json('extra_config')->nullable();
            $table->boolean('is_active')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['scope', 'scope_id']);
            $table->index(['scope', 'scope_id', 'is_active']);
            $table->index(['scope', 'scope_id', 'backend']);
            $table->index(['scope', 'scope_id', 'backend', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_providers');
    }
};
