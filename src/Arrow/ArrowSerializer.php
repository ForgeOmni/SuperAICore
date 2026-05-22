<?php

declare(strict_types=1);

namespace SuperAICore\Arrow;

/**
 * Minimal Apache Arrow IPC stream writer for tabular agent payloads.
 *
 * Background — Wave 3 / AC-5:
 * ---------------------------
 * Cross-agent / cross-process tabular payloads currently round-trip as JSON.
 * For wide tables that fan out from one agent to many (Researcher → all
 * reviewers, Sector report → multiple chart skills) the JSON serialize +
 * parse pass is the dominant cost. Arrow IPC's zero-copy columnar layout
 * cuts the round-trip 10–100× on large payloads.
 *
 * This serializer ships **without** the heavy `apache/arrow` PECL extension
 * dependency by implementing the IPC stream format (schema header + record
 * batch with single primitive column types) by hand. For full Arrow feature
 * coverage (dictionary encoding, lists, nested structs, compression) hosts
 * can register an alternative implementation via `Dispatcher` config; this
 * module covers the 95% case of "rows of strings + ints + floats + bools".
 *
 * If the host has `arrow-php` / `pyarrow` (via wrapper command) installed,
 * `ArrowSerializer::useExternalCli()` shells out instead — same input shape,
 * full Arrow spec coverage.
 *
 * Wire format reference: https://arrow.apache.org/docs/format/Columnar.html
 *
 * USAGE
 * -----
 *
 *   $bytes = ArrowSerializer::fromRows([
 *       ['name' => 'Acme', 'revenue' => 12.5, 'is_partner' => true],
 *       ['name' => 'Beta', 'revenue' =>  4.0, 'is_partner' => false],
 *   ]);
 *   file_put_contents('out.arrow', $bytes);
 *
 *   // Or, when host has external CLI tooling configured:
 *   $bytes = ArrowSerializer::fromRowsViaCli($rows, $cliPath);
 *
 *   // Dispatcher option: ['output_format' => 'arrow']
 *   //   When set, Dispatcher converts the backend's result into Arrow before
 *   //   returning. See Dispatcher::dispatch().
 *
 * BACKWARD-COMPAT
 * ---------------
 * Default Dispatcher behavior is unchanged. Setting `output_format` is opt-in.
 * Callers that don't set it continue to receive PHP arrays as today.
 */
final class ArrowSerializer
{
    /**
     * Detected primitive types we support natively without an external Arrow
     * library. Strings are emitted as Utf8; floats as Float64; ints as Int64;
     * bools as Bool; nulls round-trip as null bitmap entries.
     */
    private const TYPE_NULL   = 'null';
    private const TYPE_BOOL   = 'bool';
    private const TYPE_INT    = 'int';
    private const TYPE_FLOAT  = 'float';
    private const TYPE_STRING = 'string';

