<?php

namespace SuperAICore\Sync;

/**
 * Upserts a managed `mcpServers:` block inside each agent's YAML frontmatter
 * (`.claude/agents/*.md`). Preserves everything else in the file — only the
 * bytes between the managed-region markers are rewritten.
 *
 * Managed region format:
 *
 *   ---
 *   name: research-jordan
 *   description: "..."
 *   model: inherit
 *   # superaicore:mcp:begin (managed — regenerate via `claude:mcp-sync`; edits below are overwritten)
 *   mcpServers:
 *     arxiv:
 *       type: stdio
 *       command: node
 *       args:
 *         - ${SUPERTEAM_ROOT}/.mcp-servers/mcp-schema-proxy.mjs
 *         - uvx
 *         - arxiv-mcp-server
 *   # superaicore:mcp:end
 *   ---
 *
 * Non-destructive guarantees:
 *   - If an agent is not in `$assignments`, its file is never touched
 *   - If an agent's assigned server list is empty, the managed block is
 *     removed (but the rest of the frontmatter stays)
 *   - If a user has hand-written `mcpServers:` OUTSIDE the markers, we do
 *     NOT touch it — we still inject our own block, resulting in duplicate
 *     YAML keys (which Claude Code will flag loudly). Catch this pre-sync
 *     with `--dry-run` and fix manually.
 *   - Manifest tracks hash of the written managed block per-agent so
 *     re-runs stay idempotent; if a user edits inside the markers,
 *     on-disk hash != manifest hash → we overwrite (the markers are our
 *     territory by design).
 */
final class ClaudeAgentMcpWriter extends AbstractManifestWriter
{
    public const MARKER_BEGIN = '# superaicore:mcp:begin (managed — regenerate via `claude:mcp-sync`; edits below are overwritten)';
    public const MARKER_END   = '# superaicore:mcp:end';

    public function __construct(
        private readonly string $agentsDir,
        Manifest $manifest,
    ) {
        parent::__construct($manifest);
    }

    /**
     * @param array<string, array<int, string>> $assignments agent-name => list of server names
     * @param array<string, array{command:string,args:array<int,string>,env:array<string,string>,type:string}> $catalogServers name => config
     * @return array{written:string[],unchanged:string[],user_edited:string[],missing:string[]}
     */
    public function sync(array $assignments, array $catalogServers, bool $dryRun = false): array
    {
        $report = ['written' => [], 'unchanged' => [], 'user_edited' => [], 'missing' => []];

        $manifest = $this->manifest->read();
        $nextManifest = $manifest;

        foreach ($assignments as $agentName => $serverNames) {
            $path = rtrim($this->agentsDir, '/') . '/' . $agentName . '.md';

            if (!is_file($path)) {
                $report['missing'][] = $path;
                continue;
            }

            $subset = [];
            foreach ($serverNames as $s) {
                if (!isset($catalogServers[$s])) {
                    throw new \RuntimeException("Agent '{$agentName}' references unknown server '{$s}'");
                }
                $subset[$s] = $catalogServers[$s];
            }

            $managedBlock = $subset === []
                ? ''
                : self::renderManagedBlock($subset);

            $existing = (string) file_get_contents($path);
            $updated = self::upsertManagedBlock($existing, $managedBlock);

            if ($updated === $existing) {
                $report['unchanged'][] = $path;
                continue;
            }

            // Manifest policy: we own the bytes strictly between the markers.
            // If they differ from our last write AND from the new render, a
            // human edited inside our region. By design we still overwrite,
            // but flag it as user-edited so the operator can salvage.
            $prevHash = $manifest[$path] ?? null;
            $onDiskRegion = self::extractManagedBlock($existing);
            $newHash = hash('sha256', $managedBlock);
            if ($prevHash !== null
                && $prevHash !== hash('sha256', $onDiskRegion)
                && $prevHash !== $newHash) {
                $report['user_edited'][] = $path;
            }

            if (!$dryRun) {
                file_put_contents($path, $updated);
            }
            $report['written'][] = $path;
            $nextManifest[$path] = $newHash;
        }

        if (!$dryRun) {
            $this->manifest->write($nextManifest);
        }

        return $report;
    }

