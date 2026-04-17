<?php

use SuperAICore\Support\TablePrefix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $table = TablePrefix::apply('ai_services');
        if (Schema::hasTable($table)) return;

        Schema::create($table, function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->foreignId('capability_id')->constrained(TablePrefix::apply('ai_capabilities'))->cascadeOnDelete();
            $table->string('protocol', 30);
            $table->string('base_url', 500);
            $table->text('api_key')->nullable();
            $table->string('model', 100);
            $table->json('extra_config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TablePrefix::apply('ai_services'));
    }
};
