# Changelog

All notable changes to `forgeomni/superaicore` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[0.5.0]: https://github.com/forgeomni/SuperAICore/releases/tag/v0.5.0
