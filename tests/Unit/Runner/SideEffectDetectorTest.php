<?php

namespace SuperAICore\Tests\Unit\Runner;

use PHPUnit\Framework\TestCase;
use SuperAICore\Runner\SideEffectDetector;

final class SideEffectDetectorTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/sideeffect-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0755, true);
        file_put_contents($this->tmp . '/pre.txt', 'hello');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    public function test_no_change_is_not_detected(): void
    {
        $d = new SideEffectDetector($this->tmp);
        $d->snapshotBefore();

        $result = $d->detectAfter('');

        $this->assertFalse($result['detected']);
        $this->assertSame([], $result['reasons']);
    }

    public function test_new_file_is_detected(): void
    {
        $d = new SideEffectDetector($this->tmp);
        $d->snapshotBefore();

        touch($this->tmp . '/written.txt');
        $result = $d->detectAfter('');

        $this->assertTrue($result['detected']);
        $this->assertStringContainsString('created: written.txt', implode(' ', $result['reasons']));
    }

    public function test_modified_file_is_detected(): void
    {
        $d = new SideEffectDetector($this->tmp);
        $d->snapshotBefore();

        // bump mtime by 2s to clear the 1-second resolution on some FSes
        touch($this->tmp . '/pre.txt', time() + 2);
        $result = $d->detectAfter('');

        $this->assertTrue($result['detected']);
        $this->assertStringContainsString('modified: pre.txt', implode(' ', $result['reasons']));
    }

    public function test_deleted_file_is_detected(): void
    {
        $d = new SideEffectDetector($this->tmp);
        $d->snapshotBefore();

        unlink($this->tmp . '/pre.txt');
        $result = $d->detectAfter('');

        $this->assertTrue($result['detected']);
        $this->assertStringContainsString('deleted: pre.txt', implode(' ', $result['reasons']));
    }

    public function test_stream_json_tool_use_for_write_is_detected(): void
    {
        $d = new SideEffectDetector($this->tmp);
        $d->snapshotBefore();

        $fake = '{"type":"tool_use","name":"Write","input":{"file_path":"/tmp/x"}}';
        $result = $d->detectAfter($fake);

        $this->assertTrue($result['detected']);
        $this->assertStringContainsString('stream-json tool_use: Write', implode(' ', $result['reasons']));
    }

    public function test_non_mutating_tool_use_not_flagged(): void
    {
        $d = new SideEffectDetector($this->tmp);
        $d->snapshotBefore();

        $fake = '{"type":"tool_use","name":"Read","input":{"file_path":"/tmp/x"}}';
        $result = $d->detectAfter($fake);

        $this->assertFalse($result['detected']);
    }

    public function test_skip_dirs_are_not_scanned(): void
    {
        mkdir($this->tmp . '/.git', 0755, true);

        $d = new SideEffectDetector($this->tmp);
        $d->snapshotBefore();

        file_put_contents($this->tmp . '/.git/HEAD', 'ref: refs/heads/main');
        $result = $d->detectAfter('');

        $this->assertFalse($result['detected']);
    }

    public function test_reason_list_is_capped(): void
    {
        $d = new SideEffectDetector($this->tmp);
        $d->snapshotBefore();

        for ($i = 0; $i < 8; $i++) {
            touch($this->tmp . "/new-{$i}.txt");
        }
        $result = $d->detectAfter('');

        $this->assertTrue($result['detected']);
        $this->assertLessThanOrEqual(6, count($result['reasons']));
        $this->assertStringContainsString('more', end($result['reasons']));
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $e) {
            if ($e === '.' || $e === '..') continue;
            $p = $dir . '/' . $e;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
