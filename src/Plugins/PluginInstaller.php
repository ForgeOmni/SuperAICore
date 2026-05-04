<?php

declare(strict_types=1);

namespace SuperAICore\Plugins;

use SuperAICore\Sync\Manifest;

/**
 * Installs a single plugin directory into a target plugin scope by
 * recursive copy.
 *
 * Target convention: `<targetParentDir>/<plugin-name>/` mirrors the
 * source layout verbatim. This is the same layout SkillRegistry expects
 * at `~/.claude/plugins/<name>/skills/<skill>/SKILL.md`, so installing
 * a ruflo plugin to `$HOME/.claude/plugins/` makes its skills /
 * agents / commands immediately discoverable by every command that
 * resolves through `SkillRegistry` / `AgentRegistry`.
 *
 * Drift detection: every file we write is sha-256'd into a per-target
 * `.superaicore-manifest.json`. On re-install we compare:
 *   - source hash matches manifest → status `unchanged` (no writes)
 *   - source hash differs, on-disk hash matches manifest → `updated`
 *     (user hasn't touched it since we last wrote)
 *   - source hash differs, on-disk hash differs from manifest → user
 *     edited locally; refuse to overwrite without `force=true`. This is
 *     the same contract `CopilotHookWriter` uses.
 *
 * v1 ships copy-mode only. Symlink mode is intentionally deferred —
 * Windows symlinks need elevated privs and ruflo's plugin payloads are
 * small (KB), so copy is the simpler default.
 */
final class PluginInstaller
{
    public function __construct(
        /** Optional logger callable invoked for verbose progress. */
        private $progress = null,
    ) {}

    /**
     * Install a single plugin from sourceDir into targetParentDir.
     *
     * @param  string $sourceDir       Plugin root (must contain a discoverable plugin.json)
     * @param  string $targetParentDir Where `<name>/` will be created (e.g. ~/.claude/plugins)
     * @param  bool   $force           Overwrite even if user has edited installed files
     * @param  bool   $dryRun          Print what would change without touching disk
     */
    public function install(
        string $sourceDir,
        string $targetParentDir,
        bool $force = false,
        bool $dryRun = false,
    ): InstallResult {
        $manifestPath = PluginManifest::discoverManifestPath($sourceDir);
        if ($manifestPath === null) {
            return new InstallResult(
                name: basename($sourceDir),
                status: InstallResult::STATUS_FAILED,
                targetDir: '',
                error: "no plugin.json in {$sourceDir}",
            );
        }

        try {
            $manifest = PluginManifest::fromJsonFile($manifestPath);
        } catch (\Throwable $e) {
            return new InstallResult(
                name: basename($sourceDir),
                status: InstallResult::STATUS_FAILED,
                targetDir: '',
                error: 'invalid manifest: ' . $e->getMessage(),
            );
        }

        $targetDir = rtrim($targetParentDir, '/\\') . DIRECTORY_SEPARATOR . $manifest->name;
        $ledger = new Manifest($targetDir . DIRECTORY_SEPARATOR . '.superaicore-install.json');
        $previousEntries = $ledger->read();

        // Walk the source, build the target file plan.
        $plan = [];
        foreach ($this->walk($sourceDir) as $relPath => $absPath) {
            $plan[$relPath] = [
                'src'     => $absPath,
                'srcHash' => hash_file('sha256', $absPath),
            ];
        }
        if ($plan === []) {
            return new InstallResult(
                name: $manifest->name,
                status: InstallResult::STATUS_FAILED,
                targetDir: $targetDir,
                error: 'source dir is empty',
                manifest: $manifest,
            );
        }

        // Detect user edits on previously-installed files.
        if (!$force && is_dir($targetDir)) {
            foreach ($previousEntries as $rel => $prevHash) {
                $abs = $targetDir . DIRECTORY_SEPARATOR . $rel;
                if (!is_file($abs)) continue;
                $current = hash_file('sha256', $abs);
                if ($current !== $prevHash) {
                    return new InstallResult(
                        name: $manifest->name,
                        status: InstallResult::STATUS_USER_EDITED,
                        targetDir: $targetDir,
                        error: "user-edited file: {$rel}",
                        manifest: $manifest,
                    );
                }
            }
        }

        // Decide overall status before mutation.
        $isFresh = !is_dir($targetDir) || $previousEntries === [];
        $allMatch = !$isFresh;
        if ($allMatch) {
            foreach ($plan as $rel => $row) {
                $abs = $targetDir . DIRECTORY_SEPARATOR . $rel;
                if (!is_file($abs) || hash_file('sha256', $abs) !== $row['srcHash']) {
                    $allMatch = false;
                    break;
                }
            }
            // Also check there are no extra files we'd need to remove.
            foreach (array_keys($previousEntries) as $rel) {
                if (!isset($plan[$rel])) {
                    $allMatch = false;
                    break;
                }
            }
        }

        if ($allMatch) {
            return new InstallResult(
                name: $manifest->name,
                status: InstallResult::STATUS_UNCHANGED,
                targetDir: $targetDir,
                filesCopied: 0,
                manifest: $manifest,
            );
        }

        if ($dryRun) {
            return new InstallResult(
                name: $manifest->name,
                status: $isFresh ? InstallResult::STATUS_INSTALLED : InstallResult::STATUS_UPDATED,
                targetDir: $targetDir,
                filesCopied: count($plan),
                manifest: $manifest,
            );
        }

        // Apply: ensure target dir, copy each file, write ledger.
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            return new InstallResult(
                name: $manifest->name,
                status: InstallResult::STATUS_FAILED,
                targetDir: $targetDir,
                error: 'failed to create target dir',
                manifest: $manifest,
            );
        }

