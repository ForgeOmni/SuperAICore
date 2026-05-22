<?php

declare(strict_types=1);

namespace SuperAICore\Services;

/**
 * SuperTeam agent catalog.
 *
 * Reads `.claude/agents/*.md` files (Claude Code SubagentRegistry
 * format) from one or more roots, parses YAML frontmatter, and
 * classifies each agent into a layer (Strategy / Product / Engineering /
 * ...) based on a prefix-rule table.
 *
 * Pure read-side service — no DB, no caching beyond per-instance.
 * Hosts that want long-lived caching wrap this in a memoizer.
 */
final class AgentCatalog
{
    /**
     * Prefix → category mapping. Most-specific prefixes win (longest
     * match), so e.g. `re-` (real estate) doesn't collide with `re-`
     * for "real" anything else.
     *
     * Categories track SuperTeam CLAUDE.md "Team Structure" §.
     */
    private const PREFIX_CATEGORIES = [
        // Strategy / leadership
        'ceo-'                     => 'Strategy',
        'cto-'                     => 'Strategy',
        'cfo-'                     => 'Strategy',
        'design-director-'         => 'Strategy',
        'brand-strategist-'        => 'Strategy',
        'positioning-'             => 'Strategy',
        'pricing-'                 => 'Strategy',

        // Product / design
        'ba-'                      => 'Product',
        'product-'                 => 'Product',
        'ui-'                      => 'Product',
        'interaction-'             => 'Product',
        'ux-'                      => 'Product',

        // Engineering
        'fullstack-'               => 'Engineering',
        'qa-'                      => 'Engineering',
        'debugger-'                => 'Engineering',
        'release-'                 => 'Engineering',
        'perf-'                    => 'Engineering',
        'db-'                      => 'Engineering',
        'edge-case-'               => 'Engineering',
        'dep-'                     => 'Engineering',
        'incident-'                => 'Engineering',

        // Business / growth / marketing
        'seo-'                     => 'Business',
        'marketing-'               => 'Business',
        'operations-'              => 'Business',
        'sales-'                   => 'Business',
        'growth-'                  => 'Business',
        'copy-'                    => 'Business',
        'social-'                  => 'Business',
        'blog-'                    => 'Business',
        'email-'                   => 'Business',
        'intel-'                   => 'Business',
        'ad-'                      => 'Business',
        'customer-success-'        => 'Business',
        'revops-'                  => 'Business',
        'experimentation-'         => 'Business',

        // Security / compliance / audit
        'security-'                => 'Security',
        'legal-'                   => 'Security',
        'compliance-'              => 'Security',
        'audit-'                   => 'Security',

        // Logistics / geospatial
        'logistics-'               => 'Logistics',
        'site-planner-'            => 'Logistics',
        'regional-'                => 'Logistics',

        // Financial
        'fin-'                     => 'Financial',
        'market-pulse-'            => 'Financial',

        // Career / networking
        'career-'                  => 'Career',
        'interview-'               => 'Career',
        'recruiter-'               => 'Career',
        'network-'                 => 'Career',
        'biznet-'                  => 'Career',
        'ecosystem-'               => 'Career',
        'events-'                  => 'Career',

        // Data / AI / research
        'data-'                    => 'Data',
        'ai-'                      => 'Data',
        'research-'                => 'Data',

        // Real estate
        're-'                      => 'Real Estate',

        // Content / collection
        'content-'                 => 'Content',
        'crawler-'                 => 'Content',
    ];

    public function __construct(
        /** @var list<string> Absolute paths to `.claude/agents` roots. */
        private readonly array $roots = [],
    ) {}

    /**
     * Build a catalog from config('super-ai-core.agent_catalog.paths'),
     * falling back to common defaults so unconfigured hosts still get
     * useful output for a SuperTeam-style layout.
     */
    public static function fromConfig(): self
    {
        $paths = [];
        if (function_exists('config')) {
            $configured = config('super-ai-core.agent_catalog.paths', []);
            if (is_array($configured)) {
                foreach ($configured as $p) if (is_string($p) && $p !== '') $paths[] = $p;
            }
        }
        if ($paths === []) {
            $candidates = [];
            if (function_exists('base_path')) {
                $candidates[] = base_path('.claude/agents');
                $candidates[] = base_path('../.claude/agents');
            }
            foreach ($candidates as $c) {
                if (is_dir($c)) { $paths[] = $c; break; }
            }
        }
        return new self($paths);
    }

