<?php

namespace SuperAICore\Sync;

use SuperAICore\Registry\Agent;

/**
 * Translates `.claude/agents/*.md` → Kimi-CLI-compatible agent specs.
 *
 * Kimi's native agent format is YAML (`version: 1` + `agent.*` block)
 * under `~/.kimi/agents/<namespace>/<name>/` — very different shape from
 * Claude's YAML-frontmatter-plus-markdown-body. Verified on kimi v1.38.0
 * that Kimi REQUIRES `agent.system_prompt_path:` (pointing at an external
 * file); inline `system_prompt:` is rejected with "System prompt path is
 * required". So each synced agent lands as two files:
 *
 *   ~/.kimi/agents/superaicore/<name>/agent.yaml   (the spec + tools)
 *   ~/.kimi/agents/superaicore/<name>/system.md    (the Claude body)
 *
 * The `superaicore/` namespace keeps them out of Kimi's bundled tree
 * (`default/` / `okabe/`) so a `kimi-cli` package update never clobbers
 * synced agents and a re-sync never touches Moonshot's own definitions.
 *
 * To dispatch a synced agent, callers pass:
 *   kimi --agent-file ~/.kimi/agents/superaicore/<name>/agent.yaml --print …
 *
 * (Kimi's `--agent` flag only accepts bundled names `default` / `okabe`,
 * so custom agents must go through `--agent-file` — MVP-2 verified.)
 *
 * Field translation:
 *   Claude `name`        → Kimi `agent.name`           (verbatim)
 *   Claude `description` → dropped (Kimi agent spec has no description
 *                          field at the top level — it lives on the
 *                          subagent entry in a parent; a standalone agent
 *                          doesn't need one)
 *   Claude `tools` (list) → Kimi `agent.tools` (fully-qualified
 *                           class-path strings via {@see TOOL_MAP})
 *   Claude `model`       → DROPPED (Kimi routes models server-side via
 *                          `--model` at invocation time; baking it into
 *                          the agent file would override the operator's
 *                          per-call choice)
 *   Claude body          → file `system.md` (referenced via
 *                          `system_prompt_path: ./system.md`)
 *
 * Non-destructive merge contract lives in AbstractManifestWriter: user
 * edits to either file are detected via sha256 and preserved; an agent
 * removed from source results in removal of both files (unless the user
 * hand-edited them, in which case they're kept and flagged `stale_kept`).
 */
final class KimiAgentSync extends AbstractManifestWriter
{
    /**
     * Fully-qualified Kimi tool class paths the default agent includes.
     * Claude tool name (the key) → Kimi `kimi_cli.tools.*:*` string.
     *
     * Tools the map does NOT cover (e.g. `TodoWrite`, `Task`) either
     * have Kimi equivalents that don't match 1:1 or are intentionally
     * not exposed to synced agents — unmapped tools are dropped with
     * a warning during render. This keeps `.claude/agents/*.md` files
     * portable without needing the author to know Kimi-specific paths.
     *
     * Verified against kimi v1.38.0 bundled `kimi_cli/agents/default/agent.yaml`.
     */
    public const TOOL_MAP = [
        'Read'       => 'kimi_cli.tools.file:ReadFile',
        'Write'      => 'kimi_cli.tools.file:WriteFile',
        'Edit'       => 'kimi_cli.tools.file:StrReplaceFile',
        'MultiEdit'  => 'kimi_cli.tools.file:StrReplaceFile',
        'Glob'       => 'kimi_cli.tools.file:Glob',
        'Grep'       => 'kimi_cli.tools.file:Grep',
        'Bash'       => 'kimi_cli.tools.shell:Shell',
        'WebFetch'   => 'kimi_cli.tools.web:FetchURL',
        'WebSearch'  => 'kimi_cli.tools.web:SearchWeb',
        'Task'       => 'kimi_cli.tools.agent:Agent',
    ];

    /**
     * Default tool list emitted when an agent declares no `tools:` in its
     * frontmatter. Mirrors Kimi's bundled `coder` subagent minus the
     * `Agent` tool — a synced Claude agent is a leaf, not an orchestrator.
     */
    public const DEFAULT_TOOLS = [
        'kimi_cli.tools.file:ReadFile',
        'kimi_cli.tools.file:WriteFile',
        'kimi_cli.tools.file:StrReplaceFile',
        'kimi_cli.tools.file:Glob',
        'kimi_cli.tools.file:Grep',
        'kimi_cli.tools.shell:Shell',
        'kimi_cli.tools.web:FetchURL',
        'kimi_cli.tools.web:SearchWeb',
    ];

