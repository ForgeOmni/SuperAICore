<?php

namespace SuperAICore\Services;

/**
 * Loads an MCP server catalog JSON file and exposes simple queries.
 *
 * Why separate from McpManager: McpManager's $registry is installer-oriented
 * (icons, categories, auth flows, install_dir/entrypoint for servers that
 * need on-machine builds). McpCatalog is the runtime-config view — just
 * `{name => {command, args, env}}` with portable paths — used by the
 * project/agent sync writers.
 *
 * Catalog file shape (host-supplied):
 *   {
 *     "mcpServers": {
 *       "<name>": {"command": "...", "args": [...], "env": {...}},
 *       ...
 *     },
 *     "domains": { "<domain>": ["<name>", ...], ... }   // optional
 *   }
 *
 * Host-defined root: pass the catalog path to the constructor.
 */
final class McpCatalog
{
    /** @var array<string, array{command:string, args:array<int,string>, env:array<string,string>, type:string}> */
    private array $servers;

    /** @var array<string, array<int, string>> */
    private array $domains;

    public function __construct(string $catalogPath)
    {
        if (!is_file($catalogPath)) {
            throw new \RuntimeException("MCP catalog not found: {$catalogPath}");
        }
        $raw = file_get_contents($catalogPath);
        $data = json_decode((string) $raw, true);
        if (!is_array($data) || !isset($data['mcpServers']) || !is_array($data['mcpServers'])) {
            throw new \RuntimeException("MCP catalog malformed (missing mcpServers): {$catalogPath}");
        }
        $this->servers = [];
        foreach ($data['mcpServers'] as $name => $cfg) {
            if (!is_string($name) || !is_array($cfg)) continue;
            $this->servers[$name] = [
                'type'    => (string) ($cfg['type'] ?? 'stdio'),
                'command' => (string) ($cfg['command'] ?? ''),
                'args'    => array_values(array_map('strval', (array) ($cfg['args'] ?? []))),
                'env'     => (array) ($cfg['env'] ?? []),
            ];
        }
        $this->domains = (array) ($data['domains'] ?? []);
    }

    /** @return string[] */
    public function names(): array
    {
        return array_keys($this->servers);
    }

    public function has(string $name): bool
    {
        return isset($this->servers[$name]);
    }

    /** @return array{command:string,args:array<int,string>,env:array<string,string>,type:string} */
    public function get(string $name): array
    {
        if (!isset($this->servers[$name])) {
            throw new \InvalidArgumentException("Unknown MCP server: {$name}");
        }
        return $this->servers[$name];
    }

    /**
     * Return a sub-catalog containing only the requested names, preserving
     * input order. Unknown names throw — the sync command treats them as
     * configuration errors rather than silently dropping them.
     *
     * @param  string[] $names
     * @return array<string, array{command:string,args:array<int,string>,env:array<string,string>,type:string}>
     */
    public function subset(array $names): array
    {
        $out = [];
        foreach ($names as $n) {
            $out[$n] = $this->get($n);
        }
        return $out;
    }

    /** @return string[] */
    public function domain(string $domain): array
    {
        return (array) ($this->domains[$domain] ?? []);
    }
}
