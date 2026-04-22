<?php

/**
 * AI Core package config.
 * Publish to host app with: php artisan vendor:publish --tag=super-ai-core-config
 */
return [

    // ─── Host integration ───
    // Where the "back to host app" link in the package nav points to.
    // Null hides the link entirely.
    'host_back_url' => env('SUPER_AI_CORE_HOST_BACK_URL', null),
    // Display name for the host app (e.g. "SuperTeam", "Shopify Autopilot").
    'host_name' => env('SUPER_AI_CORE_HOST_NAME', null),
    // Icon shown next to the back link (Bootstrap Icons class).
    'host_icon' => env('SUPER_AI_CORE_HOST_ICON', 'bi-arrow-left'),

    // ─── Locale switcher ───
    // Locales offered by the nav dropdown. Empty array hides the switcher.
    'locales' => [
        'en'    => 'English',
        'zh-CN' => '简体中文',
        'fr'    => 'Français',
    ],
    // Cookie name used to persist the switched locale.
    // Host app's middleware (e.g. SuperTeam's SetLocale) should read this cookie.
    'locale_cookie' => env('SUPER_AI_CORE_LOCALE_COOKIE', 'locale'),

    // ─── Table prefix ───
    // Prepended to every package table (ai_providers → sac_ai_providers)
    // so names can't collide with a host app's tables. Set to '' to keep
    // the raw names. Read by every Eloquent model (via HasConfigurablePrefix)
    // and every migration (via SuperAICore\Support\TablePrefix).
    'table_prefix' => env('AI_CORE_TABLE_PREFIX', 'sac_'),

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

    // Layout Blade view that package pages like the process monitor extend.
    // Hosts override this in their app/config to use their own layout —
    // e.g. SuperTeam sets 'layouts.app' so pages inherit its --tf-* design
    // tokens, navbar and container.
    'layout' => env('SUPER_AI_CORE_LAYOUT', 'super-ai-core::layouts.app'),

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
        'gemini_cli' => [
            'enabled' => env('AI_CORE_GEMINI_CLI_ENABLED', true),
            'binary' => env('GEMINI_CLI_BIN', 'gemini'),
            'timeout' => 300,
        ],
        'copilot_cli' => [
            'enabled' => env('AI_CORE_COPILOT_CLI_ENABLED', true),
            'binary' => env('COPILOT_CLI_BIN', 'copilot'),
            'timeout' => 300,
            // Copilot's default UX requires per-tool confirmation; CI / non-interactive
            // runs need this on. Flip to false to opt back into prompts.
            'allow_all_tools' => (bool) env('AI_CORE_COPILOT_ALLOW_ALL_TOOLS', true),
        ],
        'kiro_cli' => [
            'enabled' => env('AI_CORE_KIRO_CLI_ENABLED', true),
            'binary' => env('KIRO_CLI_BIN', 'kiro-cli'),
            'timeout' => 300,
            // Kiro's --no-interactive mode refuses to run tools without prior
            // per-tool approval unless this is on. Flip false only for workflows
            // that pre-populate approvals via `--trust-tools=<categories>`.
            'trust_all_tools' => (bool) env('AI_CORE_KIRO_TRUST_ALL_TOOLS', true),
        ],
        'gemini_api' => [
            'enabled' => env('AI_CORE_GEMINI_API_ENABLED', true),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'),
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

        // `external_label` prefixes claimed by host ProcessSources. The
        // built-in AiProcessSource skips emitting rows whose label starts
        // with any of these, so the host's rich entry (with task /
        // project / model badges) is the only one the view renders.
        // Example for SuperTeam: ['task:']. Leave empty when the host has
        // no ProcessSource of its own.
        'host_owned_label_prefixes' => [],
    ],

    // ─── Engine catalog overrides ───
    // SuperAICore ships sensible defaults for every engine (label, icon,
    // dispatcher backends, available models, billing model) — see
    // `EngineCatalog::seed()`. Host apps can override per-engine fields here
    // without forking the catalog. New engines can also be added by name.
    //
    // Example — add a model to the Claude engine without touching the SDK:
    //   'engines' => [
    //       'claude' => [
    //           'available_models' => [
    //               'claude-opus-4-6', 'claude-sonnet-4-6',
    //               'claude-haiku-4-5-20251001', 'my-custom-fine-tune',
    //           ],
    //       ],
    //   ],
    'engines' => [],

    // ─── Provider-type registry overrides (0.6.2+) ───
    // Partial overrides on top of the 9 bundled provider types (anthropic,
    // anthropic-proxy, bedrock, vertex, google-ai, openai, openai-compatible,
    // kiro-api, builtin). Host apps (e.g. SuperTeam) can:
    //
    //   - rebrand a type's label to their own lang namespace
    //     ('label_key' => 'integrations.ai_provider_anthropic')
    //   - add a brand-new type not in the bundle
    //     ('xai-api' => ['env_key' => 'XAI_API_KEY', 'fields' => ['api_key'],
    //                    'default_backend' => 'superagent',
    //                    'allowed_backends' => ['superagent']])
    //   - swap an env key (e.g. point Anthropic at a proxy-specific var)
    //
    // The controller / UI / ProviderEnvBuilder all read through the
    // registry, so entries here flow everywhere without further edits.
    // Each descriptor shape mirrors `ProviderTypeDescriptor::fromArray()`.
    'provider_types' => [],

    // ─── Cost calculator unit prices ───
    // USD per 1M tokens. Each entry can include `billing_model` (default 'usage'):
    //   - 'usage'       — per-token billing; cost calculator multiplies tokens × rate
    //   - 'subscription' — flat-fee plan (e.g. GitHub Copilot); cost is always $0
    //                      and the dashboard renders these in the "Subscription
    //                      engines" section instead of mixing into USD totals.
    //
    // Override via config publish. Hosts can add unlisted models.
    'model_pricing' => [
        // ─── Anthropic Claude ───
        'claude-opus-4-7'             => ['input' => 15.00, 'output' => 75.00],
        'claude-opus-4-6'             => ['input' => 15.00, 'output' => 75.00],
        'claude-opus-4-5'             => ['input' => 15.00, 'output' => 75.00],
        'claude-opus-4-20250514'      => ['input' => 15.00, 'output' => 75.00],
        'claude-sonnet-4-6'           => ['input' => 3.00,  'output' => 15.00],
        'claude-sonnet-4-5'           => ['input' => 3.00,  'output' => 15.00],
        'claude-sonnet-4-5-20241022'  => ['input' => 3.00,  'output' => 15.00],
        'claude-sonnet-4'             => ['input' => 3.00,  'output' => 15.00],
        'claude-haiku-4-5'            => ['input' => 1.00,  'output' => 5.00],
        'claude-haiku-4-5-20251001'   => ['input' => 1.00,  'output' => 5.00],

        // ─── OpenAI GPT (estimates for unreleased model IDs; override per host) ───
        'gpt-5'                       => ['input' => 5.00,  'output' => 15.00],
        'gpt-5.1'                     => ['input' => 5.00,  'output' => 15.00],
        'gpt-5.1-codex'               => ['input' => 5.00,  'output' => 15.00],
        'gpt-5.1-codex-mini'          => ['input' => 0.50,  'output' => 2.00],
        'gpt-5-mini'                  => ['input' => 0.30,  'output' => 1.20],
        'gpt-4.1'                     => ['input' => 2.00,  'output' => 8.00],
        'gpt-4o'                      => ['input' => 2.50,  'output' => 10.00],
        'gpt-4o-mini'                 => ['input' => 0.15,  'output' => 0.60],

        // ─── Google Gemini ───
        'gemini-3-pro-preview'        => ['input' => 2.00,  'output' => 12.00],
        'gemini-2.5-pro'              => ['input' => 1.25,  'output' => 10.00],
        'gemini-2.5-flash'            => ['input' => 0.30,  'output' => 2.50],
        'gemini-2.5-flash-lite'       => ['input' => 0.10,  'output' => 0.40],

        // ─── GitHub Copilot CLI (subscription billed; per-token cost is $0) ───
        // The dashboard reports these under a separate "Subscription engines"
        // section so monthly USD totals stay accurate.
        'copilot:claude-sonnet-4-5'   => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'copilot:claude-opus-4-5'     => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'copilot:claude-haiku-4-5'    => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'copilot:claude-sonnet-4'     => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'copilot:gpt-5'               => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'copilot:gpt-5.1'             => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'copilot:gpt-5.1-codex'       => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'copilot:gpt-5.1-codex-mini'  => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'copilot:gpt-5-mini'          => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'copilot:gpt-4.1'             => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'copilot:gemini-3-pro-preview'=> ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],

        // ─── AWS Kiro CLI (credit-based subscription) ───
        // Kiro bills by per-response "credits" (plans: Free 50 / Pro 1k /
        // Pro+ 2k / Power 10k per month; overage $0.04/credit). Credits are
        // not tokens, so per-token USD is 0 and the dashboard groups these
        // under "Subscription engines". KiroCliBackend surfaces the per-call
        // credit count under `usage.credits` for hosts that want a custom
        // dashboard card (e.g. "2.8 credits / 43s this month") — core cost
        // totals stay at $0 to avoid cross-engine double counting. IDs use
        // DOT separators (matching `kiro-cli chat --list-models`); trailing
        // comments show Kiro's own credit `rate_multiplier` so operators
        // can see which models are more expensive per call.
        'kiro:auto'                   => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'], // router, 1.00×
        'kiro:claude-opus-4.6'        => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'], // 2.20×
        'kiro:claude-sonnet-4.6'      => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'], // 1.30× (1M context)
        'kiro:claude-opus-4.5'        => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'], // 2.20×
        'kiro:claude-sonnet-4.5'      => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'], // 1.30×
        'kiro:claude-sonnet-4'        => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'], // 1.30×
        'kiro:claude-haiku-4.5'       => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'], // 0.40×
        'kiro:deepseek-3.2'           => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'], // 0.25× (preview)
        'kiro:minimax-m2.5'           => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'], // 0.25×
        'kiro:minimax-m2.1'           => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'], // 0.15× (preview)
        'kiro:glm-5'                  => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'], // 0.50×
        'kiro:qwen3-coder-next'       => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'], // 0.05× (preview)
    ],
];
