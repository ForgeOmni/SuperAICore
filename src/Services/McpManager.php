<?php

namespace ForgeOmni\AiCore\Services;

/**
 * MCP server registry + install + auth session manager.
 *
 * PLACEHOLDER — full 2953-line implementation to be ported from SuperTeam
 * in a follow-up commit. This stub provides the service shape so other
 * packages can depend on it without blocking.
 */
class McpManager
{
    protected array $registry = [];

    public function registry(): array
    {
        return $this->registry;
    }

    public function register(string $key, array $definition): void
    {
        $this->registry[$key] = $definition;
    }

    public function install(string $key): array
    {
        return ['ok' => false, 'error' => 'McpManager not yet ported from SuperTeam'];
    }

    public function test(string $key): array
    {
        return ['ok' => false, 'error' => 'McpManager not yet ported'];
    }

    public function uninstall(string $key): array
    {
        return ['ok' => false, 'error' => 'McpManager not yet ported'];
    }
}
