<?php

declare(strict_types=1);

namespace SuperAICore\Services;

use Psr\Log\LoggerInterface;
use SuperAgent\Checkpoint\GitShadowStore;
use Symfony\Component\Process\Process;

/**
 * Per-file diff summarizer that wraps SuperAgent's `GitShadowStore`.
 *
 * SDK contract: every SuperAgent run that has the optional `GitShadowStore`
 * attached stamps `Checkpoint::$metadata['shadow_commit']` on each saved
 * checkpoint. Dispatcher/SuperAgentBackend captures two commits per
 * dispatch (the pre-run one and the post-run one) and persists them on
 * `ai_usage_logs.pre_snapshot` / `post_snapshot`.
 *
 * This service then renders those two refs through `git diff` against the
 * shadow repo and returns a uniform `{additions, deletions, files, diffs:
 * [{file, additions, deletions, status, patch}]}` envelope. Modeled after
 * opencode's `session/summary.ts` + `snapshot.diffFull()` API.
 *
 * Why not put this in the SDK: the diff is purely a UI/analytics
 * concern. SuperAgent only needs `snapshot()` + `restore()` to
 * checkpoint and revert; producing structured diffs is the host's job
 * because the host owns the audit log + UI.
 */
class SnapshotDiffService
{
    /** Hard cap on per-file patch size before truncation (bytes). */
    private const PATCH_MAX_BYTES = 256 * 1024;

