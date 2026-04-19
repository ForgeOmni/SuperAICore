<?php

namespace SuperAICore\Services;

use Composer\InstalledVersions;
use Symfony\Component\Process\Process;

/**
 * Detects installation status + version + auth of CLI backends:
 * - claude (Claude Code CLI)
 * - codex (OpenAI Codex CLI)
 * - gemini (Google Gemini CLI)
 * - copilot (GitHub Copilot CLI)
 * - superagent (SDK, no CLI — reports composer version)
 */
class CliStatusDetector
{
    const BACKENDS = ['claude', 'codex', 'gemini', 'copilot', 'superagent'];

    public static function all(): array
    {
        return [
            'claude' => self::detectBinary('claude'),
            'codex' => self::detectBinary('codex'),
            'gemini' => self::detectBinary('gemini'),
            'copilot' => self::detectBinary('copilot'),
            'superagent' => self::superagentStatus(),
        ];
    }

    public static function detect(string $backend): array
    {
        return match ($backend) {
            'superagent' => self::superagentStatus(),
            'claude', 'codex', 'gemini', 'copilot' => self::detectBinary($backend),
            default => ['installed' => false, 'backend' => $backend],
        };
    }

    protected static function detectBinary(string $binary): array
    {
        $path = self::findPath($binary);
        if (!$path) {
            return [
                'backend' => $binary,
                'installed' => false,
                'path' => null,
                'version' => null,
                'auth' => null,
            ];
        }

        $env = self::childEnv();
        $versionProcess = Process::fromShellCommandline("\"{$path}\" --version 2>/dev/null", null, $env);
        $versionProcess->setTimeout(5);
        $versionProcess->run();
        $version = trim($versionProcess->getOutput()) ?: null;

        $auth = self::detectAuth($binary, $path);

        return [
            'backend' => $binary,
            'installed' => true,
            'path' => $path,
            'version' => $version,
            'auth' => $auth,
        ];
    }

    /**
     * Build a minimally complete env for CLI child processes.
     *
     * Why this exists: PHP's built-in dev server (`php artisan serve`), FPM
     * with `clear_env=yes`, and some supervisor setups strip HOME/USER/
     * LOGNAME/TMPDIR from the request worker's environment. CLI tools
     * (`claude auth status`, `codex login status`, copilot keychain lookups)
     * need HOME to locate their credential stores — without it they report
     * "not signed in" even though the user is authenticated.
     *
     * We rebuild the essentials from `posix_getpwuid()` (the kernel knows
     * the real user regardless of PHP env scrubbing) and inherit anything
     * already set so hosts can still inject OAuth tokens via env vars.
     */
    protected static function childEnv(): array
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
        $user = getenv('USER') ?: getenv('LOGNAME') ?: ($_SERVER['USER'] ?? '');

        if ((!$home || !$user) && function_exists('posix_getpwuid') && function_exists('posix_getuid')) {
            $pw = @posix_getpwuid(posix_getuid());
            if (is_array($pw)) {
                $home = $home ?: ($pw['dir'] ?? '');
                $user = $user ?: ($pw['name'] ?? '');
            }
        }

