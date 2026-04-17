<?php

use SuperAICore\Support\TablePrefix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $table = TablePrefix::apply('integration_configs');
        if (Schema::hasTable($table)) return;

        Schema::create($table, function (Blueprint $table) {
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
        Schema::dropIfExists(TablePrefix::apply('integration_configs'));
    }
};
