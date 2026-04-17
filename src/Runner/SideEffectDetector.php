<?php

namespace SuperAICore\Runner;

/**
 * Best-effort detection of filesystem mutations caused by a skill run.
 *
 * Used by `FallbackChain` to enforce D15 "lock on side-effect": once the
 * current backend has written anything we can observe, we stop walking
 * the chain — regardless of whether the run succeeded — to avoid
 * double-writes from a subsequent hop.
 *
 * Two signals, both best-effort:
 *   1. Mutation mtime-diff: snapshot the cwd's file mtimes before the
 *      run; diff after. Scoped to cwd, skips heavy/irrelevant dirs
 *      (`.git`, `vendor`, `node_modules`, `.phpunit.cache`, `.idea`,
 *      `.claude`), capped at $maxFiles entries to bound the cost on
 *      monorepo-sized trees.
 *   2. stream-json grep: if the raw buffered output looks like it
 *      contains `"type":"tool_use"` events for file-mutating tools,
 *      report them as reasons. Tolerant of backends that emit plain
 *      text (no false positives — the regex is specific enough).
 */
final class SideEffectDetector
{
    private const SKIP_DIRS = ['.git', 'vendor', 'node_modules', '.phpunit.cache', '.idea', '.claude', 'storage', 'bootstrap/cache'];

    /** Canonical + gemini + codex mutating tool names. */
    private const WRITE_TOOLS = [
        'Write', 'Edit', 'Bash', 'NotebookEdit',
        'write_file', 'replace', 'run_shell_command',
        'apply_patch',
    ];

    /** @var array<string,int> absolute path => mtime */
    private array $snapshot = [];

    public function __construct(
        private readonly string $cwd,
        private readonly int $maxFiles = 10000,
    ) {}

    public function snapshotBefore(): void
    {
        $this->snapshot = $this->takeMtimes();
    }

    /**
     * @return array{detected:bool, reasons:string[]}
     */
    public function detectAfter(string $rawOutput = ''): array
    {
        $reasons = [];

        foreach (self::WRITE_TOOLS as $tool) {
            $quoted = preg_quote($tool, '/');
            if (preg_match('/"type"\s*:\s*"tool_use"[^\n]*"name"\s*:\s*"' . $quoted . '"/', $rawOutput)) {
                $reasons[] = "stream-json tool_use: {$tool}";
            }
        }

        $after = $this->takeMtimes();

        foreach ($after as $path => $mtime) {
            if (!array_key_exists($path, $this->snapshot)) {
                $reasons[] = 'created: ' . $this->rel($path);
            } elseif ($this->snapshot[$path] !== $mtime) {
                $reasons[] = 'modified: ' . $this->rel($path);
            }
        }
        foreach ($this->snapshot as $path => $_mtime) {
            if (!array_key_exists($path, $after)) {
                $reasons[] = 'deleted: ' . $this->rel($path);
            }
        }

        $reasons = array_values(array_unique($reasons));
        $capped = array_slice($reasons, 0, 5);
        if (count($reasons) > 5) {
            $capped[] = sprintf('… and %d more', count($reasons) - 5);
        }

        return ['detected' => !empty($reasons), 'reasons' => $capped];
    }

    /** @return array<string,int> */
    private function takeMtimes(): array
    {
        if (!is_dir($this->cwd)) {
            return [];
        }
        $out = [];
        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($this->cwd, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
                    function (\SplFileInfo $current): bool {
                        if ($current->isDir() && in_array($current->getFilename(), self::SKIP_DIRS, true)) {
                            return false;
                        }
                        return true;
                    }
                ),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($it as $info) {
                if ($info->isFile()) {
                    $out[$info->getPathname()] = $info->getMTime();
                    if (count($out) >= $this->maxFiles) {
                        break;
                    }
                }
            }
        } catch (\Throwable) {
            // Best-effort — unreadable subtrees are fine; detection just narrows.
        }
        return $out;
    }

    private function rel(string $path): string
    {
        $prefix = rtrim($this->cwd, '/') . '/';
        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }
}
