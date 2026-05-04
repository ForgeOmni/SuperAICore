<?php

declare(strict_types=1);

namespace SuperAICore\Plugins;

/**
 * Drives `PluginInstaller` over a `MarketplaceManifest`.
 *
 * Two install modes:
 *   - importAll() — install every plugin declared in the marketplace
 *   - importSelected() — install only the named subset (the typical
 *     starting point: a host pulls 4–8 plugins out of ruflo's 32-strong
 *     marketplace, leaves the rest behind).
 *
 * Each row's outcome is reported as a per-plugin `InstallResult` so
 * callers can distinguish success / unchanged / user-edited / failed
 * without re-walking the disk.
 *
 * Skips plugins whose `source` doesn't resolve to a directory on disk
 * (git-URL sources, missing local dirs) — those rows return
 * `InstallResult::STATUS_FAILED` with a clear error so the operator can
 * see the gap. They never throw.
 */
final class MarketplaceInstaller
{
    public function __construct(
        private readonly PluginInstaller $installer,
    ) {}

    /**
     * @return array<string, InstallResult> keyed by plugin name
     */
    public function importAll(
        MarketplaceManifest $marketplace,
        string $targetParentDir,
        bool $force = false,
        bool $dryRun = false,
    ): array {
        $names = array_map(fn (MarketplaceEntry $e) => $e->name, $marketplace->plugins);
        return $this->importSelected($marketplace, $names, $targetParentDir, $force, $dryRun);
    }

    /**
     * @param  string[] $names
     * @return array<string, InstallResult>
     */
    public function importSelected(
        MarketplaceManifest $marketplace,
        array $names,
        string $targetParentDir,
        bool $force = false,
        bool $dryRun = false,
    ): array {
        $byName = [];
        foreach ($marketplace->plugins as $entry) {
            $byName[$entry->name] = $entry;
        }

        $report = [];
        foreach ($names as $name) {
            if (!isset($byName[$name])) {
                $report[$name] = new InstallResult(
                    name: $name,
                    status: InstallResult::STATUS_FAILED,
                    targetDir: '',
                    error: 'not in marketplace',
                );
                continue;
            }
            $entry = $byName[$name];
            $resolved = $entry->resolvedPath();

            if (!is_dir($resolved)) {
                $report[$name] = new InstallResult(
                    name: $name,
                    status: InstallResult::STATUS_FAILED,
                    targetDir: '',
                    error: "source not a directory: {$resolved}",
                );
                continue;
            }

            $report[$name] = $this->installer->install($resolved, $targetParentDir, $force, $dryRun);
        }
        return $report;
    }
}
