<?php

declare(strict_types=1);

namespace SuperAICore\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;

/**
 * Browse and view Dispatcher trace dumps.
 *
 * Trace files are written by `TraceCollector::dump()` on triggers
 * (Provider rotation, QuotaExceededException, manual dispatcher:dump-trace).
 * This controller surfaces:
 *
 *   GET /traces          → list every .trace.json under the storage dir,
 *                          newest first, with metadata badges
 *   GET /traces/raw/{f}  → raw JSON download (so Perfetto-style viewers
 *                          can fetch over HTTP)
 *   GET /traces/{f}      → embed the bundled trace-viewer.html with the
 *                          file path pre-loaded (no upload step)
 *
 * Storage path is config('super-ai-core.tracing.storage_path') and defaults
 * to storage_path('app/superaicore/traces'). The controller refuses any
 * filename that isn't a basename under that directory — no traversal escapes.
 */
class TraceController extends Controller
{
    public function index(Request $request)
    {
        $dir = $this->storageDir();
        $traces = $this->listTraces($dir);

        return view('super-ai-core::traces.index', [
            'traces' => $traces,
            'dir'    => $dir,
        ]);
    }

    public function show(Request $request, string $filename)
    {
        $path = $this->resolveSafePath($filename);
        if ($path === null) {
            abort(404, 'Trace file not found.');
        }

        $payload = json_decode((string) File::get($path), true);
        $metadata = is_array($payload) && isset($payload['metadata']) ? $payload['metadata'] : [];

        return view('super-ai-core::traces.show', [
            'filename' => $filename,
            'metadata' => $metadata,
            'event_count' => is_array($payload['traceEvents'] ?? null) ? count($payload['traceEvents']) : 0,
            'raw_url' => route('super-ai-core.traces.raw', ['filename' => $filename]),
            'size_bytes' => File::size($path),
        ]);
    }

    public function raw(Request $request, string $filename)
    {
        $path = $this->resolveSafePath($filename);
        if ($path === null) {
            abort(404, 'Trace file not found.');
        }

        return response()->file($path, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store',
        ]);
    }

    protected function storageDir(): string
    {
        $configured = config('super-ai-core.tracing.storage_path');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        return storage_path('app/superaicore/traces');
    }

    /**
     * Reject filenames with path separators or traversal segments. Only
     * accept basenames that exist inside the configured storage dir.
     */
    protected function resolveSafePath(string $filename): ?string
    {
        if (str_contains($filename, '/') || str_contains($filename, '\\') || str_contains($filename, '..')) {
            return null;
        }
        if (!preg_match('/^trace_[A-Za-z0-9._-]+\.json$/', $filename)) {
            return null;
        }
        $candidate = rtrim($this->storageDir(), "/\\") . DIRECTORY_SEPARATOR . $filename;
        return File::exists($candidate) ? $candidate : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    protected function listTraces(string $dir): array
    {
        if (!File::isDirectory($dir)) return [];

        $out = [];
        foreach (File::files($dir) as $file) {
            if (!preg_match('/^trace_.+\.json$/', $file->getFilename())) continue;

            $meta = [];
            try {
                $decoded = json_decode((string) $file->getContents(), true);
                if (is_array($decoded) && isset($decoded['metadata'])) {
                    $meta = $decoded['metadata'];
                }
            } catch (\Throwable $e) {
                // skip — corrupt file shouldn't break the listing
                continue;
            }

            $out[] = [
                'filename'    => $file->getFilename(),
                'size_bytes'  => $file->getSize(),
                'modified_at' => date('c', $file->getMTime()),
                'producer'    => $meta['producer'] ?? '?',
                'trigger'     => $meta['trigger'] ?? 'unknown',
                'reason'      => $meta['trigger_reason'] ?? null,
                'session_id'  => $meta['session_id'] ?? null,
                'event_count' => $meta['event_count'] ?? null,
            ];
        }

        // Newest first
        usort($out, fn($a, $b) => strcmp($b['modified_at'], $a['modified_at']));
        return $out;
    }
}
