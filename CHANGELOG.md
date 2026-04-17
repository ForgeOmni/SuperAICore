# Changelog

All notable changes to `forgeomni/superaicore` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.5.6] — 2026-04-17

Absorbs the SuperRelay design as a thin skill-running CLI surface inside superaicore itself, instead of shipping a second package. Phase 1 (list + Claude exec), Phase 1.5 (translator + compatibility probe + codex/gemini runners, `--exec=native`), Phase 1.6 (fallback chain + side-effect lock, `--exec=fallback`), Phase 2 (sub-agent list + run), and Phase 3 (Gemini custom-command TOML sync) all land here.

Also fixes a 0.5.5 gap where `BackendCapabilities::transformPrompt()` existed but was never invoked — Gemini/Codex preambles are now actually prepended on every non-Claude skill/agent dispatch, so `skill run ... --backend=gemini` no longer falls back to `codebase_investigator` on external-research tasks.

In a second follow-up pass within this same version, we hardened the CLI surface so it isn't just a prompt pipe:

- `arguments:` frontmatter → typed CLI validation + structured `<arg name="..">` XML rendering into the skill body.
- `allowed-tools` frontmatter → passed through to `claude --allowedTools`; codex/gemini emit a `[note]` since neither CLI has an enforcement flag.
- Translator is now prose-safe: tool-name rewrites only fire in explicit shapes (backtick, `Name(`, "the X tool", "use/call/invoke X"). Bare capitalised words in prose are left alone; the preamble carries the translation hint for the model to interpret.

The standalone CLI binary is also renamed `super-ai-core` → `superaicore` to match the Composer package (`forgeomni/superaicore`); the Laravel package namespace (`super-ai-core::` views, `config/super-ai-core.php`, route prefix) is intentionally unchanged so existing hosts don't break.

### Added

**Skill registry — read `.claude/skills/*/SKILL.md`**
- `Registry\FrontmatterParser` — dependency-free YAML-frontmatter reader (~100 LOC). Handles scalars, quoted strings, single-level lists (block `- item` and flow `[a, b]`), `true`/`false`/`null` coercion, BOM + CRLF. Deliberately not a full YAML parser; avoids pulling in `symfony/yaml`.
- `Registry\Skill` — value object: `name`, `description`, `source` (`project`|`plugin`|`user`), `body`, `path`, `frontmatter`.
- `Registry\SkillRegistry` — three-source merge (project > plugin > user) with project winning on name collision. Sources map to `.claude/skills/` in cwd, `~/.claude/plugins/*/skills/`, `~/.claude/skills/`. Constructor takes injectable `cwd` + `home` for testability.

**Skill runners — pipe translated body through a backend CLI**
- `Runner\SkillRunner` interface (`runSkill(Skill, array $args, bool $dryRun): int`).
- `Runner\ClaudeSkillRunner` — `claude -p <body + <args> xml block>`, streams combined stdout/stderr via an injectable writer closure. Dry-run prints the resolved command shape.
- `Runner\CodexSkillRunner` — `codex exec --full-auto --skip-git-repo-check -` with prompt on stdin.
- `Runner\GeminiSkillRunner` — `gemini --prompt "" --yolo` with prompt on stdin (matches the invocation shape in `AgentSpawn/GeminiChildRunner`).

**Skill body translation + compatibility probe**
- `Translator\SkillBodyTranslator` — two-stage transform. Stage 1: rewrite canonical Claude tool names per the target `BackendCapabilities::toolNameMap()` using `\bToolName\b` word-boundaries (so `ReadMe` doesn't become `read_fileMe`). Empty-map backends skip stage 1 — the contract says empty map means canonical names are native, not "no mapping exists". Stage 2: call `BackendCapabilities::transformPrompt()` on the result. Gemini/Codex prepend their steering preambles (sub-agent Spawn Plan protocol, external-research guard, canonical→native tool hints); Claude/SuperAgent are identity. Preamble injection is idempotent via version-marker sentinels. Returns the rewritten body plus `translated` and `untranslated` arrays for reporting.
- `Runner\CompatibilityProbe` — static pre-flight returning `compatible` / `degraded` / `incompatible` + reasons. `Agent` on a backend without `supportsSubAgents()` is hard-incompatible. Backends with a non-empty toolNameMap (gemini) flag canonical tools missing from the map as `degraded`. Empty-map backends skip the gap check — we can't distinguish "native" from "missing" without a separate capability table (noted as a known limitation for codex's `WebSearch`).

