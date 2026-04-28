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

The config lands at `config/super-ai-core.php`. Migrations create ten tables:

- `integration_configs`
- `ai_capabilities`
- `ai_services`
- `ai_service_routing`
- `ai_providers`
- `ai_model_settings`
- `ai_usage_logs`
- `ai_processes`
- `skill_executions` *(since 0.8.6)*
- `skill_evolution_candidates` *(since 0.8.6)*

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

## 9. Extending provider types with `provider_types` config (0.6.2+)

SuperAICore ships 9 bundled provider types (`anthropic`, `anthropic-proxy`, `bedrock`, `vertex`, `google-ai`, `openai`, `openai-compatible`, `kiro-api`, `builtin`) — each described in `Services\ProviderTypeRegistry::bundled()` with label, icon, form fields, env-var name, base-url env, allowed backends, and an `extra_config → env` map. Host apps can rebrand a bundled type (e.g. point `label_key` at a host-owned lang namespace) or add an entirely new type via a single config block — no fork required:

```php
// config/super-ai-core.php
return [
    // …other keys…

    'provider_types' => [
        // Rebrand an existing type — the rest of the descriptor inherits.
        \SuperAICore\Models\AiProvider::TYPE_ANTHROPIC => [
            'label_key' => 'integrations.ai_provider_anthropic',
            'icon'      => 'bi-key',
        ],

        // Declare a brand-new type not in the bundle. Shape mirrors
        // ProviderTypeDescriptor::fromArray() — the registry feeds the
        // /providers UI, the env builder, AiProvider::requiresApiKey(),
        // and every backend's buildEnv() call automatically.
        'xai-api' => [
            'label_key'        => 'integrations.ai_provider_xai',
            'icon'             => 'bi-x-lg',
            'fields'           => ['api_key'],
            'default_backend'  => \SuperAICore\Models\AiProvider::BACKEND_SUPERAGENT,
            'allowed_backends' => [\SuperAICore\Models\AiProvider::BACKEND_SUPERAGENT],
            'env_key'          => 'XAI_API_KEY',
        ],
    ],
];
```

When SuperAICore later adds a new upstream type (e.g. `TYPE_ANTHROPIC_VERTEX_V2`), host apps pick it up with a `composer update` — no code change required. The registry is addressable at `app(\SuperAICore\Services\ProviderTypeRegistry::class)`; `get($type)` / `all()` / `forBackend($backend)` are the three entry points most host code needs.

Host apps that previously mirrored SuperAICore's provider-type matrix in their own controllers/runners (SuperTeam's pre-0.6.2 `IntegrationController::PROVIDER_TYPES` + `ClaudeRunner::providerEnvVars()`) can now replace those with single-line delegations to `ProviderTypeRegistry` + `ProviderEnvBuilder`. See the "Host-app migration" section of [CHANGELOG.md](CHANGELOG.md) for before/after snippets.

## 10. Automatic usage recording from runner classes (0.6.5+)

