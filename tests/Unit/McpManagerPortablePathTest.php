<?php

namespace SuperAICore\Tests\Unit;

use SuperAICore\Services\McpManager;
use SuperAICore\Tests\TestCase;

/**
 * Covers `McpManager::portablePath()` / `portableCommand()`, the helpers
 * that drive `mcp.portable_root_var` rewriting in `.mcp.json` writers.
 *
 * The default config keeps portability disabled, so paths flow through
 * unchanged — this guarantees the legacy behaviour (absolute paths +
 * resolved binaries) is preserved when the host hasn't opted in.
 */
final class McpManagerPortablePathTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mcp-portable-' . bin2hex(random_bytes(4));
        mkdir($this->tempRoot, 0755, true);
        McpManager::setProjectRootOverride($this->tempRoot);
    }

    protected function tearDown(): void
    {
        McpManager::setProjectRootOverride(null);
        @rmdir($this->tempRoot);
        parent::tearDown();
    }

    public function test_portable_path_passes_through_when_disabled(): void
    {
        config(['super-ai-core.mcp.portable_root_var' => null]);

        $abs = $this->tempRoot . DIRECTORY_SEPARATOR . '.mcp-servers' . DIRECTORY_SEPARATOR . 'foo' . DIRECTORY_SEPARATOR . 'server.py';
        $this->assertSame($abs, McpManager::portablePath($abs));
    }

    public function test_portable_path_rewrites_paths_under_project_root(): void
    {
        config(['super-ai-core.mcp.portable_root_var' => 'TEST_ROOT']);

        $sub = '.mcp-servers' . DIRECTORY_SEPARATOR . 'foo' . DIRECTORY_SEPARATOR . 'server.py';
        mkdir($this->tempRoot . DIRECTORY_SEPARATOR . '.mcp-servers' . DIRECTORY_SEPARATOR . 'foo', 0755, true);
        $abs = $this->tempRoot . DIRECTORY_SEPARATOR . $sub;
        touch($abs);

        $this->assertSame(
            '${TEST_ROOT}/.mcp-servers/foo/server.py',
            McpManager::portablePath($abs)
        );

        @unlink($abs);
        @rmdir($this->tempRoot . DIRECTORY_SEPARATOR . '.mcp-servers' . DIRECTORY_SEPARATOR . 'foo');
        @rmdir($this->tempRoot . DIRECTORY_SEPARATOR . '.mcp-servers');
    }

    public function test_portable_path_returns_input_when_path_outside_root(): void
    {
        config(['super-ai-core.mcp.portable_root_var' => 'TEST_ROOT']);

        $outside = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'somewhere-else' . DIRECTORY_SEPARATOR . 'binary.exe';
        $this->assertSame($outside, McpManager::portablePath($outside));
    }

    public function test_portable_path_collapses_to_root_var_when_path_equals_root(): void
    {
        config(['super-ai-core.mcp.portable_root_var' => 'TEST_ROOT']);

        $this->assertSame('${TEST_ROOT}', McpManager::portablePath($this->tempRoot));
    }

    public function test_portable_command_returns_bare_name_when_enabled(): void
    {
        config(['super-ai-core.mcp.portable_root_var' => 'TEST_ROOT']);

        $this->assertSame('node', McpManager::portableCommand('node', '/usr/local/bin/node'));
        $this->assertSame('uvx', McpManager::portableCommand('uvx', 'C:\\path\\to\\uvx.exe'));
    }

    public function test_portable_command_returns_resolved_path_when_disabled(): void
    {
        config(['super-ai-core.mcp.portable_root_var' => null]);

        $this->assertSame('/usr/local/bin/node', McpManager::portableCommand('node', '/usr/local/bin/node'));
    }

    public function test_portable_command_falls_back_to_bare_when_resolved_is_null(): void
    {
        config(['super-ai-core.mcp.portable_root_var' => null]);

        $this->assertSame('node', McpManager::portableCommand('node', null));
    }

    public function test_portable_root_var_treats_empty_string_as_disabled(): void
    {
        config(['super-ai-core.mcp.portable_root_var' => '']);
        $this->assertNull(McpManager::portableRootVar());

        config(['super-ai-core.mcp.portable_root_var' => '   ']);
        $this->assertNull(McpManager::portableRootVar());
    }

    public function test_materialize_replaces_placeholder_with_env_var_value(): void
    {
        config(['super-ai-core.mcp.portable_root_var' => 'TEST_ROOT']);
        putenv('TEST_ROOT=' . $this->tempRoot);

        $this->assertSame(
            $this->tempRoot . '/web/artisan',
            McpManager::materializePortablePath('${TEST_ROOT}/web/artisan')
        );

        putenv('TEST_ROOT'); // unset
    }

    public function test_materialize_falls_back_to_project_root_when_env_unset(): void
    {
        config(['super-ai-core.mcp.portable_root_var' => 'TEST_ROOT']);
        putenv('TEST_ROOT'); // ensure unset

        $this->assertSame(
            $this->tempRoot . '/web/artisan',
            McpManager::materializePortablePath('${TEST_ROOT}/web/artisan')
        );
    }

    public function test_materialize_is_noop_when_disabled(): void
    {
        config(['super-ai-core.mcp.portable_root_var' => null]);

        // Even with the placeholder text in the string and the env var
        // exported, materialise leaves it alone — the disabled mode is a
        // hard no-op so legacy callers see exactly the bytes they passed.
        putenv('TEST_ROOT=/tmp/whatever');
        $this->assertSame(
            '${TEST_ROOT}/web/artisan',
            McpManager::materializePortablePath('${TEST_ROOT}/web/artisan')
        );
        putenv('TEST_ROOT');
    }

    public function test_materialize_is_noop_when_string_has_no_placeholder(): void
    {
        config(['super-ai-core.mcp.portable_root_var' => 'TEST_ROOT']);
        putenv('TEST_ROOT=' . $this->tempRoot);

        $this->assertSame('/already/absolute/path', McpManager::materializePortablePath('/already/absolute/path'));
        $this->assertSame('node', McpManager::materializePortablePath('node'));

        putenv('TEST_ROOT');
    }

    public function test_materialize_server_spec_walks_command_args_env(): void
    {
        config(['super-ai-core.mcp.portable_root_var' => 'TEST_ROOT']);
        putenv('TEST_ROOT=' . $this->tempRoot);

        $input = [
            'command' => '${TEST_ROOT}/.venv/bin/python',
            'args' => ['-m', '${TEST_ROOT}/.mcp-servers/foo/server.py'],
            'env' => ['DATA_DIR' => '${TEST_ROOT}/data', 'API_KEY' => 'sk-no-placeholder'],
            'timeout' => 30000,
        ];

        $output = McpManager::materializeServerSpec($input);

        $this->assertSame($this->tempRoot . '/.venv/bin/python', $output['command']);
        $this->assertSame(['-m', $this->tempRoot . '/.mcp-servers/foo/server.py'], $output['args']);
        $this->assertSame($this->tempRoot . '/data', $output['env']['DATA_DIR']);
        $this->assertSame('sk-no-placeholder', $output['env']['API_KEY']);
        $this->assertSame(30000, $output['timeout']);

        putenv('TEST_ROOT');
    }

    public function test_materialize_server_spec_is_noop_when_disabled(): void
    {
        config(['super-ai-core.mcp.portable_root_var' => null]);

        $input = [
            'command' => '${TEST_ROOT}/python',
            'args' => ['${TEST_ROOT}/server.py'],
            'env' => ['X' => '${TEST_ROOT}/data'],
        ];

        $this->assertSame($input, McpManager::materializeServerSpec($input));
    }
}