        $env = [];
        if ($home) {
            $env['HOME'] = $home;
        }
        if ($user) {
            $env['USER'] = $user;
            $env['LOGNAME'] = $user;
        }
        $env['PATH'] = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin:/opt/homebrew/bin';
        // Keychain-backed auth on macOS needs TMPDIR; Linux CLI tools sometimes
        // read XDG_CONFIG_HOME. Pass through when present.
        foreach (['TMPDIR', 'XDG_CONFIG_HOME', 'XDG_DATA_HOME', 'XDG_CACHE_HOME',
                  'LANG', 'LC_ALL', 'LC_CTYPE',
                  // Host-provided OAuth/token overrides for each CLI.
                  'ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'GEMINI_API_KEY',
                  'COPILOT_GITHUB_TOKEN', 'GH_TOKEN', 'GITHUB_TOKEN'] as $k) {
            $v = getenv($k);
            if ($v !== false && $v !== '') {
                $env[$k] = $v;
            }
        }
        return $env;
    }

    /** Resolve HOME via childEnv logic (single source of truth for dir lookups). */
    protected static function resolvedHome(): string
    {
        return self::childEnv()['HOME'] ?? '';
    }

    protected static function detectAuth(string $binary, string $path): ?array
    {
        $env = self::childEnv();
        if ($binary === 'claude') {
            $p = Process::fromShellCommandline("\"{$path}\" auth status 2>/dev/null", null, $env);
            $p->setTimeout(5);
            $p->run();
            $out = trim($p->getOutput());
            $decoded = json_decode($out, true);
            return is_array($decoded) ? $decoded : null;
        }
        if ($binary === 'codex') {
            $p = Process::fromShellCommandline("\"{$path}\" login status 2>&1", null, $env);
            $p->setTimeout(5);
            $p->run();
            $out = trim($p->getOutput());
            $normalized = strtolower($out);
            return [
                'loggedIn' => str_contains($normalized, 'logged in'),
                'status' => $out ?: null,
                'method' => str_contains($normalized, 'chatgpt') ? 'ChatGPT' : null,
            ];
        }
        if ($binary === 'copilot') {
            // Copilot has no first-class `auth status` — keychain/token state lives
            // inside the binary. Best heuristic: if any of the documented env vars is
            // set, treat as headless-token mode; else fall back to whether the
            // user-config dir Copilot writes on first login exists.
            $envToken = $env['COPILOT_GITHUB_TOKEN'] ?? $env['GH_TOKEN'] ?? $env['GITHUB_TOKEN'] ?? null;
            $home = self::resolvedHome();
            $xdg = $env['XDG_CONFIG_HOME'] ?? ($home ? $home . '/.config' : '');
            $configDir = $xdg ? $xdg . '/copilot' : '';
            $homeDir   = $home ? $home . '/.copilot' : '';
            $hasState  = ($configDir && is_dir($configDir)) || ($homeDir && is_dir($homeDir));

            $result = [
                'loggedIn' => (bool) $envToken || $hasState,
                'status'   => $envToken ? 'env-token' : ($hasState ? 'config-present' : 'not-logged-in'),
                'method'   => $envToken ? 'env' : ($hasState ? 'oauth' : null),
            ];

            // Optional CLI-based liveness probe. Copilot has no cheap auth-status
            // subcommand, so the best we can do without paying for an inference
            // is verify the binary itself runs and emits its help text. Gated
            // because spawning a child process on every status poll is wasteful.
            // Opt-in via env SUPERAICORE_COPILOT_PROBE=1. Result cached in-process.
            // Uses static:: so hosts/tests can subclass and swap the probe.
            if (static::copilotProbeEnabled()) {
                $result['live'] = static::probeCopilotLive($path);
            }

            return $result;
        }
        return null;
    }

    /** @var array<string,bool> */
    private static array $copilotLiveCache = [];

    protected static function copilotProbeEnabled(): bool
    {
        $v = getenv('SUPERAICORE_COPILOT_PROBE');
        return $v === '1' || strtolower((string) $v) === 'true';
    }

    /**
     * Run `<copilot> --help` under a short timeout. Exposed as protected so
     * tests (or hosts with a better probe) can replace it. Cached per path
     * within this request.
     */
    protected static function probeCopilotLive(string $path): bool
    {
        if (isset(self::$copilotLiveCache[$path])) {
            return self::$copilotLiveCache[$path];
        }
        $p = Process::fromShellCommandline("\"{$path}\" --help 2>&1", null, self::childEnv());
        $p->setTimeout(3);
        try {
            $p->run();
        } catch (\Throwable) {
            return self::$copilotLiveCache[$path] = false;
        }
        $out = (string) $p->getOutput();
        // Be lenient: any non-empty help text containing a known Copilot keyword
        // is enough. We're verifying "binary runs and responds", not parsing
        // help grammar.
        $live = $p->isSuccessful() && $out !== '' && (
            stripos($out, 'copilot') !== false || stripos($out, 'usage') !== false
        );
        return self::$copilotLiveCache[$path] = $live;
    }

    protected static function superagentStatus(): array
    {
        $version = null;
        if (class_exists(InstalledVersions::class)) {
            try {
                $version = InstalledVersions::getPrettyVersion('forgeomni/superagent');
            } catch (\Throwable $e) {
                $version = null;
            }
        }
        return [
            'backend' => 'superagent',
            'installed' => class_exists(\SuperAgent\Agent::class),
            'path' => null,
            'version' => $version ?: 'unknown',
            'auth' => null,
        ];
    }

    protected static function findPath(string $binary): ?string
    {
        $env = self::childEnv();
        if (PHP_OS_FAMILY === 'Windows') {
            $appdata = $env['APPDATA'] ?? getenv('APPDATA');
            $candidates = [];
            if ($appdata) {
                $candidates[] = "{$appdata}/npm/{$binary}.cmd";
                $candidates[] = "{$appdata}/npm/{$binary}";
            }
        } else {
            $home = self::resolvedHome() ?: '/root';
            $candidates = [
                "{$home}/.npm-global/bin/{$binary}",
                "{$home}/.local/bin/{$binary}",
                "/usr/local/bin/{$binary}",
                "/usr/bin/{$binary}",
                "/opt/homebrew/bin/{$binary}",
            ];
            $nodeVerP = Process::fromShellCommandline('node -v 2>/dev/null', null, $env);
            $nodeVerP->setTimeout(3);
            $nodeVerP->run();
            $nodeVer = trim($nodeVerP->getOutput());
            if ($nodeVer) {
                $candidates[] = "{$home}/.nvm/versions/node/{$nodeVer}/bin/{$binary}";
            }
        }

        foreach ($candidates as $p) {
            if ($p && file_exists($p)) return $p;
        }

        $cmd = PHP_OS_FAMILY === 'Windows' ? "where {$binary} 2>NUL" : "which {$binary} 2>/dev/null";
        $p = Process::fromShellCommandline($cmd, null, $env);
        $p->setTimeout(3);
        $p->run();
        $result = trim($p->getOutput());
        if ($result) {
            return explode("\n", $result)[0] ?: null;
        }
        return null;
    }
}
