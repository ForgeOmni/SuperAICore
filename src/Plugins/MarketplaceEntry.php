<?php

declare(strict_types=1);

namespace SuperAICore\Plugins;

/**
 * One row from a marketplace.json `plugins[]` array. Wire format
 * identical to `SuperAgent\Plugins\MarketplaceEntry`.
 *
 * `source` is stored verbatim (typically `./plugins/<name>` for monorepo-
 * style marketplaces). `resolvedPath()` joins it against the marketplace
 * root so callers can iterate the plugin tree directly.
 */
final class MarketplaceEntry
{
    public function __construct(
        public readonly string $name,
        public readonly string $source,
        public readonly ?string $description,
        public readonly string $rootDir,
    ) {}

    /**
     * Absolute path to the plugin directory if the source is a relative
     * filesystem path. Returns the source verbatim for non-path sources
     * (git URLs, etc.) so callers can detect and route differently.
     */
    public function resolvedPath(): string
    {
        $src = $this->source;
        if (preg_match('#^[a-z]+://#i', $src) === 1) {
            return $src;
        }
        if (preg_match('#^([a-zA-Z]:|/)#', $src) === 1) {
            return $src;
        }
        $joined = rtrim($this->rootDir, '/\\') . DIRECTORY_SEPARATOR . ltrim($src, '/\\');
        $real = realpath($joined);
        return $real !== false ? $real : $joined;
    }
}