        $copied = 0;
        $newEntries = [];
        foreach ($plan as $rel => $row) {
            $dst = $targetDir . DIRECTORY_SEPARATOR . $rel;
            $dstDir = dirname($dst);
            if (!is_dir($dstDir)) @mkdir($dstDir, 0755, true);
            if (!@copy($row['src'], $dst)) {
                return new InstallResult(
                    name: $manifest->name,
                    status: InstallResult::STATUS_FAILED,
                    targetDir: $targetDir,
                    error: "copy failed: {$rel}",
                    filesCopied: $copied,
                    manifest: $manifest,
                );
            }
            $newEntries[$rel] = $row['srcHash'];
            $copied++;
            $this->log("  + {$rel}");
        }

        // Remove previously-installed files no longer in the source.
        foreach (array_keys($previousEntries) as $rel) {
            if (isset($plan[$rel])) continue;
            $dead = $targetDir . DIRECTORY_SEPARATOR . $rel;
            if (is_file($dead)) {
                @unlink($dead);
                $this->log("  - {$rel}");
            }
        }

        $ledger->write($newEntries);

        return new InstallResult(
            name: $manifest->name,
            status: $isFresh ? InstallResult::STATUS_INSTALLED : InstallResult::STATUS_UPDATED,
            targetDir: $targetDir,
            filesCopied: $copied,
            manifest: $manifest,
        );
    }

    /**
     * Remove a previously-installed plugin's tree. Honours the manifest:
     * we only delete files we recorded ourselves, so any file the user
     * dropped into the plugin dir later is preserved.
     */
    public function uninstall(string $name, string $targetParentDir): InstallResult
    {
        $targetDir = rtrim($targetParentDir, '/\\') . DIRECTORY_SEPARATOR . $name;
        $ledger = new Manifest($targetDir . DIRECTORY_SEPARATOR . '.superaicore-install.json');
        $entries = $ledger->read();

        if (!is_dir($targetDir)) {
            return new InstallResult($name, InstallResult::STATUS_UNCHANGED, $targetDir);
        }

        $removed = 0;
        foreach (array_keys($entries) as $rel) {
            $abs = $targetDir . DIRECTORY_SEPARATOR . $rel;
            if (is_file($abs)) {
                @unlink($abs);
                $removed++;
            }
        }
        @unlink($targetDir . DIRECTORY_SEPARATOR . '.superaicore-install.json');

        $this->pruneEmptyDirs($targetDir);

        return new InstallResult(
            name: $name,
            status: InstallResult::STATUS_REMOVED,
            targetDir: $targetDir,
            filesCopied: $removed,
        );
    }

    /**
     * @return iterable<string, string> relative path => absolute source path
     */
    private function walk(string $root): iterable
    {
        $rootLen = strlen(rtrim($root, '/\\')) + 1;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($it as $info) {
            /** @var \SplFileInfo $info */
            if (!$info->isFile()) continue;
            $rel = substr($info->getPathname(), $rootLen);
            $rel = str_replace('\\', '/', $rel);
            // Skip our own ledger file if the source happens to contain one.
            if ($rel === '.superaicore-install.json') continue;
            yield $rel => $info->getPathname();
        }
    }

    private function pruneEmptyDirs(string $dir): void
    {
        if (!is_dir($dir)) return;
        $children = scandir($dir) ?: [];
        foreach ($children as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $p = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($p)) $this->pruneEmptyDirs($p);
        }
        // After recursion, attempt removal — fails silently if non-empty.
        @rmdir($dir);
    }

    private function log(string $msg): void
    {
        if (is_callable($this->progress)) {
            ($this->progress)($msg);
        }
    }
}
