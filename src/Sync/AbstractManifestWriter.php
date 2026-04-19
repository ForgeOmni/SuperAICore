<?php

namespace SuperAICore\Sync;

/**
 * Shared non-destructive sync skeleton for writers that materialize
 * superaicore-owned files under an external CLI's config dir (Gemini
 * commands, Copilot agents, …).
 *
 * Subclasses implement a single method: how to render the desired set of
 * files for a given input. Everything else — on-disk comparison, user-edit
 * detection, manifest round-trips, dry-run, stale cleanup — lives here.
 *
 * Invariants preserved for every subclass:
 *   1. Byte-equal file on disk → status `unchanged`, no write.
 *   2. File differs AND manifest says we wrote the previous version → user
 *      has edited it; we do NOT overwrite. Manifest entry for this path is
 *      retained so the "we originally wrote it" evidence persists.
 *   3. Source entry disappears → we delete the file we previously wrote,
 *      unless the user has edited it (then `stale-kept`).
 *   4. User deleted our file → recreated on the next sync.
 *   5. dryRun=true never touches disk or manifest.
 */
abstract class AbstractManifestWriter
{
    public const STATUS_WRITTEN     = 'written';
    public const STATUS_UNCHANGED   = 'unchanged';
    public const STATUS_USER_EDITED = 'user-edited';
    public const STATUS_REMOVED     = 'removed';
    public const STATUS_STALE_KEPT  = 'stale-kept';

    public function __construct(protected readonly Manifest $manifest) {}

    /**
     * Apply the desired target set to disk.
     *
     * @param  array<string, array{contents:string, source:?string}>  $targets path → {contents, source}
     * @return array{written:string[], unchanged:string[], user_edited:string[], removed:string[], stale_kept:string[]}
     */
    protected function applyTargets(array $targets, bool $dryRun): array
    {
        $report = [
            'written'     => [],
            'unchanged'   => [],
            'user_edited' => [],
            'removed'     => [],
            'stale_kept'  => [],
        ];

        $previousEntries = $this->manifest->read();
        $nextEntries     = [];

        foreach ($targets as $path => $target) {
            $contents = $target['contents'];
            $hash = hash('sha256', $contents);

            if (is_file($path)) {
                $onDisk  = (string) @file_get_contents($path);
                $current = hash('sha256', $onDisk);
                $ours    = $previousEntries[$path] ?? null;

                if ($current === $hash) {
                    $report['unchanged'][] = $path;
                    $nextEntries[$path] = $hash;
                    continue;
                }

                if ($ours !== null && $ours !== $current) {
                    $report['user_edited'][] = $path;
                    $nextEntries[$path] = $ours;
                    continue;
                }
            }

            if (!$dryRun) {
                $dir = dirname($path);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                @file_put_contents($path, $contents);
            }
            $report['written'][] = $path;
            $nextEntries[$path] = $hash;
        }

        foreach ($previousEntries as $oldPath => $oldHash) {
            if (isset($targets[$oldPath])) {
                continue;
            }
            if (!is_file($oldPath)) {
                continue;
            }
            $current = hash('sha256', (string) @file_get_contents($oldPath));
            if ($current !== $oldHash) {
                $report['stale_kept'][] = $oldPath;
                $nextEntries[$oldPath]  = $oldHash;
                continue;
            }
            if (!$dryRun) {
                @unlink($oldPath);
            }
            $report['removed'][] = $oldPath;
        }

        if (!$dryRun) {
            $this->manifest->write($nextEntries);
        }

        return $report;
    }

    /**
     * Single-target fast-path for "make sure this one file is fresh" callers
     * (e.g. CopilotAgentWriter::syncOne used lazily by the runner).
     *
     * @return array{status:string, path:string}
     */
    protected function applyOne(string $path, string $contents): array
    {
        $hash = hash('sha256', $contents);
        $manifest = $this->manifest->read();

        if (is_file($path)) {
            $current = hash('sha256', (string) @file_get_contents($path));
            if ($current === $hash) {
                return ['status' => self::STATUS_UNCHANGED, 'path' => $path];
            }
            $ours = $manifest[$path] ?? null;
            if ($ours !== null && $ours !== $current) {
                return ['status' => self::STATUS_USER_EDITED, 'path' => $path];
            }
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($path, $contents);

        $manifest[$path] = $hash;
        $this->manifest->write($manifest);

        return ['status' => self::STATUS_WRITTEN, 'path' => $path];
    }
}
