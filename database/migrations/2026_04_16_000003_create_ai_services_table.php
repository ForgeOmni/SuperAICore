<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('ai_services')) return;

        Schema::create('ai_services', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->foreignId('capability_id')->constrained('ai_capabilities')->cascadeOnDelete();
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
        Schema::dropIfExists('ai_services');
    }
};
