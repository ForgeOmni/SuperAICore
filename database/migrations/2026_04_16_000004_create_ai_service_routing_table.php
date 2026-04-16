<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('ai_service_routing')) return;

        Schema::create('ai_service_routing', function (Blueprint $table) {
            $table->id();
            $table->string('task_type', 60);
            $table->foreignId('capability_id')->constrained('ai_capabilities')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('ai_services')->cascadeOnDelete();
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['task_type', 'capability_id', 'priority']);
            $table->index(['task_type', 'capability_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_service_routing');
    }
};
