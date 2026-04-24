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

## See also

- [docs/idempotency.md](idempotency.md) — 60s dedup window, repository-level contract
- [docs/streaming-backends.md](streaming-backends.md) — per-CLI stream formats
- [docs/task-runner-quickstart.md](task-runner-quickstart.md) — `TaskRunner` option reference
- [docs/spawn-plan-protocol.md](spawn-plan-protocol.md) — codex/gemini agent emulation
- [docs/mcp-sync.md](mcp-sync.md) — catalog-driven MCP sync
- [docs/api-stability.md](api-stability.md) — SemVer contract
