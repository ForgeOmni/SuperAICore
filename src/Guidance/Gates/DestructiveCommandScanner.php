<?php

declare(strict_types=1);

namespace SuperAICore\Guidance\Gates;

/**
 * Pure-PHP port of ruflo's `wasm-kernel/src/gates.rs::detect_destructive`.
 *
 * Twelve patterns covering destructive shell / SQL / git / k8s
 * operations that should require explicit confirmation before running:
 *
 *   - `rm -rf` / `del /s` / `format C:`
 *   - `DROP DATABASE/TABLE/SCHEMA/INDEX`, `TRUNCATE TABLE`,
 *     unbounded `DELETE FROM <table>` (no WHERE), `ALTER TABLE ... DROP`
 *   - `git push --force` / `git reset --hard` / `git clean -fd`
 *   - `kubectl delete --all` / `helm delete --all`
 *
 * `firstMatch()` returns the matched substring (or null) — same shape
 * as the Rust function. `allMatches()` yields every hit so a multi-
 * statement bash one-liner gets fully audited.
 *
 * Wiring point: AgentSpawn / Bash hook PreToolUse can call this on the
 * incoming command and return a soft-deny / require-confirm response
 * before the child process spawns.
 */
final class DestructiveCommandScanner
{
    private const PATTERNS = [
        '/(?i)\brm\s+-rf?\b/',
        '/(?i)\bdrop\s+(database|table|schema|index)\b/',
        '/(?i)\btruncate\s+table\b/',
        '/(?i)\bgit\s+push\s+.*--force\b/',
        '/(?i)\bgit\s+reset\s+--hard\b/',
        '/(?i)\bgit\s+clean\s+-fd?\b/',
        '/(?i)\bformat\s+[a-z]:/',
        '/(?i)\bdel\s+\/[sf]\b/',
        '/(?i)\b(?:kubectl|helm)\s+delete\s+(?:--all|namespace)\b/',
        '/(?i)\bDROP\s+(?:DATABASE|TABLE|SCHEMA)\b/',
        '/(?i)\bDELETE\s+FROM\s+\w+\s*$/',
        '/(?i)\bALTER\s+TABLE\s+\w+\s+DROP\b/',
    ];

    public function firstMatch(string $command): ?string
    {
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $command, $m)) {
                return $m[0];
            }
        }
        return null;
    }

    /** @return string[] */
    public function allMatches(string $command): array
    {
        $out = [];
        foreach (self::PATTERNS as $pattern) {
            if (preg_match_all($pattern, $command, $matches)) {
                foreach ($matches[0] as $m) $out[] = $m;
            }
        }
        return $out;
    }

    public function isDestructive(string $command): bool
    {
        return $this->firstMatch($command) !== null;
    }
}
