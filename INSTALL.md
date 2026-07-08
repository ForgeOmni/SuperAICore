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
  - `cursor-agent` on `$PATH` (then `cursor-agent login`, or `CURSOR_API_KEY` for headless) — for the Cursor Composer CLI backend (1.0.0+)
  - `grok` on `$PATH` (then `grok login`) — for the xAI Grok Build CLI backend (1.0.0+)
  - Anthropic API key — for `anthropic_api`
  - OpenAI API key — for `openai_api`
  - Google AI Studio key — for `gemini_api`
  - xAI API key (`XAI_API_KEY` / `GROK_API_KEY`) — for the metered `grok` provider type via `superagent` (1.0.0+)

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
CURSOR_CLI_BIN=cursor-agent
GROK_CLI_BIN=grok
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
# Cursor Composer + Grok Build CLIs (1.0.0+). Subscription engines — own
# their own login (~/.cursor, ~/.grok). `force`/`always_approve` auto-approve
# tools in headless runs; flip false to opt back into per-tool confirmation.
AI_CORE_CURSOR_CLI_ENABLED=true
AI_CORE_CURSOR_FORCE=true
AI_CORE_GROK_CLI_ENABLED=true
AI_CORE_GROK_ALWAYS_APPROVE=true
# CURSOR_API_KEY=...   # headless Cursor (otherwise `cursor-agent login`)
# xAI API key for the metered `grok` provider type via superagent (1.0.0+).
# Distinct from the grok.com-subscription `grok` CLI engine above.
# XAI_API_KEY=xai-...  # GROK_API_KEY also accepted as a fallback name
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

### Dispatch smoke test (1.1.0)

```bash
# Aggregate diagnostic: engines, auth, backends, aliases, preferences, run store
./vendor/bin/superaicore doctor

# Alias one-shot dispatch with the full JSON routing contract
./vendor/bin/superaicore send sonnet "ping" --json-result

# Inspect the routing pool and the run archive
./vendor/bin/superaicore aliases
./vendor/bin/superaicore runs list
```

