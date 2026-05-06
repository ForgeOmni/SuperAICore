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

    // ─── Embeddings (0.9.0+) ───
    // Optional embedding backend used by SemanticSkillReranker, the SDK's
    // own SemanticSkillRouter (when the host wires one), and any future
    // jcode-borrowed semantic feature. `EmbeddingProviderFactory`
    // resolves the first match below into an SDK 0.9.7
    // `SuperAgent\Memory\Embeddings\EmbeddingProvider`:
    //
    //   1. provider     — already-instantiated EmbeddingProvider (host
    //                     wires its own `OnnxEmbeddingProvider`, OpenAI-
    //                     backed adapter, prebuilt-cache reader, …).
    //   2. callback     — closure: `fn(list<string>): list<list<float>>`
    //                     OR legacy `fn(string): list<float>`. SDK's
    //                     `CallableEmbeddingProvider` auto-detects the
    //                     parameter type so old hand-rolled embedders
    //                     keep working.
    //   3. ollama_url   — local Ollama daemon (`/api/embeddings` with
    //                     `ollama_model`, default `nomic-embed-text`).
    //
    // When none is set, SemanticSkillReranker degrades to a no-op and
    // SkillRanker returns BM25 ordering. `fingerprint` is used as the
    // cache invalidation key for the callback adapter; change it when
    // the underlying model changes so cached vectors flush cleanly.
    'embeddings' => [
        'provider'     => null,
        'callback'     => null,
        'fingerprint'  => env('AI_CORE_EMBEDDINGS_FINGERPRINT', null),
        'ollama_url'   => env('AI_CORE_EMBEDDINGS_OLLAMA_URL', null),
        'ollama_model' => env('AI_CORE_EMBEDDINGS_OLLAMA_MODEL', 'nomic-embed-text'),
        'timeout_ms'   => (int) env('AI_CORE_EMBEDDINGS_TIMEOUT_MS', 10_000),
    ],

    // ─── Browser-screenshot store (0.9.7) ───
    // Backs `ProcessEntry::$latest_screenshot_url` for `/processes` rows
    // when an agent invoked SDK 0.9.7's `FirefoxBridgeTool` (`browser`).
    // `disk` — Laravel filesystem disk to write into. Default 'local';
    //          point at 's3' or a per-pod tmpfs disk for production.
    // `dir`  — relative directory under the disk.
    'browser_screenshots' => [
        'disk' => env('AI_CORE_BROWSER_SHOTS_DISK', 'local'),
        'dir'  => env('AI_CORE_BROWSER_SHOTS_DIR', 'super-ai-core/browser-screenshots'),
    ],

    // ─── Cross-harness session resume (0.9.7) ───
    // Backed by SuperAgent SDK 0.9.7's HarnessImporter SPI
    // (`ClaudeCodeImporter` reads ~/.claude/projects/<hash>/<uuid>.jsonl;
    // `CodexImporter` reads ~/.codex/sessions/**/*.jsonl). The `/processes`
    // page surfaces a "Resume from…" dropdown when this is on.
    //
    // `enabled`  — gate the entire feature. Off by default since on shared
    //              machines the importer can see every operator's history.
    // `on_load`  — optional callable invoked after the importer returns:
    //              `fn(string $harness, string $sessionId, list<Message> $messages): mixed`
    //              Whatever the callable returns is forwarded to the front-
    //              end as `host_payload` so hosts can redirect into a
    //              chat URL pre-loaded with the messages. Without a hook,
    //              the response just carries the transcript JSON.
    'resume' => [
        'enabled' => (bool) env('AI_CORE_RESUME_ENABLED', false),
        'on_load' => null,
    ],

    // ─── Builtin SuperAgent tools (0.9.7) ───
    // jcode-style auxiliary tools that ship with SuperAgent SDK 0.9.7 but
    // aren't in its default tool set. Flip these on to have
    // SuperAgentBackend prepend them to `load_tools` automatically when
    // the caller doesn't supply an explicit list. Hosts that already pass
    // their own `load_tools` retain full control — these flags only fire
    // on the implicit path.
    'tools' => [
        // jcode-style `agent_grep` — enclosing-symbol context + per-session
        // seen-chunk truncation. Strict superset of `grep` for long-running
        // agents on big repos. Lazy-loaded via SDK's BuiltinToolRegistry
        // classMap, so this just adds the name to load_tools. Default ON
        // because it's read-only, dependency-free, and only ever fires on
        // dispatches that opt into a real agentic loop with tools (one-shot
        // calls and CLI-backed dispatches don't see it). Flip to false to
        // force-disable when you want byte-identical pre-0.9.7 behaviour.
        'agent_grep_enabled' => filter_var(
            env('AI_CORE_TOOLS_AGENT_GREP', true),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        ) ?? true,

        // SDK 0.9.7 `FirefoxBridgeTool` (`browser`) — drives a real
        // Firefox / Chromium tab via Native Messaging. Requires
        // `SUPERAGENT_BROWSER_BRIDGE_PATH` to point at the launcher
        // binary; without that, every action returns an explanatory
        // error so the agent learns to ask for setup help instead of
        // looping. The tool isn't in BuiltinToolRegistry's classMap so
        // SuperAgentBackend instantiates + addTool()'s it directly when
        // this flag is on.
        'browser_enabled'    => (bool) env('AI_CORE_TOOLS_BROWSER', false),
    ],

    // ─── UI features (0.9.0) ───
    'ui' => [
        // jcode-style inline mermaid rendering. When enabled, the bundled
        // layout loads mermaid.js from the CDN and exposes
        // `window.SuperAICoreMermaid.run()` / `.upgrade(node)`. The
        // /processes log viewer auto-detects ```mermaid fences and
        // renders them as live SVGs. Disable for air-gapped hosts that
        // can't reach jsdelivr.
        'mermaid_enabled' => (bool) env('AI_CORE_UI_MERMAID', true),

        // jcode-style right-hand offcanvas drawer for auxiliary content
        // (file diffs, mermaid, JSON inspectors). Renders nothing until a
        // view drops a `<!-- side-panel: {…json…} -->` marker or wires a
        // `[data-side-panel-trigger]` button. JS API:
        // `window.SuperAICorePanel.show({title, type, content, footer})`.
        // Disable to drop the offcanvas markup + script entirely.
        'side_panel_enabled' => (bool) env('AI_CORE_UI_SIDE_PANEL', true),
    ],

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
        'kimi_cli' => [
            'enabled' => env('AI_CORE_KIMI_CLI_ENABLED', true),
            'binary' => env('KIMI_CLI_BIN', 'kimi'),
            'timeout' => 300,
            // Kimi's agentic loop is capped at max_steps_per_turn (defaults
            // to 500 in ~/.kimi/config.toml). Override here when the host
            // wants a tighter budget for cost control, or higher for long
            // -running tasks — Kimi's K2.6 ramp targets 4000 steps but
            // needs the SDK, not this CLI, for that scale.
            'max_steps_per_turn' => (int) env('AI_CORE_KIMI_MAX_STEPS_PER_TURN', 500),
            // Agent-team routing toggle (see docs/kimi-cli-backend.md §3.4):
            //   true  — (a) default: let Kimi drive its own `Agent` tool
            //           fanout. Host `AgentSpawn\Pipeline` fast-exits.
            //   false — (b) opt-in: route through our three-phase Pipeline
            //           so 0.6.8 weak-model hardening applies (guard
            //           injection, canonical output_subdir, post-fanout
            //           audit, language-aware consolidation). Needed for
            //           per-child stream observability (Kimi's stream-json
            //           hides SubagentEvent) or workloads exceeding the
            //           500-step per-turn cap.
            'use_native_agents' => filter_var(
                env('AI_CORE_KIMI_USE_NATIVE_AGENTS', true),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE,
            ) ?? true,
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

    // ─── Auto-rotate on quota errors (0.9.0) ───
    // When SuperAgentBackend catches QuotaExceededException twice within
    // `window_seconds`, run `provider:rotate` for the affected backend
    // with reason='quota_exceeded'. Off by default — opt in to avoid
    // surprising operators whose fallback provider isn't actually viable
    // (different model availability, missing api key, etc.).
    'auto_rotate' => [
        'enabled'          => env('AI_CORE_AUTO_ROTATE', false),
        'window_seconds'   => (int) env('AI_CORE_AUTO_ROTATE_WINDOW', 60),
        'min_failures'     => (int) env('AI_CORE_AUTO_ROTATE_THRESHOLD', 2),
    ],

    // ─── Cache cold warning (0.9.0) ───
    // Anthropic prompt cache TTL is 5 minutes. When a follow-up call to
    // the same session arrives after the window has closed, the user
    // pays the full input price for the entire prefix again.
    // Dispatcher::detectCacheCold() flags such cases on the result envelope
    // (`cache_warning: 'cache_likely_cold'`) so dashboards / hosts can
    // surface a "cache miss likely" badge without re-deriving the heuristic.
    //
    // Requires the host to:
    //   1. Pass a `session_id` on `Dispatcher::dispatch(['metadata' =>
    //      ['session_id' => $sessionId]])` so the lookup has a key.
    //   2. Implement `UsageRepository::findLatestForSession($sessionId,
    //      $backends)` returning the most recent matching row (or null).
    //      The bundled EloquentUsageRepository will gain this in a follow-up.
    //
    // Set `threshold_seconds` to 0 to disable the warning entirely.
    // TaskRunner fallback handoff. Per-call options override this block:
    //   'fallback_chain' => ['claude_cli', 'codex_cli', 'gemini_cli']
    //   'fallback_chain' => 'auto'
    //   'fallback_on' => ['rate limit', 'usage limit', 'quota', '429']
    //   'inherit_failure_context' => true
    //
    // Fallback always tries the requested primary backend first, so when the
    // primary's limit recovers, the next run naturally switches back.
    'task_fallback' => [
        'auto_enabled' => filter_var(
            env('AI_CORE_TASK_FALLBACK_AUTO', false),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        ) ?? false,
        'check_availability' => filter_var(
            env('AI_CORE_TASK_FALLBACK_CHECK_AVAILABILITY', false),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        ) ?? false,
        'chain' => array_values(array_filter(array_map('trim', explode(',', (string) env('AI_CORE_TASK_FALLBACK_CHAIN', ''))))),
        'auto_chain' => [
            'claude_cli',
            'codex_cli',
            'gemini_cli',
            'kimi_cli',
            'copilot_cli',
            'kiro_cli',
            'superagent',
            'anthropic_api',
            'openai_api',
            'gemini_api',
        ],
        'fallback_on' => [
            'rate limit',
            'rate_limit',
            'usage limit',
            'quota',
            'quota_exceeded',
            'exceeded your current quota',
            'too many requests',
            '429',
            'insufficient_quota',
            'billing',
            'budget',
            'limit reached',
            'usage_not_included',
        ],
        'inherit_failure_context' => filter_var(
            env('AI_CORE_TASK_FALLBACK_INHERIT_CONTEXT', true),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        ) ?? true,
    ],

    'cache_cold_warning' => [
        'threshold_seconds' => (int) env('AI_CORE_CACHE_COLD_THRESHOLD', 270),
    ],

    // ─── MCP server management ───
    'mcp' => [
        'enabled' => env('AI_CORE_MCP_ENABLED', true),
        // Directory where MCP server binaries get installed
        'install_dir' => env('AI_CORE_MCP_INSTALL_DIR', null),
        // 0.9.0 — Ordered fallback for `McpManager::readConfig()`. The
        // first existing file wins. Tokens `{project}` and `{home}` (also
        // `~`) expand at lookup time. Borrowed from jcode's three-layer
        // chain (`~/.jcode/mcp.json` → `.jcode/mcp.json` → `.claude/mcp.json`)
        // so an operator can drop a file at the location matching their
        // mental model and every CLI in the host picks it up.
        // Set to a non-empty array to override; leave commented out (or
        // set explicitly to null) to use the bundled defaults baked into
        // McpManager::resolveSearchPaths().
        'search_paths' => null,
        // Env var name used as a placeholder for the project root in
        // generated `.mcp.json` entries. When set (e.g. 'SUPERTEAM_ROOT'),
        // McpManager rewrites paths under projectRoot() to ${VAR}/<rel> and
        // writes bare command names ('node', 'uvx', 'php', …) instead of
        // resolved binary paths, so the file stays portable across machines
        // and users. Set to null (the default) to keep legacy behaviour of
        // writing absolute paths. The host's MCP runtime (Claude Code,
        // Codex, Gemini, …) must export this env var — typically via
        // `.claude/settings.local.json` — for the placeholder to expand.
        'portable_root_var' => env('AI_CORE_MCP_PORTABLE_ROOT_VAR', null),
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

        // ─── DeepSeek V4 (since SuperAgent 0.9.6) ───
        // Both rows are 1M-context MoE models — V4-Pro 49B active / 1.6T
        // total, V4-Flash 13B active / 284B total. Prices reflect the
        // DeepSeek 2026-04-24 launch sheet. The deprecated `deepseek-chat`
        // and `deepseek-reasoner` aliases retire 2026-07-24; route them to
        // the V4 successors here so cost dashboards keep working past the
        // hard cutover and the SDK's one-shot deprecation warning is the
        // user's only nudge.
        'deepseek-v4-pro'             => ['input' => 0.55,  'output' => 2.20],
        'deepseek-v4-flash'           => ['input' => 0.14,  'output' => 0.55],
        'deepseek-chat'               => ['input' => 0.14,  'output' => 0.55],
        'deepseek-reasoner'           => ['input' => 0.55,  'output' => 2.20],

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
