<?php

declare(strict_types=1);

namespace SuperAICore\Plugins;

/**
 * Parsed `plugin.json` manifest. Schema is intentionally identical to
 * `SuperAgent\Plugins\PluginManifest` — the two packages share a wire
 * format so a plugin authored for one ecosystem loads in the other.
 *
 * Compatible with both:
 *
 *   1. SuperAgent's original layout — `<plugin>/plugin.json`, fields:
 *      name, version, description, enabled_by_default, skills_dir,
 *      hooks_file, mcp_file.
 *
 *   2. Claude Code / ruflo layout — `<plugin>/.claude-plugin/plugin.json`,
 *      fields: name, version, description, author (string|object),
 *      homepage, license, keywords[]. Subdirs at plugin root:
 *      `agents/`, `commands/`, `skills/`.
 *
 * SuperAICore consumes this for the marketplace UI and the (future)
 * `plugins:install` artisan command — see `MarketplaceImporter` (W2).
 */
final class PluginManifest
{
    /**
     * @param string[] $keywords
     */
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly ?string $description = null,
        public readonly bool $enabledByDefault = false,
        public readonly string $skillsDir = 'skills',
        public readonly ?string $hooksFile = 'hooks.json',
        public readonly ?string $mcpFile = 'mcp.json',
        public readonly string $agentsDir = 'agents',
        public readonly string $commandsDir = 'commands',
        public readonly ?string $author = null,
        public readonly ?string $authorUrl = null,
        public readonly ?string $homepage = null,
        public readonly ?string $license = null,
        public readonly array $keywords = [],
    ) {}

    public static function fromArray(array $data): self
    {
        if (empty($data['name'])) {
            throw new \InvalidArgumentException('Plugin manifest must include a "name" field.');
        }
        if (empty($data['version'])) {
            throw new \InvalidArgumentException('Plugin manifest must include a "version" field.');
        }

        $author = null;
        $authorUrl = null;
        if (isset($data['author'])) {
            if (is_string($data['author'])) {
                $author = $data['author'];
            } elseif (is_array($data['author'])) {
                $author    = isset($data['author']['name']) ? (string) $data['author']['name'] : null;
                $authorUrl = isset($data['author']['url'])  ? (string) $data['author']['url']  : null;
            }
        }

        $keywords = [];
        if (isset($data['keywords']) && is_array($data['keywords'])) {
            foreach ($data['keywords'] as $k) {
                if (is_string($k) && $k !== '') $keywords[] = $k;
            }
        }

        return new self(
            name: (string) $data['name'],
            version: (string) $data['version'],
            description: isset($data['description']) ? (string) $data['description'] : null,
            enabledByDefault: (bool) ($data['enabled_by_default'] ?? $data['enabledByDefault'] ?? false),
            skillsDir: (string) ($data['skills_dir'] ?? $data['skillsDir'] ?? 'skills'),
            hooksFile: isset($data['hooks_file']) || isset($data['hooksFile'])
                ? (string) ($data['hooks_file'] ?? $data['hooksFile'])
                : 'hooks.json',
            mcpFile: isset($data['mcp_file']) || isset($data['mcpFile'])
                ? (string) ($data['mcp_file'] ?? $data['mcpFile'])
                : 'mcp.json',
            agentsDir: (string) ($data['agents_dir'] ?? $data['agentsDir'] ?? 'agents'),
            commandsDir: (string) ($data['commands_dir'] ?? $data['commandsDir'] ?? 'commands'),
            author: $author,
            authorUrl: $authorUrl,
            homepage: isset($data['homepage']) ? (string) $data['homepage'] : null,
            license: isset($data['license']) ? (string) $data['license'] : null,
            keywords: $keywords,
        );
    }

    public static function fromJsonFile(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Plugin manifest file not found: {$path}");
        }
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException("Failed to read plugin manifest: {$path}");
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Invalid JSON in plugin manifest: {$path}");
        }
        return self::fromArray($data);
    }

    /**
     * Resolve a plugin root to its manifest path. Tries:
     *   1. `<root>/.claude-plugin/plugin.json` (Claude Code / ruflo spec)
     *   2. `<root>/plugin.json` (SuperAgent legacy)
     */
    public static function discoverManifestPath(string $pluginRoot): ?string
    {
        $root = rtrim($pluginRoot, '/\\');
        $candidates = [
            $root . DIRECTORY_SEPARATOR . '.claude-plugin' . DIRECTORY_SEPARATOR . 'plugin.json',
            $root . DIRECTORY_SEPARATOR . 'plugin.json',
        ];
        foreach ($candidates as $p) {
            if (is_file($p)) return $p;
        }
        return null;
    }
}
