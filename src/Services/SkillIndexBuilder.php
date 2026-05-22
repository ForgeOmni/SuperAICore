<?php

declare(strict_types=1);

namespace SuperAICore\Services;

/**
 * Build a Pi-style "progressive disclosure" skill index for backends that
 * don't natively understand the Claude Code Skill protocol.
 *
 * Pi (pi.dev/docs/latest/skills): the host scans every available SKILL.md
 * at startup, extracts `name` + `description` from frontmatter, and emits
 * a compact XML block into the system prompt. The model decides when a
 * task is relevant and then asks the host to read the full SKILL.md.
 *
 * For Claude Code, Anthropic's CLI handles this natively. For Codex CLI,
 * Gemini CLI, and similar backends we have to inject the XML index
 * ourselves so the model has the same routing surface.
 *
 * The companion runtime contract: when the model emits a tool call like
 * `read_file({path: ".claude/skills/<name>/SKILL.md"})` the executing
 * backend must allow that read (it's part of the documented progressive-
 * disclosure protocol).
 */
final class SkillIndexBuilder
{
    /**
     * Walk every skill path, extract name + description, return the XML.
     * Returns the empty string when no skills are discovered (caller
     * should skip prepending so the system prompt stays byte-identical).
     *
     * @param list<string> $skillPaths Absolute paths to skill root dirs
     *                                  (e.g. ['/repo/.claude/skills']).
     * @param int $maxEntries Cap so the index stays small in context.
     */
    public function buildXml(array $skillPaths, int $maxEntries = 200): string
    {
        $entries = [];
        foreach ($skillPaths as $root) {
            if (!is_dir($root)) continue;
            foreach (scandir($root) ?: [] as $name) {
                if ($name === '.' || $name === '..') continue;
                $skillDir = $root . DIRECTORY_SEPARATOR . $name;
                if (!is_dir($skillDir)) continue;
                $manifest = $skillDir . DIRECTORY_SEPARATOR . 'SKILL.md';
                if (!is_file($manifest)) continue;

                $frontmatter = $this->readFrontmatter($manifest);
                if (!$frontmatter) continue;

                $skillName = (string) ($frontmatter['name'] ?? $name);
                $desc = (string) ($frontmatter['description'] ?? '');
                if ($skillName === '' || $desc === '') continue;

                $entries[$skillName] = [
                    'name' => $skillName,
                    'description' => $desc,
                    'path' => $manifest,
                ];
                if (count($entries) >= $maxEntries) break 2;
            }
        }

        if ($entries === []) return '';

        $xml = "<available_skills>\n";
        $xml .= "<!-- Progressive disclosure: each skill below ships a SKILL.md you can read in full ";
        $xml .= "when its description matches the current task. Use your read tool to fetch the file ";
        $xml .= "at the listed `path` before following its instructions. -->\n";
        foreach ($entries as $entry) {
            $xml .= sprintf(
                "  <skill name=\"%s\" path=\"%s\">%s</skill>\n",
                $this->xmlEscape($entry['name']),
                $this->xmlEscape($entry['path']),
                $this->xmlEscape($entry['description'])
            );
        }
        $xml .= "</available_skills>";
        return $xml;
    }

    /**
     * Convenience: build for the configured paths in super-ai-core.skills.paths
     * + super-ai-core.agents.paths (when callable in a Laravel host).
     */
    public function buildFromConfig(int $maxEntries = 200): string
    {
        $paths = [];
        if (function_exists('config')) {
            $skillPaths = config('super-ai-core.skills.paths', []);
            if (is_array($skillPaths)) {
                foreach ($skillPaths as $p) if (is_string($p)) $paths[] = $p;
            }
        }
        return $this->buildXml($paths, $maxEntries);
    }

    private function readFrontmatter(string $path): ?array
    {
        $fh = fopen($path, 'rb');
        if (!$fh) return null;
        $first = fgets($fh);
        if ($first === false || trim($first) !== '---') {
            fclose($fh);
            return null;
        }

        $out = [];
        while (($line = fgets($fh)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '---') break;
            if (!preg_match('/^([A-Za-z0-9_-]+):\s*(.*)$/', $line, $m)) continue;
            $value = trim($m[2], " \t\"'");
            $out[$m[1]] = $value;
        }
        fclose($fh);
        return $out === [] ? null : $out;
    }

    private function xmlEscape(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
