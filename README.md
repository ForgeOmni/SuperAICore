# forgeomni/superaicore

[![tests](https://github.com/ForgeOmni/SuperAICore/actions/workflows/tests.yml/badge.svg)](https://github.com/ForgeOmni/SuperAICore/actions/workflows/tests.yml)
[![license](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![php](https://img.shields.io/badge/php-%E2%89%A58.1-blue.svg)](composer.json)
[![laravel](https://img.shields.io/badge/laravel-10%20%7C%2011%20%7C%2012-orange.svg)](composer.json)

[English](README.md) · [简体中文](README.zh-CN.md) · [Français](README.fr.md)

Laravel package for unified AI execution across seven execution engines — **Claude Code CLI**, **Codex CLI**, **Gemini CLI**, **GitHub Copilot CLI**, **AWS Kiro CLI**, **Moonshot Kimi Code CLI**, and **SuperAgent SDK**. Ships with a framework-agnostic CLI, a capability-based dispatcher, MCP server management, usage tracking, cost analytics, and a complete admin UI.

Works standalone in a fresh Laravel install. The UI is optional and fully overridable — embed it inside a host application (e.g. SuperTeam) or disable it entirely when only the services are needed.

## Table of contents

- [Relationship to SuperAgent](#relationship-to-superagent)
- [Features](#features)
  - [Execution engines + provider types](#execution-engines--provider-types)
  - [Skill & sub-agent runner](#skill--sub-agent-runner)
  - [CLI installer & health](#cli-installer--health)
  - [Dispatcher & streaming](#dispatcher--streaming)
  - [Model catalog](#model-catalog)
  - [Provider type system](#provider-type-system)
  - [Usage tracking & cost](#usage-tracking--cost)
  - [Idempotency & tracing](#idempotency--tracing)
  - [MCP server manager](#mcp-server-manager)
  - [SuperAgent SDK integration](#superagent-sdk-integration)
  - [Agent-spawn hardening](#agent-spawn-hardening)
  - [Process monitor & admin UI](#process-monitor--admin-ui)
  - [Host integration](#host-integration)
- [Requirements](#requirements)
- [Install](#install)
- [CLI quick start](#cli-quick-start)
- [PHP quick start](#php-quick-start)
- [Architecture](#architecture)
- [Advanced usage](#advanced-usage)
- [Configuration](#configuration)
- [License](#license)

## Relationship to SuperAgent

`forgeomni/superaicore` and `forgeomni/superagent` are **sibling packages, not a parent and a child**:

- **SuperAgent** is a minimal in-process PHP SDK that drives a single LLM tool-use loop (one agent, one conversation).
- **SuperAICore** is a Laravel-wide orchestration layer — it picks a backend, resolves provider credentials, routes by capability, tracks usage, calculates cost, manages MCP servers, and ships an admin UI.

**SuperAICore does not require SuperAgent to function.** The SDK is one of several backends. The six CLI engines and the three HTTP backends work without it, and `SuperAgentBackend` gracefully reports itself as unavailable (`class_exists(Agent::class)` check) when the SDK is absent. Set `AI_CORE_SUPERAGENT_ENABLED=false` in your `.env` and the Dispatcher falls back to the remaining backends.

The `forgeomni/superagent` entry in `composer.json` is there so the SuperAgent backend compiles out of the box. If you never use it, remove it from `composer.json` before `composer install` in your host app — nothing else in SuperAICore imports the SuperAgent namespace.

## Features

Each feature below is tagged with the version it landed in. Features without a tag have been there since before 0.6.0.

### Execution engines + provider types

- **Seven execution engines** unified behind a single `Dispatcher` contract:
  - **Claude Code CLI** — provider types: `builtin` (local login), `anthropic`, `anthropic-proxy`, `bedrock`, `vertex`.
  - **Codex CLI** — `builtin` (ChatGPT login), `openai`, `openai-compatible`.
  - **Gemini CLI** — `builtin` (Google OAuth), `google-ai`, `vertex`.
  - **GitHub Copilot CLI** — `builtin` only (`copilot` binary owns OAuth/keychain/refresh). Reads `.claude/skills/` natively (zero-translation skill pass-through). **Subscription billed** — tracked separately on the dashboard.
  - **AWS Kiro CLI** *(since 0.6.1)* — `builtin` (local `kiro-cli login`), `kiro-api` (stored key injected as `KIRO_API_KEY` for headless). Ships the richest out-of-the-box CLI feature set — native agents, skills, MCP, and **subagent DAG orchestration** (no `SpawnPlan` emulation). Reads Claude's `SKILL.md` format verbatim. **Subscription billed** — credit-based Pro / Pro+ / Power plans.
  - **Moonshot Kimi Code CLI** *(since 0.6.8)* — `builtin` (`kimi login` OAuth via `auth.kimi.com`). Complements the SDK's direct-HTTP `KimiProvider` by covering the OAuth-subscription agentic-loop path, mirroring the `anthropic_api` ↔ `claude_cli` split. Native `Agent` fanout is honoured by default; opt into AICore's three-phase Pipeline via `use_native_agents=false`. **Subscription billed** — Moonshot Pro / Power.
  - **SuperAgent SDK** — provider types: `anthropic`, `anthropic-proxy`, `openai`, `openai-compatible`, plus `openai-responses` *(since 0.7.0)* and `lmstudio` *(since 0.7.0)*.
- **`openai-responses` provider type** *(since 0.7.0)* — routes through the SDK's `OpenAIResponsesProvider` against `/v1/responses`. Auto-detects Azure OpenAI deployments from the `base_url` pattern (adds `api-version=2025-04-01-preview` query string; override via `extra_config.azure_api_version`). When the row stores an `access_token` from a host-app ChatGPT-OAuth flow instead of an API key, the SDK flips the base URL to `chatgpt.com/backend-api/codex` so Plus / Pro / Business subscribers hit their subscription quota.
- **`lmstudio` provider type** *(since 0.7.0)* — local LM Studio server (default `http://localhost:1234`). OpenAI-compat wire; no real API key needed — the SDK synthesises a placeholder `Authorization` header.
- **Ten dispatcher adapters** behind the seven engines (`claude_cli`, `codex_cli`, `gemini_cli`, `copilot_cli`, `kiro_cli`, `kimi_cli`, `superagent`, `anthropic_api`, `openai_api`, `gemini_api`). CLI adapters when a provider uses `builtin` / `kiro-api`; HTTP adapters when it uses an API key. Addressable directly from the CLI when needed.
- **`EngineCatalog` single source of truth** — engine labels, icons, dispatcher backends, supported provider types, available models, and the declarative `ProcessSpec` (binary, version/auth-status args, prompt/output/model flags, default flags) live in one PHP service. Adding a new CLI engine means editing `EngineCatalog::seed()` and every picker updates automatically. Host apps override per-engine fields via `super-ai-core.engines` config. `modelOptions($key)` / `modelAliases($key)` *(since 0.5.9)* drive host-app model dropdowns.

### Skill & sub-agent runner

- **Skill & sub-agent discovery** — auto-discovers Claude Code skills (`.claude/skills/<name>/SKILL.md`) and sub-agents (`.claude/agents/<name>.md`) from three sources for skills (project > plugin > user) and two for agents. Exposes each as a first-class CLI subcommand (`skill:list`, `skill:run`, `agent:list`, `agent:run`).
- **Cross-backend native execution** — `--exec=native` runs a skill on the selected backend's CLI; `CompatibilityProbe` flags incompatible skills; `SkillBodyTranslator` rewrites canonical tool names (`` `Read` `` → `read_file`, …) and injects backend preamble (Gemini / Codex).
- **Side-effect-locking fallback chain** — `--exec=fallback --fallback-chain=gemini,claude` tries hops in order, skips incompatible ones, and hard-locks on the first hop that writes to cwd (mtime diff + stream-json `tool_use` events).
- **`gemini:sync`** — mirrors skills/agents into Gemini custom commands (`/skill:init`, `/agent:reviewer`). Respects manual edits via `~/.gemini/commands/.superaicore-manifest.json`.
- **`copilot:sync`** — mirrors agents into `~/.copilot/agents/*.agent.md`. Auto-fires before `agent:run --backend=copilot`.
- **`copilot:sync-hooks`** — merges Claude-style hooks (`.claude/settings.json:hooks`) into Copilot's `~/.copilot/config.json:hooks`.
- **`copilot:fleet`** — runs the same task across N Copilot sub-agents concurrently, aggregates results, registers each child in the Process Monitor.
- **`kiro:sync`** *(since 0.6.1)* — translates Claude agent frontmatter into `~/.kiro/agents/*.json` for native Kiro DAG execution.
- **`kimi:sync`** *(since 0.6.8)* — translates `.claude/agents/*.md` tool lists into `~/.kimi/agents/*.yaml` + `~/.kimi/mcp.json`. `claude:mcp-sync` fans out to Kimi automatically.

### CLI installer & health

- **`cli:status`** — shows which engine CLIs are installed / logged in, plus install hints for anything missing.
- **`cli:install [backend] [--all-missing]`** — shells out to the canonical package manager (`npm` / `brew` / `script`) with confirmation by default. Explicit by design — no CLI ever auto-installs as a dispatch side-effect.
- **`api:status`** *(since 0.6.8)* — 5-second cURL probe against every direct-HTTP API provider (anthropic / openai / openrouter / gemini / kimi / qwen / glm / minimax). Returns `{ok, latency_ms, reason}` per provider so operators can tell auth rejections (401/403), network timeouts, and missing keys apart at a glance. `--all` / `--providers=a,b,c` / `--json` flags. Parallel sibling of `cli:status` for direct-HTTP providers.

### Dispatcher & streaming

- **Capability-based routing** — `Dispatcher::dispatch(['task_type' => 'tasks.run', 'capability' => 'summarise'])` resolves the right backend + provider credentials via `RoutingRepository` → `ProviderResolver` → fallback chain.
- **`Contracts\StreamingBackend`** *(since 0.6.6)* — every CLI backend streams chunks through an `onChunk` callback while tee'ing to disk and registering an `ai_processes` row for the Monitor UI. `Dispatcher::dispatch(['stream' => true, ...])` opts in transparently. Honours per-call `timeout` / `idle_timeout` / `mcp_mode` (`'empty'` for claude prevents global MCPs from blocking exit). See `docs/streaming-backends.md`.
- **`Runner\TaskRunner` — one-call task execution** *(since 0.6.6)* — drop-in wrapper around `Dispatcher::dispatch(['stream' => true, ...])` that returns a typed `TaskResultEnvelope` (success / output / summary / usage / cost / log file / spawn report). Replaces ~150 lines of host-side "build prompt → spawn → tee log → extract usage → wrap result" glue with one call. Identical across all 6 CLIs. See `docs/task-runner-quickstart.md`.
- **`AgentSpawn\Pipeline` — spawn-plan protocol for codex/gemini** *(since 0.6.6)* — three-phase choreography (preamble → parallel fanout → consolidation re-call) upstream in SuperAICore. `TaskRunner` activates it when `spawn_plan_dir` is passed. New CLIs that need the protocol implement `BackendCapabilities::spawnPreamble()` + `consolidationPrompt()` once and inherit the rest. See `docs/spawn-plan-protocol.md`.
- **Per-call `cwd` on every CLI** *(since 0.6.7)* — hosts whose PHP process runs from `web/public` can still spawn a `claude` that finds `artisan` + `.claude/` at the project root. Claude-only options (`permission_mode`, `allowed_tools`, `session_id`) let headless callers bypass interactive approval prompts and restrict the tool surface.
- **Headless Claude from PHP-FPM now works** *(since 0.6.7)* — `ClaudeCliBackend` scrubs `CLAUDECODE` / `CLAUDE_CODE_ENTRYPOINT` / … from the child env so a Laravel server launched from a parent `claude` shell no longer trips claude's recursion guard. On macOS, `builtin` auth falls back to reading the OAuth token via `security find-generic-password` and injecting it as `ANTHROPIC_API_KEY` — the only path that works for web workers.
- **`Contracts\ScriptedSpawnBackend`** *(since 0.7.1)* — sibling of `StreamingBackend` for hosts that detach the child (nohup/background job) and poll the log asynchronously. `prepareScriptedProcess([...])` returns a configured `Symfony\Component\Process\Process` that reads `prompt_file` via stdin, tees combined stdout+stderr to `log_file`, applies env scrub + capability transforms (Gemini tool-name rewrite), and honours `timeout`/`idle_timeout`. `streamChat($prompt, $onChunk, $options)` is the blocking one-shot sibling — backend owns argv composition, prompt-vs-argv passing, output parsing, and ANSI stripping (Kiro/Copilot). All six CLI backends (claude/codex/gemini/copilot/kiro/kimi) implement the contract on 0.7.1; hosts collapse a per-backend `match` statement into one polymorphic call via `BackendRegistry::forEngine($engineKey)`. `Support\CliBinaryLocator` (singleton) centralises filesystem probing for CLI binaries (`~/.npm-global/bin`, `/opt/homebrew/bin`, nvm paths, Windows `%APPDATA%/npm`). `Backends\Concerns\BuildsScriptedProcess` trait supplies shared wrapper-script helpers for implementers. See [docs/host-spawn-uplift-roadmap.md](docs/host-spawn-uplift-roadmap.md).

### Model catalog

- **Dynamic model catalog** *(since 0.6.0)* — `CostCalculator`, `ClaudeModelResolver`, `GeminiModelResolver`, and `EngineCatalog::seed()`'s `available_models` all fall through to SuperAgent's `ModelCatalog` (bundled `resources/models.json` + user override at `~/.superagent/models.json`).
- **`super-ai-core:models update`** *(since 0.6.0)* — fetches `$SUPERAGENT_MODELS_URL` and refreshes pricing + model lists for every Anthropic / OpenAI / Gemini / Bedrock / OpenRouter row without `composer update`.
- **`super-ai-core:models refresh [--provider <p>]`** *(since 0.6.9)* — pulls each provider's live `GET /models` endpoint into a per-provider overlay cache at `~/.superagent/models-cache/<provider>.json`. Supports anthropic / openai / openrouter / kimi / glm / minimax / qwen. Overlay sits above the user override but below runtime `register()`, so bundled pricing is preserved when the vendor's `/models` omits rates (usually the case). `status` gains a `refresh cache` row.

### Provider type system

- **`ProviderTypeRegistry` + `ProviderEnvBuilder`** *(since 0.6.2)* — every provider type (Anthropic / OpenAI / Google / Kiro / …) lives in a single bundled registry carrying its label, icon, form fields, env-var name, base-url env, allowed backends, and `extra_config → env` map. One source of truth for `/providers` UI + CLI backend env injection + `AiProvider::requiresApiKey()`. Host apps override via `super-ai-core.provider_types`. New types surface on `composer update` with zero code changes.
- **`sdkProvider` on the descriptor** *(since 0.7.0)* — wrapper types (`anthropic-proxy`, `openai-compatible`) now declare which SDK `ProviderRegistry` key they route to. `SuperAgentBackend::buildAgent()` consults the descriptor when `provider_config.provider` isn't set, fixing a long-standing gap where wrapper types silently defaulted to `'anthropic'`.
- **`http_headers` / `env_http_headers` on the descriptor** *(since 0.7.0)* — declarative HTTP-header injection via the SDK's 0.9.1 `ChatCompletionsProvider` knobs. `http_headers` are literal; `env_http_headers` reference env vars and are silently dropped when the env var isn't set. Host apps inject `OpenAI-Project`, `LangSmith-Project`, `OpenRouter-App` etc. without package code changes.

### Usage tracking & cost

- **`ai_usage_logs`** — every call persists prompt/response tokens, duration, and cost. Rows also carry `shadow_cost_usd` + `billing_model` *(since 0.6.2)* so subscription engines (Copilot, Kiro, Claude Code builtin) surface a meaningful pay-as-you-go USD estimate instead of a $0 row.
- **Cache-aware shadow cost** *(since 0.6.5)* — `cache_read_tokens` priced at 0.1× and `cache_write_tokens` at 1.25× the base `input` rate (falls back to explicit catalog rows). Heavy-cache Claude sessions now match the real Anthropic invoice instead of over-reporting by ~10×.
- **CLI-reported `total_cost_usd`** *(since 0.6.5)* — when the backend envelope carries its own `total_cost_usd` (Claude CLI does), Dispatcher uses that figure as the billed cost and marks the row with `metadata.cost_source=cli_envelope`. Matters because only the CLI knows whether a given session is on a subscription or an API key.
- **`UsageRecorder` for host-side runners** *(since 0.6.2)* — thin façade over `UsageTracker` + `CostCalculator` that host apps spawning CLIs directly (e.g. `App\Services\ClaudeRunner`, PPT stage jobs) call after each turn to drop one `ai_usage_logs` row with `cost_usd` / `shadow_cost_usd` / `billing_model` auto-filled from the catalog.
- **`CliOutputParser`** — extracts `{text, model, input_tokens, output_tokens, …}` from captured stdout (`parseClaude()` / `parseCodex()` / `parseCopilot()` / `parseGemini()`) without constructing a full backend object.
- **`MonitoredProcess::runMonitoredAndRecord()`** *(since 0.6.5)* — opt-in trait method that buffers stdout, parses it, and writes an `ai_usage_logs` row on process exit. Parser failures never propagate — plain-text Codex/Copilot output gets a `debug`-level note instead of a row.
- **Cost dashboard** — per-model pricing, USD rollups, "By Task Type" card + per-row `usage`/`sub` billing-model badge + shadow-cost column on every breakdown *(since 0.6.2)*. Dashboards hide 0-token and `test_connection` rows by default.

### Idempotency & tracing

- **`ai_usage_logs.idempotency_key` 60s dedup window** *(since 0.6.6)* — `EloquentUsageRepository::record()` honours an `idempotency_key`; matching keys within 60s return the existing row id instead of inserting. `Dispatcher::dispatch()` auto-generates `"{backend}:{external_label}"` so hosts that accidentally double-record the same logical turn auto-collapse to one row. Migration: `php artisan migrate` adds a nullable column + composite index. See `docs/idempotency.md`.
- **Round-trip key through the SDK** *(since 0.7.0)* — Dispatcher now computes the key *before* `generate()` and forwards it to `SuperAgentBackend`, which threads it through `Agent::run($prompt, ['idempotency_key' => $k])` → `AgentResult::$idempotencyKey` (SDK 0.9.1). The backend echoes it back onto the envelope as `idempotency_key`; Dispatcher's write to `ai_usage_logs` prefers the envelope-echoed value. Net effect: hosts whose Dispatcher runs on a different PHP process than the write-through still observe the same key the SDK saw.
- **W3C `traceparent` / `tracestate` passthrough** *(since 0.7.0)* — pass `traceparent: '<w3c-string>'` on `Dispatcher::dispatch()` options. `SuperAgentBackend` forwards to `Agent::run()` options; the SDK projects it onto the Responses API's `client_metadata` envelope so OpenAI-side logs correlate with the host's distributed trace. `tracestate` and pre-built `TraceContext` instances also accepted. Empty strings are filtered.

### MCP server manager

- **UI-driven manager** — install, enable, and configure MCP servers from the admin UI.
- **Catalog-driven sync** *(since 0.6.8)* — `claude:mcp-sync` reads `.mcp-servers/mcp-catalog.json` + a thin `.claude/mcp-host.json` mapping and fans the right server set out to project `.mcp.json`, per-agent `mcpServers:` frontmatter blocks inside `.claude/agents/*.md`, and every installed CLI backend's user-scope config. `mcp:sync-backends` is the standalone entry point for hand-edited `.mcp.json` or file-watcher auto-sync. Non-destructive: user-edited files flag `user-edited` via a sha256 manifest and are left alone. See `docs/mcp-sync.md`.
- **OAuth helpers for mcp.json servers** *(since 0.6.9)* — `McpManager::oauthStatus(key)` / `oauthLogin(key)` / `oauthLogout(key)` wrap SDK 0.9.0's `McpOAuth` for MCP servers declaring an `oauth: {client_id, device_endpoint, token_endpoint, scope?}` block. Host UIs render an OAuth button per server.
- **Portable `.mcp.json` writes** *(since 0.8.1)* — opt in by setting `AI_CORE_MCP_PORTABLE_ROOT_VAR=SUPERTEAM_ROOT` (or any env var name your MCP runtime exports) and every `McpManager::install*()` writer emits bare commands (`node`, `php`, `uvx`, `uv`, `python`) plus `${SUPERTEAM_ROOT}/<rel>` placeholders for paths under the project root, so the generated file survives being copied / synced across machines / users / containers without re-pollution from `which()` and `PHP_BINARY`. Egress to per-machine targets (Codex `~/.codex/config.toml`, Gemini / Claude / Copilot / Kiro / Kimi user-scope MCP configs, `codex exec -c` runtime flags) materialises the placeholders back to absolute paths so backends that don't expand `${VAR}` still spawn correctly. Default stays `null` — legacy "absolute path everywhere" behaviour preserved for hosts that haven't opted in. See `docs/advanced-usage.md` §13.

### SuperAgent SDK integration

- **Real agentic loop** *(since 0.6.8)* — `SuperAgentBackend` honours `max_turns`, `max_cost_usd` → `Agent::withMaxBudget()`, `allowed_tools` / `denied_tools` filters, `mcp_config_file` (loads a `.mcp.json`, auto-disconnects in `finally{}`), and `provider_config.region` routed through `ProviderRegistry::createWithRegion()` for Kimi / Qwen / GLM / MiniMax regions. Envelope gains `usage.cache_read_input_tokens`, `usage.cache_creation_input_tokens`, `cost_usd` (SDK turn-summed), and `turns`.
- **`AgentTool` productivity forwarded** *(since 0.6.8)* — when callers opt into SDK sub-agent dispatch (`load_tools: ['agent', …]`), the envelope forwards `AgentTool` productivity info (`filesWritten`, `toolCallsByName`, `productivityWarning`, `status: completed|completed_empty`) under an optional `subagents` key.
- **Three 0.9.0 options forwarded** *(since 0.6.9)* — `extra_body` (deep-merged at the top level of every `ChatCompletionsProvider` request body), `features` (routed through SDK's `FeatureDispatcher`; useful keys: `prompt_cache_key.session_id`, `thinking.*`, `dashscope_cache_control`), `loop_detection: true|array` (wraps streaming handler in `LoopDetectionHarness`). Convenience shim: `prompt_cache_key: '<sessionId>'` accepted as session-id shorthand.
- **Classified `ProviderException` subclasses** *(since 0.7.0)* — `SuperAgentBackend::generate()` catches six typed SDK subclasses (`ContextWindowExceeded`, `QuotaExceeded`, `UsageNotIncluded`, `CyberPolicy`, `ServerOverloaded`, `InvalidPrompt`) each logged with a stable `error_class` tag + `retryable` flag. Contract unchanged (still returns `null`); a `logProviderError()` seam lets subclasses route on the classification.
- **SDK pinned to 0.9.1** *(since 0.7.0)* — Composer constraint `^0.9.1`. Round-trip `idempotency_key` through `AgentResult`, W3C `traceparent` passthrough, `http_headers` / `env_http_headers` injection, plus SDK-side `openai-responses` provider + Azure detection + LM Studio — all picked up without further SDK-level glue.

### Agent-spawn hardening

Five layered defences *(since 0.6.8)* so weak children (Gemini Flash, GLM Air) can't pollute the consolidator's view:

1. **`SpawnPlan::appendGuards()`** — host-injected per-agent guard block appended to every child's `task_prompt` (six rules: stay in lane, no consolidator filenames, language uniformity, extension whitelist, canonical `_signals/<name>.md` path, don't apologise for tool failures). Language-aware via CJK regex.
2. **`SpawnPlan::fromFile()` canonical ASCII `output_subdir`** — forces `output_subdir = agent.name` so Flash emitting `首席执行官` instead of `ceo-bezos` no longer breaks the audit walk.
3. **`Pipeline::cleanPrematureConsolidatorFiles()`** — before fanout, deletes any early `摘要.md` / `思维导图.md` / `流程图.md` / English variants at `$outputDir` top-level that the first-pass model wrote in violation of "emit plan and STOP".
4. **`Orchestrator::auditAgentOutput()`** — post-fanout, flags non-whitelisted extensions, consolidator-reserved filenames inside agent subdirs, and sibling-role sub-directories; warnings land in `report[N].warnings[]` without modifying disk. Per-agent plumbing (`run.log` / prompt / exec script) moves out of the user-facing output dir into `$TMPDIR`.
5. **Language-aware `SpawnConsolidationPrompt::build()`** — hard-codes the English → Chinese section-heading map for zh runs and bans fabricated error-filenames like `Error_No_Agent_Outputs_Found.md`. `GeminiCliBackend::parseJson()` tolerates Gemini's "YOLO mode is enabled." / "MCP issues detected." preamble.

### Process monitor & admin UI

- **Live-only Process Monitor** *(since 0.6.7)* — `AiProcessSource::list()` queries the live `ps aux` snapshot first and only emits `ai_processes` rows whose PID is alive. Finished / failed / killed runs disappear from the Monitor UI the moment their subprocess exits.
- **`host_owned_label_prefixes`** *(since 0.6.7)* — hosts with their own `ProcessSource` (e.g. SuperTeam's `task:` rows) claim a namespace so AiProcessSource doesn't double-render the same logical run.
- **Admin pages** — `/integrations`, `/providers`, `/services`, `/ai-models`, `/usage`, `/costs`, `/processes`. Admin-only `/processes`, disabled by default.

### Host integration

- **Trilingual UI** — English, Simplified Chinese, French, runtime-switchable.
- **Disable routes / views** — embed inside a parent app, swap the Blade layout, or reuse the back-link + locale switcher.
- **`BackendCapabilitiesDefaults` trait** *(since 0.6.6)* — host implementers `use` the trait to inherit safe no-op defaults for methods added in future minor releases. Host class stays satisfying the interface without code changes. See `docs/api-stability.md` for the full SemVer contract.

## Requirements

- PHP ≥ 8.1
- Laravel 10, 11, or 12
- Guzzle 7, Symfony Process 6/7

Optional, only when the respective backend is enabled:

- `claude` CLI on `$PATH` — `npm i -g @anthropic-ai/claude-code`
- `codex` CLI on `$PATH` — `brew install codex`
- `gemini` CLI on `$PATH` — `npm i -g @google/gemini-cli`
- `copilot` CLI on `$PATH` — `npm i -g @github/copilot` (then `copilot login`)
- `kiro-cli` on `$PATH` — [install from kiro.dev](https://kiro.dev/cli/) (then `kiro-cli login`, or set `KIRO_API_KEY` for headless Pro/Pro+/Power)
- `kimi` CLI on `$PATH` *(since 0.6.8)* — [install from kimi.com](https://kimi.com/code) (then `kimi login`)
- An Anthropic / OpenAI / Google AI Studio API key for the HTTP backends

Don't want to remember package names? Run `./vendor/bin/superaicore cli:status` to see what's missing and `./vendor/bin/superaicore cli:install --all-missing` to bootstrap everything (confirmation prompt by default).

## Install

```bash
composer require forgeomni/superaicore
php artisan vendor:publish --tag=super-ai-core-config
php artisan vendor:publish --tag=super-ai-core-migrations
php artisan migrate
```

Full step-by-step guide: [INSTALL.md](INSTALL.md).

## CLI quick start

```bash
# List Dispatcher adapters and their availability
./vendor/bin/superaicore list-backends

# Drive the seven engines from the CLI
./vendor/bin/superaicore call "Hello" --backend=claude_cli                              # Claude Code CLI (local login)
./vendor/bin/superaicore call "Hello" --backend=codex_cli                               # Codex CLI (ChatGPT login)
./vendor/bin/superaicore call "Hello" --backend=gemini_cli                              # Gemini CLI (Google OAuth)
./vendor/bin/superaicore call "Hello" --backend=copilot_cli                             # GitHub Copilot CLI (subscription)
./vendor/bin/superaicore call "Hello" --backend=kiro_cli                                # AWS Kiro CLI (subscription)
./vendor/bin/superaicore call "Hello" --backend=kimi_cli                                # Moonshot Kimi Code CLI (OAuth subscription)
./vendor/bin/superaicore call "Hello" --backend=superagent --api-key=sk-ant-...         # SuperAgent SDK

# Skip the CLI wrapper and hit the HTTP APIs directly
./vendor/bin/superaicore call "Hello" --backend=anthropic_api --api-key=sk-ant-...      # Claude engine, HTTP mode
./vendor/bin/superaicore call "Hello" --backend=openai_api --api-key=sk-...             # Codex engine, HTTP mode
./vendor/bin/superaicore call "Hello" --backend=gemini_api --api-key=AIza...            # Gemini engine, HTTP mode

# Health + install
./vendor/bin/superaicore cli:status                           # table of installed / version / auth / hint
./vendor/bin/superaicore api:status                           # 5s probe against every direct-HTTP API (0.6.8+)
./vendor/bin/superaicore cli:install --all-missing            # npm/brew/script install with confirmation

# Model catalog
./vendor/bin/superaicore super-ai-core:models status                     # sources, override mtime, total rows
./vendor/bin/superaicore super-ai-core:models list --provider=anthropic  # per-1M pricing + aliases
./vendor/bin/superaicore super-ai-core:models update                     # fetch $SUPERAGENT_MODELS_URL (0.6.0+)
./vendor/bin/superaicore super-ai-core:models refresh --provider=kimi    # live GET /models overlay (0.6.9+)
```

### Skill & sub-agent CLI

```bash
# Discover what's installed
./vendor/bin/superaicore skill:list
./vendor/bin/superaicore agent:list

# Run a skill on Claude (default)
./vendor/bin/superaicore skill:run init

# Native on Gemini — probe + translate + preamble
./vendor/bin/superaicore skill:run simplify --backend=gemini --exec=native

# Try Gemini first, fall back to Claude on incompatibility; hard-lock on cwd-touching hop
./vendor/bin/superaicore skill:run simplify --exec=fallback --fallback-chain=gemini,claude

# Run a sub-agent; backend inferred from its `model:` frontmatter
./vendor/bin/superaicore agent:run security-reviewer "audit this diff"

# Sync engines
./vendor/bin/superaicore gemini:sync                          # expose skills/agents as Gemini custom commands
./vendor/bin/superaicore copilot:sync                         # ~/.copilot/agents/*.agent.md
./vendor/bin/superaicore copilot:sync-hooks                   # merge Claude-style hooks into Copilot
./vendor/bin/superaicore kiro:sync --dry-run                  # ~/.kiro/agents/*.json (0.6.1+)
./vendor/bin/superaicore kimi:sync                            # ~/.kimi/agents/*.yaml + mcp.json (0.6.8+)

# Run the same task across N Copilot agents in parallel
./vendor/bin/superaicore copilot:fleet "refactor auth" --agents planner,reviewer,tester
```

## PHP quick start

### Long-running task — `TaskRunner` (since 0.6.6)

For anything where you want a tail-able log, a Process Monitor row, live UI previews, automatic usage recording, and optional spawn-plan emulation for codex/gemini:

```php
use SuperAICore\Runner\TaskRunner;

$envelope = app(TaskRunner::class)->run('claude_cli', $prompt, [
    'log_file'       => $logFile,
    'timeout'        => 7200,
    'idle_timeout'   => 1800,
    'mcp_mode'       => 'empty',
    'spawn_plan_dir' => $outputDir,
    'task_type'      => 'tasks.run',
    'capability'     => $task->type,
    'user_id'        => auth()->id(),
    'external_label' => "task:{$task->id}",
    'metadata'       => ['task_id' => $task->id],
    'onChunk'        => fn ($chunk) => $taskResult->updateQuietly(['preview' => $chunk]),
]);

if ($envelope->success) {
    $taskResult->update([
        'content'    => $envelope->summary,
        'raw_output' => $envelope->output,
        'metadata'   => ['usage' => $envelope->usage, 'cost_usd' => $envelope->costUsd],
    ]);
}
```

Returns a typed `TaskResultEnvelope` with `success` / `output` / `summary` / `usage` / `costUsd` / `shadowCostUsd` / `billingModel` / `logFile` / `usageLogId` / `spawnReport` / `error`. Identical API across all 6 CLI engines.

### Short call — `Dispatcher::dispatch()`

```php
use SuperAICore\Services\BackendRegistry;
use SuperAICore\Services\CostCalculator;
use SuperAICore\Services\Dispatcher;

$dispatcher = new Dispatcher(new BackendRegistry(), new CostCalculator());

$result = $dispatcher->dispatch([
    'prompt' => 'Hello',
    'backend' => 'anthropic_api',
    'provider_config' => ['api_key' => 'sk-ant-...'],
    'model' => 'claude-sonnet-4-5-20241022',
    'max_tokens' => 200,
]);

echo $result['text'];
```

Also accepts `'stream' => true` to opt into the same streaming path `TaskRunner` uses internally.

Advanced options (idempotency, tracing, SDK features, classified errors): see [docs/advanced-usage.md](docs/advanced-usage.md).

## Architecture

```
  Engines (user-facing)     Provider types                 Dispatcher adapters
  ────────────────────      ──────────────────────         ───────────────────
  Claude Code CLI ────────▶ builtin                  ────▶ claude_cli
                            anthropic / bedrock /    ────▶ anthropic_api
                            vertex / anthropic-proxy
  Codex CLI       ────────▶ builtin                  ────▶ codex_cli
                            openai / openai-compat   ────▶ openai_api
  Gemini CLI      ────────▶ builtin / vertex         ────▶ gemini_cli
                            google-ai                ────▶ gemini_api
  Copilot CLI     ────────▶ builtin                  ────▶ copilot_cli
  Kiro CLI        ────────▶ builtin / kiro-api       ────▶ kiro_cli
  Kimi Code CLI   ────────▶ builtin                  ────▶ kimi_cli
  SuperAgent SDK  ────────▶ anthropic(-proxy) /      ────▶ superagent
                            openai(-compatible) /
                            openai-responses /       (0.7.0+)
                            lmstudio                 (0.7.0+)

  Dispatcher ← BackendRegistry   (owns the 10 adapters above)
             ← ProviderResolver  (active provider from ProviderRepository)
             ← RoutingRepository (task_type + capability → service)
             ← UsageTracker      (writes to UsageRepository)
             ← CostCalculator    (model pricing → USD)
```

All repositories are interfaces. The service provider auto-binds Eloquent implementations; swap them for JSON files, Redis, or an external API without touching the dispatcher.

## Advanced usage

- **[Advanced usage guide](docs/advanced-usage.md)** — idempotency round-trip, W3C trace context, classified provider exceptions, `openai-responses` + Azure OpenAI + ChatGPT OAuth, LM Studio, `http_headers` / `env_http_headers` overrides, SDK features (`extra_body` / `features` / `loop_detection`), `ScriptedSpawnBackend` host migration.
- **[Task runner quickstart](docs/task-runner-quickstart.md)** — full `TaskRunner` option reference.
- **[Streaming backends](docs/streaming-backends.md)** — `mcp_mode`, per-backend stream formats, `onChunk`.
- **[Spawn plan protocol](docs/spawn-plan-protocol.md)** — codex/gemini agent emulation.
- **[Host spawn uplift roadmap](docs/host-spawn-uplift-roadmap.md)** — why `ScriptedSpawnBackend` exists + the 700-line glue it replaces.
- **[Idempotency](docs/idempotency.md)** — 60s dedup window, auto-key derivation.
- **[MCP sync](docs/mcp-sync.md)** — catalog + host map → every backend.
- **[API stability](docs/api-stability.md)** — the SemVer contract.

## Configuration

The published config (`config/super-ai-core.php`) covers host integration, locale switcher, route/view registration, per-backend toggles, default backend, usage retention, MCP directory, process monitor toggle, and per-model pricing. See inline comments for every key.

## License

MIT. See [LICENSE](LICENSE).
