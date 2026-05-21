<?php

declare(strict_types=1);

namespace SuperAICore\Services;

/**
 * Sub-agent permission derivation — opencode `agent/subagent-permissions.ts`
 * port. The contract: when a parent agent dispatches a sub-agent via
 * SuperAgent's `AgentTool`, the child inherits the parent's deny list
 * and can never elevate. A read-only parent must produce read-only
 * children.
 *
 * SuperAgentBackend reads two signals off the dispatch options:
 *
 *   - `parent_denied_tools`: explicit pass-through from the parent's
 *      dispatcher (this is how SuperAICore's nested dispatcher
 *      threads the parent's `denied_tools` into the child call).
 *   - `metadata.parent_agent` + `super-ai-core.agents.{parent}.permission`:
 *      fallback when the caller didn't thread parent_denied_tools but
 *      did identify which agent recursed. We consult the
 *      `PermissionEvaluator` to project the parent's ruleset and use
 *      the resulting deny set.
 *
 * Returns a list of tool names the child must not be able to call.
 * Empty list = no derivation applied (root dispatch, no parent context).
 */
class SubagentPermissionDeriver
{
    public function __construct(
        private readonly ?PermissionEvaluator $permissions = null,
    ) {}

    /**
     * @param  array<string,mixed> $options  Dispatch options. Reads:
     *                                       parent_denied_tools (list),
     *                                       metadata.parent_agent (string).
     * @return list<string>
     */
    public function deriveDenied(array $options): array
    {
        $explicit = $options['parent_denied_tools'] ?? null;
        if (is_array($explicit) && $explicit !== []) {
            $sane = array_values(array_filter(array_map('strval', $explicit), fn ($t) => $t !== ''));
            sort($sane);
            return array_values(array_unique($sane));
        }

        $parent = (string) ($options['metadata']['parent_agent']
            ?? ($options['parent_agent'] ?? ''));
        if ($parent === '' || $this->permissions === null) return [];

        $derived = $this->permissions->deriveForAgent($parent);
        if ($derived === null) return [];
        return $derived['denied_tools'] ?? [];
    }
}
