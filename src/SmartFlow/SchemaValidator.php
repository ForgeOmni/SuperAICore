<?php

declare(strict_types=1);

namespace SuperAICore\SmartFlow;

/**
 * A small, dependency-free JSON-Schema validator — enough to gate CLI output
 * without pulling in a full draft-2020 validator: required, type, enum,
 * min/max, length, pattern, items, and nested properties.
 *
 * It collects *all* errors and returns them rather than throwing on the first —
 * the ladder wants a yes/no plus a reason, not an exception per field.
 */
final class SchemaValidator
{
    /**
     * @param array<string, mixed> $schema
     * @return list<string> empty when valid
     */
    public static function validate(mixed $data, array $schema, string $path = '$'): array
    {
        $errors = [];

        $type = $schema['type'] ?? null;
        if ($type !== null && !self::typeMatches($data, $type)) {
            $errors[] = sprintf('%s: expected %s, got %s', $path, self::typeLabel($type), self::jsonType($data));
            return $errors; // a wrong type makes deeper checks meaningless
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($data, $schema['enum'], true)) {
            $errors[] = sprintf('%s: must be one of [%s]', $path, implode(', ', array_map('strval', $schema['enum'])));
        }

        if (is_string($data)) {
            if (isset($schema['minLength']) && mb_strlen($data) < (int) $schema['minLength']) {
                $errors[] = sprintf('%s: shorter than minLength %d', $path, (int) $schema['minLength']);
            }
            if (isset($schema['maxLength']) && mb_strlen($data) > (int) $schema['maxLength']) {
                $errors[] = sprintf('%s: longer than maxLength %d', $path, (int) $schema['maxLength']);
            }
            if (isset($schema['pattern']) && @preg_match('/' . $schema['pattern'] . '/u', $data) === 0) {
                $errors[] = sprintf('%s: does not match pattern', $path);
            }
        }

        if (is_int($data) || is_float($data)) {
            if (isset($schema['minimum']) && $data < $schema['minimum']) {
                $errors[] = sprintf('%s: below minimum %s', $path, (string) $schema['minimum']);
            }
            if (isset($schema['maximum']) && $data > $schema['maximum']) {
                $errors[] = sprintf('%s: above maximum %s', $path, (string) $schema['maximum']);
            }
        }

        // Object: required + per-property recursion.
        if (self::jsonType($data) === 'object' || ($type === 'object' && is_array($data))) {
            $assoc = is_array($data) ? $data : [];
            foreach ((array) ($schema['required'] ?? []) as $req) {
                if (!array_key_exists($req, $assoc)) {
                    $errors[] = sprintf('%s.%s: required field missing', $path, $req);
                }
            }
            foreach ((array) ($schema['properties'] ?? []) as $prop => $propSchema) {
                if (array_key_exists($prop, $assoc) && is_array($propSchema)) {
                    $errors = array_merge($errors, self::validate($assoc[$prop], $propSchema, $path . '.' . $prop));
                }
            }
        }

        // Array: count + item recursion.
        if ($type === 'array' && is_array($data)) {
            if (isset($schema['minItems']) && count($data) < (int) $schema['minItems']) {
                $errors[] = sprintf('%s: fewer than minItems %d', $path, (int) $schema['minItems']);
            }
            if (isset($schema['maxItems']) && count($data) > (int) $schema['maxItems']) {
                $errors[] = sprintf('%s: more than maxItems %d', $path, (int) $schema['maxItems']);
            }
            if (isset($schema['items']) && is_array($schema['items'])) {
                foreach ($data as $i => $item) {
                    $errors = array_merge($errors, self::validate($item, $schema['items'], $path . '[' . $i . ']'));
                }
            }
        }

        return $errors;
    }

    public static function isValid(mixed $data, array $schema): bool
    {
        return self::validate($data, $schema) === [];
    }

    private static function typeMatches(mixed $data, string|array $type): bool
    {
        $types = is_array($type) ? $type : [$type];
        foreach ($types as $t) {
            if ($t === 'number' && (is_int($data) || is_float($data))) {
                return true;
            }
            if ($t === 'integer' && is_int($data)) {
                return true;
            }
            // An empty PHP array is an ambiguous {}/[] — accept it for either
            // 'array' or 'object' rather than guessing from key shape.
            if (($t === 'array' || $t === 'object') && $data === []) {
                return true;
            }
            if (self::jsonType($data) === $t) {
                return true;
            }
        }
        return false;
    }

    private static function typeLabel(string|array $type): string
    {
        return is_array($type) ? implode('|', $type) : $type;
    }

    public static function jsonType(mixed $value): string
    {
        if (is_null($value)) {
            return 'null';
        }
        if (is_bool($value)) {
            return 'boolean';
        }
        if (is_int($value)) {
            return 'integer';
        }
        if (is_float($value)) {
            return 'number';
        }
        if (is_string($value)) {
            return 'string';
        }
        if (is_array($value)) {
            if ($value === []) {
                return 'array';
            }
            return array_keys($value) === range(0, count($value) - 1) ? 'array' : 'object';
        }
        return 'unknown';
    }
}
