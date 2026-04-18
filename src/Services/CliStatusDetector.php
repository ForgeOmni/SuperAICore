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

        $versionProcess = Process::fromShellCommandline("\"{$path}\" --version 2>/dev/null");
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

    protected static function detectAuth(string $binary, string $path): ?array
    {
        if ($binary === 'claude') {
            $p = Process::fromShellCommandline("\"{$path}\" auth status 2>/dev/null");
            $p->setTimeout(5);
            $p->run();
            $out = trim($p->getOutput());
            $decoded = json_decode($out, true);
            return is_array($decoded) ? $decoded : null;
        }
        if ($binary === 'codex') {
            $p = Process::fromShellCommandline("\"{$path}\" login status 2>&1");
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
            $envToken = getenv('COPILOT_GITHUB_TOKEN') ?: getenv('GH_TOKEN') ?: getenv('GITHUB_TOKEN');
            $configDir = (getenv('XDG_CONFIG_HOME') ?: ((getenv('HOME') ?: '') . '/.config')) . '/copilot';
            $homeDir   = (getenv('HOME') ?: '') . '/.copilot';
            $hasState  = is_dir($configDir) || is_dir($homeDir);
            return [
                'loggedIn' => (bool) $envToken || $hasState,
                'status'   => $envToken ? 'env-token' : ($hasState ? 'config-present' : 'not-logged-in'),
                'method'   => $envToken ? 'env' : ($hasState ? 'oauth' : null),
            ];
        }
        return null;
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
        if (PHP_OS_FAMILY === 'Windows') {
            $appdata = getenv('APPDATA');
            $candidates = [];
            if ($appdata) {
                $candidates[] = "{$appdata}/npm/{$binary}.cmd";
                $candidates[] = "{$appdata}/npm/{$binary}";
            }
        } else {
            $home = getenv('HOME') ?: '/root';
            $candidates = [
                "{$home}/.npm-global/bin/{$binary}",
                "{$home}/.local/bin/{$binary}",
                "/usr/local/bin/{$binary}",
                "/usr/bin/{$binary}",
                "/opt/homebrew/bin/{$binary}",
            ];
            $nodeVerP = Process::fromShellCommandline('node -v 2>/dev/null');
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
        $p = Process::fromShellCommandline($cmd);
        $p->setTimeout(3);
        $p->run();
        $result = trim($p->getOutput());
        if ($result) {
            return explode("\n", $result)[0] ?: null;
        }
        return null;
    }
}