    /** Total diffs cap before truncation kicks in. */
    private const MAX_FILES = 200;

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Snapshot the current worktree state and return the shadow commit
     * sha. Returns null when the project root isn't tracked (read-only
     * mount, no $HOME, etc.) or git is missing on PATH — caller silently
     * degrades to "no snapshot" instead of failing the dispatch.
     *
     * `$label` ends up in the shadow commit message for easy debugging
     * (`git log` inside the shadow repo).
     */
    public function snapshot(string $projectRoot, string $label): ?string
    {
        try {
            $store = new GitShadowStore($projectRoot);
            $store->init();
            return $store->snapshot($label);
        } catch (\Throwable $e) {
            $this->logger?->debug('SnapshotDiffService: snapshot failed: ' . $e->getMessage(), [
                'project_root' => $projectRoot,
                'label'        => $label,
            ]);
            return null;
        }
    }

    /**
     * Restore the worktree to the snapshot identified by `$hash`. Returns
     * true on success. Mirrors `Snapshot.restore()` in opencode —
     * tracked files revert; untracked files are LEFT in place (SuperAgent
     * SDK contract).
     */
    public function restore(string $projectRoot, string $hash): bool
    {
        try {
            $store = new GitShadowStore($projectRoot);
            if (!$store->has($hash)) return false;
            $store->restore($hash);
            return true;
        } catch (\Throwable $e) {
            $this->logger?->warning('SnapshotDiffService: restore failed: ' . $e->getMessage(), [
                'project_root' => $projectRoot,
                'hash'         => $hash,
            ]);
            return false;
        }
    }

    /**
     * Compute the per-file diff envelope between two shadow-git commits.
     *
     * Returns null when either snapshot is missing, the shadow repo isn't
     * initialized, or git isn't on PATH. The empty `diffs: []` envelope is
     * still returned when both commits exist but the worktree didn't change
     * (e.g. read-only agents) — that's distinct from "we couldn't diff."
     *
     * @return array{additions:int, deletions:int, files:int, diffs:list<array{file:string, additions:int, deletions:int, status:string, patch:string, truncated:bool}>, truncated:bool}|null
     */
    public function diff(string $projectRoot, string $from, ?string $to): ?array
    {
        if ($from === '' || $to === null || $to === '' || $from === $to) {
            // No-change envelope when both refs point at the same commit
            // (the dispatch produced no shadow movement, e.g. read-only).
            return ['additions' => 0, 'deletions' => 0, 'files' => 0, 'diffs' => [], 'truncated' => false];
        }

        try {
            $store = new GitShadowStore($projectRoot);
        } catch (\Throwable $e) {
            $this->logger?->debug('SnapshotDiffService: shadow store unavailable: ' . $e->getMessage());
            return null;
        }

        $shadowDir = $store->shadowDir();
        if (!is_dir($shadowDir)) return null;

        // First pass: numstat for additions/deletions per file + status.
        $stat = $this->git($shadowDir, ['diff', '--no-color', '--numstat', $from, $to]);
        if ($stat === null) return null;

        $perFile = [];
        foreach (preg_split('/\r?\n/', $stat) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $parts = preg_split('/\s+/', $line, 3);
            if (!is_array($parts) || count($parts) < 3) continue;
            [$add, $del, $path] = $parts;
            $perFile[$path] = [
                'file'      => $path,
                'additions' => is_numeric($add) ? (int) $add : 0,
                'deletions' => is_numeric($del) ? (int) $del : 0,
                'status'    => 'modified',
                'patch'     => '',
                'truncated' => false,
            ];
        }

        // Status pass: A (added) / D (deleted) / M (modified) / R (renamed).
        $statusOut = $this->git($shadowDir, ['diff', '--no-color', '--name-status', $from, $to]);
        if ($statusOut !== null) {
            foreach (preg_split('/\r?\n/', $statusOut) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') continue;
                $parts = preg_split('/\s+/', $line, 3);
                if (!is_array($parts) || count($parts) < 2) continue;
                $code = strtoupper((string) $parts[0]);
                $path = $parts[count($parts) - 1];
                $kind = match (true) {
                    str_starts_with($code, 'A') => 'added',
                    str_starts_with($code, 'D') => 'deleted',
                    str_starts_with($code, 'R') => 'renamed',
                    default                     => 'modified',
                };
                if (isset($perFile[$path])) {
                    $perFile[$path]['status'] = $kind;
                } else {
                    $perFile[$path] = [
                        'file'      => $path,
                        'additions' => 0,
                        'deletions' => 0,
                        'status'    => $kind,
                        'patch'     => '',
                        'truncated' => false,
                    ];
                }
            }
        }

        // Patch pass: full per-file unified diff. Truncate at PATCH_MAX_BYTES
        // so a single huge patch can't blow up the UsageLog row.
        $patchOut = $this->git($shadowDir, ['diff', '--no-color', $from, $to]);
        if ($patchOut !== null) {
            $sections = $this->splitPatchByFile($patchOut);
            foreach ($sections as $file => $patch) {
                if (!isset($perFile[$file])) continue;
                if (strlen($patch) > self::PATCH_MAX_BYTES) {
                    $perFile[$file]['patch']     = substr($patch, 0, self::PATCH_MAX_BYTES);
                    $perFile[$file]['truncated'] = true;
                } else {
                    $perFile[$file]['patch'] = $patch;
                }
            }
        }

        $diffs = array_values($perFile);
        $envelopeTruncated = false;
        if (count($diffs) > self::MAX_FILES) {
            $diffs = array_slice($diffs, 0, self::MAX_FILES);
            $envelopeTruncated = true;
        }

        $additions = array_sum(array_column($diffs, 'additions'));
        $deletions = array_sum(array_column($diffs, 'deletions'));

        return [
            'additions' => (int) $additions,
            'deletions' => (int) $deletions,
            'files'     => count($diffs),
            'diffs'     => $diffs,
            'truncated' => $envelopeTruncated,
        ];
    }

    /**
     * Run `git` against the bare shadow repo and return stdout. Swallows
     * non-zero exits and missing-binary errors with a debug log; returns
     * null on any failure so callers can degrade gracefully.
     */
    private function git(string $shadowDir, array $args): ?string
    {
        try {
            $proc = new Process(array_merge(['git', '--git-dir=' . $shadowDir], $args));
            $proc->setTimeout(15);
            $proc->run();
            if (!$proc->isSuccessful()) {
                $this->logger?->debug('SnapshotDiffService: git failed', [
                    'shadow_dir' => $shadowDir,
                    'args'       => $args,
                    'stderr'     => $proc->getErrorOutput(),
                ]);
                return null;
            }
            return $proc->getOutput();
        } catch (\Throwable $e) {
            $this->logger?->debug('SnapshotDiffService: git process exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Split a unified-diff blob into per-file patches keyed by the new file
     * path (`b/<path>` side of the `diff --git a/X b/Y` header).
     *
     * @return array<string,string>
     */
    private function splitPatchByFile(string $patch): array
    {
        $out = [];
        $current = null;
        $buf = '';
        foreach (preg_split('/\r?\n/', $patch) ?: [] as $line) {
            if (preg_match('#^diff --git a/(.+?) b/(.+)$#', $line, $m)) {
                if ($current !== null) $out[$current] = rtrim($buf, "\n");
                $current = $m[2];
                $buf     = $line . "\n";
                continue;
            }
            if ($current !== null) {
                $buf .= $line . "\n";
            }
        }
        if ($current !== null) $out[$current] = rtrim($buf, "\n");
        return $out;
    }
}