**Fallback chain + side-effect hard-lock (DESIGN §5 D13–D16)**
- `Runner\SideEffectDetector` — best-effort probe for filesystem mutations produced by the run. Two signals: (a) cwd mtime snapshot taken before the run vs after (scoped; skips `.git`, `vendor`, `node_modules`, `.phpunit.cache`, `.idea`, `.claude`, `storage`, `bootstrap/cache`; capped at 10k files), and (b) regex scan of the raw output buffer for `"type":"tool_use"` events for mutating tools (`Write`, `Edit`, `Bash`, `NotebookEdit`, `write_file`, `replace`, `run_shell_command`, `apply_patch`). Reason list deduped and capped at 5 + overflow hint.
- `Runner\FallbackChain` — orchestrates the chain. For each hop: re-probe, skip on `incompatible` unless it's the last hop, translate the body, tee the runner's writer into a capture buffer, take a mtime snapshot, run, then diff. If side-effects detected → print `[fallback] locked on <backend>` with reasons and return the hop's exit code (D15 hard-lock — we do not roll to the next hop even if the run failed, to avoid double-writes). No side-effects + zero exit → return 0; no side-effects + non-zero exit → log and try the next hop (or propagate on last).
- `Console\Commands\SkillRunCommand` `--exec=fallback` + `--fallback-chain=a,b,c` — default chain resolves to `<backend>,claude` when `--backend` is not claude, else `[claude]`; chain is deduped. Dry-run mode short-circuits the detector (snapshot would otherwise scan the cwd).

**Argument schema (`arguments:` frontmatter)**
- `Registry\SkillArguments` — parses three recognised shapes: free-form string (single arg required), list of names (positional, all required, strict arity), map of name→description (named, all optional in v0). Validates caller-supplied positional args, returns a human error on missing-required / extra-positional. Renders into an `<args>` XML block (flat for free-form, `<arg name="...">` tagged for positional/named). Escapes XML specials so user-supplied URLs / HTML don't break the prompt. Richer v1 shapes (`- name: x, required: true`) require nested-YAML parsing that our minimal reader doesn't yet do; they degrade silently to "unknown schema" and the model sees the raw body.
- `Console\Commands\SkillRunCommand` parses the schema at dispatch time, validates, renders, and appends the block *after* translation — so prose in user-supplied args isn't touched by `SkillBodyTranslator`. Runners get `$args = []` because the block is already in the body.
- `Runner\FallbackChain::run()` takes a pre-rendered `string $renderedArgs` which is appended after per-hop translation.

**`allowed-tools` passthrough**
- `Registry\Skill` gains `allowedTools: string[]`, parsed from frontmatter (`allowed-tools` / `allowed_tools` / `tools`).
- `Runner\ClaudeSkillRunner` / `ClaudeAgentRunner` pass the list to the Claude CLI via `--allowedTools name1,name2,...`. Shows up in the dry-run line-out.
- `Runner\CodexSkillRunner` / `CodexAgentRunner` / `GeminiSkillRunner` / `GeminiAgentRunner` emit a single `[note]` line when `allowed-tools` is declared — neither CLI exposes a matching flag, so enforcement falls back to model obedience via the preamble.

**Translator hardening — prose-safe rewrite**
- `Translator\SkillBodyTranslator` now rewrites canonical tool names only when the shape disambiguates intent:
  - `` `Read` `` — backtick-wrapped identifier
  - `Read(...)` — function-call shape
  - "the Read tool" / "the `Read` tool"
  - "use/using/call/calling/invoke/invoking Read"
