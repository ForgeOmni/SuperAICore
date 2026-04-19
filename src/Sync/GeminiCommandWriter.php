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
 * Non-destructive contract lives in AbstractManifestWriter — this class
 * only owns the rendering of a single TOML and its on-disk location.
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
final class GeminiCommandWriter extends AbstractManifestWriter
{
    public function __construct(
        private readonly string $commandsDir,
        Manifest $manifest,
        private readonly string $bin = 'superaicore',
    ) {
        parent::__construct($manifest);
    }

    /**
     * @param  Skill[] $skills
     * @param  Agent[] $agents
     * @return array{written:string[], unchanged:string[], user_edited:string[], removed:string[], stale_kept:string[]}
     */
    public function sync(array $skills, array $agents, bool $dryRun = false): array
    {
        $targets = [];
        foreach ($skills as $s) {
            $targets[$this->skillPath($s->name)] = [
                'contents' => $this->renderSkillToml($s),
                'source'   => $s->path,
            ];
        }
        foreach ($agents as $a) {
            $targets[$this->agentPath($a->name)] = [
                'contents' => $this->renderAgentToml($a),
                'source'   => $a->path,
            ];
        }

        return $this->applyTargets($targets, $dryRun);
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
