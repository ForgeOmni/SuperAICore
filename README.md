# forgeomni/superaicore

[![tests](https://github.com/ForgeOmni/SuperAICore/actions/workflows/tests.yml/badge.svg)](https://github.com/ForgeOmni/SuperAICore/actions/workflows/tests.yml)
[![license](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![php](https://img.shields.io/badge/php-%E2%89%A58.1-blue.svg)](composer.json)
[![laravel](https://img.shields.io/badge/laravel-10%20%7C%2011%20%7C%2012-orange.svg)](composer.json)

[English](README.md) · [简体中文](README.zh-CN.md) · [Français](README.fr.md)

Laravel package for unified AI execution across four execution engines: **Claude Code CLI**, **Codex CLI**, **Gemini CLI**, and **SuperAgent SDK**. Ships with a framework-agnostic CLI, a capability-based dispatcher, MCP server management, usage tracking, cost analytics, and a complete admin UI.

Works standalone in a fresh Laravel install. The UI is optional and fully overridable, so it can be embedded inside a host application (e.g. SuperTeam) or disabled entirely when only the services are needed.

## Relationship to SuperAgent

`forgeomni/superaicore` and `forgeomni/superagent` are **sibling packages, not a parent and a child**:

- **SuperAgent** is a minimal in-process PHP SDK that drives a single LLM tool-use loop (one agent, one conversation).
- **SuperAICore** is a Laravel-wide orchestration layer — it picks a backend, resolves provider credentials, routes by capability, tracks usage, calculates cost, manages MCP servers, and ships an admin UI.

**SuperAICore does not require SuperAgent to function.** SuperAgent is only one of five backends. The other four (Claude CLI, Codex CLI, Anthropic API, OpenAI API) work without it, and the `SuperAgentBackend` gracefully reports itself as unavailable (`class_exists(Agent::class)` check) when the SDK is absent. If you don't need SuperAgent, set `AI_CORE_SUPERAGENT_ENABLED=false` in your `.env` and the Dispatcher falls back to the remaining backends.

The `forgeomni/superagent` entry in `composer.json` is there so the SuperAgent backend compiles out of the box. If you never use it, you can safely remove it from `composer.json` before `composer install` in your host app — nothing else in SuperAICore imports the SuperAgent namespace.

## Features

- **Four execution engines** — Claude Code CLI, Codex CLI, Gemini CLI, and SuperAgent SDK — unified behind a single `Dispatcher` contract. Each engine accepts a fixed set of provider types:
  - **Claude Code CLI**: `builtin` (local login), `anthropic`, `anthropic-proxy`, `bedrock`, `vertex`
  - **Codex CLI**: `builtin` (ChatGPT login), `openai`, `openai-compatible`
  - **Gemini CLI**: `builtin` (Google OAuth login), `google-ai`, `vertex`
  - **SuperAgent SDK**: `anthropic`, `anthropic-proxy`, `openai`, `openai-compatible`
- Engines fan out to seven internal Dispatcher adapters (`claude_cli`, `codex_cli`, `gemini_cli`, `superagent`, `anthropic_api`, `openai_api`, `gemini_api`) — CLI adapters when a provider uses `builtin`, HTTP adapters when it uses an API key. Operators rarely need to know this, but the adapters are addressable directly from the CLI if needed.
- **Provider / Service / Routing model** — map abstract capabilities (`summarize`, `translate`, `code_review`, ...) to concrete services, and services to provider credentials.
- **MCP server manager** — install, enable, and configure MCP servers from the admin UI.
- **Usage tracking** — every call persists prompt/response tokens, duration, and cost to `ai_usage_logs`.
- **Cost analytics** — per-model pricing table, USD rollups, dashboard with charts.
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
- An Anthropic / OpenAI / Google AI Studio API key for the HTTP backends

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
./vendor/bin/super-ai-core list-backends

# Drive the four engines from the CLI
./vendor/bin/super-ai-core call "Hello" --backend=claude_cli                              # Claude Code CLI (local login)
./vendor/bin/super-ai-core call "Hello" --backend=codex_cli                               # Codex CLI (ChatGPT login)
./vendor/bin/super-ai-core call "Hello" --backend=gemini_cli                              # Gemini CLI (Google OAuth)
./vendor/bin/super-ai-core call "Hello" --backend=superagent --api-key=sk-ant-...         # SuperAgent SDK

# Skip the CLI wrapper and hit the HTTP APIs directly
./vendor/bin/super-ai-core call "Hello" --backend=anthropic_api --api-key=sk-ant-...      # Claude engine, HTTP mode
./vendor/bin/super-ai-core call "Hello" --backend=openai_api --api-key=sk-...             # Codex engine, HTTP mode
./vendor/bin/super-ai-core call "Hello" --backend=gemini_api --api-key=AIza...            # Gemini engine, HTTP mode
```

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
  SuperAgent SDK  ────────▶ anthropic(-proxy) /      ────▶ superagent
                            openai(-compatible)

  Dispatcher ← BackendRegistry   (owns the 7 adapters above)
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
