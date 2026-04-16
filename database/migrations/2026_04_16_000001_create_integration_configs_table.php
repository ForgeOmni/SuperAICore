<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('integration_configs')) return;

        Schema::create('integration_configs', function (Blueprint $table) {
            $table->id();
            $table->string('integration_key', 100)->comment('MCP server key or 3rd-party tool key');
            $table->string('field_key', 100);
            $table->text('value')->nullable()->comment('Encrypted if is_secret=true');
            $table->boolean('is_secret')->default(false);
            $table->timestamps();

            $table->unique(['integration_key', 'field_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_configs');
    }
};
