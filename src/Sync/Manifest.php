<?php

namespace SuperAICore\Sync;

/**
 * Persists the list of files superaicore has written into
 * `~/.gemini/commands/` so subsequent syncs can:
 *
 *   1. Remove files that used to back a skill/agent that no longer
 *      exists (clean up stale TOMLs).
 *   2. Detect user-modified TOMLs and skip overwriting them — we store
 *      the sha256 of what we last wrote; if the file on disk differs,
 *      the user has edited it and we leave it alone.
 *
 * Manifest path: `<gemini-home>/commands/.superaicore-manifest.json`
 *
 * Shape:
 * {
 *   "version": 1,
 *   "generated_at": "2026-04-17T12:34:56+00:00",
 *   "entries": { "<absolute path>": "<sha256 hex>" }
 * }
 */
final class Manifest
{
    public const VERSION = 1;

    public function __construct(private readonly string $path) {}

    /** @return array<string,string> path => sha256 */
    public function read(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $raw = @file_get_contents($this->path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['entries']) || !is_array($data['entries'])) {
            return [];
        }
        $out = [];
        foreach ($data['entries'] as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /** @param array<string,string> $entries path => sha256 */
    public function write(array $entries): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $payload = [
            'version'      => self::VERSION,
            'generated_at' => date('c'),
            'entries'      => $entries,
        ];
        @file_put_contents(
            $this->path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    public function path(): string
    {
        return $this->path;
    }
}
