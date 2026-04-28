<?php

namespace SuperAICore\Services;

use Symfony\Component\Process\Process;

/**
 * Picks the canonical install command for each engine CLI and runs it.
 * Kept deliberately stupid — this is orchestration, not a package
 * manager. We shell out to `npm`/`brew`/`curl|sh` that the user already
 * has, surface stdout/stderr unchanged, and return the exit code.
 *
 * Supported backends: claude / codex / gemini / copilot.
 * `superagent` is skipped — it's a Composer PHP SDK, not a CLI, and
 * host apps declare it in their own composer.json.
 *
 * Default source is `npm` for uniformity across macOS/Linux/Windows.
 * Callers can override with `--via=brew` on macOS for engines that
 * ship a Homebrew formula (currently only codex), or `--via=script`
 * for Claude's official curl installer.
 */
final class CliInstaller
{
    public const SOURCE_NPM    = 'npm';
    public const SOURCE_BREW   = 'brew';
    public const SOURCE_SCRIPT = 'script';
    // Kimi CLI is distributed as a Python package — not on npm or brew.
    // Preferred install is `uv tool install` (fast, PEP 668-safe); pip
    // with `--user` stays as a fallback for hosts without uv.
    public const SOURCE_UV     = 'uv';
    public const SOURCE_PIP    = 'pip';

    /** Backends we know how to install. Superagent is intentionally absent. */
    public const INSTALLABLE_BACKENDS = ['claude', 'codex', 'gemini', 'copilot', 'kimi'];

    /**
     * Install-command matrix. Each backend maps to a list of `{source, argv}`
     * options. The first entry is the default; users can pick a specific
     * source via `--via`.
     *
     * @return array<string,array<int,array{source:string, argv:array<int,string>, note?:string}>>
     */
    public static function sources(): array
    {
        return [
            'claude' => [
                ['source' => self::SOURCE_NPM,    'argv' => ['npm', 'install', '-g', '@anthropic-ai/claude-code']],
                ['source' => self::SOURCE_SCRIPT, 'argv' => ['sh', '-c', 'curl -fsSL https://claude.ai/install.sh | bash'], 'note' => 'POSIX only'],
            ],
            'codex' => [
                ['source' => self::SOURCE_NPM,  'argv' => ['npm', 'install', '-g', '@openai/codex']],
                ['source' => self::SOURCE_BREW, 'argv' => ['brew', 'install', 'codex'], 'note' => 'macOS only'],
            ],
            'gemini' => [
                ['source' => self::SOURCE_NPM, 'argv' => ['npm', 'install', '-g', '@google/gemini-cli']],
            ],
            'copilot' => [
                ['source' => self::SOURCE_NPM, 'argv' => ['npm', 'install', '-g', '@github/copilot']],
            ],
            'kimi' => [
                ['source' => self::SOURCE_UV,  'argv' => ['uv', 'tool', 'install', 'kimi-cli']],
                ['source' => self::SOURCE_PIP, 'argv' => ['pip', 'install', '--user', 'kimi-cli'], 'note' => 'fallback when uv unavailable'],
            ],
        ];
    }

    /**
     * Return the specific install option for a backend + source. When
     * `$source` is null the first (default) option wins.
     *
     * @return array{source:string, argv:array<int,string>, note?:string}|null
     */
    public static function resolveSource(string $backend, ?string $source = null): ?array
    {
        $all = self::sources()[$backend] ?? null;
        if (!$all) return null;
        if ($source === null) return $all[0];
        foreach ($all as $opt) {
            if ($opt['source'] === $source) return $opt;
        }
        return null;
    }

    /**
     * Does the underlying tool (npm / brew / sh) resolve on PATH?
     * Lets the command layer give a useful "install npm first" hint
     * instead of letting Symfony Process fail with a generic error.
     */
    public static function isToolAvailable(string $source): bool
    {
        $binary = match ($source) {
            self::SOURCE_NPM    => 'npm',
            self::SOURCE_BREW   => 'brew',
            self::SOURCE_SCRIPT => 'sh',
            self::SOURCE_UV     => 'uv',
            self::SOURCE_PIP    => 'pip',
            default             => null,
        };
        if ($binary === null) return false;

        // `where` (Windows) and `which` (Unix) print the resolved path on
        // stdout and only diagnostics on stderr. Symfony Process captures
        // stdout/stderr separately, so we don't need a shell stderr
        // redirect — and `2>/dev/null` is a Unix-only token that cmd.exe
        // misparses as an output filename, breaking detection on Windows.
        $cmd = PHP_OS_FAMILY === 'Windows' ? "where {$binary}" : "which {$binary}";
        $p = Process::fromShellCommandline($cmd);
        $p->setTimeout(3);
        $p->run();
        return $p->isSuccessful() && trim($p->getOutput()) !== '';
    }

    /**
     * Default install source for the current platform. macOS prefers
     * whatever the backend's `brew` option reports (if any); others
     * fall back to the matrix's first entry (npm).
     */
    public static function defaultSource(string $backend): ?string
    {
        $opt = self::resolveSource($backend, null);
        return $opt['source'] ?? null;
    }

    /**
     * Run the resolved install command, streaming output via `$writer`.
     * Returns the child process exit code — 0 = success.
     *
     * @param \Closure|null $writer fn(string $chunk): void — defaults to STDOUT
     */
    public static function install(string $backend, ?string $source = null, ?\Closure $writer = null, bool $dryRun = false): int
    {
        $opt = self::resolveSource($backend, $source);
        if (!$opt) {
            self::emit($writer, "[error] unknown backend or source: {$backend}"
                . ($source ? " via={$source}" : '') . "\n");
            return 1;
        }

        $cmdStr = implode(' ', array_map(fn($s) => (str_contains($s, ' ') ? escapeshellarg($s) : $s), $opt['argv']));
        if ($dryRun) {
            self::emit($writer, "[dry-run] {$cmdStr}\n");
            return 0;
        }

        if (!self::isToolAvailable($opt['source'])) {
            self::emit($writer, "[error] required tool not on PATH for source '{$opt['source']}'. Install it first, then retry.\n");
            return 127;
        }

        self::emit($writer, "[install] {$cmdStr}\n");

        $process = new Process($opt['argv']);
        $process->setTimeout(600);  // 10 min — npm -g can be slow
        return $process->run(fn($type, $buffer) => self::emit($writer, $buffer));
    }

    /**
     * Human-readable one-line install hint — used by `cli:status` to
     * point the user at `cli:install` without forcing a shell paste.
     */
    public static function installHint(string $backend): ?string
    {
        $opt = self::resolveSource($backend, null);
        if (!$opt) return null;
        return implode(' ', $opt['argv']);
    }

    private static function emit(?\Closure $writer, string $chunk): void
    {
        if ($writer) {
            ($writer)($chunk);
        } else {
            fwrite(\STDOUT, $chunk);
        }
    }
}
