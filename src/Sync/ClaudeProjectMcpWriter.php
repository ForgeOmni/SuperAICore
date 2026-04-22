<?php

namespace SuperAICore\Sync;

/**
 * Writes a host's project-scope `.mcp.json` from a name-list subset of a
 * McpCatalog. This is the Tier-1 always-loaded set — kept minimal so every
 * Claude Code session's baseline memory cost stays low.
 *
 * Non-destructive contract (via AbstractManifestWriter):
 *   - If on-disk `.mcp.json` hash == rendered hash → unchanged
 *   - If on-disk differs AND manifest says we wrote the previous hash →
 *     user edited; we leave it (status: user-edited)
 *   - First-time write or our-last-write matches disk → overwrite
 */
final class ClaudeProjectMcpWriter extends AbstractManifestWriter
{
    /**
     * @param string $mcpJsonPath absolute path to host's `.mcp.json`
     */
    public function __construct(
        private readonly string $mcpJsonPath,
        Manifest $manifest,
    ) {
        parent::__construct($manifest);
    }

    /**
     * @param array<string, array{command:string,args:array<int,string>,env:array<string,string>,type:string}> $servers
     * @return array{status:string, path:string}
     */
    public function sync(array $servers, bool $dryRun = false): array
    {
        $contents = self::render($servers);

        if ($dryRun) {
            $hash = hash('sha256', $contents);
            if (is_file($this->mcpJsonPath)
                && hash('sha256', (string) @file_get_contents($this->mcpJsonPath)) === $hash) {
                return ['status' => self::STATUS_UNCHANGED, 'path' => $this->mcpJsonPath];
            }
            return ['status' => self::STATUS_WRITTEN, 'path' => $this->mcpJsonPath];
        }

        return $this->applyOne($this->mcpJsonPath, $contents);
    }

    /**
     * @param array<string, array{command:string,args:array<int,string>,env:array<string,string>,type:string}> $servers
     */
    public static function render(array $servers): string
    {
        $mcpServers = [];
        foreach ($servers as $name => $cfg) {
            $entry = ['type' => $cfg['type'] ?? 'stdio', 'command' => $cfg['command']];
            if (!empty($cfg['args'])) {
                $entry['args'] = array_values($cfg['args']);
            }
            if (!empty($cfg['env'])) {
                $entry['env'] = $cfg['env'];
            }
            $mcpServers[$name] = $entry;
        }
        return json_encode(['mcpServers' => $mcpServers],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
}