- Bare prose like "Read the config carefully and Write a one-line summary" is left alone; the backend preamble (stage 2) carries the translation hint for context-dependent references. Preamble injection is still idempotent via version-marker sentinels.
- `untranslated` gap detection stays at loose `\b` word-boundary — over-flagging a compatibility gap is safer than missing one.

**Gemini custom-command sync (DESIGN §7 Phase 3)**
- `Sync\Manifest` — reads/writes `<gemini-home>/commands/.superaicore-manifest.json`. Shape: `{version:1, generated_at, entries:{path:sha256}}`. Tracks what we wrote last time so we can (a) clean up stale TOMLs for skills/agents that disappeared, and (b) detect user edits to TOMLs we created and refuse to clobber them.
- `Sync\GeminiCommandWriter::sync(skills, agents)` — writes two TOML namespaces:
  - `<gemini-home>/commands/skill/<name>.toml` with `prompt = '!{superaicore skill:run <name> {{args}}}'`
  - `<gemini-home>/commands/agent/<name>.toml` with `prompt = '!{superaicore agent:run <name> "{{args}}"}'`
  - Each file carries a `# @generated-by: superaicore` + `# @source: <path>` header. Non-destructive contract per DESIGN §10 criterion 6: a TOML we wrote + since user-edited is preserved (reported as `user-edited`); a stale TOML the user modified is kept (reported as `stale-kept`); a user-deleted TOML is recreated on the next sync.
- `Console\Commands\GeminiSyncCommand` — `gemini:sync [--dry-run] [--gemini-home=...]`. Prints a five-section change table (`+written`, `·unchanged`, `-removed`, `!user-edited`, `!stale-kept`). `--gemini-home` override primarily exists for testability; defaults to `$HOME/.gemini`.

**Sub-agent registry + runners (DESIGN §7 Phase 2)**
- `Registry\Agent` — value object: `name`, `description`, `source` (`project`|`user`), `body` (system prompt), `path`, `model`, `allowedTools`, `frontmatter`.
- `Registry\AgentRegistry` — two-source merge per D7: `$cwd/.claude/agents/*.md` (project, wins) > `$home/.claude/agents/*.md` (user). Agents are flat `.md` files (not directories like skills). Frontmatter-missing `name` falls back to the filename stem. Reads optional `allowed-tools` / `allowed_tools` / `tools` lists, `model:` string.
- `Runner\AgentRunner` interface + `ClaudeAgentRunner` / `CodexAgentRunner` / `GeminiAgentRunner`. All three concatenate `body + "\n\n---\n\n" + task` and pipe to the respective CLI. `ClaudeAgentRunner` honors the `model:` frontmatter by resolving `opus`/`sonnet`/`haiku` aliases through `ClaudeModelResolver::resolve()` and passing `--model`. `CodexAgentRunner` passes `-m`; `GeminiAgentRunner` passes `--model`. Codex and Gemini runners also apply their capability's `transformPrompt()` to inject the backend preamble.
- `Console\Commands\AgentListCommand` — `agent:list [--format=table|json]`. Table columns: name, source, model, description.
- `Console\Commands\AgentRunCommand` — `agent:run <name> <task> [--backend=claude|codex|gemini] [--dry-run]`. When `--backend` is omitted, backend is inferred from the agent's `model:` (`claude-*`/family alias → claude, `gemini-*` → gemini, `gpt-*`/`o[1-9]-*` → codex, otherwise claude).

