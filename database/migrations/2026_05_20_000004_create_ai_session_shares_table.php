<?php

use SuperAICore\Support\TablePrefix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Session-share metadata table backing `ShareController` (P3-10).
 *
 * One row per shared session. `share_id` is the public token used in
 * the share URL; `secret` is the bearer token the remote sharer
 * accepts on writes (rotated by `destroy`); `remote_url` records where
 * the share is hosted so a later `destroy` knows where to send the
 * deletion request.
 */
return new class extends Migration {
    public function up(): void
    {
        $table = TablePrefix::apply('ai_session_shares');
        if (Schema::hasTable($table)) return;

        Schema::create($table, function (Blueprint $table) {
            $table->id();
            $table->string('session_id', 80)->index();
            $table->string('share_id', 64)->unique();
            $table->string('secret', 64);
            $table->string('remote_url', 512)->nullable();
            $table->string('share_url', 512)->nullable();
            $table->string('status', 16)->default('active');   // active | revoked | failed
            $table->json('metadata')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TablePrefix::apply('ai_session_shares'));
    }
};
