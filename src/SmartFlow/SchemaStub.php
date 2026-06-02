<?php

declare(strict_types=1);

namespace SuperAICore\SmartFlow;

/**
 * Generates a minimal schema-conforming value from a JSON Schema. Used by
 * {@see BackendAgentRunner} in rehearsal mode so that rehearsing a structured
 * flow (`flow run --rehearse` / `SUPERAICORE_FLOW_FAKE=1`) produces output that
 * actually passes {@see SchemaValidator}, letting the whole cross-CLI flow run
 * end-to-end at zero cost without any CLI installed.
 *
 * Deterministic: no randomness, so a rehearsal is reproducible and its call
 * signatures are stable for resume.
 */
final class SchemaStub
{
    /**
     * @param array<string, mixed> $schema
     */
    public static function generate(array $schema, string $seed = 'stub'): mixed
    {
        // enum wins regardless of declared type.
        if (isset($schema['enum']) && is_array($schema['enum']) && $schema['enum'] !== []) {
            return $schema['enum'][0];
        }

        $type = $schema['type'] ?? self::inferType($schema);
        $type = is_array($type) ? ($type[0] ?? 'string') : $type;

        return match ($type) {
            'object' => self::object($schema, $seed),
            'array' => self::arrayOf($schema, $seed),
            'integer' => (int) ($schema['minimum'] ?? 0),
            'number' => (float) ($schema['minimum'] ?? 0),
            'boolean' => true,
            'null' => null,
            default => self::string($schema, $seed),
        };
    }

    /** @param array<string, mixed> $schema */
    private static function object(array $schema, string $seed): array
    {
        $out = [];
        $props = (array) ($schema['properties'] ?? []);
        $required = (array) ($schema['required'] ?? array_keys($props));
        foreach ($props as $name => $propSchema) {
            // Always populate required fields; also fill optionals so rehearsal
            // output looks realistic.
            if (is_array($propSchema)) {
                $out[$name] = self::generate($propSchema, $seed . '.' . $name);
            }
        }
        // Guarantee required keys exist even without a property definition.
        foreach ($required as $name) {
            if (!array_key_exists($name, $out)) {
                $out[$name] = $seed;
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $schema */
    private static function arrayOf(array $schema, string $seed): array
    {
        $min = max(1, (int) ($schema['minItems'] ?? 1));
        $items = is_array($schema['items'] ?? null) ? $schema['items'] : ['type' => 'string'];
        $out = [];
        for ($i = 0; $i < $min; $i++) {
            $out[] = self::generate($items, $seed . '[' . $i . ']');
        }
        return $out;
    }

    /** @param array<string, mixed> $schema */
    private static function string(array $schema, string $seed): string
    {
        $value = "[rehearsal] {$seed}";
        $min = (int) ($schema['minLength'] ?? 0);
        if (mb_strlen($value) < $min) {
            $value .= str_repeat('.', $min - mb_strlen($value));
        }
        if (isset($schema['maxLength'])) {
            $value = mb_substr($value, 0, (int) $schema['maxLength']);
        }
        return $value;
    }

    /** @param array<string, mixed> $schema */
    private static function inferType(array $schema): string
    {
        if (isset($schema['properties'])) {
            return 'object';
        }
        if (isset($schema['items'])) {
            return 'array';
        }
        return 'string';
    }
}
