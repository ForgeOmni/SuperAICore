<?php

use SuperAICore\Support\TablePrefix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Long-lived shell session table backing `PtyController` (P3-9).
 *
 * Phase 1: each row stores the command, cwd, env, pid, status, and the
 * rolling stdout buffer. The PtyService spawns subprocesses with
 * `proc_open` and appends each chunk to a separate `ai_pty_log_chunks`
 * file on disk (avoiding a hot DB row); this table tracks metadata +
 * the latest cursor so reconnecting clients can replay from a known
 * offset.
 *
 * Phase 2 (future): when the host runs under Reverb/Soketi, swap the
 * long-poll endpoint for a WebSocket subscription that streams new
 * chunks live. The DB row remains the source of truth so disconnected
 * clients can pick up from cursor on reconnect.
 */
return new class extends Migration {
    public function up(): void
    {
        $table = TablePrefix::apply('ai_pty_sessions');
        if (Schema::hasTable($table)) return;

        Schema::create($table, function (Blueprint $table) {
            $table->id();
            $table->string('title', 120)->nullable();
            $table->text('command');
            $table->string('cwd', 255)->nullable();
            $table->integer('pid')->nullable();
            $table->string('status', 20)->default('running')->index();   // running | exited | killed
            $table->integer('exit_code')->nullable();
            $table->string('log_path', 512)->nullable();
            $table->unsignedBigInteger('cursor')->default(0);             // bytes written so far
            $table->json('metadata')->nullable();
            $table->timestamp('exited_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TablePrefix::apply('ai_pty_sessions'));
    }
};