Expected: `doctor` ends with an `N ok, 0 warn, 0 fail`-style summary (warns
are fine — they flag engines you simply haven't installed), and `send`
returns a JSON contract whose `ok` field is `true`. See
[docs/ai-dispatch-parity.md](docs/ai-dispatch-parity.md).

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

In a Laravel host that binds a `SkillLibrary` (1.0.6+), one artisan command
fans your whole skill + agent library out to every installed CLI's native
surface — codex/gemini/grok/cursor/qwen skill dirs and copilot/kimi/kiro
instruction files — and re-propagates MCP in the same pass. It is symlink-safe
and fingerprint-lazy, so re-running it is cheap and idempotent:

```bash
php artisan superaicore:sync-cli                         # skills + MCP → every installed CLI
php artisan superaicore:sync-cli --skills-only --backends=codex,gemini
```

`TaskRunner` also runs this skill sync lazily before each CLI dispatch (one
fingerprint compare), so the command is for manual / cron / git-hook refreshes.
When no `SkillLibrary` is bound it prints a skip line and does nothing.

No config needed. Running without `--dry-run` shells out to the backend CLIs (`claude`, `codex`, `gemini`, `copilot`, `kiro-cli`, `cursor-agent`, `grok`) — install whichever ones you intend to target:

```bash
npm i -g @anthropic-ai/claude-code
brew install codex        # or: cargo install codex
npm i -g @google/gemini-cli
npm i -g @github/copilot   # then `copilot login` (OAuth device flow)
# kiro-cli — download from https://kiro.dev/cli/ then `kiro-cli login`
# (or export KIRO_API_KEY=ksk_... for headless Pro / Pro+ / Power subscribers)
curl https://cursor.com/install -fsS | bash   # then `cursor-agent login` (1.0.0+)
curl -fsSL https://grok.com/install.sh | bash  # then `grok login` (1.0.0+)
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

SuperAICore ships 15 bundled provider types (`builtin`, `moonshot-builtin`, `anthropic`, `anthropic-proxy`, `bedrock`, `vertex`, `google-ai`, `openai`, `openai-compatible`, `openai-responses`, `lmstudio`, `deepseek`, `qwen-anthropic`, `grok`, `kiro-api`) — each described in `Services\ProviderTypeRegistry::bundled()` with label, icon, form fields, env-var name, base-url env, allowed backends, and an `extra_config → env` map. Host apps can rebrand a bundled type (e.g. point `label_key` at a host-owned lang namespace) or add an entirely new type via a single config block — no fork required:

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

// 1.0.8+ — expose a scoped MCP-server subset to the chat turn (Claude only;
// default stays a locked-empty MCP surface). See docs/advanced-usage.md §12.
$response = $backend->streamChat($prompt, $onChunk, [
    'mcp_mode'        => 'file',
    'mcp_config_file' => $subsetJsonPath,   // {"mcpServers": {...}}
]);
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

**0.9.0 — no migration; SDK pin moves to `^0.9.7`.** Six additive integrations of jcode-borrowed primitives shipped in SuperAgent SDK 0.9.7. All opt-in via env flag and degrade to no-op when their host wiring is absent — pre-0.9.7 behaviour preserved unless you flip the corresponding switch. Only `agent_grep` is **on by default** (read-only, no external deps).

```bash
composer update forgeomni/superaicore forgeomni/superagent
# no `php artisan migrate` needed — no schema change in 0.9.0
```

New env knobs (all optional except where noted):

```dotenv
# ─── Builtin SuperAgent tools (0.9.7) ───
# jcode-style `agent_grep` — enclosing-symbol context + per-session
# seen-chunk truncation. Default ON because it's read-only and only
# fires on dispatches that actually drive an agentic loop with tools.
# Set to false for byte-identical pre-0.9.7 behaviour.
AI_CORE_TOOLS_AGENT_GREP=true

# SDK 0.9.7 FirefoxBridgeTool (`browser`) — drives a real Firefox /
# Chromium tab via Native Messaging. Default off; flip to true when
# the launcher is installed.
AI_CORE_TOOLS_BROWSER=false
# Path to the launcher binary expected by the WebExtension. The tool
# itself returns an explanatory error when this isn't set, so you can
# leave AI_CORE_TOOLS_BROWSER=true without crashing the loop.
SUPERAGENT_BROWSER_BRIDGE_PATH=/abs/path/to/forgeomni-bridge-launcher

# ─── Browser-screenshot store (0.9.7) ───
# Backs ProcessEntry::$latest_screenshot_url. SuperAgentBackend writes
# every base64 PNG the `browser` tool returns; AiProcessSource purges
# on reap. Defaults are sensible.
AI_CORE_BROWSER_SHOTS_DISK=local
AI_CORE_BROWSER_SHOTS_DIR=super-ai-core/browser-screenshots

# ─── Embeddings (used by SemanticSkillReranker + SDK SemanticSkillRouter) ───
# Optional Ollama daemon for the semantic skill reranker. When unset
# the reranker degrades to BM25 ordering — no behaviour change for
# hosts that haven't opted in.
AI_CORE_EMBEDDINGS_OLLAMA_URL=http://127.0.0.1:11434
AI_CORE_EMBEDDINGS_OLLAMA_MODEL=nomic-embed-text
AI_CORE_EMBEDDINGS_TIMEOUT_MS=10000

# ─── Cross-harness session resume (0.9.7) ───
# Off by default — the importers can see every operator's history on
# shared machines (~/.claude, ~/.codex), so opt-in only.
AI_CORE_RESUME_ENABLED=false
```

Six things worth reviewing on upgrade:

1. **`agent_grep` quietly enriches every SuperAgent tool-loop dispatch.** The tool is in the SDK's `BuiltinToolRegistry::classMap`, so `load_tools` resolves it through `ToolLoader` — only fires when the dispatch actually runs tools (i.e. `max_turns > 1` or an explicit `load_tools` array). One-shot calls and CLI-backed dispatches are completely unaffected. Set `AI_CORE_TOOLS_AGENT_GREP=false` if your tests assert on the exact tool list.

2. **`browser` tool is a manual install.** The SDK ships `FirefoxBridgeTool` but not the WebExtension or the launcher binary. Setup walkthrough lives in the SDK class docblock at `vendor/forgeomni/superagent/src/Tools/Browser/FirefoxBridge.php`. Until the launcher is installed and `SUPERAGENT_BROWSER_BRIDGE_PATH` points at it, the tool returns explanatory errors so the agent stops looping — safe to enable the flag ahead of installing the launcher.

3. **Browser screenshots round-trip via `external_label`.** `SuperAgentBackend::resolveScreenshotKey()` and `AiProcessSource::screenshotKeys()` both prefer `external_label` first, then the composite `aiprocess.<id>` key. Hosts that already pass `external_label` on `Dispatcher::dispatch()` (the standard convention since 0.6.6) get the round-trip for free. Hosts that don't will see screenshots stored under random keys — set `external_label` on dispatch to align them with the Process Monitor row.

4. **`SemanticSkillReranker` now resolves through the SDK.** The pre-0.9.0 hand-rolled Ollama HTTP client and callback adapter are gone — the reranker pulls a SuperAgent SDK 0.9.7 `EmbeddingProvider` from the container singleton built by `EmbeddingProviderFactory`. Three resolution paths: explicit `super-ai-core.embeddings.provider` (host wires its own), `super-ai-core.embeddings.callback` (auto-wraps as `CallableEmbeddingProvider`), or `super-ai-core.embeddings.ollama_url` (`OllamaEmbeddingProvider`). When none is set, the reranker is a clean no-op — same contract as before.

5. **`/usage` gains a "By Source" card.** `Dispatcher::resolveUsageSource()` writes `metadata.usage_source` (default `'user'`). SuperAgent's `AmbientWorker` tags background ticks with `'ambient'` via its `tagUsage` callback — when you wire the worker, those rows show up in the new dashboard card without re-instrumenting host cost code. Layout reflows to `col-lg-3` on wide viewports so existing By Task Type / By Model / By Backend cards stay legible.

6. **`/processes` Resume dropdown is opt-in.** `AI_CORE_RESUME_ENABLED=false` keeps the dropdown hidden and the controller endpoints return 403. Set to `true` only on machines where exposing every operator's `~/.claude` / `~/.codex` history to the dashboard is acceptable. To wire host-side re-dispatch (rather than just inline transcript display), set `super-ai-core.resume.on_load` to a callable returning `{redirect: '<url>'}`:

    ```php
    // config/super-ai-core.php
    'resume' => [
        'enabled' => env('AI_CORE_RESUME_ENABLED', true),
        'on_load' => function (string $harness, string $sessionId, array $messages) {
            // $messages is list<\SuperAgent\Messages\Message> — feed into your runner.
            $task = MyChatSession::createFromHarnessImport($harness, $sessionId, $messages);
            return ['redirect' => route('chat.show', $task)];
        },
    ],
    ```

See [docs/advanced-usage.md §17–§21](docs/advanced-usage.md) for full recipes — Ollama embedder wiring, browser launcher setup, AmbientWorker tick loop, harness resume callback patterns.

**0.9.1 — one new migration; SDK pin moves to `^0.9.8`.** Five additive
SuperAgent SDK 0.9.8 companion bindings (durable goal store, three-tier
approval gate, workspace plugin manifest, headless `/v1/usage` JSON,
`cache_hit_rate` aggregation) plus one backend hardening fix
(`SuperAgentBackend::resolveEmbeddingProvider()` no longer throws when
the package ServiceProvider hasn't booted).

```bash
composer update forgeomni/superaicore forgeomni/superagent
php artisan migrate    # creates `ai_goals` (the only new table)
```

No new env knobs are mandatory — every binding is a singleton resolved
through the container with sane defaults. Hosts that want to override
the goal store, lock down approvals, or pre-seed a workspace plugin
manifest do so in code:

```php
// config/super-ai-core.php  (optional)
return [
    // …existing keys…

    // Approval gate default mode. Suggest = mutations need /approve;
    // Auto = let everything through except destructive shell ops;
    // Never = pure read-only. Per-thread overrides live on
    // AiProcess.approval_mode (host migration if you want them
    // persisted).
    'runner' => [
        'approval_mode' => env('AI_CORE_APPROVAL_MODE', 'suggest'),
    ],
];
```

Six things worth reviewing on upgrade:

1. **`ai_goals` table is opt-in.** `php artisan migrate` creates it but the binding only writes rows when something resolves `app(\SuperAgent\Goals\GoalManager::class)` and calls `setActiveGoal()` / `pause()` / etc. Hosts that don't use the goal primitive can leave the table empty — there is no automatic stamping from `Dispatcher::dispatch()`.

2. **Custom `GoalStore` swaps in via container rebind.** If you already keep goals in your own table, override the binding before `app(GoalManager::class)` is first resolved:

    ```php
    // app/Providers/AppServiceProvider.php::register()
    $this->app->bind(
        \SuperAgent\Goals\Contracts\GoalStore::class,
        \App\Goals\MyGoalStore::class,
    );
    ```

    The `EloquentGoalStore` shipped here is a reference implementation, not a hard dependency.

3. **`ApprovalGate` is wired but the host owns the loop.** The gate is a pure decision function — `evaluate($toolName, $arguments, $mode, $toolUseId, $approvedToolUseId)` returns `ApprovalDecision::allow()` / `suggestApproval()` / `hardDeny()`. Hosts call it inside their tool-dispatch wrapper before forwarding to the backend, render the suggestion in their UI, and pass the user's `/approve` token back as `$approvedToolUseId` on the retry. There's no backend-side enforcement yet — opting in is one wrap call away in your runner.

4. **`/v1/usage` is unauthenticated by default.** The route is registered in `routes/web.php` under the package's standard prefix. Wrap the surrounding route group (or the per-route middleware) with whatever your host uses for API auth — `auth:sanctum`, signed URLs, an internal-only IP allowlist. The controller does not assume a session is present and will happily serve aggregate cost data to any caller that reaches it. See `routes/web.php` for the registration site.

5. **`cache_hit_rate` lands on every row with a non-zero cache slice.** Existing dashboards keep working; new code can read `metadata.cache_hit_rate` directly without re-deriving the denominator. Distinguishes "no cache eligible" (key absent) from "0% hit rate" (key present, value `0.0`). Also accepts the legacy `cache_hit_tokens` alias from DeepSeek V3 / R1 wires — older host code that stamped the alias on usage records is forward-compatible.

6. **Backend hardening fix removes a latent crash for non-Laravel hosts.** `SuperAgentBackend::resolveEmbeddingProvider()` and `configBool()` now wrap container lookups in a try/catch. Hosts that ran the backend before booting the package ServiceProvider (pure-PHPUnit tests, custom CLI entrypoints) previously hit a `BindingResolutionException`; now they degrade silently to "no embedder" / config defaults. No code change required from host side — it just stops crashing.

See [docs/advanced-usage.md §22–§26](docs/advanced-usage.md) for full recipes — durable goal store override, approval gate wiring inside a host runner, workspace plugin manifest format + diff loop, `/v1/usage` cookbook (curl examples + Grafana JSON datasource), `cache_hit_rate` dashboard recipes.

**0.9.2 — no migration; TaskRunner reliability wave is opt-in.** This
release adds per-run backend fallback for `Runner\TaskRunner` when the
primary backend fails with quota/rate-limit style output, plus the
host-facing policy, continuation, observability, and rollout patterns that
make it usable for long operator jobs. Existing calls keep the old
single-backend behaviour unless you pass `fallback_chain`, set
`super-ai-core.task_fallback.chain`, or enable automatic fallback.

```bash
composer update forgeomni/superaicore
php artisan vendor:publish --tag=super-ai-core-config --force   # optional, to pick up task_fallback defaults
```

Optional env knobs:

```dotenv
AI_CORE_TASK_FALLBACK_AUTO=false
AI_CORE_TASK_FALLBACK_CHAIN=claude_cli,codex_cli,gemini_cli
AI_CORE_TASK_FALLBACK_CHECK_AVAILABILITY=false
AI_CORE_TASK_FALLBACK_INHERIT_CONTEXT=true
```

Six things worth reviewing on upgrade:

1. **Fallback is per-run, not sticky.** The requested backend is always
   tried first, so a recovered primary backend naturally takes traffic
   again on the next task.

2. **Keep fallback chains workload-specific.** Coding tasks may prefer
   `claude_cli → codex_cli → gemini_cli`, research/summarisation may include
   `kimi_cli`, and direct HTTP backends are usually best as the final
   headless stop. Start with per-call `fallback_chain` before promoting a
   chain into global config.

3. **Fallback only continues on matching failures.** Defaults cover common
   quota/rate-limit wording (`rate limit`, `usage limit`, `quota`, `429`,
   `too many requests`, `usage_not_included`). Prompt validation errors,
   tool failures, and other non-matching failures stop on the original
   backend unless you extend `fallback_on`.

4. **Use TaskRunner fallback before queue retry.** A queue retry reruns the
   whole job; fallback keeps the same logical run moving and can pass a
   compact failure/log excerpt to the next backend. This is usually the
   better first recovery step for long tasks.

5. **Hosts can persist the attempt report.** `TaskResultEnvelope` now has
   `fallbackReport`, and `toArray()` includes `fallback_report`. If your
   host stores the envelope metadata, allow this new nullable key. UI can
   render "primary limited, continued on codex" and link each attempt to its
   `log_file`.

6. **Use the report for reliability analytics.** Correlate
   `fallback_report[*].backend` with `ai_usage_logs.backend` to identify
   primaries that frequently hit quota and secondaries that actually finish
   the work. Reorder `auto_chain` from that evidence, not guesswork.

See [docs/advanced-usage.md §27](docs/advanced-usage.md) and
[docs/task-runner-quickstart.md](docs/task-runner-quickstart.md) for the
full TaskRunner fallback recipe.

**0.9.5 — no migration; view-render fix.** Two Blade attribute-encoding
fixes on the `/processes` and `/usage` index pages. No backend, config,
or API surface moved. Hosts that customised
`resources/views/processes/index.blade.php` or
`resources/views/usage/index.blade.php` should mirror the new
`@php($var = …)` + `@json($var)` block pattern when reintroducing
their overrides — building the side-panel payload inline inside a
single-quoted HTML attribute can produce malformed markup on rows
whose screenshot URL or metadata blob contains quotes / ampersands.
Pure runtime change.

**0.9.6 — no migration; SDK pin moves to `^1.0`.** Squad multi-agent
backend + six SDK 0.9.8 / 1.0.0 companion bindings. Every binding is
additive and opt-in — pre-0.9.6 behaviour preserved unless you enable
a flag, pass a new option, or resolve a new service from the
container.

```bash
composer update forgeomni/superaicore forgeomni/superagent
php artisan vendor:publish --tag=super-ai-core-config --force   # optional; picks up the new config blocks
# no `php artisan migrate` needed — no schema change in 0.9.6
```

Optional env knobs (all default to safe values; the package ships
without any of them set):

```dotenv
# ─── Squad multi-agent (SDK 1.0.0) ───
AI_CORE_SQUAD_ENABLED=true
AI_CORE_SQUAD_BACKEND_ENABLED=true
AI_CORE_SQUAD_MAX_COST=0              # 0 disables the cap
AI_CORE_SQUAD_CHECKPOINT_DIR=         # default: storage/app/squad/

# ─── /model auto routing (SDK 0.9.8) ───
AI_CORE_AUTO_MODEL=true
AI_CORE_AUTO_MODEL_PRO=               # null → SDK default (deepseek-v4-pro)
AI_CORE_AUTO_MODEL_FLASH=             # null → SDK default (deepseek-v4-flash)
AI_CORE_AUTO_MODEL_LONG_CTX=32000
AI_CORE_AUTO_MODEL_TOOL_DEPTH=3
AI_CORE_AUTO_MODEL_SCORE_CATALOG=     # optional path to a ScoreCatalog JSON

# ─── Cache-aware compaction (SDK 0.9.8) ───
AI_CORE_COMPRESSION_CACHE_AWARE=true
AI_CORE_COMPRESSION_PIN_HEAD=4
AI_CORE_COMPRESSION_PIN_SYSTEM=true

# ─── Per-provider token-bucket rate limiter (SDK 0.9.8) ───
AI_CORE_RL_DEFAULT_RATE=8.0
AI_CORE_RL_DEFAULT_BURST=16

# ─── Untrusted input wrapping (SDK 0.9.8) ───
AI_CORE_UNTRUSTED_INPUT=true

# ─── Sub-agent depth cap (SDK 0.9.8) ───
AI_CORE_AGENT_MAX_DEPTH=0             # 0 → SDK default (5)

# ─── DeepSeek FIM (SDK 0.9.8) ───
DEEPSEEK_API_KEY=
```

Eight things worth reviewing on upgrade:

1. **Squad is gated by SDK availability.** `BackendRegistry` only
   registers `SquadBackend` when `super-ai-core.backends.squad.enabled`
   is on AND the SDK 1.0.0 classes are present (`PeerOrchestrator`,
   `TaskDecomposer`, `ModelTierMap`, `SquadCheckpointStore`). Hosts
   that didn't move to SDK 1.0.0 see zero behaviour change — Squad
   reports itself unavailable and the Dispatcher falls back to the
   other nine adapters.

2. **Squad pipelines persist per-step checkpoints.** Mid-run failures
   leave the checkpoint on disk; re-dispatch with the same `squad_id`
   and `checkpoint_dir` to resume. Default `checkpoint_dir` lands
   inside `storage/app/squad/` so Laravel's storage permissions are
   already in scope. Override per-call via `options.checkpoint_dir`
   or globally via `AI_CORE_SQUAD_CHECKPOINT_DIR`.

3. **`AutoModelRouter` is a host service, not a backend dependency.**
   Resolving `app(\SuperAICore\Services\AutoModelRouter::class)` and
   calling `select($messages, $systemPrompt, $options)` returns the
   model id this dispatch should target. Wire it into your custom
   dispatcher / planner when you want the SDK's heuristic without
   coupling to the SuperAgent backend. Hosts that don't resolve the
   service see no change.

4. **`CompressionStrategyFactory` is opt-in for hosts that drive their
   own `ContextManager`.** The default `SuperAgentBackend` flow is
   one-shot (`max_turns=1`) and doesn't construct a `ContextManager`
   at all. Hosts running long sub-agent loops or browser-tool sessions
   call `app(\SuperAICore\Services\CompressionStrategyFactory::class)->build(…)`
   when wiring their own context manager; the factory returns a
   `CacheAwareCompressor` around the bundled `ConversationCompressor`
   so summary boundaries land AFTER the cache prefix.

5. **`UntrustedInputHelper` covers free-form text the SDK doesn't
   already wrap.** SDK 0.9.8's `Goals\GoalManager` auto-wraps
   `goal.objective` via the `continuation.md` template — DO NOT
   double-wrap there. This helper is for ad-hoc memory entries,
   workspace plugin descriptions, MCP tool docs imported from
   third-party servers, and any host UI form input you concatenate
   into a system prompt. Disabled via `AI_CORE_UNTRUSTED_INPUT=false`
   when you need byte-identical prompts (tests, dispatch
   comparisons).

6. **Rate limiter is per-process.** Distributed swarms (one agent per
   pod) need a shared limiter — the cleanest path there is a Redis-
   backed Guzzle middleware on the provider's HTTP client; this
   registry stays simple and DOES NOT compete with that. Defaults
   match the SDK's per-call 429 retry budget (8 RPS / 16 burst);
   per-provider overrides go in `super-ai-core.rate_limits.<provider>`.

7. **`reasoning_effort` is a per-call option on
   `Dispatcher::dispatch()`.** Three tiers (`off` / `high` / `max`).
   Routes to the right body shape per upstream (top-level
   `reasoning_effort` for most providers, `chat_template_kwargs` for
   NVIDIA NIM, etc.). Silently ignored by providers that don't
   implement `SupportsReasoningEffort`. Also feeds the
   `AutoModelRouter` escalation heuristic when set to `max`.

8. **`smart` and `squad` console commands.** Both passthrough to the
   vendor `superagent` binary (`vendor/forgeomni/superagent/bin/superagent`).
   Reuse the operator's existing SuperAgent credentials and SDK CLI
   behaviour rather than re-implementing the orchestrator in PHP:
   ```bash
   ./vendor/bin/superaicore smart "audit this diff"
   ./vendor/bin/superaicore smart show --last
   ./vendor/bin/superaicore squad "refactor the auth module" --max-cost=2.0
   ./vendor/bin/superaicore squad --no-squad "compare against legacy path"
   ```
   Pass `--binary=/abs/path/to/superagent` when the SDK is installed
   outside `vendor/`.

See [docs/advanced-usage.md §28](docs/advanced-usage.md) for full
recipes — Squad pipelines, AutoModelRouter integration,
CacheAwareCompressor wiring, RateLimiterRegistry overrides,
AdHocMemoryRegistry chat-UI integration, ConversationForkService
side-panels, DeepSeek FIM completion endpoints.

**0.9.7 — four new migrations; SDK pin moves to `^1.0.5`.** SDK 1.0.5
capability bump (cross-provider handoff transcoder fixes, opencode
`BashArity` permission matching, opencode 7-section structured
compactor summary, the real LSP client + `LSPTool`, `LlmLoopChecker`
semantic loop detection, ACP v1 stdio server, Gemini 3.5 / 3.x with
thinking + grounding + thought-parts) plus ten opencode-borrowed
features (per-file diff summaries with revert, mid-run HITL question
tool, snapshot retention, session reminders, per-agent permission
rulesets, sub-agent permission derivation, plan mode, PTY long-lived
shell sessions, session-share host queue). Every binding is additive
and opt-in — pre-0.9.7 behaviour preserved unless you flip the
corresponding flag.

```bash
composer update forgeomni/superaicore forgeomni/superagent
php artisan vendor:publish --tag=super-ai-core-migrations
php artisan migrate
php artisan vendor:publish --tag=super-ai-core-config --force   # optional; picks up the new config blocks
```

The four migrations are additive + reversible:

- `2026_05_20_000001_add_diff_summary_and_snapshots_to_ai_usage_logs.php`
  — `ai_usage_logs` gains `pre_snapshot` (varchar 64, nullable),
  `post_snapshot` (varchar 64, nullable), `file_diff_summary` (json,
  nullable). Pre-existing rows get NULL; new dispatches through
  `SuperAgentBackend` populate them automatically.
- `2026_05_20_000002_create_ai_user_questions_table.php` — new table
  backing the `ask_user` HITL tool.
- `2026_05_20_000003_create_ai_pty_sessions_table.php` — new table
  backing the PTY long-poll endpoints.
- `2026_05_20_000004_create_ai_session_shares_table.php` — new table
  backing the session-share host queue.

Optional env knobs (every flag defaults to safe / opt-out values):

```dotenv
# ─── Shadow-git snapshots + per-file diff summary ───
AI_CORE_SNAPSHOT_ENABLED=true
AI_CORE_SNAPSHOT_PROJECT_ROOT=          # null → base_path() → getcwd()
AI_CORE_SNAPSHOT_RETENTION_DAYS=7
AI_CORE_SNAPSHOT_REVERT_ENABLED=true    # POST /usage/{id}/revert

# ─── Mid-run HITL `ask_user` tool ───
AI_CORE_TOOLS_ASK_USER=false            # off by default; flip on to expose the tool

# ─── SDK 1.0.5 LSP tool ───
AI_CORE_TOOLS_LSP=false                 # off by default; flip on for the lsp tool

# ─── Opencode structured compactor summary ───
AI_CORE_COMPRESSION_SUMMARY_PROMPT=     # set to "structured" to opt in globally

# ─── CLI plan mode (Modes\CliPlanOrchestrator) ───
AI_CORE_PLAN_ENABLED=true
AI_CORE_PLAN_BACKEND=cli:claude_cli
AI_CORE_PLAN_BUILD_BACKEND=cli:claude_cli
AI_CORE_PLAN_DIR=.superagent/plans
AI_CORE_PLAN_AUTO_APPROVE=              # null → auto-detect (HITL on = wait, off = approve)
AI_CORE_PLAN_APPROVAL_TIMEOUT=600

# ─── PTY long-lived shell sessions ───
AI_CORE_PTY_ENABLED=false               # off by default; opt-in per deployment

# ─── Session share host queue ───
AI_CORE_SHARE_ENABLED=false
AI_CORE_SHARE_REMOTE_URL=               # remote sharer base URL (POST /api/shares/{id})
AI_CORE_SHARE_SECRET=                   # Bearer token sent to the remote sharer
AI_CORE_SHARE_LOCAL_URL_TEMPLATE=       # fallback when no remote; {share_id} placeholder
```

Per-agent permission rulesets, session reminders, and the snapshot
prune scheduler live in `super-ai-core.php`:

```php
// config/super-ai-core.php

'agents' => [
    'plan' => [
        'permission' => [
            '*'    => 'allow',
            'edit' => ['*' => 'deny', '*.md' => 'allow'],
            'write'=> ['*' => 'deny', '*.md' => 'allow'],
        ],
    ],
    'explore' => [
        'permission' => [
            '*'     => 'deny',
            'read'  => 'allow',
            'grep'  => 'allow',
            'glob'  => 'allow',
            'bash'  => 'allow',
        ],
    ],
],

'reminders' => [
    'rules' => [
        [
            'name' => 'plan-mode-active',
            'when' => ['agent' => 'plan'],
            'text' => "## Plan mode active\nWrite the plan to `.superagent/plans/{session}.md`. Do NOT call any edit/write tool against the project worktree.",
        ],
    ],
],
```

Schedule snapshot pruning from `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    $schedule->command('super-ai-core:snapshot-prune')->dailyAt('02:00');
}
```

Eleven things worth reviewing on upgrade:

1. **Per-file diff summary fires automatically.** When SDK 1.0.5's
   `GitShadowStore` can checkpoint the worktree, every
   `SuperAgentBackend::generate()` dispatch lands `pre_snapshot` /
   `post_snapshot` / `file_diff_summary` on the UsageLog row. The
   `/usage` page renders a `+N −M` badge per row. Disable globally
   with `AI_CORE_SNAPSHOT_ENABLED=false` if you want byte-identical
   pre-0.9.7 behaviour.
2. **HITL `ask_user` tool is OFF by default.** The polling-loop
   semantics in `AskUserTool::execute()` block the agent for up to
   `timeout_seconds` (default 600), which is intentional for
   developer-facing use but inappropriate for a queue worker that
   needs to recycle. Flip `AI_CORE_TOOLS_ASK_USER=true` only when a
   human will be answering questions in front of `/processes`.
3. **`/processes/questions` poll cadence.** The UI polls every 4s.
   When you scale up to many concurrent agents asking questions,
   raise `AI_CORE_QUESTIONS_POLL_INTERVAL` (defaults to the 500ms
   server-side polling baked into `AskUserTool`) — the cost is
   nearly entirely server-side polling, not browser fanout.
4. **Revert is a write — secure it like one.** The route is gated by
   `AI_CORE_SNAPSHOT_REVERT_ENABLED` (default true) AND inherits the
   `super-ai-core.route.middleware` list. On multi-tenant deployments
   add an authorization middleware before exposing `/usage/{id}/revert`.
5. **`super-ai-core:snapshot-prune` is per-host.** It walks
   `~/.superagent/history/` for the user that ran the command. In a
   multi-user box, schedule it once per user (or normalise the shadow
   dir via `SUPERAGENT_HISTORY_DIR=/var/lib/superagent/history`).
6. **Per-agent permission ruleset is consulted ONLY when the caller
   didn't pass `allowed_tools` / `denied_tools`.** Explicit per-call
   lists override the config-driven ruleset. This is intentional —
   tooling layers above SuperAICore (PPT, SuperTeam, codex) already
   compute their own deny lists and shouldn't be overridden silently.
7. **Plan mode is registered with `CliModeRouter` under mode name
   `plan`.** Dispatch with `app(CliModeRouter::class)->dispatch('plan',
   $task, $ctx)`. When HITL is disabled, the orchestrator auto-approves
   (intentional — keeps the orchestrator usable in CI). Set
   `AI_CORE_PLAN_AUTO_APPROVE=false` to override.
8. **Sub-agent permission derivation reads two signals.** Either pass
   `parent_denied_tools` explicitly in the child dispatch options, or
   pass `metadata.parent_agent` and let `PermissionEvaluator` resolve
   the parent's ruleset. The deny set is monotonic across parent →
   child — children can never elevate.
9. **PTY is Phase 1 (long-poll, no stdin).** The `/pty/sessions/{id}/write`
   endpoint returns 501 because PHP can't keep a pipe alive across HTTP
   requests without a persistent worker. Use a client-side `expect`-style
   command when input is required, or wait for Phase 2 (WebSocket via
   Laravel Reverb / Soketi).
10. **Session sharing has two modes.** REMOTE (`AI_CORE_SHARE_REMOTE_URL`
    set) POSTs UsageLog rows + `file_diff_summary` to an external sharer
    with a Bearer token. LOCAL (`AI_CORE_SHARE_LOCAL_URL_TEMPLATE` set)
    renders the URL against the host's own SuperAICore — useful for
    intranet deployments where "share with a colleague" means "give them
    a link to the same Laravel instance".
11. **SDK 1.0.5 Gemini 3.5 features pass through verbatim.**
    `thinking` / `grounding` / `google_search` / `url_context` per-call
    options forward to `Agent::run($prompt, $options)` and are silently
    ignored by non-Gemini providers. `EngineCatalog` lists
    `gemini-3.5-pro / -flash / -flash-lite` for the gemini-cli engine.

See [docs/advanced-usage.md §29](docs/advanced-usage.md) for full
recipes — per-file diff dashboard, AskUserTool integration, revert
button, plan mode workflow, per-agent permission rulesets, sub-agent
permission inheritance, PTY long-poll integration, session-share host
queue, snapshot retention scheduling.

**1.0.0 — first stable release; no migration; SDK pin moves to `^1.0.9`.**
Additive across the board — no schema changes, no config publish required.
The public API is now stable per SemVer (see `docs/api-stability.md`). Four
things worth knowing:

1. **Claude Opus 4.8 is the new flagship.** SDK 1.0.9 promotes
   `claude-opus-4-8` (takes the `opus` alias; native 1M context, interleaved
   thinking, fast mode, effort control). `ClaudeModelResolver`, the `claude`
   engine catalog, `model_pricing`, and the `squad` / `cli_squad` **expert**
   tiers now point at 4.8. Hosts pinning an explicit older Opus id keep
   working — the older ids stay in the catalog.
2. **xAI Grok lands on two channels.** (a) The metered **API** provider type
   `grok` routes through the `superagent` backend (`XAI_API_KEY` /
   `GROK_API_KEY`, default `grok-4.3`). (b) The **subscription CLI** engine
   `grok_cli` (binary `grok`, grok.com login) is a separate channel. They
   share the brand, nothing else.
3. **Two new subscription CLI engines.** `cursor_cli` (Cursor Composer,
   `cursor-agent`) and `grok_cli` (Grok Build). Both `builtin`-login,
   subscription-billed ($0 usage rows, shadow cost from the catalog). Enabled
   by default; disable via `AI_CORE_CURSOR_CLI_ENABLED=false` /
   `AI_CORE_GROK_CLI_ENABLED=false`. They auto-surface in `/providers`,
   `cli:status`, model pickers, the cost dashboard, and the Process Monitor.
4. **Nothing to undo.** Pre-1.0.0 callers see byte-identical behaviour;
   downgrading the SDK to 1.0.7 still works for pinned hosts.

See [docs/advanced-usage.md §30](docs/advanced-usage.md) for the Cursor /
Grok CLI onboarding recipe, Opus 4.8 routing, and the Grok API-vs-CLI
channel split.

**1.0.2 — kimi-cli → kimi-code transition; no migration; SDK pin moves to
`^1.0.10`.** Additive across the board — no schema changes, no config publish
required. Two things worth knowing:

1. **The `kimi_cli` backend now supports both kimi CLIs.** Moonshot's new
   `@moonshot-ai/kimi-code` (TypeScript) replaces the legacy Python
   `MoonshotAI/kimi-cli`; both publish the same `kimi` binary with an
   incompatible headless surface + stream-json shape. `KimiCliBackend`
   auto-detects which is installed (a cached `kimi --help` probe — legacy has a
   `--print` flag, kimi-code doesn't) and adapts argv + parsing across all four
   spawn paths. Pin the dialect with `AI_CORE_KIMI_CLI_VARIANT` (`auto` default
   / `kimi-code` / `kimi-cli`) to skip probing during the transition. The
   `kimi_cli` Dispatcher backend id, `/providers` card, and model pickers are
   unchanged. (Agent-sync for kimi-code's `.agents/` model is a tracked
   follow-up; `KimiAgentSync` still writes the legacy `~/.kimi/agents/` layout.)
2. **SDK 1.0.10 hardens the Kimi HTTP path — transparently.** The pin moves
   `^1.0.9` → `^1.0.10`. The direct-HTTP `kimi` / `qwen` / `glm` / `deepseek` /
   `grok` / `openrouter` / `openai` provider types (routed through the
   `superagent` backend) get streaming `usage` accounting back
   (`stream_options.include_usage` — streamed calls no longer record $0), strict
   tool-schema normalization, `max_completion_tokens` for Kimi reasoning models,
   and per-model capability discovery. New opt-in `SUPERAGENT_KIMI_SWARM_ENABLED`
   gate. Nothing to undo — pre-1.0.2 callers see identical behaviour.

See [docs/advanced-usage.md §31](docs/advanced-usage.md) and
`docs/kimi-cli-backend.md` §8 for the variant-detection recipe and the
kimi-cli/kimi-code flag matrix.

**1.0.5 — SmartFlow cross-CLI workflows; no migration; SDK pin moves to
`^1.1.0`.** Additive across the board — no schema changes; publish the config
only if you customize it (`php artisan vendor:publish --tag=super-ai-core-config`).
Three things worth knowing:

1. **New `flow` command — cross-CLI dynamic workflows.** SuperAICore ports
   Claude Code's built-in `Workflow` as **SmartFlow** (`src/SmartFlow/`): one set
   of primitives (`agent` / `parallel` / `pipeline` / `gate` / `council` /
   `budget` / `schema`) drives any registered backend, so one flow can plan on
   `claude_cli` and review on `codex_cli` + `gemini_cli` concurrently. Four
   built-in flows ship under `resources/flows/*.yaml`; rehearse any of them at
   zero cost without a CLI installed:
   ```bash
   ./vendor/bin/superaicore flow list
   ./vendor/bin/superaicore flow run cross-cli-review --args diff=@my.diff --rehearse
   ./vendor/bin/superaicore flow run cross-cli-dev --args goal="add caching" --concurrency 4
   ```
   Also mounted on artisan as `php artisan flow ...`. Per-run ledgers live under
   `~/.superaicore/flows` (override with `SUPERAICORE_FLOW_DIR`); `--resume <id>`
   replays the unchanged prefix at zero cost. New config block
   `super-ai-core.smartflow.*` (`default_backend`, `concurrency`, `ledger_dir`,
   `flows_dir`, `budget`, `personas`) + `AI_CORE_SMARTFLOW_*` env.

2. **Federation with superagent.** A flow can delegate a sub-flow to
   superagent's own (cross-model) SmartFlow — `Flow::delegate()` or
   `strategy: delegate` in YAML. **named** mode runs one of superagent's own
   flows (it self-dispatches across model providers); **spec** mode runs a flow
   whose structure SuperAICore authored (superagent executes to instruction).
   Requires the SDK on the classpath (it now is, pin `^1.1.0`); a missing SDK or
   unknown flow fails gracefully without crashing the parent flow.
   ```bash
   ./vendor/bin/superaicore flow run cross-cli-federated \
       --args goal="add caching" --args research_provider=openai --rehearse
   ```

3. **SDK 1.1.0 brings its own (cross-model) SmartFlow — transparently.** The pin
   moves `^1.0.10` → `^1.1.0`; the `superagent` backend picks up the SDK's
   SmartFlow plus 1.0.10→1.1.0 wire-level hardening. No SuperAICore code depends
   on the SDK's SmartFlow classes except the optional federation bridge. Nothing
   to undo — pre-1.0.5 callers see identical behaviour.

See [docs/advanced-usage.md §32](docs/advanced-usage.md) and
[docs/smartflow.md](docs/smartflow.md) for the full SmartFlow guide — primitives,
YAML authoring, the structured-output ladder, resume, and the superagent
federation recipe.

**1.0.10 — GLM-5.2 native flagship; no migration; SDK pin moves to `^1.1.2`.**
Additive across the board — no schema changes; publish the config only if you
want the refreshed `model_pricing` table (`php artisan vendor:publish
--tag=super-ai-core-config`). Two things worth knowing:

1. **GLM-5.2 is the new `glm` default.** SDK 1.1.2 promotes `glm-5.2` (Z.ai's
   coding-first agentic flagship: 1M context, 128K max output, text-only) to the
   native `glm` flagship and adds `glm-5.1` (200K context). SuperAICore mirrors
   Z.ai's official rates into its `model_pricing` table — `glm-5.2` / `glm-5.1`
   at **$1.40 in / $4.40 out** per 1M with a **$0.26 cache-hit** input tier,
   `glm-5` at $1.00 / $3.20 — and seeds `glm-5.2` into the `superagent` engine's
   `available_models` so it shows in pickers offline. `CostCalculator` already
   falls back to the SDK `ModelCatalog`, so unlisted GLM SKUs still resolve; the
   explicit rows just keep dashboards accurate without a catalog round-trip. The
   bare `glm` shorthand and the zero-config default now resolve to GLM-5.2;
   `glm-5` / `glm-4.x` stay reachable by id.

2. **`GlmProvider` gains a `reasoning_effort` dial — transparently.** SDK 1.1.2
   makes `GlmProvider` implement `SupportsReasoningEffort` (joining MiniMax M3),
   so the existing per-call option already routes to it:

   ```php
   $dispatcher->dispatch([
       'backend'          => 'superagent',
       'prompt'           => 'Refactor this module for testability.',
       'provider_config'  => ['provider' => 'glm'],   // → glm-5.2
       'reasoning_effort' => 'max',   // off | high | max (off ⇒ thinking disabled)
   ]);
   ```

   No call-site change required — `SuperAgentBackend` forwarded
   `reasoning_effort` / `thinking` generically already, so the dial works the
   moment the SDK lands. Nothing to undo — pre-1.0.10 callers see identical
   behaviour.

See [docs/advanced-usage.md §28](docs/advanced-usage.md) (the `reasoning_effort`
three-tier dial) for the wire shapes per provider.

**1.0.11 — Fable 5 & Sonnet 5; no migration; SDK pin moves to `^1.1.5`.**
Additive across the board — no schema changes; publish the config only if you
want the refreshed `model_pricing` table (`php artisan vendor:publish
--tag=super-ai-core-config`). Three things worth knowing:

1. **Fable 5 and Sonnet 5 are native `anthropic` models now.** SDK 1.1.5 adds
   `claude-fable-5` (Anthropic's most capable model: 1M context, 128K max
   output, always-on adaptive thinking, effort dial) and `claude-sonnet-5`
   (the new `sonnet` flagship on the same Claude-5-generation surface).
   SuperAICore mirrors the official rates into `model_pricing` — Fable 5
   **$10 in / $50 out** per 1M, Sonnet 5 **$3 / $15** (intro $2/$10 through
   2026-08-31; the table carries the official rate) — and seeds both ids into
   the `superagent` engine's `available_models` so they show in pickers
   offline. The `sonnet` alias now resolves to Sonnet 5 SDK-side; every prior
   Claude id stays reachable.

2. **The Opus line got 3× cheaper — dashboards need the new table.** SDK 1.1.5
   corrects stale pricing: current Opus (`claude-opus-4-5`→`4-8`) is **$5/$25**
   per 1M (was $15/$75); Haiku 4.5 is $1/$5. SuperAICore's `model_pricing`
   follows suit (only the dated `claude-opus-4-20250514` snapshot keeps the
   historical $15/$75). If your host published an older config copy, re-publish
   or hand-edit — otherwise `CostCalculator` keeps billing Opus at the old
   rate. Zero-config `anthropic` now resolves to `claude-opus-4-8` SDK-side;
   the SDK Squad EXPERT tier routes to `claude-fable-5`, while SuperAICore's
   own `squad.tiers` config is left unchanged (point `expert` at
   `claude-fable-5` yourself if you want the SDK's tiering).

3. **The `reasoning_effort` dial now reaches Anthropic models —
   transparently.** SDK 1.1.5 makes `AnthropicProvider` implement
   `SupportsReasoningEffort`, mapping the existing per-call option to
   Anthropic's GA `output_config.effort` (Fable 5 / Sonnet 5 / Opus 4.5+ /
   Sonnet 4.6; unsupported models never 400):

   ```php
   $dispatcher->dispatch([
       'backend'          => 'superagent',
       'prompt'           => 'Audit this migration for race conditions.',
       'provider_config'  => ['provider' => 'anthropic', 'model' => 'claude-fable-5'],
       'reasoning_effort' => 'max',   // off | low…high | max
   ]);
   ```

   No call-site change required — `SuperAgentBackend` forwarded
   `reasoning_effort` / `thinking` generically already. The SDK also handles
   the Claude-5 adaptive-only request surface for you (no `budget_tokens`, no
   sampling params, no trailing prefills), which fixes latent 400s on
   Opus 4.7/4.8 as a side effect.

See [docs/advanced-usage.md §34](docs/advanced-usage.md) (Fable 5 & Sonnet 5 —
the adaptive surface and the Anthropic effort dial).

**1.1.0 — ai-dispatch parity wave; no migration; SDK pin unchanged.**
Additive: new standalone + artisan commands `send`, `resume`, `runs`,
`aliases`, `preferences`, `doctor`, and `skill:install-dispatch`, plus a new
`dispatch` config block (`aliases` / `retry_on_classes` / `runs_path` /
`preferences_path` — re-publish the config to see it, or drive it via
`AI_CORE_RUNS_PATH` / `AI_CORE_PREFERENCES_PATH`). The Claude model tables
catch up with the Claude 5 generation (`fable` family; `sonnet` now targets
`claude-sonnet-5`). Standalone-console container-safety hardening
(`Support\ConfigValue`) fixes `bin/superaicore` in dev checkouts. See
[docs/ai-dispatch-parity.md](docs/ai-dispatch-parity.md) and
[docs/advanced-usage.md §35](docs/advanced-usage.md).

**1.1.5 — delegate-in SKILL everywhere; no migration; SDK pin unchanged.**
Additive: `skill:install-dispatch` now also targets Grok / Cursor / Qwen
(`~/.grok/skills`, `~/.cursor/skills-cursor`, `~/.qwen/skills`); `--agent
all` covers all six agents in one pass. The default stays claude-only, and
the new `--uninstall` flag reverses a prior install without touching skills
you authored yourself. Nothing to configure; no config re-publish needed.

## Troubleshooting

- **`Class 'SuperAgent\Agent' not found`** — you disabled `forgeomni/superagent` but left `AI_CORE_SUPERAGENT_ENABLED=true`. Set it to `false` or re-require the SDK.
- **CLI backend missing** — run `which claude` / `which codex`. If empty, install the CLI or override `CLAUDE_CLI_BIN` / `CODEX_CLI_BIN` with an absolute path.
- **Nothing logged to `ai_usage_logs`** — check `AI_CORE_USAGE_TRACKING=true` and that migrations ran.
- **`vendor:publish` prompt is ambiguous** — pass an explicit `--tag` from the list above.
