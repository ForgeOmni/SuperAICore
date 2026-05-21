<?php

declare(strict_types=1);

namespace SuperAICore\Services;

/**
 * Per-agent permission ruleset evaluator — opencode `permission/evaluate.ts`
 * port. Each rule is `{permission, pattern, action}`; the LAST matching
 * rule wins (so a more-specific rule placed later overrides a broad
 * earlier one). When nothing matches, the default action is `ask`.
 *
 * Config shape (in `super-ai-core.agents.{name}.permission`):
 *
 *   [
 *     '*'    => 'allow',        // baseline
 *     'edit' => 'deny',         // broad deny per tool
 *     'edit' => [               // OR per-pattern map (later overrides earlier)
 *       '*'    => 'deny',
 *       '*.md' => 'allow',
 *     ],
 *     'read' => [
 *       '*'      => 'allow',
 *       '*.env*' => 'ask',
 *     ],
 *   ]
 *
 * `derive(agentName)` produces a flat `{allowed_tools[], denied_tools[],
 * ask_tools[]}` envelope that SuperAgentBackend can pass through to
 * `Agent::withAllowedTools()` / `Agent::withDeniedTools()`. Tools whose
 * effective action is `ask` aren't denied — they show up in the
 * `ask_tools` list so the host's HITL hook can intercept them.
 */
class PermissionEvaluator
{
    public const ACTION_ALLOW = 'allow';
    public const ACTION_DENY  = 'deny';
    public const ACTION_ASK   = 'ask';

    private const ACTIONS = [self::ACTION_ALLOW, self::ACTION_DENY, self::ACTION_ASK];

    /**
     * Build a flat ruleset from the opencode-style config map. Each entry
     * becomes one or more `{permission, pattern, action}` triples.
     *
     * @param array<string, string|array<string,string>> $config
     * @return list<array{permission:string, pattern:string, action:string}>
     */
    public function fromConfig(array $config): array
    {
        $rules = [];
        foreach ($config as $permission => $entry) {
            $permission = (string) $permission;
            if (is_string($entry)) {
                if (!in_array($entry, self::ACTIONS, true)) continue;
                $rules[] = ['permission' => $permission, 'pattern' => '*', 'action' => $entry];
                continue;
            }
            if (is_array($entry)) {
                foreach ($entry as $pattern => $action) {
                    if (!is_string($action) || !in_array($action, self::ACTIONS, true)) continue;
                    $rules[] = [
                        'permission' => $permission,
                        'pattern'    => (string) $pattern,
                        'action'     => $action,
                    ];
                }
            }
        }
        return $rules;
    }

    /**
     * Evaluate a permission/pattern pair against one or more rulesets.
     * Rulesets are concatenated in order, and the LAST matching rule
     * wins (opencode semantics). Default action when nothing matches is
     * `ask`.
     *
     * @param list<array{permission:string, pattern:string, action:string}> ...$rulesets
     * @return array{permission:string, pattern:string, action:string}
     */
    public function evaluate(string $permission, string $pattern, array ...$rulesets): array
    {
        $rules = array_merge(...$rulesets);
        $match = null;
        foreach ($rules as $rule) {
            if (!isset($rule['permission'], $rule['pattern'], $rule['action'])) continue;
            if (!$this->wildcardMatch($permission, $rule['permission'])) continue;
            if (!$this->wildcardMatch($pattern, $rule['pattern']))       continue;
            $match = $rule;
        }
        return $match ?? ['permission' => $permission, 'pattern' => '*', 'action' => self::ACTION_ASK];
    }

    /**
     * Merge multiple rulesets — used for the opencode pattern
     * `Permission.merge(defaults, agentSpecific, userOverrides)`.
     * Later entries simply append, so the `evaluate()` last-match-wins
     * semantics resolve the precedence correctly.
     *
     * @param list<array{permission:string, pattern:string, action:string}> ...$rulesets
     * @return list<array{permission:string, pattern:string, action:string}>
     */
    public function merge(array ...$rulesets): array
    {
        return array_merge(...$rulesets);
    }

    /**
     * Project a ruleset into the flat envelope SuperAgentBackend can apply
     * via `withAllowedTools()` / `withDeniedTools()`. `ask_tools` is the
     * union of tools whose `*` pattern resolved to `ask` — useful when a
     * host wants to wire HITL on a narrower surface than "everything".
     *
     * @param list<array{permission:string, pattern:string, action:string}> $rules
     * @return array{allowed_tools:list<string>, denied_tools:list<string>, ask_tools:list<string>}
     */
    public function project(array $rules): array
    {
        $tools = [];
        foreach ($rules as $r) {
            if (!isset($r['permission'])) continue;
            $name = (string) $r['permission'];
            if ($name === '*' || $name === '') continue;
            $tools[$name] = true;
        }
        $allowed = $denied = $ask = [];
        foreach (array_keys($tools) as $name) {
            $resolved = $this->evaluate($name, '*', $rules);
            switch ($resolved['action']) {
                case self::ACTION_ALLOW: $allowed[] = $name; break;
                case self::ACTION_DENY:  $denied[]  = $name; break;
                case self::ACTION_ASK:   $ask[]     = $name; break;
            }
        }
        sort($allowed); sort($denied); sort($ask);
        return ['allowed_tools' => $allowed, 'denied_tools' => $denied, 'ask_tools' => $ask];
    }

    /**
     * Resolve the per-agent ruleset declared in config and project it onto
     * the flat envelope. Returns null when no rules are configured for
     * this agent (caller leaves Agent::with{Allowed,Denied}Tools untouched).
     *
     * @return array{allowed_tools:list<string>, denied_tools:list<string>, ask_tools:list<string>}|null
     */
    public function deriveForAgent(string $agentName): ?array
    {
        if ($agentName === '') return null;
        if (!function_exists('config')) return null;
        try {
            $cfg = config('super-ai-core.agents.' . $agentName . '.permission');
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($cfg) || $cfg === []) return null;
        $rules = $this->fromConfig($cfg);
        if ($rules === []) return null;
        return $this->project($rules);
    }

    /**
     * Opencode's `Wildcard.match()` equivalent — single-segment glob over
     * the supplied haystack. We delegate to PHP's `fnmatch` so `*`, `?`,
     * `[abc]` brackets all behave as the opencode TS variant expects.
     */
    private function wildcardMatch(string $haystack, string $pattern): bool
    {
        if ($pattern === '' || $pattern === '*') return true;
        if ($pattern === $haystack)              return true;
        return @fnmatch($pattern, $haystack);
    }
}
