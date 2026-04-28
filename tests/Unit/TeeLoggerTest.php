<?php

namespace SuperAICore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAICore\Support\TeeLogger;

final class TeeLoggerTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/sac-tee-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_file($this->tmp)) @unlink($this->tmp);
        $dir = dirname($this->tmp);
        if (str_starts_with($dir, sys_get_temp_dir() . '/sac-tee-') && is_dir($dir)) @rmdir($dir);
    }

    public function test_writes_chunks_to_disk_and_tracks_byte_count(): void
    {
        $logger = new TeeLogger($this->tmp);
        $this->assertTrue($logger->isOpen());

        $logger->write('hello ');
        $logger->write('world');
        $logger->close();

        $this->assertSame('hello world', file_get_contents($this->tmp));
        $this->assertSame(11, $logger->bytesWritten());
        $this->assertSame($this->tmp, $logger->path());
        $this->assertFalse($logger->isOpen());
    }

    public function test_close_is_idempotent(): void
    {
        $logger = new TeeLogger($this->tmp);
        $logger->write('a');
        $logger->close();
        $logger->close();  // should not throw
        $logger->close();
        $this->assertSame('a', file_get_contents($this->tmp));
    }

    public function test_write_after_close_is_noop(): void
    {
        $logger = new TeeLogger($this->tmp);
        $logger->write('first');
        $logger->close();
        $logger->write('second');  // dropped
        $this->assertSame('first', file_get_contents($this->tmp));
    }

    public function test_write_empty_chunk_is_noop(): void
    {
        $logger = new TeeLogger($this->tmp);
        $logger->write('');
        $logger->close();
        $this->assertSame(0, $logger->bytesWritten());
    }

    public function test_creates_parent_directory_if_missing(): void
    {
        $nested = sys_get_temp_dir() . '/sac-tee-nested-' . bin2hex(random_bytes(4)) . '/run/log.txt';
        $logger = new TeeLogger($nested);

        $logger->write('ok');
        $logger->close();

        $this->assertFileExists($nested);
        $this->assertSame('ok', file_get_contents($nested));

        // cleanup
        @unlink($nested);
        @rmdir(dirname($nested));
        @rmdir(dirname($nested, 2));
    }

    public function test_unwritable_path_does_not_throw(): void
    {
        // Pick a path the OS guarantees we can't open. POSIX has /dev/null
        // (a special file, can't host children); Windows reserves NUL
        // similarly and rejects paths containing `<` / `>` / `|` / `?` /
        // `*` outright. Either way the constructor must not throw and
        // subsequent writes must silently no-op.
        $path = PHP_OS_FAMILY === 'Windows'
            ? 'NUL\\cannot\\<invalid>.log'
            : '/dev/null/cannot/exist.log';
        $logger = new TeeLogger($path);
        $this->assertFalse($logger->isOpen());
        $logger->write('lost');
        $logger->close();
        $this->assertSame(0, $logger->bytesWritten());
    }

    public function test_appends_to_existing_file(): void
    {
        file_put_contents($this->tmp, 'existing ');
        $logger = new TeeLogger($this->tmp);
        $logger->write('appended');
        $logger->close();
        $this->assertSame('existing appended', file_get_contents($this->tmp));
    }
}
