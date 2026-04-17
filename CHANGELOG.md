# Changelog

All notable changes to `forgeomni/superaicore` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.5.5] — 2026-04-17

Cross-engine compatibility: host apps that ship Claude-Code-style skills can now run them end-to-end on codex-cli and gemini-cli. Combines the work previously tagged as v0.5.3 (BackendCapabilities) and v0.5.4 (SkillManager + MCP cross-sync + Spawn Plan); those tags have been withdrawn.

### Added

**BackendCapabilities — per-engine tool/MCP/agent adapter**
- `SuperAICore\Contracts\BackendCapabilities` — interface exposing `key`, `toolNameMap`, `supportsSubAgents`, `supportsMcp`, `streamFormat`, `mcpConfigPath`, `transformPrompt`, `renderMcpConfig`.
- `Capabilities/ClaudeCapabilities` — canonical: empty tool map, no prompt transform.
- `Capabilities/GeminiCapabilities` — tool-name translation (`WebSearch`→`google_web_search`, `Read`→`read_file`, `Agent`→explicit role-play instructions) + mandatory-behavior preamble that blocks the `codebase_investigator` shortcut on external-research tasks.
- `Capabilities/CodexCapabilities` — preamble flagging no-sub-agent + MCP-only web research, TOML renderer for `[mcp_servers.*]` blocks.
- `Capabilities/SuperAgentCapabilities` — mostly passthrough (SDK path, MCPs wired internally).
- `Services/CapabilityRegistry` — container singleton; falls back to Claude capabilities for unknown backend keys.

**SkillManager + MCP cross-sync**
- `Services/SkillManager` — syncs `.claude/skills/<name>` → `~/.codex/skills/` and `~/.gemini/skills/` via symlinks (recursive-copy fallback on Windows). Optional prefix so multi-host installations don't clobber.
- `Services/McpManager::syncAllBackends()` — single canonical MCP server list (from `codexMcpServers()`) rendered through each `BackendCapabilities` adapter into the native config file (`.claude/settings.json`, `.codex/config.toml` `[mcp_servers.*]`, `.gemini/settings.json`).

**Spawn Plan emulator (sub-agent primitive for CLIs without one)**
- `AgentSpawn/SpawnPlan` — DTO + JSON loader for `_spawn_plan.json`.
- `AgentSpawn/ChildRunner` — interface for per-engine child launchers.
- `AgentSpawn/GeminiChildRunner`, `AgentSpawn/CodexChildRunner` — build a non-interactive CLI child process per agent with combined system+task prompt piped on stdin, stream-json log, per-agent output subdir.
- `AgentSpawn/Orchestrator` — fans out plan entries in parallel up to `$plan->concurrency` (default 4), throttles via `isRunning` poll + 200ms sleep, returns per-agent exit/duration/log report.