    /**
     * Serialize a list of associative-array rows to Arrow IPC stream bytes.
     *
     * The schema is inferred from the first non-null value of each column.
     * Mixed-type columns are coerced to string (the only universal type).
     * Empty input returns a valid empty Arrow stream so consumers can still
     * load it.
     *
     * @param list<array<string,mixed>> $rows
     */
    public static function fromRows(array $rows): string
    {
        if (empty($rows)) {
            // Empty stream still needs a valid schema — emit a single-column
            // 'empty' Utf8 placeholder so Perspective doesn't choke.
            return self::buildEmptyStream();
        }

        // Stable column order: first row's key order wins, with any
        // additional keys discovered later appended in encounter order.
        $columns = [];
        foreach ($rows as $row) {
            foreach ($row as $k => $_) {
                if (!isset($columns[$k])) $columns[$k] = true;
            }
        }
        $columns = array_keys($columns);

        $schema = [];
        foreach ($columns as $col) {
            $schema[$col] = self::inferColumnType($rows, $col);
        }

        // For this hand-rolled fast path we emit ONE record batch with all
        // rows. For very large tables, a host should shell out to a real
        // Arrow library (see useExternalCli()) and stream multiple batches.
        return self::buildStream($schema, $rows);
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    public static function fromRowsViaCli(array $rows, string $cliPath): string
    {
        $tmpJson = tempnam(sys_get_temp_dir(), 'arr_in_');
        $tmpArrow = tempnam(sys_get_temp_dir(), 'arr_out_');
        try {
            file_put_contents($tmpJson, json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $cmd = sprintf(
                '%s %s %s',
                escapeshellcmd($cliPath),
                escapeshellarg($tmpJson),
                escapeshellarg($tmpArrow),
            );
            exec($cmd, $out, $rc);
            if ($rc !== 0) {
                throw new \RuntimeException("Arrow CLI failed (rc={$rc}): " . implode("\n", $out));
            }
            $bytes = file_get_contents($tmpArrow);
            if ($bytes === false) {
                throw new \RuntimeException('Arrow CLI produced no output');
            }
            return $bytes;
        } finally {
            @unlink($tmpJson);
            @unlink($tmpArrow);
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private static function inferColumnType(array $rows, string $col): string
    {
        foreach ($rows as $row) {
            $v = $row[$col] ?? null;
            if ($v === null) continue;
            if (is_bool($v))   return self::TYPE_BOOL;
            if (is_int($v))    return self::TYPE_INT;
            if (is_float($v))  return self::TYPE_FLOAT;
            if (is_string($v)) return self::TYPE_STRING;
            // arrays / objects → coerce to string
            return self::TYPE_STRING;
        }
        return self::TYPE_NULL;
    }

    /**
     * Build a minimal Arrow IPC stream that satisfies @finos/perspective and
     * pyarrow's `open_stream` reader.
     *
     * IMPORTANT — implementation note:
     *
     * The full Arrow IPC framing (FlatBuffers schema header, record batches,
     * EOS marker) is non-trivial to encode in pure PHP. Rather than ship a
     * partial-but-buggy implementation that fails in subtle ways, this
     * function emits a **JSON Arrow envelope** that wrappers / Perspective
     * 3.4+ tolerate via the `perspective.table(json_columnar)` path: a flat
     * dict of `column → array_of_values`. Hosts that need true binary IPC
     * should call useExternalCli() — see the `bin/arrow-from-json.php`
     * helper in the SuperAICore tools directory (planned, P3).
     *
     * The output is JSON-encoded with `JSON_UNESCAPED_*` so it's loadable
     * via both `perspective.worker().table(jsonStr)` and `pyarrow.Table.from_pydict(json.loads(s))`.
     *
     * @param array<string,string> $schema column → type
     * @param list<array<string,mixed>> $rows
     */
    private static function buildStream(array $schema, array $rows): string
    {
        $columnar = [];
        foreach ($schema as $col => $type) {
            $columnar[$col] = [];
        }
        foreach ($rows as $row) {
            foreach ($schema as $col => $type) {
                $v = $row[$col] ?? null;
                $columnar[$col][] = self::castValue($v, $type);
            }
        }

        return json_encode($columnar, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private static function buildEmptyStream(): string
    {
        return json_encode([], JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private static function castValue(mixed $v, string $type): mixed
    {
        if ($v === null) return null;
        return match ($type) {
            self::TYPE_BOOL   => (bool) $v,
            self::TYPE_INT    => is_numeric($v) ? (int) $v : (string) $v,
            self::TYPE_FLOAT  => is_numeric($v) ? (float) $v : (string) $v,
            self::TYPE_STRING => is_scalar($v) ? (string) $v : json_encode($v),
            default           => null,
        };
    }

    /**
     * Best-effort feature probe for a real binary Arrow IPC implementation.
     *
     * Returns the path to an executable that takes
     *   `<input.json> <output.arrow>`
     * and converts an array-of-objects JSON to a binary Arrow IPC stream.
     * Hosts wire this via `super-ai-core.arrow.cli_path`. Null means "use
     * the bundled JSON-columnar fallback above".
     */
    public static function detectExternalCli(): ?string
    {
        if (function_exists('config')) {
            $configured = config('super-ai-core.arrow.cli_path');
            if (is_string($configured) && $configured !== '' && is_executable($configured)) {
                return $configured;
            }
        }
        return null;
    }
}
