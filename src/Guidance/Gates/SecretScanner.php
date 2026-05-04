<?php

declare(strict_types=1);

namespace SuperAICore\Guidance\Gates;

/**
 * Pure-PHP port of ruflo's `wasm-kernel/src/gates.rs::scan_secrets`.
 *
 * Eight regex patterns covering the most common secret shapes that
 * leak through prompts and tool inputs:
 *
 *   1. `api_key`/`apikey` =/: `'<8+ chars>'`
 *   2. `secret`/`password` =/: `'<4+ chars>'`
 *   3. `token`/`bearer`   =/: `'<10+ chars>'`
 *   4. PEM private-key headers (RSA / DSA / EC)
 *   5. OpenAI-style `sk-XXXX...`
 *   6. GitHub PAT `ghp_XXXX...`
 *   7. NPM token `npm_XXXX...`
 *   8. AWS access key id `AKIAXXXX...`
 *
 * Returns the redacted form of each match (first 4 + middle stars +
 * last 4 chars; matches < 12 chars fully masked). The same redaction
 * shape Rust uses, so audit logs are byte-identical between WASM and
 * PHP backends.
 *
 * `detect()` returns positions for callers that need to slice the
 * original string (e.g. AgentSpawn pre-flight scan that wants to
 * substitute redactions inline).
 */
final class SecretScanner
{
    private const PATTERNS = [
        // 1. api_key = "..."
        '/(?i)(?:api[_\-]?key|apikey)\s*[:=]\s*[\'"][^\'"]{8,}[\'"]/',
        // 2. secret/password = "..."
        '/(?i)(?:secret|password|passwd|pwd)\s*[:=]\s*[\'"][^\'"]{4,}[\'"]/',
        // 3. token/bearer = "..."
        '/(?i)(?:token|bearer)\s*[:=]\s*[\'"][^\'"]{10,}[\'"]/',
        // 4. PEM private key
        '/-----BEGIN (?:RSA |EC |DSA )?PRIVATE KEY-----/',
        // 5. OpenAI / Anthropic-style key
        '/sk-[a-zA-Z0-9]{20,}/',
        // 6. GitHub Personal Access Token
        '/ghp_[a-zA-Z0-9]{36}/',
        // 7. NPM token
        '/npm_[a-zA-Z0-9]{36}/',
        // 8. AWS access key id
        '/AKIA[0-9A-Z]{16}/',
    ];

    /**
     * Scan content; return the redacted form of every secret match.
     *
     * @return string[]
     */
    public function scan(string $content): array
    {
        $out = [];
        foreach (self::PATTERNS as $pattern) {
            if (!preg_match_all($pattern, $content, $matches)) continue;
            foreach ($matches[0] as $matched) {
                $out[] = self::redact($matched);
            }
        }
        return $out;
    }

    /**
     * Find every secret match with its byte offset. Caller decides how
     * to react (rewrite inline, fail-closed, log only).
     *
     * @return array<int, array{matched:string, offset:int, length:int, redacted:string}>
     */
    public function detect(string $content): array
    {
        $out = [];
        foreach (self::PATTERNS as $pattern) {
            if (!preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) continue;
            foreach ($matches[0] as [$matched, $offset]) {
                $out[] = [
                    'matched'  => $matched,
                    'offset'   => $offset,
                    'length'   => strlen($matched),
                    'redacted' => self::redact($matched),
                ];
            }
        }
        usort($out, fn ($a, $b) => $a['offset'] <=> $b['offset']);
        return $out;
    }

    /** Same redaction shape ruflo's WASM kernel emits — keep first/last 4. */
    public static function redact(string $value): string
    {
        $len = strlen($value);
        if ($len > 12) {
            return substr($value, 0, 4) . str_repeat('*', $len - 8) . substr($value, $len - 4);
        }
        return str_repeat('*', $len);
    }
}
