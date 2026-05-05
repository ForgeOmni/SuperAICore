<?php

declare(strict_types=1);

namespace SuperAICore\Plugins;

/**
 * Workspace-level plugin sharing — codex's "workspace plugin sharing
 * APIs" feature ported to PHP.
 *
 * The use case: a team checks in a `.superaicore/workspace-plugins.json`
 * manifest to their repo. When any team member opens the workspace,
 * `WorkspacePluginRegistry::sync()` reads the manifest and ensures
 * the listed plugins are installed locally — same versions, same
 * source URLs, same defaults. New hires get the full toolset on
 * `git clone` instead of needing a per-machine onboarding doc.
 *
 * The manifest is plain JSON (so a human can edit it in PRs):
 *
 *   {
 *     "plugins": [
 *       {
 *         "name":    "team-pr-review",
 *         "source":  "github.com/our-org/agent-skill-pr-review",
 *         "version": "1.4.0",
 *         "scope":   "workspace"          // or "user" (recommend)
 *       },
 *       …
 *     ]
 *   }
 *
 * `scope=workspace` means "must be installed for everyone working in
 * this repo"; `scope=user` is a recommendation only — the listing is
 * informational.
 *
 * The registry is host-friendly: it reads/writes the JSON, exposes
 * `add` / `remove` / `list` operations, and computes which entries
 * still need installing. Actually doing the install delegates to
 * `PluginInstaller` (which the host wires up; we don't pull it in
 * here so the registry stays trivial to test).
 */
final class WorkspacePluginRegistry
{
    public const SCOPE_WORKSPACE = 'workspace';
    public const SCOPE_USER      = 'user';
    public const MANIFEST_PATH   = '.superaicore/workspace-plugins.json';

    public function __construct(
        private string $workspaceRoot,
    ) {}

    public function manifestPath(): string
    {
        return rtrim($this->workspaceRoot, '/\\') . '/' . self::MANIFEST_PATH;
    }

    /**
     * @return array<int, array{name: string, source: string, version: ?string, scope: string}>
     */
    public function list(): array
    {
        if (! is_readable($this->manifestPath())) {
            return [];
        }
        $raw = (string) file_get_contents($this->manifestPath());
        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ! isset($decoded['plugins']) || ! is_array($decoded['plugins'])) {
            return [];
        }
        $out = [];
        foreach ($decoded['plugins'] as $entry) {
            if (! is_array($entry) || ! isset($entry['name'], $entry['source'])) {
                continue;
            }
            $out[] = [
                'name'    => (string) $entry['name'],
                'source'  => (string) $entry['source'],
                'version' => isset($entry['version']) ? (string) $entry['version'] : null,
                'scope'   => self::normaliseScope($entry['scope'] ?? self::SCOPE_USER),
            ];
        }
        return $out;
    }

    public function add(string $name, string $source, ?string $version = null, string $scope = self::SCOPE_USER): void
    {
        $entries = $this->list();
        // Replace any existing entry with the same name — version /
        // source bump is the common-case operation.
        $entries = array_values(array_filter(
            $entries,
            fn (array $e) => $e['name'] !== $name,
        ));
        $entries[] = [
            'name'    => $name,
            'source'  => $source,
            'version' => $version,
            'scope'   => self::normaliseScope($scope),
        ];
        $this->writeAll($entries);
    }

    public function remove(string $name): bool
    {
        $entries = $this->list();
        $filtered = array_values(array_filter(
            $entries,
            fn (array $e) => $e['name'] !== $name,
        ));
        if (count($filtered) === count($entries)) {
            return false;
        }
        $this->writeAll($filtered);
        return true;
    }

    /**
     * Diff the manifest against a list of locally-installed plugin
     * names. Returns the entries that still need to be installed —
     * scope=workspace is included unconditionally; scope=user
     * entries are reported but flagged so the host can prompt the
     * developer rather than auto-installing.
     *
     * @param list<string> $installedNames
     * @return array{
     *   missing_required: list<array{name: string, source: string, version: ?string}>,
     *   missing_recommended: list<array{name: string, source: string, version: ?string}>,
     * }
     */
    public function pendingInstalls(array $installedNames): array
    {
        $installed = array_flip($installedNames);
        $required = [];
        $recommended = [];
        foreach ($this->list() as $entry) {
            if (isset($installed[$entry['name']])) continue;
            $stripped = ['name' => $entry['name'], 'source' => $entry['source'], 'version' => $entry['version']];
            if ($entry['scope'] === self::SCOPE_WORKSPACE) {
                $required[] = $stripped;
            } else {
                $recommended[] = $stripped;
            }
        }
        return [
            'missing_required'    => $required,
            'missing_recommended' => $recommended,
        ];
    }

    private static function normaliseScope(string $scope): string
    {
        $scope = strtolower(trim($scope));
        return $scope === self::SCOPE_WORKSPACE ? self::SCOPE_WORKSPACE : self::SCOPE_USER;
    }

    /**
     * @param array<int, array{name: string, source: string, version: ?string, scope: string}> $entries
     */
    private function writeAll(array $entries): void
    {
        $dir = dirname($this->manifestPath());
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            $this->manifestPath(),
            json_encode(['plugins' => $entries], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }
}
