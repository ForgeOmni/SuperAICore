<?php

namespace SuperAICore\Registry;

/**
 * Parses and validates a skill's `arguments:` frontmatter.
 *
 * Recognised frontmatter shapes:
 *
 *   # Form A — single free-form description (most common in v0 skills):
 *   arguments: "URL to audit"
 *
 *   # Form B — flow/block list of positional argument names:
 *   arguments:
 *     - target_url
 *     - scope
 *
 *   # Form C — map of name → description (all treated optional in v0):
 *   arguments:
 *     target_url: The URL to audit
 *     scope: Optional audit scope
 *
 * Structured v1 shapes (`- name: x, required: true, description: ...`)
 * require multi-level YAML; our minimal parser doesn't support them
 * yet — when present, they degrade silently to "unknown schema" and
 * we fall back to free-form args. That's fine: the model still sees
 * the raw skill body and can interpret args itself.
 */
final class SkillArguments
{
    public const SHAPE_FREEFORM = 'freeform';
    public const SHAPE_POSITIONAL = 'positional';
    public const SHAPE_NAMED = 'named';

    /** @param string[] $names */
    public function __construct(
        public readonly string $shape,
        public readonly array $names,
        public readonly int $required,
    ) {}

    /** @param array<string,mixed> $frontmatter */
    public static function fromFrontmatter(array $frontmatter): ?self
    {
        $spec = $frontmatter['arguments'] ?? null;

        if ($spec === null || $spec === [] || $spec === '') {
            return null;
        }

        if (is_string($spec)) {
            return new self(self::SHAPE_FREEFORM, ['input'], 1);
        }

        if (is_array($spec)) {
            if (array_is_list($spec)) {
                $names = array_values(array_filter(
                    array_map(fn ($v) => is_string($v) ? $v : null, $spec),
                    fn ($v) => $v !== null && $v !== ''
                ));
                if (!$names) {
                    return null;
                }
                return new self(self::SHAPE_POSITIONAL, $names, count($names));
            }
            $names = array_values(array_filter(
                array_map(fn ($k) => is_string($k) ? $k : null, array_keys($spec)),
                fn ($k) => $k !== null && $k !== ''
            ));
            if (!$names) {
                return null;
            }
            return new self(self::SHAPE_NAMED, $names, 0);
        }

        return null;
    }

    /**
     * @param string[] $actual
     * @return string|null null on success, human message on failure
     */
    public function validate(array $actual): ?string
    {
        $count = count($actual);

        if ($this->shape === self::SHAPE_FREEFORM) {
            return $count >= 1 ? null : 'this skill expects one free-form argument';
        }

        if ($count < $this->required) {
            $missing = array_slice($this->names, $count);
            return 'missing required argument(s): ' . implode(', ', $missing);
        }

        if ($this->shape === self::SHAPE_POSITIONAL && $count > count($this->names)) {
            $extra = $count - count($this->names);
            return "unexpected {$extra} extra positional argument(s); this skill declares " . implode(', ', $this->names);
        }

        return null;
    }

    /**
     * Render the provided positional args as an XML block the backend
     * model can parse. Named/positional args get per-arg `<arg>` tags;
     * free-form uses a single flat `<args>` body.
     *
     * @param string[] $actual
     */
    public function render(array $actual): string
    {
        if ($this->shape === self::SHAPE_FREEFORM) {
            return "\n\n<args>\n" . self::esc(implode(' ', $actual)) . "\n</args>\n";
        }

        $out = "\n\n<args>\n";
        foreach ($this->names as $i => $name) {
            $val = $actual[$i] ?? '';
            $out .= '  <arg name="' . self::esc($name) . '">' . self::esc($val) . "</arg>\n";
        }
        $out .= "</args>\n";
        return $out;
    }

    /** Fallback rendering for callers without a declared schema. */
    public static function renderFreeform(array $actual): string
    {
        if (!$actual) {
            return '';
        }
        return "\n\n<args>\n" . self::esc(implode("\n", $actual)) . "\n</args>\n";
    }

    private static function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
