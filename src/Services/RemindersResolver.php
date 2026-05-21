<?php

declare(strict_types=1);

namespace SuperAICore\Services;

/**
 * Synthetic system-prompt reminder injector — opencode `session/reminders.ts`
 * port. Config-driven: each rule has a `when` predicate and a `text` body.
 * SuperAgentBackend calls `resolve($options)` before each dispatch; the
 * returned block (possibly empty) gets prepended to the caller's system
 * prompt.
 *
 * Rule shape (in `super-ai-core.reminders.rules`):
 *
 *   [
 *     'name' => 'plan-mode-active',        // optional debug label
 *     'when' => [                          // ALL key/value pairs must match
 *       'agent'   => 'plan',
 *       'model'   => 'claude-*',           // shell glob via fnmatch()
 *     ],
 *     'text' => "## Plan mode\n...",       // body to prepend
 *   ],
 *
 * Match semantics:
 *   - `when` keys are dotted paths into `$options` (e.g.
 *     `metadata.session_id`); values support fnmatch globs.
 *   - empty/omitted `when` means "always match" — useful for global
 *     reminders (compliance notices etc.).
 *   - Rules fire in order; their bodies concatenate with a blank line.
 *
 * Why config-driven rather than code: hosts already declare their UX
 * domain knowledge in config (`super-ai-core.agents`, `routing.*`, etc.).
 * Keeping reminders there means a non-PHP operator can tune the prompt
 * surface without touching the package.
 */
class RemindersResolver
{
    /**
     * @param list<array{name?:string, when?:array<string,string>, text:string}> $rules
     */
    public function __construct(
        private readonly array $rules,
    ) {}

    /**
     * Compose the synthetic reminder block for this dispatch. Returns
     * the empty string when no rule matches (caller skips the prepend
     * to keep the system prompt byte-identical).
     *
     * @param array<string,mixed> $options
     */
    public function resolve(array $options): string
    {
        if ($this->rules === []) return '';

        $parts = [];
        foreach ($this->rules as $rule) {
            if (!is_array($rule)) continue;
            $text = (string) ($rule['text'] ?? '');
            if ($text === '') continue;
            if (!$this->matches($rule['when'] ?? [], $options)) continue;
            $parts[] = $text;
        }
        if ($parts === []) return '';
        return implode("\n\n", $parts);
    }

    /**
     * @param array<string,string|int|bool|null> $predicates
     * @param array<string,mixed>                $options
     */
    private function matches(array $predicates, array $options): bool
    {
        if ($predicates === []) return true;
        foreach ($predicates as $path => $expected) {
            $actual = $this->lookup($options, (string) $path);
            if ($actual === null) return false;
            $actualStr   = is_scalar($actual) ? (string) $actual : json_encode($actual);
            $expectedStr = is_scalar($expected) ? (string) $expected : json_encode($expected);
            if ($expectedStr === '') continue;
            if (!fnmatch((string) $expectedStr, (string) $actualStr)) return false;
        }
        return true;
    }

    /**
     * Lookup a dotted-path value inside the options array. Returns null
     * for missing keys.
     */
    private function lookup(array $options, string $path): mixed
    {
        if ($path === '') return null;
        $cursor = $options;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) return null;
            $cursor = $cursor[$segment];
        }
        return $cursor;
    }
}
