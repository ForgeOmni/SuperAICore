<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Support\ProcessRegistrar;

final class ProcessRegistrarTest extends TestCase
{
    public function test_start_returns_null_outside_laravel(): void
    {
        // No Eloquent connection bound — start() should swallow the throw
        // and hand back null instead of leaking the exception.
        $this->assertNull(ProcessRegistrar::start('copilot', 99999, 'cmd', null));
    }

    public function test_start_rejects_unsafe_pids(): void
    {
        $this->assertNull(ProcessRegistrar::start('copilot', 0, 'cmd'));
        $this->assertNull(ProcessRegistrar::start('copilot', 1, 'cmd'));
        $this->assertNull(ProcessRegistrar::start('copilot', -7, 'cmd'));
    }

    public function test_end_tolerates_null_proc(): void
    {
        // Should be a no-op, not a TypeError.
        ProcessRegistrar::end(null, 'finished');
        $this->assertTrue(true); // reaching here = pass
    }

    public function test_open_log_creates_directory_and_returns_handle(): void
    {
        $logFile = sys_get_temp_dir() . '/sac-test-' . bin2hex(random_bytes(4)) . '/nested/test.log';
        $fh = ProcessRegistrar::openLog($logFile);

        $this->assertIsResource($fh);
        fwrite($fh, "test\n");
        fclose($fh);

        $this->assertFileExists($logFile);
        $this->assertSame("test\n", file_get_contents($logFile));

        @unlink($logFile);
        @rmdir(dirname($logFile));
        @rmdir(dirname(dirname($logFile)));
    }

    public function test_open_log_returns_null_for_empty_path(): void
    {
        $this->assertNull(ProcessRegistrar::openLog(null));
        $this->assertNull(ProcessRegistrar::openLog(''));
    }

    public function test_default_log_path_includes_backend_and_label(): void
    {
        $path = ProcessRegistrar::defaultLogPath('copilot', 'skill:simplify');

        $this->assertStringContainsString('superaicore-copilot-', $path);
        // Slug strips the colon — verify
        $this->assertStringContainsString('skill-simplify', $path);
        $this->assertStringEndsWith('.log', $path);
    }
}
