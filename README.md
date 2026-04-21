# forgeomni/superaicore

[![tests](https://github.com/ForgeOmni/SuperAICore/actions/workflows/tests.yml/badge.svg)](https://github.com/ForgeOmni/SuperAICore/actions/workflows/tests.yml)
[![license](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![php](https://img.shields.io/badge/php-%E2%89%A58.1-blue.svg)](composer.json)
[![laravel](https://img.shields.io/badge/laravel-10%20%7C%2011%20%7C%2012-orange.svg)](composer.json)

[English](README.md) · [简体中文](README.zh-CN.md) · [Français](README.fr.md)

Laravel package for unified AI execution across six execution engines: **Claude Code CLI**, **Codex CLI**, **Gemini CLI**, **GitHub Copilot CLI**, **AWS Kiro CLI**, and **SuperAgent SDK**. Ships with a framework-agnostic CLI, a capability-based dispatcher, MCP server management, usage tracking, cost analytics, and a complete admin UI.

Works standalone in a fresh Laravel install. The UI is optional and fully overridable, so it can be embedded inside a host application (e.g. SuperTeam) or disabled entirely when only the services are needed.

## Relationship to SuperAgent

`forgeomni/superaicore` and `forgeomni/superagent` are **sibling packages, not a parent and a child**:

- **SuperAgent** is a minimal in-process PHP SDK that drives a single LLM tool-use loop (one agent, one conversation).
- **SuperAICore** is a Laravel-wide orchestration layer — it picks a backend, resolves provider credentials, routes by capability, tracks usage, calculates cost, manages MCP servers, and ships an admin UI.

**SuperAICore does not require SuperAgent to function.** SuperAgent is one of several backends. The CLI engines (Claude / Codex / Gemini / Copilot / Kiro) and the HTTP backends (Anthropic / OpenAI / Google) work without it, and the `SuperAgentBackend` gracefully reports itself as unavailable (`class_exists(Agent::class)` check) when the SDK is absent. If you don't need SuperAgent, set `AI_CORE_SUPERAGENT_ENABLED=false` in your `.env` and the Dispatcher falls back to the remaining backends.

The `forgeomni/superagent` entry in `composer.json` is there so the SuperAgent backend compiles out of the box. If you never use it, you can safely remove it from `composer.json` before `composer install` in your host app — nothing else in SuperAICore imports the SuperAgent namespace.

## Features

- **Skill & sub-agent runner** — discovers Claude Code skills (`.claude/skills/<name>/SKILL.md`) and sub-agents (`.claude/agents/<name>.md`) and exposes them as CLI subcommands (`skill:list`, `skill:run`, `agent:list`, `agent:run`). Runs on Claude out of the box; optionally on Codex/Gemini/Copilot with compatibility probe, tool-name translation, backend preamble injection, and a side-effect-locking fallback chain. `gemini:sync` mirrors skills/agents into Gemini custom commands; `copilot:sync` mirrors agents into `~/.copilot/agents/*.agent.md` (or runs automatically before `agent:run --backend=copilot`); `copilot:sync-hooks` merges Claude-style hooks into Copilot's config.
- **One-shot CLI installer** — `cli:status` shows which engine CLIs are installed / logged in + an install hint for anything missing; `cli:install [backend] [--all-missing]` shells out to the canonical package manager (`npm`/`brew`/`script`) with confirmation by default. Explicit by design — no CLI ever auto-installs as a dispatch side-effect.
- **Parallel Copilot fan-out** — `copilot:fleet <task> --agents a,b,c` runs the same task across N Copilot sub-agents concurrently, aggregates per-agent results, and registers each child in the Process Monitor.
- **Six execution engines** — Claude Code CLI, Codex CLI, Gemini CLI, GitHub Copilot CLI, AWS Kiro CLI, and SuperAgent SDK — unified behind a single `Dispatcher` contract. Each engine accepts a fixed set of provider types:
  - **Claude Code CLI**: `builtin` (local login), `anthropic`, `anthropic-proxy`, `bedrock`, `vertex`
  - **Codex CLI**: `builtin` (ChatGPT login), `openai`, `openai-compatible`
  - **Gemini CLI**: `builtin` (Google OAuth login), `google-ai`, `vertex`
  - **GitHub Copilot CLI**: `builtin` only (the `copilot` binary owns OAuth/keychain/refresh). Reads `.claude/skills/` natively (zero-translation skill pass-through). **Subscription billed** — costs are tracked separately from per-token engines on the dashboard.
  - **AWS Kiro CLI** (0.6.1+): `builtin` (local `kiro-cli login`), `kiro-api` (stored key injected as `KIRO_API_KEY` for headless mode). Ships the richest out-of-the-box CLI feature set — native agents, skills, MCP, and **subagent DAG orchestration** (no `SpawnPlan` emulation). Reads Claude's `SKILL.md` format verbatim. **Subscription billed** — credit-based Pro / Pro+ / Power plans.
  - **SuperAgent SDK**: `anthropic`, `anthropic-proxy`, `openai`, `openai-compatible`
- Engines fan out to internal Dispatcher adapters (`claude_cli`, `codex_cli`, `gemini_cli`, `copilot_cli`, `kiro_cli`, `superagent`, `anthropic_api`, `openai_api`, `gemini_api`) — CLI adapters when a provider uses `builtin` / `kiro-api`, HTTP adapters when it uses an API key. Operators rarely need to know this, but the adapters are addressable directly from the CLI if needed.
- **EngineCatalog single source of truth** — engine labels, icons, dispatcher backends, supported provider types, available models, and the declarative **`ProcessSpec`** (binary, version/auth-status args, prompt/output/model flags, default flags) live in one PHP service. Adding a new CLI engine means editing `EngineCatalog::seed()` and the providers UI, process monitor scan, disable-toggle table, and default CLI command shape all update automatically. The same catalog also drives host-app model dropdowns via `modelOptions($key)` / `modelAliases($key)` (0.5.9+), so hosts stop hand-rolling per-backend switches — a new engine's models appear in every picker for free. Host apps can override per-engine fields (including `process_spec`) via `super-ai-core.engines` config.
- **Dynamic model catalog** (0.6.0+) — `CostCalculator`, `ClaudeModelResolver`, `GeminiModelResolver`, and `EngineCatalog::seed()`'s `available_models` all fall through to SuperAgent's `ModelCatalog` (bundled `resources/models.json` + user override at `~/.superagent/models.json`). Running `superagent models update` (or the new `super-ai-core:models update`) refreshes pricing and model lists for every Anthropic / OpenAI / Gemini / Bedrock / OpenRouter row without a `composer update` or `vendor:publish`. Config-published prices and explicit `available_models` overrides stay authoritative.
- **Gemini OAuth shown on `/providers`** (0.6.0+) — `CliStatusDetector::detectAuth('gemini')` reads `~/.gemini/oauth_creds.json` via SuperAgent's `GeminiCliCredentials`, falls back to `GEMINI_API_KEY` / `GOOGLE_API_KEY`, and reports `{loggedIn, method, expires_at}` on the provider card the same way Claude Code / Codex do.
- **CliProcessBuilderRegistry** — assembles `argv` arrays from an engine's `ProcessSpec` (`build($key, ['prompt' => …, 'model' => …])`). Default builders cover all seeded engines; hosts call `register($key, $callable)` to swap in a custom shape without forking. Also exposes `versionCommand()` and `authStatusCommand()` for status detectors. Resolved as a singleton.
- **Provider / Service / Routing model** — map abstract capabilities (`summarize`, `translate`, `code_review`, ...) to concrete services, and services to provider credentials.
- **MCP server manager** — install, enable, and configure MCP servers from the admin UI.
- **Usage tracking** — every call persists prompt/response tokens, duration, and cost to `ai_usage_logs`. Rows also carry `shadow_cost_usd` + `billing_model` (0.6.2+) so subscription engines (Copilot, Kiro, Claude Code builtin) surface a meaningful pay-as-you-go USD estimate on the dashboard instead of a $0 row.
- **`UsageRecorder` for host-side runners** (0.6.2+) — thin façade over `UsageTracker` + `CostCalculator` that host apps spawning CLIs directly (e.g. `App\Services\ClaudeRunner`, PPT stage jobs, `ExecuteTask`) can call after each turn to drop one `ai_usage_logs` row with `cost_usd` / `shadow_cost_usd` / `billing_model` auto-filled from the catalog. Complement: `CliOutputParser::parseClaude()` / `::parseCodex()` / `::parseCopilot()` / `::parseGemini()` extracts the `{text, model, input_tokens, output_tokens, …}` envelope from captured stdout without constructing a full backend object.
- **Cost analytics** — per-model pricing table, USD rollups, dashboard with charts. "By Task Type" card + per-row `usage`/`sub` billing-model badge + shadow-cost column on every breakdown (0.6.2+). Dashboards hide 0-token rows and `test_connection` rows by default, and the `/providers` "Test" buttons now self-tag as `task_type=test_connection` so they no longer clutter the main view.
- **Process monitor** — inspect running AI processes, tail logs, terminate strays.
- **Trilingual UI** — English, Simplified Chinese, French, switchable at runtime.
- **Host-friendly** — disable routes/views, swap the Blade layout, or reuse the back-link + locale switcher inside a parent app.

## Requirements

- PHP ≥ 8.1
- Laravel 10, 11, or 12
- Guzzle 7, Symfony Process 6/7

Optional, only when the respective backend is enabled:

- `claude` CLI on `$PATH` for the Claude CLI backend — `npm i -g @anthropic-ai/claude-code`
- `codex` CLI on `$PATH` for the Codex CLI backend — `brew install codex`
- `gemini` CLI on `$PATH` for the Gemini CLI backend — `npm i -g @google/gemini-cli`
- `copilot` CLI on `$PATH` for the GitHub Copilot CLI backend — `npm i -g @github/copilot` (then run `copilot login`)
- `kiro-cli` on `$PATH` for the Kiro CLI backend — [install from kiro.dev](https://kiro.dev/cli/) (then `kiro-cli login`, or set `KIRO_API_KEY` for headless Pro/Pro+/Power)
- An Anthropic / OpenAI / Google AI Studio API key for the HTTP backends

Don't want to remember the exact package names? Run `./vendor/bin/superaicore cli:status` to see what's missing and `./vendor/bin/superaicore cli:install --all-missing` to bootstrap everything in one go (confirmation prompt by default).

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

# Drive the six engines from the CLI
./vendor/bin/superaicore call "Hello" --backend=claude_cli                              # Claude Code CLI (local login)
./vendor/bin/superaicore call "Hello" --backend=codex_cli                               # Codex CLI (ChatGPT login)
./vendor/bin/superaicore call "Hello" --backend=gemini_cli                              # Gemini CLI (Google OAuth)
./vendor/bin/superaicore call "Hello" --backend=copilot_cli                             # GitHub Copilot CLI (subscription)
./vendor/bin/superaicore call "Hello" --backend=kiro_cli                                # AWS Kiro CLI (subscription)
./vendor/bin/superaicore call "Hello" --backend=superagent --api-key=sk-ant-...         # SuperAgent SDK

# Skip the CLI wrapper and hit the HTTP APIs directly
./vendor/bin/superaicore call "Hello" --backend=anthropic_api --api-key=sk-ant-...      # Claude engine, HTTP mode
./vendor/bin/superaicore call "Hello" --backend=openai_api --api-key=sk-...             # Codex engine, HTTP mode
./vendor/bin/superaicore call "Hello" --backend=gemini_api --api-key=AIza...            # Gemini engine, HTTP mode
```

## Skill & sub-agent CLI

Claude Code skills (`.claude/skills/<name>/SKILL.md`) and sub-agents (`.claude/agents/<name>.md`) are auto-discovered from three sources for skills (project > plugin > user) and two for agents (project > user). Each becomes a first-class CLI subcommand:

```bash
# Discover what's installed
./vendor/bin/superaicore skill:list
./vendor/bin/superaicore agent:list

# Run a skill on Claude (default)
./vendor/bin/superaicore skill:run init

# Run a skill natively on Gemini — probe + translate + preamble
./vendor/bin/superaicore skill:run simplify --backend=gemini --exec=native

# Try Gemini first, fall back to Claude on incompatibility; hard-lock
# on whichever backend first writes to cwd
./vendor/bin/superaicore skill:run simplify --exec=fallback --fallback-chain=gemini,claude

# Run a sub-agent; backend inferred from its `model:` frontmatter
./vendor/bin/superaicore agent:run security-reviewer "audit this diff"

# Expose every skill/agent as a Gemini custom command
# (/skill:init, /agent:security-reviewer, …)
./vendor/bin/superaicore gemini:sync

# GitHub Copilot CLI: skills are zero-translation pass-through (Copilot reads
# .claude/skills/ natively). Agents auto-sync on agent:run; manual entry point:
./vendor/bin/superaicore copilot:sync                         # write ~/.copilot/agents/*.agent.md
./vendor/bin/superaicore agent:run reviewer "audit" --backend=copilot

# Run the same task across N Copilot agents in parallel
./vendor/bin/superaicore copilot:fleet "refactor auth" --agents planner,reviewer,tester

# Mirror your Claude-style hooks (.claude/settings.json:hooks) into Copilot
./vendor/bin/superaicore copilot:sync-hooks                   # writes ~/.copilot/config.json:hooks

# AWS Kiro CLI (0.6.1+): skills are zero-translation pass-through (Kiro reads
# .claude/skills/ natively); agents auto-translate to ~/.kiro/agents/<name>.json
# on agent:run --backend=kiro, then run under Kiro's native subagent DAG.
./vendor/bin/superaicore kiro:sync --dry-run                  # preview ~/.kiro/agents/*.json
./vendor/bin/superaicore agent:run reviewer "audit" --backend=kiro

# Bootstrap missing engine CLIs (explicit — never auto-installs)
./vendor/bin/superaicore cli:status                           # table of installed / version / auth / hint
./vendor/bin/superaicore cli:install --all-missing            # npm/brew/script install with confirmation

# Inspect or refresh the model catalog (0.6.0+)
./vendor/bin/superaicore super-ai-core:models status                     # sources, override mtime, total rows
./vendor/bin/superaicore super-ai-core:models list --provider=anthropic  # per-1M pricing + aliases
./vendor/bin/superaicore super-ai-core:models update                     # fetch $SUPERAGENT_MODELS_URL
```

Key behaviours:

- `--exec=claude` (default) — run on Claude regardless of `--backend`.
- `--exec=native` — run on `--backend`'s CLI. `CompatibilityProbe` flags `Agent`-tool skills on backends without sub-agent support; `SkillBodyTranslator` rewrites canonical tool names (`` `Read` `` → `read_file`, …) in explicit shapes and injects the backend preamble (Gemini / Codex). Bare prose like "Read the config" is left untouched.
- `--exec=fallback` — walk a chain; skip incompatible hops; **hard-lock** on the first hop that touches the cwd (mtime diff + stream-json `tool_use` events). Default chain is `<backend>,claude`.
- `arguments:` frontmatter is parsed (free-form / positional / named), validated, and rendered as structured `<arg name="...">` XML appended to the prompt.
- `allowed-tools:` frontmatter is passed through to `claude --allowedTools`; codex/gemini print a `[note]` since neither CLI has an enforcement flag.
- `gemini:sync` refuses to overwrite TOMLs you manually edited and recreates ones you deleted (tracked via `~/.gemini/commands/.superaicore-manifest.json`).

## PHP quick start

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
  SuperAgent SDK  ────────▶ anthropic(-proxy) /      ────▶ superagent
                            openai(-compatible)

  Dispatcher ← BackendRegistry   (owns the 9 adapters above)
             ← ProviderResolver  (active provider from ProviderRepository)
             ← RoutingRepository (task_type + capability → service)
             ← UsageTracker      (writes to UsageRepository)
             ← CostCalculator    (model pricing → USD)
```

All repositories are interfaces. The service provider auto-binds Eloquent implementations; swap them for JSON files, Redis, or an external API without touching the dispatcher.

## Admin UI

When `views_enabled` is true the package mounts these pages under the configured route prefix (default `/super-ai-core`):

- `/integrations` — providers, services, API keys, MCP servers
- `/providers` — per-backend credential & model defaults
- `/services` — task-type routing
- `/ai-models` — model pricing overrides
- `/usage` — call log with filtering
- `/costs` — cost dashboard
- `/processes` — live process monitor (admin only, disabled by default)

## Configuration

The published config (`config/super-ai-core.php`) covers host integration, locale switcher, route/view registration, per-backend toggles, default backend, usage retention, MCP directory, process monitor toggle, and per-model pricing. See inline comments for every key.

## License

MIT. See [LICENSE](LICENSE).
