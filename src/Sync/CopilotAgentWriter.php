<?php

namespace SuperAICore\Sync;

use SuperAICore\Registry\Agent;
use SuperAICore\Translator\CopilotToolPermissions;

/**
 * Translates `.claude/agents/*.md` → `~/.copilot/agents/<name>.agent.md`.
 *
 * Copilot uses a different file shape than Claude:
 *   - extension `.agent.md` (not `.md`)
 *   - frontmatter:  `name`, `description`, `tools` (Copilot expressions)
 *   - body: the Claude body, untouched (becomes Copilot system prompt)
 *
 * Field translation:
 *   Claude `name`         → Copilot `name`             (verbatim)
 *   Claude `description`  → Copilot `description`      (verbatim)
 *   Claude `tools` (array) → Copilot `tools` (array of allow-tool exprs)
 *   Claude `model`        → DROPPED (Copilot routes models server-side via subscription)
 *
 * Non-destructive merge (same contract as GeminiCommandWriter):
 *   - Same content on disk → unchanged
 *   - Different content + we wrote it → user-edited, leave alone
 *   - Source agent gone → remove the .agent.md (unless user-edited)
 *   - User deleted the .agent.md → recreate
 *
 * Auto-sync caller: pass a single agent to `syncOne()` for the lazy
 * "make sure this one agent file is fresh before `copilot --agent X` runs"
 * path used by CopilotAgentRunner.
 */
final class CopilotAgentWriter
{
    public const STATUS_WRITTEN     = 'written';
    public const STATUS_UNCHANGED   = 'unchanged';
    public const STATUS_USER_EDITED = 'user-edited';
    public const STATUS_REMOVED     = 'removed';
    public const STATUS_STALE_KEPT  = 'stale-kept';

    public function __construct(
        private readonly string $agentsDir,
        private readonly Manifest $manifest,
        private readonly ?CopilotToolPermissions $perms = null,
    ) {}

    /**
     * Sync the full agent set. Returns a per-status path report.
     *
     * @param  Agent[] $agents
     * @return array{written:string[], unchanged:string[], user_edited:string[], removed:string[], stale_kept:string[]}
     */
    public function sync(array $agents, bool $dryRun = false): array
    {
        $report = [
            'written'     => [],
            'unchanged'   => [],
            'user_edited' => [],
            'removed'     => [],
            'stale_kept'  => [],
        ];

        $targets = [];
        foreach ($agents as $a) {
            $targets[$this->agentPath($a->name)] = [$this->renderAgent($a), $a->path];
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

    /**
     * Lazy path used by the runner — sync just one agent without scanning
     * the rest. Skips disk write when the existing file is byte-equal to
     * what we'd produce; respects user-edited files (returns USER_EDITED).
     *
     * @return array{status:string, path:string}
     */
    public function syncOne(Agent $agent): array
    {
        $path = $this->agentPath($agent->name);
        $contents = $this->renderAgent($agent);
        $hash = hash('sha256', $contents);

        $manifest = $this->manifest->read();

        if (is_file($path)) {
            $current = hash('sha256', (string) @file_get_contents($path));
            if ($current === $hash) {
                return ['status' => self::STATUS_UNCHANGED, 'path' => $path];
            }
            $ours = $manifest[$path] ?? null;
            if ($ours !== null && $ours !== $current) {
                return ['status' => self::STATUS_USER_EDITED, 'path' => $path];
            }
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($path, $contents);

        $manifest[$path] = $hash;
        $this->manifest->write($manifest);

        return ['status' => self::STATUS_WRITTEN, 'path' => $path];
    }

    public function agentPath(string $name): string
    {
        return $this->agentsDir . '/' . $this->safe($name) . '.agent.md';
    }

    private function renderAgent(Agent $agent): string
    {
        $perms = $this->perms ?? new CopilotToolPermissions();
        $copilotTools = $perms->translate($agent->allowedTools);

        $fm = ['name' => $agent->name];
        if ($agent->description !== null && $agent->description !== '') {
            $fm['description'] = $agent->description;
        }
        if ($copilotTools) {
            $fm['tools'] = $copilotTools;
        }

        $body = trim($agent->body);

        return "---\n"
            . "# @generated-by: superaicore\n"
            . "# @source: " . $agent->path . "\n"
            . $this->yaml($fm)
            . "---\n\n"
            . $body
            . "\n";
    }

    /**
     * Tiny YAML emitter sufficient for our shape (string scalars + a list of
     * string scalars under `tools`). Avoids pulling symfony/yaml just for this.
     */
    private function yaml(array $data): string
    {
        $lines = '';
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $lines .= "{$key}:\n";
                foreach ($value as $item) {
                    $lines .= '  - ' . $this->yamlScalar((string) $item) . "\n";
                }
            } else {
                $lines .= "{$key}: " . $this->yamlScalar((string) $value) . "\n";
            }
        }
        return $lines;
    }

    private function yamlScalar(string $v): string
    {
        // Per YAML spec, plain scalars only need quoting when they:
        //   - are empty
        //   - start with a YAML indicator (! & * % @ ` > | # ' " - ?)
        //   - end or start with whitespace
        //   - contain ": " (key separator) or " #" (comment) sequences
        //   - contain newlines or other control chars
        // Tool expressions like `read(*)` and `shell(git:*)` are safe unquoted.
        if ($v === '') return '""';
        if (preg_match('/^[!&*%@`>|#\'"\-\?]/', $v)) {
            return '"' . addcslashes($v, "\"\\\n\r\t") . '"';
        }
        if (preg_match('/^\s|\s$/', $v)) {
            return '"' . addcslashes($v, "\"\\\n\r\t") . '"';
        }
        if (preg_match('/: | #|\r|\n|\t/', $v)) {
            return '"' . addcslashes($v, "\"\\\n\r\t") . '"';
        }
        return $v;
    }

    private function safe(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name) ?? $name;
    }
}
