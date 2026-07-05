# Changelog

All notable changes to `forgeomni/superaicore` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] — 2026-07-05

**Claude 5 model-table catch-up** — `ClaudeModelResolver` gains the
`fable` family (`claude-fable-5`, native 1M, no `[1m]` variant) and
`claude-sonnet-5` as the `sonnet` alias target + catalog entries; the
retired Opus 4.6 rows are removed from the catalog. `EngineCatalog`'s
claude seed mirrors the same list and its `default_model` moves to
`claude-sonnet-5` (what the CLI's bare `sonnet` alias actually runs
now). Fixes model pickers missing Fable 5 / Sonnet 5 while SDK 1.1.5
already supported them — the UI table is maintained separately and had
drifted (SuperTeam carries a host-side postprocess shim until this
ships).

**ai-dispatch parity wave — alias send with a transparent routing contract,
real CLI session resume, a filesystem run archive, an agent preferences
file, a delegate-in SKILL, and `doctor`.** Borrowed from
[rennzhang/ai-dispatch](https://github.com/rennzhang/ai-dispatch): one short
token (`opus`, `kimi`, `codex`, a model id, a backend name) now resolves to
an ordered `{backend, model}` candidate pool that `superaicore send` walks
with transparent degradation — quota / rate-limit / auth / network failures
fall through to the next candidate, anything else fails closed. Machine
callers read `ok / status / backend_used / model_used / route_trace /
degraded / failure_class / session_id` instead of assuming the requested
target answered. **Additive and non-breaking** — Dispatcher, TaskRunner,
orchestrators, and SmartFlow untouched; no migrations; SDK pin unchanged.
See `docs/ai-dispatch-parity.md`.

```bash
composer update forgeomni/superaicore
# no migrations

superaicore send opus "review the diff in HEAD~1" --cwd "$PWD" --json-result
superaicore resume --session-id <id> "follow-up question" --json-result
superaicore runs list · superaicore aliases · superaicore preferences init · superaicore doctor
```

### Added

- **`AliasRouter`** (`src/Services/AliasRouter.php`) — unified short-name →
  candidate-pool routing with ai-dispatch's precedence: user config
  (`super-ai-core.dispatch.aliases`, accepts maps / `'backend:model'`
  strings) → built-in registry → backend passthrough → model-id inference →
  default backend. `superaicore aliases [target] [--json]` lists the pool or
  resolves one target exactly like `send` does.
- **`superaicore send <target> "<task>"`** (`SendCommand` +
  `src/Services/DispatchSender.php`) — one-shot dispatch through the normal
  Dispatcher streaming path (usage rows, cost, tracing, process monitor all
  see it; `usage_source: dispatch_send`). `--json-result` emits the full
  contract incl. `route_trace[]` per-candidate attempts and a `degraded`
  flag; `--prompt-file`, `--stream-progress`, `--system`, `--timeout`,
  `--task-name`, `--no-check` supported. Fall-through policy driven by
  `dispatch.retry_on_classes` over the shared
  `task_fallback.failure_classes` taxonomy (`Support\FailureClassifier`).
- **Real session resume** — `ClaudeCliBackend` accepts `resume_session_id`
  → `claude --resume <id>` (both `generate()` and `stream()`; envelopes now
  surface `session_id`), `CodexCliBackend` captures `thread.started` →
  `thread_id` and resumes via `codex exec resume <thread_id>`.
  `superaicore resume --session-id <id> "<delta>"` re-routes to the owning
  backend recorded in the run store (`--backend`/`--model` override for
  unknown sessions); resume never falls back to a different engine.
- **`RunStore`** (`src/Services/RunStore.php`) — one JSON file per
  `send`/`resume` under `~/.superaicore/runs` (`dispatch.runs_path` /
  `AI_CORE_RUNS_PATH`), browsable via `superaicore runs list|show <id>`;
  `findBySession()` powers resume's backend lookup. Filesystem-only so
  headless CLIs and delegating agents audit results with zero DB access.
- **Agent preferences file** — `superaicore preferences init|show|path`
  manages `~/.superaicore/preferences.md` (`dispatch.preferences_path` /
  `AI_CORE_PREFERENCES_PATH`): natural-language scenario→model preferences
  the CALLING agent reads before picking a target; SuperAICore never parses
  it (ai-dispatch's "routing intelligence lives at the agent layer").
- **Delegate-in SKILL** — `resources/skills/superaicore-dispatch/SKILL.md`
  teaches external Claude Code / Codex / Gemini agents to dispatch INTO
  SuperAICore (read preferences → `send` → interpret contract → `resume`);
  `superaicore skill:install-dispatch --agent claude|codex|gemini` installs
  it via the existing `SkillManager`. Mirror image of `superaicore:sync-cli`.
- **`superaicore doctor [--json]`** — aggregate diagnostic: registered
  backends, CLI engine binaries + auth (same probes as `cli:status`), alias
  resolvability, preferences file, run-store writability; exits non-zero
  only when nothing can dispatch.

### Changed

- **Claude model tables refreshed** — `ClaudeModelResolver::FAMILIES` gains
  `fable => claude-fable-5` and points `sonnet` at `claude-sonnet-5`; the
  catalog adds both Claude-5 ids (native 1M, no `[1m]` variants) and drops
  the retired Opus 4.6 rows. `EngineCatalog`'s claude seed mirrors the same
  list and its `default_model` moves to `claude-sonnet-5`. The new `fable`
  entry in `AliasRouter::BUILTIN` rides on the same family, so
  `superaicore send fable "…"` works out of the box.

### Fixed

- **Standalone console container-safety** — `function_exists('config')` is
  not a safe guard in a dev checkout (Laravel helpers autoload without a
  booted container and `config()` throws). New `Support\ConfigValue::get()`
  hardens `BackendRegistry`, `Dispatcher`, `CostCalculator`,
  `TraceCollector`, and `BackendState::isEngineDisabled` (which fataled on
  a missing DB), fixing pre-existing `bin/superaicore list-backends` /
  `call` breakage in dev checkouts.

## [1.0.11] — 2026-07-03

**SuperAgent SDK bumped to 1.1.5 — Fable 5 and Sonnet 5 land as native
`anthropic` models, the Opus line is repriced to official rates, and the
Kiro unit tests stop depending on the developer's machine.** SDK 1.1.5 adds
**Claude Fable 5** (`claude-fable-5`, Anthropic's most capable model — 1M
context, 128K max output, always-on adaptive thinking, `output_config.effort`
dial) and **Claude Sonnet 5** (`claude-sonnet-5`, the new `sonnet` flagship on
the same Claude-5-generation adaptive surface), makes `AnthropicProvider`
implement `SupportsReasoningEffort`, promotes the zero-config `anthropic`
default to **Opus 4.8**, and corrects stale Anthropic pricing (current Opus
$15/$75 → **$5/$25**; Haiku 4.5 → **$1/$5**). This release mirrors the new
rates into SuperAICore's `model_pricing` table and seeds the new ids into the
`superagent` engine so cost dashboards and model pickers stay accurate without
a live catalog probe. **Additive and non-breaking** — the SDK pin moves from
`^1.1.2` to `^1.1.5`, no migrations, no config changes for existing callers.
The `reasoning_effort` dial keeps routing through `SuperAgentBackend`
untouched — it now simply reaches Anthropic models too.

```bash
composer update forgeomni/superaicore
# no migrations
```

### Added

- **Fable 5 + Sonnet 5 native pricing** (`config/super-ai-core.php` →
  `model_pricing`) — `claude-fable-5` at Anthropic's official **$10 in /
  $50 out** per 1M (above the Opus tier) and `claude-sonnet-5` at the Sonnet
  **$3 / $15** tier (an intro $2/$10 runs through 2026-08-31 — the table
  carries the official rate; override per host to reflect the promo). The
  SDK's `ModelCatalog` carries these rows too, so unlisted Claude SKUs still
  resolve — the explicit entries keep `CostCalculator` accurate offline
  without a catalog round-trip.
- **`claude-fable-5` / `claude-sonnet-5` surfaced in the SuperAgent engine
  seed** (`EngineCatalog::seed()` → `superagent.available_models`) so the
  Claude-5 pair shows up in model pickers even when the catalog probe can't
  run (offline / composer dependency missing / catalog stale), alongside the
  existing DeepSeek V4 + MiniMax M3 + GLM-5.2 fallback ids.
- **`KiroModelResolver::resetMemo()`** — drops the in-process memoized
  catalog so the next `catalog()` call re-reads cache/CLI/fallback. Without
  it, whichever test touched the resolver first pinned the catalog for the
  whole PHPUnit process.
- **`tests/Support/IsolatesKiroCatalog`** (new trait) — sandboxes `HOME`
  (empty temp dir, so no on-disk cache) and `KIRO_CLI_BIN` (nonexistent
  binary, so the live probe fails) around each test, forcing
  `KiroModelResolver::catalog()` onto its deterministic static fallback.

### Changed

- **SDK pin `forgeomni/superagent: ^1.1.2` → `^1.1.5`** (`composer.json`).
  Highlights inherited transparently: `AnthropicProvider` gains the
  `reasoning_effort` dial (`output_config.effort` on Fable 5 / Sonnet 5 /
  Opus 4.5+ / Sonnet 4.6 — unsupported models never 400); the adaptive-only
  request surface (bare `thinking: {type: "adaptive"}`, no `budget_tokens`,
  dropped sampling params / trailing prefills) fixes latent 400s on
  Opus 4.7/4.8; zero-config `anthropic` resolves to `claude-opus-4-8`; the
  SDK Squad **EXPERT** tier routes to `claude-fable-5`. SuperAICore's own
  squad tier map (`squad.tiers`) is host-side config and is intentionally
  left unchanged (`expert` stays on `claude-opus-4-8` — now 3× cheaper;
  point it at `claude-fable-5` if you want the SDK's tiering).
- **Anthropic `model_pricing` repriced to official rates** — the current
  Opus line (`claude-opus-4-5` / `4-6` / `4-7` / `4-8`) drops from the stale
  $15/$75 to **$5/$25** per 1M; the dated `claude-opus-4-20250514` snapshot
  keeps its historical $15/$75; Haiku 4.5 was already carried at the correct
  $1/$5.

### Fixed

- **Kiro unit tests no longer drift with the local machine**
  (`KiroModelResolverTest`, the kiro `EngineCatalogTest` case). They
  previously hit the real `~/.cache/superaicore/kiro-models.json` or
  live-probed the developer's `kiro-cli`, so assertions tracked whatever
  model lineup that machine's Kiro account exposed (subscription tiers
  differ — some accounts get no Opus at all). Both test classes now use
  `IsolatesKiroCatalog` in `setUp()`/`tearDown()` and assert against the
  static fallback. Runtime behaviour is unchanged — the cache → probe →
  fallback chain still runs exactly as before in production.

## [1.0.10] — 2026-06-18

**SuperAgent SDK bumped to 1.1.2 — GLM-5.2 lands as the native `glm`
flagship and the cost catalog is taught its official Z.ai rates.** SDK 1.1.2
adds GLM-5.2 (Z.ai's coding-first agentic flagship — 1M context, 128K max
output, text-only) and GLM-5.1 (200K context) to its `ModelCatalog`,
promotes `glm-5.2` to the `glm` provider default, and gives `GlmProvider` a
`reasoning_effort` dial on top of the binary thinking toggle. This release
mirrors that into SuperAICore so cost dashboards and model pickers stay
accurate without a live catalog probe. **Additive and non-breaking** — the
SDK pin moves from `^1.1.1` to `^1.1.2`, no migrations, no config changes for
existing callers. The new `reasoning_effort` dial routes through
`SuperAgentBackend` untouched (it was already forwarded generically and is
silently ignored by providers that don't implement `SupportsReasoningEffort`).

```bash
composer update forgeomni/superaicore
# no migrations
```

### Added

- **Native GLM pricing** (`config/super-ai-core.php` → `model_pricing`) —
  `glm-5.2` and `glm-5.1` at Z.ai's official PAYG rate **$1.40 in / $4.40
  out** per 1M with a **$0.26 cache-hit input** tier (carried as
  `cache_read_input`), plus `glm-5` at its earlier $1.00 / $3.20. The SDK's
  `ModelCatalog` carries these rows too, so unlisted GLM SKUs still resolve —
  these explicit entries keep `CostCalculator` accurate offline without a
  catalog round-trip (mirroring the DeepSeek / MiniMax / Qwen / Grok blocks).
- **`glm-5.2` surfaced in the SuperAgent engine seed**
  (`EngineCatalog::seed()` → `superagent.available_models`) so Z.ai's new
  flagship shows up in model pickers even when the catalog probe can't run
  (offline / composer dependency missing / catalog stale), alongside the
  existing DeepSeek V4 + MiniMax M3 fallback ids.

### Changed

- **SDK pin `forgeomni/superagent: ^1.1.1` → `^1.1.2`** (`composer.json`).
- **`SuperAgentBackend` reasoning-effort doc** notes the
  `SupportsReasoningEffort` set grew in SDK 1.1.2 to include GLM-5.2
  (`off` → thinking disabled; `low…high` → `reasoning_effort high`;
  `max` → `reasoning_effort max`), alongside MiniMax M3.

## [1.0.9] — 2026-06-05

**ToolSearch fix for `streamChat()` MCP — without it, 1.0.8's `mcp_mode:
'file'` produced a chat turn where the model could never reach any MCP
tool.** Live-verified against the current Claude CLI: MCP servers from
`--mcp-config` report `"pending"` at init and their tools are absent from
the upfront tool list — they are **deferred behind the `ToolSearch`
meta-tool**. Meanwhile `--tools` restricts the WHOLE tool surface (the help
text's "built-in set" wording is misleading, and `mcp__x__*` patterns inside
`--tools` are silently ignored), so 1.0.8's default `Read,Glob,Grep`
allowlist locked ToolSearch out and with it every MCP tool. Additive,
non-breaking; the locked-empty default argv is unchanged.

### Fixed

- **`buildChatArgs()` guarantees `ToolSearch` on the allowlist whenever the
  effective MCP surface is non-empty** (`mcp_mode: 'file'` with a usable
  path, or `'inherit'`). Explicit `allowed_tools` lists are respected — the
  entry is appended only when missing. Older CLI versions ignore unknown
  `--tools` entries, so the append is safe everywhere. Five new tests lock
  the behavior (file/inherit append, empty-mode no-append, no duplicates).

### Docs

- Corrected the 1.0.8 composition note in README (EN/CN/FR) and
  `docs/advanced-usage.*` §12 — the "`--tools` only narrows the built-in
  set, composes cleanly with MCP" claim was wrong; replaced with the
  ToolSearch-deferral explanation.
- Added a config-schema warning to §12: `env` must serialize as a JSON
  **object** — a PHP empty array becomes `[]` and `--strict-mcp-config`
  rejects the server. Drop empty `env` keys when generating subset files.

## [1.0.8] — 2026-06-05

**`streamChat()` learns MCP — a one-shot chat turn can now expose a
caller-scoped set of MCP servers' tools to the model.** Before 1.0.8,
`ClaudeCliBackend::streamChat()` hardcoded a locked-empty MCP config
(`--mcp-config '{"mcpServers":{}}' --strict-mcp-config`), so host chat
surfaces could never reach MCP tools even when the dispatch path
(`prepareScriptedProcess()`) already supported `mcp_mode`. 1.0.8 mirrors that
existing contract onto the chat sibling. **Additive and non-breaking** — the
default stays the locked-empty surface (byte-identical argv), no migrations,
no config changes, SDK pin unchanged (`^1.1.1`).

```bash
composer update forgeomni/superaicore
# no migrations
```

### Added

- **`streamChat()` MCP options** (`Backends\ClaudeCliBackend`) —
  - `mcp_mode: 'empty'|'file'|'inherit'` (default `'empty'`, the pre-1.0.8
    behaviour). `'file'` passes a caller-supplied `mcp_config_file`
    (`{"mcpServers":{...}}` JSON) as `--mcp-config <path> --strict-mcp-config`,
    exposing exactly that server subset to the chat turn; `'inherit'` adds no
    MCP flags so the CLI loads the user's own config. A `'file'` request
    without a usable path falls back to `'empty'` rather than silently
    inheriting the user's whole MCP surface.
  - `extra_cli_flags: string[]` — appended verbatim (escape hatch, mirrors
    `prepareScriptedProcess()`); covers e.g. `--allowedTools "mcp__x__*"` on
    CLI versions that gate MCP tools behind the allowlist.
  - Note: `--tools` only narrows the **built-in** tool set; MCP servers from
    `--mcp-config` are a separate surface and `--permission-mode
    bypassPermissions` (always passed by `streamChat`) auto-approves their
    calls — so the default read-only `Read,Glob,Grep` allowlist composes
    cleanly with MCP tools.
- **`buildChatArgs(string $cliPath, array $options): array`**
  (`Backends\ClaudeCliBackend`, public) — pure argv builder extracted from
  `streamChat()` so the flag matrix (tools / MCP modes / model / extra flags)
  is unit-testable without spawning a process. Five new tests lock the
  matrix, including the back-compat default.
- **`Contracts\ScriptedSpawnBackend::streamChat()` docblock** — documents the
  three new options (`mcp_mode` / `mcp_config_file` / `extra_cli_flags`,
  "Claude; other CLIs ignore", same convention as `allowed_tools`).

### Why

Host chat features that want per-conversation MCP tool access (e.g. a "chat
with selected MCP servers" UI) can now ride the subscription-OAuth CLI path
instead of being limited to API-key SDK dispatches: write a subset
`{"mcpServers":{...}}` file, pass `mcp_mode: 'file'` + `mcp_config_file`, and
the model sees `mcp__<server>__<tool>` tools for exactly the selected servers.

## [1.0.7] — 2026-06-04

**SuperAgent SDK bumped to 1.1.1 — MiniMax M3 lands as a native model and the
DeepSeek V4 Pro / MiniMax catalog is repriced to live rates.** SDK pin moves
`^1.1.0` → `^1.1.1` (the lock advances v1.0.0 → v1.1.1, also pulling in the
1.1.0 SmartFlow engine the existing `SuperAgentFlowBridge` already targets).
Additive and non-breaking — no migrations, no config changes required. The
host mirrors the SDK's catalog corrections so cost dashboards and model
pickers stay accurate offline, without waiting on a catalog round-trip.

```bash
composer update forgeomni/superagent
# no migrations
```

### Added

- **MiniMax M3 native pricing** (`config/super-ai-core.php` → `model_pricing`) —
  M3 (MSA flagship: 1M context, 512K max output, native image/video input,
  interleaved thinking) at the standard PAYG **$0.60 in / $2.40 out** per 1M,
  alongside explicit `MiniMax-M2.7` / `M2.5` / `M2` rows ($0.30 / $1.20). The
  bare `minimax` shorthand and the zero-config default resolve to M3 in the
  SDK; M2.7 stays reachable by id. `CostCalculator` already falls back to the
  SDK `ModelCatalog`, so these rows just keep accounting accurate offline.
- **`MiniMax-M3` surfaced in the SuperAgent engine seed**
  (`EngineCatalog::seed()` → `superagent.available_models`) so it shows up in
  pickers even when the catalog probe can't run (offline / dependency missing
  / catalog stale), matching the existing DeepSeek V4 treatment.

### Changed

- **DeepSeek V4 Pro repriced** to the current official rate to match SuperAgent
  1.1.1 — **$0.435** in (cache-miss) / **$0.003625** in (cache-hit, carried as
  `cache_read_input`) / **$0.87** out per 1M, down from the stale $0.55 / $2.20.
  The deprecated `deepseek-reasoner` alias (routes to V4 Pro) follows suit.
- **SDK pin** `forgeomni/superagent` `^1.1.0` → `^1.1.1` (`composer.json`).

## [1.0.6] — 2026-06-03

**CLI skill bridge: one generic, symlink-safe, fingerprinted bridge that fans a
host's skill + agent library into every CLI backend's native surface — the way
`McpManager` already bridges MCP.** Before 1.0.6 each host hand-rolled a separate
per-CLI sync (a Codex wrapper installer, a Gemini custom-command sync, a Kimi
agent translator, …); 1.0.6 unifies the pattern behind a single
`SuperAICore\Contracts\SkillLibrary` contract + `CliSkillBridge` service +
`superaicore:sync-cli` command, and wires a **lazy on-dispatch sync** into
`TaskRunner` so every CLI spawn keeps that backend's skill surface fresh for the
cost of one fingerprint compare. SuperAICore stays generic — it knows WHERE each
CLI keeps its skills, HOW to install them safely, and WHEN to re-sync; the host
supplies WHAT via the contract. **Additive and non-breaking** — no migrations, no
config changes; when no `SkillLibrary` is bound the bridge is a silent no-op, so
existing hosts are unaffected. Also folds in a cross-backend `builtin`
subscription-auth fix. SDK pin unchanged (`^1.1.0`).

```bash
composer update forgeomni/superaicore
# no migrations. Bind a host SkillLibrary (see below) then:
php artisan superaicore:sync-cli            # skills + MCP → every installed CLI
```

### Added — generic CLI skill bridge (`src/Services/CliSkillBridge.php`)

- **`SkillLibrary` contract** *(1.0.6)* — `src/Contracts/SkillLibrary.php`. A host
  implements five methods — `skills()`, `agents()`, `skillWrapper($backend,$name)`,
  `instructionsDigest($backend)`, `fingerprint()` — and binds it in the container
  (`$this->app->singleton(SkillLibrary::class, MyLibrary::class)`). SuperAICore
  carries zero host assumptions: when nothing is bound, the whole bridge no-ops.
- **`CliSkillBridge` service** *(1.0.6)* — resolves the bound library and fans it
  out per backend in one of three shapes: **`native_dir`** (codex `.codex/skills`,
  gemini `.gemini/skills`, grok `.grok/skills`, cursor `.cursor/skills-cursor`,
  qwen `.qwen/skills`) drops one prefixed wrapper dir per skill; **`instructions`**
  (copilot / kimi / kiro) writes a single digest file telling the model how to
  load any skill on demand; **`source`** (claude reads `.claude/skills` directly)
  and **`none`** (superagent) install nothing.
- **Symlink-safe writes** *(1.0.6)* — the fix for the write-through-symlink
  incident that once clobbered 72 source skill bodies: the bridge **never writes
  through a symlink**. Every wrapper dir, `SKILL.md`, digest file, and manifest is
  `is_link()`-checked and the stale link unlinked (target left intact) before any
  write, so a `~/.codex/skills/super-team-x -> …/.claude/skills/x` link can never
  let a wrapper overwrite the source.
- **Lazy on-dispatch sync** *(1.0.6)* — each sync stamps the library
  `fingerprint()` into a per-backend manifest (`.superteam-skill-sync.json`).
  `TaskRunner` calls `CliSkillBridge::ensureSynced($engine)` before every CLI
  dispatch; it re-installs only when the fingerprint drifted, so the hot path
  costs one hash compare. Pruning is manifest-scoped — only wrappers the bridge
  installed before are removed, never the user's own skills.
- **`superaicore:sync-cli` command** *(1.0.6)* — `php artisan superaicore:sync-cli`
  propagates the whole capability surface — **skills via `CliSkillBridge`, MCP via
  the existing `McpManager::syncAllBackends()`** — to every installed CLI in one
  shot. Flags: `--skills-only`, `--mcp-only`, `--backends=codex,gemini`,
  `--project-root=`. Best for manual / cron / git-hook full refreshes; the lazy
  `TaskRunner` hook covers the per-dispatch case.

### Fixed — `builtin` subscription auth across CLI backends

- **Claude `builtin` OAuth token uses the right env var** *(1.0.6)* — the
  Keychain-extracted subscription token (`sk-ant-oat01-…`) is now injected as
  `CLAUDE_CODE_OAUTH_TOKEN` (OAuth/Bearer path), not `ANTHROPIC_API_KEY` — the
  latter made `claude` send it as an `x-api-key` console key and 401 with
  `apiKeySource:"ANTHROPIC_API_KEY"`, `api_error_status:401`.
- **Stale inherited keys scrubbed on `builtin`** *(1.0.6)* — when a backend runs
  on its subscription/OAuth login (no key supplied), a leftover/invalid console
  key in the host env (`ANTHROPIC_API_KEY` / `OPENAI_API_KEY` / `GEMINI_API_KEY` +
  `GOOGLE_API_KEY` / `CURSOR_API_KEY` / `XAI_API_KEY` + `GROK_API_KEY`) is unset in
  the child process so it can't override the login and 401. Gemini additionally
  flips `GOOGLE_GENAI_USE_GCA=true` so the CLI reaches for its OAuth login file.
  Covers `ClaudeCliBackend`, `CodexCliBackend`, `GeminiCliBackend`,
  `CursorCliBackend`, `GrokCliBackend`.

## [1.0.5] — 2026-06-02

**SmartFlow lands in SuperAICore: cross-CLI dynamic workflows — the multi-CLI
port of Claude Code's built-in `Workflow`, tracking SuperAgent SDK 1.1.0's
cross-model SmartFlow.** Where the SDK's SmartFlow routes one flow across 15
model providers, SuperAICore's routes one flow across the **execution backends**
it already manages — `claude_cli`, `codex_cli`, `gemini_cli`, `copilot_cli`,
`kimi_cli`, `qwen_cli`, `cursor_cli`, `grok_cli`, `kiro_cli`, `superagent`,
`anthropic_api`, `openai_api`, `gemini_api`. One set of primitives
(`agent()` / `parallel()` / `pipeline()` / `gate()` / `council()` / `budget` /
`schema` / `SKIP`) drives any of them, so a single flow can plan on Claude CLI
and review on Codex + Gemini CLI concurrently. **Additive and non-breaking** —
the existing Dispatcher, AgentSpawn, Squad/Team/Smart/Auto orchestrators and the
Backend contract are all untouched; SmartFlow sits beside them as a new
`src/SmartFlow/` module + `superaicore flow` command. SDK pin `^1.0.10` →
`^1.1.0`. Full Unit suite green (695 tests, +23 SmartFlow incl. federation).

```bash
composer update forgeomni/superaicore forgeomni/superagent
# no migrations; publish the config if you customize it:
php artisan vendor:publish --tag=super-ai-core-config
```

### Added — SmartFlow cross-CLI flow engine (`src/SmartFlow/`)

A PHP port of the Claude Code `Workflow` engine, retargeted from model-providers
to the `Backend` contract so the unit of routing is a **CLI**, not an API model.

- **Primitives & engine** — `Flow` exposes `agent()` (one cross-CLI call),
  `call()` (deferred), `parallel()` (barrier, concurrent), `pipeline()`
  (per-item / per-stage), `gate()` (acceptance + fallback/relay),
  `council()` (perspective-diverse vote, each lens pinnable to a different CLI),
  `budget`, `log()` / `phase()`. `FlowEngine::run($flowOrClosure, $args, $opts)`
  is the entry point; `BackendAgentRunner` executes one `AgentCall` against
  whichever backend the call/persona resolves to via `BackendRegistry`.
- **`backend` is the cross-CLI dimension** — every `agent()` / persona / YAML
  step takes a `backend` key naming a registered backend; `provider` is accepted
  as an alias for ease of porting SDK flows.
- **3-layer structured-output safety net** — `StructuredOutputLadder` recovers
  schema-valid output from a CLI's free-text reply: whole-reply JSON →
  fenced ```` ```json ```` block → regex-sniffed object/array, validated by a
  dependency-free `SchemaValidator`; on total failure returns a `SKIP` sentinel
  instead of crashing. (CLIs return prose, so the schema is baked into the
  prompt rather than enforced by a native `response_format`.)
- **Roles / personas** — `PersonaRegistry` with 7 built-ins +
  `resources/flows/personas/personas.yaml` (planner / builder / reviewer /
  researcher / writer / critic / chair); each persona can pin a `backend` /
  `model`. Override via `config('super-ai-core.smartflow.personas')`.
- **Call-ledger + resume** — every run writes a JSONL ledger under
  `~/.superaicore/flows/`; `--resume <runId>` replays the longest **unchanged
  prefix** from cache (zero cost) via content-addressed `FlowSignature`,
  rerunning only from the first changed call. Gates correctly occupy a ledger
  slot so resume stays aligned across them.
- **True parallelism** — `ProcessPool` runs `parallel()` / `pipeline()` agent
  batches as concurrent `bin/flow-agent-runner.php` subprocesses
  (`proc_open` + `stream_select`, Windows polling fallback, concurrency cap),
  degrading to in-process when `proc_open` is unavailable.
- **Zero-cost rehearsal** — `--rehearse` / `--dry-run` (or
  `SUPERAICORE_FLOW_FAKE`-style runs) synthesize deterministic
  schema-conforming stub output with no CLI invoked, so any flow runs
  end-to-end on a machine with zero CLIs installed. Every shipped flow is
  guaranteed to rehearse green.
- **Federation with superagent (cross-CLI → cross-model)** — a SuperAICore flow
  can **delegate a sub-flow to superagent's own SmartFlow**, the layering the two
  engines are built for: SuperAICore fans out across CLIs, the `superagent` leg
  fans out across model providers. `Flow::delegate()` (+ `delegate` / `spec`
  opts on any `agent()`/`call()`, + `strategy: delegate` in YAML) runs through
  the new `Delegation` value object and `SuperAgentFlowBridge` (in-process via
  the SDK's `SuperAgent\SmartFlow\FlowEngine`). Two modes: **named** —
  `delegate('research-trio', …)` runs one of superagent's OWN flows so it
  self-dispatches (`自行分发`), with `delegate_provider`/`delegate_model` to steer
  its model tier; **spec** — `delegate('', ['spec' => […]])` runs a flow whose
  structure SuperAICore authored, so superagent executes to instruction
  (`按照本项目的指示分发`). A delegated call uses the same ledger / budget / resume /
  parallel machinery (delegated spend federates into the parent budget) and
  rehearses end-to-end at zero cost. Missing SDK / unknown flow fails gracefully
  without crashing the parent.
- **4 built-in cross-CLI flows** (`resources/flows/*.yaml`, compiled by
  `YamlFlowLoader` to the same engine): `cross-cli-review` (Claude summarizes →
  Codex + Gemini review in parallel → Claude synthesizes a verdict),
  `cross-cli-dev` (Claude plans → Codex builds → gate → Gemini reviews),
  `cross-cli-council` (Claude drafts → 3 CLIs vote through distinct lenses →
  Claude decides), and `cross-cli-federated` (Claude plans → **delegates research
  to superagent's cross-model `research-trio`** → Codex builds → Gemini reviews).
  Drop your own under `./flows` or `./.superaicore/flows`.
- **CLI** — `superaicore flow list | show <name> | plan <name> |
  run <name> [--args k=v | --json {…}] [--rehearse] [--dry-run]
  [--resume <id>] [--concurrency n] [--budget-usd x] [--backend b]
  [--model m] [--out-json]`. Also exposed as `php artisan flow ...` inside a
  Laravel host.
- **Config** — new `smartflow` block in `config/super-ai-core.php` (`enabled`,
  `default_backend`, `default_model`, `concurrency`, `ledger_dir`, `flows_dir`,
  `budget.usd` / `budget.tokens`, `personas`) + `AI_CORE_SMARTFLOW_*` /
  `SUPERAICORE_FLOW_DIR` env. Service-provider singletons for `FlowEngine`,
  `FlowRegistry`, `PersonaRegistry`. Docs: `docs/smartflow.md`. Tests:
  `tests/Unit/SmartFlow/*` (23 tests, incl. delegation/federation).

### Changed — SuperAgent SDK pin `^1.0.10` → `^1.1.0`

Picks up the SDK's own SmartFlow engine plus the 1.0.10→1.1.0 wire-level
hardening, which reaches the `superagent` backend transparently. No SuperAICore
code depends on the SDK's SmartFlow classes — the port is independent and built
on SuperAICore's own `Backend` contract.

## [1.0.2] — 2026-05-31

**`kimi_cli` backend straddles the kimi-cli → kimi-code transition, on SDK
1.0.10.** Moonshot shipped `@moonshot-ai/kimi-code` (a TypeScript rewrite) to
replace the legacy Python `MoonshotAI/kimi-cli`; both publish the same `kimi`
binary but expose an incompatible headless surface + stream-json shape, so a
host mid-transition may have either. `KimiCliBackend` now probes which dialect
is installed and adapts its argv + parsing — the `kimi_cli` Dispatcher backend
id is unchanged, so routing, the `/providers` UI, and callers need nothing.
Bundled with the SDK pin `^1.0.9` → `^1.0.10` (Kimi/Moonshot HTTP-path
hardening + generalized OpenAI-compatible fixes, which reach the `superagent`
backend transparently). Additive across the board — no schema changes, no
migrations, no config publish. Full Unit suite green save the pre-existing
`kiro-cli` model-name drift (unrelated; SuperAgent's `resources/models.json` is
byte-identical between 1.0.9 and 1.0.10).

```bash
composer update forgeomni/superaicore forgeomni/superagent
# no migrations, no config publish — additive only
```

### Added — `kimi_cli` backend supports both legacy `kimi-cli` and the new `kimi-code` (`src/Backends/KimiCliBackend.php`)

Moonshot shipped [`@moonshot-ai/kimi-code`](https://github.com/MoonshotAI/kimi-code)
(v0.6.0, a TypeScript rewrite) to **replace** the legacy Python
`MoonshotAI/kimi-cli` (≤ v1.38.x). Both publish the **same `kimi` binary** but
expose an **incompatible** headless surface and stream-json shape, so during
the transition a host may have either one installed. `KimiCliBackend` now
detects which dialect is present and adapts — the `kimi_cli` Dispatcher backend
id is unchanged, so callers, routing, and the `/providers` UI need no changes.

- **Variant detection** — a new `variant` knob (`VARIANT_AUTO` default /
  `VARIANT_LEGACY` / `VARIANT_CODE`). `auto` runs a one-shot `kimi --help` probe
  (no auth, no network — commander prints help and exits before any action) and
  classifies on the stable discriminator: legacy advertises a `--print` flag,
  kimi-code does not. The result is cached per binary for the process lifetime;
  an unreadable probe defaults to kimi-code (the going-forward replacement). The
  pure classifier `classifyVariantFromHelp()` is unit-tested without spawning.
- **Per-dialect commands** — all four command-building sites (`generate` /
  `stream` `buildCommand`, `prepareScriptedProcess`, `streamChat`, and the
  Process-Monitor command summary) branch on the dialect:
  - **legacy** keeps `--print --output-format=stream-json --max-steps-per-turn N
    [--mcp-config-file F] [-w dir] --prompt …` (byte-for-byte as before);
  - **kimi-code** sends `--prompt … --output-format stream-json [--model M]` —
    no `--print` (print mode is `--prompt`-triggered), no `--yolo`/`--auto`
    (rejected alongside `--prompt`), no `--max-steps-per-turn` / `--mcp-config-file`
    / `-w` (config.toml-driven; cwd comes from the wrapper). kimi-code
    hard-rejects unknown options, so only its supported subset is sent; a
    passed `mcp_config_file` is silently dropped (with a debug log).
- **Tolerant stream-json parser** — `parseStreamJson()` / the new
  `extractAssistantText()` accept **both** wire shapes: assistant `content` as a
  plain string (kimi-code) or an array of typed `text`/`think` blocks (legacy).
  The kimi-code resume hint (`{"role":"meta","type":"session.resume_hint",…}`)
  is treated as trace and never folded into the answer text. Robust even if
  detection guesses wrong.
- **Config** — `config/super-ai-core.php` gains `kimi_cli.variant`
  (`AI_CORE_KIMI_CLI_VARIANT`, default `auto`; pin `kimi-code` / `kimi-cli` to
  skip probing during the transition), threaded through `BackendRegistry` into
  the constructor.
- **`EngineCatalog`** — the `kimi` engine's declarative `ProcessSpec`
  (`/providers` engine-info readout + the host-convenience
  `CliProcessBuilderRegistry`, NOT the real dispatch path) moves its
  `promptFlag` from `--print` → `--prompt` to track the new kimi-code default.
  `--version` probing works on both dialects; real prompts always go through
  the variant-aware backend.
- **Scope / follow-up** — this covers all four dispatch paths. `KimiAgentSync`
  still writes the legacy `~/.kimi/agents/<ns>/<name>/` layout (and
  `KimiSyncCommand` prints the legacy `--agent-file … --print` hint); kimi-code
  uses a different agent/skill discovery model (`.agents/` + `--skills-dir`),
  so agent-sync parity is tracked as a separate follow-up (see
  `docs/kimi-cli-backend.md` §8.5).
- **Tests** — `tests/Unit/KimiCliBackendTest.php` expands to 21 cases:
  per-dialect command shape (asserting kimi-code omits `--print` /
  `--max-steps-per-turn` / `--yolo` / `-w`), the classifier (incl. a
  `--print`-substring false-match guard), and both stream-json shapes (string
  `content` + the `role:meta` line). Full suite green save the pre-existing
  `kiro-cli` drift. Design notes in `docs/kimi-cli-backend.md` §8.

### Changed — SDK pin moves `^1.0.9` → `^1.0.10` (Kimi/Moonshot hardening + generalized OpenAI-compatible fixes)

**SDK uptake only — additive, no schema changes, no migrations, no config
publish, and zero SuperAICore code changes.** SuperAgent 1.0.10 hardens the
Kimi (Moonshot) HTTP path against MoonshotAI's official `kimi-code` client and
generalizes the wire-level fixes to every OpenAI-compatible provider. Because
the direct-HTTP `kimi` / `qwen` / `glm` / `minimax` / `deepseek` / `grok` /
`openrouter` / `openai` provider types all route through the `superagent`
backend into the SDK's shared `ChatCompletionsProvider`, every improvement
below reaches SuperAICore transparently — the bump is the only change required.

```bash
composer update forgeomni/superagent
# no migrations, no config publish — pure SDK uptake
```

- **Streaming usage accounting restored (every OpenAI-compatible provider).**
  SDK 1.0.10's `ChatCompletionsProvider` now sends
  `stream_options: {include_usage: true}` and parses `usage` from either the
  top-level or `choices[0]` wire location. Without it, streamed responses from
  Kimi/Qwen/GLM/MiniMax/DeepSeek/Grok/OpenRouter/OpenAI carried **no** usage
  block — silently zeroing token counts, cached-token accounting, and cost on
  `ai_usage` rows, the `/providers` cost dashboard, and `CostCalculator`. Any
  SuperAICore dispatch with `stream: true` through these provider types now
  records real token/cost/cache figures.
- **Tool schemas survive Moonshot's strict validator** — the SDK's new shared
  `Format\JsonSchemaNormalizer` inlines local `$ref`/`$defs` and fills missing
  `type` keywords on enum-only properties before tool serialization, so MCP /
  Skill / Agent tools dispatched through the `kimi` provider type are no longer
  rejected. The deref half is in the shared base, so DeepSeek / GLM / Qwen /
  Grok / OpenRouter / OpenAI tool-calling benefits too.
- **Kimi reasoning models stop returning empty answers** — the SDK sends
  `max_completion_tokens` (not `max_tokens`) and round-trips `reasoning_content`
  across turns for Kimi, so think → tool-call → think sequences stay coherent
  and the hidden reasoning channel no longer consumes the whole budget.
- **Truer capability routing** — SDK 1.0.10's `ModelCatalogRefresher` derives a
  real per-model capability map (`thinking` / `vision` / `video` / `tools` /
  `structured_output`) from the provider's `/models` response (Moonshot's
  `supports_*` flags, OpenRouter's `supported_parameters`), so capability-based
  routing through `CapabilityRegistry` / the SDK's `CapabilityRouter` sees real
  per-model signal instead of the provider-level fallback.
- **`SUPERAGENT_KIMI_SWARM_ENABLED` (new, opt-in).** The SDK's speculative Kimi
  Agent-Swarm REST tool is now gated off by default — `kimi-code` ships no swarm
  endpoint (its parallelism comes from local subagents), so the tool returns an
  actionable error unless a host sets `SUPERAGENT_KIMI_SWARM_ENABLED=1` against
  a preview/private endpoint. SuperAICore's own `kimi_cli` CLI backend and the
  `kimi` HTTP provider type are unaffected; no SuperAICore env wiring changed.

Full Unit suite green save the pre-existing `kiro-cli` model-name drift (the
locally installed Kiro CLI reports a 4.5/4.7 lineup that the bundled 4.6
fixtures predate — unrelated to this bump; SuperAgent's `resources/models.json`
is byte-identical between 1.0.9 and 1.0.10).

## [1.0.1] — 2026-05-29

**Maintenance release — no-SDK fallback fidelity + `phpunit-no-superagent`
matrix cleanup.** A focused follow-up to 1.0.0 that tightens the two
host-side fallbacks the `/providers` dashboard relies on when
`forgeomni/superagent` is absent, and silences the risky-test warnings those
same no-SDK installs surfaced in CI. Pure code/test fixes — no schema, no
config keys, no migration, and zero behaviour change for hosts that already
have the SDK on the classpath.

```bash
composer update forgeomni/superaicore
# no migrations, no config publish — pure host-side fixes
```

### Fixed — `ApiHealthDetector::check()` short-circuits a missing key before the SDK probe (`src/Services/ApiHealthDetector.php`)

`check()` previously always fell through to `ProviderRegistry::healthCheck()`
to discover that an API key was missing — informative when the SDK is on the
classpath, useless when it isn't (the no-SDK matrix returned the generic
"SuperAgent SDK not installed" reason for *every* provider, even ones the
host never configured). The env-key probe now runs **before** the
`class_exists(ProviderRegistry::class)` check: when a known provider's
API-key env var is unset — and there's no SDK 0.9.0 OAuth credential file —
`check()` returns `API key not set (<ENV>)` regardless of SDK presence, then
falls through to the existing SDK cURL probe only when a key *is* configured.
This also avoids a pointless cURL attempt when the SDK is present but the key
isn't — a net win on the dashboard probe path.

### Fixed — Gemini OAuth `expires_at` parsed in the no-SDK fallback (`src/Services/CliStatusDetector.php`)

The SDK branch of `detectGeminiAuth()` uses
`SuperAgent\Auth\GeminiCliCredentials` to read `oauth_creds.json` and
normalize `expires_at`; the local fallback (used when the SDK isn't
installed) only checked for file presence and always returned
`expires_at = null`, so callers lost the token-expiry signal on no-SDK
installs and `CliStatusDetectorGeminiAuthTest::test_oauth_creds_file_*`
failed there. The fallback now mirrors the SDK helper — it reads
`~/.gemini/oauth_creds.json` / `credentials.json`, pulls `expires_at` (or
`expiresAt`), and normalizes it (numeric values cast to `int`; string values
parsed via `strtotime()` into epoch-ms) — so the detector's output is
functionally equivalent with or without the SDK.

### Tests — skip SDK-dependent cases on the `phpunit-no-superagent` matrix

In the no-superagent CI matrix, tests that instantiate host classes
implementing an SDK interface (`ModeOrchestrator`) or reference an SDK class
directly (`ModelCatalog`) fatal-error on the missing symbol. Because the
fatal lands *after* Orchestra Testbench's `setUp()` installs its error
handlers, `tearDown()`'s flush never runs and PHPUnit flags the test as
risky. Each affected case now guards on the SDK symbol with
`markTestSkipped()`, matching the pattern already used in
`SuperAgentBackendTest`:

- `Feature\Console\ModelsCommandTest::test_unknown_action_exits_non_zero` — skips when `SuperAgent\Providers\ModelCatalog` is absent.
- `Unit\CliAutoModeTest` and `Unit\CrossLayerDispatcherTest` — `setUp()` skips when `SuperAgent\Modes\ModeOrchestrator` is absent.
- `Unit\CliModeRouterTest` — `setUp()` skips when `ModeOrchestrator` or `SuperAgent\Modes\ModeContext` is absent.

With the SDK installed (the default matrix) every case runs exactly as
before.

## [1.0.0] — 2026-05-28

**First stable release — SDK 1.0.9 uptake: Claude Opus 4.8 flagship, xAI Grok
API provider, and two new subscription CLI engines (Cursor Composer + Grok
Build).** The public API is now stable per [SemVer](https://semver.org); see
`docs/api-stability.md` for the support contract. SDK constraint moves
`^1.0.7` → `^1.0.9`. Additive across the board — no schema changes, no
migrations, no config publish required. The model catalog and
provider matrix are data-driven, so the `/providers` UI, model pickers,
`cli:status`, cost dashboard, and Process Monitor all pick up the new
engines automatically. Full Unit suite green (pre-existing `kiro-cli`
live-probe drift aside — the locally installed Kiro CLI now reports a 4.5/4.7
lineup that the bundled 4.6 fixtures predate; unrelated to this work).

```bash
composer update forgeomni/superaicore
# no migrations, no config publish — additive only
```

### Added — Claude Opus 4.8 flagship (SDK 1.0.8)

SDK 1.0.8 promotes `claude-opus-4-8` to the Anthropic flagship (takes the
`opus` alias, Opus pricing $15/$75, native 1M context, interleaved thinking,
fast mode, effort control, and dynamic workflow / agent-orchestration
support). Surfaced through the project:

- **`ClaudeModelResolver`** — `opus` alias → `claude-opus-4-8`; catalog gains
  `claude-opus-4-8` + `claude-opus-4-8[1m]` at the top of the Opus group
  (4.7/4.6 kept for explicit pinning).
- **`EngineCatalog`** — the `claude` engine's `available_models` lists
  `claude-opus-4-8` / `claude-opus-4-7` first (the catalog auto-expansion
  already surfaced 4.8 from `ModelCatalog`; the seed makes it explicit for
  no-SDK fallback).
- **`config/super-ai-core.php`** — `model_pricing` adds the `claude-opus-4-8`
  row ($15/$75); the `squad` and `cli_squad` `expert` tiers now route to
  `claude-opus-4-8` (env-overridable).
- **`CliSquadOrchestrator`** — default `expert` tier model bumped to
  `claude-opus-4-8`.

### Added — xAI Grok API provider type (`AiProvider::TYPE_GROK`)

First-class `grok` provider type routed through the `superagent` backend to
SDK 1.0.8's `GrokProvider` (xAI's OpenAI-compatible endpoint
`https://api.x.ai/v1`). API key from `XAI_API_KEY` (canonical) with
`GROK_API_KEY` aliased off the same field; default model `grok-4.3` (1M
context). Wired into `ProviderTypeRegistry` (`sdk_provider: grok`),
`AiProvider::TYPES`/`BACKEND_TYPES[superagent]`, `ApiHealthDetector`
(`grok` → `XAI_API_KEY`, added to the dashboard probe + `api:status`), and
`model_pricing` (grok-4.3 / grok-4-fast / grok-code-fast-1 / grok-3-mini /
… verified against docs.x.ai, May 2026).

### Added — Cursor Composer CLI backend (`cursor_cli` / engine `cursor`)

Spawns Cursor's headless `cursor-agent` (the "Composer" agent, 2026.05.28).
Subscription-billed via the user's Cursor plan, so usage rows emit $0 and the
dashboard groups them under "Subscription engines". The `builtin` provider
type rides the local `cursor-agent login` state (`~/.cursor`); headless
runners may export `CURSOR_API_KEY`.

- **`CursorCliBackend`** — `Backend` + `StreamingBackend` +
  `ScriptedSpawnBackend`. `-p --output-format json|stream-json --force`
  (auto-approve tools headless), `--model`, `--workspace`, trailing-positional
  prompt. Tolerant parser handles single-object JSON, Claude-Code-shaped
  `stream-json` NDJSON (`assistant`/`result` events, last turn wins), and a
  plain-text fallback; token usage parsed into the standard envelope.
- **`CursorModelResolver`** — family aliases (`composer`/`opus`/`gpt`/`auto`)
  + curated catalog (default `composer-2.5-fast`; also `composer-2.5`,
  `claude-opus-4-8-thinking-high`, gpt-5.x). `liveCatalog()` re-probes
  `cursor-agent models` on demand.
- **`CursorCapabilities`** — MCP via `.cursor/mcp.json` (synced by
  `McpManager::syncAllBackends`), `stream-json` format, no tool-name rewrites.
- Registered in `BackendRegistry`, `EngineCatalog` (ProcessSpec, subscription
  billing), `CapabilityRegistry`, `AiProvider::BACKEND_CURSOR` +
  `BACKEND_TYPES`, `ProviderTypeRegistry` (builtin), `CliStatusDetector`
  (bespoke `~/.cursor` auth probe), `CliInstaller` (official curl installer),
  `config.backends.cursor_cli`, and `model_pricing` (`cursor:*` → $0).

### Added — Grok Build CLI backend (`grok_cli` / engine `grok`)

Spawns xAI's `grok` "Grok Build" agentic CLI (0.2.8) in headless mode.
Subscription-billed via grok.com login (`~/.grok`), default model
`grok-build`. **Distinct from the metered `grok` API provider type above** —
same brand, different channel (CLI subscription vs `XAI_API_KEY` API).

- **`GrokCliBackend`** — `Backend` + `StreamingBackend` +
  `ScriptedSpawnBackend`. `-p/--single` (or `--prompt-file` for scripted
  spawn), `--output-format json|streaming-json`, `--model`, `--always-approve`,
  and effort control (`--effort low|medium|high|xhigh|max` /
  `--reasoning-effort`) passed through from options/extra_config. Same
  tolerant Claude-Code-shaped parser + token tracking as the Cursor backend.
- **`GrokModelResolver`** — `grok` → `grok-build`; `liveCatalog()` probes
  `grok models`.
- **`GrokCapabilities`** — native sub-agents (`--agents`/`create-subagent`),
  MCP managed via `grok mcp add` (no flat config file → `mcpConfigPath` null),
  `stream-json` format.
- Registered across `BackendRegistry`, `EngineCatalog`, `CapabilityRegistry`,
  `AiProvider::BACKEND_GROK` + `BACKEND_TYPES`, `ProviderTypeRegistry`
  (builtin), `CliStatusDetector` (`~/.grok` auth probe), `CliInstaller`,
  `config.backends.grok_cli`, and `model_pricing` (`grok:grok-build` → $0).

### Changed — UI surfaces the new engines

`/providers` is data-driven off `EngineCatalog` + `ProviderTypeRegistry`, so
the Cursor Composer and Grok Build engine cards, builtin provider rows, the
"Add provider" backend/type dropdowns, CLI version + login badges, and the
Process Monitor all appear automatically. Added per-engine install hints
(`cursor`/`grok`) to the providers view.

### Changed — SuperAgent SDK constraint `^1.0.7` → `^1.0.9`

`composer.json` moves to `^1.0.9`; `composer.lock` resolves to v1.0.9.
1.0.8 (Opus 4.8 + dynamic workflows + Grok provider) and 1.0.9 (the
`/deep-research` Opus 4.8 harness command) are both additive with zero
breaking changes, so downgrading to 1.0.7 still works for pinned hosts.

> **1.0.9 is SDK-REPL only.** Its sole change is a fourth Opus 4.8 harness
> slash command (`/deep-research`, alongside `/workflows` · `/ultraplan` ·
> `/ultrareview`) inside the `superagent` CLI's `Harness\CommandRouter`.
> SuperAICore does not re-expose those interactive REPL commands, so there
> is no project-side surface to wire — the dependency bump is the whole
> change. No new models, providers, schema, or config keys.

### Tests

New: `CursorModelResolverTest`, `GrokModelResolverTest`,
`CursorCliBackendTest`, `GrokCliBackendTest`, `CursorGrokCapabilitiesTest`,
plus a `grok` descriptor/env case in `ProviderTypeRegistryTest` /
`ProviderEnvBuilderTest`. Updated `BackendRegistryTest` (cursor_cli/grok_cli),
`ProviderTypeRegistryTest` (TYPE_GROK in the bundled set), and
`CliInstallerTest` (script-source default for cursor/grok).

## [0.9.9] — 2026-05-23

**Maintenance release — SDK 1.0.7 uptake + `QwenCliBackend`
interface-contract fix + 0.9.8 test-fixture catch-up.** A focused
cleanup of issues exposed by booting the 0.9.8 test matrix on a
Windows PHP 8.1 host. The `qwen_cli` backend introduced in 0.9.8
declared three interfaces but only implemented two — `streamChat()`
from `ScriptedSpawnBackend` was missing, so autoloading the class
crashed PHPUnit before a single test ran (`Class … contains 1
abstract method and must therefore be declared abstract`). Two
unit-test fixtures still encoded the pre-qwen registry shape, so the
provider-type and backend-registry assertions came up two short. SDK
constraint moves `^1.0.6` → `^1.0.7` to pick up the upstream
Windows-compatibility fix wave (cmd.exe `command -v` → `where.exe`,
`tput cols` guarded behind `DIRECTORY_SEPARATOR`, CI worktree-test
git identity injection). Zero behaviour change for callers that were
already on 0.9.8 with the qwen_cli backend disabled; the `streamChat()`
fix only matters for hosts that actually route one-shot chat through
qwen.

```bash
composer update forgeomni/superaicore
# no migrations, no config publish — pure code fixes
```

### Fixed — `QwenCliBackend::streamChat()` interface contract (`src/Backends/QwenCliBackend.php`)

`QwenCliBackend` was added in 0.9.8 and declared `implements Backend,
StreamingBackend, ScriptedSpawnBackend`, but only implemented
`Backend::generate()`, `StreamingBackend::stream()`, and
`ScriptedSpawnBackend::prepareScriptedProcess()`. The blocking
one-shot-chat method `ScriptedSpawnBackend::streamChat(string,
callable, array): string` was missing — autoloading the class on PHP
8.1 raised:

```
PHP Fatal error: Class SuperAICore\Backends\QwenCliBackend contains
1 abstract method and must therefore be declared abstract or
implement the remaining methods (ScriptedSpawnBackend::streamChat)
```

Any host that resolved `BackendRegistry`'s qwen_cli row hit this at
container-boot time; the test suite hit it at PHPUnit discovery so
no test in the project could run.

The added `streamChat()` mirrors `GeminiCliBackend::streamChat()`
shape (argv = `[binary, --output-format=json, --yolo,
--model=<model>, --prompt, <prompt>]`), parses the single JSON blob
through the existing `parseJson()` helper instead of duplicating the
parse logic, and calls `assertChatExit()` from
`BuildsScriptedProcess` for the same non-zero-exit error envelope
the other CLI backends use. The binary path comes from
`$this->binary` (constructor-injected) rather than
`CliBinaryLocator::find(AiProvider::BACKEND_QWEN)` because no
`BACKEND_QWEN` constant exists on `AiProvider` yet — that registry
expansion is deferred to the next release that touches `AiProvider`
proper.

### Fixed — 0.9.8 test fixtures: qwen-anthropic provider + qwen_cli backend

The 0.9.8 release added `AiProvider::TYPE_QWEN_ANTHROPIC` to the
provider-type registry and `qwen_cli` to the backend registry, but
two unit tests still encoded the 13-entry / `[anthropic_api]`-only
pre-qwen shapes and failed cleanly:

- **`tests/Unit/ProviderTypeRegistryTest::test_bundles_all_bundled_provider_types`**
  — expected list extended to include `AiProvider::TYPE_QWEN_ANTHROPIC`
  in its sorted form (slot 12, between `openai-responses` and
  `vertex`). The DashScope Anthropic-protocol endpoint registry
  entry has been in `ProviderTypeRegistry` since 0.9.8; the assert
  list just hadn't been updated.
- **`tests/Unit/BackendRegistryTest::test_disabled_backend_is_skipped`**
  — config block extended with `'qwen_cli' => ['enabled' => false]`
  alongside the other explicit-disable entries. The test verifies
  that with every backend off except `anthropic_api`, only
  `anthropic_api` is registered; without the qwen_cli disable line,
  `BackendRegistry`'s `?? true` default fell through and added
  `qwen_cli` to the names list.

No registry code changed — both fixes are test-only and bring the
fixtures back in sync with the 0.9.8 production shape.

### Changed — SuperAgent SDK constraint `^1.0.6` → `^1.0.7`

SDK 1.0.7 is a pure fix release (no schema changes, no API moves,
zero breaking) with three Windows-side fixes that materially improve
the SuperAICore-on-Windows story for hosts that ship SuperAgent as
the in-process engine:

- **`SuperAgentApplication` CLI argument parser** — old behaviour
  scanned every positional and treated any match against a known
  subcommand name as the command, so `superagent "fix the login
  bug"` mis-routed to the `login` subcommand. Now only the first
  positional is checked; reserved words elsewhere flow through to
  the chat prompt unchanged. Matters for hosts that proxy
  user-supplied prompts through the SDK CLI.
- **`McpCommand::resolveBinaryPath()`** — `mcp test <server>` used
  the POSIX `command -v` builtin which prints `'command' is not
  recognized` on cmd.exe. Now uses `where.exe <name> 2>nul` on
  Windows + an 18-entry cmd-builtin whitelist (`echo` / `dir` /
  `type` / `set` / `cd` / …) for stdio-server targets that pair with
  `cmd /c`.
- **`Renderer::detectTermWidth()`** — `tput cols 2>/dev/null` is
  parsed by cmd as a literal `/dev/null` output path. Now guarded
  behind `DIRECTORY_SEPARATOR !== '\\'`; Windows falls through to
  the `$COLUMNS` env-var with 80-column final default.
- **Bonus**: `AgentProgressTracker::toArray()` now exports `status`
  and `turnCount` (the parallel-coordinator envelope expected them
  but the tracker only sent token/tool counts), and the SDK's own
  `WorktreeManagerTest` now passes `-c user.email=… -c user.name=…`
  to `git commit` per-invocation so CI runners without global git
  identity don't silently break the worktree tests.

`composer.json` constraint moved to `^1.0.7`; `composer.lock` resolves
to 1.0.7. Downgrading back to 1.0.6 still works for hosts that pinned
that release, but they lose the Windows fixes.

### Validation

Full PHPUnit suite passes on PHP 8.1.34 / Windows 11:

```
Tests: 637, Assertions: 1745, Skipped: 4
```

(Unit: 593 / Feature: 44 — same totals as the post-fix 0.9.8 baseline
once the QwenCliBackend autoload crash is unblocked.)

### Compatibility

Zero breaking changes. Every fix is additive at the source level
(one new method on `QwenCliBackend`, two test fixtures extended, one
constraint bump). Hosts that:

- never enabled `qwen_cli` were unaffected by the autoload crash
  (the class wasn't autoloaded), and remain unaffected.
- enabled `qwen_cli` but never called `streamChat()` on it (i.e.
  only used `generate()` or `stream()` or `prepareScriptedProcess()`)
  were also unaffected at runtime — the missing method only fires
  at class-instantiation time on PHP 8.1+'s abstract-method check.
- proxied chat through qwen_cli get a working `streamChat()` for
  the first time.

## [0.9.8] — 2026-05-22

**Eighth execution engine + magic-trace observability + 9Router /
Pi / claude-octopus borrowings.** Adds **Qwen Code CLI** as the eighth
unified execution engine (1M-context `qwen3.7-max` at $2.50/$7.50 per
1M, native Anthropic protocol). Adds an always-on Dispatcher trace
ring (Chrome Trace Event JSON, viewable in `chrome://tracing` /
`ui.perfetto.dev`) that auto-dumps on `QuotaExceeded` / null result /
auto-rotate / soft-timeout. Adds an OpenAI-compatible proxy at
`/super-ai-core/v1/chat/completions` + `/v1/models` so Cursor / Cline /
Roo / Kiro / continue.dev / the OpenAI SDK can target SuperAICore as a
drop-in backend. Adds multi-account round-robin with cooldowns, named
routing combos, real SSE streaming on the three HTTP backends,
pre-emptive OAuth refreshers for Claude / Codex / Copilot / Kiro,
Pi-style session tree branching, progressive-disclosure skill index
for non-skill-native CLIs (codex / gemini), a `task-results:export-jsonl`
pi v3 exporter, and a `gh-watch` GitHub PR / CI reaction engine.

Every binding is additive and opt-in — pre-0.9.8 behaviour is preserved
unless you set an env flag, pass a new option, or resolve a new service
from the container. **SDK 1.0.6 bump** moves `^1.0.5` → `^1.0.6`,
picking up `Tools\Compression\RtkPipeline` with six real compressors
(git diff / grep / find / ls / tree / Bash), `Hooks\HookEvent::PR_EVENT`
+ `PrWatchHookData`, `Agent::steer()` / `Agent::followUp()` mid-turn
control, the `qwen-anthropic` SDK provider for DashScope's
Anthropic-protocol endpoint, the canonical 13-event streaming taxonomy
(`Providers\StreamEventTranslator` + `StreamEventTypes`), pi `/tree`
session forking (`Conversation\BranchManager`,
`SessionManager::fork()`), and the `Tools\Schema` cross-provider
schema normaliser. Several of these overlap with patterns SuperAICore
0.9.8 ships natively (TraceCollector, ArrowSerializer,
SessionBranchManager) — the local implementations are kept; SDK
counterparts are documented as alternatives for hosts that want them.

```bash
composer update forgeomni/superaicore
php artisan migrate                                              # five new tables / columns
php artisan vendor:publish --tag=super-ai-core-config --force   # picks up tracing + agent_catalog + qwen_cli blocks
```

Five migrations land: `kind` column on `ai_user_questions`
(Pi dialog discriminator: `select` / `confirm` / `input` / `editor`)
plus four new tables — `ai_session_branches`, `ai_routing_combos`,
`ai_provider_accounts`, `ai_pr_watchers`. Five new artisan commands:
`dispatcher:dump-trace`, `super-ai-core:gh-watch`,
`super-ai-core:oauth-refresh`, `task-results:export-jsonl`,
`super-ai-core:tasks-export-jsonl`. Three new singleton bindings:
`Tracing\TraceCollector`, `Services\AccountRoundRobin`,
`Services\AgentCatalog` (via `fromConfig()`).

### Added — Qwen Code CLI engine (8th execution engine)

- **`QwenCliBackend` (`qwen_cli`)** — QwenLM/qwen-code v0.16.0
  (2026-05-21), a gemini-cli fork adapted for the Qwen model family.
  Implements `Backend`, `StreamingBackend`, and `ScriptedSpawnBackend`
  so it slots into every existing dispatch path (one-shot, streaming,
  detached/host-spawn). Argv surface mirrors gemini-cli (`--prompt`,
  `--model`, `--output-format=json|stream-json`, `--yolo`) so the
  stream-json parser is shared.
  - **Auth — API key only**. Qwen OAuth free tier was EOL'd on
    2026-04-15; the backend reads `DASHSCOPE_API_KEY` or
    `QWEN_API_KEY` from env / `provider_config`. Custom region via
    `DASHSCOPE_BASE_URL` / `DASHSCOPE_REGION`. `isAvailable()` returns
    `false` cleanly when no key is present so routing misses surface
    as a clean "not configured" instead of a 401 mid-dispatch.
  - **Default model `qwen3.7-max`** — 1M context, $2.50/$7.50 per 1M,
    speaks Anthropic's `/v1/messages` natively (drop-in for Claude in
    fallback chains). Other catalogued models: `qwen3.7-plus`,
    `qwen3.6-max-preview`, `qwen3-max`, `qwen3.5-plus`,
    `qwen3.5-flash`, `qwen3-coder-plus`, `qwen3-coder-next`,
    `qwen3-vl-plus`. Eight pricing rows added to
    `super-ai-core.pricing.*`, verified against DashScope's public
    sheet 2026-05-22.
  - **Toggle** — `AI_CORE_QWEN_CLI_ENABLED=false` (default `true`)
    disables registration. Binary path via `QWEN_CLI_BIN` (default
    `qwen`).
  - `EngineCatalog::seed()` lists `qwen` as the eighth engine —
    label "Qwen Code", icon `translate`, dispatcher backend
    `qwen_cli`, `process_spec` mirroring gemini's flag shape.

### Added — Dispatcher trace ring (magic-trace style)

A black-box flight recorder that's always running and only writes to
disk when something interesting happens. Output is Chrome Trace Event
JSON, viewable in `chrome://tracing`, `https://ui.perfetto.dev`, or
the bundled `.claude/design-system/templates/trace-viewer.html`.

- **`Tracing\TraceCollector` (singleton)** — process-wide ring of
  `TraceEvent` records (`llm` / `cache` / `provider` / `tool` /
  `error` categories). `emitDuration()` / `emitInstant()` are
  hot-path-safe (lock-free reservoir; constant memory ~150 KB at
  1024 events). `dump($trigger, $reason, $extraMetadata)` flushes
  the ring to `storage_path('app/superaicore/traces')` and returns
  the path. No-op when `super-ai-core.tracing.enabled=false`.
- **Auto-dump triggers** wired into `Dispatcher::dispatch()`:
  - `error` — `null` result, no backend resolved
  - `rotate` — `QuotaExceededException` (also pivots tag for
    auto-rotate dashboards)
  - `timeout` — soft-timeout breach (deferred to host-side hook)
  Each trigger is independently toggleable via
  `super-ai-core.tracing.dump_on.{error,rotate,timeout}` (default `true`).
- **`SuperAgentBackend::logProviderError()`** now emits a
  `provider.error` instant and auto-dumps with `trigger=rotate` for
  `quota_exceeded` / `usage_not_included` / `server_overloaded` /
  `cyber_policy` so the post-mortem captures the dispatch envelope
  that caused the throw. Cheaper provider errors (bad prompt, context
  window) record an event but don't dump.
- **`dispatcher:dump-trace` artisan command** — manual flush for ops
  workflows. `--reason` / `--trigger` / `--clear` / `--json`.
  Resolves to the same file-naming scheme as the auto-dump.
- **`/super-ai-core/traces` controller** — list view + per-file viewer
  + raw JSON download (`/traces/raw/{filename}`). Filename pattern
  hard-validated to `^trace_[A-Za-z0-9._-]+\.json$` so traversal
  escapes are rejected at the routing layer.

### Added — 9Router-borrowed features

Eight patterns ported from the 9Router / claude-relay-service /
codex-cli playbook. Each opts in via env flag or per-call option and
degrades to no-op when the surrounding wiring isn't present.

- **OpenAI-compatible proxy** — `Http\Controllers\OpenAiCompatibleController`
  surfaces SuperAICore behind the standard Chat Completions API:
  - `GET /super-ai-core/v1/models` — lists configured models + active
    routing combos (objects shaped `{id, object, owned_by}`).
  - `POST /super-ai-core/v1/chat/completions` — accepts the standard
    `{model, messages[], stream, temperature, max_tokens, ...}`
    envelope; `model` resolves as a literal id OR an
    `ai_routing_combos.name`. Streaming uses SSE chunks shaped exactly
    like OpenAI's so any compliant client (Cursor / Cline / Roo /
    Kiro / continue.dev / openai-python SDK) drops in unchanged.
  - Honours the same `super-ai-core.route` middleware stack as the
    rest of the package; review your guard before exposing publicly.
- **Real streaming on the three HTTP backends** — `AnthropicApiBackend`,
  `OpenAiApiBackend`, `GeminiApiBackend` now implement the new
  `Contracts\StreamableTextBackend` interface. Each backend opens
  `stream=true` against its native SSE endpoint and yields canonical
  envelopes (`{type:'text'|'thinking'|'tool_use_delta'|'usage'|'stop', ...}`).
  The OpenAI-compat proxy consumes these directly; host code that wants
  raw token-level streaming from the HTTP path now has a contract it
  can rely on.
- **Named routing combos (`ai_routing_combos`)** — a combo is an
  ordered `[{provider, model}, ...]` list resolved at dispatch time.
  Sits above the static `tier_map`. CRUD endpoints
  (`/super-ai-core/routing/combos[/{name}]`) + per-call override via
  `--combo=NAME` on `smart` / `squad` / `auto`. CLI flag drops
  `combo_entries` into dispatch options so the runner builds a
  fallback chain from the combo without re-querying the DB mid-run.
- **Multi-account round-robin (`Services\AccountRoundRobin`)** — picks
  the active, non-cooled-down account with the lowest
  `(priority, last_used_at)` tuple via an atomic compare-and-update,
  so two concurrent workers can't claim the same row. `cooldown()`
  marks accounts after a `QuotaExceededException` / null result
  (default 10 min, matching Claude Code / Codex rate-limit windows).
  Hosts add account rows to `ai_provider_accounts`; the Dispatcher
  picks them up automatically before falling back to the provider's
  built-in single-account credentials.
- **OAuth refresher registry (`Services\OAuth\*`)** — pre-emptive
  token refresh for the four CLIs that own OAuth state in
  on-disk JSON (Claude / Codex / Copilot / Kiro). Each refresher
  implements `OAuthRefresherInterface::refresh(?providerConfig): RefreshResult`;
  the registry picks one by provider key. Drive from cron via the
  new `super-ai-core:oauth-refresh` artisan command:
  ```php
  $schedule->command('super-ai-core:oauth-refresh')->everyTenMinutes();
  ```
- **Caveman mode (`--caveman`)** — output token compression reminder
  ported from 9Router. Default `super-ai-core.reminders.rules`
  entry — fires when `options['caveman']=='1'` and prepends a terse
  "respond in minimal tokens" system block. Empirically saves
  30-65% on output tokens for reasoning-quick tasks (not recommended
  for long-form writing). `--caveman` flag added to `smart` / `squad`.
- **`--no-skills` / `--no-session`** — Pi-style clean-mode toggles on
  `smart` / `squad`. `--no-skills` propagates `skills_disabled=true`
  down the chain so the codex / gemini progressive-disclosure skill
  index is suppressed; `--no-session` skips harness session
  persistence for ephemeral runs.

### Added — Pi-borrowed features

- **Session tree branching (`Services\SessionBranchManager`)** — Pi's
  `/tree` model where a session is a tree, not a line. Each branch
  has an 8-hex id, a `parent_branch_id` (null = trunk), and a
  `fork_from_entry_id` (the message you clicked `/tree` on). Exactly
  one branch is active per session at any time; switching auto-summarises
  the abandoned path as a `BranchSummaryEntry` so context isn't lost.
  Endpoints: `GET /sessions/{session}/tree`,
  `POST /sessions/{session}/fork`, `POST /sessions/{session}/switch`.
  Backed by the new `ai_session_branches` table.
- **Pi-style `kind` discriminator on `ask_user`** — `select` /
  `confirm` / `input` / `editor` (matches Pi's four dialog methods
  in `pi.dev/docs/latest/extensions §Extension UI`). The
  `/processes/questions` UI renders the right widget per call.
  Default `select` preserves 0.9.7 behaviour.
- **Progressive-disclosure skill index** — `Services\SkillIndexBuilder`
  emits a compact XML index of every `.claude/skills/*/SKILL.md`
  (name + description, NOT full body) and `CodexCliBackend` /
  `GeminiCliBackend` now prepend it to every prompt. The model reads
  the body via its existing file-read tool only when it picks a skill.
  Lets non-skill-native CLIs use the SuperAICore skill catalog at the
  same cost as Claude's native skill protocol. Suppress per-call with
  `options['skills_disabled']=true` or globally via `--no-skills`.
- **`task-results:export-jsonl`** — pi v3-compatible session JSONL
  exporter (one file per `metadata.session_id`). Opt-in via
  `--i-understand` (the format is lossy and not a backup); supports
  `--anonymize` for PII strip, `--since` filtering.

### Added — claude-octopus-borrowed

- **`super-ai-core:gh-watch`** — GitHub PR / CI reaction engine
  modelled on claude-octopus. Polls every active `ai_pr_watchers` row
  (uses ETags for conditional GETs to avoid burning rate limit) and
  fires per-row actions on new events:
  - `ask_user` → insert `ai_user_questions` row so the founder can
    decide via the `/processes` UI.
  - `spawn_squad` → invoke `php artisan squad "fix ${event}"` with
    the team listed in `action_payload.team`.
  - `webhook` → POST event JSON to `action_payload.url` with bearer.
  - `log` → append to `storage/logs/gh-watch.log`.
  Auth: `GITHUB_TOKEN` / `GH_TOKEN` env. Schedule via
  `$schedule->command('super-ai-core:gh-watch')->everyFiveMinutes()`
  or run as a daemon with `--loop=30`. Backed by the new
  `ai_pr_watchers` table.

### Added — Other

- **`Arrow\ArrowSerializer`** — minimal Apache Arrow IPC stream writer
  (schema header + record batch, primitive columns) so cross-agent /
  cross-process tabular payloads can round-trip 10–100× faster than
  JSON. Activated per-dispatch via `output_format: 'arrow'` plus a
  `tabular` / `rows` field on the result; the envelope gains a
  base64-encoded Arrow IPC stream. No `apache/arrow` PECL dependency —
  the 95% case (rows of strings + ints + floats + bools) is covered
  by hand. Hosts that need dictionaries / nested structs / compression
  swap in their own implementation via Dispatcher config.
- **`Services\RtkCompressorService`** — thin Laravel-host facade over
  SuperAgent SDK's `RtkPipeline` for structured-tool-output compression
  (git diff, search results, file listings). Per-process singleton so
  cumulative byte-savings stats are aggregatable. Falls back to
  passthrough when the SDK isn't installed.
- **`Services\AgentCatalog`** — reads `.claude/agents/*.md` from
  configurable roots, parses YAML frontmatter, classifies each agent
  into a layer (Strategy / Product / Engineering / Business / Security /
  Logistics / Financial / Career / Data / Real Estate / Content) via
  longest-prefix match on the filename. Drives the new
  `/super-ai-core/agents` browser UI. Config:
  `super-ai-core.agent_catalog.paths`. Falls back to
  `base_path('.claude/agents')` then `base_path('../.claude/agents')`.
- **`/super-ai-core/costs/savings`** — new dashboard view that
  surfaces RTK compression byte savings + Arrow vs JSON payload
  deltas. Driven by `RtkCompressorService::stats()`.
- **Cookbook (`examples/cookbook/`)** — five gs-quant-style narrative
  examples: `01-dispatcher-basics`, `02-prompt-caching`,
  `03-provider-rotation`, `04-resume-from-jsonl`,
  `05-tracing-quickstart`. Each one self-contained, with prerequisites,
  copy-pasteable code, expected output, and a `## See also` block.
- **`docs/commercialization-tiers.md`** — reference / future-state doc
  describing how a tiered offering (Cloud Dashboard / Managed Dispatcher /
  Enterprise overlays) could look on top of the MIT core. Nothing in
  this doc is implemented; it's borrowed in spirit from gs-quant
  (open SDK + gated backend) and JPMorgan Perspective (open engine +
  FINOS governance + commercial overlays).
- **`SUPPLY_CHAIN.md` + `.github/workflows/supply-chain.yml`** — Pi's
  `AGENTS.md` discipline applied to this package's `composer.json`:
  no lifecycle scripts allowed, `composer install --no-scripts` is the
  default, `composer audit` runs weekly + on every PR.
- **Translated advanced-usage docs §29 (the 0.9.7 wave)** —
  `docs/advanced-usage.zh-CN.md` and `docs/advanced-usage.fr.md` now
  carry the full opencode-borrowing section (per-file diff dashboard,
  AskUserTool integration, plan mode workflow, session reminders,
  per-agent permissions, PTY sessions, session sharing).

### Changed

- **`Dispatcher` constructor** gained an optional
  `Tracing\TraceCollector $tracer = null` argument. When omitted (host
  apps that don't boot the service provider), the dispatcher falls
  back to `TraceCollector::getInstance()` so tracing remains a no-op
  rather than a NullPointer.
- **`SuperAICoreServiceProvider::register()`** wires
  `TraceCollector::class → TraceCollector::getInstance()` and injects
  it into `Dispatcher`. Hosts that override the binding still work.
- **`smart` / `squad` commands** gain four flags
  (`--no-skills`, `--no-session`, `--combo`, `--caveman`). Defaults
  preserve 0.9.7 behaviour.
- **`BackendRegistry`** registers `QwenCliBackend` when
  `super-ai-core.backends.qwen_cli.enabled=true` (the default).
- **`super-ai-core.pricing.*`** gains eight Qwen rows
  (qwen3.7-max / -plus / qwen3.6-max-preview / qwen3-max /
  qwen3.5-plus / -flash / qwen3-coder-plus / qwen3-vl-plus).
- **`super-ai-core.reminders.rules`** ships one default entry
  (`caveman-mode`), commented examples for the rest.

### Added — SDK 1.0.6 plumbing

The composer constraint moves to `^1.0.6`. Four targeted wirings land
on top of the bump:

- **`Services\RtkCompressorService::register()`** *(0.9.8)* — host
  facade gains a `register(string $toolName, CompressorInterface)`
  passthrough so hosts can register custom compressors against the
  shared pipeline. SDK 1.0.6 now ships six real compressors out of
  the box, so `compress('git_diff', $raw)` returns real byte savings
  immediately (no more passthrough fallback) — typical 30-65% on
  diff / grep payloads.
- **`Hooks\HookEvent::PR_EVENT` from `GhWatchCommand`** *(0.9.8)* —
  every event the watcher dispatches now also fires the SDK
  `PR_EVENT` hook with a `PrWatchHookData` payload, so any host
  registering SDK-side listeners (claude-octopus-style auto-spawn,
  Slack notifier, audit logger) observes the same stream as
  SuperAICore's local action handler. Best-effort wrapped — a missing
  `HookRegistry` / pre-1.0.6 SDK skips silently.
- **`Agent::steer()` / `followUp()` exposed via `SuperAgentBackend`**
  *(0.9.8)* — two new dispatch options:
  - `follow_up_queue: ['next prompt', 'and another']` pre-seeds the
    agent's follow-up queue. After the main `run()` returns, the
    agent drains the queue FIFO (up to 8 by default) and runs each
    as a continuation against the same conversation.
  - `on_agent_built: fn(Agent $a) => …` hands the constructed Agent
    to the caller before `run()`, so a host can register the agent
    against a session-keyed broker. A sibling process (HTTP
    question-answer endpoint, ACP `session/steer` RPC) can then
    call `Agent::steer($msg)` mid-run to prepend a correction at the
    next iteration boundary.
- **`qwen-anthropic` provider type** *(0.9.8)* — new
  `AiProvider::TYPE_QWEN_ANTHROPIC` registered against the
  superagent backend. Routes through SDK 1.0.6's
  `QwenAnthropicProvider` (subclass of `AnthropicProvider`) — Qwen
  3.7 Max via DashScope's Anthropic-protocol endpoint, drop-in
  substitute for Claude in fallback chains. Env: `DASHSCOPE_API_KEY`
  (canonical) + `QWEN_API_KEY` (alias).

### Notes

- **SDK constraint bumped to `^1.0.6`.** Pre-existing features in
  this release remain host-side; the SDK bump unlocks the four
  wirings above and makes RtkCompressorService's six built-in
  compressors usable without code changes in host apps.
- **SDK pieces NOT wired yet (intentional):** `Tracing\TraceCollector`
  (SDK ships its own — SuperAICore keeps the local one because it's
  already tied to Laravel storage paths and the `/traces` UI),
  `Arrow\ArrowSerializer` (same shape both sides; both available),
  `Conversation\BranchManager` (SDK ships pure tree algebra; our
  `Services\SessionBranchManager` already wraps the DB persistence
  side), `Providers\StreamEventTranslator` (canonical 13-event
  envelope; our HTTP backends emit a 5-event legacy envelope that
  the OpenAI-compat proxy already consumes — hosts that want canonical
  events call the SDK translator directly).
- **Migrations are additive.** Pre-0.9.8 rows are not touched.
- **Tracing has zero runtime cost when disabled.** Setting
  `AI_CORE_TRACE_ENABLED=false` turns every emit into a no-op; the
  file system is never touched.
- The OpenAI-compatible proxy honours the same auth middleware as the
  rest of `/super-ai-core/*`. Review your `super-ai-core.route.middleware`
  stack before exposing it to external clients.
- **Local vs SDK duplicates kept on purpose.** SDK 1.0.6 ships its
  own `Tracing\TraceCollector` / `Arrow\ArrowSerializer` /
  `Conversation\BranchManager` on the same day SuperAICore 0.9.8
  shipped local versions. The local ones stay because they're tied
  to Laravel storage paths, the `/traces` UI, the
  `ai_session_branches` table, and the `/super-ai-core/agents`
  browser respectively. Both implementations follow the same
  conceptual shapes (Chrome Trace Event JSON, Arrow IPC,
  parent_branch_id tree), so swapping later is cheap.

## [0.9.7] — 2026-05-20

**SDK 1.0.5 bump + opencode-borrowed feature wave.** SDK constraint
moves `^1.0` → `^1.0.5`, picking up cross-provider handoff transcoder
fixes (0.9.5), `BashArity` permission matching, the opencode 7-section
structured summary template, the real LSP client + `LSPTool`,
`LlmLoopChecker` semantic loop detection, the ACP v1 stdio server, and
the Gemini 3.5 / 3.x family with thinking + grounding + thought-part
wiring. On top of that bump, ten patterns are ported from
[opencode](https://github.com/sst/opencode) (`packages/opencode/src/`)
and surfaced as first-class SuperAICore features: per-file diff
summaries with revert, a mid-run HITL question tool, snapshot retention,
session reminders, per-agent permission rulesets, sub-agent permission
derivation, plan mode (plan → approve → build), long-lived PTY shell
sessions, and a session-share host queue.

Every binding is additive and opt-in — pre-0.9.7 behaviour is preserved
unless you enable a flag, pass a new option, or resolve a new service
from the container.

```bash
composer update forgeomni/superaicore forgeomni/superagent
php artisan migrate                                              # ships four new tables / columns
php artisan vendor:publish --tag=super-ai-core-config --force   # optional, picks up the new config blocks
```

Four new migrations land in this release (`pre_snapshot` /
`post_snapshot` / `file_diff_summary` columns on `ai_usage_logs`;
three new tables `ai_user_questions`, `ai_pty_sessions`,
`ai_session_shares`). Seven new singleton bindings:
`SnapshotDiffService`, `RemindersResolver`, `PermissionEvaluator`,
`SubagentPermissionDeriver`, `PtyService`, `ShareSessionService`, plus
the existing services gain optional dependencies. One new orchestrator
(`CliPlanOrchestrator`), four new controllers (`QuestionController`,
`RevertController`, `PtyController`, `ShareController`), one new
Artisan command (`super-ai-core:snapshot-prune`), one new SDK tool
(`Services\Tools\AskUserTool`).

### Added — opencode borrowings

Ten patterns ported from opencode (`packages/opencode/src/`). Each is
opt-in via config / env flag and degrades to no-op when the surrounding
wiring isn't present.

- **P0-1 — Per-file diff summary (`Services\SnapshotDiffService`).**
  SuperAgentBackend now snapshots the worktree before + after every
  dispatch via SDK's `GitShadowStore` and produces a structured
  `{additions, deletions, files, diffs: [{file, additions, deletions,
  status, patch, truncated}]}` envelope. Three new columns land on
  `ai_usage_logs`: `pre_snapshot`, `post_snapshot`,
  `file_diff_summary`. The `/usage` page surfaces a `+N −M` badge with
  a side-panel diff viewer on every row that produced a non-empty
  diff. Modeled on opencode `session/summary.ts` +
  `snapshot.diffFull()`. Diff envelope truncates at 256 KB per file
  and 200 files total to keep UsageLog rows queryable. Resolved
  project root: `options['project_root']` →
  `super-ai-core.snapshot.project_root` → `base_path()` → `getcwd()`.
- **P0-2 — Mid-run HITL question tool (`Services\Tools\AskUserTool`).**
  When `super-ai-core.tools.ask_user_enabled` is on, SuperAgentBackend
  attaches an `ask_user` SDK tool the model can call to interrupt and
  ask for a clarifying decision. The tool inserts an
  `ai_user_questions` row, polls every 500ms until the operator answers
  via `POST /processes/questions/{id}/answer`, then returns the answer
  as a tool result. Default timeout 600s (capped at 3600s). The
  `/processes` page polls `/processes/questions` every 4s and renders
  the pending row as an inline answer card (option buttons when the
  model supplied choices, free-form text input otherwise). Modeled on
  opencode `tool/question.ts`.
- **P0-3 — Snapshot retention (`super-ai-core:snapshot-prune`).** Walks
  every shadow.git repo under `GitShadowStore::defaultBaseDir()`
  (default `~/.superagent/history`), expires the reflog of commits
  older than `--days`, and runs `git gc --prune=now`. Defaults to
  `super-ai-core.snapshot.retention_days` (fallback 7). Supports
  `--dry-run` and `--base-dir`. Schedule from the host app's
  `Kernel.php` with `$schedule->command('super-ai-core:snapshot-prune')
  ->daily()`. Modeled on opencode's `prune = "7.days"` policy.
- **P1-4 — Session reminders (`Services\RemindersResolver`).**
  Config-driven synthetic system-prompt block injector. Each rule has
  a `when` predicate (dotted-path keys → fnmatch globs against
  dispatch options/metadata) and a `text` body. Bodies of matching
  rules concatenate with blank lines and prepend to the caller's
  system prompt. Config lives in `super-ai-core.reminders.rules`.
  Modeled on opencode `session/reminders.ts`.
- **P1-5 — Revert worktree to pre-dispatch snapshot
  (`Http\Controllers\RevertController`).** `POST /usage/{id}/revert`
  reads `pre_snapshot` off the UsageLog row and calls
  `GitShadowStore::restore()`. Tracked files revert to the snapshot
  state; untracked files added since the snapshot are LEFT in place
  (SDK contract — opencode `session/revert.ts` semantics). Gated by
  `super-ai-core.snapshot.revert_enabled` (default true). The `/usage`
  page surfaces a ↩ button on every row that recorded a `pre_snapshot`.
- **P1-6 — Per-agent permission ruleset
  (`Services\PermissionEvaluator`).** Opencode `permission/evaluate.ts`
  port — each rule is `{permission, pattern, action}`; the LAST
  matching rule wins; default action is `ask`. Hosts declare per-agent
  permission maps in `super-ai-core.agents.{name}.permission` (string
  action OR per-pattern map). SuperAgentBackend projects the ruleset
  onto the SDK agent's `withAllowedTools()` /  `withDeniedTools()`
  when the caller didn't pass explicit lists.
- **P2-7 — Plan mode (`Modes\CliPlanOrchestrator`).** Three-phase
  plan → approve → build workflow lifted from opencode `agent/agent.ts`
  + `tool/plan.ts`. Phase 1 dispatches a plan-only run with edit tools
  denied; the model writes a markdown plan to
  `.superagent/plans/{session}.md`. Phase 2 opens an `ai_user_questions`
  row with `[Approve, Reject]` options. Phase 3 hands off to the build
  backend with a synthetic prompt that includes the approved plan
  text. Registered with `CliModeRouter` under mode name `plan`. Auto-
  approves when HITL is disabled (`tools.ask_user_enabled=false`) so
  the orchestrator stays usable in CI. Config:
  `super-ai-core.modes.plan.*`.
- **P2-8 — Sub-agent permission derivation
  (`Services\SubagentPermissionDeriver`).** When a parent agent
  dispatches a sub-agent, the deriver reads either
  `parent_denied_tools` (explicit pass-through) or
  `metadata.parent_agent` (resolved via the `PermissionEvaluator`'s
  per-agent ruleset) and merges the parent's deny set into the
  child's. Children can never elevate. Modeled on opencode
  `agent/subagent-permissions.ts`.
- **P3-9 — PTY long-lived shell sessions (`Services\PtyService` +
  `Http\Controllers\PtyController`, Phase 1).** `proc_open`-backed
  shell sessions with a flat per-session log file + cursor-keyed long-
  poll endpoint. `POST /pty/sessions` spawns; `GET
  /pty/sessions/{id}/poll?cursor=N` returns the slice since cursor +
  the new cursor; `POST /pty/sessions/{id}/kill` terminates. Opt-in
  via `super-ai-core.pty.enabled` (default false). Phase 2 (deferred)
  will upgrade the wire to WebSocket via Laravel Reverb / Soketi
  without changing the cursor-slice protocol. Stdin writes deferred
  to Phase 2 because PHP can't keep a pipe alive across HTTP requests
  without a persistent worker.
- **P3-10 — Session share host queue (`Services\ShareSessionService` +
  `Http\Controllers\ShareController`).** Mints a `{share_id, secret,
  share_url}` triple per session, then pushes the session's UsageLog
  rows + attached `file_diff_summary` payloads to a configured remote
  sharer (`super-ai-core.share.remote_url`) as a Bearer-authenticated
  POST. Falls back to a local URL template
  (`super-ai-core.share.local_url_template`, `{share_id}` placeholder)
  when no remote is configured. Two operating modes: REMOTE forwards
  to an external service; LOCAL renders the URL against the host's
  own SuperAICore instance for intranet deployments. Modeled on
  opencode `share/share-next.ts`, scope-trimmed (no daemon queue —
  re-sync fires on every `POST /share/sessions/{sessionId}/create`).

### Added — SDK 1.0.5 plumbing

- **`super-ai-core.tools.lsp_enabled` (env `AI_CORE_TOOLS_LSP`).**
  Default OFF. When on, `SuperAgentBackend` prepends `lsp` to the
  implicit `load_tools` list so the agent can call SDK 1.0.5's bundled
  LSP client for diagnostics / hover / definition / touch against any
  of the 9 language servers (phpactor / intelephense / gopls /
  rust-analyzer / pyright / typescript-language-server / clangd /
  bash-language-server / zls). Lazy via SDK `BuiltinToolRegistry`
  classMap.
- **`super-ai-core.compression.summary_prompt` (env
  `AI_CORE_COMPRESSION_SUMMARY_PROMPT`).** Default null. Set to
  `'structured'` to pick SDK 1.0.5's opencode-ported 7-section Markdown
  summary template (Goal / Constraints / Progress / Decisions / Next
  Steps / Critical Context / Relevant Files) — ~30-50% smaller than the
  default 9-section prose summary and preserves blocked-item state
  across consecutive compactions. Per-call override via
  `options['summary_prompt']` still wins.
- **`SuperAgentBackend::buildPerCallOptions()` forwards Gemini 3.5
  features.** `thinking`, `grounding` / `google_search`, and
  `url_context` pass straight through to `Agent::run($prompt,
  $options)`. Silently ignored by non-Gemini providers (GeminiProvider
  gates on `modelSupportsThinking()` for the thinking branch and only
  appends the `googleSearch` / `urlContext` blocks to its own tools[]).
- **`EngineCatalog` gemini-cli engine** now lists `gemini-3.5-pro /
  -flash / -flash-lite`. Default model remains `gemini-2.5-pro` until
  the system gemini CLI accepts the 3.5 slugs; SDK callers using `sdk:`
  tags can already drive them today.
- **`CopilotModelResolver`** `gemini` family alias now points at
  `gemini-3-pro-preview` (the slug copilot CLI accepts), so callers
  passing `gemini` get a resolvable id rather than the resolver
  refusing to map.

### Changed

- **Composer constraint** — `forgeomni/superagent` `^1.0` → `^1.0.5`.
- **`SuperAgentBackend` constructor** gained four optional
  dependencies (`SnapshotDiffService`, `RemindersResolver`,
  `PermissionEvaluator`, `SubagentPermissionDeriver`). `BackendRegistry`
  resolves the backend via the container so these wire automatically;
  the bare `new SuperAgentBackend($logger)` constructor still works for
  tests that don't boot the package's service provider.
- **`EloquentUsageRepository::record()`** filters input to
  `AiUsageLog::$fillable` before insert, so envelope fields the
  Dispatcher attaches that don't have columns yet (legacy schemas) no
  longer trip Eloquent's MassAssignment guard.

### Database

Run `php artisan migrate` after upgrading. All four migrations are
additive and reversible:

- `2026_05_20_000001_add_diff_summary_and_snapshots_to_ai_usage_logs.php`
  — adds `pre_snapshot` (varchar 64, nullable), `post_snapshot` (varchar
  64, nullable), `file_diff_summary` (json, nullable) to `ai_usage_logs`.
- `2026_05_20_000002_create_ai_user_questions_table.php` — new table
  backing `AskUserTool`. Columns: `session_id`, `process_id`,
  `agent_label`, `question`, `options` (json), `metadata` (json),
  `answer`, `status` (pending|answered|cancelled|timed_out),
  `answered_at`. Indexes on `status`, `process_id`, `session_id`.
- `2026_05_20_000003_create_ai_pty_sessions_table.php` — new table
  backing `PtyService`. Columns: `title`, `command`, `cwd`, `pid`,
  `status` (running|exited|killed), `exit_code`, `log_path`, `cursor`,
  `metadata` (json), `exited_at`.
- `2026_05_20_000004_create_ai_session_shares_table.php` — new table
  backing `ShareSessionService`. Columns: `session_id`, `share_id`
  (unique), `secret`, `remote_url`, `share_url`, `status`
  (active|revoked|failed), `metadata` (json), `synced_at`.

### Routes

- `GET  /processes/questions` — list pending HITL questions
- `POST /processes/questions/{id}/answer` — POST `{answer}` to unblock
- `POST /processes/questions/{id}/cancel` — POST to abort the polling
  tool
- `POST /usage/{id}/revert` — restore worktree to a row's `pre_snapshot`
- `POST /pty/sessions` — spawn a long-lived shell session
- `GET  /pty/sessions/{id}` — read session metadata
- `GET  /pty/sessions/{id}/poll?cursor=N` — long-poll for new output
- `POST /pty/sessions/{id}/kill` — terminate the session
- `POST /share/sessions/{sessionId}/create` — mint a share link
- `GET  /share/sessions/{sessionId}` — read share metadata
- `POST /share/sessions/{sessionId}/destroy` — revoke a share

### Why

SDK 1.0.5 was a pure capability release — no breaking changes, only
opt-in features. opencode is the most actively-developed open-source
agent framework today; mining its `packages/opencode/src/` for
patterns that fit SuperAICore's audit-log-first architecture gives
the host a meaningful step up on visibility (per-file diff banner +
revert), interactivity (mid-run HITL), safety (per-agent permissions,
sub-agent perm inheritance, snapshot retention), workflow shape (plan
mode), and operability (PTY streams, session sharing). Every feature
keeps the pre-existing dispatch envelope byte-identical when the
relevant config knob is off.

## [0.9.6] — 2026-05-16

**Squad multi-agent + SDK 1.0.0 companion bindings.** Six SDK 0.9.8
primitives that landed alongside the 0.9.1 wave (`AutoModelStrategy`,
`CacheAwareCompressor`, `UntrustedInput`, `TokenBucket`,
`AdHocMemoryProvider`, `Conversation\Fork`, `AgentDepthGuard`,
DeepSeek FIM) are now wrapped behind first-class services, and SDK
1.0.0's `Squad` peer-collaboration pipeline lands as a tenth dispatcher
adapter (`squad`) with per-step model tiering, on-disk checkpointing,
and optional cost-cap downshift. SDK constraint moves
`^0.9.8` → `^1.0`. Every binding is additive and opt-in — pre-0.9.6
behaviour is preserved unless you enable a flag, pass a new option, or
resolve a new service from the container.

```bash
composer update forgeomni/superaicore forgeomni/superagent
php artisan vendor:publish --tag=super-ai-core-config --force   # optional, picks up the new config blocks
```

No migrations. Six new singleton bindings: `AutoModelRouter`,
`CompressionStrategyFactory`, `UntrustedInputHelper`,
`RateLimiterRegistry`, `AdHocMemoryRegistry`, `ConversationForkService`,
`DeepSeekFimService`. One new backend: `SquadBackend`. Two new console
commands: `smart` and `squad` (passthrough to the vendor `superagent`
CLI).

### Added

- **`SquadBackend` — SDK 1.0.0 adaptive cross-model pipeline.**
  Registered as the tenth dispatcher adapter when
  `super-ai-core.squad.enabled=true` and the SDK is installed. Drives
  a heuristic-decomposed pipeline via `Squad\TaskDecomposer` +
  `Squad\PeerOrchestrator`, with one model per subtask (mapped through
  `Squad\ModelTierMap`), per-step `SquadCheckpointStore` writes, peer-
  to-peer messaging via SDK's `PeerMailbox`, and an optional cost cap
  with automatic downshift at 80% budget. Mid-run failures leave the
  checkpoint on disk so the host can resume by re-dispatching with
  the same `squad_id` and `checkpoint_dir`. Envelope carries
  `squad: {squad_id, step_count, completed, roles, checkpoint_path,
  mailbox_log}` for host UIs. Cost is summed across step dispatches;
  per-step `cost_usd` rolls up onto the envelope.
- **`super-ai-core.squad` config block** — global tier map
  (`trivial` → `claude-haiku-4-5`, `easy` → `deepseek-v4-flash`,
  `moderate` → `claude-sonnet-4-6`, `hard` → `deepseek-v4-pro`,
  `expert` → `claude-opus-4-7`), `max_cost_usd` budget cap,
  `checkpoint_dir`. Override per-dispatch via `options.tier_map`.
  Env toggles: `AI_CORE_SQUAD_ENABLED`,
  `AI_CORE_SQUAD_BACKEND_ENABLED`, `AI_CORE_SQUAD_MAX_COST`,
  `AI_CORE_SQUAD_CHECKPOINT_DIR`.
- **`AutoModelRouter` — `/model auto` heuristic for any dispatch
  path.** Wraps SDK 0.9.8 `Routing\AutoModelStrategy` behind a host
  service so the Claude / Codex / Gemini CLI backends can opt in once
  their `provider_config` declares `auto_models: {pro, flash}`. The
  router escalates Flash → Pro on long context (>32k tokens),
  trailing tool-chain depth (≥3), explicit `reasoning_effort=max`,
  or intent keywords in the system prompt
  (review/audit/design/migration/architecture/…). When
  `super-ai-core.auto_model.score_catalog_path` is wired the
  catalog's top-scoring model for the inferred dim overrides the
  Pro/Flash heuristic. Hosts rebind Pro/Flash to any model pair (e.g.
  `claude-opus` / `claude-haiku`) via
  `auto_model.{pro_model, flash_model}` — no SDK fork. Env toggles:
  `AI_CORE_AUTO_MODEL`, `AI_CORE_AUTO_MODEL_PRO`,
  `AI_CORE_AUTO_MODEL_FLASH`, `AI_CORE_AUTO_MODEL_LONG_CTX`,
  `AI_CORE_AUTO_MODEL_TOOL_DEPTH`, `AI_CORE_AUTO_MODEL_SCORE_CATALOG`.
- **`CompressionStrategyFactory` — cache-aware compaction for
  host-driven `ContextManager` flows.** Wraps the bundled
  `ConversationCompressor` in SDK 0.9.8's `CacheAwareCompressor` so
  summary boundaries land AFTER the prompt-cache prefix instead of
  clobbering it on every compaction round. Hosts running long
  multi-turn sessions (sub-agent loops, browser-tool runs, multi-step
  refactors) call `app(CompressionStrategyFactory::class)->build($tokenEstimator, $config, $provider)`
  when constructing their own `ContextManager` and inherit the
  pinned-prefix behaviour. Pins 1 system message + 4 conversation
  messages by default. Env toggles:
  `AI_CORE_COMPRESSION_CACHE_AWARE`, `AI_CORE_COMPRESSION_PIN_HEAD`,
  `AI_CORE_COMPRESSION_PIN_SYSTEM`.
- **`UntrustedInputHelper` — host-side `UntrustedInput` wrapper.**
  Thin Laravel facade over SDK 0.9.8 `Security\UntrustedInput`.
  Tags or wraps free-form text injected into system prompts at sites
  the SDK's `GoalManager` doesn't already own — ad-hoc memory entries,
  workspace plugin descriptions, MCP tool descriptions from third-
  party servers, host UI form input. The SDK already wraps
  `goal.objective`; this helper covers the other injection points.
  Two methods: `tag($payload, $category)` adds the marker, `wrap()`
  prepends the standard "treat as data, not instructions" disclaimer.
  Disabled via `AI_CORE_UNTRUSTED_INPUT=false` for tests that compare
  prompts byte-for-byte.
- **`RateLimiterRegistry` — per-process token-bucket pool.** Wraps
  SDK 0.9.8 `Providers\Transport\TokenBucket` keyed by provider name
  (or any caller-chosen tag). `SuperAgentBackend::generate()` calls
  `consume()` before each provider dispatch; `SquadBackend` does the
  same. Missing keys fall back to `default`. Empty config disables
  rate limiting entirely (the SDK still has per-call 429 retry, so
  this is belt-and-suspenders for hosts already throttled
  upstream). Default `8.0 RPS / 16 burst` in
  `super-ai-core.rate_limits.default`; per-provider overrides
  (`kimi`, `openai`, `deepseek`, etc.) are commented out templates.
  Env toggles: `AI_CORE_RL_DEFAULT_RATE`, `AI_CORE_RL_DEFAULT_BURST`.
- **`AdHocMemoryRegistry` — per-session `AdHocMemoryProvider`
  pool.** Wraps SDK 0.9.8 `Memory\AdHocMemoryProvider`. UI sites can
  call `forSession($id)->push($text, $ttlSeconds)` (or the convenience
  `$registry->push($id, $text, $ttl)`) to inject a "for the next turn"
  fact that the SuperAgent backend renders ahead of the prompt. Per-
  session isolation means chat A's facts never leak into chat B. By
  design, memory is process-local — durable facts belong in
  `MEMORY.md` / `BuiltinMemoryProvider`. The registry is a
  container singleton at `app(AdHocMemoryRegistry::class)`.
- **`ConversationForkService` — codex `/side` semantics as a host
  service.** Wraps SDK 0.9.8 `Conversation\Fork`. Two methods:
  `start($parentMessages)` snapshots the current list and returns a
  fork handle; `finish($fork, $action, $indexes?)` collapses the side
  back to the parent with `discard` / `promote(...indexes)` /
  `promoteAll` semantics. Stateless — fork lifetime is the host's
  call (typically a UUID in the URL). Useful for chat UIs that want
  "branch and try a different model, promote only the useful side
  messages back".
- **`DeepSeekFimService` — standalone DeepSeek FIM (Fill-In-the-Middle)
  helper.** Wraps SDK 0.9.8 `DeepSeekProvider::completeFim()` against
  the `beta` region. The chat-shaped `Backend` abstraction doesn't fit
  prefix-completion / inline-fill use cases, so hosts building IDE-
  style completion features (`function calculateTax(\$amount) {`
  + `\n}` → infer the body) call this service directly. No
  memoisation — FIM dispatches are short-lived (one IDE keystroke =
  one call). Env: `DEEPSEEK_API_KEY` (also reads
  `super-ai-core.deepseek.api_key`).
- **`super-ai-core.agents.max_depth` — sub-agent recursion cap.**
  Forwarded to SDK 0.9.8's static `Swarm\AgentDepthGuard::setMax()`
  during service-provider boot. Negative / unset preserves the SDK
  default (5). Per-process override via `SUPERAGENT_MAX_AGENT_DEPTH`
  env var.
- **Per-call `reasoning_effort` on `SuperAgentBackend`** — three-tier
  dial (`off` / `high` / `max`) forwarded as the SDK's
  `reasoning_effort` per-call option. Routes to the right body shape
  per provider (top-level field for most; `chat_template_kwargs` for
  NVIDIA NIM, etc.) via SDK's `SupportsReasoningEffort` capability
  interface. Silently ignored by providers that don't implement it.
  Also feeds `AutoModelRouter`'s escalation heuristic when set to
  `max`.
- **`Agent::switchProvider()` handoff** — when `options['handoff']` is
  passed (`{provider, config, policy}`), `SuperAgentBackend` calls
  `Agent::switchProvider()` before dispatch. SDK 0.9.5's
  `HandoffPolicy::default()` / `preserveAll()` / `freshStart()` are
  the three presets. Envelope gains `handoff_token_status:
  {tokens, window, fits, model}` so dashboards can warn "history
  won't fit under <target_model> — compress before the next turn".
  Failure to construct the new provider leaves the original agent
  untouched (SDK contract).
- **`smart` and `squad` console commands** — passthrough to vendor
  `superagent smart` / `superagent auto --squad`. Forwards argv
  verbatim so SuperAICore CLI users get the SDK orchestration
  surface without re-implementing it in PHP. `--binary=…` opts out
  of the bundled `vendor/forgeomni/superagent/bin/superagent`
  auto-discovery. `squad` accepts `--no-squad` to revert to the
  legacy master-slave path for A/B comparisons.

### Changed

- **Composer constraint** — `forgeomni/superagent` `^0.9.8` → `^1.0`.
  SDK 1.0.0 ships the Squad pipeline (`Squad\PeerOrchestrator`,
  `TaskDecomposer`, `ModelTierMap`, `SquadCheckpointStore`,
  `SubTask`, `DifficultyClass`, `SquadDispatchRequest`) plus stable
  reorganisations of the 0.9.x companion primitives.
- **`SuperAgentBackend` constructor** — accepts an optional
  `RateLimiterRegistry` so the throttle path can stay null-safe when
  the package service provider hasn't booted (pure-PHPUnit tests,
  custom CLI entrypoints). Falls back to a container lookup at call
  time when no instance was injected; degrades silently to no-op
  when neither path resolves. Existing call sites keep working.
- **`BackendRegistry`** — registers `SquadBackend` immediately after
  `SuperAgentBackend` when SDK 1.0.0 is on the classpath and
  `super-ai-core.backends.squad.enabled` is on (default `true`).

### Notes

- **None of the new wrappers change SDK call shapes.** `Goals\GoalManager`,
  `Security\UntrustedInput`, `Swarm\AgentDepthGuard`,
  `Providers\Transport\TokenBucket`, `Conversation\Fork`,
  `Memory\AdHocMemoryProvider`, `Routing\AutoModelStrategy`, and
  `Context\Strategies\CacheAwareCompressor` are wrapped behind host
  services so swapping the SDK class for a host-owned implementation
  is a single container rebind. Tests that need byte-identical
  dispatch comparisons can disable the wrappers via the env toggles
  listed above.
- **`SquadBackend` reuses the operator's existing SuperAgent provider
  credentials.** Each step constructs a provider via the same
  `ProviderRegistry::createForHost()` adapter `SuperAgentBackend`
  uses, so a host that already routes through Anthropic + DeepSeek
  picks up Squad routing without re-configuring credentials.
- **Squad mid-run cost-cap downshift.** When the running cost crosses
  80% of `max_cost_usd`, future steps are pushed down one tier
  (`hard → moderate`, `moderate → easy`, etc.) until the cap is
  reached or the pipeline completes. The envelope's `squad.roles`
  array reflects the final tier each step ran at.
- **Auto-discovery of the vendor binary.** `smart` and `squad` walk
  three relative paths (`vendor/forgeomni/superagent/bin/superagent`
  and two ancestor variants for projects that vendor the SDK above
  the package root). Pass `--binary=/abs/path/to/superagent` when the
  host installs the SDK outside `vendor/`.

## [0.9.5] — 2026-05-11

**Side-panel trigger hardening — Blade attribute encoding fix for
process and usage views.** The browser-screenshot badge on the
processes index and the metadata-inspector link on the usage index
were building their `data-side-panel-trigger` payloads with an inline
`@json([...])` literal embedded directly inside a single-quoted HTML
attribute. The inner string values themselves contained single
quotes, double quotes, and raw HTML (`<img src="…">`), which broke
Blade's attribute quoting and produced malformed markup on certain
rows — most visibly when a screenshot URL or metadata blob contained
characters that needed escaping a second time. The payload is now
assembled in a named `@php` variable and passed through `@json($var)`
as a whole, so attribute encoding happens exactly once and the side
panel opens with the intended title and body in every row.

### Fixed

- **`resources/views/processes/index.blade.php`** — the per-process
  "screenshot" badge now builds its side-panel payload via
  `$__screenshotPayload` and renders the image URL through `e(...)`
  before interpolating it into the `<img src="…">` snippet. Earlier
  releases interpolated `$proc->latest_screenshot_url` directly into
  the HTML inside an inline array literal, so URLs containing quotes
  or ampersands could close the attribute or break the trigger JSON.
- **`resources/views/usage/index.blade.php`** — the metadata inspector
  link now resolves the cache-warning flag in a multi-line `@php`
  block and assembles the side-panel payload as `$__sidePanelPayload`
  before emitting it via `@json(...)`. The previous single-line
  `@php(...)` + inline array form produced unstable HTML when the
  metadata column contained nested JSON with quotes, so the side
  panel intermittently opened with a truncated or empty body.

### Notes

- Behaviour change is limited to view rendering on the processes and
  usage index pages; no backend, config, or API surface moved. Hosts
  that customised these Blade files should mirror the `@php` block
  pattern when reintroducing their overrides.

## [0.9.2] — 2026-05-05

**TaskRunner reliability wave — fallback handoff + operator-grade
continuation semantics.** Adds per-run backend fallback for long operator
tasks that hit CLI/API usage limits mid-flight, then rounds it out with
configuration, context handoff, observability, and host-facing persistence
patterns. The requested backend is still attempted first on every run, so
a recovered primary backend naturally takes traffic again without sticky
failover state. When fallback is active, TaskRunner can carry a short
failure/log excerpt into the next backend and returns a structured attempt
report on the envelope.

### Added

- **`TaskRunner` fallback chains** — pass `fallback_chain` as an ordered
  backend list (`['claude_cli', 'codex_cli', 'gemini_cli']`) or as
  `'auto'`. The primary backend is always prepended when omitted, duplicate
  entries are removed, and recursive fallback is disabled per attempt so
  each backend owns its normal dispatch path.
- **Workload-specific fallback policy** — `fallback_profile` now resolves
  `super-ai-core.task_fallback.chains_by_profile`, while `task_type` and
  `capability` resolve `chains_by_task_type` /
  `chains_by_capability`, and metadata keys (`task_kind`, `priority`,
  `requires_tools`) resolve `chains_by_metadata`. Precedence is explicit
  `fallback_chain` → profile → task type → capability → metadata → global
  chain → auto. Hosts can route coding, research, summarisation, and
  maintenance tasks through different recovery chains without branching
  around `TaskRunner`.
- **Built-in policy presets** — `coding`, `research`, `summarise`,
  `maintenance`, `cheap`, `fast`, and `headless` profiles ship in config
  and can be overridden by hosts.
- **Automatic fallback discovery** — `fallback_chain => 'auto'` builds an
  ordered chain from registered/enabled backends, defaulting to
  `claude_cli → codex_cli → gemini_cli → kimi_cli → copilot_cli →
  kiro_cli → superagent → anthropic_api → openai_api → gemini_api`.
  Optional availability checks use `BackendRegistry::get()->isAvailable()`
  before adding a backend to the chain.
- **Limit-aware fallback matching** — `fallback_on` controls which failed
  outputs permit handoff. Defaults cover common quota/rate-limit signals
  such as `rate limit`, `usage limit`, `quota`, `429`,
  `too many requests`, `insufficient_quota`, `billing`, `budget`, and
  `usage_not_included`. Non-matching failures stop on the original backend
  instead of masking real prompt/tool errors.
- **Failure-context inheritance** — `inherit_failure_context` defaults to
  true. The next backend receives the original prompt plus a compact
  "SuperAICore fallback handoff" block with the previous backend, exit
  code, and output/log excerpt so it can continue the same task without
  retrying the blocked path.
- **`TaskResultEnvelope::$fallbackReport`** — successful and failed
  fallback runs now expose attempted backend, attempt number, success flag,
  retryability, next backend, exit code, model, duration, usage log id,
  cost, billing model, log file, and a short error/output summary. The field
  is included in `toArray()` for host storage layers, and is present even
  when the primary succeeds while a chain was active.
- **Per-attempt Dispatcher metadata injection** — every fallback attempt now
  carries `metadata.fallback_active`, `fallback_chain`, `fallback_attempt`,
  `fallback_primary_backend`, `fallback_backend`, and
  `fallback_chain_index`, even when the caller supplied no metadata. Usage
  rows and host analytics can group attempts without parsing logs or the
  final envelope.
- **Fallback decision report** — `TaskResultEnvelope::$fallbackDecision`
  records the chain source, skipped backends, decision events, and cumulative
  fallback cost so UIs can explain why a run continued or stopped.
- **Backend cooldown / circuit breaker** — optional
  `task_fallback.cooldown.{enabled,seconds,min_failures}` cools a backend
  after retryable failures and skips it on later runs until the cooldown
  expires. Laravel cache is used when present; pure-PHP callers get
  process-local cooldown memory.
- **Attempt and cost guards** — `fallback_max_attempts` /
  `task_fallback.max_attempts` and `fallback_max_cost_usd` /
  `task_fallback.max_cost_usd` stop chain traversal before a runaway auto
  chain can burn through every backend.
- **Backoff between attempts** — `fallback_backoff_ms` plus
  `fallback_backoff_strategy=fixed|exponential` gives API-style backends a
  controlled pause before handoff. Sleeps are capped to keep accidental
  large values from blocking workers for minutes.
- **Attempt callbacks** — `onAttemptStart`, `onAttemptFinish`, and
  `onFallback` are TaskRunner-only callbacks for live host UI/status updates.
  Exceptions thrown by callbacks are swallowed and logged.
- **Success quality guard** — `fallback_success_min_chars` and
  `fallback_success_forbidden_patterns` can treat a nominally-successful but
  empty/boilerplate answer as retryable and continue to the next backend.
- **Failure classifier** — retry fragments can now be class names such as
  `quota`, `rate_limit`, `auth`, `tool_policy`, `validation`, or `network`;
  each class maps to configurable string fragments.
- **Fallback policy explain API + command** —
  `TaskRunner::explainFallbackChain()` returns chain source, runnable chain,
  cooldown skips, and limits. New `super-ai-core:fallback-policy` command
  exposes the same policy for operators (`--json`, `--profile`,
  `--task-type`, `--capability`).
- **`super-ai-core.task_fallback` config block** — global defaults for
  `auto_enabled`, `check_availability`, `chain`, `chains_by_profile`,
  `chains_by_task_type`, `chains_by_capability`, `chains_by_metadata`,
  `max_attempts`, `max_cost_usd`, `backoff_ms`, `backoff_strategy`,
  `success_min_chars`, `success_forbidden_patterns`, `cooldown`,
  `failure_classes`, `auto_chain`, `fallback_on`, and
  `inherit_failure_context`. Environment toggles include
  `AI_CORE_TASK_FALLBACK_AUTO`,
  `AI_CORE_TASK_FALLBACK_CHECK_AVAILABILITY`,
  `AI_CORE_TASK_FALLBACK_CHAIN`, and
  `AI_CORE_TASK_FALLBACK_INHERIT_CONTEXT`.

### Added — Operator patterns

- **Task-class-specific fallback strategy** — hosts can choose a conservative
  chain per workload: coding tasks might prefer
  `claude_cli → codex_cli → gemini_cli`, research/summarisation can include
  `kimi_cli`, and direct-HTTP backends can sit at the end for headless
  recovery. This release keeps the policy in options/config instead of
  burying it in routing state.
- **UI/queue persistence hook via `fallbackReport`** — the attempt report is
  intentionally small enough to store on a task row or queue-job metadata.
  Hosts can badge "continued on codex", show the failed primary's log file,
  or attach the chain to support tickets without parsing raw logs.
- **Cost and reliability analytics path** — every fallback attempt still runs
  through the normal Dispatcher/usage path, while the final envelope carries
  the chain-level report. Hosts can correlate `ai_usage_logs.backend` with
  `fallback_report` to answer "which primary is hitting quota?" and "which
  secondary actually completed the work?".
- **Safety boundary for non-quota errors** — `fallback_on` is deliberately
  string-fragment based and opt-in. Prompt validation failures, missing
  files, tool errors, and destructive-operation denials remain on the
  original backend unless the host explicitly classifies them as retryable.
- **Availability-gated automatic chain** — `check_availability` lets hosts
  turn the automatic chain into a "registered and currently usable" list
  without making availability probing mandatory for every install. This is
  the bridge between static config and the existing `BackendRegistry`
  health surface.

### Documentation

- **`docs/task-runner-quickstart.md`** documents fallback-only options,
  explicit and automatic fallback examples, and the new
  `fallbackReport` envelope field.
- **`README.*`, `INSTALL.*`, and `docs/advanced-usage.*`** now frame 0.9.2
  as a TaskRunner reliability release, including upgrade notes, env/config
  snippets, attempt-report persistence, workload-specific chain recipes,
  and the safety boundary around retryable failures.

### Tests

- **`tests/Unit/TaskRunnerTest.php`** adds coverage for context handoff
  after quota-style failure, stopping on non-matching failures, and the
  default automatic chain order.

### Compatibility

- **No breaking changes.** Fallback is opt-in unless
  `AI_CORE_TASK_FALLBACK_AUTO=true` or a host configures
  `super-ai-core.task_fallback.chain`. Existing `TaskRunner::run()` calls
  without fallback options keep their previous single-backend behaviour.

## [0.9.1] — 2026-05-04

**SuperAgent SDK 0.9.8 uptake — DeepSeek-TUI parity wave + codex `/goal`
companion.** Tracks the SDK's `0.9.6 → 0.9.8` window in one cycle.
Composer constraint moves to `^0.9.8`. The new SDK shipped
`Goals\GoalManager`, `Security\UntrustedInput`, `Swarm\AgentDepthGuard`,
`Providers\Transport\TokenBucket`, `Conversation\Fork`,
`Memory\AdHocMemoryProvider`, `Context\Strategies\CacheAwareCompressor`,
`Routing\AutoModelStrategy`, and a DeepSeek V4 `formatMessages` override
that enforces the V4 Interleaved-Thinking rule on the wire. None of
those change SDK call shapes — every new piece is additive and opt-in.
This release wires the goal-store SPI through the container,
exposes `cache_hit_rate` on every usage row, lands a three-tier
approval gate (Auto / Suggest / Never) with a single-use `/approve`
override, ships a headless `/v1/usage` JSON endpoint, and adds a
workspace-shared plugin registry checked into the repo.

### Added — SuperAgent SDK 0.9.8 companion bindings

- **`Goals\EloquentGoalStore` + `Models\AiGoal` + migration
  `2026_05_04_000001_create_ai_goals_table`** — durable backing for
  SDK 0.9.8's `Goals\Contracts\GoalStore` SPI. Each thread can have at
  most one row in non-terminal status (active / paused /
  budget_limited); paused goals stay paused after the host process
  restarts (codex behaviour). The `SuperAICoreServiceProvider` binds
  `GoalStore::class → EloquentGoalStore::class` and registers
  `GoalManager` as a singleton so `app(GoalManager::class)` resolves
  with the durable store auto-injected. Hosts that already keep
  goals in their own table swap in their own implementation by
  rebinding the contract — no fork needed.
- **`Runner\ApprovalMode` + `ApprovalDecision` + `ApprovalGate`** —
  three-tier execution gate that mirrors codex's `/permissions`
  command. Read-only allowlist (`agent_grep`, `agent_glob`,
  `agent_read`, `agent_ls`, `web_search`, `web_fetch`,
  `agent_get_goal`, …) flows through every mode without prompting.
  Mutations in `Suggest` return `canRetry: true` with code
  `mutation_pending_approval` (or `destructive_pending_approval`
  when the existing `Guidance\Gates\DestructiveCommandScanner` flags
  the call); a single-use `tool_use_id` override token unblocks one
  retry — the codex `/approve` flow ported to API shape. Hosts
  resolve via `app(ApprovalGate::class)`.
- **`Plugins\WorkspacePluginRegistry`** — codex's "workspace plugin
  sharing" pattern. A team checks
  `.superaicore/workspace-plugins.json` into the repo; the registry
  diffs against locally-installed plugin names and returns
  `missing_required` (scope=workspace, must be installed for
  everyone) vs `missing_recommended` (scope=user, informational).
  `git clone` puts new hires on the team's full toolset without a
  per-machine onboarding doc. Bound as a singleton over `base_path()`
  so `app(WorkspacePluginRegistry::class)` works from any host.
- **`Http\Controllers\UsageApiController` + `GET /v1/usage`** —
  headless JSON aggregate endpoint mirroring codex's app-server
  `/v1/usage`. One axis per request: `group_by=day | model |
  provider | thread | backend | task_type`. Same filters as the HTML
  controller (model / task_type / user_id / backend / days). Auth is
  the host's job — wrap the route group in your auth middleware.
  Buckets carry `runs / cost_usd / shadow_cost_usd / input_tokens /
  output_tokens / cache_read_tokens / cache_hit_rate`.
- **`UsageRecorder` stamps `metadata.cache_hit_rate ∈ [0, 1]`** on
  every row with a non-zero cache slice. Denominator is the GROSS
  prompt (uncached input + cache reads) so dashboards group by
  model / day / backend and average without re-deriving the
  denominator. Absent when no cache activity occurred — distinguishes
  "no cache eligible" from "0% hit rate". Also accepts the legacy
  `cache_hit_tokens` alias from DeepSeek V3 / R1 wires.
- **`UsageController` exposes `cache_hit_rate` per model + at the
  session-summary level.** The Usage page now answers "what fraction
  of my paid prompt was free this period?" — the same question
  DeepSeek-TUI asks at turn-end, just aggregated. New
  `total_cache_read_tokens` summary card alongside the existing cold-
  cache and ambient-cost slices.

### Added — Tests

- **`tests/Unit/ApprovalGateTest.php`** — 12 cases covering parsing,
  per-mode behaviour (Auto / Suggest / Never), destructive shell
  detection across modes, and the one-shot `/approve` override.
- **`tests/Unit/WorkspacePluginRegistryTest.php`** — round-trip,
  version bump, scope normalisation, corrupt-manifest handling, and
  required-vs-recommended split.
- **`tests/Unit/UsageRecorderCacheHitRateTest.php`** — verifies
  `metadata.cache_hit_rate` stamping for the gross-prompt denominator,
  the DeepSeek V3 alias path, the no-cache no-stamp case, and the
  zero-tokens divide-by-zero edge.

### Fixed

- **`SuperAgentBackend::resolveEmbeddingProvider()`** —
  `EmbeddingProviderFactory::make()` reads `super-ai-core.embeddings.*`
  via Laravel's `config()` helper, which throws when no container is
  bound. The backend now wraps both the `app()` lookup AND the
  `make()` call so dispatches that opt out of the embedder degrade
  silently instead of failing the whole turn. Surfaced by
  `SuperAgentBackendTest` (pure-PHPUnit, no Laravel container) and
  fixes a latent bug for any host that runs the backend before the
  package ServiceProvider boots.
- **`SuperAgentBackend::configBool()`** — new helper guards every
  boolean-config lookup (`super-ai-core.tools.agent_grep_enabled`,
  `super-ai-core.tools.browser_enabled`) so `BindingResolutionException`
  on container-less paths falls back to the default value instead of
  bubbling out of `generate()`.

### Compatibility

- **No breaking changes.** `forgeomni/superagent` constraint moves to
  `^0.9.8`; the SDK itself made no breaking changes in 0.9.6–0.9.8
  (new pieces are additive, `region`/`upstream` precedence is
  documented, cache-aware compactor is opt-in). Existing
  `SuperAgentBackend` envelope shape is byte-identical for non-
  thinking conversations and non-browser tool runs.
- **`ai_goals` migration** — opt-in via `php artisan migrate`. Hosts
  that don't use `Goals\GoalManager` can ignore the new table; the
  binding is wired but no code in this package writes a goal row
  unsolicited.

## [0.9.0] — 2026-05-03

**SuperAgent SDK pinned to 0.9.7 + DeepSeek V4 first-class + the full
jcode-inspired operator UX wave (roadmap items 1–9 + B1–B5).** Catches
up on the SDK's three new headline capabilities from 0.9.6
(`DeepSeekProvider`, cross-provider `reasoning_content` channel,
catalog-driven model deprecation warnings) plus the full 0.9.7 jcode
companion-tools wave (`AgentGrepTool` + `SymbolExtractor` SPI,
`EmbeddingProvider` SPI with Ollama/ONNX concretes, `FirefoxBridge` +
Native Messaging transport, `Swarm\FileLedger` / `AmbientWorker`,
`Conversation\HarnessImporter` for cross-harness session resume),
then lands every borrowing from [jcode](https://github.com/1jehuang/jcode)
that the original 0.9.0 plan deferred — multi-account
`provider:rotate`, inline mermaid renderer, semantic skill reranker,
cache-cold operator surface, right-hand side panel, browser-screenshot
store. Composer constraint moves to `^0.9.7`; everything else is
additive and backward compatible. Per project
convention, every change in this cycle (originally tagged for 0.9.1
and 0.9.2 internally) lands in 0.9.0.

### Added — SDK 0.9.6 surface

- **`SuperAgentBackend` envelope gains `thinking`** when the upstream
  provider emitted reasoning text. Sources: Anthropic native `thinking`
  blocks, DeepSeek V4-thinking / Kimi-thinking / Qwen-reasoning /
  GLM-thinking / OpenAI o-series via the SDK's
  `delta.reasoning_content` rebroadcast (single concatenated string,
  separated by blank lines). Key omitted on non-thinking turns so
  envelope shape stays byte-identical to 0.8.9 for callers that don't
  render reasoning. `Dispatcher` writes a cheap `thinking_chars` proxy
  to `ai_usage_logs.metadata` for dashboards that want to badge
  reasoning depth without storing the full text.
- **Catalog deprecation surfacing.** `SuperAgentBackend::resolveDeprecation()`
  consults the SDK's new `ModelCatalog::deprecation()` per dispatch and
  attaches `{model, deprecated_until, replaced_by, days_left}` to the
  envelope when the resolved model is flagged for retirement (`days_left`
  is negative once the window has lapsed). Dispatcher writes the same
  blob to `ai_usage_logs.metadata.deprecation` so admin pages can render
  a "you have N days to migrate to <X>" banner. Mirrors the SDK's
  one-shot `error_log` warning rather than replacing it — operators on
  CI silence both with `SUPERAGENT_SUPPRESS_DEPRECATION=1`.
- **`UsageRecorder` accepts the `cache_hit_tokens` alias** alongside
  `cache_read_tokens`. DeepSeek V3 / R1 wires emit the legacy field
  name, and SDK 0.9.6 normalises but does not rename it; accepting the
  alias here means hosts that captured the raw provider envelope (instead
  of going through `Usage::cacheReadInputTokens`) stop silently dropping
  the cache slice.

### Added — DeepSeek V4 provider type

- **`AiProvider::TYPE_DEEPSEEK = 'deepseek'`** registered in `TYPES`,
  added to `BACKEND_TYPES[BACKEND_SUPERAGENT]`, and to the api-key
  fallback list in `requiresApiKey()`. The Anthropic-wire endpoint at
  `https://api.deepseek.com/anthropic` is reached via a regular
  `anthropic-proxy` row pointing `base_url` there — both wire shapes are
  first-class on DeepSeek's side.
- **`ProviderTypeRegistry` bundled descriptor** for the type:
  `fields: ['api_key']`, `env_key: 'DEEPSEEK_API_KEY'`,
  `sdk_provider: 'deepseek'`, `allowed_backends: [SUPERAGENT]`,
  `icon: 'bi-search-heart'`. Surfaces automatically in the providers UI,
  the env-injection path, and `AiProvider::requiresApiKey()`.
- **`EngineCatalog` superagent seed** gains `deepseek-v4-pro` and
  `deepseek-v4-flash` so model pickers show V4 ids without depending on
  the live SDK catalog probe (offline / catalog stale / composer dep
  missing all degrade gracefully).
- **`config('super-ai-core.model_pricing')`** gains four DeepSeek rows:
  `deepseek-v4-pro` (\$0.55 / \$2.20), `deepseek-v4-flash` (\$0.14 /
  \$0.55), plus deprecated `deepseek-chat` / `deepseek-reasoner`
  routed to the V4 successors so cost dashboards keep working past
  the 2026-07-24 cutover.

### Added — jcode-inspired (operator UX)

- **`provider:add` artisan command** — borrowed in spirit from
  `jcode provider add`. One-shot file-driven row insertion into
  `ai_providers` for CI / container `entrypoint.sh` / scripted bootstrap.
  Secret-safe via `--api-key-stdin` (recommended) or `--api-key-env`
  (env-var reference, no key persisted). Validates backend + type combo
  against `ProviderTypeRegistry` before touching the DB. `--activate`
  flips the row to active and de-activates siblings in the same
  scope+backend. `--json` emits `{id, scope, backend, type, name,
  active, has_api_key, …}` for downstream scripts.
  ```bash
  printf '%s' "$DEEPSEEK_API_KEY" | php artisan provider:add deepseek-prod \
      --backend=superagent --type=deepseek \
      --model=deepseek-v4-pro --api-key-stdin --activate --json
  ```
- **`provider:rotate <backend>`** — multi-account quick swap, jcode
  `/account` style. Auto-rotation on `QuotaExceededException` is opt-in
  via `super-ai-core.auto_rotate.enabled`. Stamps
  `extra_config.last_rotation_*` on the freshly-activated row so
  dashboards show "rotated 5m ago because: quota_exceeded".
- **`McpManager::readConfig()` walks a configurable fallback list**.
  Default order matches jcode's three-layer chain plus the SuperAICore
  canonical: `<project>/.mcp.json` → `<project>/.claude/mcp.json` →
  `~/.jcode/mcp.json` → `~/.claude/mcp.json`. Hosts override
  `super-ai-core.mcp.search_paths` to reorder / augment; `{project}`
  and `{home}` (and `~`) tokens expand at lookup time. Operators can
  drop the file at the location matching their mental model and every
  CLI in the host picks it up.

### Added — jcode-inspired (cost / cache observability)

- **Cache-cold warning for Anthropic backends.** `Dispatcher::detectCacheCold()`
  flags result envelopes (`cache_warning: 'cache_likely_cold'`) when the
  current dispatch had zero cache reads AND the most recent same-session
  Anthropic call was longer ago than
  `super-ai-core.cache_cold_warning.threshold_seconds` (default 270 — leaves
  30s headroom under the 5-minute TTL). Computed before the usage write
  so the warning lands on the same `ai_usage_logs.metadata.cache_warning`
  row that dashboards read. Requires `metadata.session_id` on dispatch;
  degrades silently when absent.
- **`EloquentUsageRepository::findLatestForSession()`** — driver-portable
  JSON path lookup (`metadata->session_id`) that wires up the cache-cold
  heuristic above. Hosts that pass `metadata.session_id` see the warning
  fire automatically against MySQL 8 / PostgreSQL 14+ / SQLite 3.38+.
  Older drivers degrade to "no signal" — never false-positive.
- **Cache-cold UI badge in `/usage`** — Recent calls table renders a
  yellow `❄ cold` badge next to the model when
  `metadata.cache_warning` is present (warning text in the tooltip). A
  banner above the table shows the cold-call count for the window with a
  one-click drill-in; a `Cache-cold only` filter checkbox scopes the
  whole page (driven by `whereNotNull('metadata->cache_warning')`).
  Roadmap item B5.
- **Passive `rate_limit` envelope passthrough.** Dispatcher copies
  `result['rate_limit']` (when the SDK populates it from upstream
  `x-ratelimit-*` response headers) onto `ai_usage_logs.metadata.rate_limit`.
  No-op until the SDK exposes it; wire is now ready.

### Added — jcode-inspired (browser bridge surface, B4)

- **`Services\BrowserScreenshotStore`** — thin filesystem-backed
  registry for browser-bridge screenshots, decoupled from whatever
  tool produced them (today: SuperAgent's `FirefoxBridgeTool`;
  tomorrow: Playwright / Puppeteer wrappers). `store($id, $base64Png)`
  → URL; `latest($id)` → URL or null; `purgeFor($id)` for the Process
  Monitor's reaper. Configurable disk + directory so hosts can point
  it at S3 or per-pod tmpfs without schema changes.
- **`ProcessEntry::$latest_screenshot_url`** — optional field that
  carries the URL `BrowserScreenshotStore::latest()` returned. Hosts
  populate it when constructing entries; the `/processes` row renders
  a yellow `📷 screenshot` badge that opens the side panel (B1) with
  the inline `<img>` so operators get jcode's "see what the agent
  sees" experience without the right-pane log viewer needing a
  rewrite.

### Added — jcode-inspired (rendering)

- **Mermaid rendering on every Blade page** — bundled layout loads
  mermaid.js from the CDN under `super-ai-core.ui.mermaid_enabled`
  (default on; flip off for air-gapped hosts). The Process Monitor log
  viewer detects ` ```mermaid ` fences in streamed output and renders
  them as live SVGs in place. Includes `window.SuperAICoreMermaid.run()`
  / `.upgrade(node)` helpers for any host view.
- **Right-hand side panel + info-widget marker grammar (B1).** Bootstrap
  offcanvas drawer baked into the layout, hidden until invoked. JS API
  `window.SuperAICorePanel.show({title, type, content, footer})` with
  `type ∈ {html, mermaid, json, iframe, text}` (mermaid bodies re-paint
  via the existing renderer). Streamed agent output can drop a
  `<!-- side-panel: {…json…} -->` comment marker which the auto-binder
  rewrites into an "Open" button on `DOMContentLoaded`; server-rendered
  views can wire `[data-side-panel-trigger='{…json…}']` directly. First
  in-tree consumer: `/usage` row metadata inspector. Toggleable via
  `super-ai-core.ui.side_panel_enabled`.

### Added — jcode-inspired (semantic skill ranking)

- **`SemanticSkillReranker`** — optional second pass over `SkillRanker`
  BM25 top-N. Resolves an SDK 0.9.7
  `SuperAgent\Memory\Embeddings\EmbeddingProvider` via
  `EmbeddingProviderFactory` (which reads
  `super-ai-core.embeddings.{provider,callback,ollama_url}`) so the
  reranker, the SDK's own `SemanticSkillRouter`, and any host-supplied
  `OnnxEmbeddingProvider` share one container singleton + one cache.
  The HTTP / shape-detection code that lived here in 0.9.0 is now in
  the SDK; on a flaky embedder per-row failures (`[]` vector) keep the
  BM25 score for that hit instead of bailing the whole call. Falls
  back to BM25 ordering when no embedder is configured. `SkillRanker`
  accepts the reranker via constructor — null-safe, no-op when absent.
- **`EmbeddingProviderFactory`** — container-bound singleton that
  builds the bundled `EmbeddingProvider` once per request from
  `super-ai-core.embeddings.*`. `SuperAgentBackend` and
  `SemanticSkillReranker` both pull through it, so the wired embedder
  is shared across the dispatch lifecycle.

### Added — jcode-inspired (browser bridge wiring)

- **`SuperAgentBackend` browser tool wiring** — when
  `super-ai-core.tools.browser_enabled` is on, `SuperAgentBackend`
  instantiates SDK 0.9.7's `FirefoxBridgeTool` (`browser`) and
  `Agent::addTool()`'s it directly. Not in `BuiltinToolRegistry::classMap`,
  so `load_tools` can't reach it — this is the official wiring path.
  Requires `SUPERAGENT_BROWSER_BRIDGE_PATH`; without that the tool
  itself returns explanatory errors so the agent stops looping.
- **`SuperAgentBackend` agent_grep auto-load** — when
  `super-ai-core.tools.agent_grep_enabled` is on (**default true** —
  read-only, dependency-free, only fires on dispatches that opt into
  an agentic loop with tools) and the caller didn't supply an explicit
  `load_tools`, the backend prepends `'agent_grep'` to the load list.
  Both flags only fire on the implicit path; explicit `load_tools`
  keeps full control. Set `AI_CORE_TOOLS_AGENT_GREP=false` for
  byte-identical pre-0.9.7 behaviour.
- **`SuperAgentBackend::persistLatestScreenshot()`** — pairs
  `tool_use → tool_result` blocks with `toolName === 'browser'` and
  decodes the JSON content (`{format,base64,bytes}` from
  `FirefoxBridgeTool::execute` case 'screenshot') to extract the
  latest base64 PNG. The frame is written to `BrowserScreenshotStore`
  keyed by (in priority order) `process_id`, `external_label`,
  `metadata.session_id`, `session_id`, or a random hex id, and the
  resulting URL is surfaced on the dispatch envelope as
  `latest_screenshot_url`. Key omitted when no browser activity
  occurred.
- **`AiProcessSource` screenshot round-trip** — reads
  `BrowserScreenshotStore::latest()` against the row's
  `external_label` (then composite id) when constructing
  `ProcessEntry`, and `purgeFor()`'s those keys on reap. Closes the
  loop end-to-end: any backend run that took screenshots through
  `browser` shows the latest frame inline on `/processes` without
  host-side glue.

### Added — jcode-inspired (cost source attribution)

- **`Dispatcher::resolveUsageSource()`** — promotes
  `options['usage_source']` / `options['metadata']['usage_source']` to
  a top-level `metadata.usage_source` key (default `'user'`) so
  `/usage` can group on it without JSON-path nesting. Constrained to
  `[a-z0-9_-]{1,32}` against typo-as-phantom-bucket leaks. SuperAgent's
  `AmbientWorker` (its `tagUsage` callback writes
  `usage_source: 'ambient'`) lights up the dashboard split for free —
  hosts that spawn the worker get a separate ambient bar without
  re-instrumenting their cost code.
- **`/usage` "By Source" card** — sits alongside By Task Type / By
  Model / By Backend (layout reflows to `col-lg-3` on wide viewports).
  Header shows an "N ambient · $X" badge when ambient activity
  occurred so operators see at a glance how much background spend the
  current window carries.

### Added — jcode-inspired (cross-harness session resume)

- **`HarnessSessionResolver`** — wraps SDK 0.9.7's
  `Conversation\HarnessImporter` family (`ClaudeCodeImporter`,
  `CodexImporter`) so the dashboard has a single seam. `listSessions(harness, limit)`
  returns the picker rows; `loadTranscript(harness, sessionId)`
  serialises `Message[]` into a transport-friendly array and forwards
  to the optional host callback `super-ai-core.resume.on_load`
  (signature `fn($harness, $sessionId, $messages): mixed`).
- **`HarnessResumeController` + routes** — `/super-ai-core/resume`,
  `/resume/{harness}`, `POST /resume/{harness}/load`. Gated by
  `super-ai-core.resume.enabled` (off by default — the importers can
  see every operator's history on shared machines).
- **`/processes` Resume dropdown + transcript modal** — picker UI for
  Claude Code / Codex sessions. When the host returns
  `{redirect: '…'}` from the `on_load` callback the modal redirects
  there; otherwise it shows the transcript inline so operators can
  copy / paste / inspect.

### Composer

- `forgeomni/superagent` constraint bumped from `^0.9.5` to `^0.9.7`. The
  caret range was already wide enough to pull 0.9.7, but the explicit
  bump locks it for hosts on `composer.lock` and signposts the new
  behaviour (DeepSeek V4 + reasoning channel + catalog deprecation from
  0.9.6, plus `AgentGrepTool` / `EmbeddingProvider` SPI / `FirefoxBridge`
  / `Swarm\FileLedger` + `AmbientWorker` / `Conversation\HarnessImporter`
  from 0.9.7) in `composer require` output.

### Configuration additions

- `super-ai-core.cache_cold_warning.threshold_seconds`
- `super-ai-core.auto_rotate.{enabled,window_seconds,min_failures}`
- `super-ai-core.ui.mermaid_enabled`
- `super-ai-core.ui.side_panel_enabled`
- `super-ai-core.embeddings.{provider,callback,fingerprint,ollama_url,ollama_model,timeout_ms}`
- `super-ai-core.browser_screenshots.{disk,dir}`
- `super-ai-core.tools.{agent_grep_enabled,browser_enabled}`
- `super-ai-core.resume.{enabled,on_load}`
- `super-ai-core.mcp.search_paths`
- `super-ai-core.model_pricing` (DeepSeek V4 rows)

### Migration path

`composer update forgeomni/superagent` is the only required step. New
DeepSeek provider rows are created via the standard `/providers` UI or
the new `provider:add` command. Existing rows continue to behave
identically; hosts that don't touch `metadata.session_id` opt out of the
cache-cold warning automatically. The new offcanvas side panel renders
nothing until a view drops a marker / data-attribute, so existing layouts
gain only ~3KB of script + a hidden DOM node.

For the SuperAgent-side jcode borrowings landed in the same cycle (agent
grep, swarm file-shift events, ambient mode, semantic skill router,
cross-harness session resume), see the SuperAgent 0.9.7 changelog.

### Notes

- **DeepSeek V3 deprecation.** Users on `deepseek-chat` / `deepseek-reasoner`
  see the SDK's one-shot `error_log` warning per process and the
  Dispatcher's `metadata.deprecation` row. Both surface the same
  `replaced_by` recommendation. Set `SUPERAGENT_SUPPRESS_DEPRECATION=1`
  to silence the SDK warning in CI; the metadata row is informational
  only and does not block dispatch.
- **Reasoning channel is provider-driven.** SuperAICore does not request
  reasoning unless the caller passes `extra_body.reasoning` /
  `features.thinking` etc. through to the SDK. The new `thinking` envelope
  field surfaces whatever the upstream chose to emit; absent reasoning
  text means absent envelope key, byte-compatible with 0.8.9.
- **Cache-cold heuristic is opt-in.** Without `metadata.session_id`,
  there's nothing to correlate against; the warning never fires. Hosts
  that already pass a session marker (SuperTeam's `session_id` column,
  PPT's `job_id`) get the warning automatically.
- **Side panel is host-trusted.** `SuperAICorePanel` renders
  `type: 'html'` content as-is — payloads must be sanitised at the
  source. Stick to `mermaid` / `json` / `text` / `iframe` whenever the
  upstream is agent-generated.

## [0.8.9] — 2026-04-28

**Backend availability gate — fix false-negative for SDK backends.** Hosts using `findCliPath($backend)` non-null as the "is this backend installed?" gate were locking out every provider routed through `superagent` (MINIMAX / Qwen / GLM / OpenRouter / Kimi-direct / LM Studio …). SuperAgent is an in-process PHP SDK with no CLI binary, so the path probe always returns null and the host shows `SuperAgent is not installed or not available on this server.` even when `forgeomni/superagent` is fully installed and `class_exists(\SuperAgent\Agent::class)` is true.

### Added

- **`CliStatusDetector::isInstalled(string $backend): bool`** — lightweight yes/no check intended for host gating paths (task-create, run-execute). Distinct from `detect()`:
  - `superagent` → `class_exists(\SuperAgent\Agent::class)`. No path probe.
  - Built-in CLI engines (`claude`/`codex`/`gemini`/`copilot`/`kimi`) → `findPath()` only, skips the `<binary> --version` probe (~100-300ms cold) that `detectBinary()` runs to populate the version cell.
  - Catalog-registered CLI engines → `findPath($cliBinary)`.
  - Catalog-registered non-CLI engines → catalog presence is sufficient; the backend's own dispatch path will surface specific runtime errors.

Hosts should replace `if (!ClaudeRunner::findCliPath($backend))`-style gates with the new method (or a thin wrapper that delegates to it). Existing `detect()` / `all()` calls are unchanged.

## [0.8.8] — 2026-04-28

**Cross-platform CLI prompt delivery — Windows large-argv fix.** Every CLI backend's `stream()` / `generate()` was appending the user prompt as the trailing argv element. On Windows, Symfony Process wraps every command in `cmd /V:ON /E:ON /D /C "(...)"` regardless of whether the args came in as an array or a string — and `cmd.exe` has a hard 8191-char command-line cap. Markdown agent prompts at our typical 25K size were silently truncated; the CLI either failed to start or, like Claude, reported `Error: Input must be provided either through stdin or as a prompt argument when using --print`. macOS/Linux argv limits (`getconf ARG_MAX`, typically 128K+) absorbed the same calls without complaint, so the bug only surfaced on Windows.

Two-track fix, depending on whether the upstream CLI accepts the prompt on stdin:

### Fixed — stdin-capable engines (Claude / Codex / Gemini)

These CLIs natively read the prompt from stdin under their `--print` / `exec` modes, so the cleanest fix is to drop the prompt from argv entirely and pipe it via `Process::setInput()`.

- **`ClaudeCliBackend::generate()` + `::stream()`** — removed `$cmd[] = $prompt`, added `$process->setInput($prompt)` before `run()` / `runStreaming()`. Claude CLI under `--print` reads stdin when no positional prompt is present.
- **`CodexCliBackend::generate()` + `::stream()`** — same shape: argv now ends with `'-'` (Codex's documented stdin-marker token: `codex exec - …`), prompt piped via `setInput()`. Verified against `codex exec --help` v0.120: *"if `-` is used, instructions are read from stdin."*
- **`GeminiCliBackend::generate()` + `::stream()`** — switched from `-p $prompt` to `--prompt ""` + `setInput($prompt)`, mirroring the idiom already used by `GeminiSkillRunner`.

Side benefit: avoids any cmd-line escaping pitfalls for prompts containing newlines, code fences, single/double quotes, or non-ASCII (CJK), regardless of platform.

### Fixed — argv-only engines (Kimi / Kiro / Copilot)

These CLIs don't read stdin — `kimi --prompt <text>`, `kiro chat <text>`, `copilot -p <text>` all require the prompt as an argv argument. New shared trait routes them through PowerShell on Windows when the argv would exceed the cmd.exe cap; macOS/Linux paths are unchanged.

- **New trait `Backends\Concerns\LargeArgvSafeSpawn`** — exposes `buildLargeArgvSafeProcess(array $args, ?string $cwd, array $env)`. Returns a plain `Process` on POSIX or for short Windows argv (< 6500 chars). When Windows + argv ≥ 6500 chars, writes a self-contained `.ps1` to `sys_get_temp_dir()` that holds every argv element as a single-quoted PowerShell string (single quotes escaped by doubling — PowerShell's only metachar inside single-quoted literals), then invokes `& $binary @arguments`. PowerShell's call operator with array splat goes straight to `CreateProcess` with the array, hitting only the kernel-level Windows API limit (~32K) instead of cmd.exe's 8K cap.
- **`KimiCliBackend::stream()` + `::generate()`** — now use `$this->buildLargeArgvSafeProcess($cmd, ...)` instead of `new Process($cmd, ...)`.
- **`KiroCliBackend::stream()` + `::generate()`** — same.
- **`CopilotCliBackend::streamChat()`** — same.

### Added

- **`tests/Unit/LargeArgvSafeSpawnTest.php`** — covers: short argv on any platform → plain Process (no PowerShell); long argv on Windows → routes via PowerShell wrapper with `-File <ps1>`; long argv on POSIX → still plain Process (no PowerShell needed); generated `.ps1` correctly doubles single quotes and uses `@arguments` splat. Probe writes a real `.ps1` to a temp dir and inspects it post-hoc — no real CLIs spawned.

### Known caveats

- The trait's threshold (6500 chars) is conservative — leaves headroom for Symfony's `cmd /V:ON /E:ON /D /C "(…)"` wrapping overhead (~80 chars) and per-element argv quoting. Hosts that use very tight prompts can leave it at the default; hosts that observe spurious PowerShell-wrapper invocations on borderline sizes can override `winArgvThreshold()` in a subclass.
- The PowerShell wrapper writes a `.ps1` file per spawn — not cleaned up automatically (Windows reaps `%TEMP%` on disk-cleanup runs). At ~1KB per script + per-task-run cadence, the leak is negligible; could be made explicit with a finally-cleanup if a host runs into temp-dir bloat.
- `BuildsScriptedProcess` (the wrapper-bat path used by `prepareScriptedProcess()` in scripted-spawn mode) still embeds the prompt argv inline in the generated `.bat` for Copilot/Kiro and similar engines. That path also hits the cmd.exe 8K limit on Windows, but it's a separate code path from `stream()`/`generate()` and was not exercised by the current bug report — tracked as a follow-up.

## [0.8.7] — 2026-04-28

**Cross-platform CLI detection — Windows compatibility fix.** `CliStatusDetector` and `CliBinaryLocator` were silently broken on Windows because their probe commands embedded the POSIX redirect `2>/dev/null`, which `cmd.exe` misparses as an output filename, aborting the whole command and zeroing out stdout. Symptom: `claude auth status` returned valid JSON to a shell but `auth: null` to the detector — Claude Code disappeared from `/super-ai-core/providers` build-task pickers even when fully logged in. Codex was unaffected (`2>&1` is cross-platform), but every CLI's `--version` probe was broken on Windows.

### Fixed

- **`CliStatusDetector::safeProbeOutput()`** — added `bool $mergeStderr = false` parameter; stripped `2>/dev/null` / `2>&1` / `2>NUL` from every call site. Symfony Process already captures stdout/stderr separately, so shell redirects were pure platform-incompat surface area. Callers that needed merged output (codex `login status`, copilot `--help`) now opt in via the flag.
- **`CliStatusDetector::childEnv()`** — Windows fallback: `getenv(USERPROFILE)` → `getenv(HOMEDRIVE)+getenv(HOMEPATH)` when `HOME` is empty; mirrors resolved value into both `HOME` and `USERPROFILE` so binaries that read either work. Adds Windows-essential pass-through env vars (`APPDATA`, `LOCALAPPDATA`, `SystemRoot`, `PATHEXT`, `TEMP`, `TMP`, `Program*`) — most Win32 binaries refuse to start without `%SystemRoot%` set, and npm-global CLIs cache OAuth credentials under `%APPDATA%`. POSIX path keeps `posix_getpwuid()` for kernel-level user resolution under FPM `clear_env=yes`.
- **`CliStatusDetector::findPath()`** — split into three platform branches (`windowsPathCandidates` / `macPathCandidates` / `linuxPathCandidates`) via `match (PHP_OS_FAMILY)`. Windows now probes npm-global, `~/.local/bin`, `~/.npm-global/bin`, `%LOCALAPPDATA%/Programs/<binary>`, `%ProgramFiles%/<binary>`, `%ProgramFiles(x86)%/<binary>`, Scoop shims, and Chocolatey — each base × `{.exe, .cmd, .bat, ''}` extensions. macOS adds `/opt/local/bin` (MacPorts) and prioritizes Apple Silicon homebrew over Intel. Linux adds `/snap/bin` and Linuxbrew. `where`/`which` fallback unchanged but no longer carries shell redirects.
- **`CliBinaryLocator`** — same three-platform refactor, mirrors the detector's candidate list so spawn-time path resolution matches probe-time path resolution.
- **`CliInstaller::isToolAvailable()`** — dropped `' 2>/dev/null'` appended to `where`/`which`; was the same Windows misparse bug at the `npm/brew/sh` PATH check.
- **`SystemToolManager::checkTesseractLanguages()`** — dropped `' 2>/dev/null'` from tesseract probe; merge stderr post-facto via `getOutput() . getErrorOutput()` since some 5.x builds write the language list to stderr.
- **Late static binding for probes** — `self::safeProbeOutput()` → `static::safeProbeOutput()` throughout `CliStatusDetector` so test subclasses can override the probe without monkey-patching. Existing `static::probeCopilotLive()` already followed this pattern; brought the rest of the file in line.

### Added

- **`tests/Unit/CliStatusDetectorCrossPlatformTest.php`** — covers: (a) no probe command emits `2>/dev/null` or `2>NUL`, (b) codex login-status probe opts into `mergeStderr:true`, (c) `childEnv()` resolves HOME from USERPROFILE on Windows + propagates SystemRoot, (d) POSIX `XDG_CONFIG_HOME` pass-through, (e) `findPath()` candidates differ per platform (Windows must include Scoop/Chocolatey/`Program Files`, macOS must include MacPorts, Linux must include Snap + Linuxbrew). Probe recorder subclass intercepts `safeProbeOutput` via reflection so no real binaries get spawned.

### Fixed (Windows test suite)

The five Windows-only test failures that had been red on the test matrix prior to 0.8.7 are now green. Causes were all path-separator drift between PHP filesystem APIs that return backslashes (`sys_get_temp_dir()`, `RecursiveDirectoryIterator::getPathname()`, `DIRECTORY_SEPARATOR`) and code/test fixtures that used forward slashes.

- **`SideEffectDetector::rel()`** — `str_starts_with($path, $prefix)` failed on Windows because `RecursiveDirectoryIterator` returns paths with backslashes while `cwd` was constructed with forward slashes. Normalize both sides to forward slashes before comparison.
- **`McpManager::syncAllBackends()`** — joining `$home . DIRECTORY_SEPARATOR . $relPath` on Windows produced mixed-separator paths (`…/kimi-mcp-xxx\.kimi/mcp.json`) that compared unequal to what host code passed in. Use `'/'` as separator (Win32 file APIs accept it) and trim only one trailing separator from `$home` instead of rewriting it.
- **`tests/Unit/TeeLoggerTest::test_unwritable_path_does_not_throw`** — `'/dev/null/cannot/exist.log'` is writable on Windows (no `/dev/null` device, PHP creates the dirs). Branch on `PHP_OS_FAMILY` and use `'NUL\\cannot\\<invalid>.log'` on Windows — `NUL` is a reserved device name that can't host children, and `<` is forbidden in NTFS paths, so the open is guaranteed to fail.

## [0.8.6] — 2026-04-27

**Skill engine — telemetry + BM25 ranker + FIX-mode evolution candidates.** Borrowed in spirit from HKUDS/OpenSpace's `skill_engine`, trimmed to the safe subset for production use: every Claude Code Skill tool invocation gets logged via PreToolUse/Stop hooks (`SkillTelemetry`), skills are ranked against arbitrary task text via pure-PHP BM25 with CJK char-tokens and a confidence-weighted telemetry boost (`SkillRanker`), and degrading skills queue review-only FIX-mode candidates that a human applies via `php artisan skill:candidates` (`SkillEvolver`). DERIVED and CAPTURED modes are intentionally omitted — humans curate new skills on Day 0; the evolver never modifies SKILL.md directly. Cloud registry omitted — no cross-project sharing need yet. Two new tables (`sac_skill_executions`, `sac_skill_evolution_candidates`) and six artisan commands ship. The track-* commands read JSON from stdin so they plug directly into Claude Code's PreToolUse / Stop hook contracts; SuperTeam wires them in a sibling commit.

### Added

#### Telemetry — `SkillTelemetry` + `skill_executions` table

- **`SkillTelemetry::start(skill, session, host_app, transcript, cwd, metadata)`** inserts an `in_progress` row and returns the row id. **`stop(id, status, error)`** flips a single row to `completed` / `failed` / `interrupted` and computes `duration_ms`. **`closeSession(session, status, error)`** flips every still-open row for a session — used by the Stop hook (Claude Code only fires Stop once per session, not once per Skill). **`sweepOrphaned(maxAgeSeconds=7200)`** marks `in_progress` rows older than the cutoff as `orphaned` so a crashed session doesn't poison the metrics.
- **`metrics(?since, ?skillName)`** aggregates per-skill `applied / completed / failed / orphaned / interrupted / in_progress / completion_rate / failure_rate / last_used_at`. Returns array keyed by skill name; one query, single GROUP BY round-trip. **`recentFailures(skill, limit=5)`** powers the FIX-mode prompt builder.
- **`SkillExecution` Eloquent model** with five status constants (`STATUS_IN_PROGRESS / COMPLETED / FAILED / ORPHANED / INTERRUPTED`) and `metadata` cast to array. Uses `HasConfigurablePrefix` so the host's `super-ai-core.table_prefix` applies.
- **Migration `2026_04_27_000001_create_skill_executions_table`**: `id`, `skill_name` (indexed, 120 chars), `host_app` (indexed, 60 chars — e.g. `super-team`), `session_id` (indexed, 80 chars), `status` (indexed, default `in_progress`), `started_at` (indexed), `completed_at`, `duration_ms`, `transcript_path`, `error_summary`, `cwd`, `metadata` (json), plus composite indexes `(skill_name, status)` and `(skill_name, started_at)`.

#### Ranker — `SkillRanker` (BM25 + telemetry boost)

- **Pure-PHP, dependency-free BM25** over the in-process `SkillRegistry` catalog. Robertson-Walker defaults (`K1=1.5`, `B=0.75`), BM25-Plus IDF variant (handles `df=0` gracefully). Skill name is repeated in the document bag to upweight strong intent signal; description plus the first 600 chars of SKILL.md body provide the rest of the lexical surface.
- **CJK-aware tokeniser**: lowercase, strip non-`\p{L}\p{N}\-`, emit each Han character (`\x{4E00}-\x{9FFF}`) as its own token (poor-man's CJK tokenizer — Chinese skill descriptions are short, char-grams suffice). Hyphenated words also yield their parts. Tiny stopword list covers EN + zh-CN scaffolding (`一个`, `的`, `和`, …) — kept tiny on purpose, prefer recall over precision.
- **Confidence-weighted telemetry boost**: `final = bm25 * (1 + 0.4 * (completion_rate - 0.5) * applied_signal)`, where `applied_signal = min(1.0, applied / 10)` saturates near 10 runs. Skills with no telemetry get `tel_boost = 1.0`. The 0.5 midpoint means a 100% success rate adds up to +20% over BM25 alone; a 0% rate subtracts up to −20%. Pass `useTelemetry: false` to the constructor (or `--no-telemetry` to `skill:rank`) for pure-lexical ranking.
- **Each result carries a breakdown**: `score`, `bm25`, `tel_boost`, `matched` (per-term IDF×TF contribution), `metrics` (the raw telemetry row). Useful for debugging "why did skill X rank above Y?" without re-running with verbose flags.

#### Evolver — `SkillEvolver` + `skill_evolution_candidates` table

- **`proposeFix(skill, triggerType, executionId, dispatch)`** reads the last 5 failures + current metrics, builds a structured FIX-mode prompt against the live SKILL.md body (truncated to 8K chars), and persists a `SkillEvolutionCandidate` row in `pending` status. The prompt is constrained: "produce the **smallest possible patch**", "do not invent failures the evidence does not support", "do not restructure sections, rename the skill, change the frontmatter `name`, or add new tools to `allowed-tools` unless the failure evidence explicitly demands it". Output format is two sections — `Diagnosis` (2–4 sentences) and `Patch` (a single fenced \`\`\`diff block, OR the literal string `NO_FIX_RECOMMENDED`).
- **`sweepDegraded(failureRateThreshold=0.30, minApplied=5)`** walks all telemetry, queues a candidate per skill that exceeds the threshold and has at least N runs. De-dups against an existing `pending` candidate per skill so you can `--sweep` daily without backlog inflation.
- **`--dispatch` mode** (off by default — costs tokens) routes the prompt through `SuperAICore\Services\Dispatcher` with `capability: 'reasoning'`, `task_type: 'skill_evolution_fix'`, parses the `\`\`\`diff` block out of the response with a regex, and stores both `proposed_body` (full LLM response) and `proposed_diff` (extracted block) on the candidate. Failures are silent — caller checks `proposed_diff` to know whether dispatch worked.
- **`SkillEvolutionCandidate` Eloquent model** with three trigger constants (`TRIGGER_FAILURE / METRIC_DEGRADATION / MANUAL`) and five status constants (`STATUS_PENDING / REVIEWING / APPLIED / REJECTED / SUPERSEDED`). `belongsTo(SkillExecution)` via `execution_id` so a candidate that was triggered by a specific failure can jump back to the failing run's transcript.
- **Migration `2026_04_27_000002_create_skill_evolution_candidates_table`**: `id`, `skill_name` (indexed), `trigger_type` (indexed), `execution_id` (nullable, indexed), `status` (indexed, default `pending`), `rationale`, `proposed_diff` (longText), `proposed_body` (longText — full LLM response), `llm_prompt` (longText), `context` (json), `reviewed_at`, `reviewed_by`, plus composite index `(skill_name, status)`.

#### Six artisan commands wired through `SuperAICoreServiceProvider`

- **`skill:track-start`** — opens an `in_progress` row. Reads JSON from stdin (Claude Code's PreToolUse hook payload shape: `session_id`, `transcript_path`, `cwd`, `tool_name`, `tool_input.skill`); falls back to `--skill` / `--session` / `--host-app` flags when invoked manually. Emits `--json` (`{id, skill, session_id}`) for hook chaining. Auto-detects host app by walking up to find a sibling `.claude/` directory and using its parent's basename. Telemetry errors are swallowed — never fails the hook.
- **`skill:track-stop`** — closes all `in_progress` rows for a session. Reads stdin (Stop hook payload: `session_id`, `stop_hook_active`, `user_interrupted`); auto-flips to `interrupted` when `user_interrupted` is set. `--status=completed|failed|interrupted` and `--error="…"` available for manual close. Both stdin readers use a 1.0s soft deadline + 200KB cap with non-blocking reads so a pathological pipe can't hang the hook.
- **`skill:stats`** — table or JSON view of the metrics aggregation. `--since=7d|24h|2026-04-20`, `--skill=research`, `--sort=applied|failure_rate|last_used`, `--limit=50`. Color-codes failure rate red ≥30%, yellow ≥10%.
- **`skill:rank "your task description"`** — runs the BM25 ranker, prints score / boost / description in a table. `--no-telemetry` for pure-lexical, `--cwd=/path` to override project-root auto-detection (walks up from cwd looking for `.claude/skills`), `--format=json` returns `[{skill, score, description, source, path, breakdown}, …]`.
- **`skill:evolve --skill=name`** or **`skill:evolve --sweep --threshold=0.30 --min-applied=5`** — queues a FIX candidate (or many). `--dispatch` invokes the LLM via the host's `Dispatcher`. `--execution-id=N` anchors a candidate to a specific failing run (sets `trigger_type=failure`). `--cwd` and `--format=text|json` available.
- **`skill:candidates`** — list/inspect view. `--skill=`, `--status=`, `--limit=20`, `--id=N`, `--show-prompt`, `--show-diff`, `--format=table|json`. Detail view prints the full `llm_prompt` and `proposed_diff` so a reviewer can pipe straight into `git apply`.

### Notes

- **All six commands register through the package service provider** (`SuperAICoreServiceProvider::boot()`) so `php artisan skill:*` works in any host that mounts the package — no per-host wiring needed. The standalone `bin/superaicore` console doesn't auto-mount these because `Application::__construct()` predates the skill engine; expose them there only if you need skill telemetry from outside a Laravel host.
- **Hook wiring lives in the host app, not here.** Claude Code's `.claude/settings.local.json` `hooks` block points `PreToolUse → Skill` at `php artisan skill:track-start` and `Stop` at `php artisan skill:track-stop`. The package only ships the artisan endpoints; SuperTeam's sibling commit demonstrates the full plumbing.
- **No backend / dispatcher / provider changes.** `SkillEvolver::proposeFix(dispatch: true)` calls `Dispatcher::dispatch()` with the host's existing routing — whatever provider answers `capability: 'reasoning'` handles it. No new env vars, no new config keys.
- **No host-app migration.** Run `php artisan migrate` to pick up the two new tables. Both tables are guarded by `Schema::hasTable()` so re-running the migration is idempotent. `down()` drops both tables — safe to re-bootstrap in dev.
- **DERIVED / CAPTURED evolution modes intentionally not shipped.** Auto-deriving new skills from successful runs (DERIVED) and capturing user-demonstrated workflows as new skills (CAPTURED) require an LLM-judgment + human-review queue this release does not have. Both are tracked for a later milestone; FIX is the only mode that can run today.
- **`SuperAICore\Console\Application` `VERSION` constant bumped `0.8.5` → `0.8.6`.** The `composer.json` package version is the source of truth; the constant is what `bin/superaicore --version` prints.

## [0.8.5] — 2026-04-25

**SuperAgent SDK 0.9.5 uptake — `createForHost` host-config adapter migration validated end-to-end.** The 0.8.1 release moved `SuperAgentBackend::buildAgent()` over to the SDK's new `ProviderRegistry::createForHost()` adapter (a 0.9.2 feature explicitly designed for the SuperAICore / SuperTeam / SuperPilot host-integration trio), but shipped with a one-character namespace typo on the new `makeProvider()` return type (`\SuperAgent\Providers\LLMProvider` instead of `\SuperAgent\Contracts\LLMProvider`) that turned every backend call into a swallowed `TypeError` — the in-process SuperAgent path was silently inert across 0.8.1 → 0.8.2. 0.8.5 fixes the namespace, bumps the Composer constraint to `^0.9.5`, and inherits the SDK's wire-format-encoder rewrite (which fixes a class of multi-turn tool-use bugs against non-Anthropic providers). Test suite confirms green: 480 tests, 1380 assertions, 0 failures.

### Fixed

- **`SuperAgentBackend::makeProvider()` return type pointed at a non-existent namespace** (`\SuperAgent\Providers\LLMProvider`). The interface lives at `\SuperAgent\Contracts\LLMProvider`. Every call to `makeProvider()` on the 0.8.1 / 0.8.2 path threw `TypeError` mid-construct; the exception was swallowed by the `\Throwable` catch at the bottom of `SuperAgentBackend::generate()` and the call returned `null` — silently breaking the entire SuperAgent in-process backend the moment the `createForHost` refactor landed. `SuperAgentBackendTest` was failing 15 / 22 (plus 2 errors) from the same root cause. Fix is one line: correct the namespace. All 480 tests now pass.

### Changed

- **Composer constraint bumped `forgeomni/superagent` from `^0.9.0` to `^0.9.5`.** Three SDK improvements ride along with the bump:
  1. **Multi-turn tool-use replays against non-Anthropic providers now work correctly.** Pre-0.9.5, `ChatCompletionsProvider::convertMessage()` early-returned on the first `tool_use` block (dropping sibling text + parallel tool calls) and read nonexistent `ContentBlock` properties (`->id` / `->name` / `->input` instead of `->toolUseId` / `->toolName` / `->toolInput`), so every replayed tool call against Kimi / GLM / MiniMax / Qwen / OpenAI / OpenRouter / LMStudio went out as `{id: null, name: null, arguments: "null"}`. `BedrockProvider::convertMessageToAnthropic()` and `QwenNativeProvider::convertMessage()` had the same bug. 0.9.5 collapses every wire-format converter through a single `Conversation\Transcoder` so the bug fix lands in one place and the six wire families (Anthropic / OpenAI Chat / OpenAI Responses / Gemini / DashScope / Ollama) stay in lockstep. Hosts running `max_turns > 1` against any of those providers were silently broken pre-0.9.5; the upgrade restores correctness with zero call-site changes.
  2. **`Agent::switchProvider($name, $config, $policy)` is available** (0.9.5) for in-process mid-conversation handoff, with `HandoffPolicy::default() / preserveAll() / freshStart()` covering the common cases. SuperAICore's `FallbackChain` walks across CLI subprocesses and doesn't need this directly, but hosts that wrap `SuperAgentBackend` themselves can now route the same conversation through Anthropic → Kimi → Gemini → back to Anthropic without losing the tool-use trail. See the SDK's CHANGELOG for the conversation-transcoder shape.
  3. **`ProviderRegistry::createForHost($sdkKey, $hostConfig)`** (0.9.2) is now the canonical path inside `SuperAgentBackend::buildAgent()`. The dual region / no-region branch collapses to a single call; per-provider constructor-shape differences (Bedrock's split AWS credentials, Azure's auto-detected base URL, LMStudio's synthetic auth, future provider keys) are owned by the SDK adapter, not by this backend. The descriptor-declared `http_headers` / `env_http_headers` knobs ride through `hostConfig['extra']` — the default adapter passes them straight to the provider constructor. Net: `buildAgent()` shrank ~50 lines, and new SDK provider keys land here without any backend code changes.

### Notes

- **Behaviour change for `SuperAgentBackend` test substitution.** `SuperAgentBackend::makeAgent()` now always receives a constructed `LLMProvider` instance (returned from the new `makeProvider()` seam), never a string `'sa-test'` + spread `llmConfig` keys. Tests that previously asserted `$agentConfig['provider'] === 'sa-test'` should assert `instanceof LLMProvider` (`SuperAgentBackendTest::test_no_region_still_hands_llmprovider_instance_to_agent` is the canonical example). Production callers go through `Dispatcher`, which never inspects `$agentConfig['provider']`, so the change is invisible to them.
- **No DB migration. No config change. No breaking call-site change.** Composer constraint is the only host-app touchpoint.
- **`SuperAICore\Console\Application` `VERSION` constant bumped `0.8.0` → `0.8.5`.** The constant had been stale through 0.8.1 and 0.8.2 — now matches the package version `bin/superaicore --version` reports.

## [0.8.2] — 2026-04-25

**Fix the providers-page bottom card so it visually reflects engine availability the same way the top card does.** Patch release on top of 0.8.1 — no behavioural changes elsewhere.

### Fixed

- **Bottom provider card grays out and surfaces a "CLI not installed" badge when a CLI engine's binary is missing**, mirroring the top status card's `$effectivelyOff` logic. Before this fix the bottom half rendered in full colour while the top half showed the engine as unavailable — internally inconsistent and easy to miss. Engine toggled off keeps its existing `engine_disabled_badge`; CLI-missing gets a separate `cli_not_installed` chip so the two causes stay distinguishable. Built-in synthetic row gating now keys off the unified `$beEffectivelyOff` flag for the same reason. Hosts that want to skip the whole card more aggressively (rather than gray it out) can override via `resources/views/vendor/super-ai-core/providers/index.blade.php`.

## [0.8.1] — 2026-04-25

**Portable `.mcp.json` for the install-then-relocate workflow + two providers-page UI lies fixed.** The release lands an opt-in `mcp.portable_root_var` knob: with it set, every `McpManager` writer emits bare commands (`node`, `php`, `uvx`, `uv`, `python`) and rewrites in-tree absolute paths as `${ROOT_VAR}/<rel>`, so a generated `.mcp.json` survives being copied / synced across machines, users, or container layers. Egress to per-machine targets (Codex `~/.codex/config.toml`, Gemini / Claude / Copilot / Kiro / Kimi user-scope MCP configs, `codex exec -c` runtime flags) materialises the placeholders back into absolute paths so backends that don't expand `${VAR}` still spawn correctly. Default is `null` — legacy "absolute path everywhere" behaviour preserved for hosts that haven't opted in. Separately, the `/providers` page stops offering toggles and "Built-in" rows for engines whose CLI binary isn't installed.

### Added

#### Portable MCP path mode (`mcp.portable_root_var`)

- **`super-ai-core.mcp.portable_root_var` config + `AI_CORE_MCP_PORTABLE_ROOT_VAR` env var** (default `null`). When set to an env var name (e.g. `SUPERTEAM_ROOT`), every `McpManager::install*()` writer emits bare command names and rewrites paths under `projectRoot()` as `${ROOT_VAR}/<rel>`. The host's MCP runtime (Claude Code, Codex, Gemini, …) must export the env var — typically via `.claude/settings.local.json` `env` block — for the placeholder to expand at spawn time.
- **Three new helpers on `McpManager`**:
  - `portableRootVar(): ?string` — config accessor (trims, treats empty as `null`).
  - `portablePath(string $abs): string` — abs path under projectRoot → `${VAR}/<rel>`; paths outside the tree returned unchanged; equals-root collapses to bare `${VAR}`.
  - `portableCommand(string $bare, ?string $resolved): string` — bare name when portability on; resolved abs path otherwise (or bare as last fallback when `which` failed).
- **Inverse pair, used at egress**:
  - `materializePortablePath(string): string` — replaces `${ROOT_VAR}` with the env var's runtime value (falls back to `projectRoot()` when the var isn't exported in the current process). No-op when portability is disabled.
  - `materializeServerSpec(array): array` — walks `command` + `args` + `env` of one MCP-server spec; returns a new array.
- **Writer sites updated** (all `.mcp.json` writes — opt-in via `portable_root_var`, BC otherwise):
  - `installUvx` — `$uvx` → bare `uvx`.
  - `installArtisan` — `PHP_BINARY` → bare `php`, `__ARTISAN__` → portable artisan path. Also normalises registry entries that ship `'command' => PHP_BINARY` directly.
  - `installPython` (all five branches: Node entrypoint, `entrypoint_script`, `run_with_tsx`, uv pyproject, venv fallback). When pyproject + uv + portability is on, the `entrypoint_script` branch routes through `uv run <script>` to keep `command` bare instead of pinning a per-machine `.venv/bin/<script>` path.
  - `installPythonPackage` — bare `python`.
  - `installBinary` — portable binary path.
- **Backend-sync helpers updated** (synthesise per-host MCP entries on top of `.mcp.json` at sync time): `superfeedMcpConfig`, `codexOcrMcpConfig`, `codexPdfExtractMcpConfig` use `portableCommand()` for `command` and `portablePath()` for `base_path('artisan')`.
- **Egress hook**: `codexMcpServers()` runs every spec through `materializeServerSpec()` before normalising. All consumers (`syncAllBackends`, `codexMcpConfigArgs`) get bare commands + real abs paths — exactly what each backend's renderer expects to write to disk / spawn.
- **14 new tests** in `tests/Unit/McpManagerPortablePathTest.php` (21 assertions): disabled-by-default pass-through, in-tree rewriting, out-of-tree unchanged, equals-root collapsing, command bare/resolved switch, empty-string-treated-as-null parsing, env-var-driven expansion, projectRoot fallback when the var is unset at runtime, no-op on strings without placeholders, spec walking across `command` / `args` / `env`, and disabled-mode pass-through for full specs.

### Fixed

- **Providers page no longer lies about CLI engines whose binary isn't installed.** Two cases:
  1. **Top toggle** — CLI engines (`claude` / `codex` / `gemini` / `copilot` / `kiro` / `kimi`) without their binary on `$PATH` now render the `enabled` checkbox `disabled` (with a tooltip pointing at the install-CLI hint), the hidden form field clamps to `1` so a stray submit can't claim "engine on", and the card greys out the same way an explicitly-disabled engine does. The "CLI installed / not installed" badge is also gated on `$isCliEngine` so non-CLI backends (`superagent`, `anthropic_api`, …) stop being labelled missing-CLI.
  2. **Bottom "Built-in" row** — the synthetic "built-in (local CLI login)" row was rendering with a "Default backend" badge even when the engine was off or its CLI was missing. Gated on `$showBuiltinRow = $be !== 'superagent' && !$beDisabled && (!$beIsCli || $beCliInstalled)`. When hidden and there are no external providers configured, the table now shows a one-line empty state pointing at the actual reason (CLI missing vs engine off) instead of an empty body.
- No controller / route changes. The existing `toggleBackend` handler still accepts whatever the form sends — the UI just stops asking users to send a request that can't help them.

### Migration path

Hosts that want their `.mcp.json` to survive being relocated (synced into a container image, copied to a teammate's checkout, mounted into a different user's `${HOME}`):

```php
// .env
AI_CORE_MCP_PORTABLE_ROOT_VAR=SUPERTEAM_ROOT
```

```jsonc
// .claude/settings.local.json — host MCP runtime expands ${SUPERTEAM_ROOT} at spawn time
{
  "env": { "SUPERTEAM_ROOT": "${PWD}" }
}
```

After this, click "Install" / "Install All" in `/integrations` (or call `McpManager::installAll()` programmatically). The resulting `.mcp.json` ships bare commands + `${SUPERTEAM_ROOT}/<rel>` paths instead of `C:\Program Files\nodejs\node.exe` and `/Users/jane/projects/foo/.mcp-servers/bar/dist/index.js`. Backends that don't expand `${VAR}` (Codex TOML, Gemini settings JSON, …) still receive absolute paths because `codexMcpServers()` materialises before egress.

Codex helpers that write per-machine `~/.codex/config.toml` entries (not project-scope `.mcp.json`) are intentionally unchanged from the writer side, but `codexMcpServers()` egress materialises any placeholders that bled in from project-scope synthesis.

## [0.8.0] — 2026-04-24

**Consolidates the 0.7.1 + 0.7.2 arc and lands cleanup from a three-agent review pass.** The theme is *host integrations auto-discover new CLI engines*: everything needed to surface a built-in CLI engine — from argv composition down to "Built-in (Engine)" rows in the task-create picker — is now either shipped inside the engine's `ScriptedSpawnBackend` implementation or derived from its `EngineDescriptor`. Host apps that carried per-backend `match` statements in three places (spawn, chat, target-list filtering) collapse to single polymorphic calls. Adding a new CLI engine means writing a `ScriptedSpawnBackend` + seeding `EngineCatalog` + registering on `BackendRegistry`; host code stays byte-identical.

### Added

#### `ScriptedSpawnBackend` contract — detached spawn + one-shot chat
- **`Contracts\ScriptedSpawnBackend` interface** (sibling of `StreamingBackend`) with two methods:
  - `prepareScriptedProcess(array $options): Process` — build a configured `Symfony\Component\Process\Process` the caller nohups / detaches; wrapper script handles stdin-from-file piping (or argv-inline for CLIs without stdin), stdout+stderr log tee, cwd, env scrub, timeouts.
  - `streamChat(string $prompt, callable $onChunk, array $options = []): string` — blocking one-shot chat; backend owns argv construction, prompt-channel (stdin vs argv), output parser (stream-json / plain text / single-blob JSON), and ANSI stripping.
- **Options shape** documented in a PHPStan typedef at the top of the interface file. Codex-specific `-c` extras ride through as a generic `engine_extra_args: string[]` (legacy alias `codex_extra_config_args` still accepted).
- **Six CLI backends implement the contract in one pass**: `ClaudeCliBackend`, `CodexCliBackend`, `GeminiCliBackend`, `CopilotCliBackend`, `KiroCliBackend`, `KimiCliBackend`. Each carries its own per-CLI specifics (session-id + permission-mode + allowedTools + MCP modes for Claude; `exec --json --full-auto` + last-message companion file + config-args for Codex; `--prompt '' --yolo` for Gemini; argv-inline `-p <text>` for Copilot; `chat --no-interactive <text>` for Kiro; `--print --output-format stream-json --prompt <text>` for Kimi).

#### Shared concerns
- **`Backends\Concerns\BuildsScriptedProcess` trait**:
  - `buildWrappedProcess(…, string $stdinMode = 'pipe')` — single wrapper-script emitter covering both stdin-pipe engines (Claude/Codex/Gemini) and argv-prompt engines (Copilot/Kiro/Kimi) via the `stdinMode: 'pipe'|'devnull'` parameter.
  - `applyCapabilityTransform(engineKey, promptFile)` — rewrites prompt in place via `BackendCapabilities::transformPrompt()`, fast-paths out when the capability has an empty `toolNameMap` so Claude/SuperAgent spawns skip MB-scale read+write.
  - `stripAnsi(string)` — CSI + OSC + bare-ESC aware. Shared between Copilot and Kiro `streamChat`.
  - `assertChatExit(Process, response, label)` — collapses the six near-identical non-zero-exit-with-empty-output guards into one helper.
  - `escapeFlags(array)` — `array_map(escapeshellarg)` wrapper.
- **`Support\CliBinaryLocator`** — filesystem probe for CLI binaries. Binary name from `EngineCatalog->cliBinary`. Probes `~/.npm-global/bin`, `~/.local/bin`, `/usr/local/bin`, `/usr/bin`, `/opt/homebrew/bin`, and `~/.nvm/versions/node/<v>/bin` (via `node -v`); Windows variant probes `%APPDATA%/npm` + `%LOCALAPPDATA%/npm`. Result is cached in-memory for the process lifetime — typical spawn resolves 2-3 times, and each uncached call otherwise shells out `node -v` (~20-40ms cold on NVM systems).
- **`BackendRegistry::forEngine(string $engineKey): ?ScriptedSpawnBackend`** — engine-key → first registered backend on `EngineCatalog->dispatcherBackends` that implements the contract.

#### Engine descriptor fields
- **`EngineDescriptor::hasBuiltinAuth(): bool`** — derived from `provider_types` that declare empty `fields` (i.e. "nothing for the user to fill in"). Memoized per descriptor instance. Covers Claude's `builtin` (Keychain), Kimi's `moonshot-builtin` (`~/.kimi/credentials/`), Copilot's `builtin` (`gh auth`), Kiro's `builtin` (`kiro-cli login`).
- **`EngineDescriptor::$authProbeReliable: bool`** (default `true`) — declares whether the CLI has a non-interactive login-status probe. Gemini sets `auth_probe_reliable: false` in its seed because `gemini login status` doesn't exist and `gemini login` drops into a TTY. Hosts that gate built-in targets on `auth.loggedIn` skip the check for engines with an unreliable probe.
- **`ClaudeCliBackend::CLAUDE_SESSION_ENV_MARKERS`** — public `const` array of five `CLAUDE_CODE_*` markers the backend scrubs before spawn (`CLAUDECODE`, `CLAUDE_CODE_ENTRYPOINT`, `CLAUDE_CODE_SSE_PORT`, `CLAUDE_CODE_EXECPATH`, `CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS`). Hosts that still prepare their own env read from this const rather than hand-mirroring.
- **`EngineDescriptor::toArray()`** gains `has_builtin_auth` and `auth_probe_reliable` keys.

### Changed

- **`SuperAICoreServiceProvider`** registers `CliBinaryLocator` as a singleton.
- **`EngineCatalog::__construct`** honors `auth_probe_reliable` from the host's `config('super-ai-core.engines')` override map.
- **`gemini` seed** now sets `auth_probe_reliable: false`.
- **`GeminiCliBackend::prepareScriptedProcess`** auto-sets `GOOGLE_GENAI_USE_GCA=true` when neither `GEMINI_API_KEY` nor `GOOGLE_API_KEY` is in the passed env — previously lived in host's post-processor. Now every host that spawns via Gemini picks up the OAuth fallback.
- **Copilot / Kiro / Kimi `prepareScriptedProcess`** reuse the trait instead of hand-writing their wrapper scripts (review Reuse #1, Quality #2). Net -60 duplicated lines across three backends.
- **All six `streamChat()` methods** now delegate the exit-check/log/throw to `assertChatExit()`, and Copilot/Kiro use `stripAnsi()` from the trait.

### Fixed

- **`ClaudeRunner::findCliPath` in host integrations was not cache-backed**; the SDK-side `CliBinaryLocator` now memoizes so spawn-heavy code paths (task runners, cron dispatchers) save ~60-100ms per spawn across repeated lookups.
- **`hasBuiltinAuth()` is memoized** on the descriptor so `TaskController::availableExecutionTargets` (called up to 3× per render, each iterating all engines) stops making redundant container lookups.
- **Capability-transform read-write-skip** for Claude / SuperAgent prompts — empty tool-name map short-circuits the I/O before the file read.

### Migration path

Hosts currently doing:

```php
$process = match ($backend) {
    BACKEND_CODEX  => $this->buildCodexProcess(...),
    BACKEND_GEMINI => $this->buildGeminiProcess(...),
    default        => $this->buildClaudeProcess(...),
};
// …

$streamChat = match ($backend) { /* 4-arm branch */ };
// …

if (!in_array(TYPE_BUILTIN, $engine->providerTypes, true)) return true;
if ($backend === BACKEND_GEMINI) return true;
```

collapse to:

```php
$process = app(BackendRegistry::class)->forEngine($backend)
    ->prepareScriptedProcess([
        'prompt_file'  => $promptFile,
        'log_file'     => $logFile,
        'project_root' => $projectRoot,
        'model'        => $model,
        'env'          => $env,
        'engine_extra_args' => $engineExtras,  // codex: -c pairs
    ]);

$response = app(BackendRegistry::class)->forEngine($backend)
    ->streamChat($prompt, $onChunk, ['model' => $runModel, 'env' => $env]);

if (!$engine->hasBuiltinAuth())   return true;
if (!$engine->authProbeReliable) return true;
```

After this migration, a new CLI engine that ships a `ScriptedSpawnBackend` implementation + a `needs_api_key: false, fields: []` provider type shows up in every host code path — task create, task run, ResultChat, provider list, model dropdown — without a host-code patch.

## [0.7.2] — 2026-04-23

**EngineDescriptor auth metadata — surface "builtin-capable" + "reliable auth probe" so hosts don't hardcode per-engine exceptions.** Two descriptor fields added: `hasBuiltinAuth(): bool` method (derived from provider-type `needs_api_key: false`) and `authProbeReliable: bool` (default true; gemini declares false). Host integrations that used to guard built-in execution targets with `in_array(TYPE_BUILTIN, providerTypes)` and `$backend === BACKEND_GEMINI` special-cases now read descriptor fields directly — new CLI engines pick up both behaviors from their seed entry, no host-side match to patch.

### Added

- **`EngineDescriptor::hasBuiltinAuth(): bool`** — returns true when at least one of the engine's `provider_types` declares `needs_api_key: false`. Covers every OAuth/keychain/self-auth channel: Claude's `builtin`, Kimi's `moonshot-builtin`, Copilot's `builtin`, future engines that ship their own credential store under a custom provider type.
- **`EngineDescriptor::$authProbeReliable: bool`** (default true) — declares whether the CLI has a non-interactive login-status probe. Gemini-cli has no `gemini login status` subcommand and `gemini login` drops into a TTY, so `auth_probe_reliable: false` on its seed. Hosts that gate built-in execution on `auth.loggedIn` skip the check for engines with an unreliable probe and surface auth failures in the run log instead.
- Both fields round-trip through `toArray()` as `has_builtin_auth` / `auth_probe_reliable`.

### Changed

- `EngineCatalog::__construct` honors `auth_probe_reliable` override from `config('super-ai-core.engines')` — hosts can correct the default per-engine without a catalog patch.
- `gemini` seed sets `auth_probe_reliable: false` (historical behavior, moved here from SuperTeam's `TaskController::isBuiltinExecutionTargetAvailable`).

### Migration path

Hosts currently doing:

```php
if (!in_array(AiProvider::TYPE_BUILTIN, $engine->providerTypes, true)) return true;
if ($backend === AiProvider::BACKEND_GEMINI) return true;
```

collapse to:

```php
if (!$engine->hasBuiltinAuth()) return true;
if (!$engine->authProbeReliable) return true;
```

After this migration, new engines that ship with their own `<engine>-builtin` provider type (declared `needs_api_key: false`) and/or `auth_probe_reliable: false` get first-class "Built-in (<engine>)" rows in every host picker without a host patch.

## [0.7.1] — 2026-04-23

**ScriptedSpawnBackend contract — auto-discover new CLI engines in host integrations.** Hosts integrating AICore (SuperTeam, SuperPilot, shopify-autopilot, …) used to carry a `match ($backend) { 'claude' => buildClaudeProcess(…), 'codex' => buildCodexProcess(…), 'gemini' => buildGeminiProcess(…) }` for every task spawn, plus a second identical switch for one-shot chat paths. Every new CLI engine (kiro, copilot, kimi, future) forced a host patch. `ScriptedSpawnBackend` collapses that to a single polymorphic call: the backend class itself owns argv composition, prompt-file piping, MCP injection, capability transforms, and output parsing. New engines just implement the contract — host code stays byte-identical across engine additions.

### Added

- **`Contracts\ScriptedSpawnBackend` interface** — sibling of `StreamingBackend`. Two methods: `prepareScriptedProcess(array $options): Process` (configured `Symfony\Component\Process\Process` the caller can nohup/detach; wrapper script handles stdin-from-file piping, stdout+stderr log tee, cwd, env scrub, timeouts), and `streamChat(string $prompt, callable $onChunk, array $options = []): string` (blocking one-shot chat — backend owns argv, stdin-vs-argv prompt passing, output parsing, ANSI stripping).
- **`Support\CliBinaryLocator`** — filesystem probe for CLI binaries. Moved from SuperTeam's `ClaudeRunner::findCliPath()` so every backend resolves installs the same way (`~/.npm-global/bin`, `/opt/homebrew/bin`, `~/.nvm/versions/node/<v>/bin`, Windows `%APPDATA%/npm`). Binary name from `EngineCatalog->cliBinary` — no match statement. Registered as a singleton in the service provider.
- **`Backends\Concerns\BuildsScriptedProcess` trait** — shared wrapper-script helpers for `ScriptedSpawnBackend` implementations. `buildWrappedProcess(…)` writes sh/.bat and returns a pre-configured `Process`. `applyCapabilityTransform()` rewrites the prompt file in-place via `BackendCapabilities::transformPrompt()`. `escapeFlags([…])` wraps `escapeshellarg` across an argv list.
- **`BackendRegistry::forEngine(string $engineKey): ?ScriptedSpawnBackend`** — engine-key (e.g. `claude`) → first registered backend on `EngineCatalog->dispatcherBackends` that implements the contract.

### Changed

- **Six CLI backends implement `ScriptedSpawnBackend`** in one pass (`Claude` / `Codex` / `Gemini` / `Copilot` / `Kiro` / `Kimi`). Each class carries its own per-CLI specifics: Claude's `--session-id` / `bypassPermissions` / MCP-config handling / 5-marker `CLAUDE_CODE_*` env scrub; Codex's `exec --json --full-auto -C <root> -o <last-message>` + `codex_extra_config_args` pass-through; Gemini's capability transform (tool-name rewrite) applied before spawn; Copilot / Kiro's argv prompt passing + ANSI stripping; Kimi's stream-json prompt pipe.
- **`ClaudeCliBackend::CLAUDE_SESSION_ENV_MARKERS`** public constant exposes the 5-marker scrub list (`CLAUDECODE`, `CLAUDE_CODE_ENTRYPOINT`, `CLAUDE_CODE_SSE_PORT`, `CLAUDE_CODE_EXECPATH`, `CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS`) so hosts that still compose their own processes can share the canonical list.
- **`SuperAICoreServiceProvider`** — registers `CliBinaryLocator` as a singleton.

### Migration path

Hosts currently carrying a per-backend build switch collapse to:

```php
$backend = app(BackendRegistry::class)->forEngine($engineKey);  // nullable
$process = $backend->prepareScriptedProcess([
    'prompt_file'  => $promptFile,
    'log_file'     => $logFile,
    'project_root' => $projectRoot,
    'model'        => $model,
    'env'          => $env,                     // host-built (reads IntegrationConfig)
    'disable_mcp'  => $disableMcp,              // Claude primarily
    'codex_extra_config_args' => $codexArgs,    // Codex primarily
]);
$process->start();
```

After this migration, future engines that ship a `ScriptedSpawnBackend` implementation show up in every host code path automatically — no match arm to add.

## [0.7.0] — 2026-04-23

**SuperAgent 0.9.1 uptake + round-trip idempotency + classified provider errors + two new provider types.** Composer constraint lifted `^0.9.0` → `^0.9.1`. 0.9.1 is a two-round post-0.9.0 SDK release that reverse-ports mature primitives (filesystem auditing, declarative MCP catalog, provider health, W3C `traceparent` passthrough) and adds a dedicated OpenAI-surface upgrade (new `OpenAIResponsesProvider`, six classified `ProviderException` subclasses, layered retry + jittered backoff, LM Studio provider, Azure OpenAI auto-detection). Every public signature upstream is unchanged, so AICore's uptake is purely additive — five surfaces extended, one long-standing mapping gap fixed on the same pass, no migrations.

### Added

**Round-trip `idempotency_key` through the SDK.** `Dispatcher::dispatch()` now pre-computes the idempotency key via `resolveIdempotencyKey()` *before* `generate()` and injects it onto `$callOptions['idempotency_key']`. `SuperAgentBackend::generate()` forwards it to `Agent::run($prompt, ['idempotency_key' => $key])` — SDK 0.9.1 merges per-call options into the agent's stored options (pre-0.9.1 silently dropped them on the non-auto path) and echoes the (80-char-truncated) key back as `AgentResult::$idempotencyKey`. The backend surfaces it on the envelope as `idempotency_key`, and Dispatcher's write to `ai_usage_logs` prefers the envelope-echoed value over its own pre-computed one. Net effect: hosts whose Dispatcher runs on a different PHP process than the UsageRecorder write-through still observe the same key the SDK saw, and no code has to thread the value sideways.

**Classified `ProviderException` subclasses in `SuperAgentBackend`.** The generic `catch (\Throwable)` is now preceded by six typed catches matching SDK 0.9.1's `Exceptions\Provider\*` subclasses — `ContextWindowExceededException` / `QuotaExceededException` / `UsageNotIncludedException` / `CyberPolicyException` / `ServerOverloadedException` / `InvalidPromptException` — plus a `ProviderException` fallback. Each emits a structured log entry carrying a stable `error_class` tag (`context_window_exceeded`, `quota_exceeded`, …) and the SDK's `isRetryable()` verdict, so operators grepping telemetry see distinct failure modes instead of one "SuperAgentBackend error" bucket. The contract (`generate(): ?array`) still returns `null` on failure — no caller breaks — and a `logProviderError(\Throwable, string)` seam is exposed to subclasses so a host that wants to pivot `$result` onto a classified envelope key can do so in one override. Tests pin two representative classifications.

**Two new provider types: `openai-responses` and `lmstudio`.** `AiProvider::TYPE_OPENAI_RESPONSES` + `AiProvider::TYPE_LMSTUDIO` land as constants, flow into the `TYPES` map, and register as routable types on the `superagent` backend via `BACKEND_TYPES[BACKEND_SUPERAGENT]`. `ProviderTypeRegistry::bundled()` ships full descriptors (icon, form fields, allowed backends, env-key map). Both are routed through the `superagent` dispatcher adapter — `openai-responses` (SDK registry key `openai-responses`) hits OpenAI's `/v1/responses` API and auto-detects Azure deployments from the base-URL pattern; when the provider row stores an `access_token` (from a host-app ChatGPT-OAuth flow) rather than an API key, the SDK flips the base URL to `chatgpt.com/backend-api/codex` so Plus/Pro/Business subscribers hit their subscription quota. `lmstudio` (SDK registry key `lmstudio`) targets a local LM Studio server (default `http://localhost:1234`) with a synthesised placeholder `Authorization` header so no API-key row needs to carry a real secret. The `ProviderTypeRegistry::forBackend('superagent')` count goes from 4 to 6; every host picker that iterates it (`/providers` modal, CLI modes, etc.) surfaces the new types without code changes.

**`http_headers` + `env_http_headers` on `ProviderTypeDescriptor`.** Two new optional fields ship declarative HTTP-header injection for the SDK's `ChatCompletionsProvider`:
- `http_headers: array<string,string>` — literal header name → value. For `X-App-Id: myhost` and similar static identification headers.
- `env_http_headers: array<string,string>` — header name → env var name. The SDK reads the env var at request time and only injects the header when it's set + non-empty (so setting `OPENAI_PROJECT` once lights up an `OpenAI-Project` header on every call, and clearing the var quietly drops it).

Fields default to `[]` (byte-exact behaviour for existing descriptors). `SuperAgentBackend::buildAgent()` projects them onto the llmConfig passed to `ProviderRegistry::createWithRegion()` / `Agent::initialize()` so the SDK applies them uniformly across Chat Completions providers. Host apps extend via the existing `super-ai-core.provider_types.<type>.http_headers` / `env_http_headers` override keys — no package code change needed to inject a new LangSmith / Langfuse / OpenAI-Project / OpenRouter-App header.

**`sdkProvider` on `ProviderTypeDescriptor` — and a fix for a long-standing mapping gap.** A new optional `sdkProvider: ?string` field declares which SDK `ProviderRegistry` key a UI type should route to. The two BYO-base-url wrappers (`anthropic-proxy`, `openai-compatible`) now explicitly declare their SDK provider as `anthropic` / `openai` respectively; `SuperAgentBackend::buildAgent()` consults `resolveSdkProvider($providerConfig)` (via the DI-registered `ProviderTypeRegistry`) when `provider_config.provider` is absent, and falls back to the type string when the descriptor doesn't declare a mapping. Pre-0.7.0 every non-explicit provider silently defaulted to `'anthropic'` — the mapping fix means `openai-compatible` and `anthropic-proxy` rows now actually route to the intended SDK provider when operators haven't hand-set `provider_config.provider`. New types (`openai-responses`, `lmstudio`) declare the mapping from day one. Bundled `anthropic` / `openai` types don't set the field — they already matched.

**W3C `traceparent` / `tracestate` / `trace_context` passthrough.** `SuperAgentBackend::buildPerCallOptions()` forwards three trace-related options to `Agent::run()`:
- `traceparent: string` — canonical W3C `00-<trace-id>-<span-id>-<flags>` string. Usually the inbound `traceparent` HTTP header, propagated by host middleware.
- `tracestate: string` — paired vendor-specific state.
- `trace_context: TraceContext|mixed` — a pre-built `SuperAgent\Support\TraceContext` instance, for callers that already minted one.

Empty-string values are filtered so hosts without a trace middleware don't ship `traceparent: ""` on every request. The SDK projects these into the Responses API's `client_metadata` envelope (`openai-responses` provider) so OpenAI-side logs correlate with the host's distributed trace out of the box; other providers silently ignore. No envelope key is added — existing callers are byte-exact.

### Changed

- `forgeomni/superagent` bumped to **0.9.1** (from 0.9.0). Composer constraint lifted to `^0.9.1`.
- `SuperAgentBackend::generate()` restructured: one-line `$agent->run($prompt)` becomes `$agent->run($prompt, $perCallOptions)`; the envelope carries `idempotency_key` on success; the exception ladder adds six typed catches before the generic `\Throwable`.
- `SuperAgentBackend::buildAgent()` gains `resolveSdkProvider()` + `resolveHttpHeaderKnobs()` helpers + calls `lookupDescriptor()` (new protected seam) to read the type descriptor from the DI container. All three fall back gracefully when the container isn't booted (early CLI, unit tests).
- `Dispatcher::dispatch()` computes the idempotency key early (moves `resolveIdempotencyKey()` above the generate() call) and prefers the envelope-echoed `idempotency_key` over the pre-computed one when writing `ai_usage_logs`.
- `ProviderTypeDescriptor` gains three optional readonly properties (`sdkProvider`, `httpHeaders`, `envHttpHeaders`) with `null` / `[]` defaults. `fromArray()` + `toArray()` + `mergedWith()` thread them through. Every existing construction path compiles unchanged.
- `AiProvider::TYPES` + `AiProvider::BACKEND_TYPES[BACKEND_SUPERAGENT]` grow from 10 → 12 entries and 4 → 6 entries respectively.
- `tests/Unit/SuperAgentBackendTest.php` grows by 6 tests (idempotency forward + echo, 80-char truncation, traceparent forward + empty-drop, two classified-exception paths); `CapturingSuperAgentBackend` gains `lastRunOptions` / `lastErrorClass` capture fields. `tests/Unit/ProviderTypeRegistryTest.php` grows by 5 tests (`openai-responses`, `lmstudio`, `anthropic-proxy`/`openai-compatible` SDK-provider mapping, default empty http-headers, host-config http_headers override). `tests/Feature/UsageIdempotencyTest.php` grows by 2 tests (Dispatcher forwards key onto backend options, Dispatcher prefers envelope-echoed key).

### Migration notes

No database changes. Hosts on 0.6.9 upgrade cleanly.

- **Using `openai-responses` or `lmstudio`?** Add a row on `/providers` with the new type. For `openai-responses`: set `api_key` for metered OpenAI usage, or leave it blank and store an `access_token` in `extra_config.access_token` for a ChatGPT-subscription route — the SDK auto-detects. For Azure OpenAI: point `base_url` at your deployment (e.g. `https://<name>.openai.azure.com/openai/deployments/<deployment>`); the SDK adds the `api-version` query string automatically (override via `extra_config.azure_api_version` if your deployment lags).
- **Want custom headers per provider type?** Override `super-ai-core.provider_types.<type>.http_headers` or `.env_http_headers` in your published `config/super-ai-core.php`. Example:
  ```php
  'provider_types' => [
      'openai' => [
          'http_headers' => ['X-App' => 'my-host-app'],
          'env_http_headers' => ['OpenAI-Project' => 'OPENAI_PROJECT'],
      ],
  ],
  ```
  No package code change required.
- **Exception classification for smarter routing.** No action needed — default behaviour still returns `null` on every failure. Hosts that want to react to specific failure modes (e.g. compact-then-retry on `ContextWindowExceeded`, cycle providers on `QuotaExceeded`) subclass `SuperAgentBackend` and override `logProviderError()` to surface the `error_class` onto the envelope, then read it in their `Dispatcher` wrapper.
- **Distributed tracing.** If your host has an HTTP middleware that reads the inbound `traceparent` header and stashes it (e.g. on a request scope), pass it through to `Dispatcher::dispatch(['traceparent' => $headerValue, ...])`. Silent no-op when empty. See `docs/advanced-usage.md`.
- **`anthropic-proxy` / `openai-compatible` providers whose `provider_config.provider` was empty.** Pre-0.7.0 these routed through the SDK's `anthropic` provider by default (a pre-existing bug — `anthropic-proxy` matched, but `openai-compatible` silently went to `anthropic` and failed). After 0.7.0 the descriptor's `sdk_provider` drives the mapping correctly. Hosts that explicitly set `provider_config.provider` see no change; hosts that relied on the accidental fallback see their `openai-compatible` rows now route where they were meant to.

## [0.6.9] — 2026-04-23

**SuperAgent 0.9.0 uptake.** Point release tracking the SDK's two-wave post-0.8.9 block — kimi-cli-inspired Kimi Code OAuth + live `/models` refresh + prompt-cache-key adapter, then qwen-code-inspired `QwenProvider` rebuild as OpenAI-compat with the legacy DashScope shape preserved as `qwen-native`. Every public signature upstream is unchanged, so AICore's upgrade is purely additive: four surfaces extended, five correctness fixes landed for free.

`forgeomni/superagent` constraint lifted from `^0.8.0` → `^0.9.0` (installed: `v0.9.0`). 0.9.0 is a two-wave post-0.8.9 SDK release driven by close reads of MoonshotAI's `kimi-cli` and Alibaba's `qwen-code`; every public signature is unchanged, so the bump lands as additive surface plus a handful of correctness fixes that AICore gets for free.

### Automatic correctness fixes (no AICore code change)

- **Kimi thinking wire shape.** Pre-0.9.0 the SDK sent a fabricated `kimi-k2-thinking-preview` model id on `thinking` requests — Moonshot never published that id, so every thinking call 400'd. 0.9.0 sends `reasoning_effort: low|medium|high` + `thinking: {type: enabled}` on the real model. Any AICore caller passing `features.thinking.*` to Kimi works now where it previously failed.
- **Fragmented tool_call SSE assembly.** A single streamed tool call split across N chunks used to surface as N ContentBlocks in `AgentResult::$messages` — `SuperAgentBackend::extractSubagentProductivity()` read these as N sub-agents when only one ran. `ChatCompletionsProvider::parseSSEStream()` now accumulates per-index and emits exactly one block per tool; the envelope's `subagents[]` count becomes accurate on every OpenAI-compatible provider.
- **`finish_reason: "error_finish"` now retryable.** DashScope compat-mode's mid-stream throttle signal is raised as `StreamContentError` (retryable, HTTP 429) instead of being silently appended to `result->text()`. AICore callers see correct 429 back-off behaviour for Qwen under load without code changes.
- **Cached-token reads from dual shapes.** `usage.prompt_tokens_details.cached_tokens` (current OpenAI shape, used by Kimi) AND `usage.cached_tokens` (legacy) both populate `AgentResult`'s usage. `SuperAgentBackend`'s `usage.cache_read_input_tokens` envelope key becomes accurate on Kimi where pre-0.9.0 it was `0` for all runs.
- **Anthropic OAuth refresh is now flock-serialized.** `CredentialStore::withLock()` wraps the refresh HTTP call on `anthropic` / `kimi-code` / `qwen-code`. Parallel requests using stored Anthropic OAuth creds (e.g. a Laravel queue with N workers) no longer race-overwrite each other's refresh tokens.

### Added

**`super-ai-core:models refresh [--provider <p>]`** — pulls each provider's live `GET /models` endpoint into the per-provider overlay cache at `~/.superagent/models-cache/<provider>.json`. Wraps SDK 0.9.0's `ModelCatalogRefresher::refresh()` / `refreshAll()`. Supports anthropic / openai / openrouter / kimi / glm / minimax / qwen. New models Moonshot / Alibaba / BigModel ship get picked up on a single CLI hit, above the user override but below runtime `register()` calls — bundled pricing is preserved when the vendor's `/models` omits rates. `status` subcommand gains a `refresh cache` row so operators can see which providers have been pulled. Error hint on failure tells the caller which env var to set. `src/Console/Commands/ModelsCommand.php`.

**`SuperAgentBackend` forwards 0.9.0 provider-level options.** Three new additive plumbing keys:
- `extra_body: array` — deep-merged at the top level of every `ChatCompletionsProvider` request body. Power-user escape hatch for vendor-specific wire fields (Kimi / Qwen / GLM / MiniMax / OpenAI / OpenRouter) before SuperAgent ships a capability adapter for them.
- `features: array` — routed through SDK's `FeatureDispatcher`. Useful keys: `prompt_cache_key.session_id` (Kimi session prompt cache, silent skip elsewhere), `thinking.*` (CoT dispatch with graceful fallback on every provider), `dashscope_cache_control` (Qwen Anthropic-style cache markers).
- `loop_detection: bool|array` — wraps the Agent's streaming handler in `LoopDetectionHarness`. `true` uses SDK defaults; an array overrides thresholds for `TOOL_LOOP` (5 same tool+args in a row) / `STAGNATION` (8 same name) / `FILE_READ_LOOP` (8 of 15 recent read-like calls, with cold-start exemption) / `CONTENT_LOOP` (50-char window 10×) / `THOUGHT_LOOP` (3× same thinking text). Violations fan out via the SDK's wire-event emitter without affecting callers that don't opt in.

A convenience shim accepts `prompt_cache_key: string` directly as a session-id shorthand, mapped to `features.prompt_cache_key.session_id` internally.

**`ApiHealthDetector` — dual-key Qwen and OAuth credential awareness.**
- `qwen-native` joins `DEFAULT_PROVIDERS` alongside `qwen`. Both share `QWEN_API_KEY`, so hosts that want the legacy DashScope-native body shape (for `parameters.thinking_budget` or `parameters.enable_code_interpreter`) see both endpoints in the dashboard probe without re-configuring anything. Callers that need the DashScope-native shape now route via `provider_config.provider = 'qwen-native'` through `SuperAgentBackend` — composer key unchanged, provider-registry key is the only switch.
- `filterToConfigured()` now treats an SDK 0.9.0 OAuth credential file under `~/.superagent/credentials/kimi-code.json` / `qwen-code.json` as "configured" for `kimi` / `qwen`, so a host that ran `superagent auth login kimi-code` (no API key) shows up in `api:status` instead of silently disappearing.

**`McpManager` — three new helpers for mcp.json OAuth servers.**
- `oauthStatus(key)` → `'ok' | 'needed' | 'n/a'` based on SDK 0.9.0's `McpOAuth::cachedToken()` and the server's mcp.json `oauth` block.
- `oauthLogin(key)` → runs SDK's RFC 8628 device flow (`McpOAuth::authenticate()`) against `{client_id, device_endpoint, token_endpoint, scope?}` in the server entry, persisting the token in the SDK's shared store.
- `oauthLogout(key)` → delegates to `McpOAuth::clearToken()`.

These are complementary to the pre-existing `startAuth()` / `clearAuth()` / `testConnection()` methods (which handle browser-login / session-dir-based servers like LinkedIn scraper). A host UI can now render an OAuth button per server whose mcp.json entry declares `oauth: {...}`.

### Changed

- `forgeomni/superagent` bumped to **0.9.0** (from 0.8.9). Composer constraint lifted to `^0.9.0` — 0.8.x is no longer accepted because 0.9.0 rebinds the `qwen` registry key to an OpenAI-compatible provider (pre-0.9.0 was DashScope-native, now `qwen-native`), and we don't want silent behavioural drift on hosts that pin `^0.8.0` and upgrade lockfiles later.
- `SuperAgentBackend` header docblock documents the new `extra_body` / `features` / `loop_detection` / `prompt_cache_key` options and the `region: 'code'` Kimi/Qwen OAuth path.
- `ApiHealthDetector` header docblock documents the two-key Qwen split and the OAuth credential file check.
- `ModelsCommand` subcommand list surfaces `refresh` alongside `list / update / status / reset`; the "SDK not found" hint updates to `^0.9.0`.

### Migration notes

No database changes. Hosts already on 0.6.8 upgrade cleanly.

- **Qwen behaviour change in the SDK.** If your host explicitly relied on SuperAgent's `qwen` provider returning a DashScope-native body shape (i.e. reading `parameters.thinking_budget` / `parameters.enable_code_interpreter` on the wire), switch `provider_config.provider` to `'qwen-native'`. The default binding `'qwen'` now speaks OpenAI-compat `/compatible-mode/v1/chat/completions` — this is what Alibaba's own `qwen-code` CLI uses in production, and it's what AICore assumes going forward.
- **Enabling Kimi prompt caching.** Pass `prompt_cache_key: $sessionId` (or the full `features.prompt_cache_key.session_id` shape) on `SuperAgentBackend::generate()` options when the provider is Kimi. Silent skip on non-Kimi providers, so it's safe to pass unconditionally from a shared dispatcher.
- **Enabling loop detection for in-process runs.** Pass `loop_detection: true` on `SuperAgentBackend::generate()` options. Violations fire as wire events on the SDK's internal emitter — no AICore envelope key is added, so existing callers are byte-exact.
- **Live model-catalog refresh.** Run `php artisan super-ai-core:models refresh` (or `./bin/superaicore super-ai-core:models refresh`) after setting provider API-key env vars. Subsequent `CostCalculator` / `ModelResolver` calls see the live catalog automatically via `ModelCatalog::overlayRefresherCache()`.
- **MCP OAuth login flow in the UI.** `McpManager::oauthStatus()` / `oauthLogin()` / `oauthLogout()` are blocking on stdio during the device-flow poll (the SDK prints the verification URL + user code to STDERR). Host UIs that surface these should run the login out of the request cycle — a queued job or a shelled-out `superagent auth login-mcp <name>` is the right pattern. Status and logout are safe from a web request.

## [0.6.8] — 2026-04-22

Fans MCP configuration out from one host-owned catalog, upgrades the in-process SuperAgent backend to actually use what the SDK ships in 0.8.8, and hardens the weak-model agent-spawn path so a Gemini Flash child that ignores its output contract gets flagged instead of silently polluting the consolidator's view. Three strands in one release:

1. **Catalog-driven MCP sync.** A single `mcp-catalog.json` + `.claude/mcp-host.json` mapping now drives project `.mcp.json`, per-agent frontmatter `mcpServers:` blocks, and every installed CLI backend's user-scope config (Claude / Codex / Gemini / Copilot / Kiro). RUN 63 (2026-04-21) surfaced the motivating bug: hosts trimmed `.mcp.json` but forgot to push the change to Gemini's own config, so Gemini kept spawning 50+ servers that no longer existed and blew past its process cap. The new flow makes "edit the catalog → re-run `claude:mcp-sync` → every backend drops to the same server set" the single path.
2. **SuperAgent 0.8.9 integration.** The `SuperAgentBackend` was a 65-line one-shot wrapper. It now honours `max_turns`, `max_cost_usd` (hard budget cap inside the Agent loop), tool filters, `mcp_config_file`, and the Kimi/Qwen/GLM/MiniMax `region` split that landed in SDK 0.8.8. When callers opt into SDK sub-agent dispatch (`load_tools: ['agent', …]`) the envelope also forwards SDK 0.8.9's `AgentTool` productivity info (`filesWritten` / `toolCallsByName` / `productivityWarning` / `status: completed_empty`) under a new `subagents` key, so `Dispatcher` callers can detect a sub-agent that produced only prose without scraping narratives. New `ApiHealthDetector` + `api:status` command wrap the SDK's `ProviderRegistry::healthCheck()` so operators can tell auth-rejected vs network-timeout vs no-key apart for every direct-HTTP API provider (anthropic / openai / gemini / kimi / qwen / glm / minimax / openrouter) from one table.
3. **Weak-model agent-spawn hardening.** After RUN 68 (2026-04-22) where a Gemini Flash child wrote a `generate_charts.py`, fabricated a sibling-role subdir, and crowned its subdir with a `summary.md` that belongs to the consolidator, `Orchestrator::run()` now audits each agent's output subdir post-fanout and annotates the report with contract-violation warnings. Per-agent plumbing (`run.log`, prompt, exec script) also moves out of the user-facing output dir into `$TMPDIR`, so the founder browsing the run directory sees only real deliverables. `GeminiCliBackend::parseJson()` tolerates the "YOLO mode is enabled." / "MCP issues detected." / deprecation-warning preamble the CLI prepends to its JSON output. Additional defences layered in alongside: host-injected per-agent **guard clauses** in `task_prompt` (language-aware — CJK detection picks Chinese vs English), **canonical ASCII `output_subdir`** (RUN 70: Gemini Flash emitting `首席执行官` instead of `ceo-bezos` broke consolidation walks — the host discards the model's preference), **pre-fanout cleanup** of early `摘要.md` / `思维导图.md` / `流程图.md` the first-pass model wrote at output root in violation of the "emit plan and stop" preamble, and a **language-aware consolidation prompt** (RUN 71: Chinese runs were getting `# Executive Summary` English section titles on top of Chinese body — the prompt now fixed-maps English → Chinese headings and forbids fabricated error-filenames like `Error_No_Agent_Outputs_Found.md`).

No breaking changes. No migrations. Hosts already on 0.6.7 upgrade cleanly.

### Added

**`claude:mcp-sync` — catalog + host-map → project `.mcp.json` + agent frontmatter + backend configs**
- New standalone & artisan command. Reads a host-supplied catalog (`.mcp-servers/mcp-catalog.json`, shape `{mcpServers: {name: {command, args, env}}}`) plus a thin host mapping (`.claude/mcp-host.json`) that picks the project tier-1 server list and per-agent tier-2 assignments. Writes the canonical project `.mcp.json`, upserts managed `mcpServers:` blocks inside `.claude/agents/*.md` between `# superaicore:mcp:begin` / `# superaicore:mcp:end` markers, then propagates the same server set to each installed CLI backend's native config via `McpManager::syncAllBackends()`.
- `--dry-run` prints the +/- change table without touching disk. `--no-propagate` skips the backend fan-out. `--host-config` / `--project-root` accept overrides for scripted runs.
- Non-destructive by contract (via `AbstractManifestWriter`): byte-equal on-disk hash → `unchanged`; on-disk hash differs from manifest → `user-edited` and we leave it alone for the project file; for agent frontmatter, edits outside the markers are preserved while edits inside are flagged `user_edited` but still overwritten (the managed region belongs to this tool by design). Agents absent from `assignments` are **never** touched.

**`mcp:sync-backends` — standalone fan-out for hand-edited `.mcp.json` or file-watcher auto-sync**
- Ships as a separate entry point for three cases: hand-edited `.mcp.json` (bypassing the host-map flow), file-watcher / git-hook driven auto-sync on every `.mcp.json` write, and recovering from a backend whose own config drifted. `--backends=claude,codex` narrows the target set; default is every backend whose `BackendCapabilities::supportsMcp()` returns true.

**`Services\McpCatalog` + `Sync\ClaudeProjectMcpWriter` + `Sync\ClaudeAgentMcpWriter`**
- `McpCatalog` loads the catalog JSON and exposes `names() / has() / get() / subset() / domain()` — kept separate from `McpManager` because `McpManager`'s registry is installer-oriented (icons, categories, install_dir), while `McpCatalog` is the runtime-config view (portable paths).
- `ClaudeProjectMcpWriter::render()` emits a deterministic `.mcp.json` shape (`{type, command, args?, env?}` per server, `args` / `env` omitted when empty, trailing newline). `sync()` round-trips through `AbstractManifestWriter::applyOne()`.
- `ClaudeAgentMcpWriter::renderManagedBlock()` / `upsertManagedBlock()` / `extractManagedBlock()` splice the YAML block into each agent's frontmatter; tolerates CRLF frontmatter and BOM prefixes. Throws when the agent file lacks leading `---` (rather than silently injecting into free-form markdown).

**`SuperAgentBackend` — in-process agentic loop instead of one-shot completion**
- `max_turns` (default 1, preserves pre-0.6.8 behaviour); `max_cost_usd` → `Agent::withMaxBudget()` so a runaway in-process loop aborts before it burns the caller's budget; `allowed_tools` / `denied_tools` filter the tool surface; `mcp_config_file` (pointing at a `.mcp.json` — same shape `claude:mcp-sync` writes) loads via `MCPManager::loadFromJsonFile()` + `autoConnect()`, registers each MCPTool on the Agent, and `disconnectAll()` runs in `finally{}` so stdio subprocesses don't linger past `generate()`.
- `provider_config.region` routes through `ProviderRegistry::createWithRegion()` rather than the normal string-name path — SuperAgent 0.8.8's region map (Kimi intl/cn, Qwen intl/us/cn/hk, GLM intl/cn, MiniMax intl/cn) requires the provider instance be built with the region in config, but `Agent::resolveProvider()`'s internal 8-key allowlist silently drops `region`. The backend builds the LLMProvider explicitly and hands the instance in to route around this.
- Envelope gains `usage.cache_read_input_tokens`, `usage.cache_creation_input_tokens`, `cost_usd` (the SDK's own turn-summed cost — Dispatcher already prefers backend-reported cost when non-zero), and `turns`.
- Default path passes `tools: []` to short-circuit SDK's `ToolLoader` — avoids a cascade of `[SuperAgent] Config unavailable …` stderr lines in non-Laravel contexts. Callers wanting the SDK's default tool set pass `load_tools: true` explicitly.
- **SDK 0.8.9 AgentTool productivity forwarded via envelope `subagents` key (predisposed).** When a caller opts into SDK sub-agent dispatch (`load_tools: ['agent', …]`), the new `extractSubagentProductivity()` helper walks `AgentResult::$messages` for `tool_result` blocks emitted by the SDK's `AgentTool`, decodes them, and bubbles `{agentId, status, filesWritten, toolCallsByName, productivityWarning, totalToolUseCount}` per sub-agent into an optional envelope `subagents: […]` key. Dispatcher callers can detect a sub-agent that produced only prose (`status: completed_empty`) or called tools without writing (advisory `productivityWarning`) without scraping narratives. **Backward-compatible by construction:** the key is omitted when no AgentTool result appears in the trail, so existing callers that don't dispatch sub-agents through the SDK path see a byte-exact envelope.

**`api:status` + `ApiHealthDetector` — 5s cURL probe for direct-HTTP API providers**
- New command (`bin/superaicore api:status` / `php artisan api:status`) runs SuperAgent's `ProviderRegistry::healthCheck()` against anthropic / openai / openrouter / gemini / kimi / qwen / glm / minimax. Each probe is a 5s `GET /v1/models` (or equivalent cheapest listing endpoint) and returns `{ok, latency_ms, reason}`. Auth rejections (HTTP 401/403), network timeouts, and missing keys each report themselves with a distinct `reason` so operators can fix the right thing without guessing.
- Default behaviour filters to providers whose API-key env var is actually set — `--all` probes every DEFAULT_PROVIDERS entry (useful for debugging which env vars are missing), `--providers=a,b,c` narrows, `--json` emits structured output for piping into dashboards. Parallel sibling of `cli:status` for CLI engines.

**`Services\CliStatusDetector::safeProbeOutput()` helper**
- Extracts the "spawn a short CLI probe, swallow timeouts/crashes, return trimmed stdout or null" pattern shared by `detectBinary()` (`--version`) and `detectAuth()` (Claude / Codex auth subcommands). Status probes run from `/providers` and `cli:status` on every hit and must be infallible; the new helper centralises that contract so future probes can't accidentally let a Throwable leak.

**`AgentSpawn\Orchestrator::auditAgentOutput()` — post-fanout contract audit**
- Scans each agent's output subdir after its child exits and flags three classes of weak-model contract violation: (a) non-whitelisted extensions (anything outside `md` / `csv` / `png`), (b) consolidator-reserved filenames inside an agent subdir (`summary.md`, `思维导图.md`, `流程图.md`, English + Chinese variants), and (c) sibling-role sub-directories (kebab-case agent-id shapes, plus a hard list of role nouns: `ceo / cfo / cto / marketing / …`). Warnings land in `report[N].warnings[]` — never modifies disk, so a founder-facing re-dispatch decision stays with the host.
- `Pipeline::runFanoutAndConsolidate()` logs each warning to `laravel.log` via `logger->warning('Pipeline: audit [<agent>] — <message>')` so regressions are visible without the operator opening per-agent `run.log` files in `$TMPDIR`. The `_spawn_plan.json` plumbing file is deleted on successful consolidation — it's a mechanism, not a deliverable, and cluttered the output dir a founder actually browses.

**Per-agent plumbing moved to `$TMPDIR`**
- `Orchestrator::run()` now mints one `$TMPDIR/superaicore-spawn-<date>-<hex>/<agent>/` tree per fanout and writes each child's `run.log` + derived `run.prompt.md` / `run-exec.sh` / `-last.txt` files there. The user-facing `$outputRoot/<agent>/` dir only receives the child's real deliverables via Write/write_file — so the founder browsing the run output sees only `.md` / `.csv` / `.png`, not five lines of shell plumbing. Retained on failure paths for post-mortem debugging.

**`GeminiCliBackend::parseJson()` tolerates CLI preamble noise**
- Gemini CLI prepends noise to stdout before the JSON blob depending on flags and environment: `"YOLO mode is enabled. All tool calls will be automatically approved."` (often twice), `"MCP issues detected. Run /mcp list for status."`, deprecation warnings. A strict `$output[0] !== '{'` check was dropping the whole result → `text=''` → `TaskRunner` flagged `success=false` → `Pipeline`'s spawn-plan handoff was skipped while `_spawn_plan.json` sat orphaned in the output dir (RUN 65, 2026-04-22). Parser now locates the first `{` and decodes from there; `json_decode` itself rejects the case where the `{` is inside a preamble sentence rather than starting a real object.

**`SpawnPlan::appendGuards()` — host-injected per-agent guard block in every `task_prompt`**
- New static that appends a language-appropriate guard block (six rules: stay in lane, no consolidation filenames, language uniformity including filenames, extension whitelist `.md` / `.csv` / `.png`, canonical `_signals/<agent-name>.md` path, don't apologize for tool failures). Idempotent via `## [SuperAICore host-injected per-agent guard]` marker so re-parsing an already-processed plan doesn't double-append.
- Language detection is a single CJK regex on the agent's `task_prompt` — if any char ≥ U+4E00 appears, the Chinese template runs; otherwise the English one. Both convey the same six rules so `Orchestrator::auditAgentOutput` catches the same violations regardless of which language ran.
- Also strips any inline `CRITICAL OUTPUT RULE: …` sentence the first-pass model embedded — those conflict with the fresh, path-authoritative version ChildRunner appends from `$outputRoot/$output_subdir`, and when the first-pass model emitted a localized `output_subdir` that the host overrode, the two disagreed. RUN 68 / RUN 70 fix, 2026-04-22.

**`SpawnPlan::fromFile()` — canonical ASCII `output_subdir` enforcement**
- Now always sets `output_subdir = agent.name`, discarding the model's preference. Gemini Flash regressed from `ceo-bezos` → `首席执行官` under `$LANGUAGE=zh-CN`, and `Orchestrator::auditAgentOutput()` would then walk `$outputRoot/ceo-bezos/` (empty) while the real writes landed at `$outputRoot/首席执行官/` — silent false clean bill of health, plus the consolidation re-call hallucinated "no output files found" on the non-ASCII path. Children write to whatever absolute path the host-injected guard pins (`$outputRoot/<canonical>/`), so the model's original `output_subdir` value was never load-bearing in practice.

**`Pipeline::cleanPrematureConsolidatorFiles()` — pre-fanout reserved-filename sweep**
- Before kicking off fanout, removes any consolidator-reserved file sitting at `$outputDir` top-level (`summary.md`, `mindmap.md`, `flowchart.md`, `摘要.md`, `思维导图.md`, `流程图.md` + `.html` variants). RUN 70 surfaced Gemini Flash writing `思维导图.md` + `流程图.md` during first-pass plan emission even though the preamble says "emit plan and STOP"; leaving those in place either misled the founder into thinking consolidation ran, or raced the real consolidation pass. Emits one `Pipeline: removed premature consolidator file before fanout — <path>` warning per deletion so the provenance is traceable back to a specific backend invocation. Idempotent — non-first runs with nothing to clean are no-ops.

**`CodexCapabilities` / `GeminiCapabilities` preamble — mandatory per-agent guard-clause directive**
- Both preambles now explicitly instruct the plan-emitting backend to embed four verbatim rules inside every generated `task_prompt`: (a) stay in your lane (one agent only, no sibling-role sub-dirs); (b) consolidation is not your job (forbid `summary.md` / `摘要.md` / `思维导图.md` / `流程图.md` / `mindmap.md` / `flowchart.md`); (c) language uniformity including CSV headers, `_signals/<name>.md`, code comments; (d) extension whitelist `.md` / `.csv` / `.png` only. Belt-and-braces with `SpawnPlan::appendGuards()` — the preamble directive prevents first-pass output from being wrong in the first place; the host-side injection guarantees the guards reach every child even if the plan-emitting model ignored the directive (as Flash sometimes does).

**`tests/Unit/OrchestratorAuditTest.php` + `tests/Unit/SpawnPlanGuardsTest.php`**
- Fixture-based coverage of `Orchestrator::auditAgentOutput()` (extension whitelist, sibling-role detection, consolidator-reserved-name detection, `_signals/` exemption) and `SpawnPlan::appendGuards()` (CJK-triggered language routing, idempotency under marker, `CRITICAL OUTPUT RULE` strip, canonical `output_subdir` override).

**`docs/mcp-sync.md`**
- End-to-end documentation for the MCP-sync layer: catalog shape, host mapping shape, non-destructive contract, dry-run, propagation, typical workflows (first setup / adding a server / recovering a drifted backend).

### Changed

- `forgeomni/superagent` bumped to **0.8.9** (from 0.8.7). 0.8.8 shipped the native Kimi / Qwen / GLM / MiniMax providers + region-aware credentials + capability-driven feature dispatch; 0.8.9 is a tiny focused release that adds `AgentTool` productivity instrumentation (see the `SuperAgentBackend` entry above for how we forward it). Composer constraint stays at `^0.8.0`.
- `Services\CostCalculator::resolveRate()` — SDK's `\SuperAgent\Providers\ModelCatalog::pricing()` was already the final fallback since 0.6.3; behaviour unchanged, but with SuperAgent 0.8.9 pinned the catalog now covers Kimi K2.6, Qwen3.6, GLM-5 / 4.6, MiniMax M2.7 out of the box — new models get accurate pricing on first run without `composer update`.
- `src/Console/Application.php` — registers the three new commands (`claude:mcp-sync`, `mcp:sync-backends`, `api:status`).
- `SuperAICoreServiceProvider::boot()` — registers the same three as artisan commands in Laravel hosts.
- `Capabilities\SpawnConsolidationPrompt::build()` — now language-aware. Detects CJK in any agent's `task_prompt` (same heuristic as `SpawnPlan::appendGuards`) and picks between `buildZh()` / `buildEn()`. The Chinese variant hard-codes an English → Chinese section-heading map (`# Executive Summary` → `# 执行摘要`, `## Key Findings` → `## 关键发现`, `## Agreement` → `## 一致点`, `## Disagreement` → `## 分歧点`, `## Recommendations` → `## 建议`, `## Risks` → `## 风险`, `## Appendix` → `## 附录`, `## Warnings` → `## 警告`) and flags the mapping as a "hard constraint; downstream renderer treats English headings on a Chinese body as failure" (RUN 71 fix, 2026-04-22). Both variants now explicitly forbid fabricated error-filenames like `Error_No_Agent_Outputs_Found.md` / `consolidation_failed.md` — errors go into a `## ⚠️ Warnings` / `## 警告` section inside `摘要.md` instead, so the three-file contract stays intact even when an agent's subdir is empty. Both variants now also encourage embedding child-generated PNG charts (`![](<agent-subdir>/<chart>.png)`) in the summary so the consolidated doc carries visuals.

### Migration notes

No database changes. Hosts should review:

- **First-time MCP-sync setup:** drop a catalog at `.mcp-servers/mcp-catalog.json`, write `.claude/mcp-host.json` referencing which servers belong to the project tier vs which agents get which tier-2 servers, then `php artisan claude:mcp-sync --dry-run` to preview. See `docs/mcp-sync.md` for the full shape.
- **Hosts already using `SuperAgentBackend` for one-shot calls:** no change. `max_turns` defaults to 1, envelope stays shape-compatible (new keys are additive), `generate()` still returns `array|null`.
- **Hosts that want real agentic runs through `SuperAgentBackend`:** pass `max_turns > 1`, and optionally `max_cost_usd` as a safety cap and `mcp_config_file` to make the project's MCP tools available to the in-process Agent. Hosts that additionally opt into SDK sub-agent dispatch (`load_tools: ['agent', …]`) get a new optional envelope key `subagents: [{agentId, status, filesWritten, toolCallsByName, productivityWarning, totalToolUseCount}, …]` — use `status === 'completed_empty'` or a non-null `productivityWarning` as a re-dispatch signal when a child produced only prose.
- **API provider debugging:** `bin/superaicore api:status --all --json` surfaces a per-provider `{ok, latency_ms, reason}` table — fastest way to distinguish a dead key from a network issue.

---

## [0.6.7] — 2026-04-22

Runtime-polish release focused on Claude CLI headless invocation + Process Monitor accuracy. Two production blockers uncovered while running `claude` from PHP-FPM dev servers (SuperTeam / PPT) are fixed upstream: (1) child claude processes inherited the parent `claude` shell's `CLAUDECODE` / `CLAUDE_CODE_*` markers and tripped the recursion guard with `"Not logged in"`; (2) `builtin` OAuth auth failed under PHP-FPM because macOS Keychain access is scoped to the audit session that wrote the item, and web workers live in a different session than the terminal where the user ran `claude login`. The Process Monitor also switches to a live-only view so finished runs disappear the moment their subprocess exits instead of accumulating in the UI.

No breaking changes. No migrations. Hosts already on 0.6.6 upgrade cleanly.

### Fixed

**`ClaudeCliBackend` env scrub — unsets `CLAUDECODE` / `CLAUDE_CODE_*` parent markers**
- When `php artisan serve` (or a `php-fpm` pool) was launched from a shell that was itself inside a `claude` session, the parent Claude Code 2.x process set `CLAUDECODE=1`, `CLAUDE_CODE_ENTRYPOINT=cli`, `CLAUDE_CODE_SSE_PORT=...`, `CLAUDE_CODE_EXECPATH=...`, `CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS=...` and those leaked through Symfony Process into the child `claude -p` invocation. Claude's parent-recursion guard saw them and refused authentication with `"Not logged in · Please run /login"` even though the user was fully logged in.
- `buildEnv()` now seeds the Process env array with those five keys set to `false` (Symfony's "actively remove this var from the child env" sentinel) before any provider-type branch runs. The bedrock / vertex branches still set their own `CLAUDE_CODE_USE_BEDROCK` / `CLAUDE_CODE_USE_VERTEX` on top — those are intentional and not in the unset list.

**`ClaudeCliBackend` builtin OAuth — macOS Keychain fallback via `security` CLI**
- `builtin` provider type (local `claude login`) used to rely on claude's native Keychain call to read the OAuth token. That call respects macOS audit-session boundaries, so processes spawned from PHP-FPM workers (web UI → `nohup` → `task:execute` → `claude`) live in a different audit session from the interactive shell where the user ran `claude login` and the call silently fails — the CLI reports `apiKeySource:"none"` and `"Not logged in"`.
- New `readBuiltinOauthToken()` shells out to `security find-generic-password -s "Claude Code-credentials" -w`, parses the JSON payload, extracts `claudeAiOauth.accessToken`, and injects it as `ANTHROPIC_API_KEY` in the child env. Claude honors that env var as an authenticated session. Silent fallback on non-macOS / no-token / no-login — env stays empty and claude's native path takes over, matching prior behavior.
- This only kicks in when the provider type is `builtin` AND the host platform is Darwin AND the Keychain item exists. Zero change for API-key providers, bedrock, vertex, or Linux deployments.

**`AiProcessSource::list()` — live-only view (OS is source of truth)**
- Previously returned the last 100 `ai_processes` rows ordered by `started_at` with dead PIDs reaped as a side effect. Finished / failed / killed runs lingered in the Process Monitor UI until a user manually cleared them, and the `status` column was stamped from the DB row (which could lag the real subprocess).
- Now indexes the live `ps aux` snapshot once, iterates only `status=running` rows, verifies each row's PID is in the live set (falling back to `$p->isAlive()` for cross-platform parity), reaps dead PIDs as before, and returns ONLY the verified-alive entries with `status=running` stamped from ground truth. Hosts that want a historical view should query `ai_processes` directly — the table remains the full audit log of every spawn.

### Added

**`cwd` option on every `StreamingBackend::stream()`**
- New optional key on the stream options. When set, overrides the child process's working directory via `Process::setWorkingDirectory()`. Critical when the parent PHP process runs from a directory the CLI doesn't expect (e.g. PHP-FPM serving from `web/public` while the CLI's skill / agent / MCP loaders need to find `artisan` + `.claude/` at the project root).
- Plumbed through all five CLI backends (`ClaudeCliBackend` / `CodexCliBackend` / `GeminiCliBackend` / `KiroCliBackend` / `CopilotCliBackend`) and the shared `StreamableProcess` trait. Omit the key to keep the process's inherited cwd — prior behavior.

**Claude-specific stream options: `permission_mode`, `allowed_tools`, `session_id`**
- `permission_mode` — forwarded to claude's `--permission-mode` flag. Pass `'bypassPermissions'` for headless runs (claude otherwise blocks on interactive Write / Edit / Bash approval prompts that never get answered and produces no output). `'default'` / `'plan'` also accepted for interactive wrappers; omit to leave claude's default.
- `allowed_tools` — forwarded to `--allowedTools` as a comma-separated list (accepts array or string, e.g. `['Read','Grep','Write','WebSearch']`). Restricts the tool surface when combined with `permission_mode=default`.
- `session_id` — forwarded to `--session-id` for traceability across host log files and claude's session store. Claude auto-generates one when omitted.
- Documented on the `StreamingBackend` option-shape PHPDoc so IDEs auto-complete them. Other CLIs ignore these three keys (no-op) — they're claude-specific today.

**`process_monitor.host_owned_label_prefixes` config**
- New list-typed config key under `super-ai-core.process_monitor`. Hosts register the label prefixes their own `ProcessSource` claims (SuperTeam uses `['task:']`). When `AiProcessSource::list()` encounters a row whose `external_label` starts with one of those prefixes, it skips emitting it — the host's rich entry (with task / project / model / provider badges) is the only one the view renders. The row is still PID-verified and reaped if dead.
- Default `[]` — legacy behavior (emit every row) preserved for hosts without their own ProcessSource.

### Changed

- `Contracts\StreamingBackend` — option-shape PHPDoc extended with `cwd`, `permission_mode`, `allowed_tools`, `session_id`. No method-signature change; backends that don't consume a key silently ignore it.
- `resources/views/processes/index.blade.php` — the "Run #X" pill now resolves through `__('processes.run_id')` instead of a hard-coded label, so host translation packs can override it cleanly. No `processes` translation file ships in this release yet — Laravel's fallback renders the key verbatim until one is published.
- `composer.json` — reformatted to 2-space indentation. No dependency or metadata changes.

### Migration notes

No database changes. Hosts should review:

- **Running claude from PHP-FPM / `php artisan serve` under a parent `claude` shell:** the env-scrub fix means your first 0.6.7 run will start authenticating correctly without any host-side change. If you had a workaround that manually unset the `CLAUDECODE` markers, it's now redundant but harmless.
- **Hosts with their own `ProcessSource`:** add your label prefix to `super-ai-core.process_monitor.host_owned_label_prefixes` in `config/super-ai-core.php` to stop `AiProcessSource` from emitting a duplicate bare row for runs your source already renders.
- **Hosts relying on `AiProcessSource::list()` returning finished rows:** switch to querying `ai_processes` directly. The list() method is now explicitly live-only by contract.

---

## [0.6.6] — 2026-04-21

Bundles all five phases of the **host-spawn-uplift** roadmap (`docs/host-spawn-uplift-roadmap.md`) in one release: live-streaming CLI execution (Phase A), one-call task orchestration (Phase B), three-phase spawn-plan emulation (Phase C), `ai_usage_logs` idempotency (Phase D), and a formal SemVer contract freeze (Phase E). Hosts that want to stay on `Backend::generate()` are unaffected — every new path is purely additive. The one technical interface addition (`BackendCapabilities::spawnPreamble` + `consolidationPrompt` from Phase C) is shielded for downstream extenders by the new `Capabilities\Concerns\BackendCapabilitiesDefaults` trait shipped in Phase E.

**Migration required:** `php artisan migrate` adds the nullable `idempotency_key` column + composite index for Phase D. No config changes. No host code changes — existing call sites keep working.

### Added — Phase E (API stability + forward-compat trait)

**`docs/api-stability.md` — formal SemVer contract**
- Lists every API now considered stable: `Contracts\StreamingBackend`, `Support\TeeLogger`, `Backends\Concerns\StreamableProcess`, `Runner\TaskRunner`, `Runner\TaskResultEnvelope`, `AgentSpawn\Pipeline`, `Contracts\BackendCapabilities` (with the trait below for future-proofing), `Capabilities\SpawnConsolidationPrompt::build()` (signature only — prompt text remains tunable), `Services\Dispatcher::dispatch()` option keys + return shape, `Services\UsageRecorder::record()` shape, `Services\EloquentUsageRepository::IDEMPOTENCY_WINDOW_SECONDS`, and `Models\AiUsageLog` columns.
- Documents the deprecation policy: deprecated APIs ship in minor release N with a pointer to the replacement, coexist for at least two minor releases (N+1, N+2), and only get removed at the next major.
- Lists what's intentionally NOT stable so hosts know which surfaces they can lean on and which to avoid: concrete CLI backend internals, `Runner\AgentRunner` family (older API), `AgentSpawn\Orchestrator` direct usage, `AgentSpawn\ChildRunner` interface, Blade views, CLI command output formats, internal index/column types.
- Includes a **pre-soak caveat** documenting that the maintainer chose to declare stability before the originally-planned production-soak window. If a Phase A/B/C/D bug forces a backward-incompatible fix, the maintainer will bump major rather than retroactively rewrite history.

**`Capabilities\Concerns\BackendCapabilitiesDefaults` — forward-compat trait**
- New trait providing no-op default implementations of any `BackendCapabilities` methods added after the Phase E freeze (currently `spawnPreamble()` and `consolidationPrompt()` from Phase C, both returning `''`).
- Hosts implementing custom `BackendCapabilities` should `use BackendCapabilitiesDefaults;` to inherit safe defaults for any method SuperAICore adds in future minor releases — the host class stays satisfying the interface without adding the new method itself. Bundled `*Capabilities` classes do NOT use the trait (they provide real implementations); it exists exclusively for downstream extension safety.
- Maintainer commitment: when SuperAICore adds another `BackendCapabilities` method in a future release, a no-op default lands in this trait in the SAME release so hosts that adopted the trait get safe semantics for free.

**README.md — `TaskRunner` promoted to recommended entry point**
- New "PHP quick start" section leads with a realistic `TaskRunner::run()` example showing log-file tee, MCP injection, spawn-plan handoff, idempotency-via-external_label, and live `onChunk` UI updates. The previous one-shot `Dispatcher::dispatch()` example moves below it as the "short call" path for non-task workloads (test connections, vision routing, embeddings).
- Cross-links to the four phase docs (`task-runner-quickstart.md`, `streaming-backends.md`, `spawn-plan-protocol.md`, `idempotency.md`) plus the new `api-stability.md`.

### Added — Phase D (idempotency_key + 60s dedup window)

**Migration: `ai_usage_logs.idempotency_key VARCHAR(80) NULL`**
- New migration `2026_04_21_000002_add_idempotency_key_to_ai_usage_logs.php` adds the column + a composite index `(idempotency_key, created_at)` covering the "find a matching row in the last N seconds" lookup the repository runs on every record() with a key set. Run `php artisan migrate` after upgrading.
- Nullable + non-unique by design — old rows + non-keyed callers (test_connection probes, ad-hoc scripts) coexist fine.

**`EloquentUsageRepository::record()` honors `idempotency_key`**
- When the input data has `idempotency_key` set, the repository checks `ai_usage_logs` for a row with the same key written within `IDEMPOTENCY_WINDOW_SECONDS` (default 60). If found, returns that row's id instead of inserting a duplicate.
- 60s is long enough to absorb host-side accidental double-records (Dispatcher writing + a host that also calls UsageRecorder for the same turn) but short enough that two genuinely separate runs that happen to share a key don't get falsely deduped.
- `EloquentUsageRepository::IDEMPOTENCY_WINDOW_SECONDS` is `public const` so callers can read it (e.g. for cleanup window math) without depending on hardcoded literals.

**`UsageRecorder::record()` accepts and forwards `idempotency_key`**
- Threaded straight through to `UsageTracker → UsageRepository`. Hosts that want explicit dedup control pass their own key (e.g. internal job id, run UUID).

**`Dispatcher::dispatch()` auto-generates `idempotency_key` from `external_label`**
- New `Dispatcher::resolveIdempotencyKey()` picks the key with this precedence:
  1. Explicit `options['idempotency_key']` — caller wins. `false` opts out of auto-gen entirely.
  2. Auto-derived from `options['external_label']` when present: `"{backend}:{external_label}"` — stable across the duplicate dispatches that come from a host's accidental double-record, distinct across legitimately separate runs (each task has its own external_label).
  3. Otherwise null — no dedup, every record() inserts a row.
- Truncated to 80 chars to fit the column.
- This is the load-bearing safety net: hosts that haven't fully migrated to TaskRunner often call both `Dispatcher::dispatch()` AND their own `UsageRecorder::record()` for the same logical turn (PPT ClaudeStreamUsageParser is a known case). After Phase D those duplicate calls auto-collapse to one row without any host code change.

**`AiUsageLog` model: `idempotency_key` added to fillable + property docblock**

### Added — Phase C (AgentSpawn\Pipeline)

**`AgentSpawn\Pipeline` — three-phase spawn-plan orchestration**
- New service registered as a singleton in `SuperAICoreServiceProvider`. `Pipeline::maybeRun($backend, $outputDir, $firstPass, $options)` detects `_spawn_plan.json` in the output directory after a first-pass run, fans out N child CLI processes via the existing `AgentSpawn\Orchestrator`, then re-invokes the same backend with the consolidation prompt from `BackendCapabilities::consolidationPrompt()` and returns a merged `TaskResultEnvelope` with `spawnReport` populated.
- Lifts ~150 lines (`maybeRunSpawnPlan` + `runConsolidationPass`) that downstream hosts (SuperTeam, etc.) used to maintain themselves. Once a host upgrades and removes those methods, adding a new CLI that needs spawn-plan emulation requires zero host changes — only an upstream `BackendCapabilities` + `ChildRunner` implementation.
- Returns null when (a) the first pass failed, (b) no plan file was found, or (c) the backend opts out of the protocol (claude/kiro/copilot/superagent return `''` from `consolidationPrompt`). In each case `TaskRunner` keeps the first-pass envelope unchanged.
- Plan-file location: checks `$outputDir/_spawn_plan.json` first, then the cwd as fallback. Found-but-misplaced plans are moved to the canonical location before `SpawnPlan::fromFile()` reads them, so subsequent runs don't pick up a stale plan from cwd.
- Cost / duration merge: when the consolidation pass succeeds, `costUsd` / `shadowCostUsd` / `durationMs` accumulate first pass + consolidation. `summary` is the consolidation text alone (the user-facing answer); `output` is both passes joined by `\n--- consolidation ---\n`.
- Test seam: optional `$orchestratorFactory` constructor arg lets unit tests stub Phase 2 without spawning real CLI children. Production code defaults to `Orchestrator::forBackend()`.

**`Capabilities\SpawnConsolidationPrompt` — default Phase 3 prompt template**
- Lifted from SuperTeam's `runConsolidationPass()` so every downstream host produces identical `摘要.md` / `思维导图.md` / `流程图.md` trees regardless of which CLI ran. Used by `CodexCapabilities` + `GeminiCapabilities` `consolidationPrompt()` implementations. Hosts with different filename conventions should NOT extend this class — instead build their own consolidation prompt and feed it directly into `TaskRunner::run()` as a separate dispatch.

**`BackendCapabilities::spawnPreamble()` + `consolidationPrompt()`**
- Two new interface methods. `CodexCapabilities` + `GeminiCapabilities` return non-empty strings (the `PREAMBLE` constants `transformPrompt()` was already injecting + the consolidation template). `ClaudeCapabilities` (native sub-agents) / `KiroCapabilities` / `CopilotCapabilities` / `SuperAgentCapabilities` return `''` to opt out of the protocol.
- `transformPrompt()` is unchanged; the new method simply exposes the same preamble text for direct callers (Pipeline, host code that wants to render the preamble separately from the user prompt).
- **Note for hosts implementing custom `BackendCapabilities`:** this is technically an interface addition and will require those hosts to add the two methods. Returning `''` from both opts out of the protocol cleanly.

**`TaskRunner` activates Pipeline transparently when `spawn_plan_dir` is set**
- The Phase B no-op stub becomes load-bearing. Hosts that wired `spawn_plan_dir` pre-Phase-C automatically get the new behavior on upgrade.
- `TaskRunner::__construct` now accepts an optional `Pipeline` arg (second positional). Backward-compatible: omitting it makes `spawn_plan_dir` a no-op rather than throwing, so legacy callers keep working.

### Added — Phase B (TaskRunner)

**`Runner\TaskRunner` — one-call task execution wrapper around Dispatcher**
- New service registered as a singleton in `SuperAICoreServiceProvider`. `app(TaskRunner::class)->run($backend, $prompt, $options)` drives `Dispatcher::dispatch(['stream' => true, ...])`, normalizes the result into a typed `TaskResultEnvelope`, and offers two optional persistence hooks (`prompt_file`, `summary_file`) so hosts keep their on-disk debug breadcrumbs without writing the file plumbing themselves.
- Hosts that adopted Phase A's `stream:true` flag can now collapse their `executeTask()` / `executeClaude()` bodies (typically 100–200 lines of "build prompt file → spawn → tee log → extract summary → wrap into result array") to a single `$runner->run()` call. Sample migration in `docs/task-runner-quickstart.md`.
- Forwards every Dispatcher option transparently (model, system, provider_config, log_file, timeout, idle_timeout, mcp_mode, mcp_config_file, external_label, onChunk, task_type, capability, user_id, provider_id, metadata, scope, scope_id) and consumes only three runner-only keys: `prompt_file`, `summary_file`, `spawn_plan_dir`.
- `spawn_plan_dir` is wired today as a no-op forward-compat hook — Phase C ships `AgentSpawn\Pipeline` and TaskRunner will activate the fan-out + consolidation transparently. Hosts can pass the option now and pick up the behavior on upgrade with no call-site change.
- Conservative success semantics: `$envelope->success === true` requires `exit_code === 0` AND `text !== ''`. Phase A's `stream()` returns the envelope with `text=''` when the subprocess exited cleanly but the parser couldn't extract a final result event (malformed output, premature exit, model refused). Treating that as success would cause hosts to overwrite a TaskResult with a blank summary — TaskRunner conservatively fails so hosts can distinguish "the model spoke" from "the binary returned 0 but the output was unusable".

**`Runner\TaskResultEnvelope` — typed result shape**
- Public-readonly properties for `success` / `exitCode` / `output` / `summary` / `usage` / `costUsd` / `shadowCostUsd` / `billingModel` / `model` / `backend` / `durationMs` / `logFile` / `usageLogId` / `spawnReport` / `error`. Replaces the ad-hoc `['success', 'exit_code', 'output', ...]` arrays each downstream host invented.
- `::failed()` factory for the "Dispatcher couldn't even run the prompt" path (no provider configured, CLI not signed in, backend disabled, empty prompt).
- `toArray()` projection for hosts whose existing storage layer expects an array shape — eases incremental migration.

**`Dispatcher::dispatch()` now surfaces `usage_log_id` on the result**
- Captures the row id `UsageRecorder::record()` returns and stamps it on the dispatch envelope so downstream callers (notably `TaskRunner`) can attach the id to their own envelope without re-querying. Useful for "patch this row with extra metadata once Phase C consolidation finishes" flows and for skipping double-record on hosts that still call UsageRecorder themselves.
- Backward compatible: `usage_log_id` is omitted when no row was written (`UsageTracker` not bound, write failed, `AI_CORE_USAGE_TRACKING=false`). Hosts that don't read the key see no change.

### Added — Phase A (StreamingBackend)

**`Contracts\StreamingBackend` — sibling of `Backend`**
- New interface declaring `stream(array $options): ?array`. Same inputs as `generate()`, plus `log_file` / `timeout` / `idle_timeout` / `mcp_mode` / `mcp_config_file` / `external_label` / `onChunk` / `metadata`. Returns the same envelope `generate()` does, augmented with `log_file`, `duration_ms`, and `exit_code`.
- All five CLI backends implement it in this release: `ClaudeCliBackend` / `CodexCliBackend` / `GeminiCliBackend` / `KiroCliBackend` / `CopilotCliBackend`. The API backends (`AnthropicApiBackend`, `OpenAiApiBackend`, `GeminiApiBackend`) and `SuperAgentBackend` are deferred — they'd need SSE / SDK-internal streaming support that's out of scope for Phase A.

**`Support\TeeLogger` — append-only tee writer for streamed CLI output**
- Used by `stream()` implementations (and any future runner that wants the same "chunk fan-out") to persist the raw stream so the Process Monitor `tail` view, the post-hoc `CliOutputParser`, and the ad-hoc human reader all see the same authoritative bytes. Failure is non-fatal: unwritable paths silently skip disk writes rather than killing the run. `bytesWritten()` / `path()` / `isOpen()` helpers for observability.

**`Backends\Concerns\StreamableProcess` — shared register-tee-wait-end trait**
- Packages the `ProcessRegistrar::start() + TeeLogger + Process::wait(callback) + ProcessRegistrar::end()` dance so each backend's `stream()` body can stay focused on command construction + output parsing. Different from the long-standing `Runner\Concerns\MonitoredProcess` trait in two ways: no `emit()` requirement on the consumer (backends are silent by default; UI updates flow through `$onChunk` only when the caller passes one), and returns a richer envelope bundling captured output + log path + timing.

**`ClaudeCliBackend::parseStreamJson()` — NDJSON walker for `--output-format=stream-json` captures**
- Walks a captured stream-json log for the LAST `result` event and extracts `{text, model, input_tokens, output_tokens, cache_read_input_tokens, cache_creation_input_tokens, total_cost_usd, stop_reason, num_turns, session_id}`. Public for testing — host parsers that already capture the same NDJSON shape (PPT pipelines, task runners) can reuse this without spawning a process.

**`Dispatcher::dispatch([...'stream' => true])` — opt-in streaming route**
- When `options['stream'] === true` and the resolved backend implements `StreamingBackend`, `dispatch()` calls `stream()` instead of `generate()` and forwards `log_file` / `timeout` / `idle_timeout` / `mcp_mode` / `mcp_config_file` / `external_label` / `onChunk` through unchanged. Backends that don't implement the contract fall back to `generate()` silently — callers see the same envelope shape either way (stream-only adds `log_file` + `exit_code`).

**MCP injection knob — `mcp_mode: 'inherit' | 'empty' | 'file'` (ClaudeCliBackend only today)**
- `empty` writes a temp `{"mcpServers":{}}` and passes `--mcp-config <file> --strict-mcp-config` to claude — **required in headless mode when the host has many global MCPs**, otherwise claude keeps spawning them past its final stream event and blocks parent exit. `file` uses an explicit `mcp_config_file`. `inherit` (default) lets claude pick up its global MCP set as usual. Other backends accept the option but no-op today; forward-compat stub so hosts can pass it defensively.

### Changed

- `ClaudeCliBackend` / `CodexCliBackend` / `GeminiCliBackend` / `KiroCliBackend` / `CopilotCliBackend` all gained `implements StreamingBackend` + `use StreamableProcess;`. `generate()` signature / behavior unchanged — no breaking change for existing callers.
- `Dispatcher::dispatch()` now stamps `usage_log_id` on the result envelope when `UsageTracker` writes a row. Existing callers that don't read the key see no change.

### Migration notes

Hosts that currently hand-roll the spawn (build `claude -p --output-format stream-json --verbose ... > log.txt 2>&1`, manage `--mcp-config`, manage timeouts, manage tee, manage usage recording) can replace that entire block with:

- One `Dispatcher::dispatch(['stream' => true, ...])` call (Phase A primitive), OR
- One `app(TaskRunner::class)->run($backend, $prompt, $options)` call (Phase B convenience — recommended for task-execution code paths).

See `docs/streaming-backends.md` and `docs/task-runner-quickstart.md` for full quickstarts. Phase C (`AgentSpawn\Pipeline`) collapses spawn-plan handling further; Phases A + B are the load-bearing primitives.

**Hosts implementing custom `BackendCapabilities`:** the Phase C interface addition (`spawnPreamble` + `consolidationPrompt`) is technically a breaking change. Add `use \SuperAICore\Capabilities\Concerns\BackendCapabilitiesDefaults;` to inherit no-op defaults — the trait keeps the host class satisfying the interface today and through any future minor-release method additions. Bundled `*Capabilities` classes don't use the trait (they provide real implementations).

**Database migration:** run `php artisan migrate` to add the Phase D `idempotency_key` column + composite index. The other phases add no migrations.

### Tests

- 22 new tests in Phase A: `TeeLogger` basics (7), `ClaudeCliBackend::parseStreamJson()` edge cases (5), `StreamingBackend` contract enforcement across all 5 CLIs (10).
- 15 new tests in Phase B: `TaskResultEnvelope` shape (4), `TaskRunner` wrapping contract (11) — empty-prompt failure, dispatcher-null failure, envelope mapping, empty-text-treated-as-failure, prompt_file persistence, summary_file persistence (incl. skipped on empty text), runner-only options stripped, log_file fallback, backend fallback.
- 24 new tests in Phase C: `BackendCapabilities` spawn-protocol contract across all 6 impls (12), `Pipeline::maybeRun` decision tree (6) with stubbed Orchestrator (no real subprocesses), TaskRunner→Pipeline activation (4) — pipeline-absent no-op, pipeline-present activation, pipeline-null first-pass-kept, pipeline-not-called-when-spawn_plan_dir-omitted, pipeline-not-called-when-first-pass-failed.
- 10 new tests in Phase D: migration column present, no-key calls don't dedup, same-key-same-window dedups, distinct-keys-no-dedup, expired-window inserts new row, Dispatcher auto-key from external_label, no-label no-auto-key, explicit-key overrides auto-gen, `idempotency_key:false` opts out of auto-gen, key truncated to 80 chars.
- 2 new tests in Phase E: `BackendCapabilitiesDefaults` trait satisfies the interface, host can selectively override trait defaults per method.
- Full suite: **349 tests / 1034 assertions / 0 failures / 0 skipped** (was 276 / 812 at 0.6.5).

---

## [0.6.5] — 2026-04-21

Small patch tightening the 0.6.2 accounting story. Fixes a Kiro auth-detection bug that reported `not-logged-in` on machines with a valid `~/.kiro/` session, teaches `shadowCalculate()` about Anthropic's cache-token price tiers so heavy-cache Claude calls don't overstate shadow cost by ~10×, prefers the CLI's own `total_cost_usd` over the pricing catalog when the envelope carries it, and tidies the Recent-calls dashboard (Provider / Service column + capability column + filter-state persistence). Also adds an opt-in `MonitoredProcess::runMonitoredAndRecord()` helper so host runners can drop one line after a CLI exits and get a fully-populated `ai_usage_logs` row for free.

No breaking changes.

### Fixed

**Kiro auth: generic detector probed the wrong directory**
- `Services\CliStatusDetector::detectGenericCliAuth()` built the config-dir check from the literal binary name (`~/.kiro-cli/`), but Kiro — like most CLIs — writes its session into `~/.<engine>/` (`~/.kiro/`). Users who had already run `kiro-cli login` saw "not logged in" on `/super-ai-core/providers` anyway. The detector now strips a `-cli` / `_cli` suffix from the binary and probes both the stripped form and the literal, so `kiro-cli` → `~/.kiro/` resolves correctly without breaking engines whose config-dir matches their binary name verbatim. Also adds a `config_dir` field to the auth payload so UI can show *which* directory it found.

### Added

**Cache-aware shadow cost (cache_read 0.1×, cache_write 1.25×)**
- `Services\CostCalculator::shadowCalculate()` now accepts optional `$cacheReadTokens` / `$cacheWriteTokens` parameters and prices them separately from base input. Uses the catalog's explicit `cache_read_input` / `cache_creation_input` rates when present, otherwise applies the standard Anthropic multipliers against the `input` rate (cache reads ≈ 10% of input price, cache writes ≈ 125%). A PPT Strategist run with 500k cache_read + 70k cache_write tokens now reports a shadow cost that matches the Anthropic invoice for the same workload instead of inflating it ~10× by rolling all cache traffic into input.
- `Services\Dispatcher::dispatch()` forwards the cache token counts from `result.usage.cache_read_input_tokens` / `result.usage.cache_creation_input_tokens` (Anthropic wire format) straight into `shadowCalculate()`.
- `Services\UsageRecorder::record()` accepts `cache_read_tokens` / `cache_write_tokens` in the input payload, feeds them to the calculator, and tucks them into `metadata.cache_read_tokens` / `cache_write_tokens` for debugging. Host-side callers (SuperTeam's `ClaudeStreamUsageParser`, `ExecuteTask::recordTaskUsage`, `AiServiceDispatcher::recordUsage`) stop pre-summing cache tokens into `input_tokens` and pass them through these fields instead.

**Dispatcher prefers CLI-reported `total_cost_usd`**
- When the backend's `usage` envelope includes `total_cost_usd` (Claude CLI does, per its `result` event), Dispatcher now uses that figure as the billed cost instead of re-deriving from tokens × rate. Matters because Claude CLI is the only signal that knows whether a given session is billed against a subscription or an API key — the catalog can't infer that from the model id alone. For backends that don't report a billed cost (HTTP APIs mostly), the calculator-derived value still wins. Metadata now records `cost_source: 'cli_envelope' | 'calculator'` so operators can spot which rows came from which path.

**Usage dashboard: Provider / Service + Capability columns**
- Recent-calls table on `/super-ai-core/usage` now surfaces the friendly Provider name (or Service name when it's a service-routed call), plus a dedicated `capability` column. Previously operators had to cross-reference `provider_id` / `service_id` against DB rows to answer "which API key ran this?".
- Filter-state persistence: the `Hide 0-token rows` and `Hide test_connection` toggles were default-on but silently reverted to default on every form submit that un-ticked them (HTML checkboxes don't post when unchecked). A hidden `filters_applied=1` marker now rides along with the form, so an un-ticked box stays un-ticked across reloads.

**`Runner\Concerns\MonitoredProcess::runMonitoredAndRecord()` — opt-in usage recording for runner classes**
- Variant of the existing `runMonitored()` that buffers stdout in memory, parses it with `CliOutputParser` on exit, and writes an `ai_usage_logs` row through `UsageRecorder`. Host runners (anything using the `MonitoredProcess` trait) can drop one call at the bottom of their spawn path and stop hand-rolling parser + recorder glue:
  ```php
  $exitCode = $this->runMonitoredAndRecord(
      process:         $process,
      backend:         'claude_cli',
      commandSummary:  'claude -p "…" --output-format=stream-json',
      externalLabel:   "task:{$task->id}",
      engine:          'claude',  // drives CliOutputParser selection
      context:         [
          'task_type'  => 'ppt.strategist',
          'capability' => 'agent_spawn',
          'user_id'    => auth()->id(),
          'provider_id'=> $providerId,
          'metadata'   => ['ppt_job_id' => 42],
      ],
  );
  ```
- `runMonitored()` (plain-text variant) is untouched — opt-in by design so adopting recording doesn't silently alter the output format a host runner already depends on.
- Parser failures never propagate: if `CliOutputParser` can't match the engine's output shape (common for plain-text Codex / Copilot runs), no row is written and a `debug`-level log note is emitted instead of throwing. The CLI's actual exit code is always returned untouched.

### Changed

**Usage metadata: cost source + cache token counts**
- Every row written via `Dispatcher` or `UsageRecorder` now carries `metadata.cost_source` (`'cli_envelope'` / `'calculator'` / `'caller'`) and — when applicable — `metadata.cache_read_tokens` / `cache_write_tokens`. The dashboard doesn't surface these yet but they're available for drill-downs and invoice reconciliation.

---

## [0.6.2] — 2026-04-21

Patch release closing the "most dashboard rows are 0/0/0" gap on `/super-ai-core/usage` and `/super-ai-core/costs`. Previously every execution that the host app routed through its own runners (`App\Services\ClaudeRunner`, etc.) silently bypassed `ai_usage_logs`, and the few rows that did land there came from the `/providers` "Test connection" button with subscription-billed CLIs that returned `{input_tokens:0, output_tokens:0}` — making the dashboard look empty even during heavy use. This release adds a shadow-cost accounting path so subscription engines surface meaningful USD numbers, a clean `UsageRecorder` API so host runners can drop a one-liner at their call sites, and default dashboard filters that hide the noise.

Also fixes two bugs in the 0.6.1 Kiro integration: the `--model` flag was being dropped (so every call silently went through Kiro's `auto` router regardless of what the user selected) and the seeded model IDs used Claude-CLI dash separators (`claude-sonnet-4-6`) instead of Kiro's dot separators (`claude-sonnet-4.6`), which `kiro-cli` quietly rejects. The picker is now populated **live** from `kiro-cli chat --list-models` and surfaces the 7 non-Anthropic models Kiro supports (DeepSeek / MiniMax / GLM / Qwen) plus the `auto` router.

**Architectural change:** `Services\ProviderTypeRegistry` becomes the single source of truth for provider-type metadata (label / icon / fields / env-var name / allowed backends / needs_api_key). Host apps (notably SuperTeam) previously maintained a parallel `PROVIDER_TYPES` matrix and duplicated the env-injection switch in their own runners — those duplicates can now be replaced by single-line registry lookups. When SuperAICore adds a new API type in the future, host apps pick it up automatically without any code change, only a `composer update`.

No breaking changes. Existing `$dispatcher->dispatch()` callers continue to work; new columns on `ai_usage_logs` are nullable and backfill automatically on new writes.

### Added

**Shadow cost (`shadow_cost_usd`, `billing_model` on `ai_usage_logs`)**
- New migration `2026_04_21_000001_add_shadow_cost_to_ai_usage_logs.php` — adds `shadow_cost_usd decimal(12,6) nullable` and `billing_model varchar(20) nullable` after `cost_usd`. Run `php artisan migrate` on any host using the package.
- `Services\CostCalculator::shadowCalculate(string $model, int $inputTokens, int $outputTokens): float` — computes pay-as-you-go USD for the same tokens regardless of billing model, so a Copilot / Claude-Code-builtin session appears on the Cost Analytics dashboard with a meaningful number instead of a $0 row. Falls through to the SuperAgent `ModelCatalog` for models the host config doesn't enumerate. Returns 0 when the model id is unknown or tokens are zero.
- `Services\Dispatcher::dispatch()` — now stamps `cost_usd`, `shadow_cost_usd`, and `billing_model` onto both the returned result array and the `ai_usage_logs` row. Also forwards `metadata` from the options bag so callers can attach arbitrary context (job id, agent name, etc.) without a custom column.
- `Models\AiUsageLog` — fillable + casts extended for the two new columns.

**`Services\UsageRecorder` — façade for host-side runners**
- Thin wrapper on top of `UsageTracker` + `CostCalculator` that auto-fills `cost_usd`, `shadow_cost_usd`, and `billing_model` from the pricing catalog. Host apps that spawn CLIs directly (e.g. `App\Services\ClaudeRunner`, the PPT stage jobs, `ExecuteTask`) can now drop a single call after each turn:

  ```php
  app(\SuperAICore\Services\UsageRecorder::class)->record([
      'task_type'     => 'ppt.strategist',     // or 'tasks.run', 'ppt.executor', …
      'capability'    => 'agent_spawn',
      'backend'       => 'claude_cli',
      'model'         => 'claude-sonnet-4-5-20241022',
      'input_tokens'  => 12345,
      'output_tokens' => 6789,
      'duration_ms'   => 45000,
      'user_id'       => auth()->id(),
      'metadata'      => ['ppt_job_id' => 42],
  ]);
  ```

  Registered as a singleton in `SuperAICoreServiceProvider::register()`. No-ops when `AI_CORE_USAGE_TRACKING=false` (via the underlying `UsageTracker`).

**`Services\CliOutputParser` — reusable parsers for captured CLI output**
- Static delegates over the backend classes' existing parsers: `::parseClaude()`, `::parseCodex()`, `::parseCopilot()`, `::parseGemini()`. Return the `{text, model, input_tokens, output_tokens, …}` envelope or null when the output doesn't match. Host apps that already capture CLI stdout can extract tokens without constructing a full backend object.

**Dashboard improvements**
- `/super-ai-core/usage` — new "By Task Type" card, "Shadow cost" column on every breakdown table, a per-row billing-model badge (usage / sub), and two toggles above the filters: **Hide 0-token rows** (default on) and **Hide test_connection** (default on). Noise from the `/providers` test button is now filtered by default.
- `/super-ai-core/costs` — "Subscription engines" panel now shows an estimated shadow-cost total alongside call count and token totals, so operators can compare a Copilot session against a pay-as-you-go spend on the same scale. `test_connection` rows are excluded from all roll-ups.

**`Services\ProviderTypeRegistry` + `Support\ProviderTypeDescriptor` — provider-type single source of truth**
- Bundled descriptors for all 9 shipped types (`builtin` / `anthropic` / `anthropic-proxy` / `bedrock` / `vertex` / `google-ai` / `openai` / `openai-compatible` / `kiro-api`) each carrying: label_key, desc_key, icon, form fields[], default_backend, allowed_backends[], needs_api_key, needs_base_url, env_key, base_url_env, env_extras (extra_config → env var map for bedrock / vertex / google-ai), backend_env_flags (static flags like `CLAUDE_CODE_USE_BEDROCK=1` that fire only when routed through a specific backend).
- API: `all()` / `get($type)` / `forBackend($backend)` / `requiresApiKey($type)` / `requiresBaseUrl($type)`. Registered as a singleton in `SuperAICoreServiceProvider::register()`.
- Host-config overlay: new `super-ai-core.provider_types` config key accepts partial overrides (e.g. re-point a type's `label_key` to a host-owned lang namespace) OR brand-new types the bundle doesn't know about (e.g. a future `xai-api`). Merge order: config > bundled.
- `AiProvider::typesForBackend()` / `requiresApiKey()` / `requiresBaseUrl()` now delegate to the registry when the container is booted. `BACKEND_TYPES` constant preserved as a fallback for pre-boot / CLI contexts.
- `ProviderTypeDescriptor::toArray()` returns the exact legacy shape SuperTeam's Blade templates already iterate (`label_key`, `desc_key`, `icon`, `fields`, `backend`, `allowed_backends`), so host-side migration is a controller swap, not a view rewrite.

**`Services\ProviderEnvBuilder` — centralized env injection**
- Replaces the hardcoded env-var switch that every `*CliBackend::buildEnv()` and every host runner (e.g. SuperTeam's `ClaudeRunner::providerEnvVars()`) used to duplicate. Reads the descriptor's `envKey` / `baseUrlEnv` / `envExtras` / `backendEnvFlags` and produces the `{VAR => value}` map for `Process::setEnv()`.
- `buildEnv(AiProvider $provider, ?string $apiKeyEnvKey = null)` — drives from a persisted provider row.
- `buildEnvFromConfig(array $providerConfig)` — drives from the `provider_config` array Dispatcher-driven backends pass around. `KiroCliBackend::buildEnv()` is the first internal consumer (dropped the local `KIRO_API_KEY` literal).
- Registered as a singleton in `SuperAICoreServiceProvider::register()`.

**`Services\KiroModelResolver` — live model catalog from `kiro-cli`**
- Kiro is the only CLI in the matrix that exposes its authoritative model list programmatically (`kiro-cli chat --list-models --format json-pretty`), so this resolver does NOT carry a hardcoded catalog. Three-layer resolution: in-process memo → `~/.cache/superaicore/kiro-models.json` (24h TTL) → live CLI probe → 12-row static fallback (only when the binary is missing).
- `catalog()` / `families()` / `defaultFor($family)` / `resolve($id)` shape mirrors `ClaudeModelResolver` / `CopilotModelResolver` so any picker iterating a resolver uniformly keeps working.
- `parseListModels($json)` is exposed for testing; the rest is static. `refresh()` bypasses the TTL for on-demand updates.
- Surfaces the **full** Kiro roster in the picker — previously 0.6.1 showed only 4 Anthropic IDs, now users see all 12: `auto`, `claude-{opus,sonnet}-4.{5,6}`, `claude-sonnet-4`, `claude-haiku-4.5`, `deepseek-3.2`, `minimax-m2.5`, `minimax-m2.1`, `glm-5`, `qwen3-coder-next`. New Kiro models appear in the dropdown as soon as the CLI knows about them — no `composer update` required.

### Changed

**Test-connection buttons now tag themselves**
- `Http\Controllers\ProviderController::testBuiltin()`, `ProviderController::test()`, and `Http\Controllers\AiServiceController::testService()` now pass `task_type => 'test_connection'` plus a short `capability` and `metadata.origin` to the Dispatcher. Rows from the "Test" buttons are grouped and hidden from the dashboards by default instead of cluttering them.

**Billing model stamped at write time, not recomputed on read**
- `CostDashboardController` now prefers the row-stamped `billing_model` (set by the Dispatcher at record time) and falls back to `CostCalculator::billingModel()` only for pre-0.6.1 rows. This keeps historical accuracy when the catalog changes.

**Kiro model picker / pricing seed switched to Kiro's dot-separated slug vocabulary**
- `EngineCatalog::seed('kiro').available_models` — 4 dash-format entries (`claude-sonnet-4-6`, …) replaced with 12 dot-format entries matching what `kiro-cli chat --list-models` actually returns.
- `EngineCatalog::seed('kiro').default_model` — `claude-sonnet-4-6` → `claude-sonnet-4.6`.
- `EngineCatalog::resolverOptions('kiro')` — was reusing `ClaudeModelResolver` (wrong vocab, limited scope); now reads `KiroModelResolver::catalog()` + `::families()`.
- `EngineCatalog::expandFromCatalog()` — dropped the `'kiro' => 'anthropic'` / `'kiro' => 'claude-'` branches. SuperAgent `ModelCatalog` speaks dash-format Anthropic IDs, which don't match Kiro's dot-format at all, so that expansion only ever added noise.
- `config/super-ai-core.php` pricing rows — the five `kiro:claude-*-4-X` dash-slug keys (which never matched a real CLI call because Kiro never accepted dash-format) are replaced with twelve correct dot-slug keys, each annotated with Kiro's own credit `rate_multiplier` for operator reference.

**`CliStatusDetector::detectAuth()` — generic fallback for catalog-registered CLI engines**
- Adds a default branch that walks `ProviderTypeRegistry` looking for any configured type whose `env_key` is set in the child env, and checks for a `~/.<binary>/` config directory. Returns `{loggedIn, status, method, expires_at}` in the same shape as the Claude / Codex / Gemini / Copilot branches.
- Closes the 0.6.1 cosmetic gap where the `/providers` Kiro card showed "installed ✓" but left the auth line blank. Also future-proofs any new CLI engine added via `EngineCatalog::seed()` or host config — the new card gets a sensible auth readout without a code change.

### Fixed

**Kiro CLI silently ignored the `--model` selection (0.6.1 regression)**
- `Backends\KiroCliBackend::generate()` was reading `$options['model']` into a local variable but never passing it to the spawned `kiro-cli` process — every call went through Kiro's `auto` router regardless of what the user picked on `/providers` or supplied to `$dispatcher->dispatch(['model' => …])`. Now the argv includes `--model <id>` when a model is supplied, with `KiroModelResolver::resolve()` translating Claude-style dash IDs (e.g. `claude-sonnet-4-6`) into the dot-format Kiro requires (`claude-sonnet-4.6`) before handing off.
- Docstring also corrected — 0.6.1's comment claimed "Model selection is NOT a CLI flag in headless mode", which contradicted Kiro 2.x's actual `kiro-cli chat --help` output.

### Migration notes

1. Run `php artisan migrate` on every host (adds two nullable columns — safe, non-destructive).
2. Existing rows get `NULL` for both new columns; the dashboards render `—` for them. Subscription rows previously written with `cost_usd=0` will only surface shadow cost going forward; backfill is not attempted.
3. Host apps that already wire their own runners and want real-execution tracking: call `app(UsageRecorder::class)->record()` from each CLI completion path. See the snippet above. No API breakage for hosts that do nothing.
4. Hosts that published `config/super-ai-core.php` and hand-edited the `kiro:*` pricing rows: the published file still carries the dash-slug keys from 0.6.1. Re-publish with `php artisan vendor:publish --tag=super-ai-core-config --force` (or hand-migrate: rename `kiro:claude-sonnet-4-6` → `kiro:claude-sonnet-4.6`, add the 7 new rows). The old dash-slug rows were never matched by any real CLI call and can be deleted safely.
5. If `kiro-cli` is installed on hosts but the Kiro dropdown looks stale, force a catalog refresh with `php -r 'SuperAICore\Services\KiroModelResolver::refresh();'` (or just delete `~/.cache/superaicore/kiro-models.json` — the next request will reprobe).

### Known gaps (follow-up)

- `Runner\ClaudeAgentRunner`, `CodexAgentRunner`, `GeminiAgentRunner`, `CopilotAgentRunner` still emit plain-text streams and do not auto-record usage — adopting stream-json output would change the user-visible output format, so opt-in wiring is deferred to 0.7. Hosts that want tracking today should adopt `UsageRecorder` at their own CLI call sites.
- No backfill of the handful of "Test connection" rows with `task_type=NULL` from before 0.6.1 — they'll disappear as soon as the "Hide 0-token rows" default filter is applied, but you can also clear them manually: `DELETE FROM ai_usage_logs WHERE task_type IS NULL AND input_tokens=0 AND output_tokens=0;`
- Claude / Codex / Gemini / Copilot CLIs do **not** expose a list-models subcommand (only Kiro does), so their pickers stay on the SuperAgent `ModelCatalog` fallback — already dynamic via `superaicore super-ai-core:models update`, but not live-probed on every invocation the way Kiro's is. Adding provider-API probing (Anthropic `/v1/models`, OpenAI `/v1/models`, Google `v1/models:list`) is deferred to 0.7.

### Tests

- 28 new tests, 103 new assertions across the release:
  - 10 for `KiroModelResolver` (JSON parse, dash→dot, family aliases, static fallback, malformed input)
  - 8 for `ProviderTypeRegistry` (bundled 9 types present, forBackend filter, kiro-api shape, bedrock env-extras / backend flag, host-config rebrand, host-config added type, legacy toArray shape)
  - 10 for `ProviderEnvBuilder` (each of the 9 bundled types produces expected env map, host-added type, missing api_key, unknown type)
- Full suite: **276 tests / 812 assertions / 0 failures / 0 skipped** (was 248 / 709 at 0.6.1). Live-probe smoke verified against `kiro-cli` 2.x on macOS: all 12 models round-trip, cache file written to `~/.cache/superaicore/kiro-models.json`, dash→dot resolver translations line up with Kiro's router accept list.

### Host-app migration (SuperTeam & similar)

Host apps that previously duplicated a `PROVIDER_TYPES` matrix, a `providerEnvVars()` switch, or a hardcoded backend→label table can now replace those with single-line registry queries. After the migration, any future API type SuperAICore adds surfaces in the host UI via `composer update` with no code change. Typical replacements:

```php
// BEFORE (host-side duplicated matrix)
const PROVIDER_TYPES = [
    AiProvider::TYPE_ANTHROPIC => ['backend' => …, 'icon' => …, 'fields' => …],
    AiProvider::TYPE_OPENAI    => ['backend' => …, 'icon' => …, 'fields' => …],
    // … grew every time SuperAICore added a type
];

// AFTER
$types = app(\SuperAICore\Services\ProviderTypeRegistry::class)->all();
// optionally filter: ->forBackend($backend)
// each entry is a ProviderTypeDescriptor; ->toArray() gives the legacy shape
```

```php
// BEFORE (host-side env switch)
switch ($provider->type) {
    case AiProvider::TYPE_ANTHROPIC:  $env['ANTHROPIC_API_KEY'] = $apiKey; break;
    case AiProvider::TYPE_BEDROCK:    $env['AWS_ACCESS_KEY_ID'] = …; …
    // … 7 cases, missing TYPE_KIRO_API
}

// AFTER
$env = app(\SuperAICore\Services\ProviderEnvBuilder::class)->buildEnv($provider);
```

Hosts can override individual descriptors via `config/super-ai-core.php`'s `provider_types` key — handy to point `label_key` at the host's own lang namespace without restating the rest of the descriptor.

---

## [0.6.1] — 2026-04-20

Adds **AWS Kiro CLI** (`kiro-cli` ≥ 2.0) as the sixth execution engine. Kiro joins the matrix with the richest out-of-the-box feature set of any CLI backend — native **agents**, **skills**, **MCP**, **subagent DAG orchestration**, **and** two auth channels (local `kiro-cli login` and `KIRO_API_KEY` headless mode). Subagents are native (no `SpawnPlan` emulation needed), skills read the Claude `SKILL.md` format verbatim, and MCP config lives at `~/.kiro/settings/mcp.json` with the same `mcpServers` schema plus Kiro-specific extensions (`disabled`, `autoApprove`, `disabledTools`, remote `url`/`headers`).

Subscription engine (Kiro Pro / Pro+ / Power credit plans), so costs route into the dashboard's subscription bucket the same way Copilot does — per-token USD stays at 0 and the CLI backend surfaces per-call `credits` + `duration_s` under `usage` for hosts that want to render credit dashboards.

All additive — no breaking changes. Existing installs that don't have `kiro-cli` on `$PATH` see it report as unavailable in `cli:status` / `list-backends` and continue to use the other five engines unchanged.

### Added

**Kiro CLI execution engine**
- `Backends\KiroCliBackend` — spawns `kiro-cli chat --no-interactive --trust-all-tools <prompt>`, parses the plain-text response body, and extracts the trailing `▸ Credits: X • Time: Y` summary line into `usage.credits` / `usage.duration_s`. Supports both auth channels: `type=builtin` leaves env untouched so the host's `kiro-cli login` keychain state carries the request, `type=kiro-api` injects the stored key as `KIRO_API_KEY` which makes `kiro-cli` skip its browser login flow.
- `Capabilities\KiroCapabilities` — `supportsSubAgents()=true` (Kiro's native DAG planner runs the orchestration; no `SpawnPlan` emulation needed), MCP path `~/.kiro/settings/mcp.json`, tool-name map for the lowercase Kiro vocabulary (`Read`→`read`, `Grep`→`grep`, `Bash`→`bash`, …). `renderMcpConfig()` writes the same `mcpServers` key Claude uses **plus** preserves `disabled` / `autoApprove` / `disabledTools` on entries the user added, and supports remote servers via `url` / `headers`.
- `Runner\KiroAgentRunner` — `kiro-cli chat --no-interactive --trust-all-tools --agent <name> <task>`. Auto-syncs the agent JSON before spawn.
- `Runner\KiroSkillRunner` — sends the SKILL.md body verbatim to `kiro-cli chat --no-interactive`. Kiro reads Claude's skill frontmatter shape natively, so no translator preamble is injected.
- `Sync\KiroAgentWriter` — translates `.claude/agents/*.md` → `~/.kiro/agents/<name>.json`. Field mapping: body→`prompt`, `model`→`model` (Anthropic slugs pass through unchanged), `allowed-tools` → lowercased `tools` + `allowedTools`. Reuses `AbstractManifestWriter` so user-edited JSONs are preserved (STATUS_USER_EDITED) and removed source agents are cleaned up (STATUS_REMOVED).
- `Console\Commands\KiroSyncCommand` — `kiro:sync [--dry-run] [--kiro-home <dir>]` prints the +/- change table and writes `~/.kiro/agents/<name>.json` files. Mostly a manual preview — `agent:run --backend=kiro` auto-syncs the targeted agent.
- Registered in `EngineCatalog::seed()` with `billing_model=subscription`, `cli_binary=kiro-cli`, `dispatcher_backends=['kiro_cli']`, and a `ProcessSpec` that pins the `chat --no-interactive --trust-all-tools` prefix so the default `CliProcessBuilderRegistry` builder produces the right argv. Wired into `BackendRegistry`, `CapabilityRegistry`, `BackendState::DISPATCHER_TO_ENGINE`, `McpManager::syncAllBackends()`, and the `AgentRunCommand` / `SkillRunCommand` runner factories.

**Kiro provider type (`kiro-api`)**
- `Models\AiProvider::TYPE_KIRO_API` + `BACKEND_KIRO` constants; `BACKEND_TYPES[kiro] = [builtin, kiro-api]`. `requiresApiKey()` treats `kiro-api` like `openai` / `anthropic` so the provider form prompts for a key. `TYPE_BUILTIN` remains the "host has already run `kiro-cli login`" path with no env injection.

**Kiro model picker flows through ModelCatalog**
- `EngineCatalog::expandFromCatalog()` maps `kiro → anthropic` with a `claude-` prefix filter, so the same SuperAgent `ModelCatalog` refresh that updates Claude / Codex / Gemini also surfaces new Anthropic model IDs in the Kiro dropdown.
- `EngineCatalog::resolverOptions('kiro')` reuses `ClaudeModelResolver::families()` + `::catalog()` for identical slugs (family aliases `sonnet` / `opus` / `haiku` ship alongside full IDs) and appends Kiro's routing primitive `auto` ("Auto (Kiro router picks the cheapest model)").

**MCP sync reaches the sixth engine**
- `McpManager::syncAllBackends()` picks up `kiro` automatically through the `EngineCatalog::keys()` → `supportsMcp()` filter; the hardcoded fallback list (used only when the container isn't booted) adds `kiro` for parity.

**Pricing entries**
- `config/super-ai-core.php` — five `kiro:<model>` subscription rows (`claude-sonnet-4-6`, `claude-sonnet-4-5`, `claude-opus-4-6`, `claude-haiku-4-5`, `auto`) with `input=0 / output=0 / billing_model=subscription`. Core cost totals stay at $0 per-call; host apps that want a credit dashboard read `usage.credits` off the dispatcher response.

### Changed

- `AgentRunCommand` / `SkillRunCommand` — `--backend` option docstring now lists `claude|codex|gemini|copilot|kiro|superagent`. Runner factory gains a `kiro` branch for both commands.
- `BackendRegistry` — new `kiro_cli` config section (binary / timeout / trust-all-tools); defaults to enabled so fresh installs without `kiro-cli` on `$PATH` see `isAvailable()=false` and skip the engine.
- `Console\Application` registers `kiro:sync` alongside `gemini:sync` / `copilot:sync` / `copilot:sync-hooks`.

### Tests

- 5 new tests: 4 × `KiroCliBackend::parseOutput()` (UTF-8 `▸` bullet, ASCII `>` fallback, missing summary line, empty input), 1 × `EngineCatalog::modelOptions('kiro')` (Claude resolver reuse + `auto` pseudo-model).
- Harness updates: `BackendRegistryTest` config fixtures include `kiro_cli` in both the "register all" and "disable all except anthropic_api" scenarios.
- Full suite: **248 tests / 709 assertions / 0 failures / 0 skipped** (was 243 / 690 at 0.6.0).

### Environment reference

```env
# Kiro CLI backend (0.6.1+) — disable if you don't want superaicore to
# probe for the binary at all. All defaults are safe; leaving untouched is
# fine when kiro-cli isn't installed.
AI_CORE_KIRO_CLI_ENABLED=true
KIRO_CLI_BIN=kiro-cli
# Kiro's --no-interactive mode refuses to run tools without prior per-tool
# approval unless this is on. Flip false only for workflows that
# pre-populate approvals via `--trust-tools=<categories>`.
AI_CORE_KIRO_TRUST_ALL_TOOLS=true

# Kiro API-key auth (headless, Pro / Pro+ / Power subscribers). Setting
# KIRO_API_KEY makes kiro-cli skip its browser login flow. Stored per
# provider in the DB via type=kiro-api; this env var is only needed when
# the CLI is invoked outside superaicore's dispatcher.
# KIRO_API_KEY=ksk_...
```

```bash
# Drive Kiro from the CLI
./vendor/bin/superaicore call "Hello" --backend=kiro_cli
./vendor/bin/superaicore agent:run reviewer "audit this diff" --backend=kiro
./vendor/bin/superaicore skill:run simplify --backend=kiro --exec=native

# Preview agent JSON that would land in ~/.kiro/agents/
./vendor/bin/superaicore kiro:sync --dry-run
```

---

## [0.6.0] — 2026-04-19

Minor-version bump because the **SuperAgent `ModelCatalog` (0.8.7)** now flows into every place SuperAICore used to hand-maintain model metadata: `CostCalculator` pricing, `ModelResolver` alias lookup, `EngineCatalog::modelOptions()` dropdown bodies, and the new `super-ai-core:models` CLI. Host apps running `superagent models update` immediately see updated pricing and new model rows without a `composer update` or `vendor:publish`. Also: Gemini CLI OAuth state lands on the `/providers` card, the model-picker placeholder is translated for en/zh-CN/fr, and `CliStatusDetector` picks up host-registered CLI engines automatically.

All additive — no breaking changes. Host apps that already publish `model_pricing` or `super-ai-core.engines.<key>.available_models` keep their authoritative values; the catalog fallback only fires when the host hasn't opined.

### Added

**SuperAgent `ModelCatalog` integrated as a pricing fallback**
- `Services\CostCalculator::resolveRate()` — new 4th step after config lookup + longest-prefix match falls through to `\SuperAgent\Providers\ModelCatalog::pricing($model)`. The bundled SuperAgent catalog covers every current Anthropic / OpenAI / Gemini / OpenRouter / Bedrock row, including entries SuperAICore's `model_pricing` config didn't enumerate (`claude-opus-4-6-20250514`, `claude-sonnet-4-7`, `gpt-5-nano`, `gemini-1.5-*`, etc.). Config still wins when set — defence-in-depth for hosts that publish their own rates.
- `Services\ClaudeModelResolver::resolve()` / `Services\GeminiModelResolver::resolve()` — after the local `FAMILIES` / `ALIASES` table misses, consult `ModelCatalog::resolveAlias()` with a provider-prefix guard (`claude-` / `gemini`) so Gemini's resolver can never return a Claude id and vice versa. Adds aliases like `gemini` → `gemini-2.0-flash`, `claude-opus` → latest Opus without editing the resolver.
- `Services\EngineCatalog` — seed's `available_models` is now unioned with `ModelCatalog::modelsFor(<provider>)` entries for claude / gemini / codex. Seed order is preserved; catalog-only ids get appended. Copilot stays on its dot-ID list; hosts that publish `super-ai-core.engines.<key>.available_models` override the union entirely.

**`super-ai-core:models` CLI (`Console\Commands\ModelsCommand.php`)**
- `list [--provider <p>]` — prints the merged (bundled + user override) catalog with per-1M pricing and aliases.
- `update [--url <u>]` — fetches the remote catalog to `~/.superagent/models.json` atomically. Honours `SUPERAGENT_MODELS_URL` by default.
- `status` — shows source provenance + override mtime + staleness + total rows loaded.
- `reset [-y]` — deletes the user override with a confirmation prompt (skip via `-y`).
- Exposed via the standalone `bin/superaicore` entry point. Registered in `Console\Application` alongside `cli:status` / `cli:install`.

**Opt-in catalog auto-refresh at CLI startup**
- `bin/superaicore` — invokes `ModelCatalog::maybeAutoUpdate()` before constructing the application. No-op unless `SUPERAGENT_MODELS_AUTO_UPDATE=1` AND `SUPERAGENT_MODELS_URL` is set AND the user override is older than 7 days. Network failures are swallowed so a dead remote never blocks the CLI.

**Gemini CLI OAuth detection**
- `Services\CliStatusDetector::detectAuth('gemini', ...)` — new branch reads `~/.gemini/oauth_creds.json` / `credentials.json` / `settings.json` via `\SuperAgent\Auth\GeminiCliCredentials`, falls back to `GEMINI_API_KEY` / `GOOGLE_API_KEY` env vars, and reports `{loggedIn, status, method, expires_at}` the same shape the claude/codex branches return. The `/providers` Gemini card now shows "logged in (oauth)" instead of "?" when the user ran `gemini login`.

### Changed

**Model-picker placeholder is translated**
- `Services\EngineCatalog::modelOptions()` — signature changed from `string $placeholder = '— 继承默认 —'` to `?string $placeholder = null`. When null (default) the method pulls `trans('super-ai-core::messages.inherit_default')`, falling back to the English literal `(inherit default)` when no Laravel translator is registered (e.g. plain PHPUnit). en/zh-CN/fr message files already carried the key; the hardcoded CN literal was the only blocker for EN/FR UIs.

**`CliStatusDetector` picks up host-registered CLI engines**
- `all()` iterates `EngineCatalog::keys()` instead of a hardcoded list, so any engine a host app registered via `super-ai-core.engines` config with `is_cli: true` + `cli_binary: <name>` surfaces in `cli:status` and the `/providers` cards. Built-in engines still hit `detectBinary()` directly for a fast path; catalog engines are resolved through the registered descriptor.
- `detect(<backend>)` accepts any backend key that the catalog knows; unknown backends fall through to a `['installed' => false]` stub instead of silently being dropped.

**`BackendRegistry` constructor accepts a testable SDK-availability callable**
- New optional third param `?callable $superagentAvailable = null` lets tests inject `fn() => false` to exercise the "SuperAgent SDK absent" branch without having to uninstall the package. Defaults to `[SuperAgentDetector::class, 'isAvailable']` so production callers see no behaviour change.

### Fixed

**Previously-unreachable SDK-missing test now runs**
- `tests\Unit\BackendRegistryTest::test_superagent_is_hidden_when_sdk_missing_even_with_config_enabled` used to call `markTestSkipped()` on every run because `composer.json` requires `forgeomni/superagent` as a hard dep — `class_exists(\SuperAgent\Agent::class)` is always true. The test now uses the injectable availability callable, asserts the negative path, and a matching `test_superagent_registered_when_sdk_available_and_enabled` covers the positive path. Skip count drops from 1 to 0.

### Tests
- 18 new tests: 3 × `CostCalculator` (catalog fallback, config-wins, no-match-returns-zero), 2 × `GeminiModelResolver` (catalog alias resolution, cross-provider isolation), 4 × `ModelsCommand` (list / filter / status / unknown-action), 3 × `CliStatusDetectorGeminiAuth` (oauth file / env key / not-logged-in), 5 × `EngineCatalog` (placeholder fallback, explicit placeholder, claude + gemini catalog expansion, host override wins, copilot untainted), 1 × `BackendRegistry` (SDK-present positive path). The pre-existing `test_superagent_is_hidden_when_sdk_missing...` case now actually executes.
- Full suite: **243 tests / 690 assertions / 0 failures / 0 skipped** (was 225 / 634 / 1 skipped at 0.5.9).

### Environment reference

```env
# Opt-in catalog auto-refresh at CLI startup (both must be set)
SUPERAGENT_MODELS_URL=https://your-cdn/models.json
SUPERAGENT_MODELS_AUTO_UPDATE=1
```

```bash
# Inspect or refresh the model catalog
./vendor/bin/superaicore super-ai-core:models status
./vendor/bin/superaicore super-ai-core:models list --provider=anthropic
./vendor/bin/superaicore super-ai-core:models update                 # from $SUPERAGENT_MODELS_URL
./vendor/bin/superaicore super-ai-core:models update --url https://…
./vendor/bin/superaicore super-ai-core:models reset                  # delete user override
```
t## [0.6.1] — 2026-04-20

Adds **AWS Kiro CLI** (`kiro-cli` ≥ 2.0) as the sixth execution engine. Kiro joins the matrix with the richest out-of-the-box feature set of any CLI backend — native **agents**, **skills**, **MCP**, **subagent DAG orchestration**, **and** two auth channels (local `kiro-cli login` and `KIRO_API_KEY` headless mode). Subagents are native (no `SpawnPlan` emulation needed), skills read the Claude `SKILL.md` format verbatim, and MCP config lives at `~/.kiro/settings/mcp.json` with the same `mcpServers` schema plus Kiro-specific extensions (`disabled`, `autoApprove`, `disabledTools`, remote `url`/`headers`).

Subscription engine (Kiro Pro / Pro+ / Power credit plans), so costs route into the dashboard's subscription bucket the same way Copilot does — per-token USD stays at 0 and the CLI backend surfaces per-call `credits` + `duration_s` under `usage` for hosts that want to render credit dashboards.

All additive — no breaking changes. Existing installs that don't have `kiro-cli` on `$PATH` see it report as unavailable in `cli:status` / `list-backends` and continue to use the other five engines unchanged.

### Added

**Kiro CLI execution engine**
- `Backends\KiroCliBackend` — spawns `kiro-cli chat --no-interactive --trust-all-tools <prompt>`, parses the plain-text response body, and extracts the trailing `▸ Credits: X • Time: Y` summary line into `usage.credits` / `usage.duration_s`. Supports both auth channels: `type=builtin` leaves env untouched so the host's `kiro-cli login` keychain state carries the request, `type=kiro-api` injects the stored key as `KIRO_API_KEY` which makes `kiro-cli` skip its browser login flow.
- `Capabilities\KiroCapabilities` — `supportsSubAgents()=true` (Kiro's native DAG planner runs the orchestration; no `SpawnPlan` emulation needed), MCP path `~/.kiro/settings/mcp.json`, tool-name map for the lowercase Kiro vocabulary (`Read`→`read`, `Grep`→`grep`, `Bash`→`bash`, …). `renderMcpConfig()` writes the same `mcpServers` key Claude uses **plus** preserves `disabled` / `autoApprove` / `disabledTools` on entries the user added, and supports remote servers via `url` / `headers`.
- `Runner\KiroAgentRunner` — `kiro-cli chat --no-interactive --trust-all-tools --agent <name> <task>`. Auto-syncs the agent JSON before spawn.
- `Runner\KiroSkillRunner` — sends the SKILL.md body verbatim to `kiro-cli chat --no-interactive`. Kiro reads Claude's skill frontmatter shape natively, so no translator preamble is injected.
- `Sync\KiroAgentWriter` — translates `.claude/agents/*.md` → `~/.kiro/agents/<name>.json`. Field mapping: body→`prompt`, `model`→`model` (Anthropic slugs pass through unchanged), `allowed-tools` → lowercased `tools` + `allowedTools`. Reuses `AbstractManifestWriter` so user-edited JSONs are preserved (STATUS_USER_EDITED) and removed source agents are cleaned up (STATUS_REMOVED).
- `Console\Commands\KiroSyncCommand` — `kiro:sync [--dry-run] [--kiro-home <dir>]` prints the +/- change table and writes `~/.kiro/agents/<name>.json` files. Mostly a manual preview — `agent:run --backend=kiro` auto-syncs the targeted agent.
- Registered in `EngineCatalog::seed()` with `billing_model=subscription`, `cli_binary=kiro-cli`, `dispatcher_backends=['kiro_cli']`, and a `ProcessSpec` that pins the `chat --no-interactive --trust-all-tools` prefix so the default `CliProcessBuilderRegistry` builder produces the right argv. Wired into `BackendRegistry`, `CapabilityRegistry`, `BackendState::DISPATCHER_TO_ENGINE`, `McpManager::syncAllBackends()`, and the `AgentRunCommand` / `SkillRunCommand` runner factories.

**Kiro provider type (`kiro-api`)**
- `Models\AiProvider::TYPE_KIRO_API` + `BACKEND_KIRO` constants; `BACKEND_TYPES[kiro] = [builtin, kiro-api]`. `requiresApiKey()` treats `kiro-api` like `openai` / `anthropic` so the provider form prompts for a key. `TYPE_BUILTIN` remains the "host has already run `kiro-cli login`" path with no env injection.

**Kiro model picker flows through ModelCatalog**
- `EngineCatalog::expandFromCatalog()` maps `kiro → anthropic` with a `claude-` prefix filter, so the same SuperAgent `ModelCatalog` refresh that updates Claude / Codex / Gemini also surfaces new Anthropic model IDs in the Kiro dropdown.
- `EngineCatalog::resolverOptions('kiro')` reuses `ClaudeModelResolver::families()` + `::catalog()` for identical slugs (family aliases `sonnet` / `opus` / `haiku` ship alongside full IDs) and appends Kiro's routing primitive `auto` ("Auto (Kiro router picks the cheapest model)").

**MCP sync reaches the sixth engine**
- `McpManager::syncAllBackends()` picks up `kiro` automatically through the `EngineCatalog::keys()` → `supportsMcp()` filter; the hardcoded fallback list (used only when the container isn't booted) adds `kiro` for parity.

**Pricing entries**
- `config/super-ai-core.php` — five `kiro:<model>` subscription rows (`claude-sonnet-4-6`, `claude-sonnet-4-5`, `claude-opus-4-6`, `claude-haiku-4-5`, `auto`) with `input=0 / output=0 / billing_model=subscription`. Core cost totals stay at $0 per-call; host apps that want a credit dashboard read `usage.credits` off the dispatcher response.

### Changed

- `AgentRunCommand` / `SkillRunCommand` — `--backend` option docstring now lists `claude|codex|gemini|copilot|kiro|superagent`. Runner factory gains a `kiro` branch for both commands.
- `BackendRegistry` — new `kiro_cli` config section (binary / timeout / trust-all-tools); defaults to enabled so fresh installs without `kiro-cli` on `$PATH` see `isAvailable()=false` and skip the engine.
- `Console\Application` registers `kiro:sync` alongside `gemini:sync` / `copilot:sync` / `copilot:sync-hooks`.

### Tests

- 5 new tests: 4 × `KiroCliBackend::parseOutput()` (UTF-8 `▸` bullet, ASCII `>` fallback, missing summary line, empty input), 1 × `EngineCatalog::modelOptions('kiro')` (Claude resolver reuse + `auto` pseudo-model).
- Harness updates: `BackendRegistryTest` config fixtures include `kiro_cli` in both the "register all" and "disable all except anthropic_api" scenarios.
- Full suite: **248 tests / 709 assertions / 0 failures / 0 skipped** (was 243 / 690 at 0.6.0).

### Environment reference

```env
# Kiro CLI backend (0.6.1+) — disable if you don't want superaicore to
# probe for the binary at all. All defaults are safe; leaving untouched is
# fine when kiro-cli isn't installed.
AI_CORE_KIRO_CLI_ENABLED=true
KIRO_CLI_BIN=kiro-cli
# Kiro's --no-interactive mode refuses to run tools without prior per-tool
# approval unless this is on. Flip false only for workflows that
# pre-populate approvals via `--trust-tools=<categories>`.
AI_CORE_KIRO_TRUST_ALL_TOOLS=true

# Kiro API-key auth (headless, Pro / Pro+ / Power subscribers). Setting
# KIRO_API_KEY makes kiro-cli skip its browser login flow. Stored per
# provider in the DB via type=kiro-api; this env var is only needed when
# the CLI is invoked outside superaicore's dispatcher.
# KIRO_API_KEY=ksk_...
```

```bash
# Drive Kiro from the CLI
./vendor/bin/superaicore call "Hello" --backend=kiro_cli
./vendor/bin/superaicore agent:run reviewer "audit this diff" --backend=kiro
./vendor/bin/superaicore skill:run simplify --backend=kiro --exec=native

# Preview agent JSON that would land in ~/.kiro/agents/
./vendor/bin/superaicore kiro:sync --dry-run
```


## [0.6.0] — 2026-04-19

Minor-version bump because the **SuperAgent `ModelCatalog` (0.8.7)** now flows into every place SuperAICore used to hand-maintain model metadata: `CostCalculator` pricing, `ModelResolver` alias lookup, `EngineCatalog::modelOptions()` dropdown bodies, and the new `super-ai-core:models` CLI. Host apps running `superagent models update` immediately see updated pricing and new model rows without a `composer update` or `vendor:publish`. Also: Gemini CLI OAuth state lands on the `/providers` card, the model-picker placeholder is translated for en/zh-CN/fr, and `CliStatusDetector` picks up host-registered CLI engines automatically.

All additive — no breaking changes. Host apps that already publish `model_pricing` or `super-ai-core.engines.<key>.available_models` keep their authoritative values; the catalog fallback only fires when the host hasn't opined.

### Added

**SuperAgent `ModelCatalog` integrated as a pricing fallback**
- `Services\CostCalculator::resolveRate()` — new 4th step after config lookup + longest-prefix match falls through to `\SuperAgent\Providers\ModelCatalog::pricing($model)`. The bundled SuperAgent catalog covers every current Anthropic / OpenAI / Gemini / OpenRouter / Bedrock row, including entries SuperAICore's `model_pricing` config didn't enumerate (`claude-opus-4-6-20250514`, `claude-sonnet-4-7`, `gpt-5-nano`, `gemini-1.5-*`, etc.). Config still wins when set — defence-in-depth for hosts that publish their own rates.
- `Services\ClaudeModelResolver::resolve()` / `Services\GeminiModelResolver::resolve()` — after the local `FAMILIES` / `ALIASES` table misses, consult `ModelCatalog::resolveAlias()` with a provider-prefix guard (`claude-` / `gemini`) so Gemini's resolver can never return a Claude id and vice versa. Adds aliases like `gemini` → `gemini-2.0-flash`, `claude-opus` → latest Opus without editing the resolver.
- `Services\EngineCatalog` — seed's `available_models` is now unioned with `ModelCatalog::modelsFor(<provider>)` entries for claude / gemini / codex. Seed order is preserved; catalog-only ids get appended. Copilot stays on its dot-ID list; hosts that publish `super-ai-core.engines.<key>.available_models` override the union entirely.

**`super-ai-core:models` CLI (`Console\Commands\ModelsCommand.php`)**
- `list [--provider <p>]` — prints the merged (bundled + user override) catalog with per-1M pricing and aliases.
- `update [--url <u>]` — fetches the remote catalog to `~/.superagent/models.json` atomically. Honours `SUPERAGENT_MODELS_URL` by default.
- `status` — shows source provenance + override mtime + staleness + total rows loaded.
- `reset [-y]` — deletes the user override with a confirmation prompt (skip via `-y`).
- Exposed via the standalone `bin/superaicore` entry point. Registered in `Console\Application` alongside `cli:status` / `cli:install`.

**Opt-in catalog auto-refresh at CLI startup**
- `bin/superaicore` — invokes `ModelCatalog::maybeAutoUpdate()` before constructing the application. No-op unless `SUPERAGENT_MODELS_AUTO_UPDATE=1` AND `SUPERAGENT_MODELS_URL` is set AND the user override is older than 7 days. Network failures are swallowed so a dead remote never blocks the CLI.

**Gemini CLI OAuth detection**
- `Services\CliStatusDetector::detectAuth('gemini', ...)` — new branch reads `~/.gemini/oauth_creds.json` / `credentials.json` / `settings.json` via `\SuperAgent\Auth\GeminiCliCredentials`, falls back to `GEMINI_API_KEY` / `GOOGLE_API_KEY` env vars, and reports `{loggedIn, status, method, expires_at}` the same shape the claude/codex branches return. The `/providers` Gemini card now shows "logged in (oauth)" instead of "?" when the user ran `gemini login`.

### Changed

**Model-picker placeholder is translated**
- `Services\EngineCatalog::modelOptions()` — signature changed from `string $placeholder = '— 继承默认 —'` to `?string $placeholder = null`. When null (default) the method pulls `trans('super-ai-core::messages.inherit_default')`, falling back to the English literal `(inherit default)` when no Laravel translator is registered (e.g. plain PHPUnit). en/zh-CN/fr message files already carried the key; the hardcoded CN literal was the only blocker for EN/FR UIs.

**`CliStatusDetector` picks up host-registered CLI engines**
- `all()` iterates `EngineCatalog::keys()` instead of a hardcoded list, so any engine a host app registered via `super-ai-core.engines` config with `is_cli: true` + `cli_binary: <name>` surfaces in `cli:status` and the `/providers` cards. Built-in engines still hit `detectBinary()` directly for a fast path; catalog engines are resolved through the registered descriptor.
- `detect(<backend>)` accepts any backend key that the catalog knows; unknown backends fall through to a `['installed' => false]` stub instead of silently being dropped.

**`BackendRegistry` constructor accepts a testable SDK-availability callable**
- New optional third param `?callable $superagentAvailable = null` lets tests inject `fn() => false` to exercise the "SuperAgent SDK absent" branch without having to uninstall the package. Defaults to `[SuperAgentDetector::class, 'isAvailable']` so production callers see no behaviour change.

### Fixed

**Previously-unreachable SDK-missing test now runs**
- `tests\Unit\BackendRegistryTest::test_superagent_is_hidden_when_sdk_missing_even_with_config_enabled` used to call `markTestSkipped()` on every run because `composer.json` requires `forgeomni/superagent` as a hard dep — `class_exists(\SuperAgent\Agent::class)` is always true. The test now uses the injectable availability callable, asserts the negative path, and a matching `test_superagent_registered_when_sdk_available_and_enabled` covers the positive path. Skip count drops from 1 to 0.

### Tests
- 18 new tests: 3 × `CostCalculator` (catalog fallback, config-wins, no-match-returns-zero), 2 × `GeminiModelResolver` (catalog alias resolution, cross-provider isolation), 4 × `ModelsCommand` (list / filter / status / unknown-action), 3 × `CliStatusDetectorGeminiAuth` (oauth file / env key / not-logged-in), 5 × `EngineCatalog` (placeholder fallback, explicit placeholder, claude + gemini catalog expansion, host override wins, copilot untainted), 1 × `BackendRegistry` (SDK-present positive path). The pre-existing `test_superagent_is_hidden_when_sdk_missing...` case now actually executes.
- Full suite: **243 tests / 690 assertions / 0 failures / 0 skipped** (was 225 / 634 / 1 skipped at 0.5.9).

### Environment reference

```env
# Opt-in catalog auto-refresh at CLI startup (both must be set)
SUPERAGENT_MODELS_URL=https://your-cdn/models.json
SUPERAGENT_MODELS_AUTO_UPDATE=1
```

```bash
# Inspect or refresh the model catalog
./vendor/bin/superaicore super-ai-core:models status
./vendor/bin/superaicore super-ai-core:models list --provider=anthropic
./vendor/bin/superaicore super-ai-core:models update                 # from $SUPERAGENT_MODELS_URL
./vendor/bin/superaicore super-ai-core:models update --url https://…
./vendor/bin/superaicore super-ai-core:models reset                  # delete user override
```

## [0.5.9] — 2026-04-19

Follow-up release that closes two regressions shipped in 0.5.7/0.5.8 and finishes turning `EngineCatalog` into the single source of truth for host-app model pickers.

**Regressions fixed:**
1. The Copilot CLI card never appeared on `/providers` because the view filtered the CLI-status list through a hardcoded 4-engine map, silently dropping copilot.
2. CLI auth detection reported every CLI as "not signed in" under `php artisan serve` (and any FPM pool with `clear_env = yes`), because the request worker's env is stripped of `HOME`/`USER`/`LOGNAME` — `claude auth status`, `codex login status`, and the Copilot config-dir heuristic all need HOME to locate their credential stores.

**Catalog work:** model dropdowns that host apps used to hand-roll per backend (`if ($backend === 'claude') … elseif …`) now resolve from a single `EngineCatalog::modelOptions($key)` / `modelAliases($key)` call. New engines registered via `super-ai-core.engines` config light up in every host dropdown automatically.

### Fixed

**Providers page: Copilot card now renders**
- `resources/views/providers/index.blade.php` previously narrowed the CLI-status card list through `array_intersect_key` against a hardcoded `['claude', 'codex', 'gemini', 'superagent']` array, so the copilot engine — registered in the catalog since 0.5.7 — never produced a card. Rewrote to iterate the live `$engines` catalog so every enabled CLI surfaces automatically with its label, install status, version, path, auth state, and install hint. Added the `npm i -g @github/copilot` install hint for the "not installed" path.

**CLI auth detection survives env-stripped request workers**
- `Services\CliStatusDetector::childEnv()` — new helper that rebuilds the minimum env a CLI child needs (`HOME`, `USER`, `LOGNAME`, `PATH`, plus passthroughs for `TMPDIR`, `XDG_*`, `LANG`, and every documented CLI OAuth token env var) and hands it explicitly to every `Process::fromShellCommandline()` call. When `getenv('HOME')` is false (PHP's built-in dev server, FPM with `clear_env=yes`, supervisor configs that scrub env), we fall back to `posix_getpwuid(posix_getuid())` — the kernel knows the real user regardless of what PHP's env table says.
- `detectBinary()`, `detectAuth()` (claude/codex/copilot branches), `probeCopilotLive()`, and `findPath()` (both `node -v` + `which <binary>`) all now use the rebuilt env. Fixes the symptom where claude/codex/copilot cards showed "未登录" / "Not signed in" on `/providers` after a fresh `php artisan serve` even though all three CLIs were authenticated.

### Changed

**`EngineCatalog` is now the single source of truth for model dropdowns**
- `modelOptions(string $key, bool $withPlaceholder = true, string $placeholder = '— 继承默认 —'): array` — returns the associative shape `['' => placeholder, '<id>' => '<display>', ...]` that Blade `<select>` lists consume directly. Per-engine `ModelResolver` (Claude / Codex / Gemini / Copilot / SuperAgent) drives the body when present, so family aliases (`sonnet`, `pro`, `flash`) appear alongside the full catalog in one pass. Engines without a dedicated resolver (host-registered CLIs) fall back to `EngineDescriptor::availableModels`.
- `modelAliases(string $key): array` — same data reshaped as a sequential `[{id, name}, ...]` list, matching the JSON envelope task create/show blades' model-picker JS already expects.
- Host apps previously hand-maintained per-backend `switch ($backend)` statements in 3–4 controllers to build the same lists. They can now delete those and call the catalog. New engines plugged in via `super-ai-core.engines` config then auto-populate every host dropdown without host-side code changes.

**New `CopilotModelResolver`**
- `Services\CopilotModelResolver` — canonical model catalog for the Copilot CLI. Copilot's IDs use **dot** separators (`claude-sonnet-4.6`, `gpt-5.1`) — unlike Claude CLI's **dashes** (`claude-sonnet-4-6`). Before this resolver, hosts that piped a Claude-shaped ID through the copilot backend would get silently rejected ("Model '...' from --model flag is not available", exit 1, no assistant output). `resolve()` rewrites known family aliases (`sonnet`/`opus`/`haiku` → latest Copilot dot-ID) and passes unknown input through; `catalog()` / `families()` / `defaultFor()` mirror the shape the other resolvers already expose so `EngineCatalog::modelOptions('copilot')` gets family aliases + full catalog for free. The seeded `copilot` `available_models` list is rebuilt as a projection of this resolver so dashboard/legacy callers stay in sync.

**Copilot engine label tightened**
- `EngineCatalog::seed()` — `label` field for `copilot` changed from `'GitHub Copilot CLI'` to `'GitHub Copilot'`. Shows up on `/providers` card headers and in every `Built-in (<label>)` string that reads `$engine->label`. Docs and READMEs still refer to "GitHub Copilot CLI" in contexts where the CLI tool itself is being described.

### Tests
- 6 new `EngineCatalogTest` cases — resolver-driven options for claude + copilot (family aliases + full catalog), host-registered engine options (descriptor fallback), placeholder on/off, `modelAliases()` shape, unknown-engine guard.
- Full suite: 225 tests / 634 assertions / 1 pre-existing skip, zero regressions.

## [0.5.8] — 2026-04-18

Follow-up release on top of 0.5.7. Declarative CLI command-shape lands on `EngineDescriptor` so host apps stop duplicating process-launch tables, a builder registry derives argv from that spec with a per-engine override hatch, the Copilot auth heuristic gets an opt-in liveness probe, and the Gemini/Copilot sync writers share a single non-destructive skeleton. All additive — no breaking changes.

### Added

**Engine process-spec + CLI builder registry**
- `Support\ProcessSpec` — declarative command-shape metadata (binary, version args, auth-status args, prompt/output/model flags, default flags, default timeout). Host apps previously duplicated this table; it now lives on the engine catalog.
- `Support\EngineDescriptor` gains a nullable `processSpec` field, surfaced in `toArray()` and seeded for every CLI engine (claude/codex/gemini/copilot). `superagent` stays null. Hosts can override per-engine via `super-ai-core.engines.<key>.process_spec` (accepts `ProcessSpec` instance or array).
- `Services\CliProcessBuilderRegistry` — assembles argv arrays from a ProcessSpec (`build($key, ['prompt' => ..., 'model' => ...])`). Default builder covers all seeded engines; hosts call `register($key, $callable)` to override without forking. Also exposes `versionCommand()` / `authStatusCommand()` for the status detector path. Registered as a singleton on the service provider.

**Copilot CLI liveness probe (opt-in)**
- `Services\CliStatusDetector::detectAuth('copilot', ...)` now optionally verifies the binary itself runs (`copilot --help` under 3s timeout) and returns the result as `auth.live`. Gated behind `SUPERAICORE_COPILOT_PROBE=1` so status pages stay fast by default; result cached per-path within a request. `static::` dispatch lets hosts/tests subclass and swap the probe.

### Changed

**Sync writers share a single non-destructive skeleton**
- New `Sync\AbstractManifestWriter` hoists the contract that both `GeminiCommandWriter` and `CopilotAgentWriter` were implementing by hand: on-disk hash compare, user-edit detection, manifest round-trips, dry-run, stale cleanup, status constants. Concrete writers now only render targets and delegate to `applyTargets()` / `applyOne()`. `CopilotHookWriter` stays standalone — its single-JSON-file contract is too different to share.

**CLI installer — one-shot bootstrap for engine CLIs**
- `cli:status` — table of installed / version / auth / install-hint per backend (`claude` / `codex` / `gemini` / `copilot` / `superagent`). Pass `--json` for machine-readable output.
- `cli:install [backend?] [--all-missing] [--via=npm|brew|script] [--yes] [--dry-run]` — shells out to `npm`/`brew`/`curl|sh`. Default source is `npm` for uniformity (Windows/Linux/macOS); `brew` is offered for codex, `curl` for claude. Superagent is intentionally skipped (PHP SDK, not a CLI). Pre-flight check that the underlying tool (`npm` / `brew` / `sh`) resolves on PATH; exits 127 with a hint when it doesn't. Confirmation prompt by default; `--yes` skips it for CI.
- `Services\CliInstaller` — the install-command matrix + execution engine, reusable from host apps.

**Copilot fan-out + hooks sync (followups #3, #4)**
- `copilot:fleet <task> --agents a,b,c` — parallel Copilot sub-agent fan-out. Native `/fleet` is interactive-only, so we orchestrate N concurrent `copilot --agent X -p ... --output-format=json` children, stream their output with `[<agent>]` prefixes, aggregate per-agent `{text, model, output_tokens, premium_requests, exit_code}` via the existing JSONL parser, and register each child in `ai_processes`.
- `copilot:sync-hooks` — merge a host app's Claude-style `hooks` block (`.claude/settings.json.hooks` by default, configurable via `--source`) into `~/.copilot/config.json`. Copilot accepts PascalCase event names (`PreToolUse`/`PostToolUse`/`SessionStart`) verbatim and delivers the VS-Code-compatible snake_case payload, so translation is a pure file-placement operation. Manifest-tracked; re-sync is a no-op; user-edited hook blocks are detected via deep-ksort hashing and refused to overwrite.

### Changed

**CLI backends now report real token usage (followup #1)**
- `Backends\ClaudeCliBackend` — switched from `--print` (text) to `--output-format=json`. New `parseJson()` extracts `result` as text, `usage.{input_tokens,output_tokens,cache_read_input_tokens,cache_creation_input_tokens}`, `total_cost_usd`, and the primary model from `modelUsage` (picks the key with highest `costUSD` so side-call models like haiku don't overshadow the main opus answer).
- `Backends\CodexCliBackend` — switched to `exec --json`. New `parseJsonl()` parses the event stream: `item.completed{type=agent_message}` for text, `turn.completed.usage` for tokens, `turn.failed`/`error` for `stop_reason=error`.
- `Backends\GeminiCliBackend` — switched to `--output-format=json`. New `parseJson()` identifies the "main" answering model by `stats.models.<id>.roles.main` (falls back to highest-output when absent) and normalises Gemini-specific `candidates`/`prompt` token names to the canonical `input_tokens`/`output_tokens` contract.
- Dispatcher / CostCalculator downstream needed no changes — they already read `input_tokens` / `output_tokens`. Dashboards should stop showing `$0` for CLI routes that previously emitted placeholder usage.

**MonitoredProcess trait across all runners (followup #6)**
- New `Runner\Concerns\MonitoredProcess::runMonitored()` consolidates the `start()` → `ProcessRegistrar::start` → `wait()` with tee → `ProcessRegistrar::end` lifecycle. All 8 engine runners (Claude/Codex/Gemini/Copilot × Skill/Agent) now use it, so every CLI subprocess shows up in the Process Monitor UI with a live PID, log file, and finished/failed status. Copilot's two runners also migrated to the trait for consistency.
- `emit()` visibility on those runners widened from `private` to `protected` so the trait can call it.

### Tests
- 10 new `CliInstallerTest` cases (matrix coverage, source resolution, dry-run, unknown-backend, tool-available probe).
- 6 new `ClaudeCliBackendTest` + 6 new `CodexCliBackendTest` + 6 new `GeminiCliBackendTest` cases covering real JSONL/JSON envelopes, model-selection heuristics, failure paths, missing-field tolerance.
- 3 new `CopilotFleetRunnerTest` cases (dry-run fan-out + model override).
- 8 new `CopilotHookWriterTest` cases (written / unchanged / user-edited / cleared / hash stability / settings reader).
- 4 new `ProcessSpecTest` + 6 new `CliProcessBuilderRegistryTest` + 4 new `CliStatusDetectorCopilotProbeTest` cases covering seed shape, host overrides, default/override builders, positional-prompt CLIs, gated probe on/off + cache.
- Full suite: 219 tests / 617 assertions / 1 pre-existing skip, zero regressions.

## [0.5.7] — 2026-04-18

GitHub Copilot CLI lands as the fifth execution engine. Full end-to-end: backend, capabilities, skill/agent runners, `copilot:sync` for translating `.claude/agents` → `~/.copilot/agents/*.agent.md`, tool-permission translation from canonical Claude names to Copilot's category-glob syntax, and subscription-billing awareness on the cost dashboard. The `copilot` CLI itself handles OAuth device flow, keychain storage, and session-token refresh — we delegate entirely to the binary and never store GitHub credentials ourselves.

Also a focused set of infrastructure extractions used by Copilot and leveraged by the followups in `[Unreleased]`:

- `Services\EngineCatalog` — single source of truth for engine labels, icons, dispatcher backends, provider-type matrices, model catalogs, and process-scan keywords. New engines plug in via `EngineCatalog::seed()` and the UI / monitor / toggle-table update automatically. Host apps can override per-engine fields through `super-ai-core.engines` config.
- `Support\EngineDescriptor` — value object backing the catalog; also the contract the providers page iterates.
- `Support\ProcessRegistrar` — optional persistence helper that writes CLI subprocesses into `ai_processes` so the Process Monitor sees them. No-op outside Laravel (swallows throws when Eloquent isn't bound), which keeps the CLI runners framework-agnostic. Extended across all runners in `[Unreleased]` via a shared trait.
- `docs/copilot-followups.md` — written alongside this release to capture everything we deliberately did NOT ship in 0.5.7 (usage extraction for Claude/Codex/Gemini, `/fleet` fan-out, hooks integration, plugin-skill coverage, XDG path fix). Most of those are now landing in `[Unreleased]`.

### Added

**Copilot CLI backend**
- `Backends\CopilotCliBackend` — spawns `copilot -p <prompt> --allow-all-tools --output-format=json`. JSONL parser extracts assistant text (concatenated `assistant.message` events), the model the Copilot router actually selected (`session.tools_updated.data.model`), and output-token counts. Copilot doesn't report `input_tokens` (billing is request-based, not per-token), so that field stays 0 and the cost calculator's subscription-billing path handles the $0 USD contribution to dashboard totals. `premium_requests` (subscription metric) is exposed on the usage array but not consumed downstream yet.
- Auth delegated to the binary: `builtin` (local `copilot login`) is the default; `COPILOT_GITHUB_TOKEN` / `GH_TOKEN` / `GITHUB_TOKEN` passthrough for headless runners.
- New env flags: `AI_CORE_COPILOT_CLI_ENABLED`, `COPILOT_CLI_BIN`, `AI_CORE_COPILOT_ALLOW_ALL_TOOLS`.

**Copilot capabilities + tool-permission translation**
- `Capabilities\CopilotCapabilities` — no preamble (Copilot reads `.claude/skills/` natively), sub-agents supported (`--agent <name>`), MCP passthrough via `~/.copilot/mcp-config.json`. `toolNameMap()` returns empty because Copilot accepts canonical Claude names for most built-ins.
- `Translator\CopilotToolPermissions` — translates canonical Claude tool names in `allowed-tools:` / `disallowed-tools:` frontmatter to Copilot's `category(glob)` grant syntax (`Bash` → `shell`, `Read`/`Write`/`Edit` → `write`, etc.). Feeds `copilot --allow-tool` / `--deny-tool` repeatable flags.

**Copilot skill & agent runners**
- `Runner\CopilotSkillRunner` — `copilot -p <skill body + args> -s --allow-all-tools`. Zero-translation pass-through: Copilot reads `.claude/skills/` itself, so skill bodies referencing tool names resolve natively.
- `Runner\CopilotAgentRunner` — `copilot --agent <name> -p <task> -s --allow-all-tools`. Auto-syncs the `.agent.md` target before exec so users never need to remember `copilot:sync`. If the user has hand-edited the synced file, we proceed with a warning instead of overwriting.

**Copilot agent sync (Claude → Copilot agent file translation)**
- `Sync\CopilotAgentWriter` — reads `.claude/agents/<name>.md` and writes `~/.copilot/agents/<name>.agent.md` with a `# @generated-by: superaicore` + `# @source: <path>` header. Tracks per-target `sha256` in the manifest so we can detect user edits (refuse to overwrite) and stale files (left in place as `stale-kept`).
- `Console\Commands\CopilotSyncCommand` — `copilot:sync [--dry-run] [--copilot-home=...]`. Prints the same five-section change table as `gemini:sync` (`+written`, `·unchanged`, `-removed`, `!user-edited`, `!stale-kept`). `--copilot-home` defaults to `$XDG_CONFIG_HOME/copilot` when that's set, else `$HOME/.copilot`.

**Cost dashboard: subscription vs usage billing**
- `Services\CostCalculator::billingModel()` — reports `usage` or `subscription` per (model, backend). Copilot models are tagged `subscription` so they contribute $0 to the USD-per-call rollup but are counted separately in a new "Subscription calls" panel on the cost dashboard. Pricing catalog extended with Copilot's subscription-request tiers.
- `Http\Controllers\CostDashboardController` — splits the summary into `per-token` vs `subscription` rollups; views updated to match.

**Providers UI**
- Copilot card on `/providers` with install hint (`npm i -g @github/copilot`), `copilot login` reminder, and model catalog (gpt-5.4, claude-sonnet-4.6, etc.). Engine on/off toggle gates both `copilot_cli` at the dispatcher level.
- `BackendState::DISPATCHER_TO_ENGINE` gains `copilot_cli → copilot` mapping.

### Changed

- `Console\Application` adds `copilot:sync` and accepts `--backend=copilot` in `skill:run` / `agent:run`.
- `AgentRunCommand::inferBackend()` now recognises `gpt-5.*`-style Copilot model names alongside the existing family patterns. Still defaults to `claude` when in doubt.
- `ProcessMonitor::DEFAULT_KEYWORDS` includes `copilot` so the process monitor picks up Copilot CLI invocations.
- `CliStatusDetector` probes `copilot` on `$PATH`, reports version and a best-effort auth state (env token / local config / none).

### Tests
- `CopilotCliBackendTest` — 5 cases (JSONL parser happy path + multi-message concat + non-zero exit + empty / non-JSON input + bogus binary probe).
- `CopilotCapabilitiesTest` — 6 cases (capability flags + identity passthrough).
- `CopilotSkillRunnerTest` / `CopilotAgentRunnerTest` — dry-run shape, `--allow-all-tools` flag, allowed-tools note, auto-sync preamble.
- `CopilotAgentWriterTest` — 8 cases (first sync, idempotent second sync, stale cleanup, user-edited preservation, `--dry-run` isolation).
- `CopilotToolPermissionsTest` — canonical → category translation, mixed allow/deny, unmapped names.
- `EngineCatalogTest` — 11 cases covering label/icon lookup, backend → engine map, provider-type matrix, model catalog fallback, host override.
- `ProcessRegistrarTest` — 6 cases (null outside Laravel, unsafe pids rejected, log file creation, default path format).
- Full suite at release tag: 165 tests / 421 assertions / 1 skip.

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

[0.6.6]: https://github.com/forgeomni/SuperAICore/releases/tag/v0.6.6
[0.6.5]: https://github.com/forgeomni/SuperAICore/releases/tag/v0.6.5
[0.6.2]: https://github.com/forgeomni/SuperAICore/releases/tag/v0.6.2
[0.6.1]: https://github.com/forgeomni/SuperAICore/releases/tag/v0.6.1
[0.6.0]: https://github.com/forgeomni/SuperAICore/releases/tag/v0.6.0
[0.5.9]: https://github.com/forgeomni/SuperAICore/releases/tag/v0.5.9
[0.5.8]: https://github.com/forgeomni/SuperAICore/releases/tag/v0.5.8
[0.5.7]: https://github.com/forgeomni/SuperAICore/releases/tag/v0.5.7
[0.5.6]: https://github.com/forgeomni/SuperAICore/releases/tag/v0.5.6
[0.5.5]: https://github.com/forgeomni/SuperAICore/releases/tag/v0.5.5
[0.5.2]: https://github.com/forgeomni/SuperAICore/releases/tag/v0.5.2
[0.5.1]: https://github.com/forgeomni/SuperAICore/releases/tag/v0.5.1
[0.5.0]: https://github.com/forgeomni/SuperAICore/releases/tag/v0.5.0
