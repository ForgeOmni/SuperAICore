<?php

namespace SuperAICore\Registry;

/**
 * Locates and loads Claude sub-agents from two sources (highest → lowest):
 *   1. $cwd/.claude/agents/ *.md        (project)
 *   2. $home/.claude/agents/ *.md       (user)
 *
 * Unlike skills, agents are flat `.md` files (not directories). Name
 * defaults to the filename stem when frontmatter omits `name:`. Plugin
 * source is not supported (D7 — matches Claude Code's discovery order).
 */
final class AgentRegistry
{
    public function __construct(
        private readonly ?string $cwd = null,
        private readonly ?string $home = null,
    ) {}

    /** @return array<string, Agent> keyed by name */
    public function all(): array
    {
        $cwd  = $this->cwd  ?? getcwd() ?: '';
        $home = $this->home ?? ($_SERVER['HOME'] ?? getenv('HOME') ?: '');

        $agents = [];
        foreach ($this->loadFromDir($home . '/.claude/agents', Agent::SOURCE_USER()) as $agent) {
            $agents[$agent->name] = $agent;
        }
        foreach ($this->loadFromDir($cwd . '/.claude/agents', Agent::SOURCE_PROJECT()) as $agent) {
            $agents[$agent->name] = $agent;
        }

        ksort($agents);
        return $agents;
    }

    public function get(string $name): ?Agent
    {
        return $this->all()[$name] ?? null;
    }

    /** @return Agent[] */
    private function loadFromDir(string $dir, string $source): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob(rtrim($dir, '/') . '/*.md') ?: [];
        sort($files);

        $out = [];
        foreach ($files as $file) {
            $raw = @file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            [$fm, $body] = FrontmatterParser::parse($raw);

            $name = is_string($fm['name'] ?? null) && $fm['name'] !== ''
                ? $fm['name']
                : pathinfo($file, PATHINFO_FILENAME);
            $desc  = is_string($fm['description'] ?? null) ? $fm['description'] : null;
            $model = is_string($fm['model'] ?? null) && $fm['model'] !== '' ? $fm['model'] : null;

            $allowed = [];
            foreach (['allowed-tools', 'allowed_tools', 'tools'] as $k) {
                if (is_array($fm[$k] ?? null)) {
                    $allowed = array_values(array_filter($fm[$k], 'is_string'));
                    break;
                }
            }

            $out[] = new Agent(
                name: $name,
                description: $desc,
                source: $source,
                body: $body,
                path: $file,
                model: $model,
                allowedTools: $allowed,
                frontmatter: $fm,
            );
        }
        return $out;
    }
}
