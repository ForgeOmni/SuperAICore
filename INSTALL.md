# Installation — forgeomni/superaicore

[English](INSTALL.md) · [简体中文](INSTALL.zh-CN.md) · [Français](INSTALL.fr.md)

This guide walks through a full install of `forgeomni/superaicore` into an existing Laravel 10/11/12 application.

## 1. Prerequisites

- PHP ≥ 8.1 with `ext-json`, `ext-mbstring`, `ext-pdo`
- Composer 2.x
- Laravel 10, 11 or 12 (fresh install works too)
- A SQL database (MySQL 8+, PostgreSQL 13+, or SQLite 3.35+)
- Optional, per backend:
  - `claude` CLI on `$PATH` — for the Claude CLI backend
  - `codex` CLI on `$PATH` — for the Codex CLI backend
  - Anthropic API key — for `anthropic_api`
  - OpenAI API key — for `openai_api`

## 2. Require the package

```bash
composer require forgeomni/superaicore
```

If you do **not** want the SuperAgent backend, you can remove the sibling requirement before install:

```bash
# optional — drop the SuperAgent SDK dependency
composer remove forgeomni/superagent
# then in .env
# AI_CORE_SUPERAGENT_ENABLED=false
```

The `SuperAgentBackend` reports itself unavailable when the SDK is missing and the Dispatcher falls back to the remaining four backends.

## 3. Publish config & migrations

```bash
php artisan vendor:publish --tag=super-ai-core-config
php artisan vendor:publish --tag=super-ai-core-migrations
php artisan vendor:publish --tag=super-ai-core-views    # only if you plan to override Blade templates
```

The config lands at `config/super-ai-core.php`. Migrations create eight tables:

- `integration_configs`
- `ai_capabilities`
- `ai_services`
- `ai_service_routing`
- `ai_providers`
- `ai_model_settings`
- `ai_usage_logs`
- `ai_processes`

Run them:

```bash
php artisan migrate
```

## 4. Environment

Minimal `.env` for the HTTP backends:

```dotenv
AI_CORE_DEFAULT_BACKEND=anthropic_api
ANTHROPIC_API_KEY=sk-ant-...
# or, for OpenAI:
OPENAI_API_KEY=sk-...
```

Full list of env flags (see `config/super-ai-core.php` for defaults):

```dotenv
# routing & UI
AI_CORE_ROUTES_ENABLED=true
AI_CORE_ROUTE_PREFIX=super-ai-core
AI_CORE_VIEWS_ENABLED=true
SUPER_AI_CORE_LAYOUT=super-ai-core::layouts.app

# host integration (optional)
SUPER_AI_CORE_HOST_BACK_URL=https://your-host.app/dashboard
SUPER_AI_CORE_HOST_NAME="Your Host App"
SUPER_AI_CORE_HOST_ICON=bi-arrow-left
SUPER_AI_CORE_LOCALE_COOKIE=locale

# backends
AI_CORE_CLAUDE_CLI_ENABLED=true
AI_CORE_CODEX_CLI_ENABLED=true
AI_CORE_SUPERAGENT_ENABLED=true
AI_CORE_ANTHROPIC_API_ENABLED=true
AI_CORE_OPENAI_API_ENABLED=true
CLAUDE_CLI_BIN=claude
CODEX_CLI_BIN=codex
ANTHROPIC_BASE_URL=https://api.anthropic.com
OPENAI_BASE_URL=https://api.openai.com

# table names (defaults to sac_ — set to '' to keep raw ai_* names)
AI_CORE_TABLE_PREFIX=sac_

# usage + MCP + monitor
AI_CORE_USAGE_TRACKING=true
AI_CORE_USAGE_RETAIN_DAYS=180
AI_CORE_MCP_ENABLED=true
AI_CORE_MCP_INSTALL_DIR=/var/lib/mcp
AI_CORE_PROCESS_MONITOR=false
```

## 5. Smoke test

```bash
# See which backends the current environment can reach
./vendor/bin/super-ai-core list-backends

# Round-trip a prompt through the Anthropic API
./vendor/bin/super-ai-core call "ping" --backend=anthropic_api --api-key="$ANTHROPIC_API_KEY"
```

Expected: a short text reply and a usage block.

## 6. Open the admin UI

Default mount point is `/super-ai-core`. The package routes sit behind the `['web', 'auth']` middleware stack out of the box, so sign in to your Laravel app first, then visit:

- `http://your-app.test/super-ai-core/integrations`
- `http://your-app.test/super-ai-core/providers`
- `http://your-app.test/super-ai-core/services`
- `http://your-app.test/super-ai-core/usage`
- `http://your-app.test/super-ai-core/costs`

To enable the live process monitor (admin only) set `AI_CORE_PROCESS_MONITOR=true`.

## 7. Headless / service-only install

Skip routes and views entirely and resolve services from the container:

```dotenv
AI_CORE_ROUTES_ENABLED=false
AI_CORE_VIEWS_ENABLED=false
```

```php
$dispatcher = app(\SuperAICore\Services\Dispatcher::class);
$result = $dispatcher->dispatch([
    'prompt' => 'Summarise this ticket',
    'task_type' => 'summarize',
]);
```

## 8. Upgrading

```bash
composer update forgeomni/superaicore
php artisan vendor:publish --tag=super-ai-core-migrations --force
php artisan migrate
```

Review [CHANGELOG.md](CHANGELOG.md) for breaking changes before `--force` publishing config.

## Troubleshooting

- **`Class 'SuperAgent\Agent' not found`** — you disabled `forgeomni/superagent` but left `AI_CORE_SUPERAGENT_ENABLED=true`. Set it to `false` or re-require the SDK.
- **CLI backend missing** — run `which claude` / `which codex`. If empty, install the CLI or override `CLAUDE_CLI_BIN` / `CODEX_CLI_BIN` with an absolute path.
- **Nothing logged to `ai_usage_logs`** — check `AI_CORE_USAGE_TRACKING=true` and that migrations ran.
- **`vendor:publish` prompt is ambiguous** — pass an explicit `--tag` from the list above.
