<?php

namespace SuperAICore\Sync;

use SuperAICore\Registry\Agent;

/**
 * Translates `.claude/agents/*.md` → `~/.kiro/agents/<name>.json`.
 *
 * Kiro's agent format is JSON (not YAML-frontmatter markdown), but the
 * semantics line up almost 1:1 with the Claude shape:
 *
 *   Claude `name`          → Kiro `name`          (verbatim)
 *   Claude `description`   → Kiro `description`   (verbatim)
 *   Claude body            → Kiro `prompt`        (body becomes the system prompt)
 *   Claude `model`         → Kiro `model`         (verbatim — Kiro routes Anthropic IDs)
 *   Claude `allowed-tools` → Kiro `tools`         (lowercased per Kiro's vocabulary)
 *                            + Kiro `allowedTools` (same list — auto-approve the
 *                            declared set so --trust-all-tools isn't needed to run)
 *
 * When the source agent declares no tool restrictions, we write `tools: ["*"]`
 * so Kiro's default tool set is available. Non-destructive merge: this writer
 * reuses `AbstractManifestWriter`, so a hand-edited `.json` file is preserved.
 */
final class KiroAgentWriter extends AbstractManifestWriter
{
    public function __construct(
        private readonly string $agentsDir,
        Manifest $manifest,
    ) {
        parent::__construct($manifest);
    }

    /**
     * Sync the full agent set.
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

    /** Lazy one-file path used by KiroAgentRunner. */
    public function syncOne(Agent $agent): array
    {
        return $this->applyOne(
            $this->agentPath($agent->name),
            $this->renderAgent($agent),
        );
    }

    public function agentPath(string $name): string
    {
        return $this->agentsDir . '/' . $this->safe($name) . '.json';
    }

    private function renderAgent(Agent $agent): string
    {
        $payload = [
            '_generated_by' => 'superaicore',
            '_source'       => $agent->path,
            'name'          => $agent->name,
        ];
        if ($agent->description !== null && $agent->description !== '') {
            $payload['description'] = $agent->description;
        }
        if ($agent->model) {
            $payload['model'] = $agent->model;
        }

        $tools = $this->translateTools($agent->allowedTools);
        $payload['tools'] = $tools ?: ['*'];
        if ($tools) {
            // Auto-approve the declared set — matches Claude's allowed-tools
            // semantics. Users who want confirmation prompts can delete this
            // key from the written JSON (user-edit detection preserves it).
            $payload['allowedTools'] = $tools;
        }

        $payload['prompt'] = trim($agent->body);

        return json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) . "\n";
    }

    /**
     * Claude canonical names → Kiro lowercase names. Unknown tokens pass
     * through unchanged so MCP-qualified names like `@exa.search` survive.
     *
     * @param  string[] $allowedTools
     * @return string[]
     */
    private function translateTools(array $allowedTools): array
    {
        static $map = [
            'Read'  => 'read',
            'Write' => 'write',
            'Edit'  => 'write',
            'Grep'  => 'grep',
            'Glob'  => 'fileSearch',
            'Bash'  => 'bash',
            // Web tools have no native Kiro equivalent; keep the Claude name
            // so users see a clear miss if they declared them without an MCP.
        ];
        $out = [];
        foreach ($allowedTools as $t) {
            $norm = $map[$t] ?? $t;
            if (!in_array($norm, $out, true)) {
                $out[] = $norm;
            }
        }
        return $out;
    }

    private function safe(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name) ?? $name;
    }
}
