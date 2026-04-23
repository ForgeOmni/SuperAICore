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
 * Probe order on *nix (matches host's historical order):
 *   1. $HOME/.npm-global/bin/<binary>
 *   2. $HOME/.local/bin/<binary>
 *   3. /usr/local/bin/<binary>
 *   4. /usr/bin/<binary>
 *   5. /opt/homebrew/bin/<binary>
 *   6. $HOME/.nvm/versions/node/<active-node-version>/bin/<binary>
 *
 * On Windows: $APPDATA/npm/<binary>(.cmd), $LOCALAPPDATA/npm/<binary>.cmd.
 *
 * Falls back to the binary's bare name (e.g. `"claude"`) when nothing
 * is found — `PATH` resolution still gets a chance at exec time.
 */
class CliBinaryLocator
{
    public function __construct(protected EngineCatalog $catalog) {}

    /**
     * Full path to the engine's binary, or its bare name when unresolvable.
     */
    public function find(string $engineKey): string
    {
        $engine = $this->catalog->get($engineKey);
        $binary = $engine?->cliBinary ?: $engineKey;

        $paths = [];
        if ($this->isWindows()) {
            $appdata = getenv('APPDATA');
            if ($appdata) {
                $paths[] = "{$appdata}/npm/{$binary}.cmd";
                $paths[] = "{$appdata}/npm/{$binary}";
            }
            $localAppData = getenv('LOCALAPPDATA');
            if ($localAppData) {
                $paths[] = "{$localAppData}/npm/{$binary}.cmd";
            }
        } else {
            $home = getenv('HOME') ?: '/root';
            $paths = [
                "{$home}/.npm-global/bin/{$binary}",
                "{$home}/.local/bin/{$binary}",
                '/usr/local/bin/' . $binary,
                '/usr/bin/' . $binary,
                '/opt/homebrew/bin/' . $binary,
            ];
            $nodeVer = trim((string) @shell_exec('node -v 2>/dev/null'));
            if ($nodeVer) {
                $paths[] = "{$home}/.nvm/versions/node/{$nodeVer}/bin/{$binary}";
            }
        }

        foreach ($paths as $path) {
            if ($path && file_exists($path)) {
                return $path;
            }
        }

        return $binary;
    }

    protected function isWindows(): bool
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }
}
