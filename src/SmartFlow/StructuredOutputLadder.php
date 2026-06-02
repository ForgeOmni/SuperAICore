<?php

declare(strict_types=1);

namespace SuperAICore\SmartFlow;

/**
 * The three-layer structured-output safety net. Given the raw text a CLI
 * returned and the requested JSON Schema, it tries to recover a valid structured
 * value in three escalating ways and reports which rung won:
 *
 *   1. native    — the CLI was asked for JSON and the whole reply parses
 *                  straight to a valid value.
 *   2. submitted — the model voluntarily fenced its JSON in a ```json block
 *                  (or a ``` block), which we parse and validate.
 *   3. extracted — last-ditch: sniff the first {...} / [...] out of free text.
 *
 * If none yields a schema-valid value, the result is invalid and the caller
 * substitutes the {@see Skip} sentinel. When no schema is requested the raw
 * text passes through as layer 'text'.
 *
 * Most CLI backends return free prose, so layers 2/3 carry the load here — the
 * schema is baked into the prompt rather than enforced by a native
 * `response_format`.
 */
final class StructuredOutputLadder
{
    /**
     * @param array<string, mixed>|null $schema
     * @return array{value: mixed, layer: string, valid: bool, errors: list<string>}
     */
    public static function resolve(string $text, ?array $schema, bool $nativeRequested = false): array
    {
        if ($schema === null) {
            return ['value' => $text, 'layer' => 'text', 'valid' => true, 'errors' => []];
        }

        $trimmed = trim($text);

        // Layer 1/2: the whole reply is JSON. If we asked for native JSON it's
        // 'native'; otherwise the model chose to emit bare JSON → 'submitted'.
        $direct = self::tryDecode($trimmed);
        if ($direct !== null) {
            $errors = SchemaValidator::validate($direct, $schema);
            if ($errors === []) {
                return [
                    'value' => $direct,
                    'layer' => $nativeRequested ? 'native' : 'submitted',
                    'valid' => true,
                    'errors' => [],
                ];
            }
        }

        // Layer 2: fenced ```json ... ``` (or ``` ... ```) block.
        foreach (self::fencedCandidates($text) as $candidate) {
            $decoded = self::tryDecode($candidate);
            if ($decoded === null) {
                continue;
            }
            $errors = SchemaValidator::validate($decoded, $schema);
            if ($errors === []) {
                return ['value' => $decoded, 'layer' => 'submitted', 'valid' => true, 'errors' => []];
            }
        }

        // Layer 3: greedy sniff for the first object/array embedded in prose.
        foreach (self::sniffCandidates($text) as $candidate) {
            $decoded = self::tryDecode($candidate);
            if ($decoded === null) {
                continue;
            }
            $errors = SchemaValidator::validate($decoded, $schema);
            if ($errors === []) {
                return ['value' => $decoded, 'layer' => 'extracted', 'valid' => true, 'errors' => []];
            }
        }

        // Nothing valid — report the best error we saw (re-validate the direct
        // decode if there was one, else a generic message).
        $errors = $direct !== null
            ? SchemaValidator::validate($direct, $schema)
            : ['no parseable JSON found in response'];

        return ['value' => Skip::instance(), 'layer' => 'none', 'valid' => false, 'errors' => $errors];
    }

    private static function tryDecode(string $json): ?array
    {
        $json = trim($json);
        if ($json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }
        return null;
    }

    /** @return list<string> */
    private static function fencedCandidates(string $text): array
    {
        $out = [];
        if (preg_match_all('/```json\s*\n?(.*?)\n?```/s', $text, $m)) {
            foreach ($m[1] as $block) {
                $out[] = $block;
            }
        }
        if (preg_match_all('/```\s*\n?(\{.*?\}|\[.*?\])\n?```/s', $text, $m)) {
            foreach ($m[1] as $block) {
                $out[] = $block;
            }
        }
        return $out;
    }

    /** @return list<string> */
    private static function sniffCandidates(string $text): array
    {
        $out = [];
        // Greedy first — captures the largest balanced-ish object/array.
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $out[] = $m[0];
        }
        if (preg_match('/\[.*\]/s', $text, $m)) {
            $out[] = $m[0];
        }
        return $out;
    }
}
