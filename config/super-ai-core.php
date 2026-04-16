<?php

/**
 * AI Core package config.
 * Publish to host app with: php artisan vendor:publish --tag=super-ai-core-config
 */
return [

    // ─── Route registration ───
    'route' => [
        // Whether to register package routes at all. Disable if host wants
        // to own all routing and use services directly.
        'enabled' => env('AI_CORE_ROUTES_ENABLED', true),
        'prefix' => env('AI_CORE_ROUTE_PREFIX', 'super-ai-core'),
        'middleware' => ['web', 'auth'],
    ],

    // ─── View registration ───
    // Package ships full UI. Set to false if host provides its own views
    // and only wants the services.
    'views_enabled' => env('AI_CORE_VIEWS_ENABLED', true),

    // ─── Backends ───
    // Which backends are usable. Disable ones you don't need.
    'backends' => [
        'claude_cli' => [
            'enabled' => env('AI_CORE_CLAUDE_CLI_ENABLED', true),
            'binary' => env('CLAUDE_CLI_BIN', 'claude'),
            'timeout' => 300,
        ],
        'codex_cli' => [
            'enabled' => env('AI_CORE_CODEX_CLI_ENABLED', true),
            'binary' => env('CODEX_CLI_BIN', 'codex'),
            'timeout' => 300,
        ],
        'superagent' => [
            'enabled' => env('AI_CORE_SUPERAGENT_ENABLED', true),
        ],
        'anthropic_api' => [
            'enabled' => env('AI_CORE_ANTHROPIC_API_ENABLED', true),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'api_version' => '2023-06-01',
        ],
        'openai_api' => [
            'enabled' => env('AI_CORE_OPENAI_API_ENABLED', true),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
        ],
    ],

    // ─── Default backend ───
    // When no provider/routing is configured, fall back to this backend
    // reading credentials from env.
    'default_backend' => env('AI_CORE_DEFAULT_BACKEND', 'anthropic_api'),

    // ─── Usage tracking ───
    'usage_tracking' => [
        'enabled' => env('AI_CORE_USAGE_TRACKING', true),
        'retain_days' => (int) env('AI_CORE_USAGE_RETAIN_DAYS', 180),
    ],

    // ─── MCP server management ───
    'mcp' => [
        'enabled' => env('AI_CORE_MCP_ENABLED', true),
        // Directory where MCP server binaries get installed
        'install_dir' => env('AI_CORE_MCP_INSTALL_DIR', null),
    ],

    // ─── Process monitor (admin only) ───
    'process_monitor' => [
        'enabled' => env('AI_CORE_PROCESS_MONITOR', false),
    ],

    // ─── Cost calculator unit prices ───
    // USD per 1M tokens. Override via config publish.
    'model_pricing' => [
        'claude-opus-4-6'             => ['input' => 15.00, 'output' => 75.00],
        'claude-opus-4-20250514'      => ['input' => 15.00, 'output' => 75.00],
        'claude-sonnet-4-5-20241022'  => ['input' => 3.00,  'output' => 15.00],
        'claude-sonnet-4-6'           => ['input' => 3.00,  'output' => 15.00],
        'claude-haiku-4-5-20251001'   => ['input' => 1.00,  'output' => 5.00],
        'gpt-4o'                      => ['input' => 2.50,  'output' => 10.00],
        'gpt-4o-mini'                 => ['input' => 0.15,  'output' => 0.60],
    ],
];
