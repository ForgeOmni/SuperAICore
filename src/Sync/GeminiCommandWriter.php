<?php

namespace SuperAICore\Sync;

use SuperAICore\Registry\Agent;
use SuperAICore\Registry\Skill;

/**
 * Writes Gemini custom-command TOML files that shell out to
 * `superaicore skill:run <name>` / `superaicore agent:run <name>`.
 *
 * Namespacing (DESIGN §5 D8):
 *   ~/.gemini/commands/skill/<name>.toml   → Gemini renders `/skill:<name>`
 *   ~/.gemini/commands/agent/<name>.toml   → Gemini renders `/agent:<name>`
 *
 * Non-destructive contract (DESIGN §10 criterion 6):
 *   - A TOML we previously wrote, now with different content on disk,
 *     is considered user-modified. We refuse to overwrite or delete it
 *     — the user has adopted responsibility for it.
 *   - A TOML the user deleted (no longer on disk) will be recreated on
 *     the next sync.
 *
 * Write-once content per skill/agent:
 *
 *   # @generated-by: superaicore
 *   # @source: <absolute source path>
 *   description = "<...>"
 *   prompt = '!{superaicore skill:run <name> {{args}}}'
 *
 * The `!{...}` prefix tells Gemini to shell out; `{{args}}` is Gemini's
 * custom-command placeholder for user arguments at invocation time.
 */
final class GeminiCommandWriter
{
    /** Returned as the sync report. */
    public const STATUS_WRITTEN     = 'written';
    public const STATUS_UNCHANGED   = 'unchanged';
    public const STATUS_USER_EDITED = 'user-edited';
    public const STATUS_REMOVED     = 'removed';
    public const STATUS_STALE_KEPT  = 'stale-kept';

    public function __construct(
        private readonly string $commandsDir,
        private readonly Manifest $manifest,
        private readonly string $bin = 'superaicore',
    ) {}

    /**
     * @param  Skill[] $skills
     * @param  Agent[] $agents
     * @return array{written:string[], unchanged:string[], user_edited:string[], removed:string[], stale_kept:string[]}
     */
    public function sync(array $skills, array $agents, bool $dryRun = false): array
    {
        $report = [
            'written'     => [],
            'unchanged'   => [],
            'user_edited' => [],
            'removed'     => [],
            'stale_kept'  => [],
        ];

        $targets = [];
        foreach ($skills as $s) {
            $targets[$this->skillPath($s->name)] = [$this->renderSkillToml($s), $s->path];
        }
        foreach ($agents as $a) {
            $targets[$this->agentPath($a->name)] = [$this->renderAgentToml($a), $a->path];
        }

        $previousEntries = $this->manifest->read();
        $nextEntries     = [];

        foreach ($targets as $path => [$contents, $source]) {
            $hash = hash('sha256', $contents);

            if (is_file($path)) {
                $onDisk  = (string) @file_get_contents($path);
                $current = hash('sha256', $onDisk);
                $ours    = $previousEntries[$path] ?? null;

                if ($current === $hash) {
                    $report['unchanged'][] = $path;
                    $nextEntries[$path] = $hash;
                    continue;
                }

                if ($ours !== null && $ours !== $current) {
                    $report['user_edited'][] = $path;
                    $nextEntries[$path] = $ours;
                    continue;
                }
            }

            if (!$dryRun) {
                $dir = dirname($path);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                @file_put_contents($path, $contents);
            }
            $report['written'][] = $path;
            $nextEntries[$path] = $hash;
        }

        foreach ($previousEntries as $oldPath => $oldHash) {
            if (isset($targets[$oldPath])) {
                continue;
            }
            if (!is_file($oldPath)) {
                continue;
            }
            $current = hash('sha256', (string) @file_get_contents($oldPath));
            if ($current !== $oldHash) {
                $report['stale_kept'][] = $oldPath;
                $nextEntries[$oldPath]  = $oldHash;
                continue;
            }
            if (!$dryRun) {
                @unlink($oldPath);
            }
            $report['removed'][] = $oldPath;
        }

        if (!$dryRun) {
            $this->manifest->write($nextEntries);
        }

        return $report;
    }

    private function skillPath(string $name): string
    {
        return $this->commandsDir . '/skill/' . $this->safe($name) . '.toml';
    }

    private function agentPath(string $name): string
    {
        return $this->commandsDir . '/agent/' . $this->safe($name) . '.toml';
    }

    private function renderSkillToml(Skill $skill): string
    {
        $lines = [
            '# @generated-by: superaicore',
            '# @source: ' . $skill->path,
            'description = ' . $this->tomlString($skill->description ?? ''),
            "prompt = '!{" . $this->bin . ' skill:run ' . $skill->name . " {{args}}}'",
        ];
        return implode("\n", $lines) . "\n";
    }

    private function renderAgentToml(Agent $agent): string
    {
        $lines = [
            '# @generated-by: superaicore',
            '# @source: ' . $agent->path,
            'description = ' . $this->tomlString($agent->description ?? ''),
            "prompt = '!{" . $this->bin . ' agent:run ' . $agent->name . ' "{{args}}"}\'',
        ];
        return implode("\n", $lines) . "\n";
    }

    private function tomlString(string $v): string
    {
        return '"' . addcslashes($v, "\"\\\n\r\t") . '"';
    }

    private function safe(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name) ?? $name;
    }
}