    /**
     * @return array<string, list<array{name:string, description:string, file:string, model:?string}>>
     *   Keyed by category, each containing a list of agents sorted by name.
     */
    public function groupedByCategory(): array
    {
        $grouped = [];
        foreach ($this->all() as $agent) {
            $cat = $this->categorize($agent['name']);
            $grouped[$cat][] = $agent;
        }
        foreach ($grouped as $cat => $_) {
            usort($grouped[$cat], fn ($a, $b) => strcmp($a['name'], $b['name']));
        }
        // Stable category order
        $order = [
            'Strategy', 'Product', 'Engineering', 'Business', 'Security',
            'Logistics', 'Financial', 'Career', 'Data', 'Real Estate',
            'Content', 'Other',
        ];
        $ordered = [];
        foreach ($order as $cat) {
            if (isset($grouped[$cat])) $ordered[$cat] = $grouped[$cat];
        }
        foreach ($grouped as $cat => $list) {
            if (!isset($ordered[$cat])) $ordered[$cat] = $list;
        }
        return $ordered;
    }

    /**
     * @return list<array{name:string, description:string, file:string, model:?string}>
     */
    public function all(): array
    {
        $out = [];
        $seen = [];
        foreach ($this->roots as $root) {
            if (!is_dir($root)) continue;
            foreach (glob(rtrim($root, '/\\') . DIRECTORY_SEPARATOR . '*.md') ?: [] as $file) {
                $name = basename($file, '.md');
                if ($name === 'PROTOCOL' || $name === 'README') continue;
                if (isset($seen[$name])) continue;
                $front = $this->readFrontmatter($file);
                if ($front === null) continue;
                $out[] = [
                    'name'        => (string) ($front['name'] ?? $name),
                    'description' => (string) ($front['description'] ?? ''),
                    'file'        => $file,
                    'model'       => $front['model'] ?? null,
                ];
                $seen[$name] = true;
            }
        }
        usort($out, fn ($a, $b) => strcmp($a['name'], $b['name']));
        return $out;
    }

    public function find(string $name): ?array
    {
        foreach ($this->all() as $agent) {
            if ($agent['name'] === $name) return $agent;
        }
        return null;
    }

    /**
     * Read full body (after frontmatter) for the agent's detail view.
     */
    public function body(string $file): string
    {
        if (!is_file($file)) return '';
        $raw = (string) file_get_contents($file);
        // strip leading frontmatter block if present
        if (str_starts_with($raw, '---')) {
            $end = strpos($raw, "\n---", 3);
            if ($end !== false) {
                $raw = substr($raw, $end + 4);
            }
        }
        return ltrim($raw, "\r\n");
    }

    private function categorize(string $name): string
    {
        // Match longest prefix first
        $prefixes = array_keys(self::PREFIX_CATEGORIES);
        usort($prefixes, fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($prefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return self::PREFIX_CATEGORIES[$prefix];
            }
        }
        return 'Other';
    }

    /** @return array<string,mixed>|null */
    private function readFrontmatter(string $path): ?array
    {
        $fh = fopen($path, 'rb');
        if (!$fh) return null;
        $first = fgets($fh);
        if ($first === false || trim($first) !== '---') {
            fclose($fh);
            return null;
        }
        $out = [];
        while (($line = fgets($fh)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '---') break;
            if (!preg_match('/^([A-Za-z0-9_-]+):\s*(.*)$/', $line, $m)) continue;
            $value = trim($m[2]);
            // Strip wrapping quotes (single/double)
            if (strlen($value) >= 2 && (
                ($value[0] === '"' && $value[strlen($value) - 1] === '"') ||
                ($value[0] === "'" && $value[strlen($value) - 1] === "'")
            )) {
                $value = substr($value, 1, -1);
            }
            $out[$m[1]] = $value;
        }
        fclose($fh);
        return $out;
    }
}
