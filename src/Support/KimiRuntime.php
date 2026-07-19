<?php

namespace SuperAICore\Support;

/**
 * Filesystem-layout probe for Moonshot's Kimi CLI generations.
 *
 * Two generations of the `kimi` binary keep their state in different
 * homes, and every support surface (auth detection, MCP sync, skills
 * bridge) needs to agree on which one is active:
 *
 *   - kimi-code (current, verified v0.27.0) — `$KIMI_CODE_HOME`, default
 *     `~/.kimi-code/`. Holds `bin/kimi` (single Node-SEA binary),
 *     `config.toml`, `credentials/kimi-code.json`, `mcp.json`, `skills/`.
 *   - kimi-cli (legacy Python package, ≤ v1.x) — `~/.kimi/`. Holds
 *     `credentials/kimi-code.json`, `mcp.json`, `agents/`.
 *
 * This is deliberately a *directory* probe, not a `kimi --help` probe:
 * the question here is "where do this install's config files live", and
 * the answer is authoritative from the filesystem alone — no child
 * process needed. `KimiCliBackend::resolveVariant()` still probes the
 * binary for the *argv dialect*; the two agree in practice because each
 * generation only ever writes its own home.
 *
 * Precedence: an explicit `$KIMI_CODE_HOME` wins, then an existing
 * `~/.kimi-code/`, then an existing legacy `~/.kimi/`. When neither dir
 * exists we assume kimi-code — it is the going-forward install.
 */
final class KimiRuntime
{
    /** True when the active install is the new kimi-code generation. */
    public static function isKimiCode(): bool
    {
        if (getenv('KIMI_CODE_HOME')) {
            return true;
        }
        if (is_dir(self::codeHome())) {
            return true;
        }
        return !is_dir(self::legacyHome());
    }

    /**
     * Absolute state dir of the new kimi-code install
     * (`$KIMI_CODE_HOME` or `~/.kimi-code`). The dir may not exist yet.
     */
    public static function codeHome(): string
    {
        $env = getenv('KIMI_CODE_HOME');
        if (is_string($env) && $env !== '') {
            return rtrim($env, '/\\');
        }
        return self::homeDir() . '/.kimi-code';
    }

    /** Absolute state dir of the legacy kimi-cli install (`~/.kimi`). */
    public static function legacyHome(): string
    {
        return self::homeDir() . '/.kimi';
    }

    /** Active generation's state dir. */
    public static function stateDir(): string
    {
        return self::isKimiCode() ? self::codeHome() : self::legacyHome();
    }

    /**
     * OAuth credential files that signal "logged in", most-preferred
     * first. Both generations name the file `credentials/kimi-code.json`;
     * only the parent dir differs. Callers should treat ANY existing
     * non-empty candidate as authenticated — a host mid-migration can
     * legitimately have either.
     *
     * @return string[]
     */
    public static function credentialCandidates(): array
    {
        return [
            self::codeHome() . '/credentials/kimi-code.json',
            self::legacyHome() . '/credentials/kimi-code.json',
        ];
    }

    /**
     * User-scope MCP config, `$HOME`-relative (the shape
     * `BackendCapabilities::mcpConfigPath()` promises). Both generations
     * read the same Claude-compatible `{"mcpServers": {...}}` JSON; only
     * the path moved. When `$KIMI_CODE_HOME` points outside `$HOME` the
     * relative contract can't hold — we fall back to the default
     * `.kimi-code/mcp.json` so the sync still lands where a stock
     * install reads it.
     */
    public static function mcpConfigRelPath(): string
    {
        if (!self::isKimiCode()) {
            return '.kimi/mcp.json';
        }
        $home = self::homeDir();
        $code = self::codeHome();
        if ($home !== '' && str_starts_with($code, $home . '/')) {
            return substr($code, strlen($home) + 1) . '/mcp.json';
        }
        return '.kimi-code/mcp.json';
    }

    /** Absolute user-scope MCP config path for the active generation. */
    public static function mcpConfigPath(): string
    {
        return self::stateDir() . '/mcp.json';
    }

    /**
     * kimi-code's user-scope auto-discovered skills dir, `$HOME`-relative
     * (kimi-code only — legacy kimi-cli has no skills dir; it reads
     * `.claude/skills/` natively). Same outside-`$HOME` fallback rule as
     * {@see mcpConfigRelPath()}.
     */
    public static function skillsRelPath(): string
    {
        return dirname(self::mcpConfigRelPath()) . '/skills';
    }

    protected static function homeDir(): string
    {
        return rtrim(getenv('HOME') ?: (getenv('USERPROFILE') ?: ''), '/\\');
    }
}
