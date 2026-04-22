<?php

namespace SuperAICore\Support;

/**
 * Append-only tee writer for streamed CLI output.
 *
 * Used by {@see Backends\Concerns\StreamableProcess} (and any future
 * runner that wants to fan a single chunk to disk + a callback) to
 * persist the raw stream so the Process Monitor `tail` view, the
 * post-hoc `CliOutputParser`, and the ad-hoc human reader all see the
 * same authoritative bytes.
 *
 * Failure is non-fatal — when the file can't be opened (unwritable
 * directory, ENOSPC, etc.), `write()` becomes a no-op and `path()`
 * returns the requested path so callers can still surface it in error
 * messages. The host's log directory creation (`mkdir`) is best-effort;
 * if it fails the TeeLogger silently skips disk writes rather than
 * killing the run.
 *
 * Thread/concurrency note: each TeeLogger owns one fd. Callers must NOT
 * share an instance across processes; each spawn should construct its
 * own.
 */
final class TeeLogger
{
    /** @var resource|null */
    private $handle;

    private string $path;
    private int $bytes = 0;

    public function __construct(string $path)
    {
        $this->path = $path;

        $dir = \dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $fh = @fopen($path, 'a');
        $this->handle = is_resource($fh) ? $fh : null;
    }

    public function write(string $chunk): void
    {
        if ($chunk === '' || $this->handle === null) return;

        $written = @fwrite($this->handle, $chunk);
        if ($written !== false) {
            $this->bytes += $written;
        }
    }

    public function close(): void
    {
        if ($this->handle !== null) {
            @fclose($this->handle);
            $this->handle = null;
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    public function bytesWritten(): int
    {
        return $this->bytes;
    }

    public function isOpen(): bool
    {
        return $this->handle !== null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