### Changed
- `GeminiCapabilities` and `CodexCapabilities` preambles now instruct the model to write `_spawn_plan.json` and stop, instead of "play all roles sequentially" which was unreliable on Flash. The host handles Phase 2 orchestration and Phase 3 consolidation; the consolidation-pass prompt itself is authored by the host orchestrator (e.g. SuperTeam's `ExecuteTask`) — aicore provides the building blocks.

### Verified
- On SuperTeam: a Gemini Flash run that previously produced meta-analyses of the local Laravel codebase (4× `codebase_investigator` calls, zero web searches) now emits 10+ `google_web_search` calls and actually investigates the requested external subject.

## [0.5.2] — 2026-04-17

### Added
- **Gemini CLI as the fourth execution engine.** New backend adapters `gemini_cli` (spawns Google's `gemini` CLI) and `gemini_api` (HTTP against `generativelanguage.googleapis.com/v1beta/models/{model}:generateContent`). The "Gemini" engine accepts three provider types: `builtin` (local Google OAuth login), `google-ai` (Google AI Studio API key), `vertex` (Vertex AI via ADC passthrough through the CLI adapter).
- `SuperAICore\Services\GeminiModelResolver` — family-alias rewrites (`pro`/`flash`/`flash-lite` → current full id) + hand-maintained catalog consumed by the providers page fallback.
- `TYPE_GOOGLE_AI = 'google-ai'` provider type; `BACKEND_GEMINI = 'gemini'` added to `AiProvider::BACKENDS` and the `BACKEND_TYPES` matrix.
- `CliStatusDetector` now probes `gemini` on `$PATH`; providers page shows a Gemini card with `npm i -g @google/gemini-cli` as the install hint.
- `ProcessMonitor::DEFAULT_KEYWORDS` includes `gemini` so the process monitor picks up Gemini CLI invocations.
  gemini-sync- New env flags: `AI_CORE_GEMINI_CLI_ENABLED`, `AI_CORE_GEMINI_API_ENABLED`, `GEMINI_CLI_BIN`, `GEMINI_BASE_URL`.
- Gemini 2.5 pricing added to `config.model_pricing` (pro / flash / flash-lite).
- 10 new tests: `GeminiModelResolverTest` (5), plus extensions to `BackendRegistryTest`, `BackendStateTest`, `AiProviderMatrixTest`, `CostCalculatorTest`. Suite is now 44 tests / 119 assertions.

### Changed
- `BackendState::DISPATCHER_TO_ENGINE` extended: `gemini_cli` and `gemini_api` both map to the `gemini` engine, so the runtime on/off switch on `/providers` gates both adapters together.
- `Dispatcher::backendForProvider()` rewritten to dispatch on (engine, type) rather than type alone — needed because `vertex` is now ambiguous (Claude engine uses it for Vertex AI Anthropic, Gemini engine uses it for Vertex AI Gemini).
- `ProviderController::fallbackModels()` now takes the provider's backend so it can return the Gemini catalog for `gemini + vertex` without colliding with the Claude catalog used for `claude + vertex`.

## [0.5.1] — 2026-04-17

### Added
- Configurable table prefix (`config/super-ai-core.php:table_prefix`, env `AI_CORE_TABLE_PREFIX`). Default is `sac_`, so the eight package tables become `sac_ai_providers`, `sac_ai_services`, etc. Set to the empty string to keep the raw names.
- `SuperAICore\Support\TablePrefix` helper read by every migration; `SuperAICore\Models\Concerns\HasConfigurablePrefix` trait applied to all eight models.
- GitHub Actions CI (`.github/workflows/tests.yml`) — matrix across PHP 8.1/8.2/8.3 × Laravel 10/11/12 plus a dedicated `phpunit-no-superagent` job that exercises the SuperAgent-SDK-missing path.
- Real phpunit suite: `phpunit.xml`, `tests/TestCase.php` (on Orchestra Testbench), 9 test classes covering `TablePrefix`, `SuperAgentDetector`, `BackendRegistry`, `BackendState`, `CostCalculator`, `AiProvider` backend→type matrix, `Dispatcher` (with a stub `Backend`), and end-to-end migration + prefix round-trips. 34 tests, 85 assertions, all green on both SDK-present and SDK-missing matrices.

### Changed
- **BREAKING (pre-1.0)** — table names default to the `sac_` prefix. Hosts that installed `v0.5.0` migrations must either set `AI_CORE_TABLE_PREFIX=''` to keep the raw names or rename existing tables.

## [0.5.0] — 2026-04-16

Initial public release. The package consolidates the AI execution stack that used to live inside SuperTeam into a standalone Laravel package with a complete admin UI.

### Added

**Backends**
- `ClaudeCliBackend` — shells out to the `claude` CLI with configurable binary path and timeout.
- `CodexCliBackend` — shells out to the `codex` CLI.
- `SuperAgentBackend` — optional, delegates to `forgeomni/superagent` when the SDK is present; gracefully unavailable otherwise.
- `AnthropicApiBackend` — HTTP backend for the Anthropic Messages API.
- `OpenAiApiBackend` — HTTP backend for the OpenAI Chat Completions API.
- `BackendRegistry` with per-backend enable flags and env-driven configuration.
- `CliStatusDetector` — probes `$PATH` and reports detected CLI versions on the providers page.

**Dispatcher & routing**
- `Dispatcher` — unified entry point: resolves backend, provider, model, then executes and tracks.
- `ProviderResolver` — reads the active provider per task type from `AiProvider` / `AiServiceRouting`.
- `RoutingRepository`, `ProviderRepository`, `ServiceRepository`, `UsageRepository` interfaces, auto-bound to Eloquent implementations.
- `ClaudeModelResolver` / `CodexModelResolver` — resolve effective model from service config, provider default, or backend default.

**Persistence**
- Eight migrations: `integration_configs`, `ai_capabilities`, `ai_services`, `ai_service_routing`, `ai_providers`, `ai_model_settings`, `ai_usage_logs`, `ai_processes`.
- Matching Eloquent models under `SuperAICore\Models\*`.
- `UsageTracker` persists token counts, duration and USD cost on every dispatch.
- `CostCalculator` with a config-driven per-model pricing table (Claude 4.x, GPT-4o family).

**MCP & processes**
- `McpManager` — install, enable, disable and inspect MCP servers.
- `SystemToolManager` — registry of system-level tools exposed to agents.
- `ProcessMonitor` + `ProcessSourceRegistry` + pluggable `ProcessSource` contract; ships with `AiProcessSource` and tables for live process inspection.

**Admin UI**
- Blade views for integrations, providers, AI services, AI models, usage, costs and processes.
- Reusable partials for provider cards, config modals and provider-specific fields.
- `layouts/app.blade.php` with navbar, back-to-host link, and locale switcher.
- Trilingual interface: English, Simplified Chinese (`zh-CN`), French.
- `LocaleController` + cookie-based locale persistence compatible with host middleware.

**HTTP layer**
- Controllers: `IntegrationController`, `ProviderController`, `AiServiceController`, `UsageController`, `CostDashboardController`, `ProcessController`, `LocaleController`.
- Routes registered under a configurable prefix with `['web', 'auth']` middleware; can be disabled entirely via `AI_CORE_ROUTES_ENABLED`.

**CLI**
- Framework-agnostic binary at `bin/super-ai-core`.
- `list-backends` — report availability for every backend in the current environment.
- `call` — send a prompt through any backend with inline credentials and model override.

**Configuration**
- Publishable config (`config/super-ai-core.php`) with env flags for host integration, locale switcher, route/view registration, per-backend toggles, default backend, usage retention, MCP install dir, process monitor toggle, and per-model pricing.

**Documentation**
- Trilingual `README.md` / `README.zh-CN.md` / `README.fr.md`.
- Trilingual `INSTALL.md` / `INSTALL.zh-CN.md` / `INSTALL.fr.md`.
- MIT `LICENSE`.

### Known limitations

- Streaming responses are not yet exposed through the `Dispatcher` return shape.
- Process monitor is disabled by default and requires admin-only middleware wiring in the host app.
- Model pricing table covers Claude 4.x and GPT-4o only; other models fall back to zero cost and must be added to `config.model_pricing`.

[0.5.5]: https://github.com/forgeomni/SuperAICore/releases/tag/v0.5.5
[0.5.2]: https://github.com/forgeomni/SuperAICore/releases/tag/v0.5.2
[0.5.1]: https://github.com/forgeomni/SuperAICore/releases/tag/v0.5.1
[0.5.0]: https://github.com/forgeomni/SuperAICore/releases/tag/v0.5.0
