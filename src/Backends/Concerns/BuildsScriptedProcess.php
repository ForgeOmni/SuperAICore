<?php

namespace SuperAICore\Backends\Concerns;

use SuperAICore\Services\CapabilityRegistry;
use SuperAICore\Support\CliBinaryLocator;
use Symfony\Component\Process\Process;

/**
 * Shared helpers for `ScriptedSpawnBackend` implementations.
 *
 * Hands back the wrapper-script pattern the host previously duplicated
 * across `buildClaudeProcess` / `buildCodexProcess` / `buildGeminiProcess`:
 *   - Write a sh (or .bat on Windows) file that `cd`s to the project
 *     root, optionally `unset`s parent-session env markers, and pipes
 *     the prompt file through the CLI binary with the given flags.
 *   - Wrap it in a `Symfony\Component\Process\Process` pre-configured
 *     with env, timeout, and idle timeout.
 *
 * Capability transform (`BackendCapabilities::transformPrompt`) is
 * applied to the prompt file in-place before spawn.
 */
trait BuildsScriptedProcess
{
    /**
     * Core assembly — backend subclasses call this after composing their
     * own argv flags.
     *
     * @param  string  $engineKey          One of EngineCatalog::keys().
     * @param  string  $promptFile         Full path; read from stdin.
     * @param  string  $logFile            Full path; combined stdout+stderr.
     * @param  string  $projectRoot        cwd for the wrapper script.
     * @param  string  $cliFlagsString     Already-escaped argv string
     *                                     (everything after the binary name,
     *                                     before the stdin pipe).
     * @param  array<string,string|false>  $env
     * @param  string[]                    $envUnsetExtras  Env var names
     *                                     to `unset` (*nix) / `set =` (win)
     *                                     inside the wrapper script, on top
     *                                     of whatever `$env` already removes.
     * @param  int|null  $timeout          Hard wall-clock cap (default 7200).
     * @param  int|null  $idleTimeout      Idle cap (default 1800).
     */
    protected function buildWrappedProcess(
        string $engineKey,
        string $promptFile,
        string $logFile,
        string $projectRoot,
        string $cliFlagsString,
        array $env = [],
        array $envUnsetExtras = [],
        ?int $timeout = null,
        ?int $idleTimeout = null,
    ): Process {
        $this->applyCapabilityTransform($engineKey, $promptFile);

        $cliPath = app(CliBinaryLocator::class)->find($engineKey);
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        if ($isWindows) {
            $promptFileWin = str_replace('/', '\\', $promptFile);
            $cliPathWin    = str_replace('/', '\\', $cliPath);
            $logFileWin    = str_replace('/', '\\', $logFile);
            $projectRootWin = str_replace('/', '\\', $projectRoot);

            $unsetLines = '';
            foreach ($envUnsetExtras as $var) {
                $unsetLines .= "set {$var}=\r\n";
            }

            $shellCmd = "type \"{$promptFileWin}\" | \"{$cliPathWin}\" {$cliFlagsString} > \"{$logFileWin}\" 2>&1";
            $execScript = str_replace('.log', '-exec.bat', $logFile);
            $scriptContent = "@echo off\r\n{$unsetLines}cd /D \"{$projectRootWin}\"\r\n{$shellCmd}\r\n";
            file_put_contents($execScript, $scriptContent);
            $process = new Process(['cmd', '/C', $execScript], $projectRoot);
        } else {
            $unsetLine = '';
            if ($envUnsetExtras) {
                $unsetLine = 'unset ' . implode(' ', $envUnsetExtras) . "\n";
            }
            $shellCmd = "cat \"{$promptFile}\" | \"{$cliPath}\" {$cliFlagsString} > \"{$logFile}\" 2>&1";
            $execScript = str_replace('.log', '-exec.sh', $logFile);
            $scriptContent = "#!/bin/sh\n{$unsetLine}cd \"{$projectRoot}\"\n{$shellCmd}\n";
            file_put_contents($execScript, $scriptContent);
            chmod($execScript, 0755);
            $process = new Process(['sh', $execScript], $projectRoot);
        }

        if ($env) {
            $process->setEnv($env);
        }
        $process->setTimeout($timeout ?? 7200);
        $process->setIdleTimeout($idleTimeout ?? 1800);

        return $process;
    }

    /**
     * Rewrite the prompt file in-place via the backend's capability
     * adapter. No-op for backends whose capabilities return the prompt
     * unchanged. Safely swallows missing capability / unreadable files.
     *
     * Host integrations that need skill-body-level translation (e.g.
     * SuperTeam's SkillBodyTranslator wrapping the raw transform) should
     * run their translator BEFORE calling `prepareScriptedProcess()` —
     * this method only runs the capability's own `transformPrompt()`
     * on the full file body.
     */
    protected function applyCapabilityTransform(string $engineKey, string $promptFile): void
    {
        if (!is_file($promptFile) || !is_writable($promptFile)) return;
        if (!class_exists(CapabilityRegistry::class)) return;

        try {
            $cap = app(CapabilityRegistry::class)->for($engineKey);
        } catch (\Throwable) {
            return;
        }

        $existing = (string) @file_get_contents($promptFile);
        $transformed = $cap->transformPrompt($existing);

        if ($transformed !== $existing) {
            @file_put_contents($promptFile, $transformed);
        }
    }

    /**
     * Escape a flag list for shell insertion — matches host's prior
     * `array_map(fn($p) => escapeshellarg($p), $flags)` pattern.
     *
     * @param  string[]  $flags
     */
    protected function escapeFlags(array $flags): string
    {
        return implode(' ', array_map(static fn ($p) => escapeshellarg((string) $p), $flags));
    }
}
