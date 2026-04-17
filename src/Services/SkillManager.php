<?php

namespace SuperAICore\Services;

/**
 * Cross-backend Skill synchronization.
 *
 * Host apps author skills once (e.g. SuperTeam's `.claude/skills/<name>/SKILL.md`);
 * this service mirrors them into each backend CLI's skill directory so
 * `codex skills list` and `gemini skills list` discover the same set.
 *
 * Uses symlinks on Unix (zero-copy, edits propagate instantly); falls back
 * to file copy on Windows or when symlink creation fails.
 *
 * Backends:
 *   - claude:  ~/.claude/skills/       (user) or `.claude/skills/` (project)
 *   - codex:   ~/.codex/skills/
 *   - gemini:  ~/.gemini/skills/
 *
 * The convention we adopt is that skills may be prefixed per host
 * (e.g. `super-team-<name>`) in the target dirs so multiple host apps
 * installed on the same machine don't clobber each other.
 */
class SkillManager
{
    /**
     * Sync every skill under $sourceDir into each backend's skills dir.
     *
     * @param  string  $sourceDir  e.g. /abs/path/.claude/skills
     * @param  array   $backends   subset of ['claude','codex','gemini']
     * @param  string  $prefix     filename prefix in target dirs (distinguishes host apps)
     * @return array{backend:string,synced:int,skipped:int,errors:array}[]
     */
    public static function sync(
        string $sourceDir,
        array $backends = ['codex', 'gemini'],
        string $prefix = ''
    ): array {
        $report = [];
        if (!is_dir($sourceDir)) {
            foreach ($backends as $b) {
                $report[] = ['backend' => $b, 'synced' => 0, 'skipped' => 0, 'errors' => ["source dir not found: {$sourceDir}"]];
            }
            return $report;
        }

        $skills = self::listSkills($sourceDir);

        foreach ($backends as $backend) {
            $targetDir = self::targetDirFor($backend);
            if (!$targetDir) {
                $report[] = ['backend' => $backend, 'synced' => 0, 'skipped' => 0, 'errors' => ["no skill dir known for backend {$backend}"]];
                continue;
            }

            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0755, true);
            }

            $synced = 0;
            $skipped = 0;
            $errors = [];
            foreach ($skills as $name) {
                $src = $sourceDir . DIRECTORY_SEPARATOR . $name;
                $dst = $targetDir . DIRECTORY_SEPARATOR . ($prefix ? $prefix . $name : $name);

                if (self::isAlreadyLinked($src, $dst)) {
                    $skipped++;
                    continue;
                }

                if (is_link($dst) || file_exists($dst)) {
                    self::removePath($dst);
                }

                if (self::canSymlink()) {
                    if (@symlink($src, $dst)) {
                        $synced++;
                        continue;
                    }
                    $errors[] = "symlink failed: {$name}";
                }

                // Fallback: recursive copy
                if (self::copyRecursive($src, $dst)) {
                    $synced++;
                } else {
                    $errors[] = "copy failed: {$name}";
                }
            }

            $report[] = compact('backend') + ['target' => $targetDir, 'synced' => $synced, 'skipped' => $skipped, 'errors' => $errors];
        }

        return $report;
    }

    /** Absolute path of `~/.<backend>/skills` or equivalent. */
    public static function targetDirFor(string $backend): ?string
    {
        $home = self::homeDir();
        if (!$home) return null;
        return match ($backend) {
            'codex'  => $home . DIRECTORY_SEPARATOR . '.codex'  . DIRECTORY_SEPARATOR . 'skills',
            'gemini' => $home . DIRECTORY_SEPARATOR . '.gemini' . DIRECTORY_SEPARATOR . 'skills',
            'claude' => $home . DIRECTORY_SEPARATOR . '.claude' . DIRECTORY_SEPARATOR . 'skills',
            default  => null,
        };
    }

    /** List skill directories under $sourceDir (each skill = one subdir). */
    protected static function listSkills(string $sourceDir): array
    {
        $skills = [];
        foreach (scandir($sourceDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $sourceDir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) $skills[] = $entry;
        }
        sort($skills);
        return $skills;
    }

    protected static function isAlreadyLinked(string $src, string $dst): bool
    {
        if (!is_link($dst)) return false;
        $target = readlink($dst);
        if ($target === false) return false;
        return rtrim($target, DIRECTORY_SEPARATOR) === rtrim($src, DIRECTORY_SEPARATOR);
    }

    protected static function canSymlink(): bool
    {
        if (PHP_OS_FAMILY === 'Windows') return false;
        return function_exists('symlink');
    }

    protected static function removePath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                self::removePath($path . DIRECTORY_SEPARATOR . $entry);
            }
            @rmdir($path);
        }
    }

    protected static function copyRecursive(string $src, string $dst): bool
    {
        if (is_file($src)) {
            return @copy($src, $dst);
        }
        if (!is_dir($src)) return false;
        if (!is_dir($dst)) @mkdir($dst, 0755, true);
        $ok = true;
        foreach (scandir($src) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $ok = self::copyRecursive($src . DIRECTORY_SEPARATOR . $entry, $dst . DIRECTORY_SEPARATOR . $entry) && $ok;
        }
        return $ok;
    }

    protected static function homeDir(): string
    {
        $home = getenv('HOME') ?: '';
        if ($home) return rtrim($home, DIRECTORY_SEPARATOR);
        if (PHP_OS_FAMILY === 'Windows') {
            $profile = getenv('USERPROFILE');
            if ($profile) return rtrim($profile, DIRECTORY_SEPARATOR);
        }
        return '';
    }
}
