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
    /**
     * Built-in backends known to this class. Host-registered CLI engines
     * (added via `super-ai-core.engines` config) are merged in at runtime
     * by `resolveBackends()` so `cli:status` / `/providers` pick them up
     * without this const being rewritten.
     */
    const BACKENDS = ['claude', 'codex', 'gemini', 'copilot', 'superagent'];

    public static function all(): array
    {
        $out = [];
        foreach (self::resolveBackends() as $backend) {
            $out[$backend] = self::detect($backend);
        }
        return $out;
    }

    public static function detect(string $backend): array
    {
        if ($backend === 'superagent') {
            return self::superagentStatus();
        }
        // Built-in CLI engines dispatch to detectBinary() directly so we don't
        // pay for an EngineCatalog lookup on the hot path. Everything else
        // (host-registered CLI engines) must live in the catalog with
        // `is_cli: true` + a `cli_binary` to be probed; anything else is
        // reported as not-installed so the cli:status row still renders.
        if (in_array($backend, ['claude', 'codex', 'gemini', 'copilot'], true)) {
            return self::detectBinary($backend);
        }
        $engine = self::catalogEngine($backend);
        if ($engine && ($engine->isCli ?? false) && !empty($engine->cliBinary)) {
            return self::detectBinary((string) $engine->cliBinary);
        }
        return ['installed' => false, 'backend' => $backend];
    }

    /**
     * Full list of backends to probe: built-in CLIs + any extra CLI engines
     * a host registered via config. Returns built-ins first so the render
     * order stays stable across installs.
     *
     * @return string[]
     */
    protected static function resolveBackends(): array
    {
        $backends = self::BACKENDS;
        foreach (self::catalogKeys() as $key) {
            if (in_array($key, $backends, true)) continue;
            $engine = self::catalogEngine($key);
            if ($engine && ($engine->isCli ?? false)) {
                $backends[] = $key;
            }
        }
        return $backends;
    }

    /** @return string[] */
    protected static function catalogKeys(): array
    {
        if (!function_exists('app') || !class_exists(\SuperAICore\Services\EngineCatalog::class)) {
            return [];
        }
        try {
            return array_keys(app(\SuperAICore\Services\EngineCatalog::class)->all());
        } catch (\Throwable) {
            return [];
        }
    }

    protected static function catalogEngine(string $key): ?\SuperAICore\Support\EngineDescriptor
    {
        if (!function_exists('app') || !class_exists(\SuperAICore\Services\EngineCatalog::class)) {
            return null;
        }
        try {
            return app(\SuperAICore\Services\EngineCatalog::class)->get($key);
        } catch (\Throwable) {
            return null;
        }
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
        if ($binary === 'gemini') {
            // Gemini CLI has no `auth status` subcommand. Read the credential
            // files it writes on first `gemini login` directly — same discovery
            // order as SuperAgent\Auth\GeminiCliCredentials so the two stay in
            // sync. Falls back to env-var API keys when no file is present.
            return self::detectGeminiAuth($env);
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

        // Generic fallback for any CLI engine registered through
        // EngineCatalog but without a hardcoded branch above (0.6.2+).
        // Walks the provider-type registry to see if any configured type
        // targeting this engine has its env var set; failing that, checks
        // for a `~/.<binary>/` config directory. Keeps new CLI engines
        // from showing an empty auth cell on `/providers` just because
        // nobody remembered to add a branch here.
        return static::detectGenericCliAuth($binary, $env);
    }

    /**
     * Best-effort auth readout for a CLI engine we don't have a bespoke
     * branch for. Conservative by design — we never return `loggedIn:true`
     * on spec, only when we can point at concrete evidence (env var
     * present, or a config dir the CLI is known to create on first login).
     *
     * @param array<string,string> $env
     * @return array{loggedIn:bool,status:?string,method:?string,expires_at:?int}
     */
    protected static function detectGenericCliAuth(string $binary, array $env): array
    {
        $envKeyHit = null;
        if (function_exists('app') && class_exists(\SuperAICore\Services\ProviderTypeRegistry::class)) {
            try {
                $registry = app(\SuperAICore\Services\ProviderTypeRegistry::class);
                foreach ($registry->all() as $descriptor) {
                    if ($descriptor->envKey === null) continue;
                    if (!empty($env[$descriptor->envKey])) {
                        $envKeyHit = $descriptor->envKey;
                        break;
                    }
                }
            } catch (\Throwable) {
                // fall through
            }
        }

        // Convention: `<binary> login` writes into `~/.<engine>/`. The
        // engine key is the binary minus any `-cli` / `_cli` suffix (so
        // `kiro-cli` → `~/.kiro/`, `claude` → `~/.claude/`). We probe both
        // the stripped form and the literal binary so odd-binaries-with-
        // their-own-dir still resolve.
        $home = self::resolvedHome();
        $hasConfigDir = false;
        $foundDir = null;
        if ($home) {
            $homeTrim = rtrim($home, '/');
            $candidates = array_unique([
                preg_replace('/[-_]cli$/', '', ltrim($binary, '.')),
                ltrim($binary, '.'),
            ]);
            foreach ($candidates as $name) {
                if ($name === '') continue;
                $dir = $homeTrim . '/.' . $name;
                if (is_dir($dir)) {
                    $hasConfigDir = true;
                    $foundDir = $dir;
                    break;
                }
            }
        }

        return [
            'loggedIn'   => $envKeyHit !== null || $hasConfigDir,
            'status'     => $envKeyHit !== null
                ? 'env-key'
                : ($hasConfigDir ? 'config-present' : 'not-logged-in'),
            'method'     => $envKeyHit !== null
                ? 'api-key'
                : ($hasConfigDir ? 'oauth' : null),
            'config_dir' => $foundDir,
            'expires_at' => null,
        ];
    }

    /**
     * Read Gemini CLI credentials from the files it writes on `gemini login`.
     * Prefers SuperAgent\Auth\GeminiCliCredentials when available (so the
     * normalization stays consistent with `superagent auth login gemini`);
     * falls back to a local probe of the same path list otherwise.
     *
     * @return array{loggedIn:bool,status:?string,method:?string,expires_at:?int}
     */
    protected static function detectGeminiAuth(array $env): array
    {
        $home = $env['HOME'] ?? '';
        $envKey = $env['GEMINI_API_KEY'] ?? $env['GOOGLE_API_KEY'] ?? null;

        if ($home && class_exists(\SuperAgent\Auth\GeminiCliCredentials::class)) {
            try {
                $creds = (new \SuperAgent\Auth\GeminiCliCredentials(
                    $home . '/.gemini/oauth_creds.json',
                    $home . '/.gemini/credentials.json',
                    $home . '/.gemini/settings.json',
                ))->read();
                if (is_array($creds)) {
                    $mode = (string) ($creds['mode'] ?? '');
                    return [
                        'loggedIn'   => true,
                        'status'     => (string) ($creds['source'] ?? $mode),
                        'method'     => $mode ?: null,
                        'expires_at' => $creds['expires_at'] ?? null,
                    ];
                }
            } catch (\Throwable) {
                // fall through
            }
        }

        // Local fallback: check the same files without the SuperAgent helper.
        if ($home) {
            foreach (['/.gemini/oauth_creds.json', '/.gemini/credentials.json'] as $rel) {
                if (is_file($home . $rel)) {
                    return [
                        'loggedIn'   => true,
                        'status'     => ltrim($rel, '/'),
                        'method'     => 'oauth',
                        'expires_at' => null,
                    ];
                }
            }
        }

        if ($envKey) {
            return [
                'loggedIn'   => true,
                'status'     => 'env-key',
                'method'     => 'api_key',
                'expires_at' => null,
            ];
        }

        return [
            'loggedIn'   => false,
            'status'     => 'not-logged-in',
            'method'     => null,
            'expires_at' => null,
        ];
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