    /**
     * Render the full managed block including begin/end markers, or empty
     * string if no servers. The returned string ends with a newline when
     * non-empty so it can be dropped straight into the frontmatter.
     *
     * @param array<string, array{command:string,args:array<int,string>,env:array<string,string>,type:string}> $servers
     */
    public static function renderManagedBlock(array $servers): string
    {
        if ($servers === []) {
            return '';
        }

        $lines = [self::MARKER_BEGIN, 'mcpServers:'];
        foreach ($servers as $name => $cfg) {
            $lines[] = '  ' . self::yamlKey($name) . ':';
            $lines[] = '    type: ' . self::yamlScalar($cfg['type'] ?? 'stdio');
            $lines[] = '    command: ' . self::yamlScalar($cfg['command']);
            if (!empty($cfg['args'])) {
                $lines[] = '    args:';
                foreach ($cfg['args'] as $a) {
                    $lines[] = '      - ' . self::yamlScalar($a);
                }
            }
            if (!empty($cfg['env'])) {
                $lines[] = '    env:';
                foreach ($cfg['env'] as $k => $v) {
                    $lines[] = '      ' . self::yamlKey((string) $k) . ': ' . self::yamlScalar((string) $v);
                }
            }
        }
        $lines[] = self::MARKER_END;
        return implode("\n", $lines) . "\n";
    }

    /**
     * Splice the managed block into $content's frontmatter, replacing any
     * previous managed block between the markers. If $managedBlock is empty
     * and a block already exists, the block is removed.
     */
    public static function upsertManagedBlock(string $content, string $managedBlock): string
    {
        $fm = self::splitFrontmatter($content);
        if ($fm === null) {
            throw new \RuntimeException('Agent file has no YAML frontmatter — cannot inject mcpServers');
        }
        [$pre, $frontmatter, $post] = $fm;

        $beginQ = preg_quote(self::MARKER_BEGIN, '/');
        $endQ   = preg_quote(self::MARKER_END,   '/');
        $hasBlock = (bool) preg_match("/{$beginQ}.*?{$endQ}\n?/s", $frontmatter);

        if ($managedBlock === '') {
            if (!$hasBlock) return $content;
            $new = preg_replace("/\\n?{$beginQ}.*?{$endQ}\\n?/s", "\n", $frontmatter);
            // Normalize accidental double newlines introduced by removal.
            $new = preg_replace("/\\n{3,}/", "\n\n", (string) $new);
            return $pre . $new . $post;
        }

        if ($hasBlock) {
            $new = preg_replace("/{$beginQ}.*?{$endQ}\\n?/s", $managedBlock, $frontmatter);
            return $pre . (string) $new . $post;
        }

        // Append before closing `---`
        $trimmed = rtrim($frontmatter, "\n");
        return $pre . $trimmed . "\n" . $managedBlock . $post;
    }

    /**
     * Return the bytes currently inside the managed region (for hashing), or
     * empty string when absent.
     */
    public static function extractManagedBlock(string $content): string
    {
        $fm = self::splitFrontmatter($content);
        if ($fm === null) return '';
        $beginQ = preg_quote(self::MARKER_BEGIN, '/');
        $endQ   = preg_quote(self::MARKER_END,   '/');
        if (preg_match("/({$beginQ}.*?{$endQ}\\n?)/s", $fm[1], $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * Split file content into [pre-frontmatter, frontmatter-body-including-closing-delim, post].
     * The frontmatter segment starts with `---\n` and ends with `---\n`. Returns null when the
     * file lacks a leading `---` block.
     *
     * @return array{0:string,1:string,2:string}|null
     */
    private static function splitFrontmatter(string $content): ?array
    {
        // Require `---` at start-of-file (optional leading BOM/whitespace) and a closing `---` on its own line.
        if (!preg_match('/^(\xEF\xBB\xBF)?---\r?\n(.*?)\r?\n---\r?\n/s', $content, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $fullMatch  = $m[0][0];
        $matchStart = $m[0][1];
        $pre   = substr($content, 0, $matchStart) . "---\n";
        $frontmatter = $m[2][0] . "\n";
        $post  = "---\n" . substr($content, $matchStart + strlen($fullMatch));
        return [$pre, $frontmatter, $post];
    }

    private static function yamlKey(string $k): string
    {
        // Simple keys don't need quoting. Quote if contains anything nasty.
        return preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $k) ? $k : self::yamlScalar($k);
    }

    private static function yamlScalar(string $s): string
    {
        // Always safe: single-quote and escape embedded single-quotes by doubling.
        // Skip quoting only for trivially-safe bare scalars.
        if ($s === '') return "''";
        if (preg_match('/^[A-Za-z0-9_\-\/\.]+$/', $s)
            && !in_array(strtolower($s), ['true','false','null','yes','no','on','off'], true)) {
            return $s;
        }
        return "'" . str_replace("'", "''", $s) . "'";
    }
}
