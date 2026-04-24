<?php

namespace SuperAICore\Backends\Concerns;

use SuperAICore\Services\CapabilityRegistry;
use SuperAICore\Support\CliBinaryLocator;
use Symfony\Component\Process\Process;

/**
 * Shared wrapper-script pattern for `ScriptedSpawnBackend`
 * implementations.
 *
 * Writes a sh (or .bat on Windows) file that `cd`s to the project
 * root, optionally `unset`s parent-session env markers, and either
 * pipes the prompt file through the CLI on stdin (`stdinMode: 'pipe'`)
 * or closes stdin (`stdinMode: 'devnull'`) for CLIs that take the
 * prompt as an argv argument.
 *
 * Wraps the script in a `Symfony\Component\Process\Process`
 * pre-configured with env, timeout, and idle timeout. The capability
 * transform (`BackendCapabilities::transformPrompt`) runs in-place on
 * the prompt file before spawn.
 */
trait BuildsScriptedProcess
{
    /**
     * Core assembly — backend subclasses call this after composing their
     * own argv flags.
     *
     * @param  string  $engineKey          One of `AiProvider::BACKEND_*`.
     * @param  string  $promptFile         Full path; read from stdin when
     *                                     `$stdinMode === 'pipe'`, otherwise
     *                                     consumed elsewhere by the caller.
     * @param  string  $logFile            Full path; combined stdout+stderr.
     * @param  string  $projectRoot        cwd for the wrapper script.
     * @param  string  $cliFlagsString     Already-escaped argv string.
     * @param  array<string,string|false>  $env
     * @param  string[]                    $envUnsetExtras  Env vars to
     *                                     `unset`/`set=` inside the wrapper
     *                                     script, on top of `$env` removals.
     * @param  int|null  $timeout          Hard wall-clock cap (default 7200).
     * @param  int|null  $idleTimeout      Idle cap (default 1800).
     * @param  string  $stdinMode          `'pipe'` → `cat $promptFile | …`;
     *                                     `'devnull'` → `… </dev/null` (nix)
     *                                     or no stdin (win). Used by engines
     *                                     that take the prompt via argv
     *                                     (Copilot `-p`, Kimi `--prompt`,
     *                                     Kiro positional).
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
        string $stdinMode = 'pipe',
    ): Process {
        $this->applyCapabilityTransform($engineKey, $promptFile);

        $cliPath = app(CliBinaryLocator::class)->find($engineKey);
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        if ($isWindows) {
            $cliPathWin     = str_replace('/', '\\', $cliPath);
            $logFileWin     = str_replace('/', '\\', $logFile);
            $projectRootWin = str_replace('/', '\\', $projectRoot);

            $unsetLines = '';
            foreach ($envUnsetExtras as $var) {
                $unsetLines .= "set {$var}=\r\n";
            }

            if ($stdinMode === 'pipe') {
                $promptFileWin = str_replace('/', '\\', $promptFile);
                $shellCmd = "type \"{$promptFileWin}\" | \"{$cliPathWin}\" {$cliFlagsString} > \"{$logFileWin}\" 2>&1";
            } else {
                $shellCmd = "\"{$cliPathWin}\" {$cliFlagsString} > \"{$logFileWin}\" 2>&1";
            }
            $execScript = str_replace('.log', '-exec.bat', $logFile);
            file_put_contents($execScript, "@echo off\r\n{$unsetLines}cd /D \"{$projectRootWin}\"\r\n{$shellCmd}\r\n");
            $process = new Process(['cmd', '/C', $execScript], $projectRoot);
        } else {
            $unsetLine = $envUnsetExtras
                ? 'unset ' . implode(' ', $envUnsetExtras) . "\n"
                : '';

            if ($stdinMode === 'pipe') {
                $shellCmd = "cat \"{$promptFile}\" | \"{$cliPath}\" {$cliFlagsString} > \"{$logFile}\" 2>&1";
            } else {
                $shellCmd = "\"{$cliPath}\" {$cliFlagsString} </dev/null > \"{$logFile}\" 2>&1";
            }
            $execScript = str_replace('.log', '-exec.sh', $logFile);
            file_put_contents($execScript, "#!/bin/sh\n{$unsetLine}cd \"{$projectRoot}\"\n{$shellCmd}\n");
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
     * adapter. Short-circuits when the capability has an empty
     * `toolNameMap` (Claude / SuperAgent) — saves a MB-scale read+write
     * per spawn on large pipeline prompts.
     *
     * Host integrations that need skill-body-level translation (e.g.
     * SuperTeam's SkillBodyTranslator) run their translator BEFORE
     * `prepareScriptedProcess()`; this method only runs the capability's
     * own `transformPrompt()` on the file body.
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

        // Fast path: capabilities with no tool-name rewrites (Claude,
        // SuperAgent) can't change the prompt — skip the I/O entirely.
        if (method_exists($cap, 'toolNameMap') && $cap->toolNameMap() === []) {
            return;
        }

        $existing = (string) @file_get_contents($promptFile);
        $transformed = $cap->transformPrompt($existing);

        if ($transformed !== $existing) {
            @file_put_contents($promptFile, $transformed);
        }
    }

    /**
     * Escape a flag list for shell insertion.
     *
     * @param  string[]  $flags
     */
    protected function escapeFlags(array $flags): string
    {
        return implode(' ', array_map(static fn ($p) => escapeshellarg((string) $p), $flags));
    }

    /**
     * Strip ANSI escape sequences from a stdout chunk — CLI tools with
     * color / cursor / title-bar codes even when their output is a
     * pipe (Copilot, Kiro). Covers CSI (`\x1B[…`), OSC (`\x1B]…BEL`),
     * designating character sets (`\x1B(X`), and bare ESC.
     */
    protected function stripAnsi(string $data): string
    {
        $data = preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $data) ?? $data;
        $data = preg_replace('/\x1B\].*?(\x07|\x1B\\\\)/s', '', $data) ?? $data;
        $data = preg_replace('/\x1B[()][0-9A-Z]/', '', $data) ?? $data;
        return str_replace("\x1B", '', $data);
    }

    /**
     * Throw a descriptive RuntimeException when a chat subprocess exited
     * non-zero without producing any output — shared across every
     * `streamChat()` implementation.
     */
    protected function assertChatExit(Process $process, string $response, string $engineLabel): void
    {
        if ($process->getExitCode() !== 0 && $response === '') {
            $stderr = $process->getErrorOutput();
            if (isset($this->logger) && $this->logger) {
                $this->logger->error("{$engineLabel} chat failed (exit {$process->getExitCode()}): {$stderr}");
            }
            throw new \RuntimeException("{$engineLabel} chat failed (exit {$process->getExitCode()})");
        }
    }
}
