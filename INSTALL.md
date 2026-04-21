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
  - `gemini` CLI on `$PATH` — for the Gemini CLI backend
  - `copilot` CLI on `$PATH` (then `copilot login`) — for the GitHub Copilot CLI backend
  - `kiro-cli` on `$PATH` (then `kiro-cli login`, or `KIRO_API_KEY` for headless Pro/Pro+/Power) — for the Kiro CLI backend (0.6.1+)
  - Anthropic API key — for `anthropic_api`
  - OpenAI API key — for `openai_api`
  - Google AI Studio key — for `gemini_api`

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
AI_CORE_GEMINI_CLI_ENABLED=true
AI_CORE_COPILOT_CLI_ENABLED=true
AI_CORE_KIRO_CLI_ENABLED=true
AI_CORE_SUPERAGENT_ENABLED=true
AI_CORE_ANTHROPIC_API_ENABLED=true
AI_CORE_OPENAI_API_ENABLED=true
AI_CORE_GEMINI_API_ENABLED=true
CLAUDE_CLI_BIN=claude
CODEX_CLI_BIN=codex
GEMINI_CLI_BIN=gemini
COPILOT_CLI_BIN=copilot
KIRO_CLI_BIN=kiro-cli
AI_CORE_COPILOT_ALLOW_ALL_TOOLS=true
# Kiro's --no-interactive mode refuses to run tools without prior per-tool
# approval unless this is on. Flip false only for workflows that
# pre-populate approvals via `--trust-tools=<categories>` (0.6.1+).
AI_CORE_KIRO_TRUST_ALL_TOOLS=true
# Kiro API-key auth (headless, Pro / Pro+ / Power subscribers). Setting
# KIRO_API_KEY makes kiro-cli skip its browser login flow. Normally stored
# per provider in the DB via type=kiro-api; set this env var only when
# kiro-cli is invoked outside superaicore's dispatcher (0.6.1+).
# KIRO_API_KEY=ksk_...
# Opt-in liveness probe for `cli:status` copilot row (0.5.8+). Off by
# default — spawning `copilot --help` on every status poll is wasteful.
SUPERAICORE_COPILOT_PROBE=false
# Optional model-catalog auto-refresh at CLI startup (0.6.0+). Both must
# be set for the refresh to fire; it only runs when the local override is
# older than 7 days and network failures are swallowed.
# SUPERAGENT_MODELS_URL=https://your-cdn/models.json
# SUPERAGENT_MODELS_AUTO_UPDATE=1
ANTHROPIC_BASE_URL=https://api.anthropic.com
OPENAI_BASE_URL=https://api.openai.com
GEMINI_BASE_URL=https://generativelanguage.googleapis.com

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
./vendor/bin/superaicore list-backends

