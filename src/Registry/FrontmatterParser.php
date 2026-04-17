<?php

namespace SuperAICore\Registry;

/**
 * Minimal YAML frontmatter parser for Claude skill/agent markdown files.
 *
 * Format:
 *   ---
 *   key: value
 *   list:
 *     - a
 *     - b
 *   ---
 *   body text...
 *
 * Covers the subset Claude skills/agents actually emit: scalars, simple
 * single-level lists, quoted strings. Deliberately not a full YAML parser —
 * we avoid pulling in symfony/yaml to keep this leaf-dep-free.
 */
final class FrontmatterParser
{
    /**
     * @return array{0: array<string,mixed>, 1: string} [frontmatter, body]
     */
    public static function parse(string $raw): array
    {
        $raw = preg_replace("/^\xEF\xBB\xBF/", '', $raw) ?? $raw;

        if (!preg_match('/^---\r?\n/', $raw)) {
            return [[], $raw];
        }

        $lines = preg_split("/\r?\n/", $raw);
        array_shift($lines);

        $yamlLines = [];
        $closed = false;
        while ($lines) {
            $line = array_shift($lines);
            if (rtrim($line) === '---') {
                $closed = true;
                break;
            }
            $yamlLines[] = $line;
        }

        if (!$closed) {
            return [[], $raw];
        }

        $body = implode("\n", $lines);
        return [self::parseYaml($yamlLines), $body];
    }

    /** @param string[] $lines */
    private static function parseYaml(array $lines): array
    {
        $out = [];
        $lastKey = null;

        foreach ($lines as $line) {
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            if ($lastKey !== null && preg_match('/^\s+-\s*(.*)$/', $line, $m)) {
                if (!is_array($out[$lastKey] ?? null)) {
                    $out[$lastKey] = [];
                }
                $out[$lastKey][] = self::unquote(trim($m[1]));
                continue;
            }

            if (preg_match('/^([A-Za-z0-9_-]+)\s*:\s*(.*)$/', $line, $m)) {
                $key = $m[1];
                $val = trim($m[2]);
                if ($val === '') {
                    $out[$key] = [];
                } elseif (preg_match('/^\[(.*)\]$/', $val, $arr)) {
                    $out[$key] = array_map(
                        fn($v) => self::unquote(trim($v)),
                        $arr[1] === '' ? [] : explode(',', $arr[1])
                    );
                } else {
                    $out[$key] = self::coerce(self::unquote($val));
                }
                $lastKey = $key;
            }
        }

        return $out;
    }

    private static function unquote(string $v): string
    {
        if (strlen($v) >= 2) {
            $first = $v[0];
            $last = $v[strlen($v) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($v, 1, -1);
            }
        }
        return $v;
    }

    private static function coerce(string $v): mixed
    {
        return match (strtolower($v)) {
            'true' => true,
            'false' => false,
            'null', '~' => null,
            default => $v,
        };
    }
}
