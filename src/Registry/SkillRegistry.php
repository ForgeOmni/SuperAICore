<?php

namespace SuperAICore\Registry;

/**
 * Locates and loads Claude skills from three sources (highest → lowest precedence):
 *   1. $cwd/.claude/skills/         (project)
 *   2. $home/.claude/plugins/ * /skills/  (plugin)
 *   3. $home/.claude/skills/        (user)
 *
 * A skill is a directory containing SKILL.md with YAML frontmatter
 * (`name`, `description`) and a prompt body. Same-named skills collapse
 * with project > plugin > user precedence.
 */
final class SkillRegistry
{
    public function __construct(
        private readonly ?string $cwd = null,
        private readonly ?string $home = null,
    ) {}

    /** @return array<string, Skill> keyed by name */
    public function all(): array
    {
        $cwd = $this->cwd ?? getcwd() ?: '';
        $home = $this->home ?? ($_SERVER['HOME'] ?? getenv('HOME') ?: '');

        $sources = [
            [Skill::SOURCE_USER(), $home . '/.claude/skills'],
        ];

        $pluginsRoot = $home . '/.claude/plugins';
        if (is_dir($pluginsRoot)) {
            foreach ($this->listDirs($pluginsRoot) as $pluginDir) {
                $skillsDir = $pluginDir . '/skills';
                if (is_dir($skillsDir)) {
                    $sources[] = [Skill::SOURCE_PLUGIN(), $skillsDir];
                }
            }
        }

        $sources[] = [Skill::SOURCE_PROJECT(), $cwd . '/.claude/skills'];

        $skills = [];
        foreach ($sources as [$source, $dir]) {
            foreach ($this->loadFromDir($dir, $source) as $skill) {
                $skills[$skill->name] = $skill;
            }
        }

        ksort($skills);
        return $skills;
    }

    public function get(string $name): ?Skill
    {
        return $this->all()[$name] ?? null;
    }

    /** @return Skill[] */
    private function loadFromDir(string $dir, string $source): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $out = [];
        foreach ($this->listDirs($dir) as $skillDir) {
            $md = $skillDir . '/SKILL.md';
            if (!is_file($md)) {
                continue;
            }
            $raw = @file_get_contents($md);
            if ($raw === false) {
                continue;
            }
            [$fm, $body] = FrontmatterParser::parse($raw);
            $name = is_string($fm['name'] ?? null) && $fm['name'] !== ''
                ? $fm['name']
                : basename($skillDir);
            $desc = is_string($fm['description'] ?? null) ? $fm['description'] : null;

            $allowed = [];
            foreach (['allowed-tools', 'allowed_tools', 'tools'] as $k) {
                if (is_array($fm[$k] ?? null)) {
                    $allowed = array_values(array_filter($fm[$k], 'is_string'));
                    break;
                }
            }

            $out[] = new Skill(
                name: $name,
                description: $desc,
                source: $source,
                body: $body,
                path: $md,
                allowedTools: $allowed,
                frontmatter: $fm,
            );
        }
        return $out;
    }

    /** @return string[] */
    private function listDirs(string $dir): array
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return [];
        }
        $out = [];
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $full = $dir . '/' . $e;
            if (is_dir($full)) {
                $out[] = $full;
            }
        }
        sort($out);
        return $out;
    }
}
