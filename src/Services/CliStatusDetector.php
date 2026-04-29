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
    const BACKENDS = ['claude', 'codex', 'gemini', 'copilot', 'kimi', 'superagent'];

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
        if (in_array($backend, ['claude', 'codex', 'gemini', 'copilot', 'kimi'], true)) {
            return self::detectBinary($backend);
        }
        $engine = self::catalogEngine($backend);
        if ($engine && ($engine->isCli ?? false) && !empty($engine->cliBinary)) {
            return self::detectBinary((string) $engine->cliBinary);
        }
        return ['installed' => false, 'backend' => $backend];
    }

    /**
     * Lightweight "is this backend reachable on this host?" check.
     *
     * Hosts use this as a precondition gate before dispatching a task to
     * a backend (e.g. `if (!isInstalled($backend)) { show error; return; }`).
     * Distinct from `detect()` because:
     *
     *   - SDK backends (superagent) have no CLI binary — `class_exists` is
     *     the truth, not a path lookup. Calling `findCliPath()` on these
     *     always returns null and falsely reports "not installed".
     *   - CLI backends only need their binary on disk to be considered
     *     reachable here. `detect()` additionally runs `<binary> --version`
     *     (~100-300ms cold) which is wasted work for a yes/no gate.
     *
     * The host's previous shortcut — treating `findCliPath()` non-null as
     * the install gate — broke MINIMAX/Qwen/GLM/etc. providers (they all
     * route through the `superagent` backend) by demanding a non-existent
     * `superagent` binary. Use this method instead for boolean gating.
     */
    public static function isInstalled(string $backend): bool
    {
        if ($backend === 'superagent') {
            return class_exists(\SuperAgent\Agent::class);
        }
        if (in_array($backend, ['claude', 'codex', 'gemini', 'copilot', 'kimi'], true)) {
            return self::findPath($backend) !== null;
        }
        $engine = self::catalogEngine($backend);
        if ($engine && ($engine->isCli ?? false) && !empty($engine->cliBinary)) {
            return self::findPath((string) $engine->cliBinary) !== null;
        }
        // Catalog-registered non-CLI engine (future SDK-style backends):
        // catalog presence + dispatcher class registered is enough — actual
        // runtime errors will surface through the backend's own error path.
        if ($engine !== null && !($engine->isCli ?? false)) {
            return true;
        }
        return false;
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

    /**
     * Run a short CLI probe and return trimmed stdout, or null if the binary
     * times out, crashes, or prints nothing. Never throws — status probes must
     * not surface failures to callers, since `cli:status` and `/providers` are
     * called from request paths that assume the detector is infallible.
     *
     * `$mergeStderr` opts into merging stderr into the returned string —
     * needed when the CLI prints status to stderr (codex `login status`,
     * copilot `--help`). Always prefer this over baking `2>&1` into the
     * command string: cmd.exe on Windows treats `2>/dev/null` as an output
     * filename and aborts the whole command (#175), and Symfony Process
     * already captures the streams separately for us.
     *
     * @param array<string,string> $env
     */
    protected static function safeProbeOutput(string $command, array $env, int $timeoutSeconds, bool $mergeStderr = false): ?string
    {
        $p = Process::fromShellCommandline($command, null, $env);
        $p->setTimeout($timeoutSeconds);
        try {
            $p->run();
        } catch (\Throwable) {
            return null;
        }
        $out = trim($p->getOutput());
        if ($mergeStderr) {
            $err = trim($p->getErrorOutput());
            if ($err !== '') {
                $out = $out === '' ? $err : ($out . "\n" . $err);
            }
        }
        return $out === '' ? null : $out;
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
        $version = static::safeProbeOutput("\"{$path}\" --version", $env, 5);

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
     * with `clear_env=yes`, supervisor, IIS workers, and stock `cmd.exe`
     * sessions all strip or never set the env vars CLI tools need to find
     * their credential stores. Without HOME (POSIX) or USERPROFILE (Windows),
     * `claude auth status`, `codex login status`, gemini OAuth file lookups,
     * and copilot keychain lookups all silently report "not signed in" even
     * when the user is authenticated.
     *
     * Resolution order:
     *   - POSIX: `getenv(HOME)` → `posix_getpwuid()` (kernel-level, immune
     *     to PHP env scrubbing).
     *   - Windows: `getenv(USERPROFILE)` → `getenv(HOMEDRIVE)+getenv(HOMEPATH)`.
     *   - We mirror the resolved value into both HOME and USERPROFILE so
     *     downstream binaries find it regardless of which one they read.
     *
     * Inherited values from the PHP parent process are preserved so hosts
     * can still inject OAuth tokens (ANTHROPIC_API_KEY, GH_TOKEN, …) via
     * env vars in supervisord/systemd configs.
     */
    protected static function childEnv(): array
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
        $user = getenv('USER') ?: getenv('LOGNAME') ?: ($_SERVER['USER'] ?? '');

        if (PHP_OS_FAMILY === 'Windows') {
            if (!$home) {
                $home = getenv('USERPROFILE') ?: ($_SERVER['USERPROFILE'] ?? '');
                if (!$home) {
                    $drive = getenv('HOMEDRIVE') ?: '';
                    $path  = getenv('HOMEPATH')  ?: '';
                    if ($drive && $path) {
                        $home = $drive . $path;
                    }
                }
            }
            if (!$user) {
                $user = getenv('USERNAME') ?: ($_SERVER['USERNAME'] ?? '');
            }
        } elseif ((!$home || !$user) && function_exists('posix_getpwuid') && function_exists('posix_getuid')) {
            $pw = @posix_getpwuid(posix_getuid());
            if (is_array($pw)) {
                $home = $home ?: ($pw['dir']  ?? '');
                $user = $user ?: ($pw['name'] ?? '');
            }
        }

        $env = [];
        if ($home) {
            $env['HOME'] = $home;
            // Mirror to Windows-native names so binaries that only read
            // USERPROFILE still resolve. Cheap and harmless on POSIX too.
            if (PHP_OS_FAMILY === 'Windows') {
                $env['USERPROFILE'] = $home;
            }
        }
        if ($user) {
            $env['USER']    = $user;
            $env['LOGNAME'] = $user;
            if (PHP_OS_FAMILY === 'Windows') {
                $env['USERNAME'] = $user;
            }
        }

        // Sensible PATH fallback per platform when PHP wasn't given one.
        $env['PATH'] = getenv('PATH') ?: (PHP_OS_FAMILY === 'Windows'
            ? 'C:\\Windows\\System32;C:\\Windows;C:\\Windows\\System32\\Wbem'
            : '/usr/local/bin:/usr/bin:/bin:/opt/homebrew/bin');

        // Pass-through env vars: cross-platform first, then platform-specific
        // additions. Windows binaries need APPDATA/LOCALAPPDATA for credential
        // stores (npm-global tools cache OAuth there) and SystemRoot — most
        // Win32 binaries refuse to start without %SystemRoot% set. POSIX
        // binaries care about TMPDIR (mac keychain) and XDG_* (Linux conf).
        $passthrough = [
            'LANG', 'LC_ALL', 'LC_CTYPE',
            'ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'GEMINI_API_KEY', 'GOOGLE_API_KEY',
            'COPILOT_GITHUB_TOKEN', 'GH_TOKEN', 'GITHUB_TOKEN',
        ];
        if (PHP_OS_FAMILY === 'Windows') {
            $passthrough = array_merge($passthrough, [
                'APPDATA', 'LOCALAPPDATA', 'PROGRAMDATA',
                'ProgramFiles', 'ProgramFiles(x86)', 'ProgramW6432',
                'SystemRoot', 'SystemDrive', 'COMSPEC', 'PATHEXT',
                'TEMP', 'TMP',
            ]);
        } else {
            $passthrough = array_merge($passthrough, [
                'TMPDIR', 'XDG_CONFIG_HOME', 'XDG_DATA_HOME', 'XDG_CACHE_HOME',
            ]);
        }
        foreach ($passthrough as $k) {
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
            $out = static::safeProbeOutput("\"{$path}\" auth status", $env, 5) ?? '';
            $decoded = json_decode($out, true);
            return is_array($decoded) ? $decoded : null;
        }
        if ($binary === 'codex') {
            // Codex 0.5+ writes "Logged in …" to stderr when piped — merge.
            $out = static::safeProbeOutput("\"{$path}\" login status", $env, 5, mergeStderr: true) ?? '';
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
        if ($binary === 'kimi') {
            // Kimi CLI has no `auth status` subcommand — `kimi login`
            // writes the OAuth token to ~/.kimi/credentials/kimi-code.json.
            // The file is 0600 and its mere presence (+ non-zero size)
            // is sufficient for the logged-in signal. The `work_dirs`
            // tracker in ~/.kimi/kimi.json is orthogonal and does NOT
            // indicate auth — it persists even after `kimi logout`.
            $home = self::resolvedHome();
            $credFile = $home ? $home . '/.kimi/credentials/kimi-code.json' : '';
            $loggedIn = $credFile !== ''
                && is_file($credFile)
                && @filesize($credFile) > 0;
            return [
                'loggedIn' => $loggedIn,
                'status'   => $loggedIn ? 'oauth-credential-present' : 'not-logged-in',
                'method'   => $loggedIn ? 'oauth' : null,
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
        // Be lenient: any non-empty help text containing a known Copilot
        // keyword is enough. We're verifying "binary runs and responds",
        // not parsing help grammar. Some copilot builds print --help to
        // stderr, so merge.
        $out = static::safeProbeOutput("\"{$path}\" --help", self::childEnv(), 3, mergeStderr: true) ?? '';
        $live = $out !== '' && (
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
        $candidates = match (PHP_OS_FAMILY) {
            'Windows' => self::windowsPathCandidates($binary, $env),
            'Darwin'  => self::macPathCandidates($binary, $env),
            default   => self::linuxPathCandidates($binary, $env),
        };

        foreach ($candidates as $p) {
            if ($p && file_exists($p)) return $p;
        }

        // Last resort: ask the OS resolver. `where` (Windows) and `which`
        // (Unix) both print only the path on stdout when found and only
        // diagnostics on stderr when not — Symfony Process captures the
        // streams separately so no shell redirect is needed.
        $cmd = PHP_OS_FAMILY === 'Windows' ? "where {$binary}" : "which {$binary}";
        $result = static::safeProbeOutput($cmd, $env, 3);
        if ($result) {
            return explode("\n", $result)[0] ?: null;
        }
        return null;
    }

    /**
     * Windows install locations, in resolution-priority order.
     *
     * Covers: npm-global (most Node-based CLIs), pip --user / pipx / cargo,
     * VS Code-style per-user installs, system Program Files, Scoop shims,
     * Chocolatey. Each base is probed with `.exe`, `.cmd`, `.bat`, and
     * extension-less so we catch every conventional Windows binary form.
     *
     * @param array<string,string> $env
     * @return string[]
     */
    protected static function windowsPathCandidates(string $binary, array $env): array
    {
        $home         = $env['HOME'] ?? '';
        $appdata      = $env['APPDATA']            ?? (getenv('APPDATA') ?: '');
        $localApp     = $env['LOCALAPPDATA']       ?? (getenv('LOCALAPPDATA') ?: '');
        $progFiles    = $env['ProgramFiles']       ?? (getenv('ProgramFiles') ?: '');
        $progFilesX86 = $env['ProgramFiles(x86)']  ?? (getenv('ProgramFiles(x86)') ?: '');

        $exts = ['.exe', '.cmd', '.bat', ''];

        $bases = array_filter([
            $appdata ? "{$appdata}/npm" : null,
            $home    ? "{$home}/.local/bin" : null,
            $home    ? "{$home}/.npm-global/bin" : null,
            $localApp ? "{$localApp}/Programs/{$binary}" : null,
            $progFiles ? "{$progFiles}/{$binary}" : null,
            $progFilesX86 ? "{$progFilesX86}/{$binary}" : null,
            $home    ? "{$home}/scoop/shims" : null,
            'C:/ProgramData/chocolatey/bin',
        ]);

        $candidates = [];
        foreach ($bases as $base) {
            foreach ($exts as $ext) {
                $candidates[] = "{$base}/{$binary}{$ext}";
            }
        }
        return $candidates;
    }

    /**
     * macOS install locations: Homebrew (Apple Silicon at /opt/homebrew,
     * Intel at /usr/local), MacPorts at /opt/local, npm-global / pip --user
     * under $HOME, plus nvm if `node -v` resolves an active version.
     *
     * @param array<string,string> $env
     * @return string[]
     */
    protected static function macPathCandidates(string $binary, array $env): array
    {
        $home = $env['HOME'] ?? '';
        $candidates = [];
        if ($home) {
            $candidates[] = "{$home}/.npm-global/bin/{$binary}";
            $candidates[] = "{$home}/.local/bin/{$binary}";
        }
        // Apple Silicon homebrew first — it's the modern default and shadows
        // the Intel path on M-series machines that still keep /usr/local.
        $candidates[] = "/opt/homebrew/bin/{$binary}";
        $candidates[] = "/usr/local/bin/{$binary}";
        $candidates[] = "/opt/local/bin/{$binary}"; // MacPorts
        $candidates[] = "/usr/bin/{$binary}";

        $nodeVer = static::safeProbeOutput('node -v', $env, 3);
        if ($home && $nodeVer) {
            $candidates[] = "{$home}/.nvm/versions/node/{$nodeVer}/bin/{$binary}";
        }
        return $candidates;
    }

    /**
     * Linux/BSD install locations: distro package paths, Snap, Linuxbrew,
     * npm-global / pip --user under $HOME, plus nvm if active.
     *
     * @param array<string,string> $env
     * @return string[]
     */
    protected static function linuxPathCandidates(string $binary, array $env): array
    {
        $home = $env['HOME'] ?: '/root';
        $candidates = [
            "{$home}/.npm-global/bin/{$binary}",
            "{$home}/.local/bin/{$binary}",
            "/usr/local/bin/{$binary}",
            "/usr/bin/{$binary}",
            "/snap/bin/{$binary}",
            "/home/linuxbrew/.linuxbrew/bin/{$binary}",
        ];

        $nodeVer = static::safeProbeOutput('node -v', $env, 3);
        if ($nodeVer) {
            $candidates[] = "{$home}/.nvm/versions/node/{$nodeVer}/bin/{$binary}";
        }
        return $candidates;
    }
}