    public function __construct(
        private readonly string $agentsDir,
        Manifest $manifest,
    ) {
        parent::__construct($manifest);
    }

    /**
     * Sync the full agent set. Emits one agent.yaml + one system.md per
     * agent into `<agentsDir>/<name>/`.
     *
     * @param  Agent[] $agents
     * @return array{written:string[], unchanged:string[], user_edited:string[], removed:string[], stale_kept:string[]}
     */
    public function sync(array $agents, bool $dryRun = false): array
    {
        $targets = [];
        foreach ($agents as $a) {
            $dir = $this->agentDir($a->name);
            $targets[$dir . '/agent.yaml'] = [
                'contents' => $this->renderYaml($a),
                'source'   => $a->path,
            ];
            $targets[$dir . '/system.md'] = [
                'contents' => trim($a->body) . "\n",
                'source'   => $a->path,
            ];
        }

        return $this->applyTargets($targets, $dryRun);
    }

    /**
     * Absolute path to the directory that houses `agent.yaml` + `system.md`
     * for a given Claude agent name.
     */
    public function agentDir(string $name): string
    {
        return rtrim($this->agentsDir, '/') . '/' . $this->safe($name);
    }

    /**
     * Absolute path to `agent.yaml` — handy for
     * `--agent-file <path>` callers (the KimiAgentRunner that arrives in
     * a later MVP).
     */
    public function agentFilePath(string $name): string
    {
        return $this->agentDir($name) . '/agent.yaml';
    }

    /**
     * Emit the YAML spec. Keeps `system_prompt_path: ./system.md` relative
     * so the agent dir is portable (the user can `cp -r` it elsewhere and
     * still invoke via `--agent-file`).
     */
    public function renderYaml(Agent $agent): string
    {
        $tools = $this->mapTools($agent->allowedTools);
        $lines = [
            '# @generated-by: superaicore',
            '# @source: ' . $agent->path,
            'version: 1',
            'agent:',
            '  name: ' . $this->yamlScalar($agent->name),
            '  system_prompt_path: ./system.md',
            '  tools:',
        ];
        foreach ($tools as $t) {
            $lines[] = '    - ' . $this->yamlScalar($t);
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * Translate Claude tool names to Kimi class paths via TOOL_MAP, drop
     * unknowns. Empty input → DEFAULT_TOOLS so the agent has a sensible
     * working set instead of an empty toolbox.
     *
     * @param  string[] $claudeTools
     * @return string[]
     */
    public function mapTools(array $claudeTools): array
    {
        if ($claudeTools === []) {
            return self::DEFAULT_TOOLS;
        }
        $out = [];
        foreach ($claudeTools as $raw) {
            // `tools:` entries can be bare `Read` or `Bash(git:*)` (tool
            // with a permission expression). For the Kimi map we only
            // care about the bare tool name; Kimi doesn't enforce
            // per-command grants the way Copilot does.
            $name = trim(preg_replace('/\(.*$/', '', (string) $raw) ?? '');
            if ($name === '') continue;
            $mapped = self::TOOL_MAP[$name] ?? null;
            if ($mapped !== null && !in_array($mapped, $out, true)) {
                $out[] = $mapped;
            }
        }
        return $out;
    }

    private function yamlScalar(string $v): string
    {
        // Quote when the value starts with a YAML indicator, contains
        // special sequences (`: `, ` #`, newlines), or is an ambiguous
        // type coercion (numbers-looking, `true`/`false`/`null`).
        if ($v === '') return '""';
        if (preg_match('/^[!&*%@`>|#\'"\-\?\[{]/', $v)
            || preg_match('/^\s|\s$/', $v)
            || preg_match('/: | #|\r|\n|\t/', $v)
            || in_array(strtolower($v), ['true', 'false', 'null', 'yes', 'no', 'on', 'off'], true)
            || preg_match('/^-?\d+(\.\d+)?$/', $v)) {
            return '"' . addcslashes($v, "\"\\\n\r\t") . '"';
        }
        return $v;
    }

    private function safe(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name) ?? $name;
    }
}
