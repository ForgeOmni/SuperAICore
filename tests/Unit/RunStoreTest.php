<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Services\RunStore;

final class RunStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sac-runstore-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function test_record_and_get_round_trip(): void
    {
        $store = new RunStore($this->dir);
        $id = $store->record(['status' => 'ok', 'requested_target' => 'opus', 'session_id' => 'sess-1']);

        $this->assertNotNull($id);
        $run = $store->get($id);
        $this->assertSame('ok', $run['status']);
        $this->assertSame($id, $run['run_id']);
        $this->assertArrayHasKey('recorded_at', $run);
    }

    public function test_list_returns_newest_first(): void
    {
        $store = new RunStore($this->dir);
        $store->record(['run_id' => '20260101T000000Z-aaaaaa', 'status' => 'ok']);
        $store->record(['run_id' => '20260102T000000Z-bbbbbb', 'status' => 'failed']);

        $rows = $store->list();
        $this->assertSame('20260102T000000Z-bbbbbb', $rows[0]['run_id']);
        $this->assertSame('20260101T000000Z-aaaaaa', $rows[1]['run_id']);
    }

    public function test_find_by_session_returns_most_recent_match(): void
    {
        $store = new RunStore($this->dir);
        $store->record(['run_id' => '20260101T000000Z-aaaaaa', 'session_id' => 's1', 'backend_used' => 'claude_cli']);
        $store->record(['run_id' => '20260102T000000Z-bbbbbb', 'session_id' => 's1', 'backend_used' => 'codex_cli']);

        $found = $store->findBySession('s1');
        $this->assertSame('codex_cli', $found['backend_used']);
        $this->assertNull($store->findBySession('nope'));
        $this->assertNull($store->findBySession(''));
    }

    public function test_record_survives_unwritable_dir(): void
    {
        $store = new RunStore('/dev/null/not-a-dir');
        $this->assertNull($store->record(['status' => 'ok']));
    }
}
