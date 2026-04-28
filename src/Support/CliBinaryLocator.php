<?php

namespace SuperAICore\Support;

use SuperAICore\Services\EngineCatalog;

/**
 * Filesystem probe for CLI engine binaries.
 *
 * Moved from host-side `ClaudeRunner::findCliPath()` so every backend
 * (claude/codex/gemini/copilot/kiro/kimi/future) resolves its install
 * location the same way. Binary name comes from `EngineCatalog->cliBinary`
 * — no host switch statement needed.
 *
 * Probe order on Linux/BSD:
 *   1. $HOME/.npm-global/bin/<binary>
 *   2. $HOME/.local/bin/<binary>
 *   3. /usr/local/bin/<binary>
 *   4. /usr/bin/<binary>
 *   5. /snap/bin/<binary>
 *   6. /home/linuxbrew/.linuxbrew/bin/<binary>
 *   7. $HOME/.nvm/versions/node/<active>/bin/<binary>
 *
 * macOS adds /opt/homebrew/bin (Apple Silicon) before /usr/local/bin
 * (Intel) and includes /opt/local/bin (MacPorts).
 *
 * Windows probes %APPDATA%/npm, $HOME/.local/bin, $HOME/.npm-global/bin,
 * %LOCALAPPDATA%/Programs/<binary>, %ProgramFiles%/<binary>, Scoop, and
 * Chocolatey — each base × {.exe, .cmd, .bat, ''} extension form.
 *
 * Falls back to the binary's bare name (e.g. `"claude"`) when nothing
 * is found — `PATH` resolution still gets a chance at exec time.
 */
class CliBinaryLocator
{
    /** @var array<string,string> */
    protected array $cache = [];

    public function __construct(protected EngineCatalog $catalog) {}

    /**
     * Full path to the engine's binary, or its bare name when unresolvable.
     * Cached in-memory for the process lifetime — a single spawn typically
     * resolves 2-3 times (host dispatch + backend trait call) and each
     * uncached call walks 5-6 `file_exists` probes plus shell-execs
     * `node -v` (~20-40ms cold on NVM-managed installs).
     */
    public function find(string $engineKey): string
    {
        if (isset($this->cache[$engineKey])) {
            return $this->cache[$engineKey];
        }

        $engine = $this->catalog->get($engineKey);
        $binary = $engine?->cliBinary ?: $engineKey;

        $paths = match (PHP_OS_FAMILY) {
            'Windows' => $this->windowsCandidates($binary),
            'Darwin'  => $this->macCandidates($binary),
            default   => $this->linuxCandidates($binary),
        };

        foreach ($paths as $path) {
            if ($path && file_exists($path)) {
                return $this->cache[$engineKey] = $path;
            }
        }

        return $this->cache[$engineKey] = $binary;
    }

    /**
     * Drop cached resolutions — useful in tests and after on-the-fly
     * CLI install/uninstall flows.
     */
    public function forget(?string $engineKey = null): void
    {
        if ($engineKey === null) {
            $this->cache = [];
            return;
        }
        unset($this->cache[$engineKey]);
    }

    /** @return string[] */
    protected function windowsCandidates(string $binary): array
    {
        $home         = getenv('USERPROFILE') ?: getenv('HOME') ?: '';
        $appdata      = getenv('APPDATA') ?: '';
        $localApp     = getenv('LOCALAPPDATA') ?: '';
        $progFiles    = getenv('ProgramFiles') ?: '';
        $progFilesX86 = getenv('ProgramFiles(x86)') ?: '';

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

    /** @return string[] */
    protected function macCandidates(string $binary): array
    {
        $home = getenv('HOME') ?: '';
        $candidates = [];
        if ($home) {
            $candidates[] = "{$home}/.npm-global/bin/{$binary}";
            $candidates[] = "{$home}/.local/bin/{$binary}";
        }
        $candidates[] = "/opt/homebrew/bin/{$binary}";
        $candidates[] = "/usr/local/bin/{$binary}";
        $candidates[] = "/opt/local/bin/{$binary}";
        $candidates[] = "/usr/bin/{$binary}";

        $nodeVer = trim((string) @shell_exec('node -v 2>/dev/null'));
        if ($home && $nodeVer) {
            $candidates[] = "{$home}/.nvm/versions/node/{$nodeVer}/bin/{$binary}";
        }
        return $candidates;
    }

    /** @return string[] */
    protected function linuxCandidates(string $binary): array
    {
        $home = getenv('HOME') ?: '/root';
        $candidates = [
            "{$home}/.npm-global/bin/{$binary}",
            "{$home}/.local/bin/{$binary}",
            "/usr/local/bin/{$binary}",
            "/usr/bin/{$binary}",
            "/snap/bin/{$binary}",
            "/home/linuxbrew/.linuxbrew/bin/{$binary}",
        ];
        $nodeVer = trim((string) @shell_exec('node -v 2>/dev/null'));
        if ($nodeVer) {
            $candidates[] = "{$home}/.nvm/versions/node/{$nodeVer}/bin/{$binary}";
        }
        return $candidates;
    }
}
