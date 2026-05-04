<?php

declare(strict_types=1);

namespace SuperAICore\Plugins;

/**
 * Outcome of `PluginInstaller::install()` for a single plugin.
 *
 * `unchanged` when the on-disk plugin dir's recursive sha matches the
 * source — re-running an install of the same version is a no-op.
 *
 * `user_edited` when the target dir exists, has files, and any file the
 * installer previously wrote has been modified by the user. The
 * installer refuses to overwrite without `--force` — same drift contract
 * as the hooks fanout.
 */
final class InstallResult
{
    public const STATUS_INSTALLED   = 'installed';
    public const STATUS_UPDATED     = 'updated';
    public const STATUS_UNCHANGED   = 'unchanged';
    public const STATUS_USER_EDITED = 'user_edited';
    public const STATUS_REMOVED     = 'removed';
    public const STATUS_FAILED      = 'failed';

    public function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly string $targetDir,
        public readonly ?string $error = null,
        public readonly int $filesCopied = 0,
        public readonly ?PluginManifest $manifest = null,
    ) {}

    public function ok(): bool
    {
        return $this->status !== self::STATUS_FAILED && $this->status !== self::STATUS_USER_EDITED;
    }
}