If your host has a class that uses `Runner\Concerns\MonitoredProcess` to spawn CLI subprocesses (SuperTeam's `ClaudeRunner` is the canonical example), you can switch any one spawn path to automatic `ai_usage_logs` recording by swapping `runMonitored()` for `runMonitoredAndRecord()`. The new variant buffers stdout, parses it with `CliOutputParser` on exit, and calls `UsageRecorder::record()` with the token counts it recovers — so a single method call replaces the 20–40 lines of parser + recorder glue most host runners end up writing per backend.

```php
use Symfony\Component\Process\Process;

class MyRunner {
    use \SuperAICore\Runner\Concerns\MonitoredProcess;

    public function run(Task $task): int
    {
        $process = Process::fromShellCommandline(
            'claude -p "…" --output-format=stream-json --verbose'
        );

        // runMonitored() — spawn + register in Process Monitor. Use for
        //   runs whose output format you don't want to touch (legacy).
        // runMonitoredAndRecord() — same, PLUS usage-row recording on exit.
        return $this->runMonitoredAndRecord(
            process:         $process,
            backend:         'claude_cli',
            commandSummary:  'claude -p "review" --output-format=stream-json',
            externalLabel:   "task:{$task->id}",
            engine:          'claude',           // drives CliOutputParser selection
            context:         [
                'task_type'  => 'tasks.run',
                'capability' => 'agent_spawn',
                'user_id'    => $task->user_id,
                'provider_id'=> $task->provider_id,
                'metadata'   => ['task_id' => $task->id],
            ],
        );
    }
}
```

The CLI's exit code is always returned unchanged. If `CliOutputParser` can't match the stream shape (common for plain-text Codex / Copilot runs), no row is written and a `debug`-level log note is emitted — this is opt-in precisely because adopting it shouldn't silently break a runner whose output format isn't stream-json yet.

## 11. Upgrading

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

**0.6.6 migration** — adds one nullable column + composite index to `ai_usage_logs`: `idempotency_key varchar(80)` + index `(idempotency_key, created_at)`. Powers the 60s Dispatcher dedup window.

**0.6.7 — no migration.** Pure runtime behavior change. Two things worth reviewing:

1. **Hosts running claude from a process that was itself launched by a parent `claude` shell** (e.g. `php artisan serve` started inside a Claude Code session) will notice claude suddenly starts authenticating correctly. If you'd papered over this with a manual env-scrub in your own runner, it's now redundant but harmless.
2. **Hosts with their own `ProcessSource`** should add their label prefix to the new config key so `AiProcessSource` doesn't emit a duplicate bare row next to their rich one:

   ```php
   // config/super-ai-core.php
   'process_monitor' => [
       'enabled' => env('AI_CORE_PROCESS_MONITOR', false),
       'host_owned_label_prefixes' => ['task:'],   // SuperTeam convention
   ],
   ```

`AiProcessSource::list()` is now explicitly **live-only** by contract — it returns ONLY currently-running OS processes. Hosts that previously relied on `list()` returning finished rows for a history view should query the `ai_processes` table directly (it remains the full audit log of every spawn).

**0.6.8 — no migration.** Additive features only. Three things worth reviewing:

1. **Adopting catalog-driven MCP sync** is opt-in. Drop a catalog at `.mcp-servers/mcp-catalog.json`, write `.claude/mcp-host.json` with the project / agent tier choices, then `php artisan claude:mcp-sync --dry-run` to preview. Hosts that don't run the command see zero change — no file is touched until you invoke it. See `docs/mcp-sync.md` for the shape.

2. **Upgrading `SuperAgentBackend` callers.** Existing one-shot users keep working verbatim (`max_turns` still defaults to 1, envelope keys are additive). The SDK is pinned at **`forgeomni/superagent` 0.8.9**. To actually use the new in-process capabilities, pass:
   ```php
   $dispatcher->dispatch([
       'prompt'          => '…',
       'backend'         => 'superagent',
       'max_turns'       => 10,              // run the real agentic loop
       'max_cost_usd'    => 1.50,            // hard cap via Agent::withMaxBudget()
       'mcp_config_file' => base_path('.mcp.json'),
       'provider_config' => ['provider' => 'kimi', 'region' => 'cn'],  // region-aware
       'load_tools'      => ['agent'],       // OPT-IN: SDK sub-agent dispatch via AgentTool
   ]);

   // When `load_tools: ['agent']` is set and the run dispatched sub-agents,
   // the envelope gains an optional `subagents` key (SDK 0.8.9 productivity):
   //   [
   //     ['agentId' => 'research-jordan',
   //      'status' => 'completed',           // or 'completed_empty' on zero-tool-call runs
   //      'filesWritten' => ['/abs/path.md'],
   //      'toolCallsByName' => ['Read' => 3, 'Write' => 1],
   //      'productivityWarning' => null,     // advisory when tools ran but wrote nothing
   //      'totalToolUseCount' => 4],
   //     …,
   //   ]
   // Treat `status === 'completed_empty'` or a non-null `productivityWarning`
   // as a re-dispatch signal. Key is omitted entirely when no sub-agent ran —
   // zero change for callers that don't use AgentTool.
   ```

3. **Debug API providers with one command.** `bin/superaicore api:status` probes every provider whose API-key env var is set (5s cURL per); `--all` widens to every DEFAULT_PROVIDERS entry, `--json` emits structured output for dashboards. Distinguishes auth-rejected (HTTP 401/403), network timeout, and missing key each with a distinct `reason`.

4. **Weak-model agent-spawn hardening is automatic.** Hosts using `AgentSpawn\Pipeline` (including everyone on `TaskRunner` with `spawn_plan_dir`) pick up five additional defences on upgrade with zero code change: host-injected per-agent guard clauses in every `task_prompt` (language-aware via CJK detection), canonical ASCII `output_subdir`, pre-fanout cleanup of premature consolidator-reserved files, post-fanout contract audit, and a language-aware consolidation prompt that forbids fabricated error-filenames. Two side-effects worth knowing:
   - Per-agent `run.log` / prompt / exec script now write to `$TMPDIR/superaicore-spawn-<date>-<hex>/<agent>/` instead of `$outputRoot/<agent>/`. The user-facing output dir only holds real deliverables (`.md` / `.csv` / `.png`). Update any host tooling that previously globbed `$outputRoot/<agent>/run.log` — the path moved.
   - `Orchestrator::run()` now returns `report[N].warnings[]` on each entry. Existing callers that only read `exit` / `log` / `duration_ms` / `error` stay source-compatible (the key is optional per the PHPDoc).

**0.6.9 — no migration.** Additive surface + five automatic correctness fixes you get on the SDK bump. Composer constraint is lifted `^0.8.0` → `^0.9.0`. Four things worth reviewing:

1. **Qwen provider key rebind (SDK-side).** SDK 0.9.0 rebinds the `qwen` registry key to an OpenAI-compat provider (`<region>/compatible-mode/v1/chat/completions`). The legacy DashScope-native body shape lives on as `qwen-native`. If you depended on wire-level `parameters.thinking_budget` / `parameters.enable_code_interpreter` (DashScope-native fields), switch your provider config:
   ```php
   'provider_config' => ['provider' => 'qwen-native', 'region' => 'cn'],
   ```
   Both keys read the same `QWEN_API_KEY` / `DASHSCOPE_API_KEY` env. The `qwen` default is what Alibaba's own `qwen-code` CLI uses in production, so leave it alone unless you were touching those DashScope-native fields. `ApiHealthDetector::DEFAULT_PROVIDERS` now includes both keys, so `api:status` shows both endpoints side-by-side.

2. **Three new `SuperAgentBackend` options.** All additive, all optional:
   ```php
   $dispatcher->dispatch([
       'prompt'           => '…',
       'backend'          => 'superagent',
       'provider_config'  => ['provider' => 'kimi', 'region' => 'cn'],

       // NEW — vendor-specific wire fields, deep-merged into the request body
       'extra_body'       => ['custom_vendor_field' => 'value'],

       // NEW — capability-routed features; silent skip on unsupported providers
       'features'         => [
           'prompt_cache_key' => ['session_id' => $sessionId],  // Kimi session cache
           'thinking'         => ['budget' => 4000],             // CoT with fallback
       ],
       // Shorthand: `'prompt_cache_key' => $sessionId` maps to
       // features.prompt_cache_key.session_id automatically.

       // NEW — loop-detection harness on top of the streaming handler
       'loop_detection'   => true,    // or: ['tool_loop_threshold' => 7, ...]
   ]);
   ```
   `loop_detection` catches `TOOL_LOOP` (5 same tool+args in a row), `STAGNATION` (8 same name), `FILE_READ_LOOP` (8 of 15 recent read-like calls, with cold-start exemption), `CONTENT_LOOP` (50-char window 10×), and `THOUGHT_LOOP` (3× same thinking text). Violations fire as SDK wire events — the AICore envelope stays byte-exact for callers that don't opt in.

3. **Live model catalog refresh.** New subcommand:
   ```bash
   ./bin/superaicore super-ai-core:models refresh              # refresh every provider with env credentials
   ./bin/superaicore super-ai-core:models refresh --provider=kimi
   php artisan super-ai-core:models refresh --provider=qwen
   ```
   Pulls each provider's live `GET /models` into `~/.superagent/models-cache/<provider>.json`. Overlays above the user override but below runtime `register()` calls, so bundled pricing is preserved when the vendor's `/models` omits rates. `CostCalculator` / `ModelResolver` pick it up automatically on the next call — no restart, no config publish.

4. **Kimi Code / Qwen Code OAuth.** If you don't have an API key, log in interactively via the SDK CLI:
   ```bash
   ./vendor/bin/superagent auth login kimi-code     # RFC 8628 device flow against auth.kimi.com
   ./vendor/bin/superagent auth login qwen-code     # device flow + PKCE S256 against chat.qwen.ai
   ```
   The resulting token lands at `~/.superagent/credentials/<kimi-code|qwen-code>.json`. `ApiHealthDetector::filterToConfigured()` now treats these files as "configured" for `kimi` / `qwen`, so `api:status` and `/providers` pick them up without a `KIMI_API_KEY` / `QWEN_API_KEY` env var. The Anthropic OAuth refresh path is now `flock`-serialised across processes — Laravel queue workers sharing stored OAuth creds no longer race-overwrite refresh tokens.

**For MCP servers declaring an `oauth:` block in `mcp.json`** you can now call `McpManager::oauthStatus($key)` / `oauthLogin($key)` / `oauthLogout($key)` in your UI. `oauthLogin()` blocks on stdio during the device-flow poll — run it as a queued job, not inline in a request. Existing `startAuth()` / `clearAuth()` / `testConnection()` (browser-login / session-dir servers like the LinkedIn scraper) stay unchanged.

**0.7.0 — no migration.** Additive surface + one long-standing mapping fix. Composer constraint is lifted `^0.9.0` → `^0.9.1`. Five things worth reviewing:

1. **Two new provider types: `openai-responses` and `lmstudio`.** Both route through the `superagent` backend (SDK keys `openai-responses` / `lmstudio`).
   - **OpenAI Responses API** — metered mode: add a provider row with `type = openai-responses` and an API key. ChatGPT-subscription mode: leave `api_key` blank and store `access_token` in `extra_config.access_token` (from your host-app's ChatGPT OAuth flow) — the SDK auto-flips the base URL to `chatgpt.com/backend-api/codex`. Azure OpenAI: set `base_url` to `https://<name>.openai.azure.com/openai/deployments/<deployment>` — the SDK auto-adds the `api-version=2025-04-01-preview` query (override via `extra_config.azure_api_version`).
   - **LM Studio** — point `base_url` at your local LM Studio server (default `http://localhost:1234/v1`). No API key needed; the SDK synthesises a placeholder `Authorization` header. Useful for disconnected / on-prem workloads.

2. **Round-trip `idempotency_key` through the SDK.** If you were already passing `idempotency_key` on `Dispatcher::dispatch()` options, nothing changes in your code — but the value now travels with the SDK's `AgentResult` instead of going sideways through UsageRecorder. Hosts whose Dispatcher runs on a different process than the write-through no longer need to re-compute the key on the write side. Same applies to the `external_label`-derived auto-key: `Dispatcher::dispatch()` pre-computes the same `"{backend}:{external_label}"` value, forwards it to `Agent::run()`, and prefers the envelope-echoed value when writing `ai_usage_logs`.

3. **W3C trace context passthrough.** If your host has a middleware that captures the inbound `traceparent` header, forward it to Dispatcher:
   ```php
   $dispatcher->dispatch([
       'prompt'       => '…',
       'backend'      => 'superagent',
       'provider_config' => ['type' => 'openai-responses', 'api_key' => env('OPENAI_API_KEY')],
       'traceparent'  => $request->header('traceparent'),  // silent no-op when null
       'tracestate'   => $request->header('tracestate'),
   ]);
   ```
   The SDK projects these onto the Responses API's `client_metadata` so OpenAI-side logs correlate with the host's distributed trace. Silent drop on invalid strings — safe to pass unconditionally.

4. **Classified `ProviderException` subclasses.** The `SuperAgentBackend` catch ladder now splits into six specific SDK subclasses (`ContextWindowExceeded`, `QuotaExceeded`, `UsageNotIncluded`, `CyberPolicy`, `ServerOverloaded`, `InvalidPrompt`) before the generic `\Throwable`, each logged with a stable `error_class` tag + `retryable` flag. Contract unchanged — still returns `null` on failure — so no call site breaks. Hosts wanting smarter routing subclass `SuperAgentBackend` and override the `logProviderError(\Throwable $e, string $code)` seam.

5. **Declarative HTTP headers per provider type.** Two new descriptor fields — `http_headers` (literal header → value) and `env_http_headers` (header → env var name, read at request time) — let you inject `OpenAI-Project`, `LangSmith-Project`, `OpenRouter-App` etc. across every SDK-routed call of a provider type, without touching package code. Example:
   ```php
   // config/super-ai-core.php
   'provider_types' => [
       // Tag every OpenAI-routed call with your own app id + pick up the
       // OPENAI_PROJECT env var (the SDK silently omits the header when
       // the env var isn't set, so this is safe on hosts that haven't
       // configured project-scoped keys).
       \SuperAICore\Models\AiProvider::TYPE_OPENAI => [
           'http_headers'     => ['X-App' => 'my-host-app'],
           'env_http_headers' => ['OpenAI-Project' => 'OPENAI_PROJECT'],
       ],

       // Same for the new Responses API type — inject a LangSmith project
       // header so cross-provider tracing works without a wrapper layer.
       \SuperAICore\Models\AiProvider::TYPE_OPENAI_RESPONSES => [
           'env_http_headers' => ['Langsmith-Project' => 'LANGSMITH_PROJECT'],
       ],
   ],
   ```

**Pre-existing `openai-compatible` or `anthropic-proxy` providers.** Before 0.7.0 these rows silently routed through the SDK's `anthropic` provider when `provider_config.provider` wasn't hand-set — `anthropic-proxy` matched by accident, but `openai-compatible` failed. After 0.7.0 the descriptor's `sdk_provider` maps them correctly (`anthropic` / `openai`). If your host was explicitly setting `provider_config.provider`, nothing changes. If you were relying on the broken default, `openai-compatible` rows now start working as intended.

See `docs/advanced-usage.md` for deeper recipes — multi-turn Responses, LangSmith tracing, LM Studio over LAN, host-level exception routing, per-provider HTTP header overrides.

**0.7.1 — no migration.** Additive contract only — `Contracts\ScriptedSpawnBackend` lands alongside (not replacing) `StreamingBackend`. All six CLI backends (`Claude` / `Codex` / `Gemini` / `Copilot` / `Kiro` / `Kimi`) implement it in the same release. Hosts currently carrying a per-backend `match ($backend) { 'claude' => buildClaudeProcess(…), 'codex' => buildCodexProcess(…), … }` for task spawns + a second copy for one-shot chat can collapse both into one polymorphic call:

```php
use SuperAICore\Services\BackendRegistry;

$backend = app(BackendRegistry::class)->forEngine($engineKey);  // nullable — null when engine disabled
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

// One-shot chat sibling — backend owns argv + output parsing + ANSI strip:
$response = $backend->streamChat($prompt, function (string $chunk) {
    echo $chunk;
});
```

After this migration, future engines that ship a `ScriptedSpawnBackend` implementation light up automatically in every host code path — no `match` arm to add. `Support\CliBinaryLocator` is registered as a singleton the service provider so host-side CLI-path resolution uses the same `~/.npm-global/bin` / `/opt/homebrew/bin` / nvm / Windows `%APPDATA%/npm` probes the package's own backends use. `ClaudeCliBackend::CLAUDE_SESSION_ENV_MARKERS` is exposed as a public constant so hosts still composing their own `claude` processes can share the canonical 5-marker scrub list.

See `docs/advanced-usage.md` §12 for the full before/after migration pattern and `docs/host-spawn-uplift-roadmap.md` for the context.

**0.8.1 — no migration.** Two opt-in changes; both safe to skip on upgrade.

1. **Portable `.mcp.json` writes via `mcp.portable_root_var`.** Default stays `null` — legacy "absolute path everywhere" preserved. Opt in when you want a generated `.mcp.json` to survive being copied / synced across machines, users, or container layers (typical for hosts whose `mcp` install dir lives inside the project tree and therefore moves with it):

   ```dotenv
   # .env — any env var name your MCP runtime exports works
   AI_CORE_MCP_PORTABLE_ROOT_VAR=SUPERTEAM_ROOT
   ```

   ```jsonc
   // .claude/settings.local.json — Claude Code expands ${SUPERTEAM_ROOT} at MCP spawn time
   { "env": { "SUPERTEAM_ROOT": "${PWD}" } }
   ```

   After this, every `McpManager::install*()` writer emits bare commands (`node`, `php`, `uvx`, `uv`, `python`) and rewrites paths under `projectRoot()` as `${SUPERTEAM_ROOT}/<rel>`. Backend-sync helpers (`superfeedMcpConfig`, `codexOcrMcpConfig`, `codexPdfExtractMcpConfig`) honour the same knob. Egress to per-machine targets (Codex `~/.codex/config.toml`, Gemini / Claude / Copilot / Kiro / Kimi user-scope MCP configs, `codex exec -c` runtime flags) materialises placeholders back to absolute paths via `materializePortablePath()`, so backends with strict literal handling still spawn correctly. New helpers on `McpManager`: `portablePath()`, `portableCommand()`, `portableRootVar()`, `materializePortablePath()`, `materializeServerSpec()`. See `docs/advanced-usage.md` §13 for recipes (containerised hosts, multi-user mounts, what to do when the env var isn't exported at runtime).

2. **`/providers` page now gates UI on CLI availability.** Pure UI fix — no controller / route / DB change. CLI engines (`claude` / `codex` / `gemini` / `copilot` / `kiro` / `kimi`) whose binary isn't on `$PATH` render the engine toggle as `disabled` (with tooltip + clamped hidden field), and the synthetic "built-in (local CLI login)" row inside the per-backend table is hidden when the engine is off or its CLI is missing. When neither built-in nor any external provider applies, the table now shows a one-line empty state pointing at the actual reason. Hosts that previously saw users toggle "engine on" only to have spawns silently fail at runtime can stop fielding those tickets.

**0.8.5 — no migration.** SDK uptake + correctness fix; no DB / config change. Composer constraint moves `^0.9.0` → `^0.9.5`. Three things worth knowing:

1. **Multi-turn tool-use replays against Kimi / GLM / MiniMax / Qwen / OpenAI / OpenRouter / LMStudio start working correctly.** Pre-0.9.5, the SDK's `ChatCompletionsProvider::convertMessage()` early-returned on the first `tool_use` block (dropping sibling text + parallel tool calls) and read nonexistent `ContentBlock` properties — every replayed tool call went out as `{id: null, name: null, arguments: "null"}`. Hosts running `Dispatcher::dispatch(['backend' => 'superagent', 'max_turns' => 10, …])` against any of those providers were silently broken pre-upgrade. No call-site change required; the SDK's new `Conversation\Transcoder` puts every wire family behind one canonical converter so the fix lands across all of them at once.

2. **`SuperAgentBackend::buildAgent()` always hands a constructed `LLMProvider` to the SDK now (not a provider-name string + spread `llmConfig` keys).** Production callers go through `Dispatcher` and never inspect `$agentConfig['provider']`, so the change is invisible. Hosts that subclass `SuperAgentBackend` and override `makeAgent()` should update test assertions that previously checked `$agentConfig['provider'] === 'sa-test'` to `instanceof \SuperAgent\Contracts\LLMProvider` — see `tests/Unit/SuperAgentBackendTest.php::test_no_region_still_hands_llmprovider_instance_to_agent` for the canonical pattern. The new `makeProvider()` seam in `SuperAgentBackend` is the substitution point for tests that need a fake LLMProvider without registering it in `ProviderRegistry`.

3. **`Agent::switchProvider($name, $config, $policy)` is now available** if you wrap `SuperAgentBackend` directly and want in-process mid-conversation handoff between provider families. SuperAICore's own `FallbackChain` walks across CLI subprocesses (a different concern) so it doesn't use this. See the SDK's CHANGELOG `[0.9.5]` entry for the `HandoffPolicy::default() / preserveAll() / freshStart()` presets and the cross-family wire-format encoding rules.

The fix to the namespace typo introduced in 0.8.1 (`makeProvider()` was returning a non-existent `\SuperAgent\Providers\LLMProvider` and silently breaking the entire SuperAgent in-process backend across 0.8.1 → 0.8.2) is also part of this release. Hosts that noticed `Dispatcher::dispatch(['backend' => 'superagent', …])` returning `null` on every call should now see real envelopes again — verify with `bin/superaicore api:status` against your SuperAgent-routed providers, or run the package suite: 480 tests, 1380 assertions.

**0.8.6 — adds two tables.** First release where `php artisan migrate` lands new schema since 0.6.6. The skill engine is **opt-in via hook wiring** — install the package, run the migration, and zero behaviour changes until you point Claude Code's `PreToolUse(Skill)` and `Stop` hooks at the new artisan commands. Three things worth knowing:

1. **Run the migration.** Two new tables: `skill_executions` (one row per Claude Code Skill tool invocation — telemetry) and `skill_evolution_candidates` (review-only FIX-mode patches the evolver proposes). Both honour `super-ai-core.table_prefix` via `HasConfigurablePrefix`. Both `up()` methods are guarded by `Schema::hasTable()` so re-running the migration is idempotent. `down()` drops both — safe to re-bootstrap in dev.

   ```bash
   composer update forgeomni/superaicore
   php artisan vendor:publish --tag=super-ai-core-migrations --force
   php artisan migrate
   ```

2. **Wire the hooks (host-side, optional).** The package only ships the artisan endpoints — Claude Code's `.claude/settings.local.json` does the actual hook-to-command binding:

   ```jsonc
   {
     "hooks": {
       "PreToolUse": [
         {
           "matcher": "Skill",
           "hooks": [{ "type": "command", "command": "php artisan skill:track-start --json" }]
         }
       ],
       "Stop": [
         {
           "hooks": [{ "type": "command", "command": "php artisan skill:track-stop --json" }]
         }
       ]
     }
   }
   ```

   Both commands read the Claude Code hook JSON payload from stdin (1.0s soft-deadline + 200KB cap, non-blocking, never fails the hook on telemetry errors) and auto-detect `host_app` by walking up to find a sibling `.claude/` directory and using its parent's basename. SuperTeam's sibling commit demonstrates the full plumbing.

3. **Optional: cron `skill:evolve --sweep` daily.** Once you have telemetry flowing, the evolver can scan for skills with degraded metrics and queue review-only candidates without burning tokens (defaults to no LLM dispatch). Review queue via `php artisan skill:candidates`.

   ```php
   // app/Console/Kernel.php
   $schedule->command('skill:evolve --sweep --threshold=0.30 --min-applied=5')
            ->daily()
            ->withoutOverlapping();
   ```

   `--sweep` de-dups against existing `pending` rows so it's idempotent across runs. Add `--dispatch` to also invoke the LLM via `Dispatcher` with `capability: 'reasoning'` — costs tokens, but gives reviewers an actual diff to apply. The evolver **never** modifies SKILL.md directly. DERIVED / CAPTURED modes (auto-deriving new skills from successful runs / capturing user-demonstrated workflows) are intentionally not shipped — humans curate new skills on Day 0.

The six artisan commands (`skill:track-start`, `skill:track-stop`, `skill:stats`, `skill:rank`, `skill:evolve`, `skill:candidates`) are all registered through `SuperAICoreServiceProvider::boot()`. They are **not** mounted on the standalone `bin/superaicore` console — call them via `php artisan` from your Laravel host. See `docs/advanced-usage.md` §16 for `SkillRanker` integration patterns (host-side skill-picker UI, weighted retrieval, telemetry-aware fallback chains).

## Troubleshooting

- **`Class 'SuperAgent\Agent' not found`** — you disabled `forgeomni/superagent` but left `AI_CORE_SUPERAGENT_ENABLED=true`. Set it to `false` or re-require the SDK.
- **CLI backend missing** — run `which claude` / `which codex`. If empty, install the CLI or override `CLAUDE_CLI_BIN` / `CODEX_CLI_BIN` with an absolute path.
- **Nothing logged to `ai_usage_logs`** — check `AI_CORE_USAGE_TRACKING=true` and that migrations ran.
- **`vendor:publish` prompt is ambiguous** — pass an explicit `--tag` from the list above.
