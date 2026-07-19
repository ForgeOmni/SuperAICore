# forgeomni/superaicore

[![tests](https://github.com/ForgeOmni/SuperAICore/actions/workflows/tests.yml/badge.svg)](https://github.com/ForgeOmni/SuperAICore/actions/workflows/tests.yml)
[![license](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![php](https://img.shields.io/badge/php-%E2%89%A58.1-blue.svg)](composer.json)
[![laravel](https://img.shields.io/badge/laravel-10%20%7C%2011%20%7C%2012-orange.svg)](composer.json)

[English](README.md) · [简体中文](README.zh-CN.md) · [Français](README.fr.md)

Laravel package for unified AI execution across eight execution engines — **Claude Code CLI**, **Codex CLI**, **Gemini CLI**, **GitHub Copilot CLI**, **AWS Kiro CLI**, **Moonshot Kimi Code CLI**, **Alibaba Qwen Code CLI**, and **SuperAgent SDK**. Ships with a framework-agnostic CLI, a capability-based dispatcher, MCP server management, usage tracking, cost analytics, an OpenAI-compatible proxy, magic-trace ring-buffer tracing, and a complete admin UI.

Works standalone in a fresh Laravel install. The UI is optional and fully overridable — embed it inside a host application (e.g. SuperTeam) or disable it entirely when only the services are needed.

## Table of contents

- [Relationship to SuperAgent](#relationship-to-superagent)
- [Features](#features)
  - [Execution engines + provider types](#execution-engines--provider-types)
  - [Skill & sub-agent runner](#skill--sub-agent-runner)
  - [Skill engine — telemetry, ranking, evolution](#skill-engine--telemetry-ranking-evolution)
  - [jcode companion-tools wave (0.9.0 / SDK 0.9.7)](#jcode-companion-tools-wave-090--sdk-097)
  - [DeepSeek-TUI parity wave (0.9.1 / SDK 0.9.8)](#deepseek-tui-parity-wave-091--sdk-098)
  - [TaskRunner reliability wave (0.9.2)](#taskrunner-reliability-wave-092)
  - [Squad multi-agent + SDK 1.0.0 wave (0.9.6)](#squad-multi-agent--sdk-100-wave-096)
  - [opencode-borrowed feature wave (0.9.7 / SDK 1.0.5)](#opencode-borrowed-feature-wave-097--sdk-105)
  - [Qwen + tracing + 9Router wave (0.9.8)](#qwen--tracing--9router-wave-098)
  - [Opus 4.8 + Grok + Cursor wave (1.0.0 / SDK 1.0.9)](#opus-48--grok--cursor-wave-100--sdk-109)
  - [kimi-cli + kimi-code dual-CLI wave (1.0.2 / SDK 1.0.10)](#kimi-cli--kimi-code-dual-cli-wave-102--sdk-1010)
  - [SmartFlow cross-CLI workflows wave (1.0.5 / SDK 1.1.0)](#smartflow-cross-cli-workflows-wave-105--sdk-110)
  - [CLI skill bridge wave (1.0.6)](#cli-skill-bridge-wave-106)
  - [MiniMax M3 + catalog reprice wave (1.0.7 / SDK 1.1.1)](#minimax-m3--catalog-reprice-wave-107--sdk-111)
  - [streamChat MCP wave (1.0.8)](#streamchat-mcp-wave-108)
  - [GLM-5.2 native flagship wave (1.0.10 / SDK 1.1.2)](#glm-52-native-flagship-wave-1010--sdk-112)
  - [Fable 5 & Sonnet 5 wave (1.0.11 / SDK 1.1.5)](#fable-5--sonnet-5-wave-1011--sdk-115)
  - [ai-dispatch parity wave (1.1.0)](#ai-dispatch-parity-wave-110)
  - [GPT-5.6 + Grok 4.5 catalog refresh wave (1.1.6 / SDK 1.1.6)](#gpt-56--grok-45-catalog-refresh-wave-116--sdk-116)
  - [Kimi K3 wave (1.1.7 / SDK 1.1.7)](#kimi-k3-wave-117--sdk-117)
  - [Kimi Code 0.27 support refresh wave (1.1.8)](#kimi-code-027-support-refresh-wave-118)
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

- **Ten execution engines** unified behind a single `Dispatcher` contract:
  - **Claude Code CLI** — provider types: `builtin` (local login), `anthropic`, `anthropic-proxy`, `bedrock`, `vertex`.
  - **Codex CLI** — `builtin` (ChatGPT login), `openai`, `openai-compatible`.
  - **Gemini CLI** — `builtin` (Google OAuth), `google-ai`, `vertex`.
  - **GitHub Copilot CLI** — `builtin` only (`copilot` binary owns OAuth/keychain/refresh). Reads `.claude/skills/` natively (zero-translation skill pass-through). **Subscription billed** — tracked separately on the dashboard.
  - **AWS Kiro CLI** *(since 0.6.1)* — `builtin` (local `kiro-cli login`), `kiro-api` (stored key injected as `KIRO_API_KEY` for headless). Ships the richest out-of-the-box CLI feature set — native agents, skills, MCP, and **subagent DAG orchestration** (no `SpawnPlan` emulation). Reads Claude's `SKILL.md` format verbatim. **Subscription billed** — credit-based Pro / Pro+ / Power plans.
  - **Moonshot Kimi Code CLI** *(since 0.6.8)* — `builtin` (`kimi login` OAuth via `auth.kimi.com`). Complements the SDK's direct-HTTP `KimiProvider` by covering the OAuth-subscription agentic-loop path, mirroring the `anthropic_api` ↔ `claude_cli` split. Native `Agent` fanout is honoured by default; opt into AICore's three-phase Pipeline via `use_native_agents=false`. **Subscription billed** — Moonshot Pro / Power.
  - **Alibaba Qwen Code CLI** *(since 0.9.8)* — gemini-cli fork (`QwenLM/qwen-code` v0.16.0). API key only via `DASHSCOPE_API_KEY` / `QWEN_API_KEY` (Qwen OAuth was EOL'd 2026-04-15). Default model `qwen3.7-max` — 1M context, $2.50/$7.50 per 1M, speaks Anthropic's `/v1/messages` natively (drop-in for Claude in fallback chains). **Usage billed.**
  - **Cursor Composer CLI** *(since 1.0.0)* — `builtin` (`cursor-agent login` browser OAuth → `~/.cursor`; headless runners may export `CURSOR_API_KEY`). Cursor's headless Composer agent (`cursor-agent`). Default model `composer-2.5-fast`; also proxies Anthropic (`claude-opus-4-8-thinking-high`) and OpenAI (`gpt-5.x-codex`) SKUs + an `auto` router. MCP via `.cursor/mcp.json`. **Subscription billed** — Cursor plan.
  - **xAI Grok Build CLI** *(since 1.0.0)* — `builtin` (`grok login` grok.com OAuth → `~/.grok`). xAI's "Grok Build" agentic CLI (`grok`). Default model `grok-build`; native sub-agents, effort control (`--effort low…max`), MCP via `grok mcp add`. **Subscription billed** — grok.com plan. *(Distinct from the metered xAI **API** provider type below.)*
  - **SuperAgent SDK** — provider types: `anthropic`, `anthropic-proxy`, `openai`, `openai-compatible`, plus `openai-responses` *(since 0.7.0)*, `lmstudio` *(since 0.7.0)*, `deepseek` *(since 0.9.0)*, `qwen-anthropic` *(since 0.9.8)*, and `grok` *(since 1.0.0 — metered xAI API, `XAI_API_KEY`/`GROK_API_KEY`, default `grok-4.3`, 1M context)*.
- **`openai-responses` provider type** *(since 0.7.0)* — routes through the SDK's `OpenAIResponsesProvider` against `/v1/responses`. Auto-detects Azure OpenAI deployments from the `base_url` pattern (adds `api-version=2025-04-01-preview` query string; override via `extra_config.azure_api_version`). When the row stores an `access_token` from a host-app ChatGPT-OAuth flow instead of an API key, the SDK flips the base URL to `chatgpt.com/backend-api/codex` so Plus / Pro / Business subscribers hit their subscription quota.
- **`lmstudio` provider type** *(since 0.7.0)* — local LM Studio server (default `http://localhost:1234`). OpenAI-compat wire; no real API key needed — the SDK synthesises a placeholder `Authorization` header.
- **Thirteen dispatcher adapters** behind the ten engines (`claude_cli`, `codex_cli`, `gemini_cli`, `copilot_cli`, `kiro_cli`, `kimi_cli`, `qwen_cli`, `cursor_cli`, `grok_cli`, `superagent`, `anthropic_api`, `openai_api`, `gemini_api`). CLI adapters when a provider uses `builtin` / `kiro-api`; HTTP adapters when it uses an API key. Addressable directly from the CLI when needed.
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

### Skill engine — telemetry, ranking, evolution

Three orthogonal services *(since 0.8.6)* that turn the static skill catalog into a feedback loop. Borrowed in spirit from HKUDS/OpenSpace's `skill_engine`, trimmed to the safe subset for production use — DERIVED / CAPTURED modes intentionally omitted (humans curate new skills on Day 0); cloud registry omitted (no cross-project sharing need yet).

- **`SkillTelemetry`** *(since 0.8.6)* — one row per Claude Code Skill tool invocation in `sac_skill_executions`. PreToolUse hook → `php artisan skill:track-start` (insert `in_progress` row, returns id). Stop hook → `php artisan skill:track-stop` (close every still-open row for the session). Both commands accept Claude Code's hook JSON payload on stdin so the wiring lives in `.claude/settings.local.json`, not in PHP. Aggregation seam: `SkillTelemetry::metrics(?since, ?skillName)` returns per-skill `applied / completed / failed / orphaned / interrupted / completion_rate / failure_rate / last_used_at`. `sweepOrphaned(maxAgeSeconds=7200)` recovers from crashed sessions.
- **`SkillRanker`** *(since 0.8.6)* — pure-PHP BM25 over the `SkillRegistry` catalog (Robertson-Walker `K1=1.5`, `B=0.75`, BM25-Plus IDF). CJK-aware tokeniser emits each Han character as its own token (Chinese skill descriptions are short — char-grams suffice), tiny EN+zh stopword list, hyphenated words yield their parts. Confidence-weighted telemetry boost: `final = bm25 * (1 + 0.4 * (success_rate - 0.5) * applied_signal)`, where `applied_signal = min(1, applied / 10)` saturates near 10 runs. Skills with no telemetry get `boost = 1.0`. Drives `php artisan skill:rank "your task description"` — table or JSON, with full per-term IDF×TF breakdown for debugging.
- **`SkillEvolver`** *(since 0.8.6)* — FIX-mode only. Reads recent failures + current SKILL.md, builds a constrained LLM prompt ("smallest possible patch", "do not invent failures the evidence does not support", "do not restructure sections / rename / change frontmatter `name` / add new tools to `allowed-tools` unless evidence demands it"), and persists a `SkillEvolutionCandidate` row in `pending` status. **Never modifies SKILL.md directly** — humans review via `php artisan skill:candidates --id=N --show-prompt --show-diff`. `--dispatch` mode (off by default — costs tokens) routes the prompt through the Dispatcher with `capability: 'reasoning'`, parses the `\`\`\`diff` block, and stores both `proposed_body` and `proposed_diff`. `--sweep --threshold=0.30 --min-applied=5` queues candidates for every skill that exceeds the threshold; de-duped against existing pending rows so it's safe to run daily. Triggers: `manual` / `failure` / `metric_degradation`.
- **Six artisan commands**: `skill:track-start`, `skill:track-stop`, `skill:stats`, `skill:rank`, `skill:evolve`, `skill:candidates`. All registered through `SuperAICoreServiceProvider::boot()` — `php artisan skill:*` works in any host that mounts the package.
- **Two new tables**: `sac_skill_executions` (skill_name, host_app, session_id, status, started_at, completed_at, duration_ms, transcript_path, error_summary, cwd, metadata json) and `sac_skill_evolution_candidates` (skill_name, trigger_type, execution_id, status, rationale, proposed_diff, proposed_body, llm_prompt, context json, reviewed_at, reviewed_by). Both honour `super-ai-core.table_prefix` via `HasConfigurablePrefix`. `php artisan migrate` to pick them up.

### Kimi Code 0.27 support refresh wave (1.1.8)

No SDK bump — a SuperAICore-only refresh, re-verified live against Moonshot's
kimi-code v0.27.0. The CLI moved its whole state dir from `~/.kimi/` (legacy
Python kimi-cli) to `$KIMI_CODE_HOME` (default `~/.kimi-code/`); everything
below now probes the layout and picks the right paths, while legacy installs
keep their old behavior untouched.

- **Login detection fixed** — `doctor` / providers UI checked only
  `~/.kimi/credentials/`; a logged-in kimi-code install
  (`~/.kimi-code/credentials/kimi-code.json`) was reported as logged out.
  Both generations' paths are checked now.
- **MCP sync lands where the CLI reads it** — the `claude:mcp-sync` fan-out
  wrote `~/.kimi/mcp.json`, which kimi-code never reads; it now targets
  `~/.kimi-code/mcp.json` (same Claude-compatible `mcpServers` JSON,
  hand-edited keys preserved) when the new layout is present.
- **Binary discovery for non-login shells** — the official installer drops
  the single binary into `~/.kimi-code/bin`, absent from fpm / queue / cron
  PATHs; `CliBinaryLocator` and `KimiCliBackend::isAvailable()` probe it
  directly on all three platforms.
- **Installer default modernized** — `cli:install kimi` now runs Moonshot's
  official install script; `--via=uv` / `--via=pip` remain for the legacy
  Python CLI.
- **Skills go native** — kimi-code auto-discovers `~/.kimi-code/skills/`
  (SKILL.md packs), so the CLI skill bridge promotes Kimi from an
  instructions-digest file to first-class per-skill installs, like
  Codex / Gemini / Grok / Cursor.
- **Tool names corrected** — kimi-code speaks Claude Code tool names on the
  wire (`Bash`, not legacy `Shell`), verified from a live capture;
  translation now applies to legacy installs only.
- **Dispatch unchanged** — the headless contract (`--prompt` +
  `--output-format stream-json`) is identical in 0.27; `KimiCliBackend`
  needed comments only. Field notes: `docs/kimi-cli-backend.md` §9.

### Kimi K3 wave (1.1.7 / SDK 1.1.7)

SDK pin moves `^1.1.6` → `^1.1.7`. SuperAgent 1.1.7 lands **Kimi K3** — Moonshot's
new open-weight general flagship (released 2026-07-16) and the SDK's new
zero-config `kimi` default. Additive and non-breaking — no migrations, no config
changes.

- **Kimi K3 priced** — `kimi-k3` (a 2.8T open-weight MoE, 1M context, always-on
  thinking, image + video input) at the official metered-API rate **$3 in /
  $0.30 cached / $15 out** per 1M, seeded into `model_pricing` so
  `CostCalculator` buckets it offline without a catalog round-trip. The
  coding-focused `kimi-k2.7-code` is unchanged; the retired `kimi-k2-6` stays
  reachable by id (resolves through the SDK's `ModelCatalog`). The
  native-Kimi zero-config default (`kimi` → `kimi-k3`) is owned SDK-side by
  `KimiProvider`; SuperAICore forwards it untouched, and the subscription
  `kimi` CLI engine (kimi-code OAuth, $0/token) is a separate surface, left
  as-is.
- **Fixed: `superaicore --version`** now reports `1.1.7` (it was stuck at
  `1.1.5` — never bumped in the 1.1.6 release).
- **Internal cleanup** — `SuperAgentBackend::buildPerCallOptions` now routes its
  repeated string-forwarding through two helpers (`putRawString` /
  `putLoweredString`); behavior-preserving, with regression tests.

### GPT-5.6 + Grok 4.5 catalog refresh wave (1.1.6 / SDK 1.1.6)

SDK pin moves `^1.1.5` → `^1.1.6`. SuperAgent 1.1.6 lands **GPT-5.6**
(Sol / Terra / Luna — the new `openai-responses` default) and **Grok 4.5**
(the new `grok` default) with their request surfaces, and corrects the
Gemini / DeepSeek / MiniMax / GLM / Qwen catalog to official rates;
SuperAICore forwards the new per-call options, mirrors the corrected rates
into its own `model_pricing` table, and fixes the Gemini picker drift
(`gemini-3.5-pro` / `gemini-3.5-flash-lite` never shipped). Additive and
non-breaking — no migrations, no config changes.

- **GPT-5.6 / Gemini 3.5 request surface forwarded** — `SuperAgentBackend`
  now forwards `reasoning_mode` (`standard`|`pro` — Sol Pro),
  `reasoning_context` (`auto`|`all_turns`|`current_turn`) and
  `prompt_cache_options` (explicit caching: writes 1.25×, reads keep −90%)
  to the SDK's `OpenAIResponsesProvider`, plus `thinking_level`
  (`minimal`…`high`, the control that replaces `thinkingBudget`) to
  `GeminiProvider`. All four are silently ignored by providers that don't
  speak them. The existing `reasoning_effort` dial gains `none`/`max` on
  GPT-5.6 and the always-on three-level dial on Grok 4.5 — SDK-side, no
  SuperAICore change needed.
- **New models priced** — `gpt-5.6-sol` **$5 / $0.50 cached / $30** per 1M,
  `gpt-5.6-terra` **$2.50 / $0.25 / $15**, `gpt-5.6-luna` **$1 / $0.10 / $6**
  (all 1.05M context); `grok-4.5` **$2 / $0.50 cached / $6** (500K context,
  the new `grok` default; `grok-4.3` stays reachable); `gemini-3.5-flash`
  (the actual flagship) **$1.50 / $0.15 cached / $9**;
  `gemini-3.1-pro-preview` $2/$12; `gemini-3.1-flash-lite` $0.25/$1.50;
  `kimi-k2.7-code` $0.95 / $0.19 cache-hit / $4 (+`-highspeed` at 2×);
  `glm-5-turbo` / `glm-5v-turbo` $1.20/$4.
- **Fleet-wide corrections mirrored** — `gpt-5` to its official **$1.25/$10**
  (was a $5/$15 estimate), `deepseek-v4-flash` output **$0.55 → $0.28**
  (+$0.0028 cache-hit), `MiniMax-M3` to the permanent tiered
  **$0.30/$1.20** (cache-read $0.06), `qwen3.7-plus` to the GA **$0.40/$1.60**.
  Re-publish the config if your host carries an older copy.
- **Gemini catalog corrected to reality** — `gemini-3.5-pro` and
  `gemini-3.5-flash-lite` never publicly shipped and are removed from the
  `gemini` engine picker; `gemini-3.5-flash` / `gemini-3.1-pro-preview` /
  `gemini-3.1-flash-lite` land in `EngineCatalog` and `GeminiModelResolver`.
  Zero-config SDK defaults move (`openai-responses` → `gpt-5.6-sol`, `grok` →
  `grok-4.5`, `gemini` → `gemini-3.5-flash`); every previously shipped id
  stays reachable by explicit config.
- **Subscription CLI catalogs re-verified live (2026-07-12)** — the Grok
  Build plan (grok CLI 0.2.93) now routes `grok-4.5` as the subscription
  default plus `grok-composer-2.5-fast` (`grok-build` kept as a legacy row),
  and Cursor Composer's lineup (~189 slugs) makes `composer-2.5` the
  "current" pick and proxies Fable 5 / Sonnet 5 / GPT-5.6 Sol / Grok 4.5 /
  Gemini 3.5 Flash / Kimi K2.7 Code / GLM 5.2 — `GrokModelResolver`,
  `CursorModelResolver` (new `fable`/`sonnet`/`grok`/`gemini`/`kimi`/`glm`
  aliases), the engine seeds and the `grok:*`/`cursor:*` $0 subscription
  rows all follow. ZCode (Z.ai's desktop IDE) was evaluated and skipped —
  no headless CLI surface to integrate.

### ai-dispatch parity wave (1.1.0)

Borrowed from [rennzhang/ai-dispatch](https://github.com/rennzhang/ai-dispatch):
let one AI agent hand a task to another local AI engine without knowing that
engine's flags. One short token now resolves to an ordered `{backend, model}`
candidate pool with transparent degradation, sessions can genuinely be
resumed, and every dispatch is archived. Additive and non-breaking — see
[docs/ai-dispatch-parity.md](docs/ai-dispatch-parity.md).

```bash
superaicore send opus "review the diff in HEAD~1" --cwd "$PWD" --json-result
superaicore resume --session-id <id> "follow-up" --json-result
```

- **`superaicore send <target> "<task>"`** — target is an alias (`opus`,
  `kimi`, `gemini-pro`, …), a backend name, or a model id; `AliasRouter`
  resolves it (user config → built-ins → passthrough → inference) and the
  candidates are tried in order. Quota / rate-limit / auth / network
  failures fall through (`degraded: true` + full `route_trace[]`); anything
  else fails closed. `--json-result` returns `ok / status / backend_used /
  model_used / route_trace / degraded / failure_class / session_id / run_id`.
- **`superaicore resume --session-id <id>`** — true session continuation:
  `claude --resume` / `codex exec resume <thread_id>`; the run store knows
  which engine owns the session so the caller sends only the delta.
- **`superaicore runs list|show`** — filesystem run archive
  (`~/.superaicore/runs`), zero DB access needed.
- **`superaicore aliases [target]`** — inspect or resolve the routing pool;
  extend via `super-ai-core.dispatch.aliases`.
- **`superaicore preferences init|show|path`** — natural-language
  scenario→model preferences (`~/.superaicore/preferences.md`) read by the
  CALLING agent before it picks a target.
- **`superaicore skill:install-dispatch`** — installs the bundled
  `superaicore-dispatch` SKILL into agent skill dirs so external agents can
  delegate INTO SuperAICore (the reverse of `superaicore:sync-cli`). Covers
  `~/.claude/skills` / `~/.codex/skills` / `~/.gemini/skills`, and *(1.1.5)*
  `~/.grok/skills` / `~/.cursor/skills-cursor` / `~/.qwen/skills`; defaults
  to claude, `--agent all` installs everywhere, `--uninstall` reverses a
  prior install without touching your own skills *(1.1.5)*.
- **`superaicore doctor [--json]`** — aggregate diagnostic: engines, auth,
  backends, aliases, preferences, run store.

### Fable 5 & Sonnet 5 wave (1.0.11 / SDK 1.1.5)

SDK pin moves `^1.1.2` → `^1.1.5`. SuperAgent 1.1.5 lands **Claude Fable 5**
(`claude-fable-5`, Anthropic's most capable model) and **Claude Sonnet 5**
(`claude-sonnet-5`, the new `sonnet` flagship) as first-class `anthropic`
models, gives `AnthropicProvider` a `reasoning_effort` dial, and corrects
stale Anthropic pricing; SuperAICore mirrors the official rates into its own
`model_pricing` table and seeds the new ids into the `superagent` engine so
cost dashboards and pickers stay accurate offline. Additive and non-breaking —
no migrations, no config changes.

- **Fable 5 + Sonnet 5 native pricing** *(1.0.11)* — `claude-fable-5` (1M
  context, 128K max output, high-res vision, always-on adaptive thinking) at
  the official **$10 in / $50 out** per 1M — above the Opus tier — and
  `claude-sonnet-5` (same Claude-5-generation adaptive surface, close to
  Opus 4.8 at the Sonnet price) at **$3 / $15** (intro $2/$10 through
  2026-08-31; the table carries the official rate). Both ids are seeded into
  the `superagent` engine's `available_models` so they show in pickers
  offline.
- **Opus line repriced to official rates** *(1.0.11)* — current Opus
  (`claude-opus-4-5`→`4-8`) drops from the stale $15/$75 to **$5/$25** per 1M;
  only the dated `claude-opus-4-20250514` snapshot keeps the historical
  $15/$75. Re-publish the config if your host carries an older copy, or
  `CostCalculator` keeps billing Opus 3× too high.
- **Anthropic `reasoning_effort` dial** *(1.0.11)* — SDK 1.1.5 makes
  `AnthropicProvider` implement `SupportsReasoningEffort`, mapping the
  per-call option to Anthropic's GA `output_config.effort`
  (`low`/`medium`/`high`/`xhigh`/`max`) on Fable 5 / Sonnet 5 / Opus 4.5+ /
  Sonnet 4.6 — unsupported models and `off` yield no `output_config`, so a
  stray effort never 400s. Routes through `SuperAgentBackend` untouched.
- **Adaptive-only surface handled SDK-side** *(1.0.11)* — Fable 5 / Sonnet 5
  emit `thinking: {type: "adaptive"}` (never `budget_tokens`) and drop
  `temperature`/`top_p`/`top_k` and trailing assistant prefills; the same
  guards fix latent 400s that Opus 4.7/4.8 already hit. Zero-config
  `anthropic` now resolves to `claude-opus-4-8`; the SDK Squad EXPERT tier
  routes to `claude-fable-5` (SuperAICore's own `squad.tiers` config is
  unchanged).
- **Kiro tests made hermetic** *(1.0.11)* — `KiroModelResolverTest` and the
  kiro `EngineCatalogTest` case no longer read the developer machine's
  `~/.cache/superaicore/kiro-models.json` or live-probe `kiro-cli`; a new
  `IsolatesKiroCatalog` test trait plus `KiroModelResolver::resetMemo()` pin
  them to the deterministic static fallback. Production behaviour unchanged.

### GLM-5.2 native flagship wave (1.0.10 / SDK 1.1.2)

SDK pin moves `^1.1.1` → `^1.1.2`. SuperAgent 1.1.2 promotes **GLM-5.2** to the
native `glm` flagship and gives `GlmProvider` a `reasoning_effort` dial;
SuperAICore mirrors Z.ai's official rates into its own `model_pricing` table and
seeds the new id into the `superagent` engine so cost dashboards and pickers stay
accurate offline. Additive and non-breaking — no migrations, no config changes.

- **GLM-5.2 native pricing** *(1.0.10)* — `glm-5.2` (Z.ai's coding-first
  agentic flagship: 1M context, 128K max output, text-only) and `glm-5.1` (200K
  context) at the official PAYG **$1.40 in / $4.40 out** per 1M, with a **$0.26
  cache-hit input** tier (carried as `cache_read_input`); `glm-5` keeps its
  earlier $1.00 / $3.20. `CostCalculator` already falls back to the SDK
  `ModelCatalog`, so these rows simply keep accounting accurate without a
  catalog round-trip; `glm-5.2` is also seeded into the `superagent` engine's
  `available_models` so it shows in pickers offline.
- **GLM-5.2 `reasoning_effort` dial** *(1.0.10)* — SDK 1.1.2 makes `GlmProvider`
  implement `SupportsReasoningEffort`, joining MiniMax M3. The per-call
  `reasoning_effort` option (`off` → thinking disabled; `low…high` →
  `reasoning_effort high`; `max` → `reasoning_effort max`) and the bare
  `thinking` toggle both route through `SuperAgentBackend` untouched — they were
  already forwarded generically, so the dial works the moment the SDK lands.
- **Catalog carried forward** *(1.0.10)* — GLM-5.1 (long-horizon, 200K context)
  and the earlier `glm-5` line stay reachable by id; only the bare `glm`
  shorthand and the zero-config default now resolve to GLM-5.2.

### streamChat MCP wave (1.0.8)

`ClaudeCliBackend::streamChat()` can now expose a caller-scoped set of MCP
servers' tools to a one-shot chat turn. Pre-1.0.8 the chat sibling hardcoded a
locked-empty MCP config even though the dispatch path
(`prepareScriptedProcess()`) already supported `mcp_mode`; 1.0.8 mirrors that
contract. Additive and non-breaking — the default stays the locked-empty
surface, no migrations, SDK pin unchanged.

- **`mcp_mode: 'empty'|'file'|'inherit'`** *(1.0.8)* — default `'empty'` (the
  pre-1.0.8 behaviour, byte-identical argv). `'file'` passes
  `mcp_config_file` (a `{"mcpServers":{...}}` JSON path) as
  `--mcp-config <path> --strict-mcp-config`, exposing exactly that server
  subset to the turn; `'inherit'` adds no MCP flags. `'file'` without a
  usable path falls back to `'empty'` — never silently inherits the user's
  whole MCP surface.
- **`extra_cli_flags: string[]`** *(1.0.8)* — appended verbatim; escape hatch
  mirroring `prepareScriptedProcess()`.
- **`buildChatArgs()`** *(1.0.8)* — public pure argv builder extracted from
  `streamChat()`; the tools / MCP / model / extra-flags matrix is now
  unit-tested without process spawns.
- **ToolSearch auto-append** *(1.0.9)* — current Claude CLIs defer MCP tools
  behind the `ToolSearch` meta-tool (servers report "pending" at init and
  their tools are absent from the upfront list), and `--tools` restricts the
  **whole** tool surface — so an allowlist without ToolSearch makes every
  MCP tool unreachable. Whenever the effective MCP surface is non-empty
  (`'file'` with a path, or `'inherit'`), `ToolSearch` is guaranteed onto
  the allowlist; older CLIs ignore unknown `--tools` entries, so this is
  safe everywhere. Hosts: write a subset config file, pass
  `mcp_mode: 'file'`, and the model loads `mcp__<server>__<tool>` for
  exactly the selected servers — see `docs/advanced-usage.md` §12.

### MiniMax M3 + catalog reprice wave (1.0.7 / SDK 1.1.1)

SDK pin moves `^1.1.0` → `^1.1.1`. SuperAgent 1.1.1 lands **MiniMax M3** as a
first-class native model and reprices the DeepSeek V4 Pro / MiniMax catalog to
live vendor rates; SuperAICore mirrors those corrections into its own
`model_pricing` table and engine seed so cost dashboards and pickers stay
accurate offline. Additive and non-breaking — no migrations, no config changes.

- **MiniMax M3 native pricing** *(1.0.7)* — `MiniMax-M3` (MSA flagship: 1M
  context, 512K max output, native image/video input, interleaved thinking) at
  the standard PAYG **$0.60 in / $2.40 out** per 1M, with explicit
  `MiniMax-M2.7` / `M2.5` / `M2` rows ($0.30 / $1.20). `CostCalculator` already
  falls back to the SDK `ModelCatalog`, so these rows simply keep accounting
  accurate without a catalog round-trip; `MiniMax-M3` is also seeded into the
  `superagent` engine's `available_models` so it shows in pickers offline.
- **DeepSeek V4 Pro repriced** *(1.0.7)* — to the current official rate
  **$0.435** in (cache-miss) / **$0.003625** in (cache-hit, `cache_read_input`)
  / **$0.87** out per 1M, down from the stale $0.55 / $2.20. The deprecated
  `deepseek-reasoner` alias (routes to V4 Pro) follows suit.
- **SmartFlow carried forward** *(1.0.7)* — the 1.1.1 pin still includes the
  1.1.0 SmartFlow engine the existing `SuperAgentFlowBridge` delegates to, so
  cross-CLI flows that fan out to `superagent` are unchanged.

### CLI skill bridge wave (1.0.6)

One generic, symlink-safe, fingerprinted bridge that fans a host's skill + agent
library into every CLI backend's native surface — the same shape
`McpManager::syncAllBackends()` already gives MCP. Before 1.0.6 each host
hand-rolled a separate per-CLI sync; 1.0.6 unifies them behind a contract +
service + command, plus a lazy on-dispatch sync. Additive and non-breaking: when
no `SkillLibrary` is bound the bridge is a silent no-op.

- **`SkillLibrary` contract** *(1.0.6)* — the host implements five methods
  (`skills()`, `agents()`, `skillWrapper($backend,$name)`,
  `instructionsDigest($backend)`, `fingerprint()`) and binds it
  (`$this->app->singleton(SkillLibrary::class, MyLibrary::class)`). SuperAICore
  knows WHERE / HOW / WHEN; the host supplies WHAT. No host assumptions baked in.
- **Three install shapes** *(1.0.6)* — `CliSkillBridge` fans the library out per
  backend: **`native_dir`** (codex / gemini / grok / cursor / qwen) drops one
  prefixed wrapper dir per skill into the CLI's skills directory;
  **`instructions`** (copilot / kimi / kiro) writes a single digest file that
  tells the model how to load any skill on demand; **`source`** (claude) reads
  `.claude/skills` directly and installs nothing.
- **Symlink-safe** *(1.0.6)* — the bridge **never writes through a symlink**.
  Every wrapper dir / `SKILL.md` / digest / manifest is `is_link()`-checked and the
  stale link unlinked (target intact) before any write — closing the
  write-through-symlink hole that once clobbered source skill bodies.
- **Lazy on-dispatch sync** *(1.0.6)* — each sync stamps the library
  `fingerprint()` into a per-backend manifest (`.superteam-skill-sync.json`);
  `TaskRunner` re-installs a backend before a dispatch only when the fingerprint
  drifted, so the hot path is one hash compare. Pruning is manifest-scoped —
  never touches the user's own skills.
- **`superaicore:sync-cli`** *(1.0.6)* — one command propagates the whole
  capability surface (skills + MCP) to every installed CLI:
  `--skills-only` / `--mcp-only` / `--backends=codex,gemini` / `--project-root=`.
- **Folded-in fix** *(1.0.6)* — `builtin` (subscription/OAuth) runs across the
  claude / codex / gemini / cursor / grok backends now scrub any stale inherited
  console key so it can't override the login and 401; Claude's Keychain OAuth
  token is injected as `CLAUDE_CODE_OAUTH_TOKEN`, not `ANTHROPIC_API_KEY`.

### SmartFlow cross-CLI workflows wave (1.0.5 / SDK 1.1.0)

SDK pin moves to `^1.1.0`. SuperAICore 1.0.5 ports Claude Code's built-in
`Workflow` engine as **SmartFlow** — cross-CLI dynamic workflows — and federates
it with superagent's own (cross-model) SmartFlow. Where the SDK's SmartFlow
routes one flow across model providers, SuperAICore's routes one flow across the
**CLIs/backends** it already manages, so different CLIs collaborate on one task.
Additive and non-breaking: the Dispatcher, AgentSpawn, and the
Squad/Team/Smart/Auto orchestrators are untouched. New module `src/SmartFlow/`,
new command `superaicore flow`, new docs `docs/smartflow.md`.

- **One flow, many CLIs** *(1.0.5)* — the same primitives
  (`agent()` / `parallel()` / `pipeline()` / `gate()` / `council()` / `budget` /
  `schema` / `SKIP`) drive any registered backend, so one flow can plan on
  `claude_cli` and review on `codex_cli` + `gemini_cli` concurrently. `backend`
  is the cross-CLI knob on every step; reusable `personas` carry the system
  prompt and can pin a backend/model.
- **3-layer structured-output safety net** *(1.0.5)* — CLIs return prose, so a
  `schema` is baked into the prompt and recovered through native → fenced
  ```` ```json ```` → regex-sniffed layers, validated by a dependency-free
  `SchemaValidator`; total failure yields a `SKIP` sentinel instead of a crash.
- **Resume + call-ledger** *(1.0.5)* — every run writes a JSONL ledger under
  `~/.superaicore/flows`; `--resume <id>` replays the longest unchanged prefix
  from cache at zero cost (content-addressed signatures; gates stay aligned).
- **True parallelism** *(1.0.5)* — `parallel()` / `pipeline()` batches run as
  concurrent `bin/flow-agent-runner.php` subprocesses (`proc_open` +
  `stream_select`, Windows polling fallback), degrading to in-process when
  unavailable.
- **Zero-cost rehearsal** *(1.0.5)* — `flow run --rehearse` runs any flow
  end-to-end with no CLI invoked (deterministic schema-conforming stubs), so
  flows are testable on a bare machine; every built-in flow rehearses green.
- **Federation with superagent** *(1.0.5)* — `Flow::delegate()` (and
  `strategy: delegate` in YAML) hands a sub-flow to superagent's cross-model
  SmartFlow: **named** mode runs one of superagent's own flows so it
  self-dispatches across providers; **spec** mode runs a flow whose structure
  SuperAICore authored, so superagent executes to instruction. Delegated spend
  federates into the parent budget; the whole nested run rehearses at zero cost.
- **4 built-in cross-CLI flows + YAML authoring** *(1.0.5)* — `cross-cli-review`,
  `cross-cli-dev`, `cross-cli-council`, and `cross-cli-federated` (which
  delegates research to superagent), compiled by `YamlFlowLoader`; drop your own
  under `./flows` or `./.superaicore/flows`. Config block
  `super-ai-core.smartflow.*`.

### jcode companion-tools wave (0.9.0 / SDK 0.9.7)

Five jcode-borrowed primitives shipped in SuperAgent SDK 0.9.7 and surfaced
in SuperAICore 0.9.0. Each is opt-in via env flag and degrades to no-op when
its host wiring is absent — pre-0.9.7 behaviour preserved verbatim unless
you flip the corresponding switch. SDK constraint moves to `^0.9.7`.

- **`agent_grep` tool — default ON** *(0.9.0)* — `SuperAgentBackend` auto-prepends `'agent_grep'` to `load_tools` when callers don't supply one (`AI_CORE_TOOLS_AGENT_GREP=true` is the default). The tool injects enclosing-symbol context (PHP/JS/TS/Py/Go) into every grep hit and truncates chunks the agent has already seen this session. Strict superset of `grep`; only fires on dispatches that actually opt into a tool-using agentic loop. Set `AI_CORE_TOOLS_AGENT_GREP=false` for byte-identical pre-0.9.7 behaviour.
- **`browser` tool wiring** *(0.9.0)* — `AI_CORE_TOOLS_BROWSER=true` makes `SuperAgentBackend` instantiate SDK 0.9.7's `FirefoxBridgeTool` (drives Firefox / Chromium via Native Messaging) and `Agent::addTool()` it. Requires `SUPERAGENT_BROWSER_BRIDGE_PATH` to point at the launcher; without it every action returns an explanatory error so the agent stops looping.
- **`BrowserScreenshotStore` round-trip** *(0.9.0)* — when the `browser` tool emits a base64 PNG, `SuperAgentBackend` writes it to `BrowserScreenshotStore` keyed by `process_id` / `external_label` / `metadata.session_id` and surfaces the URL on the dispatch envelope as `latest_screenshot_url`. `AiProcessSource` reads `latest()` against the row's `external_label` (then composite id) when constructing `ProcessEntry`, and `purgeFor()`'s those keys on reap. End-to-end loop closes without host-side glue. Configurable disk + dir via `super-ai-core.browser_screenshots`.
- **`SemanticSkillReranker` via `EmbeddingProvider` SPI** *(0.9.0)* — the optional second pass over `SkillRanker`'s BM25 top-N now resolves a SuperAgent SDK 0.9.7 `EmbeddingProvider` through `EmbeddingProviderFactory` (`super-ai-core.embeddings.{provider,callback,ollama_url}`). Reranker, the SDK's own `SemanticSkillRouter`, and any host-supplied `OnnxEmbeddingProvider` share one container singleton + one cache. Per-row failure (`[]` vector) keeps the BM25 score for that hit instead of bailing the whole call. Falls back to BM25 ordering when no embedder is configured.
- **`usage_source` cost-attribution split** *(0.9.0)* — `Dispatcher::resolveUsageSource()` promotes `options['usage_source']` / `options['metadata']['usage_source']` to a top-level `metadata.usage_source` key (default `'user'`). `/usage` gains a "By Source" card with an "N ambient · $X" badge so SuperAgent's `AmbientWorker`-tagged dedup/staleness ticks are visible at a glance without re-instrumenting host cost code.
- **Cross-harness session resume** *(0.9.0)* — `HarnessSessionResolver` wraps SDK 0.9.7's `Conversation\HarnessImporter` family (`ClaudeCodeImporter` reads `~/.claude/projects/<hash>/<uuid>.jsonl`, `CodexImporter` reads `~/.codex/sessions/**/*.jsonl`). `/processes` gains a "Resume from…" dropdown + transcript modal gated by `super-ai-core.resume.enabled`. Hosts wire `super-ai-core.resume.on_load` (callable) to actually re-dispatch into a backend; otherwise the modal shows the transcript inline for inspection.

Full recipes (Ollama embedder wiring, browser launcher setup, ambient
worker tick loop, harness resume callback): [docs/advanced-usage.md
§17–§21](docs/advanced-usage.md).

### DeepSeek-TUI parity wave (0.9.1 / SDK 0.9.8)

Five SDK 0.9.8 companion bindings landed in SuperAICore 0.9.1, plus one
backend hardening fix. SDK constraint moves to `^0.9.8`. None of the new
SDK pieces (`Goals\GoalManager`, `Security\UntrustedInput`,
`Swarm\AgentDepthGuard`, `Providers\Transport\TokenBucket`,
`Conversation\Fork`, `Memory\AdHocMemoryProvider`, the DeepSeek V4
Interleaved-Thinking enforcer, `Routing\AutoModelStrategy`,
`Context\Strategies\CacheAwareCompressor`) change SDK call shapes —
they're additive and opt-in.

- **`Goals\EloquentGoalStore` + `AiGoal` model + migration** *(0.9.1)* — durable backing for SDK 0.9.8's `Goals\Contracts\GoalStore` SPI. Each thread can hold at most one row in non-terminal status (`active` / `paused` / `budget_limited`); paused goals stay paused after the host process restarts. The service provider binds `GoalStore::class → EloquentGoalStore::class` and registers `GoalManager` as a singleton, so `app(GoalManager::class)` resolves with the durable store auto-injected. Hosts that already keep goals in their own table swap in their own `GoalStore` implementation — no fork. Run `php artisan migrate` to pick up the `ai_goals` table; if you don't use `Goals\GoalManager` the binding stays inert.
- **Three-tier approval gate** *(0.9.1)* — `Runner\ApprovalMode` (`Auto` / `Suggest` / `Never`) + `ApprovalGate` + `ApprovalDecision` mirror codex's `/permissions` command. Read-only allowlist (`agent_grep` / `agent_glob` / `agent_read` / `agent_ls` / `web_search` / `web_fetch` / `agent_get_goal`) flows through every mode. Mutations in `Suggest` return `canRetry: true` with code `mutation_pending_approval` (or `destructive_pending_approval` when the existing `Guidance\Gates\DestructiveCommandScanner` flags the call); a single-use `tool_use_id` override token unblocks one retry — the codex `/approve` flow ported to API shape. `Auto` mode lets ordinary mutations through but still pauses for `/approve` on destructive ops; `Never` is read-only. Resolve via `app(ApprovalGate::class)`.
- **`Plugins\WorkspacePluginRegistry`** *(0.9.1)* — codex's "workspace plugin sharing" pattern. A team checks `.superaicore/workspace-plugins.json` into the repo; the registry diffs against locally-installed plugin names and returns `missing_required` (scope=`workspace`, must install for everyone) vs `missing_recommended` (scope=`user`, informational). `git clone` puts new hires on the team's full toolset without a per-machine onboarding doc. Bound as a singleton over `base_path()`.
- **Headless `GET /v1/usage` JSON endpoint** *(0.9.1)* — `Http\Controllers\UsageApiController` mirrors codex's app-server `/v1/usage` shape. One axis per request: `group_by=day | model | provider | thread | backend | task_type`. Same filters as the HTML controller (`model`, `task_type`, `user_id`, `backend`, `days`). Auth is the host's job — wrap the route group in your own middleware. Buckets carry `runs / cost_usd / shadow_cost_usd / input_tokens / output_tokens / cache_read_tokens / cache_hit_rate`.
- **`metadata.cache_hit_rate` on every usage row** *(0.9.1)* — `UsageRecorder` stamps `cache_hit_rate ∈ [0, 1]` whenever the row carries a non-zero cache slice. Denominator is the GROSS prompt (uncached input + cache reads) so dashboards can group by model / day / backend and average without re-deriving the denominator. Absent when no cache activity occurred — distinguishes "no cache eligible" from "0% hit rate". Also accepts the legacy `cache_hit_tokens` alias from DeepSeek V3 / R1 wires. The `/usage` page now answers "what fraction of my paid prompt was free this period?" — same question DeepSeek-TUI asks at turn-end, just aggregated. New `total_cache_read_tokens` summary card.

Full recipes (goal store override, approval gate wiring, workspace
plugin manifest, `/v1/usage` cookbook, cache-hit-rate dashboards):
[docs/advanced-usage.md §22–§26](docs/advanced-usage.md).

### TaskRunner reliability wave (0.9.2)

Long operator tasks can now fail over between backends when the primary
CLI/API hits a quota or rate-limit wall. 0.9.2 treats this as a reliability
layer for TaskRunner: explicit/automatic chains, failure-context handoff,
attempt reporting, UI persistence hooks, and safe retry boundaries. Fallback
is per-run: the requested backend is always attempted first, so recovered
primaries take traffic again automatically.

- **Explicit chains** — pass `fallback_chain` as
  `['claude_cli', 'codex_cli', 'gemini_cli']`; TaskRunner prepends the
  requested backend when missing and de-dupes the chain.
- **Workload policies** — pass `fallback_profile` or rely on
  `task_type` / `capability` to resolve `chains_by_profile`,
  `chains_by_task_type`, or `chains_by_capability` from config.
- **Automatic chains** — `fallback_chain => 'auto'` builds the chain from
  registered/enabled backends, with optional availability checks via
  `AI_CORE_TASK_FALLBACK_CHECK_AVAILABILITY=true`.
- **Limit-aware handoff** — `fallback_on` defaults cover quota/rate-limit
  wording (`rate limit`, `usage limit`, `quota`, `429`,
  `too many requests`, `usage_not_included`). Non-matching failures stop
  on the original backend.
- **Failure-context inheritance** — the next backend receives the original
  prompt plus a compact failure/log excerpt unless
  `inherit_failure_context=false`.
- **`TaskResultEnvelope::$fallbackReport`** records every attempt
  (backend, attempt number, success, exit code, model, log file, error).
- **Workload-specific policy** — hosts can keep different chains for coding,
  research/summarisation, and background maintenance instead of using one
  global retry rule for every task type.
- **Operator observability** — the compact report and per-attempt Dispatcher
  metadata can be stored on task rows or usage rows and rendered as "primary
  limited, continued on codex" with direct links to per-attempt logs.
- **Reliability analytics** — combine `fallbackReport` with
  `ai_usage_logs.backend` to find primaries that frequently hit quota and
  secondaries that actually complete work.
- **Safe rollout path** — start with per-call chains, promote stable policy
  into config, then enable automatic fallback only after backend availability
  and billing behaviour are understood.

Global defaults live under `super-ai-core.task_fallback`; env toggles are
`AI_CORE_TASK_FALLBACK_AUTO`, `AI_CORE_TASK_FALLBACK_CHAIN`,
`AI_CORE_TASK_FALLBACK_CHECK_AVAILABILITY`, and
`AI_CORE_TASK_FALLBACK_INHERIT_CONTEXT`. See
[docs/advanced-usage.md §27](docs/advanced-usage.md) and
[docs/task-runner-quickstart.md](docs/task-runner-quickstart.md).

### Squad multi-agent + SDK 1.0.0 wave (0.9.6)

SDK constraint moves to `^1.0`. SuperAICore 0.9.6 lands the SDK
1.0.0 `Squad` peer-collaboration pipeline as a tenth dispatcher
adapter and wraps the SDK 0.9.8 companion primitives
(`AutoModelStrategy`, `CacheAwareCompressor`, `UntrustedInput`,
`TokenBucket`, `AdHocMemoryProvider`, `Conversation\Fork`,
`AgentDepthGuard`, DeepSeek FIM) behind first-class host services so
they're addressable from any dispatch path. Every binding is additive
and opt-in — pre-0.9.6 behaviour is preserved unless you enable a
flag, pass a new option, or resolve a new service from the container.
No migrations.

- **`SquadBackend` — SDK 1.0.0 adaptive cross-model pipeline**
  *(0.9.6)* — registered as the tenth dispatcher adapter when
  `super-ai-core.squad.enabled=true` and the SDK 1.0.0 classes are on
  the classpath. Drives a heuristic-decomposed pipeline via
  `Squad\TaskDecomposer` + `Squad\PeerOrchestrator`, with one model
  per subtask (mapped through `Squad\ModelTierMap`), per-step
  `SquadCheckpointStore` writes, peer-to-peer messaging via SDK's
  `PeerMailbox`, and an optional cost cap with automatic downshift at
  80% budget. Mid-run failures leave the checkpoint on disk; resume
  by re-dispatching with the same `squad_id` and `checkpoint_dir`.
  Envelope carries `squad: {squad_id, step_count, completed, roles,
  checkpoint_path, mailbox_log}`. Tier map ships with sensible
  defaults (`trivial` → `claude-haiku-4-5`, `easy` →
  `deepseek-v4-flash`, `moderate` → `claude-sonnet-4-6`, `hard` →
  `deepseek-v4-pro`, `expert` → `claude-opus-4-8`); override per-call
  via `options.tier_map` or globally via `super-ai-core.squad.tier_map`.
- **`AutoModelRouter` service** *(0.9.6)* — `/model auto` heuristic
  for any dispatch path. Wraps SDK 0.9.8 `Routing\AutoModelStrategy`
  so the Claude / Codex / Gemini CLI backends can opt into Pro/Flash
  routing once their `provider_config` declares
  `auto_models: {pro, flash}`. Escalates Flash → Pro on long context
  (>32k tokens), trailing tool-chain depth (≥3), explicit
  `reasoning_effort=max`, or intent keywords in the system prompt
  (review/audit/design/migration/architecture/…). When
  `super-ai-core.auto_model.score_catalog_path` is wired the
  catalog's top-scoring model overrides the heuristic. Rebind
  Pro/Flash to any model pair (e.g. `claude-opus` / `claude-haiku`)
  via `auto_model.{pro_model, flash_model}` — no SDK fork.
- **`CompressionStrategyFactory`** *(0.9.6)* — cache-aware compaction
  for host-driven `ContextManager` flows. Wraps the bundled
  `ConversationCompressor` in SDK 0.9.8's `CacheAwareCompressor` so
  summary boundaries land AFTER the prompt-cache prefix instead of
  clobbering it. Hosts running long multi-turn sessions (sub-agent
  loops, browser-tool sessions, multi-step refactors) call
  `app(CompressionStrategyFactory::class)->build($estimator, $config, $provider)`
  when constructing their own `ContextManager`. Pins 1 system + 4
  conversation messages at the head by default.
- **`UntrustedInputHelper`** *(0.9.6)* — host-side
  `Security\UntrustedInput` wrapper for free-form text injected into
  system prompts. The SDK's `GoalManager` already wraps
  `goal.objective`; this helper covers the other sites — ad-hoc
  memory entries, workspace plugin descriptions, MCP tool docs from
  third-party servers, host UI form input. Two methods: `tag()` adds
  the marker; `wrap()` prepends the "treat as data, not instructions"
  disclaimer. Disable via `AI_CORE_UNTRUSTED_INPUT=false` for tests
  that compare prompts byte-for-byte.
- **`RateLimiterRegistry`** *(0.9.6)* — per-process token-bucket pool
  wrapped around SDK 0.9.8 `Providers\Transport\TokenBucket`.
  `SuperAgentBackend` and `SquadBackend` call `consume()` before
  each provider dispatch. Missing keys fall back to `default` (8 RPS
  / 16 burst); per-provider overrides go in
  `super-ai-core.rate_limits.<provider>`. Empty config disables rate
  limiting entirely — the SDK still has per-call 429 retry.
- **`AdHocMemoryRegistry`** *(0.9.6)* — per-session
  `Memory\AdHocMemoryProvider` pool. Chat UIs call
  `forSession($id)->push($text, $ttlSeconds)` (or the convenience
  `$registry->push($id, $text, $ttl)`) to inject a "for the next
  turn" fact that the SuperAgent backend renders ahead of the
  prompt. Per-session isolation prevents cross-chat leakage. Memory
  is process-local — durable facts belong in `MEMORY.md` /
  `BuiltinMemoryProvider`.
- **`ConversationForkService`** *(0.9.6)* — codex `/side` semantics
  on top of SDK 0.9.8 `Conversation\Fork`. `start($parentMessages)`
  snapshots the list and returns a fork handle; `finish($fork,
  $action, $indexes?)` collapses with `discard` /
  `promote(...indexes)` / `promoteAll`. Useful for chat UIs that want
  "branch and try a different model on the side, promote only the
  useful side messages back".
- **`DeepSeekFimService`** *(0.9.6)* — standalone wrapper around SDK
  0.9.8 `DeepSeekProvider::completeFim()` against the `beta` region.
  The chat-shaped `Backend` abstraction doesn't fit FIM, so hosts
  building IDE-style completion features call this service directly:
  `app(DeepSeekFimService::class)->complete($prefix, $suffix,
  ['max_tokens' => 64])`.
- **`reasoning_effort` three-tier dial on `SuperAgentBackend`**
  *(0.9.6)* — per-call `reasoning_effort: 'off' | 'high' | 'max'`
  forwarded as the SDK's `reasoning_effort` per-call option. Routes
  to the right body shape per upstream via SDK's
  `SupportsReasoningEffort` capability interface. Silently ignored
  by providers that don't implement it. Also feeds the
  `AutoModelRouter` escalation heuristic when set to `max`.
- **`Agent::switchProvider()` handoff** *(0.9.6)* — pass
  `options.handoff: {provider, config, policy}` and
  `SuperAgentBackend` calls `Agent::switchProvider()` before
  dispatch. Envelope gains `handoff_token_status: {tokens, window,
  fits, model}` so dashboards can warn "history won't fit under
  <target_model> — compress before the next turn". Failure to
  construct the new provider leaves the original agent untouched.
- **`smart` / `squad` console commands** *(0.9.6)* — passthrough to
  vendor `superagent smart` / `superagent auto --squad`. Reuse the
  operator's existing SuperAgent credentials and SDK CLI behaviour
  rather than re-implementing the orchestrator in PHP:
  ```bash
  ./vendor/bin/superaicore smart "audit this diff"
  ./vendor/bin/superaicore smart show --last
  ./vendor/bin/superaicore squad "refactor auth module" --max-cost=2.0
  ./vendor/bin/superaicore squad --no-squad "compare against legacy path"
  ```
- **`super-ai-core.agents.max_depth`** *(0.9.6)* — forwarded to SDK
  0.9.8 `Swarm\AgentDepthGuard::setMax()` during service-provider
  boot. Negative / unset preserves SDK default (5). Per-process
  override: `SUPERAGENT_MAX_AGENT_DEPTH` env var.

Full recipes (Squad pipelines, AutoModelRouter integration,
CacheAwareCompressor wiring, RateLimiterRegistry overrides,
AdHocMemoryRegistry chat-UI integration, ConversationForkService
side-panels, DeepSeek FIM completion endpoints):
[docs/advanced-usage.md §28](docs/advanced-usage.md).

### opencode-borrowed feature wave (0.9.7 / SDK 1.0.5)

SDK constraint moves `^1.0` → `^1.0.5`, picking up cross-provider
handoff transcoder fixes, opencode `BashArity` permission matching,
the opencode 7-section structured compactor summary, the SDK's real
LSP client (`LSPTool`), `LlmLoopChecker` semantic loop detection,
ACP v1 stdio server, and the Gemini 3.5 / 3.x family with thinking +
grounding + thought-part wiring. On top of that SDK bump, ten patterns
are ported from [opencode](https://github.com/sst/opencode)
(`packages/opencode/src/`) and surfaced as first-class SuperAICore
features. Run `php artisan migrate` after upgrading — 0.9.7 ships
three new tables and three new columns on `ai_usage_logs`.

- **Per-file diff summary on every dispatch** *(0.9.7)* —
  `SuperAgentBackend` snapshots the worktree before and after each
  call via the SDK's `GitShadowStore`, then `Services\SnapshotDiffService`
  produces a structured `{additions, deletions, files, diffs[]}`
  envelope where each diff carries `{file, additions, deletions,
  status, patch, truncated}`. Persisted on `ai_usage_logs.file_diff_summary`
  alongside the two snapshot hashes (`pre_snapshot`, `post_snapshot`).
  The `/usage` page renders a `+N −M` badge per row + a side-panel
  diff viewer. Modeled on opencode `session/summary.ts` +
  `snapshot.diffFull()`.
- **Mid-run HITL `ask_user` tool** *(0.9.7)* —
  `Services\Tools\AskUserTool` (opt-in via
  `AI_CORE_TOOLS_ASK_USER=true`) lets the agent interrupt and ask
  the operator a clarifying question with optional pre-defined
  choices. Rows land in the new `ai_user_questions` table and render
  as inline cards on `/processes` (polled every 4s). Modeled on
  opencode `tool/question.ts`. Endpoints:
  `/processes/questions{,/{id}/answer,/{id}/cancel}`.
- **Revert worktree to pre-dispatch snapshot** *(0.9.7)* —
  `POST /usage/{id}/revert` reads `pre_snapshot` off the UsageLog row
  and restores the worktree via SDK's `GitShadowStore::restore()`.
  Tracked files revert; untracked files left in place. Gated by
  `AI_CORE_SNAPSHOT_REVERT_ENABLED` (default true). The `/usage`
  page surfaces a ↩ button on every row that recorded a snapshot.
- **Shadow-git snapshot retention** *(0.9.7)* —
  `super-ai-core:snapshot-prune` Artisan command walks every
  `shadow.git` under `~/.superagent/history/`, expires old reflogs
  past `--days` (default 7), and runs `git gc --prune=now`. Supports
  `--dry-run`. Schedule from the host app's `Kernel.php` with
  `$schedule->command('super-ai-core:snapshot-prune')->daily()`.
  Modeled on opencode's `prune = "7.days"` policy.
- **Session reminders synthetic injection** *(0.9.7)* —
  `Services\RemindersResolver` reads
  `super-ai-core.reminders.rules` and prepends synthetic system-prompt
  blocks when a rule's `when` predicate (dotted-path → fnmatch globs)
  matches the dispatch options/metadata. Modeled on opencode
  `session/reminders.ts`.
- **Per-agent permission ruleset** *(0.9.7)* —
  `Services\PermissionEvaluator` ports opencode `permission/evaluate.ts`
  (`{permission, pattern, action}` rules with last-match-wins
  semantics, fnmatch wildcards, default `ask`). Configure per agent
  via `super-ai-core.agents.{name}.permission`; `SuperAgentBackend`
  projects the ruleset onto the SDK agent's `withAllowedTools()` /
  `withDeniedTools()` when the caller didn't pass explicit lists.
- **Plan mode (`Modes\CliPlanOrchestrator`)** *(0.9.7)* —
  three-phase plan → approve → build workflow. Phase 1 runs the model
  in plan-only mode (edit tools denied except for the plan file glob)
  and writes a markdown plan to `.superagent/plans/{session}.md`.
  Phase 2 opens an `ai_user_questions` row asking the operator to
  `[Approve, Reject]`. Phase 3 hands off to the build backend with a
  synthetic prompt containing the approved plan. Registered with
  `CliModeRouter` under mode name `plan`. Auto-approves when HITL is
  disabled so the orchestrator stays usable in CI. Config:
  `super-ai-core.modes.plan.*`. Modeled on opencode `agent/agent.ts` +
  `tool/plan.ts`.
- **Sub-agent permission derivation** *(0.9.7)* —
  `Services\SubagentPermissionDeriver` merges the parent agent's
  `denied_tools` set into the child's so a read-only parent always
  produces read-only children. Reads `options['parent_denied_tools']`
  (explicit) or `options['metadata']['parent_agent']` (resolved
  through `PermissionEvaluator`). Modeled on opencode
  `agent/subagent-permissions.ts`.
- **PTY long-lived shell sessions, Phase 1** *(0.9.7)* —
  `Services\PtyService` + `Http\Controllers\PtyController` spawn
  `proc_open`-backed shell sessions and stream stdout to clients via
  cursor-keyed long-poll. Endpoints:
  `POST /pty/sessions` (spawn), `GET /pty/sessions/{id}/poll?cursor=N`
  (poll), `POST /pty/sessions/{id}/kill` (terminate). Opt-in via
  `AI_CORE_PTY_ENABLED=true`. Phase 2 (deferred) upgrades the wire to
  WebSocket via Reverb / Soketi without changing the cursor protocol.
- **Session share host queue** *(0.9.7)* —
  `Services\ShareSessionService` mints a `{share_id, secret,
  share_url}` triple per session and POSTs the session's UsageLog
  rows + attached `file_diff_summary` payloads to a configured remote
  sharer (`AI_CORE_SHARE_REMOTE_URL`) with a Bearer token. Falls back
  to a local URL template (`AI_CORE_SHARE_LOCAL_URL_TEMPLATE`, with a
  `{share_id}` placeholder) when no remote is configured. Modeled on
  opencode `share/share-next.ts`.
- **SDK 1.0.5 LSP tool** *(0.9.7)* — opt in via
  `AI_CORE_TOOLS_LSP=true` and `SuperAgentBackend` prepends `lsp` to
  the implicit `load_tools` list so the agent gets the SDK's bundled
  LSP client (phpactor / intelephense / gopls / rust-analyzer /
  pyright / tsserver / clangd / bash-language-server / zls). Lazy via
  the SDK's `BuiltinToolRegistry` classMap.
- **Opencode structured compactor summary** *(0.9.7)* — set
  `AI_CORE_COMPRESSION_SUMMARY_PROMPT=structured` to opt every
  dispatch into the SDK's 7-section Markdown summary template
  (Goal / Constraints / Progress / Decisions / Next Steps / Critical
  Context / Relevant Files). ~30-50% smaller than the default
  9-section summary and preserves blocked-item state across
  compactions. Per-call `options['summary_prompt']` overrides.
- **Gemini 3.5 thinking + grounding + URL context** *(0.9.7)* —
  `thinking`, `grounding` / `google_search`, and `url_context` per-call
  options pass straight through to the SDK's `GeminiProvider` (silently
  ignored elsewhere). `EngineCatalog` now lists `gemini-3.5-pro /
  -flash / -flash-lite` for the gemini-cli engine; `CopilotModelResolver`
  gains a `gemini` family alias resolving to `gemini-3-pro-preview`.

Full recipes (per-file diff dashboard, AskUserTool integration, plan
mode workflow, session reminders, per-agent permissions, PTY sessions,
session sharing):
[docs/advanced-usage.md §29](docs/advanced-usage.md).

### Qwen + tracing + 9Router wave (0.9.8)

The eighth execution engine, an always-on Dispatcher trace ring
(`chrome://tracing`-viewable, auto-dumps on quota / null result /
auto-rotate), an OpenAI-compatible proxy at
`/super-ai-core/v1/chat/completions`, multi-account round-robin with
cooldowns, real SSE streaming on the three HTTP backends, pre-emptive
OAuth refreshers for Claude / Codex / Copilot / Kiro, Pi-style
session tree branching, a progressive-disclosure skill index for the
non-skill-native CLIs, a pi v3 JSONL exporter, and a `gh-watch`
GitHub PR / CI reaction engine. **SDK constraint bumped to `^1.0.6`** —
picks up the real `RtkPipeline` (6 built-in compressors), the
`Hooks\HookEvent::PR_EVENT` hook (fired automatically from
`gh-watch`), `Agent::steer()` / `followUp()` mid-turn control
(exposed via `SuperAgentBackend` options), and the `qwen-anthropic`
SDK provider (new `AiProvider::TYPE_QWEN_ANTHROPIC` for DashScope's
Anthropic-protocol endpoint — drop-in Claude substitute).

- **Qwen Code CLI as the 8th engine (`qwen_cli`)** *(0.9.8)* — fork of
  `gemini-cli` adapted for Alibaba's Qwen family. Implements
  `Backend`, `StreamingBackend`, and `ScriptedSpawnBackend` so it
  slots into every existing dispatch path. API key only
  (`DASHSCOPE_API_KEY` / `QWEN_API_KEY`); OAuth EOL'd 2026-04-15.
  Default model `qwen3.7-max` (1M context, $2.50/$7.50 per 1M, native
  Anthropic `/v1/messages` protocol — drop-in for Claude in fallback
  chains). Toggle via `AI_CORE_QWEN_CLI_ENABLED`.
- **Dispatcher trace ring (`Tracing\TraceCollector`)** *(0.9.8)* —
  always-on, lock-free ring of `llm` / `cache` / `provider` / `tool` /
  `error` events. ~150 KB at 1024 events; zero file-system cost when
  disabled. Auto-dumps to Chrome Trace Event JSON (viewable in
  `chrome://tracing`, `https://ui.perfetto.dev`, or the bundled
  `trace-viewer.html`) on `error` / `rotate` / `timeout` triggers.
  `SuperAgentBackend` auto-dumps with `trigger=rotate` for
  `quota_exceeded` / `usage_not_included` / `server_overloaded` /
  `cyber_policy` so the post-mortem captures the failing envelope.
  Manual flush: `php artisan dispatcher:dump-trace`. UI:
  `/super-ai-core/traces`.
- **OpenAI-compatible proxy** *(0.9.8)* —
  `Http\Controllers\OpenAiCompatibleController` exposes
  `GET /v1/models` + `POST /v1/chat/completions` (streaming + non-streaming).
  `model` accepts either a literal id or an `ai_routing_combos.name`,
  so Cursor / Cline / Roo / Kiro / continue.dev / the OpenAI SDK can
  drop in unchanged. Streaming chunks shaped exactly like OpenAI's.
- **Real SSE streaming on the three HTTP backends** *(0.9.8)* —
  `AnthropicApiBackend`, `OpenAiApiBackend`, `GeminiApiBackend`
  implement the new `Contracts\StreamableTextBackend` interface,
  yielding canonical envelopes (`{type:'text'|'thinking'|'tool_use_delta'|'usage'|'stop'}`).
  The OpenAI-compat proxy consumes these directly.
- **Named routing combos (`ai_routing_combos`)** *(0.9.8)* — ordered
  `[{provider, model}, ...]` resolved at dispatch time. Sits above
  the static `tier_map`. CRUD: `/super-ai-core/routing/combos[/{name}]`.
  Per-call override: `--combo=NAME` on `smart` / `squad` / `auto`.
- **Multi-account round-robin (`AccountRoundRobin`)** *(0.9.8)* —
  picks the active, non-cooled-down account with the lowest
  `(priority, last_used_at)` tuple via atomic compare-and-update.
  `cooldown()` marks accounts for 10 min on `QuotaExceededException`
  / null result. Backed by the new `ai_provider_accounts` table.
- **OAuth refresher registry** *(0.9.8)* — pre-emptive token refresh
  for the four CLIs that own OAuth state in on-disk JSON
  (Claude / Codex / Copilot / Kiro). Drive via
  `php artisan super-ai-core:oauth-refresh`; schedule from
  `app/Console/Kernel.php` with `->everyTenMinutes()`.
- **Pi-style session tree branching** *(0.9.8)* —
  `Services\SessionBranchManager` + `ai_session_branches` table.
  Forking creates a new branch from an old entry; switching
  auto-summarises the abandoned branch so context isn't lost.
  Endpoints: `/sessions/{session}/tree`, `/sessions/{session}/fork`,
  `/sessions/{session}/switch`.
- **Progressive-disclosure skill index** *(0.9.8)* —
  `Services\SkillIndexBuilder` emits a compact XML index of every
  `SKILL.md` (name + description, no body) that `CodexCliBackend` /
  `GeminiCliBackend` prepend to every prompt. The model reads the
  body via its existing file-read tool only when it picks a skill.
  Suppress with `options['skills_disabled']=true` or `--no-skills`.
- **Pi `kind` discriminator on `ask_user`** *(0.9.8)* — `select` /
  `confirm` / `input` / `editor` so the `/processes/questions` UI
  renders the right widget per call (default `select` preserves 0.9.7
  behaviour).
- **Caveman mode (`--caveman`)** *(0.9.8)* — output-token compression
  reminder ported from 9Router. Empirically saves 30-65% on output
  tokens for reasoning-quick tasks (not for long-form writing).
- **GitHub PR / CI watcher (`super-ai-core:gh-watch`)** *(0.9.8)* —
  claude-octopus pattern. Polls every active `ai_pr_watchers` row
  (ETag-cached), fires per-row actions (`ask_user` / `spawn_squad` /
  `webhook` / `log`). Schedule via `->everyFiveMinutes()` or daemonise
  with `--loop=30`.
- **Pi v3 session JSONL exporter** *(0.9.8)* —
  `php artisan task-results:export-jsonl` emits one file per
  `metadata.session_id`. Opt-in via `--i-understand` (the format is
  lossy); supports `--anonymize`, `--since`.
- **Apache Arrow tabular round-trip (`Arrow\ArrowSerializer`)** *(0.9.8)* —
  minimal IPC stream writer (no `apache/arrow` PECL dependency). Opt
  in per dispatch with `output_format: 'arrow'`; the envelope gains a
  base64-encoded Arrow stream. 10–100× faster than JSON for wide
  tabular agent payloads.
- **SuperTeam agents browser (`/super-ai-core/agents`)** *(0.9.8)* —
  reads `.claude/agents/*.md` from configurable roots and groups by
  category (Strategy / Product / Engineering / Business / Security / …).
  Config: `super-ai-core.agent_catalog.paths`.
- **SDK 1.0.6 wirings** *(0.9.8)* — four targeted wirings on top of
  the SDK bump: (1) `RtkCompressorService` now returns real byte
  savings out of the box (SDK ships six built-in compressors —
  git diff / grep / find / ls / tree / Bash); (2) `GhWatchCommand`
  fires `Hooks\HookEvent::PR_EVENT` with a `PrWatchHookData` payload
  on every event, so SDK hook listeners observe the same stream as
  the local action handler; (3) `SuperAgentBackend` accepts a
  `follow_up_queue` array option (pre-seeds the agent's follow-up
  queue) and an `on_agent_built: fn(Agent)` callback (lets a sibling
  process register the running agent against a session-keyed broker
  so HTTP / ACP `session/steer` can call `Agent::steer()` mid-run);
  (4) new `AiProvider::TYPE_QWEN_ANTHROPIC` provider type backed by
  SDK 1.0.6's `QwenAnthropicProvider` — Qwen 3.7 Max via DashScope's
  Anthropic-protocol endpoint, drop-in Claude substitute.

Full recipes (Qwen CLI install, tracing viewer setup, OpenAI proxy
client setup, routing combo CRUD, multi-account onboarding, OAuth
refresher schedule, session branch forking, gh-watch row schema,
SDK 1.0.6 wirings): [docs/advanced-usage.md §30](docs/advanced-usage.md).

### kimi-cli + kimi-code dual-CLI wave (1.0.2 / SDK 1.0.10)

Moonshot shipped `@moonshot-ai/kimi-code` (a TypeScript rewrite) to replace the
legacy Python `MoonshotAI/kimi-cli`. Both publish the same `kimi` binary but
expose an incompatible headless surface, so 1.0.2 makes the `kimi_cli` backend
straddle the transition — and takes the SDK pin to `^1.0.10`. Additive — no
schema changes, no migrations, no config publish; the `kimi_cli` Dispatcher
backend id is unchanged.

- **Dual-dialect `kimi_cli` backend** *(1.0.2)* — `KimiCliBackend` auto-detects
  which `kimi` is installed via a cached one-shot `kimi --help` probe (legacy
  advertises a `--print` flag; kimi-code does not) and adapts argv across all
  four spawn paths. Legacy keeps `--print --output-format=stream-json
  --max-steps-per-turn N [--mcp-config-file F] --prompt …`; kimi-code uses
  `--prompt`-triggered print mode — no `--print`/`--yolo`, and no
  `--max-steps-per-turn` / `--mcp-config-file` / `-w` (config.toml-driven,
  unknown options hard-rejected). Pin the dialect with
  `AI_CORE_KIMI_CLI_VARIANT` (`auto` default / `kimi-code` / `kimi-cli`).
- **Tolerant stream-json parsing** *(1.0.2)* — the parser accepts both wire
  shapes: assistant `content` as a plain string (kimi-code) or an array of
  typed `text`/`think` blocks (legacy), and treats the new
  `{"role":"meta","type":"session.resume_hint",…}` line as trace. Robust even
  if detection guesses wrong.
- **SDK `^1.0.9` → `^1.0.10`** *(1.0.2)* — Kimi/Moonshot HTTP-path hardening,
  generalized to every OpenAI-compatible provider and reaching the `superagent`
  backend transparently: streaming `usage` accounting restored
  (`stream_options.include_usage` — no more silently-zeroed token/cost/cache on
  streamed `kimi` / `qwen` / `glm` / `deepseek` / `grok` / `openrouter` /
  `openai` calls), strict tool-schema normalization (MCP / Skill / Agent tools
  survive Moonshot's validator), `max_completion_tokens` for Kimi reasoning
  models, and per-model capability discovery. New opt-in
  `SUPERAGENT_KIMI_SWARM_ENABLED` gate.
- **Unchanged surfaces** *(1.0.2)* — the `kimi_cli` backend id, `/providers`
  engine card, model pickers, `cli:status`, cost dashboard, and Process Monitor
  need nothing; only the underlying CLI dialect adapts. (Agent-sync parity for
  kimi-code's `.agents/` model is a tracked follow-up.)

Full recipes (variant detection + override, the kimi-cli/kimi-code flag matrix,
SDK 1.0.10 transparent fixes): [docs/advanced-usage.md §31](docs/advanced-usage.md)
and `docs/kimi-cli-backend.md` §8.

### Opus 4.8 + Grok + Cursor wave (1.0.0 / SDK 1.0.9)

The 1.0.0 stable cut takes SDK `^1.0.9` and lands the Opus 4.8 generation,
xAI Grok on two channels, and two new subscription CLI engines. Additive —
no schema changes, no migrations, no config publish.

- **Claude Opus 4.8 flagship** *(1.0.0)* — SDK 1.0.9 promotes
  `claude-opus-4-8` to the Anthropic flagship: it takes the `opus` alias,
  native 1M context, interleaved thinking, fast mode, effort control, and
  dynamic-workflow / agent-orchestration support, at the Opus tier
  ($15 / $75 per 1M). `ClaudeModelResolver` resolves `opus → claude-opus-4-8`
  and lists `claude-opus-4-8` / `claude-opus-4-8[1m]` first; the `claude`
  engine catalog, `model_pricing`, and the `squad` / `cli_squad` **expert**
  tiers all point at 4.8.
- **xAI Grok API provider (`grok` type)** *(1.0.0)* — first-class provider
  type routed through the `superagent` backend to SDK 1.0.9's `GrokProvider`
  (xAI's OpenAI-compatible `https://api.x.ai/v1`). `XAI_API_KEY` (canonical)
  with `GROK_API_KEY` aliased; default model `grok-4.3` (1M context).
  Surfaced in `ApiHealthDetector` (`api:status` + dashboard probe) and the
  cost catalog (grok-4.3 / grok-4-fast / grok-code-fast-1 / grok-3-mini).
- **Cursor Composer CLI (`cursor_cli`)** *(1.0.0)* — Cursor's headless
  `cursor-agent` (Composer 2.5). Subscription engine; `builtin` login at
  `~/.cursor`. Streaming + scripted-spawn + one-shot chat, Claude-Code-shaped
  JSON / stream-json parsing with token tracking, MCP via `.cursor/mcp.json`,
  `--force` headless tool-approval. Default model `composer-2.5-fast`.
- **Grok Build CLI (`grok_cli`)** *(1.0.0)* — xAI's `grok` "Grok Build"
  agentic CLI. Subscription engine; `builtin` login at `~/.grok`. Effort
  control (`--effort low…max` / `--reasoning-effort`), `--prompt-file`
  scripted spawn, native sub-agents. Default model `grok-build`. **Distinct
  from the metered Grok API provider type** — same brand, different channel.
- **Data-driven surfaces** — because `EngineCatalog`, `ProviderTypeRegistry`,
  and the per-engine model resolvers feed everything, the `/providers` UI
  (engine cards, builtin rows, add-provider dropdowns, version + login
  badges), model pickers, `cli:status`, the cost dashboard, the Process
  Monitor, and `McpManager` sync all pick up the new engines automatically.

Full recipes (Cursor / Grok CLI onboarding, Opus 4.8 routing, Grok API vs
CLI channel split, effort control):
[docs/advanced-usage.md §30](docs/advanced-usage.md).

### CLI installer & health

- **`cli:status`** — shows which engine CLIs are installed / logged in, plus install hints for anything missing.
- **`cli:install [backend] [--all-missing]`** — shells out to the canonical package manager (`npm` / `brew` / `script`) with confirmation by default. Explicit by design — no CLI ever auto-installs as a dispatch side-effect.
- **`api:status`** *(since 0.6.8)* — 5-second cURL probe against every direct-HTTP API provider (anthropic / openai / openrouter / gemini / kimi / qwen / glm / minimax). Returns `{ok, latency_ms, reason}` per provider so operators can tell auth rejections (401/403), network timeouts, and missing keys apart at a glance. `--all` / `--providers=a,b,c` / `--json` flags. Parallel sibling of `cli:status` for direct-HTTP providers.

### Dispatcher & streaming

- **Capability-based routing** — `Dispatcher::dispatch(['task_type' => 'tasks.run', 'capability' => 'summarise'])` resolves the right backend + provider credentials via `RoutingRepository` → `ProviderResolver` → fallback chain.
- **`Contracts\StreamingBackend`** *(since 0.6.6)* — every CLI backend streams chunks through an `onChunk` callback while tee'ing to disk and registering an `ai_processes` row for the Monitor UI. `Dispatcher::dispatch(['stream' => true, ...])` opts in transparently. Honours per-call `timeout` / `idle_timeout` / `mcp_mode` (`'empty'` for claude prevents global MCPs from blocking exit). See `docs/streaming-backends.md`.
- **`Runner\TaskRunner` — one-call task execution** *(since 0.6.6)* — drop-in wrapper around `Dispatcher::dispatch(['stream' => true, ...])` that returns a typed `TaskResultEnvelope` (success / output / summary / usage / cost / log file / spawn report / fallback report). Replaces ~150 lines of host-side "build prompt → spawn → tee log → extract usage → wrap result" glue with one call. 0.9.2 adds the TaskRunner reliability wave: opt-in backend fallback, continuation context, attempt observability, and workload-specific retry policy. Identical API across all 6 CLIs. See `docs/task-runner-quickstart.md`.
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

- **Real agentic loop** *(since 0.6.8)* — `SuperAgentBackend` honours `max_turns`, `max_cost_usd` → `Agent::withMaxBudget()`, `allowed_tools` / `denied_tools` filters, `mcp_config_file` (loads a `.mcp.json`, auto-disconnects in `finally{}`), and `provider_config.region` for Kimi / Qwen / GLM / MiniMax regions. Envelope gains `usage.cache_read_input_tokens`, `usage.cache_creation_input_tokens`, `cost_usd` (SDK turn-summed), and `turns`.
- **`AgentTool` productivity forwarded** *(since 0.6.8)* — when callers opt into SDK sub-agent dispatch (`load_tools: ['agent', …]`), the envelope forwards `AgentTool` productivity info (`filesWritten`, `toolCallsByName`, `productivityWarning`, `status: completed|completed_empty`) under an optional `subagents` key.
- **Three 0.9.0 options forwarded** *(since 0.6.9)* — `extra_body` (deep-merged at the top level of every `ChatCompletionsProvider` request body), `features` (routed through SDK's `FeatureDispatcher`; useful keys: `prompt_cache_key.session_id`, `thinking.*`, `dashscope_cache_control`), `loop_detection: true|array` (wraps streaming handler in `LoopDetectionHarness`). Convenience shim: `prompt_cache_key: '<sessionId>'` accepted as session-id shorthand.
- **Classified `ProviderException` subclasses** *(since 0.7.0)* — `SuperAgentBackend::generate()` catches six typed SDK subclasses (`ContextWindowExceeded`, `QuotaExceeded`, `UsageNotIncluded`, `CyberPolicy`, `ServerOverloaded`, `InvalidPrompt`) each logged with a stable `error_class` tag + `retryable` flag. Contract unchanged (still returns `null`); a `logProviderError()` seam lets subclasses route on the classification.
- **`createForHost` host-config adapter migration complete** *(since 0.8.5)* — `SuperAgentBackend::buildAgent()` collapses to a single `ProviderRegistry::createForHost($sdkKey, $hostConfig)` call instead of branching on `region` and hand-rolling the constructor shape per provider. The SDK-side per-key adapter (default for ChatCompletions-style; dedicated one for `bedrock` that splits AWS credentials; built-in Azure auto-detection on `openai-responses`; LMStudio synthetic auth) owns the constructor-shape mapping. Future SDK provider keys land here without backend changes — the adapter is the extension point.
- **SDK pinned to 0.9.5** *(since 0.8.5)* — Composer constraint `^0.9.5`. Multi-turn tool-use replays against non-Anthropic providers now work correctly (pre-0.9.5, `ChatCompletionsProvider::convertMessage()` early-returned on the first `tool_use` block and read nonexistent `ContentBlock` properties — every replayed tool call against Kimi / GLM / MiniMax / Qwen / OpenAI / OpenRouter / LMStudio went out as `{id: null, name: null, arguments: "null"}`); the SDK's six-encoder `Conversation\Transcoder` puts every wire family behind one canonical converter so the bug fix lands in one place. Plus `Agent::switchProvider($name, $config, $policy)` is available for in-process mid-conversation handoff (with `HandoffPolicy::default() / preserveAll() / freshStart()` presets) — useful for hosts that wrap `SuperAgentBackend` directly.

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
- `qwen` CLI on `$PATH` *(since 0.9.8)* — `npm i -g @qwen-code/qwen-code` (then export `DASHSCOPE_API_KEY` — OAuth EOL'd 2026-04-15)
- An Anthropic / OpenAI / Google AI Studio / DashScope API key for the HTTP backends

Don't want to remember package names? Run `./vendor/bin/superaicore cli:status` to see what's missing and `./vendor/bin/superaicore cli:install --all-missing` to bootstrap everything (confirmation prompt by default).

## Install

```bash
composer require forgeomni/superaicore
php artisan vendor:publish --tag=super-ai-core-config
php artisan vendor:publish --tag=super-ai-core-migrations
php artisan migrate
```

Upgrading from 0.9.7? Just `composer update forgeomni/superaicore` and
`php artisan migrate` — five additive migrations land in 0.9.8 (`kind`
column on `ai_user_questions`, plus four new tables:
`ai_session_branches`, `ai_routing_combos`, `ai_provider_accounts`,
`ai_pr_watchers`). Re-publish the config to pick up the new
`tracing.*`, `agent_catalog.*`, and `backends.qwen_cli.*` blocks:

```bash
php artisan vendor:publish --tag=super-ai-core-config --force
```

Optional cron entries (host's `app/Console/Kernel.php`):

```php
$schedule->command('super-ai-core:snapshot-prune')->dailyAt('02:00');   // 0.9.7
$schedule->command('super-ai-core:oauth-refresh')->everyTenMinutes();   // 0.9.8
$schedule->command('super-ai-core:gh-watch')->everyFiveMinutes();       // 0.9.8
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
./vendor/bin/superaicore call "Hello" --backend=qwen_cli --api-key=sk-...                # Alibaba Qwen Code CLI (0.9.8+)
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

### SmartFlow — cross-CLI workflows (1.0.5)

```bash
# List / inspect cross-CLI flows
./vendor/bin/superaicore flow list
./vendor/bin/superaicore flow show cross-cli-review

# Rehearse end-to-end at ZERO cost (no CLI invoked — deterministic stubs)
./vendor/bin/superaicore flow run cross-cli-review --args diff=@my.diff --rehearse

# Run for real: Claude summarizes, Codex + Gemini review in parallel, Claude decides
./vendor/bin/superaicore flow run cross-cli-review --args diff=@my.diff --concurrency 4

# Federated: plan on Claude, DELEGATE research to superagent's cross-model flow, build/review on CLIs
./vendor/bin/superaicore flow run cross-cli-federated --args goal="add caching" --args research_provider=openai

# Resume a prior run — the unchanged prefix replays from cache, zero cost
./vendor/bin/superaicore flow run cross-cli-dev --args goal="add caching" --resume <runId>
```

Also available as `php artisan flow ...` inside a Laravel host. See
[docs/smartflow.md](docs/smartflow.md) for the primitives, YAML authoring, and
the superagent federation (`delegate` named/spec modes).

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

# …or, in a Laravel host with a SkillLibrary bound, do all of the above generically (1.0.6+):
php artisan superaicore:sync-cli                              # skills + MCP → every installed CLI
php artisan superaicore:sync-cli --skills-only --backends=codex,gemini

# Run the same task across N Copilot agents in parallel
./vendor/bin/superaicore copilot:fleet "refactor auth" --agents planner,reviewer,tester
```

### Skill engine — telemetry / ranking / evolution (artisan, since 0.8.6)

Mounted on Laravel artisan via the package service provider — invoke with `php artisan` from any host:

```bash
# Hook targets — read Claude Code hook payload on stdin
php artisan skill:track-start --json     # PreToolUse(Skill) — insert in_progress row
php artisan skill:track-stop  --json     # Stop — close session's open rows

# Read the table
php artisan skill:stats --since=7d --sort=failure_rate
php artisan skill:stats --skill=research --format=json

# Rank skills against a task description (BM25 + telemetry boost)
php artisan skill:rank "estimate effort for an outsource project"
php artisan skill:rank "重构认证模块" --no-telemetry --format=json

# Queue a FIX-mode candidate (review-only — never auto-applied)
php artisan skill:evolve --skill=research                          # manual trigger
php artisan skill:evolve --skill=research --dispatch               # also invoke LLM (costs tokens)
php artisan skill:evolve --sweep --threshold=0.30 --min-applied=5  # all degraded skills

# Inspect the queue
php artisan skill:candidates                                       # list pending
php artisan skill:candidates --id=42 --show-prompt --show-diff     # full detail
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

Returns a typed `TaskResultEnvelope` with `success` / `output` / `summary` / `usage` / `costUsd` / `shadowCostUsd` / `billingModel` / `logFile` / `usageLogId` / `spawnReport` / `fallbackReport` / `error`. Identical API across all 6 CLI engines.

Add fallback for quota/rate-limit failures:

```php
$envelope = app(TaskRunner::class)->run('claude_cli', $prompt, [
    'fallback_chain' => ['claude_cli', 'codex_cli', 'gemini_cli'],
    'fallback_on' => ['rate limit', 'usage limit', 'quota', '429'],
    'inherit_failure_context' => true,
]);
```

When fallback is active, `$envelope->fallbackReport` contains the attempted
backend chain and final failure/success state.

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
  Qwen Code CLI   ────────▶ dashscope-api            ────▶ qwen_cli      (0.9.8+)
  SuperAgent SDK  ────────▶ anthropic(-proxy) /      ────▶ superagent
                            openai(-compatible) /
                            openai-responses /       (0.7.0+)
                            lmstudio                 (0.7.0+)

  Dispatcher ← BackendRegistry   (owns the 11 adapters above)
             ← ProviderResolver  (active provider from ProviderRepository)
             ← RoutingRepository (task_type + capability → service)
             ← AccountRoundRobin (multi-account picker with cooldowns, 0.9.8+)
             ← TraceCollector    (magic-trace ring; auto-dumps on error/rotate, 0.9.8+)
             ← UsageTracker      (writes to UsageRepository)
             ← CostCalculator    (model pricing → USD)
```

All repositories are interfaces. The service provider auto-binds Eloquent implementations; swap them for JSON files, Redis, or an external API without touching the dispatcher.

## Advanced usage

- **[Advanced usage guide](docs/advanced-usage.md)** — idempotency round-trip, W3C trace context, classified provider exceptions, `openai-responses` + Azure OpenAI + ChatGPT OAuth, LM Studio, `http_headers` / `env_http_headers` overrides, SDK features (`extra_body` / `features` / `loop_detection`), `ScriptedSpawnBackend` host migration, skill engine telemetry / BM25 ranker / FIX-mode evolution (0.8.6+), the **0.9.0 jcode wave**, the **0.9.1 DeepSeek-TUI parity wave**, the **0.9.2 TaskRunner reliability wave**, the **0.9.6 Squad multi-agent + SDK 1.0.0 wave**, the **0.9.7 opencode-borrowed wave**, the **0.9.8 Qwen + tracing + 9Router wave**, and the **1.0.5 SmartFlow cross-CLI wave**.
- **[SmartFlow — cross-CLI workflows](docs/smartflow.md)** *(1.0.5)* — the multi-CLI port of Claude Code's `Workflow`: the `agent`/`parallel`/`pipeline`/`gate`/`council`/`budget`/`schema` primitives, YAML authoring, the 3-layer structured-output ladder, resume + call-ledger, zero-cost rehearsal, and **federation with superagent's cross-model SmartFlow** (`delegate` named/spec modes).
- **[Cookbook](examples/cookbook/README.md)** *(0.9.8+)* — five gs-quant-style narrative examples: dispatcher basics, prompt caching, provider rotation, cross-harness resume, tracing quickstart.
- **[Commercialization tiers](docs/commercialization-tiers.md)** *(0.9.8+)* — reference doc for how a tiered offering (Cloud Dashboard / Managed Dispatcher / Enterprise overlays) could look on top of the MIT core. Nothing in this doc is implemented today.
- **[Supply chain policy](SUPPLY_CHAIN.md)** *(0.9.8+)* — no Composer lifecycle scripts, `composer install --no-scripts` default, weekly `composer audit`.
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
