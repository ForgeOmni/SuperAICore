<?php

declare(strict_types=1);

namespace SuperAICore\SmartFlow;

use Symfony\Component\Yaml\Yaml;

/**
 * Discovers runnable flows. Built-in cross-CLI flows ship as
 * `resources/flows/*.yaml`; users can drop their own under `./flows` or
 * `./.superaicore/flows`. PHP flows can be registered directly. The registry is
 * lazy: listing only peeks at name/description; a flow is compiled by
 * {@see YamlFlowLoader} when {@see get()} is called.
 */
final class FlowRegistry
{
    /** @var array<string, string> name => yaml file path */
    private array $files = [];

    /** @var array<string, FlowDefinition> name => php-registered definition */
    private array $php = [];

    /** @var list<string> */
    private array $dirs;

    private YamlFlowLoader $loader;

    /**
     * @param list<string>|null $dirs
     */
    public function __construct(?array $dirs = null, ?YamlFlowLoader $loader = null)
    {
        $this->loader = $loader ?? new YamlFlowLoader();
        $this->dirs = $dirs ?? self::defaultDirs();
        $this->scan();
    }

    public function register(FlowDefinition $definition): void
    {
        $this->php[$definition->name] = $definition;
    }

    public function has(string $name): bool
    {
        return isset($this->php[$name]) || isset($this->files[$name]);
    }

    public function get(string $name): ?FlowDefinition
    {
        if (isset($this->php[$name])) {
            return $this->php[$name];
        }
        if (isset($this->files[$name])) {
            return $this->loader->loadFile($this->files[$name]);
        }
        return null;
    }

    /**
     * @return array<string, array{name: string, description: string, source: ?string, phases: int}>
     */
    public function list(): array
    {
        $out = [];
        foreach ($this->php as $name => $def) {
            $out[$name] = [
                'name' => $name,
                'description' => $def->description,
                'source' => 'php',
                'phases' => count($def->phases),
            ];
        }
        foreach ($this->files as $name => $path) {
            if (isset($out[$name])) {
                continue;
            }
            $meta = $this->peek($path);
            $out[$name] = [
                'name' => $name,
                'description' => $meta['description'] ?? '',
                'source' => $path,
                'phases' => $meta['phases'] ?? 0,
            ];
        }
        ksort($out);
        return $out;
    }

    /** @return list<string> */
    public function dirs(): array
    {
        return $this->dirs;
    }

    private function scan(): void
    {
        foreach ($this->dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (glob(rtrim($dir, '/\\') . '/*.yaml') ?: [] as $path) {
                $name = $this->peek($path)['name'] ?? pathinfo($path, PATHINFO_FILENAME);
                // First win: built-in dirs are scanned first, so user dirs only
                // add new names (built-ins are not overridden silently here).
                if (!isset($this->files[$name])) {
                    $this->files[$name] = $path;
                }
            }
        }
    }

    /**
     * @return array{name?: string, description?: string, phases?: int}
     */
    private function peek(string $path): array
    {
        try {
            $parsed = Yaml::parseFile($path);
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($parsed)) {
            return [];
        }
        return [
            'name' => (string) ($parsed['name'] ?? pathinfo($path, PATHINFO_FILENAME)),
            'description' => (string) ($parsed['description'] ?? ''),
            'phases' => is_array($parsed['phases'] ?? null) ? count($parsed['phases']) : 0,
        ];
    }

    /** @return list<string> */
    public static function defaultDirs(): array
    {
        $dirs = [];
        // Built-in flows shipped with the package.
        $builtin = dirname(__DIR__, 2) . '/resources/flows';
        if (is_dir($builtin)) {
            $dirs[] = $builtin;
        }
        // Project-local user flows.
        $cwd = getcwd() ?: '.';
        foreach (['/.superaicore/flows', '/flows'] as $rel) {
            if (is_dir($cwd . $rel)) {
                $dirs[] = $cwd . $rel;
            }
        }
        // Config override (single dir or list).
        $cfg = Cfg::get('super-ai-core.smartflow.flows_dir');
        foreach (is_array($cfg) ? $cfg : (is_string($cfg) ? [$cfg] : []) as $d) {
            if (is_string($d) && is_dir($d)) {
                $dirs[] = $d;
            }
        }
        return $dirs;
    }
}
