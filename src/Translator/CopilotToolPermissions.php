<?php

namespace SuperAICore\Translator;

/**
 * Translates Claude tool grants (e.g. ["Read", "Bash(git:*)", "WebFetch"])
 * into Copilot CLI's `--allow-tool` expression syntax:
 *
 *   shell(<glob>) | write(<glob>) | read(<glob>) | url(<glob>) | memory | <mcp-server>
 *
 * Both the agent.md frontmatter `tools:` field and the runtime `--allow-tool`
 * flags accept the same expression set, so this class is reused by:
 *
 *   - CopilotAgentWriter (fills frontmatter at sync time)
 *   - CopilotAgentRunner (passes --allow-tool when no allow-all-tools)
 *
 * Mapping (Claude canonical → Copilot expression):
 *   Read              → read(*)
 *   Write / Edit      → write(*)
 *   Bash              → shell(*)
 *   Bash(<glob>)      → shell(<glob>)            (parses Claude's parameterised form)
 *   WebFetch          → url(*)
 *   WebSearch         → url(*)                   (Copilot has no separate search grant)
 *   Glob / Grep       → read(*)                  (read is the umbrella; both are file scans)
 *   Agent / Task      → (dropped — no Copilot equivalent; sub-agents handled differently)
 *   memory            → memory
 *   mcp__<srv>        → <srv>                    (Copilot grants per MCP server)
 *
 * Unknown tools fall through unchanged with a warning collected in `unknown()`.
 */
final class CopilotToolPermissions
{
    /** @var string[] */
    private array $unknown = [];

    /**
     * Translate a list of Claude tool entries to Copilot expressions.
     * De-duplicates output and preserves first-seen order.
     *
     * @param  string[] $claudeTools
     * @return string[] Copilot --allow-tool expressions
     */
    public function translate(array $claudeTools): array
    {
        $this->unknown = [];
        $out = [];

        foreach ($claudeTools as $entry) {
            if (!is_string($entry) || $entry === '') {
                continue;
            }
            foreach ($this->translateOne(trim($entry)) as $expr) {
                if (!in_array($expr, $out, true)) {
                    $out[] = $expr;
                }
            }
        }

        return $out;
    }

    /** @return string[] tool entries that did not resolve to a known mapping */
    public function unknown(): array
    {
        return $this->unknown;
    }

    /** @return string[] one Claude entry can expand to 0..N Copilot expressions */
    private function translateOne(string $entry): array
    {
        // MCP-prefixed Claude tools: `mcp__github__create_issue` → `github`
        if (str_starts_with($entry, 'mcp__')) {
            $parts = explode('__', $entry);
            return isset($parts[1]) ? [$parts[1]] : [];
        }

        // Parameterised: Bash(git:*) / Read(/etc/*) → split base + arg
        if (preg_match('/^([A-Za-z]+)\((.*)\)$/', $entry, $m)) {
            $base = $m[1];
            $arg  = $m[2] !== '' ? $m[2] : '*';
            return $this->mapBase($base, $arg);
        }

        return $this->mapBase($entry, '*');
    }

    /** @return string[] */
    private function mapBase(string $name, string $glob): array
    {
        $glob = $glob === '' ? '*' : $glob;

        return match ($name) {
            'Read'                 => ["read({$glob})"],
            'Write', 'Edit',
            'NotebookEdit'         => ["write({$glob})"],
            'Bash', 'BashOutput',
            'KillShell'            => ["shell({$glob})"],
            'WebFetch', 'WebSearch'=> ["url({$glob})"],
            'Glob', 'Grep'         => ["read({$glob})"],
            'Agent', 'Task',
            'TodoWrite',
            'ExitPlanMode'         => [],   // no Copilot equivalent — silently drop
            'memory'               => ['memory'],
            default => $this->fallback($name, $glob),
        };
    }

    /** @return string[] */
    private function fallback(string $name, string $glob): array
    {
        // Lowercased category words pass through (e.g. user typed `shell`/`write`/`read`/`url` directly).
        $lc = strtolower($name);
        if (in_array($lc, ['shell', 'write', 'read', 'url'], true)) {
            return ["{$lc}({$glob})"];
        }
        $this->unknown[] = $name;
        return [];
    }
}