# Round-trip a prompt through the Anthropic API
./vendor/bin/superaicore call "ping" --backend=anthropic_api --api-key="$ANTHROPIC_API_KEY"
```

Expected: a short text reply and a usage block.

### Skill & sub-agent CLI smoke test

If you have any Claude Code skills or sub-agents installed (under `./.claude/skills/` in the project, `~/.claude/plugins/*/skills/`, or `~/.claude/skills/` / `~/.claude/agents/`), they are picked up automatically:

```bash
./vendor/bin/superaicore skill:list
./vendor/bin/superaicore agent:list

# Dry-run prints the resolved command without actually calling the CLI
./vendor/bin/superaicore skill:run <name> --dry-run

# Generate Gemini custom commands for every discovered skill/agent
# (writes to ~/.gemini/commands/skill/*.toml and agent/*.toml)
./vendor/bin/superaicore gemini:sync --dry-run

# Translate Claude sub-agents into Copilot's `.agent.md` format.
# Auto-runs on `agent:run --backend=copilot`; this flag is a manual preview.
./vendor/bin/superaicore copilot:sync --dry-run

# Same contract for Kiro (0.6.1+): agents translated to ~/.kiro/agents/<name>.json.
# Auto-runs on `agent:run --backend=kiro`; this flag is a manual preview.
./vendor/bin/superaicore kiro:sync --dry-run
```

No config needed. Running without `--dry-run` shells out to the backend CLIs (`claude`, `codex`, `gemini`, `copilot`, `kiro-cli`) — install whichever ones you intend to target:

```bash
npm i -g @anthropic-ai/claude-code
brew install codex        # or: cargo install codex
npm i -g @google/gemini-cli
npm i -g @github/copilot   # then `copilot login` (OAuth device flow)
# kiro-cli — download from https://kiro.dev/cli/ then `kiro-cli login`
# (or export KIRO_API_KEY=ksk_... for headless Pro / Pro+ / Power subscribers)
```

One-shot alternative (recommended) — let superaicore detect and install:

```bash
./vendor/bin/superaicore cli:status                 # see what's missing
./vendor/bin/superaicore cli:install --all-missing  # install everything (confirmation by default)
```

### Model catalog smoke test (0.6.0+)

`CostCalculator` and the per-engine `ModelResolver`s fall through to the SuperAgent model catalog whenever the host config doesn't enumerate a model. Inspect what's loaded and refresh the user override without touching `composer.json` or `config/super-ai-core.php`:

```bash
./vendor/bin/superaicore super-ai-core:models status                       # bundled / user-override / remote URL + staleness
./vendor/bin/superaicore super-ai-core:models list --provider=anthropic    # per-1M token pricing + aliases
./vendor/bin/superaicore super-ai-core:models update                       # fetch SUPERAGENT_MODELS_URL → ~/.superagent/models.json
./vendor/bin/superaicore super-ai-core:models update --url https://…       # ad-hoc URL for this one run
./vendor/bin/superaicore super-ai-core:models reset -y                     # delete the user override
```

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

## 8. Host-side usage tracking with `UsageRecorder` (0.6.2+)

If your host app spawns CLIs through its own runner (e.g. `App\Services\ClaudeRunner`, stage jobs, an `ExecuteTask` pipeline) instead of going through `Dispatcher::dispatch()`, those executions won't reach `ai_usage_logs` on their own — the Dispatcher is the only writer. Drop a single `UsageRecorder::record()` call at each CLI completion path to get proper rows, with `cost_usd`, `shadow_cost_usd`, and `billing_model` auto-filled from the catalog:

```php
use SuperAICore\Services\UsageRecorder;

// Tokens you already extracted from the CLI's stream-json / stdout:
app(UsageRecorder::class)->record([
    'task_type'     => 'ppt.strategist',      // whatever groups your runs
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

If you only have the raw captured CLI stdout and haven't parsed tokens yourself, `CliOutputParser` handles the common shapes:

```php
use SuperAICore\Services\CliOutputParser;

$env = CliOutputParser::parseClaude($stdout);    // or parseCodex / parseCopilot / parseGemini
// $env = ['text' => '…', 'model' => '…', 'input_tokens' => 12345, 'output_tokens' => 6789, …] or null
```

`UsageRecorder` is a singleton; it no-ops when `AI_CORE_USAGE_TRACKING=false`.

## 9. Upgrading

```bash
composer update forgeomni/superaicore
php artisan vendor:publish --tag=super-ai-core-migrations --force
php artisan migrate
```

Review [CHANGELOG.md](CHANGELOG.md) for breaking changes before `--force` publishing config.

**0.6.2 migration** — adds two nullable columns to `ai_usage_logs`: `shadow_cost_usd decimal(12,6)` and `billing_model varchar(20)`. Safe, non-destructive. Existing rows get `NULL` (rendered as `—` on the dashboard); new writes are backfilled automatically by the Dispatcher. Host apps that want to clean up pre-0.6.1 `task_type=NULL` test rows can:

```sql
DELETE FROM ai_usage_logs WHERE task_type IS NULL AND input_tokens = 0 AND output_tokens = 0;
```

## Troubleshooting

- **`Class 'SuperAgent\Agent' not found`** — you disabled `forgeomni/superagent` but left `AI_CORE_SUPERAGENT_ENABLED=true`. Set it to `false` or re-require the SDK.
- **CLI backend missing** — run `which claude` / `which codex`. If empty, install the CLI or override `CLAUDE_CLI_BIN` / `CODEX_CLI_BIN` with an absolute path.
- **Nothing logged to `ai_usage_logs`** — check `AI_CORE_USAGE_TRACKING=true` and that migrations ran.
- **`vendor:publish` prompt is ambiguous** — pass an explicit `--tag` from the list above.
