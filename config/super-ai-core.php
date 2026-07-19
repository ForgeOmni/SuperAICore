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

        // Mid-run HITL `ask_user` tool. Opt-in. When on, SuperAgentBackend
        // attaches the `AskUserTool` (writes to `ai_user_questions`, polls
        // for the answer, blocks the agent loop on user reply). UI lives
        // at `/processes/questions` — render an inline card per pending row.
        'ask_user_enabled'   => (bool) env('AI_CORE_TOOLS_ASK_USER', false),

        // SDK 1.0.5 `LSPTool` (`lsp`) — opencode-ported stdio JSON-RPC
        // language-server client. Exposes diagnostics / hover /
        // definition / touch actions against any of the 9 bundled
        // servers (phpactor, intelephense, gopls, rust-analyzer,
        // pyright, typescript-language-server, clangd,
        // bash-language-server, zls) detected by composer.json / go.mod
        // / Cargo.toml / package.json / etc. Lazy-loaded via SDK
        // BuiltinToolRegistry classMap, so this just adds 'lsp' to
        // load_tools on the implicit path. Default OFF because the
        // tool spawns a subprocess per (server, root-dir) pair; flip
        // on when the agent should be able to consult diagnostics
        // mid-loop rather than only via Bash.
        'lsp_enabled'        => (bool) env('AI_CORE_TOOLS_LSP', false),
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

    // ─── /model auto routing (SDK 0.9.8) ───
    // Wraps SDK's Routing\AutoModelStrategy via Services\AutoModelRouter.
    // The router escalates Flash → Pro on long context, deep tool chains,
    // explicit reasoning_effort=max, or system prompts that mention
    // review/audit/design/etc. Hosts can rebind Pro/Flash to other model
    // pairs (e.g. claude-opus / claude-haiku) without forking the SDK.
    'auto_model' => [
        'enabled'              => (bool) env('AI_CORE_AUTO_MODEL', true),
        'pro_model'            => env('AI_CORE_AUTO_MODEL_PRO', null),
        'flash_model'          => env('AI_CORE_AUTO_MODEL_FLASH', null),
        'long_context_tokens'  => (int) env('AI_CORE_AUTO_MODEL_LONG_CTX', 32_000),
        'tool_chain_threshold' => (int) env('AI_CORE_AUTO_MODEL_TOOL_DEPTH', 3),
        'pro_keywords'         => null,
        // Path to a SDK ScoreCatalog JSON file; when set, the catalog's
        // top-scoring model for the inferred intent dim overrides the
        // Pro/Flash heuristic. Useful when the host runs its own evals.
        'score_catalog_path'   => env('AI_CORE_AUTO_MODEL_SCORE_CATALOG', null),
    ],

    // ─── Cache-aware compaction (SDK 0.9.8) ───
    // When hosts drive their own ContextManager (long-running multi-turn
    // sessions), they should pull `CompressionStrategyFactory::build()`
    // to register a `CacheAwareCompressor` that pins the prompt-cache
    // prefix instead of clobbering it on every compaction round.
    'compression' => [
        'cache_aware' => (bool) env('AI_CORE_COMPRESSION_CACHE_AWARE', true),
        'pin_head'    => (int)  env('AI_CORE_COMPRESSION_PIN_HEAD', 4),
        'pin_system'  => (bool) env('AI_CORE_COMPRESSION_PIN_SYSTEM', true),

        // SDK 1.0.5 — opencode-style 7-section structured Markdown summary
        // template, ~30-50% smaller output than the default 9-section prose
        // summary and preserves blocked-item state across compactions.
        // Set to 'structured' to opt every dispatch into it; set to null
        // (default) to keep SDK behaviour. Per-call override remains
        // available via `options.summary_prompt`.
        'summary_prompt' => env('AI_CORE_COMPRESSION_SUMMARY_PROMPT', null),
    ],

    // ─── Shadow-git snapshots + per-file diff summaries (opencode-inspired) ───
    // SuperAgentBackend uses SuperAgent SDK's `GitShadowStore` to checkpoint
    // the worktree before + after each dispatch. SnapshotDiffService then
    // produces a structured `{additions, deletions, files, diffs[]}` envelope
    // that lands on the UsageLog row as `file_diff_summary`.
    //
    // `POST /usage/{id}/revert` reads `pre_snapshot` and calls
    // `GitShadowStore::restore()`. `super-ai-core:snapshot-prune` walks every
    // shadow repo under `~/.superagent/history/` and trims commits older
    // than `retention_days`.
    'snapshot' => [
        'enabled'        => (bool) env('AI_CORE_SNAPSHOT_ENABLED', true),
        // When null, resolveProjectRoot() falls back to base_path() → getcwd().
        // Set this when running SuperAICore from a service that should
        // checkpoint a different worktree (e.g. a multi-tenant runner).
        'project_root'   => env('AI_CORE_SNAPSHOT_PROJECT_ROOT', null),
        'retention_days' => (int) env('AI_CORE_SNAPSHOT_RETENTION_DAYS', 7),
        'max_file_mb'    => (float) env('AI_CORE_SNAPSHOT_MAX_FILE_MB', 2.0),
        // Allow operators to disable revert in shared deployments (the
        // route still exists but returns 403 when this is false).
        'revert_enabled' => (bool) env('AI_CORE_SNAPSHOT_REVERT_ENABLED', true),
    ],

    // ─── Session reminders (opencode-style synthetic prompt blocks) ───
    // Each rule has:
    //   - `when`:  array<string,string> — option/metadata key → value (or
    //             glob via fnmatch); ALL must match. Empty / omitted = always.
    //   - `text`:  the markdown block to prepend to the system prompt
    //   - `name`:  optional debug label
    // Rules fire in order and concatenate their text with a blank line
    // between them. RemindersResolver short-circuits when no rules match.
    // ─── SuperTeam Agent catalog ───
    // Roots scanned by SuperAICore\Services\AgentCatalog. Each path is a
    // directory containing Claude Code SubagentRegistry .md files
    // (frontmatter: name, description, model). The /super-ai-core/agents
    // page reads from these and groups by filename-prefix category.
    // When empty, AgentCatalog::fromConfig() falls back to
    // base_path('.claude/agents') then base_path('../.claude/agents').
    'agent_catalog' => [
        'paths' => array_filter([
            env('AI_CORE_AGENT_CATALOG_PATH', ''),
        ]),
    ],

    'reminders' => [
        'rules' => [
            // Example rule (commented to keep default behaviour byte-identical):
            //   ['name' => 'plan-mode-active',
            //    'when' => ['agent' => 'plan'],
            //    'text' => "## Plan mode active\nWrite the plan to `.superagent/plans/{session}.md`. Do NOT call any edit/write tool against the project worktree."],

            // 9Router-borrowed Caveman mode. Active when --caveman flag
            // is passed (smart/squad/auto) — injects a terse-prose
            // instruction that empirically saves 30-65% on output
            // tokens for reasoning-quick tasks. NOT recommended for
            // long-form writing or design work.
            [
                'name' => 'caveman-mode',
                'when' => ['caveman' => '1'],
                'text' => "## Caveman mode (output compression)\n\nRespond in minimal tokens. Technical prose only. Skip pleasantries, hedges, and summaries. Use bullets over paragraphs. Use code blocks instead of describing code. Skip 'I will now...' / 'Here's the...' preambles — just output the answer. Why use many word when few word do trick.",
            ],
        ],
    ],

    // ─── PTY long-lived shell sessions (P3-9 Phase 1) ───
    // Enable to surface the `/pty/sessions` endpoints. Phase 1 is
    // long-poll only (proc_open + flat log + cursor). Phase 2 will add
    // WebSocket streaming via Reverb; the wire shape stays the same.
    // Disabled by default because spawning long-lived processes from
    // an HTTP controller has clear failure modes — read the PtyService
    // docblock before opting in on a multi-tenant deployment.
    'pty' => [
        'enabled' => (bool) env('AI_CORE_PTY_ENABLED', false),
    ],

    // ─── Session share (P3-10) ───
    // When `remote_url` is set, ShareSessionService POSTs session-event
    // batches to that endpoint. Leave empty to disable sharing entirely
    // (the route returns 403). The `secret` is sent as a Bearer token.
    'share' => [
        'enabled'    => (bool) env('AI_CORE_SHARE_ENABLED', false),
        'remote_url' => env('AI_CORE_SHARE_REMOTE_URL', ''),
        'secret'     => env('AI_CORE_SHARE_SECRET', ''),
        // When `remote_url` is empty and `local_url_template` is set,
        // ShareSessionService falls back to a self-hosted share view
        // (the host's own SuperAICore can serve as the share viewer).
        'local_url_template' => env('AI_CORE_SHARE_LOCAL_URL_TEMPLATE', ''),
    ],

    // ─── Per-agent permission ruleset (opencode-inspired) ───
    // Each key maps an agent NAME to an opencode-style permission map.
    // The map keys are tool names; values are either:
    //   - 'allow' / 'deny' / 'ask' (broadcast for that tool)
    //   - array<string,string> of glob → action overrides
    // PermissionEvaluator turns this into allowed_tools / denied_tools on
    // the SuperAgentBackend. Plan-mode pattern lifted from opencode
    // `agent/agent.ts`:
    //
    //   'plan' => [
    //       '*'    => 'allow',
    //       'edit' => ['*' => 'deny', '*.md' => 'allow'],
    //       'write'=> ['*' => 'deny', '*.md' => 'allow'],
    //   ],
    'agents' => [
        // Default empty — host opt-in.
    ],

    // ─── Security primitives (SDK 0.9.8) ───
    'security' => [
        // UntrustedInput wrapping. SDK auto-tags goal objectives via
        // GoalManager; this flag controls host-side tagging for OTHER
        // free-form text (workspace plugin descriptions, MCP tool docs,
        // ad-hoc memory entries). Off only when a host wants strictly
        // raw prompts (tests, byte-identical dispatch comparisons).
        'untrusted_input_enabled' => (bool) env('AI_CORE_UNTRUSTED_INPUT', true),
    ],

    // ─── Sub-agent depth cap (SDK 0.9.8) ───
    'agents' => [
        // Maximum sub-agent recursion depth. SDK default is 5.
        // Negative / unset → SDK default; can also override via
        // SUPERAGENT_MAX_AGENT_DEPTH env var on a per-process basis.
        'max_depth' => (int) env('AI_CORE_AGENT_MAX_DEPTH', 0),
    ],

    // ─── Per-provider token-bucket rate limiter (SDK 0.9.8) ───
    // Key is the provider name (`anthropic` / `kimi` / `openai` / etc.);
    // `default` covers any provider without its own row. Empty array
    // disables rate limiting (the SDK has its own per-call retry on
    // 429, so this is mostly belt + suspenders for hosts that have
    // already been throttled by a provider).
    'rate_limits' => [
        'default'  => ['rate' => (float) env('AI_CORE_RL_DEFAULT_RATE', 8.0),  'burst' => (int) env('AI_CORE_RL_DEFAULT_BURST', 16)],
        // 'kimi'    => ['rate' => 5.0,  'burst' => 10],
        // 'openai'  => ['rate' => 16.0, 'burst' => 32],
        // 'deepseek'=> ['rate' => 8.0,  'burst' => 16],
    ],

    // ─── DeepSeek FIM (SDK 0.9.8) ───
    // Standalone API for prefix-completion / inline-fill use cases.
    'deepseek' => [
        'api_key' => env('DEEPSEEK_API_KEY', null),
    ],

    // ─── Squad multi-agent (SDK 1.0.0) ───
    // PeerOrchestrator-driven peer-to-peer pipeline with per-step
    // model tiering, optional cost cap with downshift, and on-disk
    // checkpoints for crash-resume.
    'squad' => [
        'enabled'        => (bool) env('AI_CORE_SQUAD_ENABLED', true),
        // Default tier map. Override per dispatch via options.tier_map.
        'tier_map' => [
            'trivial'  => ['provider' => 'anthropic', 'model' => 'claude-haiku-4-5'],
            'easy'     => ['provider' => 'deepseek',  'model' => 'deepseek-v4-flash'],
            'moderate' => ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6'],
            'hard'     => ['provider' => 'deepseek',  'model' => 'deepseek-v4-pro'],
            'expert'   => ['provider' => 'anthropic', 'model' => 'claude-opus-4-8'],
        ],
        'max_cost_usd'   => (float) env('AI_CORE_SQUAD_MAX_COST', 0),
        'checkpoint_dir' => env('AI_CORE_SQUAD_CHECKPOINT_DIR', null),
    ],

    // ─── Squad team library (TeamRegistry, SDK-bundled + overlays) ───
    // SDK ships ~20 production teams in vendor/forgeomni/superagent/
    // resources/squad-teams/. Hosts that want to add their own teams
    // (or override a bundled team by name) point here at one or more
    // directories of YAML team files. Later directories override
    // earlier ones — and the host can override the SDK's bundled
    // teams entirely by registering the same name.
    'squad_team_dirs' => array_values(array_filter(array_map('trim', explode(',', (string) env('AI_CORE_SQUAD_TEAM_DIRS', ''))))),

    // ─── Cross-layer mode bridges + CLI plan mode (P2-7) ───
    // - bridge_sdk_squad: reverse SDK squad bridge. When true,
    //   SuperAICore installs its CrossLayerDispatcher as SuperAgent SDK's
    //   default squad dispatcher via `SquadDispatcherRegistry`. This lets
    //   SDK squad calls (AutoModeAgent::runSquad, `superagent auto
    //   --squad` invoked in-process) route per-role steps onto
    //   SuperAICore CLI backends through `cli:<name>` provider tags —
    //   no per-call config required.
    // - plan: two-phase plan/build orchestrator. See CliPlanOrchestrator
    //   for the workflow contract.
    'modes' => [
        'bridge_sdk_squad' => (bool) env('AI_CORE_BRIDGE_SDK_SQUAD', true),
        'plan' => [
            'enabled'          => (bool)   env('AI_CORE_PLAN_ENABLED', true),
            'plan_backend'     => (string) env('AI_CORE_PLAN_BACKEND', 'cli:claude_cli'),
            'build_backend'    => (string) env('AI_CORE_PLAN_BUILD_BACKEND', 'cli:claude_cli'),
            'plan_dir'         => (string) env('AI_CORE_PLAN_DIR', '.superagent/plans'),
            // null = auto-detect (uses HITL when tools.ask_user_enabled,
            // else auto-approves so the orchestrator stays usable in CI)
            'auto_approve'     => env('AI_CORE_PLAN_AUTO_APPROVE', null),
            'approval_timeout' => (int)    env('AI_CORE_PLAN_APPROVAL_TIMEOUT', 600),
        ],
    ],

    // ─── CLI-layer auto/smart/squad (host-side mirror of SDK modes) ───
    // CrossLayerDispatcher routes every leaf step through one seam:
    //   - cli:<name>  → SuperAICore CLI backend
    //   - sdk:<name>  → SuperAgent SDK provider
    //   - auto/smart/squad → recurse into the matching CLI-layer mode
    // The three modes share difficulty scoring with SDK's TaskComplexity
    // so user mental model is consistent across layers.
    'cli_auto' => [
        'default_cli'     => env('AI_CORE_CLI_AUTO_DEFAULT', 'cli:claude_cli'),
        'smart_threshold' => (float) env('AI_CORE_CLI_AUTO_SMART_TH', 0.4),
        'squad_threshold' => (float) env('AI_CORE_CLI_AUTO_SQUAD_TH', 0.7),
        'prefer_squad'    => (bool)  env('AI_CORE_CLI_AUTO_PREFER_SQUAD', true),
    ],
    'cli_smart' => [
        // difficulty band → CLI backend tag (or sdk:/auto/smart/squad)
        'routing' => [
            'trivial'  => env('AI_CORE_CLI_SMART_TRIVIAL',  'cli:gemini_cli'),
            'easy'     => env('AI_CORE_CLI_SMART_EASY',     'cli:gemini_cli'),
            'moderate' => env('AI_CORE_CLI_SMART_MODERATE', 'cli:codex_cli'),
            'hard'     => env('AI_CORE_CLI_SMART_HARD',     'cli:claude_cli'),
            'expert'   => env('AI_CORE_CLI_SMART_EXPERT',   'cli:claude_cli'),
        ],
        'merge_provider'   => env('AI_CORE_CLI_SMART_MERGE',   'cli:claude_cli'),
        'default_provider' => env('AI_CORE_CLI_SMART_DEFAULT', 'cli:claude_cli'),
        'max_cost_usd'     => (float) env('AI_CORE_CLI_SMART_MAX_COST', 0),
    ],
    'cli_squad' => [
        // Default tier map; provider tags can mix cli:/sdk:/auto/smart/squad.
        'tier_map' => [
            'trivial'  => ['provider' => env('AI_CORE_CLI_SQUAD_TRIVIAL_PROV',  'cli:gemini_cli'),  'model' => env('AI_CORE_CLI_SQUAD_TRIVIAL_MODEL',  'gemini-2.5-flash')],
            'easy'     => ['provider' => env('AI_CORE_CLI_SQUAD_EASY_PROV',     'cli:gemini_cli'),  'model' => env('AI_CORE_CLI_SQUAD_EASY_MODEL',     'gemini-2.5-flash')],
            'moderate' => ['provider' => env('AI_CORE_CLI_SQUAD_MODERATE_PROV', 'cli:codex_cli'),   'model' => env('AI_CORE_CLI_SQUAD_MODERATE_MODEL', 'gpt-5.1')],
            'hard'     => ['provider' => env('AI_CORE_CLI_SQUAD_HARD_PROV',     'cli:claude_cli'),  'model' => env('AI_CORE_CLI_SQUAD_HARD_MODEL',     'claude-sonnet-4-6')],
            'expert'   => ['provider' => env('AI_CORE_CLI_SQUAD_EXPERT_PROV',   'cli:claude_cli'),  'model' => env('AI_CORE_CLI_SQUAD_EXPERT_MODEL',   'claude-opus-4-8')],
        ],
        'checkpoint_dir' => env('AI_CORE_CLI_SQUAD_CHECKPOINT_DIR', null),
        'max_cost_usd'   => (float) env('AI_CORE_CLI_SQUAD_MAX_COST', 0),
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
            // CLI dialect. Moonshot's kimi-code (verified through v0.27.0;
            // state in $KIMI_CODE_HOME, default ~/.kimi-code/) replaces the
            // legacy Python `MoonshotAI/kimi-cli` (state in ~/.kimi/), but
            // both publish the same `kimi` binary with DIFFERENT headless
            // flags + stream-json shape.
            //   'auto'      — (default) probe `kimi --help` once and adapt
            //                 (legacy advertises `--print`; kimi-code does not)
            //   'kimi-code' — force the new dialect (`--prompt`-driven)
            //   'kimi-cli'  — force the legacy dialect (`--print`-driven)
            // Pin a value during the transition if probing is undesirable.
            'variant' => env('AI_CORE_KIMI_CLI_VARIANT', 'auto'),
            // LEGACY kimi-cli only: its agentic loop is capped by a
            // `--max-steps-per-turn` flag (defaults to 500 in
            // ~/.kimi/config.toml). kimi-code removed the flag — the step
            // budget is config-driven (~/.kimi-code/config.toml) and this
            // value is ignored for that dialect.
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
        'qwen_cli' => [
            // QwenLM/qwen-code v0.16.0 (2026-05-21). Fork of gemini-cli;
            // OAuth flow EOL'd 2026-04-15 — API key only.
            'enabled' => env('AI_CORE_QWEN_CLI_ENABLED', true),
            'binary'  => env('QWEN_CLI_BIN', 'qwen'),
            'timeout' => 300,
        ],
        'cursor_cli' => [
            // Cursor Composer headless agent (`cursor-agent`). Subscription
            // engine — owns its own login (~/.cursor). Default model
            // composer-2.5-fast. `force` auto-approves tools for headless
            // runs (without it cursor-agent blocks on per-tool confirmation).
            'enabled' => env('AI_CORE_CURSOR_CLI_ENABLED', true),
            'binary'  => env('CURSOR_CLI_BIN', 'cursor-agent'),
            'timeout' => 300,
            'force'   => (bool) env('AI_CORE_CURSOR_FORCE', true),
        ],
        'grok_cli' => [
            // xAI Grok Build CLI (`grok`). Subscription engine — grok.com
            // login (~/.grok). Default model grok-build. `always_approve`
            // auto-approves tools for headless runs. Distinct from the
            // metered xAI API provider (superagent backend, `grok` type).
            'enabled'        => env('AI_CORE_GROK_CLI_ENABLED', true),
            'binary'         => env('GROK_CLI_BIN', 'grok'),
            'timeout'        => 300,
            'always_approve' => (bool) env('AI_CORE_GROK_ALWAYS_APPROVE', true),
        ],
        'antigravity_cli' => [
            // Google Antigravity CLI (`agy`, verified 1.1.4). Subscription
            // engine — Google-account sign-in via the interactive TUI
            // (shared ~/.gemini/oauth_creds.json; state in
            // ~/.gemini/antigravity-cli/). The official successor to
            // gemini-cli's retired consumer tiers (individual OAuth dead
            // since 2026-06-18). Plain-text print mode; models span Gemini
            // 3.5/3.1, Claude 4.6 and GPT-OSS via AntigravityModelResolver.
            'enabled' => env('AI_CORE_ANTIGRAVITY_CLI_ENABLED', true),
            'binary'  => env('ANTIGRAVITY_CLI_BIN', 'agy'),
            'timeout' => 300,
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
        'squad' => [
            'enabled' => env('AI_CORE_SQUAD_BACKEND_ENABLED', true),
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

    // ─── Tracing ring buffer (magic-trace inspired) ───
    // Always-on, lock-free, in-process ring of trace events covering every
    // Dispatcher LLM/tool call plus the operator-relevant moments (cache
    // miss warning, auto-rotate, provider disabled, soft timeout). The ring
    // is dumped to disk only on triggers:
    //   - QuotaExceededException / null-result error
    //   - auto_rotate fires (covers the silent-rotation observability gap)
    //   - `php artisan dispatcher:dump-trace` manual flush
    //
    // Output: Chrome Trace Event JSON, viewable in chrome://tracing,
    // https://ui.perfetto.dev, or the bundled SuperTeam template at
    // .claude/design-system/templates/trace-viewer.html. The wire contract
    // is shared across SuperAgent / SuperAICore / SuperTeam — see SuperTeam
    // .claude/refs/ref-trace-format.md.
    //
    // Disabling tracing turns every emit into a no-op; the file system is
    // never touched. Ring size is per-process and constant memory — 1024
    // events ≈ ~150 KB of structured records.
    'tracing' => [
        'enabled'      => filter_var(
            env('AI_CORE_TRACE_ENABLED', true),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        ) ?? true,
        'ring_size'    => (int) env('AI_CORE_TRACE_RING_SIZE', 1024),
        // When null, resolves to storage_path('app/superaicore/traces').
        'storage_path' => env('AI_CORE_TRACE_STORAGE_PATH', null),
        // Auto-dump triggers; each can be toggled independently.
        'dump_on' => [
            'error'    => (bool) env('AI_CORE_TRACE_DUMP_ON_ERROR', true),
            'rotate'   => (bool) env('AI_CORE_TRACE_DUMP_ON_ROTATE', true),
            'timeout'  => (bool) env('AI_CORE_TRACE_DUMP_ON_TIMEOUT', true),
        ],
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
    //   'fallback_profile' => 'coding'
    //   'fallback_on' => ['rate limit', 'usage limit', 'quota', '429']
    //   'inherit_failure_context' => true
    //
    // Fallback always tries the requested primary backend first, so when the
    // primary's limit recovers, the next run naturally switches back.
    // Workload maps let hosts keep different policies for coding, research,
    // summarisation, or background maintenance without branching in callers.
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
        'chains_by_profile' => [
            'coding' => ['claude_cli', 'codex_cli', 'gemini_cli', 'antigravity_cli'],
            'research' => ['claude_cli', 'kimi_cli', 'gemini_cli', 'antigravity_cli'],
            'summarise' => ['claude_cli', 'kimi_cli', 'gemini_cli', 'antigravity_cli'],
            'maintenance' => ['codex_cli', 'gemini_cli', 'antigravity_cli', 'openai_api'],
            'cheap' => ['gemini_cli', 'antigravity_cli', 'kimi_cli', 'openai_api'],
            'fast' => ['codex_cli', 'gemini_cli', 'antigravity_cli', 'openai_api'],
            'headless' => ['anthropic_api', 'openai_api', 'gemini_api'],
        ],
        'chains_by_task_type' => [
            // 'tasks.run' => ['claude_cli', 'codex_cli', 'gemini_cli'],
        ],
        'chains_by_capability' => [
            // 'summarise' => ['claude_cli', 'kimi_cli'],
            // 'code' => ['claude_cli', 'codex_cli', 'gemini_cli'],
        ],
        // `antigravity_cli` rides directly behind `gemini_cli` in every
        // chain: it is Google's successor to gemini-cli's retired consumer
        // tiers, so hosts whose gemini OAuth died (IneligibleTierError)
        // fall through to the same models via the agy subscription.
        'chains_by_metadata' => [
            'task_kind' => [
                'coding' => ['claude_cli', 'codex_cli', 'gemini_cli', 'antigravity_cli'],
                'research' => ['claude_cli', 'kimi_cli', 'gemini_cli', 'antigravity_cli'],
                'summarise' => ['claude_cli', 'kimi_cli'],
            ],
            'priority' => [
                'cheap' => ['gemini_cli', 'antigravity_cli', 'kimi_cli', 'openai_api'],
                'fast' => ['codex_cli', 'gemini_cli', 'antigravity_cli', 'openai_api'],
            ],
            'requires_tools' => [
                'true' => ['claude_cli', 'codex_cli', 'gemini_cli', 'antigravity_cli'],
                'false' => ['anthropic_api', 'openai_api', 'gemini_api'],
            ],
        ],
        'max_attempts' => (int) env('AI_CORE_TASK_FALLBACK_MAX_ATTEMPTS', 0),
        'max_cost_usd' => (float) env('AI_CORE_TASK_FALLBACK_MAX_COST_USD', 0),
        'backoff_ms' => (int) env('AI_CORE_TASK_FALLBACK_BACKOFF_MS', 0),
        'backoff_strategy' => env('AI_CORE_TASK_FALLBACK_BACKOFF_STRATEGY', 'fixed'),
        'success_min_chars' => (int) env('AI_CORE_TASK_FALLBACK_SUCCESS_MIN_CHARS', 0),
        'success_forbidden_patterns' => array_values(array_filter(array_map('trim', explode(',', (string) env('AI_CORE_TASK_FALLBACK_SUCCESS_FORBIDDEN', ''))))),
        'cooldown' => [
            'enabled' => filter_var(
                env('AI_CORE_TASK_FALLBACK_COOLDOWN', false),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE,
            ) ?? false,
            'seconds' => (int) env('AI_CORE_TASK_FALLBACK_COOLDOWN_SECONDS', 300),
            'min_failures' => (int) env('AI_CORE_TASK_FALLBACK_COOLDOWN_MIN_FAILURES', 1),
        ],
        'failure_classes' => [
            'quota' => ['quota', 'quota_exceeded', 'insufficient_quota', 'usage_not_included', 'billing', 'budget'],
            'rate_limit' => ['rate limit', 'rate_limit', 'too many requests', '429', 'limit reached'],
            'auth' => ['unauthorized', 'forbidden', 'invalid api key', 'not signed in', 'login required'],
            'tool_policy' => ['permission denied', 'policy', 'not allowed', 'approval required'],
            'validation' => ['invalid prompt', 'missing required', 'validation'],
            'network' => ['timeout', 'connection refused', 'could not resolve', 'network'],
        ],
        'auto_chain' => [
            'claude_cli',
            'codex_cli',
            'gemini_cli',
            'antigravity_cli',
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

    // ─── Alias dispatch (ai-dispatch parity wave) ───
    // Powers `superaicore send/resume/runs/aliases/preferences/doctor`.
    'dispatch' => [
        // User alias pool merged over AliasRouter::BUILTIN — one alias maps
        // to an ordered candidate list `send` walks with transparent
        // degradation. Accepts full maps, compact 'backend:model' strings,
        // or a single string:
        //
        //   'aliases' => [
        //       'reviewer' => [
        //           ['backend' => 'claude_cli', 'model' => 'opus'],
        //           'gemini_cli:pro',
        //       ],
        //       'mimo' => 'superagent:mimo-v2.5-pro',
        //   ],
        'aliases' => [],

        // Failure classes (see task_fallback.failure_classes taxonomy)
        // that let `send` fall through to the next alias candidate.
        // Anything else — tool_policy, validation, unmatched runtime
        // errors — fails closed so fallback never hides a broken task.
        'retry_on_classes' => ['quota', 'rate_limit', 'auth', 'network'],

        // Run archive directory for `send`/`resume` results
        // (`runs list/show`). Null → AI_CORE_RUNS_PATH env, then
        // ~/.superaicore/runs.
        'runs_path' => env('AI_CORE_RUNS_PATH', null),

        // Natural-language scenario preferences the CALLING agent reads
        // before picking a send target (`superaicore preferences`).
        // Null → AI_CORE_PREFERENCES_PATH env, then
        // ~/.superaicore/preferences.md.
        'preferences_path' => env('AI_CORE_PREFERENCES_PATH', null),
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
        // Fable 5 (`claude-fable-5`) is Anthropic's most capable model
        // (since SDK 1.1.5) — 1M context, 128K max output, always-on
        // adaptive thinking, `output_config.effort` dial — priced above the
        // Opus tier at the official $10/$50 per 1M. Sonnet 5 ships alongside
        // on the same Claude-5-generation adaptive surface at the Sonnet
        // $3/$15 tier (intro $2/$10 through 2026-08-31 — keep the official
        // rate here; override per host if you want the promo reflected).
        // The current Opus line (4.5→4.8) is repriced to Anthropic's
        // official $5/$25 (SDK 1.1.5 corrected the stale $15/$75); only the
        // dated Opus 4.0 snapshot keeps the historical $15/$75.
        'claude-fable-5'              => ['input' => 10.00, 'output' => 50.00],
        'claude-sonnet-5'             => ['input' => 3.00,  'output' => 15.00],
        'claude-opus-4-8'             => ['input' => 5.00,  'output' => 25.00],
        'claude-opus-4-7'             => ['input' => 5.00,  'output' => 25.00],
        'claude-opus-4-6'             => ['input' => 5.00,  'output' => 25.00],
        'claude-opus-4-5'             => ['input' => 5.00,  'output' => 25.00],
        'claude-opus-4-20250514'      => ['input' => 15.00, 'output' => 75.00],
        'claude-sonnet-4-6'           => ['input' => 3.00,  'output' => 15.00],
        'claude-sonnet-4-5'           => ['input' => 3.00,  'output' => 15.00],
        'claude-sonnet-4-5-20241022'  => ['input' => 3.00,  'output' => 15.00],
        'claude-sonnet-4'             => ['input' => 3.00,  'output' => 15.00],
        'claude-haiku-4-5'            => ['input' => 1.00,  'output' => 5.00],
        'claude-haiku-4-5-20251001'   => ['input' => 1.00,  'output' => 5.00],

        // ─── OpenAI GPT ───
        // GPT-5.6 (GA 2026-07-09, SDK 1.1.6) replaces GPT-5.5 and retires the
        // mini/nano naming — Sol / Terra / Luna, all 1.05M context / 128K
        // output with vision, official OpenAI rates with a cached-input tier
        // (carried as `cache_read_input`). Note the long-context surcharge
        // (2× in / 1.5× out beyond 272K input) is NOT modelled here — hosts
        // with long-context traffic should override upward. `gpt-5` is also
        // corrected to its official $1.25/$10 (was a pre-release estimate);
        // the dotted 5.1 SKUs keep their estimate rates pending official ids.
        'gpt-5.6-sol'                 => ['input' => 5.00,  'output' => 30.00, 'cache_read_input' => 0.50],
        'gpt-5.6-terra'               => ['input' => 2.50,  'output' => 15.00, 'cache_read_input' => 0.25],
        'gpt-5.6-luna'                => ['input' => 1.00,  'output' => 6.00,  'cache_read_input' => 0.10],
        'gpt-5'                       => ['input' => 1.25,  'output' => 10.00],
        'gpt-5.1'                     => ['input' => 5.00,  'output' => 15.00],
        'gpt-5.1-codex'               => ['input' => 5.00,  'output' => 15.00],
        'gpt-5.1-codex-mini'          => ['input' => 0.50,  'output' => 2.00],
        'gpt-5-mini'                  => ['input' => 0.30,  'output' => 1.20],
        'gpt-4.1'                     => ['input' => 2.00,  'output' => 8.00],
        'gpt-4o'                      => ['input' => 2.50,  'output' => 10.00],
        'gpt-4o-mini'                 => ['input' => 0.15,  'output' => 0.60],

        // ─── DeepSeek V4 (since SuperAgent 0.9.6; repriced 1.1.1) ───
        // Both rows are 1M-context MoE models — V4-Pro 49B active / 1.6T
        // total, V4-Flash 13B active / 284B total. V4-Pro was repriced to
        // the current official rate in SuperAgent 1.1.1 (down from the stale
        // $0.55 / $2.20): $0.435 in (cache-miss) / $0.003625 in (cache-hit,
        // carried as `cache_read_input`) / $0.87 out per 1M. The deprecated
        // `deepseek-chat` and `deepseek-reasoner` aliases retire 2026-07-24;
        // they route to the V4 successors here (chat → flash, reasoner →
        // pro) so cost dashboards keep working past the hard cutover and the
        // SDK's one-shot deprecation warning is the user's only nudge.
        // V4-Flash output corrected $0.55 → $0.28 in SDK 1.1.6 (official
        // sheet), with a $0.0028 cache-hit input tier; V4 GA mid-July brings
        // peak-hour 2× pricing — these are the off-peak base rates.
        'deepseek-v4-pro'             => ['input' => 0.435, 'output' => 0.87, 'cache_read_input' => 0.003625],
        'deepseek-v4-flash'           => ['input' => 0.14,  'output' => 0.28, 'cache_read_input' => 0.0028],
        'deepseek-chat'               => ['input' => 0.14,  'output' => 0.28, 'cache_read_input' => 0.0028],
        'deepseek-reasoner'           => ['input' => 0.435, 'output' => 0.87, 'cache_read_input' => 0.003625],

        // ─── MiniMax (native, since SuperAgent 1.1.1) ───
        // MiniMax shipped M3 on 2026-06-01 — an MSA-architecture flagship:
        // 1M context, 512K max output, native image *and* video input,
        // single-model interleaved thinking. The bare `minimax` shorthand
        // and the zero-config default now resolve to M3; M2.7 stays
        // reachable by id and the `m2` / `minimax-m2` aliases. The launch
        // promo became MiniMax's permanent tiered price (SDK 1.1.6): $0.30
        // in / $1.20 out per 1M for ≤512K input (cache-read $0.06; $0.60 /
        // $2.40 above 512K; priority tier 1.5× — the >512K tier is NOT
        // modelled here, override upward for long-context traffic).
        // The SDK's ModelCatalog carries these rows too, so unlisted MiniMax
        // SKUs still resolve — these explicit entries keep cost dashboards
        // accurate offline without a catalog round-trip.
        'MiniMax-M3'                  => ['input' => 0.30,  'output' => 1.20, 'cache_read_input' => 0.06],
        'MiniMax-M2.7'                => ['input' => 0.30,  'output' => 1.20],
        'MiniMax-M2.5'                => ['input' => 0.30,  'output' => 1.20],
        'MiniMax-M2'                  => ['input' => 0.30,  'output' => 1.20],

        // ─── Z.ai GLM (native, GLM-5.2 since SuperAgent 1.1.2) ───
        // GLM-5.2 is Z.ai's coding-first agentic flagship — 1M context, 128K
        // max output, text-only, with a new `reasoning_effort` dial on top of
        // the binary thinking toggle (drive both via the SDK's generic
        // `reasoning_effort` / `thinking` options; they route through
        // SuperAgentBackend untouched). GLM-5.1 is the 200K-context
        // long-horizon sibling; both bill the same official Z.ai PAYG rate of
        // $1.40 in / $4.40 out, with a $0.26 cache-hit input tier (cache
        // storage currently limited-time free). GLM-5 stays reachable by id at
        // its earlier $1.00 / $3.20 rate. The SDK's ModelCatalog carries these
        // rows too, so unlisted GLM SKUs still resolve — these explicit entries
        // keep cost dashboards accurate offline without a catalog round-trip.
        'glm-5.2'                     => ['input' => 1.40,  'output' => 4.40, 'cache_read_input' => 0.26],
        'glm-5.1'                     => ['input' => 1.40,  'output' => 4.40, 'cache_read_input' => 0.26],
        'glm-5'                       => ['input' => 1.00,  'output' => 3.20],
        // Turbo pair at the official Z.ai $1.20 / $4 rate (SDK 1.1.6
        // correction); glm-5v-turbo is the multimodal sibling.
        'glm-5-turbo'                 => ['input' => 1.20,  'output' => 4.00, 'cache_read_input' => 0.24],
        'glm-5v-turbo'                => ['input' => 1.20,  'output' => 4.00],

        // ─── Google Gemini ───
        // Catalog corrected to reality in SDK 1.1.6: `gemini-3.5-pro` and
        // `gemini-3.5-flash-lite` never publicly shipped and carry no rows;
        // `gemini-3.5-flash` is the actual flagship at the official $1.50 /
        // $9 (cache-read $0.15). `gemini-3.1-pro-preview` ($2 / $12 ≤200K
        // tier; $4 / $18 above — the higher tier is NOT modelled here) owns
        // the `gemini-pro` alias; the retired `gemini-3-pro-preview` keeps
        // its historical $2 / $15 for old usage rows.
        'gemini-3.5-flash'            => ['input' => 1.50,  'output' => 9.00, 'cache_read_input' => 0.15],
        'gemini-3.1-pro-preview'      => ['input' => 2.00,  'output' => 12.00],
        'gemini-3.1-flash-lite'       => ['input' => 0.25,  'output' => 1.50],
        'gemini-3-pro-preview'        => ['input' => 2.00,  'output' => 15.00],
        'gemini-2.5-pro'              => ['input' => 1.25,  'output' => 10.00],
        'gemini-2.5-flash'            => ['input' => 0.30,  'output' => 2.50],
        'gemini-2.5-flash-lite'       => ['input' => 0.10,  'output' => 0.40],

        // ─── Alibaba Qwen (DashScope) ───
        // qwen3.7-max (2026-05-21): 1M context, native Anthropic API
        // protocol, $2.50/$7.50 per 1M. Verified against DashScope's
        // public pricing sheet 2026-05-22.
        // Earlier Qwen3 entries kept so cost dashboards still bucket
        // calls against legacy aliases correctly.
        // qwen3.7-plus corrected to the GA tiered price in SDK 1.1.6:
        // $0.40/$1.60 per 1M ≤256K input (multimodal image+video; the
        // >256K tier is not modelled here).
        'qwen3.7-max'                 => ['input' => 2.50,  'output' => 7.50],
        'qwen3.7-plus'                => ['input' => 0.40,  'output' => 1.60],
        'qwen3.6-max-preview'         => ['input' => 0.78,  'output' => 3.90],
        'qwen3-max'                   => ['input' => 0.78,  'output' => 3.90],
        'qwen3.5-plus'                => ['input' => 0.40,  'output' => 1.20],
        'qwen3.5-flash'               => ['input' => 0.15,  'output' => 0.60],
        'qwen3-coder-plus'            => ['input' => 0.40,  'output' => 1.20],
        // qwen3-coder-next (GA 2026-02-04): 262K context agentic coder, in the
        // `qwen` engine's available_models. Official Alibaba Model Studio
        // (International) tiered rate — base ≤32K tier modelled here; the
        // higher input-length tiers ($0.50/$2.50 for 32-128K; $0.80/$4 for
        // 128-256K) are noted but not modelled — override upward for
        // long-context traffic.
        'qwen3-coder-next'            => ['input' => 0.30,  'output' => 1.50],
        'qwen3-vl-plus'               => ['input' => 0.78,  'output' => 3.90],

        // ─── Moonshot Kimi (metered API; SDK 1.1.7) ───
        // kimi-k3 (2026-07-16) is Moonshot's new general flagship and the
        // SDK's zero-config `kimi` default (SDK 1.1.7 moved it off
        // kimi-k2-6) — a 2.8T open-weight MoE (16/896 experts active), 1M
        // context, always-on thinking, image+video input — at $3 in /
        // $0.30 cache-hit / $15 out per 1M. kimi-k2.7-code (2026-06-12) is
        // the separate coding flagship — 262K context / 32K output, thinking
        // forced on — at $0.95 in / $0.19 cache-hit / $4 out per 1M; the
        // highspeed variant bills exactly 2×. The retired kimi-k2-6 general
        // model stays reachable by id (resolves via the SDK ModelCatalog).
        // All distinct from the subscription `kimi` CLI engine (kimi-code
        // OAuth), which stays $0/token.
        'kimi-k3'                     => ['input' => 3.00,  'output' => 15.00, 'cache_read_input' => 0.30],
        'kimi-k2.7-code'              => ['input' => 0.95,  'output' => 4.00, 'cache_read_input' => 0.19],
        'kimi-k2.7-code-highspeed'    => ['input' => 1.90,  'output' => 8.00, 'cache_read_input' => 0.38],

        // ─── xAI Grok (SDK 1.0.8; grok-4.5 since 1.1.6) ───
        // grok-4.5 (released 2026-07-08) is the flagship and the SDK's
        // zero-config `grok` default — $2 in / $0.50 cached / $6 out per 1M
        // (2× beyond 200K prompt, not modelled here), 500K context, an
        // always-on three-level reasoning dial, and `x-grok-conv-id` cache
        // pinning. grok-4.3 (1M context) stays reachable by id. grok-4-fast
        // is the cheap 2M-context tier; grok-code-fast-1 targets agentic
        // coding. The SDK's ModelCatalog carries the same rows, so unlisted
        // Grok SKUs still resolve — these explicit entries keep cost
        // dashboards accurate without a catalog round-trip.
        'grok-4.5'                    => ['input' => 2.00,  'output' => 6.00, 'cache_read_input' => 0.50],
        'grok-4.3'                    => ['input' => 1.25,  'output' => 2.50],
        'grok-4.20'                   => ['input' => 1.25,  'output' => 2.50],
        'grok-build-0.1'              => ['input' => 1.00,  'output' => 2.00],
        'grok-4'                      => ['input' => 3.00,  'output' => 15.00],
        'grok-4-fast'                 => ['input' => 0.20,  'output' => 0.50],
        'grok-code-fast-1'            => ['input' => 0.20,  'output' => 1.50],
        'grok-3'                      => ['input' => 3.00,  'output' => 15.00],
        'grok-3-mini'                 => ['input' => 0.30,  'output' => 0.50],

        // ─── GitHub Copilot CLI (subscription billed; per-token cost is $0) ───
        // The dashboard reports these under a separate "Subscription engines"
        // section so monthly USD totals stay accurate.
        // Keys use copilot's own dot-separated ids — that's what the JSONL
        // `session.tools_updated` event reports, and the prefixed lookup in
        // CostCalculator::resolveRate() is exact-match. (Rows before SDK
        // 1.1.10 used Claude-CLI dash ids the copilot wire never emits.)
        'copilot:claude-sonnet-5'     => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'copilot:claude-sonnet-4.6'   => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'copilot:claude-sonnet-4.5'   => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'copilot:claude-fable-5'      => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'copilot:claude-opus-4.8'     => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'copilot:claude-opus-4.7'     => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'copilot:claude-opus-4.6'     => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'copilot:claude-opus-4.5'     => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'copilot:claude-haiku-4.5'    => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'copilot:gpt-5.6-sol'         => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'copilot:gpt-5.6-luna'        => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'copilot:gpt-5.6-terra'       => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'copilot:gpt-5.6'             => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'copilot:gpt-5.5'             => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'copilot:gpt-5.4'             => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'copilot:gpt-5.4-mini'        => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'copilot:gpt-5.3-codex'       => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'copilot:gpt-5-mini'          => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'copilot:gemini-3.5-flash'    => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'copilot:gemini-3.1-pro'      => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'copilot:kimi-k2.7-code'      => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'copilot:mai-code-1-flash'    => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'copilot:raptor-mini'         => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],

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

        // ─── Cursor Composer CLI (subscription billed; per-token cost is $0) ───
        // Routed via `cursor-agent` against the user's Cursor plan. The CLI
        // does not meter per-token, so usage rows emit $0 and the dashboard
        // groups them under "Subscription engines" (keyed by engine `cursor:`).
        'cursor:auto'                           => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'cursor:composer-2.5'                   => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'cursor:composer-2.5-fast'              => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'cursor:claude-fable-5-thinking-high'   => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'cursor:claude-sonnet-5-thinking-high'  => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'cursor:claude-opus-4-8-thinking-high'  => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'cursor:claude-opus-4-7-thinking-high'  => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'cursor:gpt-5.6-sol-high'               => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'cursor:gpt-5.5-high'                   => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'cursor:gpt-5.3-codex'                  => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'cursor:gpt-5.2'                        => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'cursor:cursor-grok-4.5-high'           => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        // Legacy grok slug (≤ cursor-agent 2026.05) — keeps historical usage rows priced.
        'cursor:grok-4.5-xhigh'                 => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'cursor:gemini-3.5-flash'               => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'cursor:kimi-k2.7-code'                 => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'cursor:glm-5.2-high'                   => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],

        // ─── Grok Build CLI (subscription billed; per-token cost is $0) ───
        // Routed via `grok` against a grok.com subscription. Distinct from
        // the metered xAI API rows above (`grok-4.5` etc.); the CLI channel
        // is keyed by engine `grok:` and contributes $0 to USD totals. The
        // grok CLI 0.2.93 Build plan routes grok-4.5 (default) +
        // grok-composer-2.5-fast; grok-build remains for older accounts.
        'grok:grok-4.5'               => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'grok:grok-composer-2.5-fast' => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
        'grok:grok-build'             => ['input' => 0, 'output' => 0, 'billing_model' => 'subscription'],
    ],

    // ─── SmartFlow (1.0.5) — cross-CLI dynamic workflows ───────────────────
    // The multi-CLI port of Claude Code's built-in Workflow engine. One set of
    // primitives (agent / parallel / pipeline / gate / council / budget /
    // schema) drives any registered backend, so a single flow can route its
    // planner to one CLI and its reviewers to another. Static flows ship as
    // YAML under resources/flows; rehearsal runs any flow end-to-end at zero
    // cost. See docs/smartflow.md. CLI: `superaicore flow ...`.
    'smartflow' => [
        'enabled'         => env('AI_CORE_SMARTFLOW_ENABLED', true),

        // Backend (CLI key) used for agent() calls that don't pin one
        // themselves (and whose persona doesn't either). Falls back to
        // `default_backend` then 'claude_cli'.
        'default_backend' => env('AI_CORE_SMARTFLOW_DEFAULT_BACKEND', null),
        'default_model'   => env('AI_CORE_SMARTFLOW_DEFAULT_MODEL', null),

        // Max simultaneous CLI workers for parallel()/pipeline() batches. The
        // process pool degrades to in-process when proc_open is unavailable.
        'concurrency'     => (int) env('AI_CORE_SMARTFLOW_CONCURRENCY', 4),

        // Where per-run call-ledgers (JSONL) are written for resume. Defaults to
        // ~/.superaicore/flows. Override with SUPERAICORE_FLOW_DIR too.
        'ledger_dir'      => env('AI_CORE_SMARTFLOW_LEDGER_DIR', null),

        // Extra directory (or list) to scan for user-authored YAML flows, on top
        // of resources/flows, ./.superaicore/flows and ./flows.
        'flows_dir'       => env('AI_CORE_SMARTFLOW_FLOWS_DIR', null),

        // Hard ceilings enforced by the per-run Budget (null = unbounded).
        'budget' => [
            'usd'    => env('AI_CORE_SMARTFLOW_BUDGET_USD', null),
            'tokens' => env('AI_CORE_SMARTFLOW_BUDGET_TOKENS', null),
        ],

        // Persona overrides, keyed by role id, e.g.
        //   'reviewer' => ['backend' => 'codex_cli', 'model' => '...'],
        // merged over the built-ins + resources/flows/personas/*.yaml.
        'personas' => [],
    ],
];
