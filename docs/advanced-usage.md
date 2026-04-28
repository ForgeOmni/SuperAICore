# Advanced usage

[English](advanced-usage.md) · [简体中文](advanced-usage.zh-CN.md) · [Français](advanced-usage.fr.md)

Practical recipes for the SuperAICore features that don't fit on the README. This guide covers the **superagent** backend path specifically — the six CLI backends are documented separately ([streaming-backends](streaming-backends.md), [spawn-plan-protocol](spawn-plan-protocol.md), [task-runner-quickstart](task-runner-quickstart.md)).

All examples target 0.7.0+ unless noted. Features first shipped earlier carry a `(since X.Y.Z)` tag.

## Table of contents

1. [Round-trip idempotency](#1-round-trip-idempotency)
2. [W3C trace context passthrough](#2-w3c-trace-context-passthrough)
3. [Classified provider exceptions](#3-classified-provider-exceptions)
4. [OpenAI Responses API](#4-openai-responses-api)
5. [ChatGPT subscription routing via OAuth](#5-chatgpt-subscription-routing-via-oauth)
6. [Azure OpenAI auto-detection](#6-azure-openai-auto-detection)
7. [LM Studio — local OpenAI-compat server](#7-lm-studio--local-openai-compat-server)
8. [Declarative HTTP headers per provider type](#8-declarative-http-headers-per-provider-type)
9. [SDK feature dispatcher — `extra_body`, `features`, `loop_detection`](#9-sdk-feature-dispatcher)
10. [Prompt-cache keys (Kimi)](#10-prompt-cache-keys-kimi)
11. [Extending the provider-type registry](#11-extending-the-provider-type-registry)
12. [Host CLI spawn via `ScriptedSpawnBackend`](#12-host-cli-spawn-via-scriptedspawnbackend)
13. [Portable `.mcp.json` writes](#13-portable-mcpjson-writes)
14. [SuperAgent host-config adapter — `createForHost`](#14-superagent-host-config-adapter--createforhost)
15. [Mid-conversation provider handoff (`Agent::switchProvider`)](#15-mid-conversation-provider-handoff-agentswitchprovider)
16. [Skill engine — telemetry, ranking, FIX-mode evolution](#16-skill-engine--telemetry-ranking-fix-mode-evolution)

---

## 1. Round-trip idempotency

*Since 0.6.6 (window), 0.7.0 (round-trip).*

SuperAICore has had a 60-second `idempotency_key` dedup window on `ai_usage_logs` since 0.6.6 — pass `idempotency_key` on `Dispatcher::dispatch()` options and repeated calls collapse to one row. 0.7.0 closes the loop through the SDK: the key now travels with `AgentResult::$idempotencyKey` (SDK 0.9.1), so the Dispatcher's write-through reads the key the SDK actually observed rather than re-computing it.

```php
use SuperAICore\Services\Dispatcher;

$dispatcher = app(Dispatcher::class);

// Explicit key — caller knows best.
$r1 = $dispatcher->dispatch([
    'prompt'          => $prompt,
    'backend'         => 'superagent',
    'provider_config' => ['type' => 'anthropic', 'api_key' => env('ANTHROPIC_API_KEY')],
    'idempotency_key' => "checkout:{$order->id}:line:{$line->id}",
]);

// Same call again within 60s → same ai_usage_logs row id.
$r2 = $dispatcher->dispatch([
    'prompt'          => $prompt,
    'backend'         => 'superagent',
    'provider_config' => ['type' => 'anthropic', 'api_key' => env('ANTHROPIC_API_KEY')],
    'idempotency_key' => "checkout:{$order->id}:line:{$line->id}",
]);

assert($r1['usage_log_id'] === $r2['usage_log_id']);
assert($r1['idempotency_key'] === 'checkout:…');  // echoed off the envelope
```

Auto-derivation from `external_label` still works — if you don't pass `idempotency_key` but do pass `external_label`, the Dispatcher uses `"{backend}:{external_label}"`. Pass `idempotency_key => false` to opt out of auto-dedup entirely (rare — every call legitimately distinct).

**Why round-trip matters:** hosts whose Dispatcher runs in a web worker but whose UsageRecorder writes from a queue worker (common in Laravel Horizon setups) no longer need to thread the key through job payloads — it rides along on the envelope. See [docs/idempotency.md](idempotency.md) for the full contract.

---

## 2. W3C trace context passthrough

*Since 0.7.0.*

Forward an inbound `traceparent` header onto every LLM call and the SDK projects it into the OpenAI Responses API's `client_metadata` envelope. Result: OpenAI-side logs + your host's distributed trace join up without a wrapper layer.

```php
// app/Http/Middleware/AttachTraceContext.php
public function handle($request, Closure $next)
{
    // Usually this middleware already runs before your controllers to
    // propagate traceparent / tracestate through the request lifecycle.
    // SuperAICore reads the headers off the Request when dispatched.
    return $next($request);
}

// anywhere a request-scoped call fires:
$result = app(Dispatcher::class)->dispatch([
    'prompt'          => $prompt,
    'backend'         => 'superagent',
    'provider_config' => ['type' => 'openai-responses', 'api_key' => env('OPENAI_API_KEY')],
    'traceparent'     => $request->header('traceparent'),  // safe when null
    'tracestate'      => $request->header('tracestate'),
]);
```

Non-`openai-responses` providers silently ignore the header — safe to pass unconditionally from a shared dispatcher helper. Invalid traceparent strings (not matching the W3C `00-<32hex>-<16hex>-<2hex>` shape) are dropped without error.

If you already minted a `SuperAgent\Support\TraceContext` elsewhere (e.g. a background job that wants to start a root trace), pass it directly:

```php
use SuperAgent\Support\TraceContext;

$trace = TraceContext::fresh();   // random, sampled
$dispatcher->dispatch([
    ...,
    'trace_context' => $trace,
]);
```

---

## 3. Classified provider exceptions

*Since 0.7.0 (host side), SDK 0.9.1 (classification).*

Before 0.7.0 every SuperAgent-backed failure landed in one log bucket: "SuperAgentBackend error: <message>". Now the SDK raises six typed subclasses (`ContextWindowExceeded`, `QuotaExceeded`, `UsageNotIncluded`, `CyberPolicy`, `ServerOverloaded`, `InvalidPrompt`) and `SuperAgentBackend::generate()` catches each individually, emitting a stable `error_class` tag + `retryable` verdict.

Reading the classification in operator telemetry just needs a log drain that indexes the `error_class` field:

```
[warning] SuperAgentBackend error [context_window_exceeded]: context too long
    error_class=context_window_exceeded retryable=false
```

### Smarter routing on failure

The default contract returns `null` on failure so your Dispatcher callers see "no backend gave an answer" and fall through. If you want to react to specific failure modes (compact-then-retry on context overflow, cycle providers on quota exhaustion, backoff on overload), subclass `SuperAgentBackend` and override the `logProviderError` seam to surface the classification onto the envelope:

```php
use SuperAICore\Backends\SuperAgentBackend;

class RoutingSuperAgentBackend extends SuperAgentBackend
{
    public ?string $lastErrorClass = null;

    protected function logProviderError(\Throwable $e, string $code): void
    {
        $this->lastErrorClass = $code;
        parent::logProviderError($e, $code);
    }
}
```

Bind it in your `AppServiceProvider`:

```php
$this->app->extend(\SuperAICore\Services\BackendRegistry::class, function ($registry) {
    $registry->register(new RoutingSuperAgentBackend(logger()));
    return $registry;
});
```

Then in a dispatcher wrapper of your own:

```php
$result = $dispatcher->dispatch($opts);
if ($result === null) {
    $backend = app(\SuperAICore\Backends\SuperAgentBackend::class);
    if ($backend->lastErrorClass === 'context_window_exceeded') {
        // compact history and retry
    } elseif ($backend->lastErrorClass === 'quota_exceeded') {
        // switch to a different provider row and retry
    }
}
```

---

## 4. OpenAI Responses API

*Since 0.7.0.*

The `openai-responses` provider type routes through the SDK's `OpenAIResponsesProvider` against `/v1/responses`. Key advantages over Chat Completions:

- **Stateful multi-turn via `previous_response_id`.** The SDK threads a server-assigned response id through follow-up turns so you don't resend the conversation context.
- **Fine-grained `reasoning.effort` and `text.verbosity`.** The SDK translates SuperAICore's `features.thinking.*` onto the Responses-API shape.
- **`prompt_cache_key` at the wire level.** Same `features.prompt_cache_key.session_id` knob works.

```php
$r = $dispatcher->dispatch([
    'prompt'  => $prompt,
    'backend' => 'superagent',
    'provider_config' => [
        'type'    => 'openai-responses',
        'api_key' => env('OPENAI_API_KEY'),
    ],
    'model' => 'gpt-5',
    'features' => [
        'thinking'         => ['effort' => 'medium'],
        'prompt_cache_key' => ['session_id' => $conversationId],
    ],
]);
```

### Multi-turn without resending context

SDK stores responses server-side by default (`store: true`). For the next turn, pass the previous response id in `extra_body`:

```php
$turn2 = $dispatcher->dispatch([
    'prompt'  => 'And what about X?',
    'backend' => 'superagent',
    'provider_config' => ['type' => 'openai-responses', 'api_key' => env('OPENAI_API_KEY')],
    'extra_body' => [
        'previous_response_id' => $turn1['response_id'] ?? null,  // SDK echoes this on success
    ],
]);
```

For stateless turns (regulatory reasons, secret prompts), set `extra_body.store = false` on the first call.

---

## 5. ChatGPT subscription routing via OAuth

*Since 0.7.0 — builds on SDK 0.9.1's ChatGPT backend detection.*

Paying for ChatGPT Plus / Pro / Business? Your subscription quota is usable for API-like calls via `chatgpt.com/backend-api/codex`. SuperAICore's `openai-responses` type routes there automatically when the provider row stores an `access_token` in `extra_config` rather than an `api_key` on the top-level field.

**Host-app OAuth flow.** You own the OAuth dance — Anthropic's `codex` CLI does it, as does the `codex` OSS client. Once you have a fresh access_token:

```php
use SuperAICore\Models\AiProvider;

$provider = AiProvider::create([
    'scope'        => 'global',
    'backend'      => AiProvider::BACKEND_SUPERAGENT,
    'type'         => AiProvider::TYPE_OPENAI_RESPONSES,
    'name'         => 'ChatGPT Plus (OAuth)',
    'api_key'      => null,   // leave blank
    'extra_config' => [
        'access_token'   => $tokens['access_token'],
        'refresh_token'  => $tokens['refresh_token'],
        'expires_at'     => $tokens['expires_at']->toIso8601String(),
    ],
    'is_active'    => true,
]);
```

The SDK flips `base_url` to `https://chatgpt.com/backend-api/codex` (drops the `/v1/` prefix), sends the access_token as `Authorization: Bearer …`, and your requests bill against the subscription. Rate limits and model availability mirror the ChatGPT plan, not the API tier.

Refresh the token with your own job — the SDK won't refresh an `access_token` on the `openai-responses` provider (that's a host-app concern).

---

## 6. Azure OpenAI auto-detection

*Since 0.7.0.*

Point `base_url` at your Azure deployment and the SDK auto-detects via six URL markers (`openai.azure.`, `cognitiveservices.azure.`, `aoai.azure.`, `azure-api.`, `azurefd.`, `windows.net/openai`). No config flag needed.

```php
AiProvider::create([
    'scope'        => 'global',
    'backend'      => AiProvider::BACKEND_SUPERAGENT,
    'type'         => AiProvider::TYPE_OPENAI_RESPONSES,
    'name'         => 'Azure OpenAI — eastus2',
    'api_key'      => env('AZURE_OPENAI_KEY'),
    'base_url'     => 'https://mycompany.openai.azure.com/openai/deployments/gpt-5',
    'extra_config' => [
        // Optional — override the default api-version when your deployment lags:
        'azure_api_version' => '2024-10-21',
    ],
]);
```

Azure-mode behaviour:

- Requests become `/openai/responses?api-version=2025-04-01-preview` (default, overridable).
- Both `Authorization: Bearer` and `api-key: <key>` headers go out so either Azure auth path works.
- Model id should match the deployment name, not the OpenAI model id — Azure surfaces its own.

---

## 7. LM Studio — local OpenAI-compat server

*Since 0.7.0.*

LM Studio runs a local OpenAI-compat server (typically on `http://localhost:1234/v1`). The `lmstudio` provider type targets it with zero auth ceremony — the SDK synthesises a placeholder `Authorization` header so Guzzle doesn't balk.

```php
AiProvider::create([
    'scope'     => 'global',
    'backend'   => AiProvider::BACKEND_SUPERAGENT,
    'type'      => AiProvider::TYPE_LMSTUDIO,
    'name'      => 'LM Studio — local',
    'base_url'  => 'http://localhost:1234/v1',
    'is_active' => true,
]);
```

Use cases:

- **Fully offline / on-prem** — no cloud keys, no egress.
- **Prompt engineering** — iterate against a local model before burning API spend.
- **CI-gated tests** — spin LM Studio up in a container, point `base_url` at it, run your Dispatcher test suite against real model output.

For LM Studio on a different host on the same LAN, just point `base_url` at its IP. No other changes needed.

---

## 8. Declarative HTTP headers per provider type

*Since 0.7.0.*

Two new `ProviderTypeDescriptor` fields let host apps inject HTTP headers onto every SDK-routed call of a specific provider type:

- `http_headers` — literal headers. For static identification: `X-App: my-host`.
- `env_http_headers` — header name → env var name map. SDK reads the env var at request time; silently drops the header when the var is unset. For project-scoped headers: `OpenAI-Project: <from env>`.

```php
// config/super-ai-core.php
return [
    // …
    'provider_types' => [
        // Project-scoped OpenAI keys get an OpenAI-Project header on every call.
        \SuperAICore\Models\AiProvider::TYPE_OPENAI => [
            'env_http_headers' => ['OpenAI-Project' => 'OPENAI_PROJECT'],
        ],

        // Same for the Responses API type. Also tag every call for LangSmith.
        \SuperAICore\Models\AiProvider::TYPE_OPENAI_RESPONSES => [
            'http_headers'     => ['X-Service' => 'my-host-app'],
            'env_http_headers' => [
                'OpenAI-Project'     => 'OPENAI_PROJECT',
                'LangSmith-Project'  => 'LANGSMITH_PROJECT',
            ],
        ],

        // OpenRouter identification (rate-limit tier uses it).
        'openrouter' => [
            'http_headers' => [
                'HTTP-Referer' => 'https://myapp.example.com',
                'X-Title'      => 'My Host App',
            ],
        ],
    ],
];
```

The headers are applied inside the SDK's `ChatCompletionsProvider` — they ride on every request (chat + responses + model listing + health probe), so your telemetry sees them uniformly.

---

## 9. SDK feature dispatcher — `extra_body`, `features`, `loop_detection`

*Since 0.6.9.*

Three plumbing keys on `Dispatcher::dispatch()` when `backend=superagent`:

### `extra_body` — vendor-specific wire escape hatch

Deep-merged at the top level of every `ChatCompletionsProvider` request body. Use when you need a field the SDK hasn't surfaced yet:

```php
$dispatcher->dispatch([
    ...,
    'backend' => 'superagent',
    'extra_body' => [
        'response_format' => ['type' => 'json_object'],  // OpenAI JSON mode
        'seed'            => 42,                         // deterministic-ish outputs
    ],
]);
```

### `features` — capability-routed SDK features

Routed through SDK's `FeatureDispatcher`. Silent skip on unsupported providers — safe to pass unconditionally.

```php
$dispatcher->dispatch([
    ...,
    'features' => [
        // CoT with graceful fallback on every provider
        'thinking'                  => ['effort' => 'high', 'budget' => 8000],
        // Kimi session prompt cache (silent skip elsewhere)
        'prompt_cache_key'          => ['session_id' => $conversationId],
        // Qwen Anthropic-style cache markers (DashScope native shape only)
        'dashscope_cache_control'   => true,
    ],
]);
```

### `loop_detection` — catch runaway agents

Wraps the streaming handler in `LoopDetectionHarness`. `true` uses SDK defaults; an array overrides thresholds:

```php
$dispatcher->dispatch([
    ...,
    'loop_detection' => [
        'tool_loop_threshold'     => 7,   // 5 same tool+args in a row default
        'stagnation_threshold'    => 10,  // 8 same name default
        'file_read_loop_recent'   => 20,
        'file_read_loop_triggered' => 12,
        'content_loop_window'     => 100,
        'content_loop_repeats'    => 15,
        'thought_loop_repeats'    => 4,
    ],
]);
```

Violations fire as SDK wire events — the AICore envelope stays byte-exact for callers that don't opt in.

---

## 10. Prompt-cache keys (Kimi)

*Since 0.6.9.*

Kimi supports session-level prompt caching via a caller-supplied session id (distinct from Anthropic's per-block markers). A stable session id lets Kimi reuse the prompt-prefix cache across turns of the same conversation, cutting input-token cost dramatically on multi-turn runs.

Two equivalent shapes:

```php
// shorthand (preferred for single-session calls)
$dispatcher->dispatch([
    ...,
    'backend'          => 'superagent',
    'provider_config'  => ['type' => 'openai-compatible', 'api_key' => env('KIMI_API_KEY'),
                           'base_url' => 'https://api.moonshot.ai/v1', 'provider' => 'kimi'],
    'prompt_cache_key' => $sessionId,
]);

// explicit (for symmetry with other `features.*`)
$dispatcher->dispatch([
    ...,
    'features' => [
        'prompt_cache_key' => ['session_id' => $sessionId],
    ],
]);
```

Non-Kimi providers silently skip the feature — safe to pass from a shared dispatcher.

---

## 11. Extending the provider-type registry

*Since 0.6.2.*

Hosts add brand-new provider types via `super-ai-core.provider_types` without forking the package. Example — registering xAI's Grok API:

```php
// config/super-ai-core.php
return [
    // …
    'provider_types' => [
        'xai-api' => [
            'label_key'        => 'integrations.ai_provider_xai',
            'desc_key'         => 'integrations.ai_provider_xai_desc',
            'icon'             => 'bi-x-lg',
            'fields'           => ['api_key'],
            'default_backend'  => \SuperAICore\Models\AiProvider::BACKEND_SUPERAGENT,
            'allowed_backends' => [\SuperAICore\Models\AiProvider::BACKEND_SUPERAGENT],
            'env_key'          => 'XAI_API_KEY',
            'sdk_provider'     => 'xai',                    // (0.7.0+) SDK registry key
            'http_headers'     => ['X-App' => 'my-host'],   // (0.7.0+) optional
            'env_http_headers' => ['X-App-Version' => 'APP_VERSION'],
        ],
    ],
];
```

The registry is addressable at `app(ProviderTypeRegistry::class)`. The `/providers` UI, `ProviderEnvBuilder`, `AiProvider::requiresApiKey()`, and every backend that cares about provider types pick up the new entry automatically.

For the full descriptor shape, see `src/Support/ProviderTypeDescriptor.php`.

---

## 12. Host CLI spawn via `ScriptedSpawnBackend`

*Since 0.7.1.*

Hosts that integrate AICore (SuperTeam, SuperPilot, shopify-autopilot, …) used to carry a `match ($backend) { 'claude' => buildClaudeProcess(…), 'codex' => buildCodexProcess(…), 'gemini' => buildGeminiProcess(…) }` for every task spawn, plus a second identical switch for one-shot chat paths. Every new CLI engine (kiro, copilot, kimi, future) forced a host patch. 0.7.1 introduces `Contracts\ScriptedSpawnBackend` — a sibling contract to `StreamingBackend` that collapses both switches into one polymorphic call. All six CLI backends (`Claude` / `Codex` / `Gemini` / `Copilot` / `Kiro` / `Kimi`) implement it in the same release.

### Before (per-backend match, ~150 lines in host runner)

```php
// Host's pre-0.7.1 task-runner glue
$process = match ($engineKey) {
    'claude'  => $this->buildClaudeProcess($promptFile, $logFile, $projectRoot, $model, $env),
    'codex'   => $this->buildCodexProcess($promptFile, $logFile, $projectRoot, $model, $env),
    'gemini'  => $this->buildGeminiProcess($promptFile, $logFile, $projectRoot, $model, $env),
    'copilot' => $this->buildCopilotProcess($promptFile, $logFile, $projectRoot, $model, $env),
    'kiro'    => $this->buildKiroProcess($promptFile, $logFile, $projectRoot, $model, $env),
    'kimi'    => $this->buildKimiProcess($promptFile, $logFile, $projectRoot, $model, $env),
    default   => throw new \InvalidArgumentException("unknown engine: {$engineKey}"),
};
$process->start();
```

Each `buildXxxProcess()` handles its own argv composition, `--output-format stream-json`-or-similar flags, MCP config injection, env scrub (Claude's 5 `CLAUDE_CODE_*` markers, Codex's `--config` passthrough), capability transforms (Gemini tool-name rewrite), wrapper-script piping, and cwd.

### After (single polymorphic call)

```php
use SuperAICore\Services\BackendRegistry;

$backend = app(BackendRegistry::class)->forEngine($engineKey);  // nullable — null when engine disabled in config
if ($backend === null) {
    throw new \RuntimeException("engine {$engineKey} has no scripted-spawn backend registered");
}

$process = $backend->prepareScriptedProcess([
    'prompt_file'             => $promptFile,
    'log_file'                => $logFile,
    'project_root'            => $projectRoot,
    'model'                   => $model,
    'env'                     => $env,          // host-built — reads IntegrationConfig
    'disable_mcp'             => $disableMcp,   // Claude primarily
    'codex_extra_config_args' => $codexArgs,    // Codex primarily
    'timeout'                 => 7200,
    'idle_timeout'            => 1800,
]);
$process->start();
```

`BackendRegistry::forEngine($engineKey)` iterates the engine's `dispatcher_backends` in order (CLI first by construction, e.g. `claude → ['claude_cli', 'anthropic_api']`) and returns the first one that implements `ScriptedSpawnBackend`. Returns `null` when the engine has no CLI backend registered — either disabled via `AI_CORE_CLAUDE_CLI_ENABLED=false` or a superagent-only engine that doesn't implement scripted spawn.

### One-shot chat sibling — `streamChat()`

Blocking one-shot chat turn (display-ready text via `onChunk`, accumulated response returned at exit). Backend owns argv, prompt-vs-argv passing, output parsing, and ANSI stripping (Kiro / Copilot emit colour codes that would otherwise leak into host UIs):

```php
$response = $backend->streamChat(
    $prompt,
    function (string $chunk) use ($ui) {
        $ui->append($chunk);
    },
    [
        'cwd'           => $projectRoot,
        'model'         => $model,
        'env'           => $env,
        'timeout'       => 0,            // 0 = no hard cap; idle_timeout still applies
        'idle_timeout'  => 300,
        'allowed_tools' => ['Read', 'Bash'],  // Claude only; other CLIs ignore
    ]
);
```

### Wrapper-script helpers for implementers

If you're implementing `ScriptedSpawnBackend` for a new CLI engine, `Backends\Concerns\BuildsScriptedProcess` trait provides the shared plumbing:

- `buildWrappedProcess(…)` — writes a sh or .bat wrapper script that pipes `prompt_file` through stdin, tees stdout+stderr to `log_file`, applies cwd + env, and returns a pre-configured `Symfony\Component\Process\Process`.
- `applyCapabilityTransform()` — rewrites the prompt file in-place via `BackendCapabilities::transformPrompt()` (for backends that need tool-name rewrites or preamble injection).
- `escapeFlags([…])` — wraps `escapeshellarg` across an argv list.

### CLI binary location

`Support\CliBinaryLocator` is registered as a singleton in the service provider. It centralises filesystem probing for CLI binaries across the places macOS / Linux / Windows typically install them:

- `~/.npm-global/bin` (npm global prefix)
- `/opt/homebrew/bin` and `/usr/local/bin` (Homebrew on arm64 / x86_64)
- `~/.nvm/versions/node/<v>/bin` (every installed nvm version)
- Windows `%APPDATA%/npm`

The binary name comes from `EngineCatalog->cliBinary` — no `match` statement. Hosts can inject this locator if they need to reuse the same probes for their own host-owned CLI paths:

```php
$locator = app(\SuperAICore\Support\CliBinaryLocator::class);
$claudePath = $locator->locate('claude');   // absolute path or null
```

### Claude env-scrub list (for hosts still composing their own `claude` processes)

`ClaudeCliBackend::CLAUDE_SESSION_ENV_MARKERS` is exposed as a public constant listing the 5 env markers (`CLAUDECODE`, `CLAUDE_CODE_ENTRYPOINT`, `CLAUDE_CODE_SSE_PORT`, `CLAUDE_CODE_EXECPATH`, `CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS`) that must be scrubbed from the child env to stop Claude's parent-session recursion guard from refusing to start. Hosts that keep their own process composition can read this constant rather than re-deriving the list:

```php
use SuperAICore\Backends\ClaudeCliBackend;

$env = array_diff_key($parentEnv, array_flip(ClaudeCliBackend::CLAUDE_SESSION_ENV_MARKERS));
```

### Why the contract matters long-term

After adopting `ScriptedSpawnBackend`, a new CLI engine lands upstream and shows up in host runners automatically — no host patch, no `match` arm to add. That's the point of the contract: every new engine since 0.7.1 (Moonshot's Kimi, future Alibaba Qwen, future Mistral `le-chat`, …) becomes visible in host code paths the moment SuperAICore registers its backend. See [docs/host-spawn-uplift-roadmap.md](host-spawn-uplift-roadmap.md) for the full context — the 700 lines of per-backend glue it replaces, the phased migration plan, and the pre-soak caveat.

---

## 13. Portable `.mcp.json` writes

*Since 0.8.1.*

Generated `.mcp.json` files have always been moveable *if* you hand-authored them with bare command names (`node`, `uvx`, …) and `${SUPERTEAM_ROOT}/<rel>` placeholder paths. But every UI-driven write path on `McpManager::install*()` resolved binaries through `which()` / `PHP_BINARY` and joined project-relative paths into absolute ones before writing — so the moment a user clicked "Install" or "Install All", the file got re-polluted with `C:\Program Files\nodejs\node.exe`, `/Users/jane/projects/foo/.mcp-servers/bar/dist/index.js`, venv-bin paths, etc. The file then broke the moment it was synced into a teammate's checkout, mounted into a container, or copied to a different `${HOME}`.

0.8.1 adds an opt-in **portable mode** driven by one config knob:

```dotenv
# .env — pick any env var name your MCP runtime exports
AI_CORE_MCP_PORTABLE_ROOT_VAR=SUPERTEAM_ROOT
```

When the knob is set (default `null` = legacy "absolute paths everywhere"), every writer flips two things:

1. **Bare commands** — `which('node')` / `PHP_BINARY` / `which('uvx')` etc. are replaced by `node` / `php` / `uvx`. The CLI engine's PATH at spawn time decides which binary actually runs — no per-machine pinning.
2. **Path placeholders** — every absolute path under `projectRoot()` is rewritten to `${SUPERTEAM_ROOT}/<rel>`. Paths outside the tree (e.g. `/usr/share/...`, `/var/lib/...`) stay absolute. The host's MCP runtime expands the placeholder at spawn time.

### Telling the MCP runtime to expand `${SUPERTEAM_ROOT}`

Most MCP runtimes (Claude Code, Codex, Gemini, …) read project-scope env from `.claude/settings.local.json` or equivalent, then expand it into spawned MCP-server processes. Wire `SUPERTEAM_ROOT` to your project root once:

```jsonc
// .claude/settings.local.json
{
  "env": {
    "SUPERTEAM_ROOT": "${PWD}"
  }
}
```

The actual value depends on how the host runs — for a Laravel app served by `php artisan serve`, `${PWD}` works. For container deployments, set `SUPERTEAM_ROOT=/srv/app` (or whatever your in-container project root is) in the container's env file. For a queue worker that boots from a different cwd, export it from the systemd unit / supervisord config.

### What the writers produce — before and after

```jsonc
// Before — legacy absolute paths
{
  "mcpServers": {
    "ocr": {
      "command": "C:\\Users\\jane\\AppData\\Local\\Programs\\Python\\Python312\\python.exe",
      "args": ["C:\\Users\\jane\\projects\\acme\\.mcp-servers\\ocr\\main.py"]
    },
    "pdf-extract": {
      "command": "/opt/homebrew/Cellar/php/8.3.0/bin/php",
      "args": ["/Users/jane/projects/acme/artisan", "pdf:extract"]
    }
  }
}
```

```jsonc
// After — portable, with AI_CORE_MCP_PORTABLE_ROOT_VAR=SUPERTEAM_ROOT
{
  "mcpServers": {
    "ocr": {
      "command": "python",
      "args": ["${SUPERTEAM_ROOT}/.mcp-servers/ocr/main.py"]
    },
    "pdf-extract": {
      "command": "php",
      "args": ["${SUPERTEAM_ROOT}/artisan", "pdf:extract"]
    }
  }
}
```

### Egress rule — placeholders materialise into per-machine targets

Project-scope `.mcp.json` keeps placeholders for portability. But three categories of target *can't* be portable:

- **User-scope backend configs** — `~/.codex/config.toml` (TOML, no `${VAR}` expansion at all), `~/.gemini/settings.json`, `~/.claude/settings.json`, `~/.copilot/...`, `~/.kiro/...`, `~/.kimi/...` are inherently per-machine.
- **Codex `exec -c` runtime flags** — passed to `codex exec` on the command line; not env-expanded.
- **Helpers that synthesise MCP entries on top of `.mcp.json`** at sync time — `superfeedMcpConfig`, `codexOcrMcpConfig`, `codexPdfExtractMcpConfig` produce specs consumed by `syncAllBackends()` and `codexMcpConfigArgs()`.

For these, `codexMcpServers()` runs every spec through `materializeServerSpec()` immediately before returning. The materialiser:

1. Replaces `${SUPERTEAM_ROOT}` with the env var's runtime value (`getenv('SUPERTEAM_ROOT')`).
2. Falls back to `projectRoot()` when the var isn't exported in the current process — common for queue workers that don't inherit the web request env.
3. No-ops when portability is disabled (the spec is already absolute).

Net effect: one project-scope `.mcp.json` ships with bare commands + placeholders; every backend writer that consumes it gets bare commands + real absolute paths exactly when it needs them.

### Programmatic helpers

If your host has its own MCP-spec writer that needs the same treatment, the five helpers on `McpManager` are public:

```php
use SuperAICore\Services\McpManager;

$mcp = app(McpManager::class);

// Forward direction — used by writers
$cmd  = $mcp->portableCommand('uvx', $resolvedUvxPath);   // 'uvx' or absolute
$path = $mcp->portablePath('/Users/jane/proj/.mcp/foo');  // '${SUPERTEAM_ROOT}/.mcp/foo'

// Inverse — used at egress to non-portable targets
$abs  = $mcp->materializePortablePath('${SUPERTEAM_ROOT}/foo'); // '/srv/app/foo'
$spec = $mcp->materializeServerSpec([                            // walks command + args + env
    'command' => 'python',
    'args'    => ['${SUPERTEAM_ROOT}/script.py'],
    'env'     => ['DATA_DIR' => '${SUPERTEAM_ROOT}/data'],
]);

// Knob accessor — null if portability disabled
$varName = $mcp->portableRootVar(); // 'SUPERTEAM_ROOT' or null
```

### Gotchas

- **`uv run` substitution for pyproject Python servers.** Portability + `pyproject.toml` + `entrypoint_script` triggers a routing change — instead of pinning `command` to `<projectRoot>/.venv/bin/<script>` (a per-machine path), the writer emits `command: "uv"`, `args: ["run", "<script>"]`. `uv` resolves the venv at spawn time. If your host doesn't have `uv` on PATH at MCP spawn time, leave portability off for that server (or install `uv`).
- **`PHP_BINARY` registry entries.** The `pdf-extract` registry entry keeps `'command' => PHP_BINARY` directly (so the registry shape stays the same). `installArtisan` normalises it to `'php'` at write time when portability is on. Custom registry entries that hardcode an absolute binary in `command` need the same treatment — pass it through `portableCommand($bare, $resolved)` from your writer.
- **Out-of-tree paths stay absolute.** `portablePath('/usr/share/foo')` returns `/usr/share/foo` unchanged because `/usr/share` isn't under `projectRoot()`. This is intentional — the placeholder only makes sense for tree-relative paths.
- **Codex helpers (`codexOcrMcpConfig` / `codexPdfExtractMcpConfig` / `superfeedMcpConfig`) write to project-scope `.mcp.json`.** They emit placeholders when portability is on. The egress materialiser then re-absolutises before they hit `~/.codex/config.toml`. The pre-0.8.1 behaviour (always-absolute) is preserved verbatim when portability is off.

---

## 14. SuperAgent host-config adapter — `createForHost`

*Since 0.8.5.*

`SuperAgentBackend::buildAgent()` no longer hand-rolls SDK provider construction. The dual region / no-region branch + manual HTTP-header injection collapses to one call:

```php
// src/Backends/SuperAgentBackend.php (excerpt — what the backend does internally)
$hostConfig = [
    'api_key'  => $providerConfig['api_key']  ?? null,
    'base_url' => $providerConfig['base_url'] ?? null,
    'model'    => $options['model']           ?? $providerConfig['model']    ?? null,
    'region'   => $providerConfig['region']   ?? null,
    'extra'    => [
        'http_headers'     => $descriptor->httpHeaders,
        'env_http_headers' => $descriptor->envHttpHeaders,
    ],
];
$agentConfig['provider'] = ProviderRegistry::createForHost($providerName, $hostConfig);
```

The SDK's per-key adapter owns the constructor-shape mapping:

- **Default adapter** — passes `api_key` / `base_url` / `model` / `max_tokens` / `region` straight through, plus deep-merges `extra` after them (so `extra` can't accidentally overwrite a top-level field). Covers Anthropic / OpenAI / OpenAI-Responses / OpenRouter / Ollama / LMStudio / Gemini / Kimi / Qwen / Qwen-native / GLM / MiniMax.
- **`bedrock` adapter** (built into the SDK) — splits `credentials.aws_access_key_id` / `aws_secret_access_key` / `aws_region` into the BedrockProvider constructor's separate `access_key` / `secret_key` / `region` slots. Falls back to `host['api_key']` for `access_key` if the structured credentials block isn't present.
- **Future provider keys** — each ships its own adapter (or rides the default one). New SDK provider types light up here without any backend code change.

### What hosts that bypass `Dispatcher` should do

Most hosts go through `Dispatcher::dispatch(['backend' => 'superagent', …])` and never touch this layer. But hosts that construct an `Agent` directly — typically because they want to drive the SDK's `withSystemPrompt()` / `addTool()` / streaming hooks without the Dispatcher envelope — can use the same shape:

```php
use SuperAgent\Agent;
use SuperAgent\Providers\ProviderRegistry;
use SuperAICore\Services\ProviderTypeRegistry;
use SuperAICore\Models\AiProvider;

$row = AiProvider::find(42);
$descriptor = app(ProviderTypeRegistry::class)->get($row->type);
$sdkKey = $descriptor?->sdkProvider ?: $row->type;

$provider = ProviderRegistry::createForHost($sdkKey, [
    'api_key'  => $row->decrypted_api_key,
    'base_url' => $row->base_url,
    'model'    => $row->extra_config['default_model'] ?? null,
    'region'   => $row->extra_config['region']        ?? null,
    'extra'    => [
        // Descriptor-declared static + env-driven HTTP headers ride
        // through `extra` on the default adapter. Hosts can also
        // pass any provider-specific knob the SDK accepts here:
        // `organization`, `reasoning`, `verbosity`, `store`, `extra_body`,
        // `prompt_cache_key`, `azure_api_version`, etc.
        'http_headers'     => $descriptor?->httpHeaders     ?? [],
        'env_http_headers' => $descriptor?->envHttpHeaders  ?? [],
        'organization'     => $row->extra_config['organization'] ?? null,
    ],
]);

$agent = new Agent([
    'provider'   => $provider,
    'max_turns'  => 5,
    'max_tokens' => 4000,
]);
```

### Why it matters

- **One factory call per provider**, regardless of provider key. Hosts that previously carried a `match ($providerType) { 'bedrock' => new BedrockProvider([...]), 'openai' => new OpenAIProvider([...]), … }` switch — collapse to one line. New SDK provider keys (`openai-responses` and `lmstudio` landed in 0.7.0; future ones land transparently) work without a `match` arm.
- **Region-aware providers stay region-aware** without callers having to know the region map. Pass `'region' => 'cn'` on Kimi / Qwen / GLM / MiniMax and the SDK's per-provider `regionToBaseUrl()` resolves the right endpoint. `'region' => 'code'` on Kimi / Qwen routes through the OAuth credential store (`KimiCodeCredentials` / `QwenCodeCredentials`) and falls back to `api_key` when no token is cached.
- **Custom adapters are an extension point.** Hosts that maintain their own provider class (e.g. an internal proxy with non-standard auth) register a custom adapter once and the rest of the host treats it like any other key:

  ```php
  use SuperAgent\Providers\ProviderRegistry;

  ProviderRegistry::registerHostConfigAdapter('my-internal-proxy', static function (array $host): array {
      return [
          'api_key'  => $host['credentials']['internal_token'] ?? null,
          'base_url' => 'https://llm-proxy.internal/v1',
          'model'    => $host['model'] ?? 'gpt-4o',
          // … whatever the concrete provider class needs
      ];
  });

  // Then everywhere else in the host:
  $provider = ProviderRegistry::createForHost('my-internal-proxy', $hostConfig);
  ```

### Test substitution — `makeProvider()` seam

Backend subclasses that need to inject a fake `LLMProvider` without touching the global `ProviderRegistry` can override `makeProvider()` directly:

```php
class FakeSuperAgentBackend extends \SuperAICore\Backends\SuperAgentBackend
{
    protected function makeProvider(string $providerName, array $hostConfig): \SuperAgent\Contracts\LLMProvider
    {
        return new MyFakeProvider($hostConfig);
    }
}
```

`SuperAgentBackend::makeAgent()` always receives a constructed `LLMProvider` (never a string + spread llmConfig keys) post-0.8.5, so test assertions should check `$agentConfig['provider'] instanceof \SuperAgent\Contracts\LLMProvider` rather than comparing to a provider-name string.

---

## 15. Mid-conversation provider handoff (`Agent::switchProvider`)

*Since 0.8.5 (via SDK 0.9.5).*

This one is **not used by SuperAICore itself** — `FallbackChain` walks across CLI subprocess backends, not in-process SDK providers, and `Dispatcher` doesn't carry conversation state across calls. But hosts that wrap `SuperAgentBackend` and drive the `Agent` directly can hand a live conversation off to a different provider mid-flight without losing the message history. Useful for "start cheap, escalate on context-window pressure" or "rebuild this on a different model when the first one goes off the rails" patterns.

```php
use SuperAgent\Agent;
use SuperAgent\Conversation\HandoffPolicy;

$agent = new Agent(['provider' => 'anthropic', 'api_key' => $key, 'model' => 'claude-opus-4-7']);
$agent->run('analyse this codebase');

// Hand off to a cheaper / faster model for the next phase:
$agent->switchProvider('kimi', ['api_key' => $kimiKey, 'model' => 'kimi-k2-6'])
      ->run('write the unit tests');

// Check whether the history fits under the new model's context window —
// different tokenizers count the same history 20–30% apart:
$status = $agent->lastHandoffTokenStatus();
if ($status !== null && ! $status['fits']) {
    // Trigger your existing IncrementalContext compression before the next call
}

// Want to keep Anthropic signed-thinking blocks around in case you swap back?
$agent->switchProvider('kimi', [...], HandoffPolicy::preserveAll());

// Conversation went off the rails — try again with a different model on a clean slate:
$agent->switchProvider('openai', [...], HandoffPolicy::freshStart());
```

The three policy presets:

- **`HandoffPolicy::default()`** — keep tool history, drop signed thinking, append a handoff system marker, reset continuation ids. Sensible default for "switch to a different model and keep going".
- **`HandoffPolicy::preserveAll()`** — keep everything in the internal representation. The encoder still drops what its target wire shape can't carry (Anthropic signed thinking, Kimi `prompt_cache_key`, Responses-API encrypted reasoning items, Gemini `cachedContent` refs), but those get parked under `AssistantMessage::$metadata['provider_artifacts'][$providerKey]` so a later swap back to the originating family can re-stitch them.
- **`HandoffPolicy::freshStart()`** — collapse history to the latest user turn so a different model can take a clean shot.

### What's lossy

Cross-family encoding always strips artifacts the target wire shape can't carry. The handoff is atomic — a failed provider construction (missing `api_key`, unknown region, network probe rejection) leaves the agent on the old provider with its message list untouched. Gemini is the only family that doesn't expose tool-call ids on the wire; the SDK's encoder rebuilds the `toolUseId → toolName` index from the assistant history each call, so Gemini-originated conversations round-trip through other providers and back without an external mapping table. Full encoding rules live in the SDK's CHANGELOG `[0.9.5]` entry — search for "Notes" inside that section.

### When to reach for this from a SuperAICore host

Almost never via the package directly. The Dispatcher is one-shot per call. But host-side runners that build their own `Agent` (e.g. SuperTeam's PPT pipeline that wants to plan with Claude + execute with Kimi without paying for two separate context replays) can use it. If you find yourself wanting it from within SuperAICore's `SuperAgentBackend`, file an issue first — there's no current product surface for in-process multi-turn handoff and adding one would touch the Dispatcher contract.

---

## 16. Skill engine — telemetry, ranking, FIX-mode evolution

*Since 0.8.6.*

Three orthogonal services that turn the static `.claude/skills/` catalog into a feedback loop:

- **`SkillTelemetry`** — one row per Claude Code Skill tool invocation in `sac_skill_executions`.
- **`SkillRanker`** — pure-PHP BM25 over the registry, boosted by recent success rate.
- **`SkillEvolver`** — FIX-mode patches for failing skills, queued as review-only candidates. **Never auto-applies.**

DERIVED / CAPTURED evolution modes (auto-derive new skills from successful runs, capture user-demonstrated workflows) are intentionally not shipped — humans curate new skills on Day 0. Cloud registry omitted (no cross-project sharing need yet). The whole engine is borrowed in spirit from HKUDS/OpenSpace's `skill_engine`, trimmed to the safe subset for production use.

### Wiring telemetry through Claude Code hooks

The package only ships the artisan endpoints. The hook contract is owned by Claude Code:

```jsonc
// .claude/settings.local.json
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

Both commands read the hook JSON payload from stdin — `session_id`, `transcript_path`, `cwd`, `tool_name`, `tool_input.skill` for `PreToolUse`; `session_id`, `stop_hook_active`, `user_interrupted` for `Stop`. Payload reads use a 1.0s soft deadline + 200KB cap with non-blocking reads so a pathological pipe can't hang the session. Telemetry errors are swallowed silently — the hook never fails. CLI-flag fallbacks (`--skill`, `--session`, `--host-app`, `--status`, `--error`) work for manual invocation outside Claude Code.

`host_app` is auto-detected by walking up to find a sibling `.claude/` directory and using its parent's basename — useful when the same package is mounted in SuperTeam, SuperFeed, etc. and you want metrics partitioned per host.

### Aggregation: `SkillTelemetry::metrics()`

```php
use SuperAICore\Services\SkillTelemetry;
use Carbon\Carbon;

// All-time, all skills
$metrics = SkillTelemetry::metrics();

// Last 7 days
$metrics = SkillTelemetry::metrics(Carbon::now()->subDays(7));

// One skill specifically
$metrics = SkillTelemetry::metrics(null, 'research');

// Returns:
// [
//   'research' => [
//     'applied' => 42, 'completed' => 38, 'failed' => 3,
//     'orphaned' => 1, 'interrupted' => 0, 'in_progress' => 0,
//     'completion_rate' => 0.9048, 'failure_rate' => 0.0714,
//     'last_used_at' => '2026-04-26 14:33:12',
//   ],
//   ...
// ]
```

One query, single GROUP BY round-trip. `recentFailures($skillName, $limit = 5)` powers the FIX-mode prompt builder.

### Ranking: `SkillRanker`

Pure-PHP BM25 (Robertson-Walker `K1=1.5`, `B=0.75`, BM25-Plus IDF). Skill name is repeated in the document bag to upweight intent signal; description plus the first 600 chars of SKILL.md body provide the rest of the lexical surface. CJK-aware tokeniser emits each Han character as its own token (poor-man's CJK tokenizer — Chinese skill descriptions are short, char-grams suffice). Confidence-weighted telemetry boost: `final = bm25 * (1 + 0.4 * (success_rate - 0.5) * applied_signal)`, where `applied_signal = min(1, applied / 10)` saturates near 10 runs.

```php
use SuperAICore\Registry\SkillRegistry;
use SuperAICore\Services\SkillRanker;

$ranker = new SkillRanker(new SkillRegistry(base_path()));

$results = $ranker->rank('estimate effort for an outsource project', limit: 5);
foreach ($results as $r) {
    echo "{$r['skill']->name}  score={$r['score']}  boost={$r['breakdown']['tel_boost']}\n";
    // breakdown also carries: bm25, matched (per-term IDF×TF), metrics (raw telemetry row)
}

// Disable boost for pure-lexical ranking (e.g. when you've just seeded telemetry):
$ranker = new SkillRanker(new SkillRegistry(base_path()), useTelemetry: false);

// Restrict to a subset by name (host-side picker UI):
$results = $ranker->rank($query, limit: 10, skillNames: ['research', 'plan', 'init']);
```

CLI sibling: `php artisan skill:rank "your task" --no-telemetry --format=json --cwd=/abs/path`. The `--cwd` override matters for hosts running from `web/public` whose project root sits a few levels up.

### FIX-mode evolution: `SkillEvolver`

The evolver builds a constrained LLM prompt against the live SKILL.md (truncated to 8K chars) plus the last 5 failures from telemetry, persists a `SkillEvolutionCandidate` in `pending` status, and **never modifies SKILL.md directly**. Humans review the queue via `php artisan skill:candidates`.

```php
use SuperAICore\Services\Dispatcher;
use SuperAICore\Services\SkillEvolver;
use SuperAICore\Registry\SkillRegistry;
use SuperAICore\Models\SkillEvolutionCandidate;

$evolver = new SkillEvolver(
    new SkillRegistry(base_path()),
    app(Dispatcher::class),   // optional — only needed when dispatch=true
);

// Manual trigger — no LLM call, just stages a candidate with the prompt
$candidate = $evolver->proposeFix('research');

// Anchor the candidate to a specific failing run
$candidate = $evolver->proposeFix(
    skillName: 'research',
    triggerType: SkillEvolutionCandidate::TRIGGER_FAILURE,
    executionId: 1234,
    dispatch: false,
);

// Burn tokens — invoke the LLM and store both the full response + extracted diff
$candidate = $evolver->proposeFix('research', dispatch: true);
echo $candidate->proposed_diff;   // null if the LLM said NO_FIX_RECOMMENDED

// Sweep — queue candidates for every skill with failure_rate > threshold
// after at least N runs. De-dups against existing pending rows.
$ids = $evolver->sweepDegraded(failureRateThreshold: 0.30, minApplied: 5);
```

The constraints baked into the prompt:

- "Produce the **smallest possible patch**, not a rewrite."
- "If you cannot identify a concrete root cause from the evidence below, reply with `NO_FIX_RECOMMENDED`."
- "Do not invent failures the evidence does not support."
- "Do not restructure sections, rename the skill, change the frontmatter `name`, or add new tools to `allowed-tools` unless the failure evidence explicitly demands it."
- Output format pinned to two sections: `Diagnosis` (2–4 sentences) + `Patch` (single fenced \`\`\`diff block, OR the literal string `NO_FIX_RECOMMENDED`).

`--dispatch` mode routes the prompt through `Dispatcher::dispatch()` with `capability: 'reasoning'`, `task_type: 'skill_evolution_fix'` — whatever provider answers `reasoning` in your `RoutingRepository` handles it. No new env vars, no new config keys.

Recommended cadence: nightly cron, no LLM dispatch. Reviewers see a queue of telemetry-flagged skills with the prompts pre-built; they decide which ones are worth burning tokens on by re-running with `--dispatch` from `php artisan skill:evolve --skill=<name> --dispatch`.

```php
// app/Console/Kernel.php
$schedule->command('skill:evolve --sweep --threshold=0.30 --min-applied=5')
         ->daily()
         ->withoutOverlapping();
```

### Reviewing candidates

```bash
# List pending
php artisan skill:candidates

# Filter
php artisan skill:candidates --skill=research --status=pending

# Inspect one
php artisan skill:candidates --id=42 --show-prompt --show-diff

# JSON for tooling
php artisan skill:candidates --id=42 --format=json
```

Statuses: `pending` (just queued) → `reviewing` → `applied | rejected | superseded`. Human-side workflow piped straight into `git apply`:

```bash
php artisan skill:candidates --id=42 --show-diff --format=text \
  | sed -n '/^=== Proposed Diff ===$/,$p' \
  | tail -n +2 \
  | git apply --check                  # dry-run validation
```

After applying, mark the candidate done:

```php
SkillEvolutionCandidate::find(42)->update([
    'status' => SkillEvolutionCandidate::STATUS_APPLIED,
    'reviewed_at' => now(),
    'reviewed_by' => auth()->user()->email,
]);
```

### What's intentionally not shipped

- **DERIVED mode** (auto-derive new skills from successful runs) — would need an LLM judge to decide whether a multi-turn run is worth promoting to a skill, plus a curation queue. Out of scope for 0.8.6.
- **CAPTURED mode** (capture user-demonstrated workflows as new skills) — same blocker plus a UX surface to label the demonstration. Out of scope for 0.8.6.
- **Cloud registry / cross-project skill sharing** — no current need; would require a registry service and skill-signing.
- **Auto-apply** — the evolver always stages, never applies. By design — a wrong patch in SKILL.md poisons every future run of that skill.
- **Mounting on `bin/superaicore`** — the six artisan commands are registered through `SuperAICoreServiceProvider::boot()` only. The standalone console doesn't auto-mount them because skill telemetry is a host-app concern, not a Composer-CLI one. If you need them outside Laravel, register them on your own Symfony Console manually.

---

## See also

- [docs/idempotency.md](idempotency.md) — 60s dedup window, repository-level contract
- [docs/streaming-backends.md](streaming-backends.md) — per-CLI stream formats
- [docs/task-runner-quickstart.md](task-runner-quickstart.md) — `TaskRunner` option reference
- [docs/spawn-plan-protocol.md](spawn-plan-protocol.md) — codex/gemini agent emulation
- [docs/mcp-sync.md](mcp-sync.md) — catalog-driven MCP sync
- [docs/api-stability.md](api-stability.md) — SemVer contract