**Console commands**
- `Console\Commands\SkillListCommand` — `skill:list [--format=table|json]`. Table shows name, source, description (truncated to 80 chars).
- `Console\Commands\SkillRunCommand` — `skill:run <name> [-- args...] [--backend=claude|codex|gemini|superagent] [--exec=claude|native|fallback] [--fallback-chain=...] [--dry-run]`.
  - `--exec=claude` (default): run on Claude CLI regardless of `--backend`.
  - `--exec=native`: `--backend` selects the target; runs `CompatibilityProbe` + `SkillBodyTranslator` first, prints `[probe]` / `[translate]` lines, then dispatches to the backend runner. Incompatible verdicts print reasons but still run (best-effort, user opted into native).
  - `--exec=fallback`: walks the resolved chain with probe + translate + side-effect lock per hop (see above).
  - Constructor takes optional injected `SkillRegistry`, `Services\CapabilityRegistry`, and `array<string,SkillRunner>` keyed by backend for testability.
- Both commands wired into the standalone `SuperAICore\Console\Application` used by `bin/superaicore`. A Laravel host can wrap them as Artisan commands via the existing service provider pattern.

### Changed
- Framework-agnostic binary renamed `bin/super-ai-core` → `bin/superaicore`; `composer.json` `bin` entry and Symfony Console application name updated. README / README.zh-CN / README.fr / INSTALL.md / INSTALL.zh-CN / INSTALL.fr CLI usage examples updated in lockstep. Laravel package namespace is unchanged (`config/super-ai-core.php`, `super-ai-core::*` views, `AI_CORE_ROUTE_PREFIX` default). `.claude/settings.local.json` permission allowlist updated to the new binary path.

### Tests
- 40 new unit/feature tests (tests/Unit/Registry, tests/Unit/Translator, tests/Unit/Runner, tests/Feature/Console):
  - `FrontmatterParserTest` — 8 cases incl. BOM/CRLF, unclosed frontmatter, flow sequences, quoted values, boolean/null coercion.
  - `SkillRegistryTest` — three-source merge with project-wins, `get()` miss, empty-environment safety.
  - `AgentRegistryTest` — two-source merge, user-only agents, fallback-to-filename-stem for frontmatter without `name:`, empty-environment safety.
  - `SkillBodyTranslatorTest` — gemini rewrite + preamble injection, codex passthrough + preamble, claude identity, word-boundary safety, unmapped-canonical reporting, preamble idempotency on repeated translate.
  - `CompatibilityProbeTest` — per-backend verdicts for claude/codex/gemini × with/without Agent × with/without unmapped canonical tools.
  - `SideEffectDetectorTest` — mtime snapshot diff (create/modify/delete/no-change), stream-json grep for mutating tools, skip-dirs ignored (`.git`), reason-list cap with overflow hint.
  - `FallbackChainTest` — single-hop compatible run, incompatible-first-hop-is-skipped, side-effect locks on first hop (second hop must not run), failure-without-side-effect falls through, all-hops-fail propagates last exit, empty chain.
  - `SkillRunCommandTest` — claude happy path, unknown skill, fallback dry-run walks to claude, native-gemini-incompatible (translates + probes + runs), native-claude-compatible (no probe/translate noise), native-gemini-degraded.
  - `AgentRunCommandTest` — project-agent runs on inferred claude backend, gemini-model agent infers gemini backend, `--backend` overrides inferred backend, unknown-agent non-zero exit, dry-run propagated.
  - `SkillArgumentsTest` — 8 cases across the three recognised shapes (free-form / positional / named), XML rendering + escape of special chars, free-form helper behaviour.
  - `ClaudeSkillRunnerTest` — dry-run announces `--allowedTools` when non-empty; absent flag when frontmatter declares none.
  - `GeminiCommandWriterTest` — 7 cases: first-sync writes both namespaces, second sync is idempotent, stale TOML removed when skill disappears, user-edited TOML preserved against overwrite, user-edited stale kept instead of deleted, user-deleted TOML recreated, `--dry-run` touches no disk.
  - Extended `SkillRunCommandTest` with args-schema rejection (missing required / extra positional) and named-arg XML rendering.
- Full suite: 119 tests / 346 assertions / 1 pre-existing skip (unrelated), zero regressions.

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
