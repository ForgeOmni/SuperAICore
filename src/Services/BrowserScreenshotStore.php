<?php

declare(strict_types=1);

namespace SuperAICore\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Filesystem-backed registry for browser-bridge screenshots so the Process
 * Monitor can render the latest frame inline without coupling to whatever
 * tool produced it (today: SuperAgent's `FirefoxBridgeTool`; tomorrow:
 * Playwright / Puppeteer wrappers).
 *
 * Hosts call `store($processId, $base64Png)` from a tool callback (the
 * SuperAgent tool exposes the base64 result; the host re-publishes it
 * here keyed by the SuperAICore process id). The Process Monitor view
 * then surfaces the latest frame via `latest($processId)`.
 *
 * **Why a separate store and not a column.** Screenshots are large and
 * their lifetime is "until the process ends, then maybe a bit longer
 * for diagnosis". Keeping them on disk under a configurable disk + dir
 * means the host can point them at S3 (or a per-pod tmpfs) without
 * schema changes. The metadata row on `ai_processes` only carries the
 * filename pointer.
 *
 * **Garbage collection.** `purgeFor($processId)` is called by the
 * Process Monitor's reaper when a row flips to FINISHED/KILLED. Hosts
 * that want longer retention swap in a no-op store.
 */
final class BrowserScreenshotStore
{
    public function __construct(
        private readonly string $disk = 'local',
        private readonly string $dir  = 'super-ai-core/browser-screenshots',
    ) {}

    /**
     * Persist a base64-encoded PNG and return the URL the view should
     * use as `<img src="...">`. Returns null on encoding / write failure.
     */
    public function store(string $processId, string $base64Png): ?string
    {
        $bytes = base64_decode($base64Png, true);
        if ($bytes === false || $bytes === '') return null;

        $relative = $this->dir . '/' . $this->safeName($processId) . '.png';
        $disk = $this->disk();
        if (!$disk->put($relative, $bytes)) return null;

        // Filesystem URL works for the `local` driver when the symlink
        // is in place; for any other driver it's the disk's native URL.
        return method_exists($disk, 'url') ? $disk->url($relative) : ('/storage/' . ltrim($relative, '/'));
    }

    public function latest(string $processId): ?string
    {
        $relative = $this->dir . '/' . $this->safeName($processId) . '.png';
        $disk = $this->disk();
        if (!$disk->exists($relative)) return null;
        return method_exists($disk, 'url') ? $disk->url($relative) : ('/storage/' . ltrim($relative, '/'));
    }

    public function purgeFor(string $processId): void
    {
        $relative = $this->dir . '/' . $this->safeName($processId) . '.png';
        $disk = $this->disk();
        if ($disk->exists($relative)) $disk->delete($relative);
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->disk);
    }

    /** Strip anything that could escape the configured directory. */
    private function safeName(string $id): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]+/', '_', $id) ?? 'unknown';
        return substr($clean, 0, 200);
    }
}
