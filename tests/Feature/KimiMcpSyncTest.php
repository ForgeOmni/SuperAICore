<?php

namespace SuperAICore\Tests\Feature;

use SuperAICore\Services\McpManager;
use SuperAICore\Tests\TestCase;

/**
 * End-to-end validation of `McpManager::syncAllBackends(['kimi'])` against
 * a real Laravel container (Orchestra Testbench). Exercises the full path:
 *
 *   project `.mcp.json`  →  McpManager::codexMcpServers()
 *                        →  CapabilityRegistry::for('kimi')->renderMcpConfig()
 *                        →  file_put_contents($HOME/.kimi/mcp.json)
 *
 * HOME is sandboxed so the test doesn't touch the developer's real
 * `~/.kimi/` dir. A project `.mcp.json` is dropped under base_path() for
 * the container to discover.
 */
class KimiMcpSyncTest extends TestCase
{
    private string $sandboxHome;
    private string $sandboxProject;
    /** @var string|false */
    private $savedHome = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandboxHome    = sys_get_temp_dir() . '/kimi-mcp-' . bin2hex(random_bytes(3));
        $this->sandboxProject = $this->sandboxHome . '/project';
        mkdir($this->sandboxHome . '/.kimi', 0755, true);
        mkdir($this->sandboxProject, 0755, true);

        $this->savedHome = getenv('HOME');
        putenv('HOME=' . $this->sandboxHome);
        $_SERVER['HOME'] = $this->sandboxHome;

        // Point McpManager's project-root probe at our sandbox. Without
        // this it falls back to `dirname(base_path())` which walks OUTSIDE
        // the sandbox and reads whatever `.mcp.json` lives at the testbench
        // parent (usually none → empty catalog → empty write).
        McpManager::setProjectRootOverride($this->sandboxProject);
    }

    protected function tearDown(): void
    {
        McpManager::setProjectRootOverride(null);
        putenv($this->savedHome === false ? 'HOME' : 'HOME=' . $this->savedHome);
        if ($this->savedHome !== false) {
            $_SERVER['HOME'] = $this->savedHome;
        }
        $this->rrmdir($this->sandboxHome);
        parent::tearDown();
    }

    public function test_sync_all_backends_writes_kimi_mcp_json_from_project_catalog(): void
    {
        // Project catalog drives the downstream fan-out. Shape is identical
        // to what `claude:mcp-sync` writes before propagation.
        file_put_contents($this->sandboxProject . '/.mcp.json', json_encode([
            'mcpServers' => [
                'fetch' => [
                    'type' => 'stdio',
                    'command' => 'uvx',
                    'args' => ['mcp-server-fetch'],
                ],
                'arxiv' => [
                    'type' => 'stdio',
                    'command' => 'node',
                    'args' => ['arxiv.mjs'],
                    'env' => ['KEY' => 'x'],
                ],
            ],
        ]));

        $report = McpManager::syncAllBackends(['kimi']);

        $this->assertCount(1, $report);
        $row = $report[0];
        $this->assertSame('kimi', $row['backend']);
        $this->assertNull($row['error'], 'unexpected error: ' . ($row['error'] ?? ''));
        $this->assertGreaterThan(0, $row['bytes']);
        $this->assertSame(
            $this->sandboxHome . '/.kimi/mcp.json',
            $row['path'],
        );

        $written = json_decode((string) file_get_contents($row['path']), true);
        $this->assertIsArray($written);
        $this->assertArrayHasKey('mcpServers', $written);
        $this->assertArrayHasKey('fetch', $written['mcpServers']);
        $this->assertArrayHasKey('arxiv', $written['mcpServers']);
        $this->assertSame('uvx', $written['mcpServers']['fetch']['command']);
        $this->assertSame(['mcp-server-fetch'], $written['mcpServers']['fetch']['args']);
        $this->assertSame(['KEY' => 'x'], $written['mcpServers']['arxiv']['env']);
    }

    public function test_sync_all_backends_preserves_non_mcp_server_user_keys(): void
    {
        // Pre-seed a user-hand-edited ~/.kimi/mcp.json with both an auth
        // segment outside `mcpServers` (must survive) and an mcpServers
        // entry that the sync should overwrite (documented contract, same
        // as Gemini/Copilot renderMcpConfig behaviour).
        file_put_contents($this->sandboxHome . '/.kimi/mcp.json', json_encode([
            'mcpServers' => ['stale' => ['type' => 'stdio', 'command' => 'dead']],
            'auth'       => ['oauth_token' => 'USER_SECRET_TOKEN'],
            'telemetry'  => false,
        ]));

        file_put_contents($this->sandboxProject . '/.mcp.json', json_encode([
            'mcpServers' => [
                'fresh' => ['type' => 'stdio', 'command' => 'node', 'args' => ['new.mjs']],
            ],
        ]));

        $report = McpManager::syncAllBackends(['kimi']);
        $this->assertNull($report[0]['error'] ?? null);

        $written = json_decode((string) file_get_contents($report[0]['path']), true);

        // Non-`mcpServers` user segments must survive verbatim.
        $this->assertSame('USER_SECRET_TOKEN', $written['auth']['oauth_token']);
        $this->assertFalse($written['telemetry']);

        // `mcpServers` itself is fully replaced by the project catalog.
        $this->assertArrayHasKey('fresh', $written['mcpServers']);
        $this->assertArrayNotHasKey('stale', $written['mcpServers']);
    }

    public function test_default_list_includes_kimi(): void
    {
        // Drop an empty project catalog so the sync has no work to do, but
        // the default-list probe still runs. When called with $backends=null,
        // McpManager iterates the catalog AND the static fallback — both
        // now contain 'kimi', so we expect to see a row for it.
        file_put_contents($this->sandboxProject . '/.mcp.json', json_encode([
            'mcpServers' => new \stdClass(),
        ]));

        $report = McpManager::syncAllBackends();
        $backends = array_column($report, 'backend');

        $this->assertContains('kimi', $backends, 'kimi should be in the default fan-out list');
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $e) {
            if ($e === '.' || $e === '..') continue;
            $p = $dir . '/' . $e;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
