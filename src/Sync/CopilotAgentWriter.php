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
 * Non-destructive merge contract lives in AbstractManifestWriter.
 *
 * Auto-sync caller: pass a single agent to `syncOne()` for the lazy
 * "make sure this one agent file is fresh before `copilot --agent X` runs"
 * path used by CopilotAgentRunner.
 */
final class CopilotAgentWriter extends AbstractManifestWriter
{
    public function __construct(
        private readonly string $agentsDir,
        Manifest $manifest,
        private readonly ?CopilotToolPermissions $perms = null,
    ) {
        parent::__construct($manifest);
    }

    /**
     * Sync the full agent set. Returns a per-status path report.
     *
     * @param  Agent[] $agents
     * @return array{written:string[], unchanged:string[], user_edited:string[], removed:string[], stale_kept:string[]}
     */
    public function sync(array $agents, bool $dryRun = false): array
    {
        $targets = [];
        foreach ($agents as $a) {
            $targets[$this->agentPath($a->name)] = [
                'contents' => $this->renderAgent($a),
                'source'   => $a->path,
            ];
        }

        return $this->applyTargets($targets, $dryRun);
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
        return $this->applyOne(
            $this->agentPath($agent->name),
            $this->renderAgent($agent),
        );
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
