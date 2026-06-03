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
17. [Semantic skill reranker via `EmbeddingProvider` SPI (0.9.0)](#17-semantic-skill-reranker-via-embeddingprovider-spi-090)
18. [agent_grep + browser tool flags (0.9.0)](#18-agent_grep--browser-tool-flags-090)
19. [Browser screenshot round-trip (0.9.0)](#19-browser-screenshot-round-trip-090)
20. [Usage source split — `user` vs `ambient` (0.9.0)](#20-usage-source-split--user-vs-ambient-090)
21. [Cross-harness session resume (0.9.0)](#21-cross-harness-session-resume-090)
22. [Durable goal store (0.9.1)](#22-durable-goal-store-091)
23. [Three-tier approval gate (0.9.1)](#23-three-tier-approval-gate-091)
24. [Workspace plugin manifest (0.9.1)](#24-workspace-plugin-manifest-091)
25. [Headless `/v1/usage` JSON endpoint (0.9.1)](#25-headless-v1usage-json-endpoint-091)
26. [`cache_hit_rate` aggregation (0.9.1)](#26-cache_hit_rate-aggregation-091)
27. [TaskRunner reliability wave (0.9.2)](#27-taskrunner-reliability-wave-092)
28. [Squad multi-agent + SDK 1.0.0 companion bindings (0.9.6)](#28-squad-multi-agent--sdk-100-companion-bindings-096)
29. [SDK 1.0.5 bump + opencode-borrowed feature wave (0.9.7)](#29-sdk-105-bump--opencode-borrowed-feature-wave-097)
30. [Opus 4.8 + Grok + Cursor (1.0.0 / SDK 1.0.9)](#30-opus-48--grok--cursor-100--sdk-109)
31. [kimi-cli + kimi-code dual-CLI support (1.0.2 / SDK 1.0.10)](#31-kimi-cli--kimi-code-dual-cli-support-102--sdk-1010)
32. [SmartFlow — cross-CLI dynamic workflows + superagent federation (1.0.5 / SDK 1.1.0)](#32-smartflow--cross-cli-dynamic-workflows--superagent-federation-105--sdk-110)
33. [CLI skill bridge — `superaicore:sync-cli` + the `SkillLibrary` contract (1.0.6)](#33-cli-skill-bridge--superaicoresync-cli--the-skilllibrary-contract-106)

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

## 17. Semantic skill reranker via `EmbeddingProvider` SPI (0.9.0)

*Since 0.9.0 — SuperAgent SDK 0.9.7 `Memory\Embeddings\EmbeddingProvider`.*

The optional second pass over `SkillRanker`'s BM25 top-N (introduced in 0.9.0) used to ship a hand-rolled Ollama HTTP client + a callable adapter. 0.9.0 replaces both with the SDK's `EmbeddingProvider` SPI so the reranker, the SDK's own `SemanticSkillRouter`, and any host-supplied `OnnxEmbeddingProvider` share one container singleton + one cache.

### Path of least resistance — Ollama

```dotenv
AI_CORE_EMBEDDINGS_OLLAMA_URL=http://127.0.0.1:11434
AI_CORE_EMBEDDINGS_OLLAMA_MODEL=nomic-embed-text
```

```bash
ollama pull nomic-embed-text   # 768-dim, ~270MB
```

That's it. `EmbeddingProviderFactory` lazy-builds a `SuperAgent\Memory\Embeddings\OllamaEmbeddingProvider` on first use, and `SemanticSkillReranker` consumes it transparently. Skill ranking starts boosting by intent semantics on top of the BM25 lexical match — `php artisan skill:rank "重构认证模块"` now also prefers a skill whose description says "auth refactor" even when none of the literal Chinese tokens appear.

### Bring your own — `EmbeddingProvider` instance

For ONNX, OpenAI, Cohere, prebuilt-cache, or any non-Ollama path, register the typed provider directly:

```php
// app/Providers/AppServiceProvider.php
use SuperAgent\Memory\Embeddings\OnnxEmbeddingProvider;

$this->app->bind(
    \SuperAgent\Memory\Embeddings\EmbeddingProvider::class,
    fn () => new OnnxEmbeddingProvider('/abs/path/to/all-MiniLM-L6-v2.onnx', dimensions: 384)
);
```

Then point the factory at the binding (or set `super-ai-core.embeddings.provider` in published config to an instance). The SDK's `OnnxEmbeddingProvider` requires either `ext-onnxruntime` or the `ankane/onnxruntime` userland binding plus a model file — see its constructor docblock for the install error path.

### Bring your own — closure (legacy shape)

If you already have an embedder closure from pre-0.9.0 SuperAICore, pass it as `super-ai-core.embeddings.callback`. The SDK's `CallableEmbeddingProvider` auto-detects whether the closure takes `array $texts` (preferred batch shape) or `string $text` (legacy single-shot), so existing host code keeps working:

```php
// config/super-ai-core.php — both shapes work
return [
    'embeddings' => [
        // Batch shape — preferred
        'callback'    => fn (array $texts) => $myBatchEmbedder->embedAll($texts),
        // OR single-text shape (legacy VectorMemoryProvider form)
        // 'callback' => fn (string $text) => $myEmbedder->embed($text),
        'fingerprint' => 'my-bge-large-v1.5',  // bumps to invalidate cache when model changes
    ],
];
```

The `fingerprint` is the cache invalidation key — change it when you swap the underlying model so cached vectors flush cleanly without polluting unrelated entries.

### Per-row failure stays graceful

When the embedder returns `[]` for a specific text (Ollama daemon flake, ONNX OOM on one input), the reranker keeps the BM25 score for that hit instead of bailing the whole call. Other rows still get the cosine boost. The query vector is cached per `fingerprint() . sha1(query)` so repeated calls with the same query (typical in batch ranking / test harnesses) don't re-embed.

### Sharing with the SDK's `SemanticSkillRouter`

Hosts that drive the SDK directly (not via SuperAICore's Dispatcher) can pull the same instance from the container so the reranker and the SDK router share one cache:

```php
use SuperAICore\Services\EmbeddingProviderFactory;
use SuperAgent\Skills\SemanticSkillRouter;

$embedder = app(EmbeddingProviderFactory::class)->make();
if ($embedder !== null) {
    $router = new SemanticSkillRouter(
        skillManager: $myManager,
        embedder: $embedder,           // same instance the reranker uses
        threshold: 0.55,
        topK: 3,
    );
}
```

`SuperAgentBackend` also forwards the resolved `EmbeddingProvider` into `Agent`'s forwarded options bag (under `embedding_provider`) so future SDK consumers pick it up via `Agent::getOptions()` without per-call wiring.

---

## 18. agent_grep + browser tool flags (0.9.0)

*Since 0.9.0 — SuperAgent SDK 0.9.7 `AgentGrepTool` + `FirefoxBridgeTool`.*

Two tool-injection knobs on `super-ai-core.tools`. Both fire **only** when the caller didn't supply an explicit `load_tools` array (caller sovereignty wins). And both fire **only** for SuperAgent-backend dispatches that actually drive an agentic loop with tools — one-shot calls (`max_turns=1`, no `load_tools`) and CLI-backed dispatches (`claude_cli`, `codex_cli`, etc.) are completely unaffected.

### `agent_grep` — default ON

```dotenv
AI_CORE_TOOLS_AGENT_GREP=true   # default — set false to opt out
```

When on, `SuperAgentBackend` prepends `'agent_grep'` to the implicit `load_tools` list. The tool sits in the SDK's `BuiltinToolRegistry::classMap`, so `ToolLoader` lazy-resolves it when the agent dispatches its first tool call.

What you get over the plain `grep` tool:

1. **Enclosing-symbol injection** — every match line is annotated with the `class::function` (or top-level `function`) it sits inside, for PHP / JS / TS / Python / Go files. Default extractor is pure-PHP regex — `~95%` accurate on typical code, no external dependency.
2. **Per-session seen-chunk truncation** — repeat queries to the same `(file, line range, sha)` tuple within one session get truncated to a `... (lines N–M previously shown to you in this session)` marker. State lives in `ToolStateManager` keyed by `(file, lineRange, sha)` so swarm isolation works without leaking one agent's seen-chunk ledger into another.

For tree-sitter precision (worth it on Rust / Ruby / Java / C++ codebases the regex extractor doesn't cover well), subclass `AgentGrepTool` and pass a `CompositeSymbolExtractor([new TreeSitterSymbolExtractor(), new RegexSymbolExtractor()])` — see the SDK class docblock at `vendor/forgeomni/superagent/src/Tools/Builtin/AgentGrepTool.php` for the install path. Requires `tree-sitter` CLI binary on `$PATH` and the corresponding grammars; SuperAICore doesn't auto-vendor them.

Want pure-`grep` parity (e.g. for a script that munges raw ripgrep output)? Just pass an explicit `load_tools` that excludes `agent_grep` — caller-supplied lists win:

```php
$dispatcher->dispatch([
    'backend'    => 'superagent',
    'load_tools' => ['grep', 'read_file', 'web_fetch'],   // explicit — no agent_grep
    'max_turns'  => 5,
    // …
]);
```

### `browser` — manual install required

```dotenv
AI_CORE_TOOLS_BROWSER=true
SUPERAGENT_BROWSER_BRIDGE_PATH=/abs/path/to/forgeomni-bridge-launcher
```

The `browser` tool isn't in `BuiltinToolRegistry::classMap`, so `load_tools` can't reach it. `SuperAgentBackend::attachBrowserTool()` instantiates `FirefoxBridgeTool` and `Agent::addTool()`'s it directly when both the flag is on and the SDK class is available.

The tool drives a real Firefox or Chromium tab via Native Messaging — actions: `navigate`, `screenshot`, `click`, `type`, `eval`, `wait`, `close`. The PHP side (`FirefoxBridgeTool` + `NativeMessagingTransport` + `FirefoxBridge`) is fully self-contained in the SDK; the host installs three things:

1. **Firefox** (or any Chromium-based browser with WebExtension support).
2. **Forgeomni Bridge WebExtension** — minimal `manifest.json` + `~150 LoC` background script that opens `runtime.connectNative('forgeomni_bridge')` and dispatches incoming messages to `tabs.*` / `webNavigation.*` APIs. Walkthrough in `vendor/forgeomni/superagent/src/Tools/Browser/FirefoxBridge.php` class docblock.
3. **Native Messaging launcher binary** — any executable that pipes length-prefixed JSON between Firefox and the PHP process. jcode's Rust binary works as-is, or write a 50-line Node / Go shim.

Until the launcher is installed and `SUPERAGENT_BROWSER_BRIDGE_PATH` points at it, every action returns an explanatory error so the agent learns to ask for setup help instead of looping. Safe to enable the flag ahead of installing the launcher.

Pass an explicit `launcherArgv` per dispatch if you want to override the env-var lookup (rare):

```php
$dispatcher->dispatch([
    'backend'              => 'superagent',
    'browser_launcher_argv' => ['/opt/bridge/launcher', '--profile=staging'],
    // …
]);
```

### Tight capability surface

`FirefoxBridgeTool` deliberately exposes only the seven actions above. No tab management, cookies, history, downloads, or extension APIs — those expand the abuse blast radius meaningfully and aren't needed for the typical "use the page like a human would" workload. Hosts that need more wire it directly via `FirefoxBridge::evalJs()` from a custom tool.

---

## 19. Browser screenshot round-trip (0.9.0)

*Since 0.9.0.*

When the `browser` tool runs `action: 'screenshot'`, `FirefoxBridgeTool::execute()` returns a `ToolResult::success(['format' => 'png', 'base64' => $data, 'bytes' => N])`. The result content gets JSON-encoded and stored on the `ToolResultMessage` content block in the `AgentResult` message trail.

`SuperAgentBackend::persistLatestScreenshot()` walks that trail post-dispatch:

1. Index every `tool_use` block whose `toolName === 'browser'` by `toolUseId`.
2. For each later `tool_result` block whose `toolUseId` matches and `isError !== true`, decode the JSON content and read `base64`.
3. Keep the LAST successful one — a long agent run might take many screenshots and only the most recent is operationally interesting.
4. Write it to `BrowserScreenshotStore` keyed by the dispatch's process_id (precedence: `options['process_id']` → `external_label` → `metadata.session_id` → `session_id` → random hex).
5. Surface the resulting URL on the dispatch envelope as `latest_screenshot_url`.

### Round-trip with `AiProcessSource`

`AiProcessSource::list()` reads `BrowserScreenshotStore::latest()` against the `ai_processes` row's `external_label` (then composite `aiprocess.<id>` key) when constructing each `ProcessEntry`. The `/processes` view renders a yellow `📷 screenshot` badge on rows that have a frame; clicking opens the inline image in the side panel (the B1 offcanvas drawer).

On reap (PID dies, status flips to FINISHED), `AiProcessSource` calls `BrowserScreenshotStore::purgeFor()` against the same keys so screenshots don't accumulate past the run's useful lifetime.

### Configuring the storage backend

```dotenv
AI_CORE_BROWSER_SHOTS_DISK=local                                # any Laravel filesystem disk
AI_CORE_BROWSER_SHOTS_DIR=super-ai-core/browser-screenshots     # relative to disk root
```

Production tip: use a per-pod tmpfs disk (mount `tmpfs` at `/var/cache/super-ai-core/screenshots`, configure a `local` disk pointing there) or an S3 disk with a short lifecycle rule. The `local` default works on a single-machine Laravel install and during development.

### Custom UI

Hosts that want their own screenshot rendering (carousel, history, OCR pipeline) read directly from the store:

```php
use SuperAICore\Services\BrowserScreenshotStore;

$store = app(BrowserScreenshotStore::class);
$url = $store->latest($externalLabel);   // null when no frame on disk
```

For multi-frame archives (rather than just the latest), wrap the call site to write your own keyed slot via `store($key, $base64Png)` with a per-frame key suffix (e.g. `"task:42:frame:7"`).

---

## 20. Usage source split — `user` vs `ambient` (0.9.0)

*Since 0.9.0.*

SuperAgent SDK 0.9.7's `Swarm\AmbientWorker` runs background memory-dedup and staleness scans on a tick. Its `tagUsage` callback fires per completed pass with `usage_source: 'ambient'`, but until 0.9.0 SuperAICore had no way to bucket those rows separately on `/usage` — they mixed into user-facing spend.

`Dispatcher::resolveUsageSource()` now extracts the source from `options['usage_source']` or `options['metadata']['usage_source']` and writes it as a top-level `metadata.usage_source` key (default `'user'`). Constrained to `[a-z0-9_-]{1,32}` against typo-as-phantom-bucket leaks.

### Wiring the AmbientWorker

The worker itself lives in the SDK; SuperAICore wires a `tagUsage` callback that dispatches a no-op accounting call to record the spend:

```php
// app/Console/Commands/AmbientTickCommand.php
use SuperAgent\Memory\Palace\PalaceStorage;
use SuperAgent\Memory\Palace\MemoryDeduplicator;
use SuperAgent\Swarm\AmbientWorker;
use SuperAICore\Services\Dispatcher;

class AmbientTickCommand extends Command
{
    protected $signature = 'super-ai-core:ambient-tick';

    public function handle(PalaceStorage $palace, MemoryDeduplicator $dedup, Dispatcher $dispatcher): int
    {
        $worker = new AmbientWorker(
            storage: $palace,
            deduplicator: $dedup,
            config: [
                'dedup_interval_seconds'       => 600,   // 10m
                'stale_check_interval_seconds' => 3600,  // 1h
                'pass_budget_seconds'          => 5,
            ],
            tagUsage: function (string $passName, array $stats) use ($dispatcher) {
                // Record a synthetic row tagged 'ambient' so /usage groups it.
                // No prompt, no model call — we just want the metadata row.
                // Most hosts already write their own ambient rows directly via
                // UsageRecorder; the dispatch path here is illustrative.
            },
        );

        $report = $worker->tick();
        $this->table(['pass', 'ran', 'stats'], collect($report)->map(fn ($r, $p) => [
            $p,
            $r['ran'] ? 'yes' : '—',
            json_encode($r['stats'] ?? null),
        ])->all());
        return self::SUCCESS;
    }
}
```

```php
// app/Console/Kernel.php
$schedule->command('super-ai-core:ambient-tick')
         ->everyFiveMinutes()
         ->withoutOverlapping();
```

Hosts that drive ambient-mode dispatches through `Dispatcher` (e.g. background re-summarisation of long memory drawers) just pass the source on each call:

```php
$dispatcher->dispatch([
    'prompt'   => 'Summarise drawers above 20K tokens.',
    'backend'  => 'superagent',
    'metadata' => ['usage_source' => 'ambient'],
    // …
]);
```

### Reading the split on `/usage`

The dashboard's "By Source" card sits alongside By Task Type / By Model / By Backend. Header shows an "N ambient · $X" badge when ambient activity occurred so operators see at a glance how much background spend the current window carries. Layout reflows to `col-lg-3` on wide viewports so the existing cards stay legible.

The `whereJsonContains` / JSON path lookup isn't needed — Dispatcher's writer pulls `usage_source` to the top-level metadata key on every row, so the controller groups in PHP via Eloquent collection methods. Works on MySQL 5.7, PostgreSQL 9, and SQLite without driver-specific JSON ops.

### Custom source buckets

The allowlist accepts any `[a-z0-9_-]{1,32}` string. Host-defined sources (`'eval'`, `'audit'`, `'replay'`) just work:

```php
$dispatcher->dispatch([
    'metadata' => ['usage_source' => 'eval'],   // appears as its own bucket on /usage
    // …
]);
```

Anything outside the allowlist (uppercase, special chars, > 32 chars) gets normalised — the writer applies `mb_strtolower(preg_replace('/[^a-z0-9_-]+/i', '', $c))` then truncates to 32 chars. Rows with unparseable values fall back to `'user'`.

---

## 21. Cross-harness session resume (0.9.0)

*Since 0.9.0 — SuperAgent SDK 0.9.7 `Conversation\HarnessImporter` family.*

The /processes page gains a "Resume from…" dropdown when `super-ai-core.resume.enabled` is on. Operators pick a Claude Code (`~/.claude/projects/<hash>/<uuid>.jsonl`) or Codex (`~/.codex/sessions/**/*.jsonl`) session from the picker and either inspect the transcript inline or have the host re-dispatch it into a backend.

### Enabling the feature

Off by default — the importers can see every operator's session history on shared machines:

```dotenv
AI_CORE_RESUME_ENABLED=true
```

This unmasks the dropdown on `/processes` and opens three endpoints under `/super-ai-core/resume`:

- `GET /resume` — list available harnesses on this machine
- `GET /resume/{harness}` — list sessions newest-first (`limit` query param, default 30, max 200)
- `POST /resume/{harness}/load` — load one session, returns transcript + optional host payload

### The `on_load` callback — host re-dispatch hook

Without a callback, the `/load` endpoint just returns the parsed transcript JSON. Hosts that want a one-click "resume into chat with provider X" wire a callable returning `{redirect: '<url>'}`:

```php
// config/super-ai-core.php
use SuperAgent\Messages\Message;

return [
    'resume' => [
        'enabled' => env('AI_CORE_RESUME_ENABLED', false),
        'on_load' => function (string $harness, string $sessionId, array $messages): array {
            // $messages is list<Message> — feed straight into Agent::loadMessages($messages)
            // or run through Conversation\Transcoder::encode() for a different wire family.
            $session = ChatSession::createFromHarnessImport($harness, $sessionId, $messages);
            return [
                'redirect' => route('chat.show', $session),
                'session_id' => $session->id,
            ];
        },
    ],
];
```

The frontend modal checks for `host_payload.redirect` — when present, it navigates there instead of rendering the transcript inline.

### Programmatic access from a controller

Hosts that want their own "Resume" UI can resolve the resolver and build whatever flow they want:

```php
use SuperAICore\Services\HarnessSessionResolver;

class MyResumeController extends Controller
{
    public function __construct(protected HarnessSessionResolver $resolver) {}

    public function pickAndResume(Request $request)
    {
        $harness = $request->input('harness', 'claude');
        if (!in_array($harness, $this->resolver->availableHarnesses(), true)) {
            return back()->withErrors(['harness' => 'Unsupported harness']);
        }

        $sessions = $this->resolver->listSessions($harness, limit: 50);
        // → [['id' => '8e2c-…', 'project' => 'shopify-autopilot',
        //     'started_at' => '2026-04-30T…', 'message_count' => 47,
        //     'first_user_message' => 'Refactor the checkout flow…'], …]

        // Once user picks one:
        $payload = $this->resolver->loadTranscript($harness, $sessionId);
        // → ['harness' => 'claude', 'session' => '8e2c-…',
        //    'transcript' => [['role' => 'user', 'content' => '…'], …],
        //    'host_payload' => /* whatever your on_load returned */]

        return view('my.resume.review', compact('payload'));
    }
}
```

### Cross-wire continuation via the SDK Transcoder

The importers return SuperAgent `Message[]` in the SDK's internal representation. To resume on a different provider family (start in Claude, continue in Kimi), pass the messages through the SDK's 0.9.5 `Conversation\Transcoder`:

```php
use SuperAgent\Agent;
use SuperAgent\Conversation\Transcoder;

$messages = $this->resolver->loadTranscript('claude', $sessionId)['transcript'];
// Hydrate the transcript array back into Message instances if you serialised it.
// (HarnessImporter::load() returns Message instances directly — use that path
//  when re-dispatching from a host process that hasn't crossed a JSON boundary.)

$agent = new Agent([
    'provider' => /* host-built Kimi LLMProvider */,
    'max_turns' => 10,
]);
$agent->loadMessages($messages);   // Transcoder handles the wire-shape conversion
$agent->run('Continue where the previous session left off — write the unit tests.');
```

### What's lossy across harnesses

Importers are deliberately tolerant — malformed lines / unknown event types are skipped silently rather than rejecting the whole session. Real session logs from real harnesses are dirty (Claude Code 1.x vs 2.x schema drift, Codex CLI rollout format changes). The Transcoder strips artifacts the target wire shape can't carry — Anthropic signed thinking blocks won't survive a hop to OpenAI, and OpenAI Responses encrypted reasoning items don't survive a hop to Anthropic. Tool-call ids round-trip correctly across all families since 0.9.5.

### What's not shipped

- **Auto-discovery of jcode / pi / OpenCode session files** — the SDK's 0.9.7 importer set covers Claude Code and Codex. Hosts that need other harnesses implement `HarnessImporter` directly and drop a service-provider binding to register the implementation.
- **Re-dispatch UI for SuperAICore's own `ai_processes` history** — `/processes` is live-only by contract since 0.6.7 (it shows running PIDs, not finished rows). The Resume dropdown is for cross-harness session pickup, not for replaying prior SuperAICore runs. Hosts that want "rerun this finished task with a different provider" build their own UI on top of the `ai_processes` audit log.

---

## 22. Durable goal store (0.9.1)

*Since 0.9.1 — SuperAgent SDK 0.9.8 `Goals\Contracts\GoalStore` SPI.*

SDK 0.9.8 ships `Goals\GoalManager` — a thread-scoped primitive for
"this conversation is working towards X". The manager needs persistence
to survive process restarts (codex pauses goals when the user runs
`/pause`, and they have to stay paused after the host process recycles).
SuperAICore 0.9.1 wires the default Eloquent backing.

### Default wiring

`SuperAICoreServiceProvider::register()` binds:

```php
$this->app->bind(
    \SuperAgent\Goals\Contracts\GoalStore::class,
    \SuperAICore\Goals\EloquentGoalStore::class,
);
$this->app->singleton(\SuperAgent\Goals\GoalManager::class);
```

So `app(GoalManager::class)` resolves with the durable store
auto-injected. Run `php artisan migrate` to create the `ai_goals` table
(`thread_id`, `description`, `status`, `metadata`, timestamps). The
table is honoured by `super-ai-core.table_prefix` if your host uses one.

```php
use SuperAgent\Goals\GoalManager;

$manager = app(GoalManager::class);
$manager->setActiveGoal($threadId, 'Refactor checkout flow to honour the new tax engine');

// Later — agent reads the goal mid-conversation via the agent_get_goal
// read-only tool, or the host pauses it during a budget overrun:
$manager->pause($threadId);
// …redeploy host process…
$active = $manager->getActiveGoal($threadId);   // still null — paused
$manager->resume($threadId);
```

### Constraint: at most one non-terminal row per thread

`EloquentGoalStore::setActiveGoal()` transitions any pre-existing
`active` / `paused` / `budget_limited` row for the thread to
`superseded` before inserting the new one. Terminal statuses
(`completed`, `cancelled`, `superseded`) accumulate freely as audit
trail.

### Custom store — host already keeps goals in its own table

Hosts that already model goals (e.g. SuperTeam stores `objectives` per
project) substitute their own implementation. The contract is small —
five methods on `GoalStore`:

```php
namespace App\Goals;

use SuperAgent\Goals\Contracts\GoalStore;
use SuperAgent\Goals\Goal;

final class MyGoalStore implements GoalStore
{
    public function setActiveGoal(string $threadId, string $description, array $metadata = []): Goal
    { /* upsert into your `objectives` table, mark prior active row superseded */ }

    public function getActiveGoal(string $threadId): ?Goal
    { /* return Goal::active(...) or null when paused / completed / absent */ }

    public function pause(string $threadId): void           { /* … */ }
    public function resume(string $threadId): void          { /* … */ }
    public function complete(string $threadId, ?string $result = null): void { /* … */ }
}
```

Override the binding in your host service provider's `register()` —
**before** anything resolves `GoalManager`:

```php
$this->app->bind(
    \SuperAgent\Goals\Contracts\GoalStore::class,
    \App\Goals\MyGoalStore::class,
);
```

The `EloquentGoalStore` in this package becomes dead code from your
host's perspective — it's a reference implementation, not a hard
dependency.

---

## 23. Three-tier approval gate (0.9.1)

*Since 0.9.1.*

`Runner\ApprovalGate` mirrors codex's `/permissions` command (renamed
from `/approvals`). Three modes — `Auto`, `Suggest`, `Never` — with a
single-use `/approve` override token for the codex-style "let this one
specific call through" flow.

### Where the modes differ

```
                read-only tools   ordinary mutations    destructive shell
   ──────────────────────────────────────────────────────────────────────
   Auto         allow              allow                 SUGGEST APPROVAL
   Suggest      allow              SUGGEST APPROVAL      SUGGEST APPROVAL
   Never        allow              hard deny             hard deny
```

Read-only allowlist is hard-coded on the enum:

```php
ApprovalMode::readOnlyAllowlist();
// → ['agent_grep', 'agent_glob', 'agent_read', 'agent_ls',
//    'agent_status', 'web_search', 'web_fetch', 'agent_get_goal']
```

Destructive shell detection runs through the existing
`Guidance\Gates\DestructiveCommandScanner` — same regex set the package
has used since pre-0.7. Auto mode uses the scanner as a safety floor
even though it lets ordinary mutations through.

### Wiring inside a host runner

The gate is a pure decision function — host code calls it before
forwarding the tool call to the backend, and renders the suggestion in
its own UI. There's no backend-side enforcement; opting in is one wrap
call:

```php
use SuperAICore\Runner\ApprovalGate;
use SuperAICore\Runner\ApprovalMode;
use SuperAICore\Runner\ApprovalDecision;

$gate    = app(ApprovalGate::class);
$mode    = ApprovalMode::parse($thread->approval_mode ?? 'suggest');
$pending = $thread->pending_approval_tool_use_id;   // host-stored, see below

$decision = $gate->evaluate(
    toolName:           $call->name,
    arguments:          $call->arguments,
    mode:               $mode,
    toolUseId:          $call->id,
    approvedToolUseId:  $pending,
);

if ($decision->allow) {
    $thread->forget('pending_approval_tool_use_id');   // single-use override consumed
    return $backend->dispatchTool($call);
}

if ($decision->canRetry) {
    // Suggest mode — surface to user. They tap /approve in the UI,
    // host stamps $thread->pending_approval_tool_use_id = $call->id,
    // then re-issues the same call.
    return [
        'error' => $decision->reason,
        'code'  => $decision->errorCode,    // 'mutation_pending_approval' or
                                            // 'destructive_pending_approval'
        'tool_use_id' => $call->id,
    ];
}

// Hard deny — Never mode rejecting a mutation. Tell the user to switch
// modes; do NOT auto-retry.
throw new RuntimeException($decision->reason);
```

### The `/approve` flow

1. Agent emits a `tool_use` block that mutates state.
2. Gate returns `canRetry: true`, code `mutation_pending_approval`, with the call's `tool_use_id`.
3. Host UI shows "Approve this call?" with the diff / shell command.
4. User taps `/approve`. Host stores `tool_use_id` in `pending_approval_tool_use_id`.
5. Host re-runs the same agent turn. The gate sees `approvedToolUseId === toolUseId`, returns `allow`.
6. Host clears `pending_approval_tool_use_id`. Single-use — the next call from the agent goes through the gate fresh.

The override is `hash_equals($approvedToolUseId, $toolUseId)` — string
equality, no encoding tricks. Host owns the storage and clearing
discipline; the gate is stateless.

### Custom destructive-scanner

The gate constructor takes an optional
`DestructiveCommandScanner`. To override (e.g. add SQL DROP detection),
re-bind:

```php
$this->app->singleton(\SuperAICore\Runner\ApprovalGate::class, function ($app) {
    return new \SuperAICore\Runner\ApprovalGate(
        scanner: new \App\Guidance\StrictScanner(),
    );
});
```

---

## 24. Workspace plugin manifest (0.9.1)

*Since 0.9.1.*

Codex's "workspace plugin sharing" pattern in PHP. A team commits
`.superaicore/workspace-plugins.json` to the repo; new hires get the
team's full plugin set on `git clone` instead of a per-machine
onboarding doc.

### Manifest format

```json
{
    "plugins": [
        {
            "name":    "team-pr-review",
            "source":  "github.com/our-org/agent-skill-pr-review",
            "version": "1.4.0",
            "scope":   "workspace"
        },
        {
            "name":    "team-jira-helper",
            "source":  "github.com/our-org/agent-skill-jira",
            "version": "0.8.2",
            "scope":   "user"
        }
    ]
}
```

- `scope: "workspace"` → must be installed for everyone working in this repo. The registry returns it as `missing_required`.
- `scope: "user"` → recommendation only. Returned as `missing_recommended`; host UI prompts the developer rather than auto-installing.

### Sync loop

```php
use SuperAICore\Plugins\WorkspacePluginRegistry;

$registry = app(WorkspacePluginRegistry::class);

// Gather names of plugins the host already has installed locally —
// this is host-specific (your PluginInstaller knows where they live).
$installedNames = collect(app(\App\Plugins\PluginInstaller::class)->list())
    ->pluck('name')
    ->all();

$pending = $registry->pendingInstalls($installedNames);
// → [
//     'missing_required'    => [['name' => 'team-pr-review',  …]],
//     'missing_recommended' => [['name' => 'team-jira-helper', …]],
//   ]

foreach ($pending['missing_required'] as $entry) {
    // Auto-install — no prompt; this is a workspace-scope requirement.
    app(\App\Plugins\PluginInstaller::class)->install(
        $entry['name'], $entry['source'], $entry['version'],
    );
}

if ($pending['missing_recommended']) {
    // Prompt the developer rather than auto-installing.
    $this->info(sprintf(
        "Recommended plugins this workspace uses: %s. Run `php artisan plugin:install --recommended` to add them.",
        collect($pending['missing_recommended'])->pluck('name')->implode(', '),
    ));
}
```

### Adding / removing entries from PHP

```php
$registry->add(
    name:    'team-deploy-helper',
    source:  'github.com/our-org/agent-skill-deploy',
    version: '2.1.0',
    scope:   WorkspacePluginRegistry::SCOPE_WORKSPACE,
);

$registry->remove('team-jira-helper');   // returns true / false
```

The registry writes pretty-printed JSON with stable key ordering, so
the manifest is review-friendly when it lands in a PR.

### Where the manifest lives

Hard-coded at `<workspace_root>/.superaicore/workspace-plugins.json`.
The default `workspaceRoot` is `base_path()`. Override the singleton
binding if your repo layout puts the workspace root elsewhere:

```php
$this->app->singleton(\SuperAICore\Plugins\WorkspacePluginRegistry::class, function () {
    return new \SuperAICore\Plugins\WorkspacePluginRegistry(
        workspaceRoot: '/var/www/myapp',
    );
});
```

---

## 25. Headless `/v1/usage` JSON endpoint (0.9.1)

*Since 0.9.1.*

`Http\Controllers\UsageApiController` mirrors codex's app-server
`/v1/usage` shape — one axis per request, identical bucket schema. For
billing pipelines / Grafana / CI cost gates that don't want to scrape
the HTML dashboard.

### Route registration + auth

The route is registered under the package's standard prefix (default
`super-ai-core`):

```
GET /super-ai-core/v1/usage
```

Auth is the host's responsibility. Wrap the surrounding route group or
the per-route middleware in your config:

```php
// config/super-ai-core.php
return [
    'route' => [
        'middleware' => ['web', 'auth:sanctum', 'can:view-billing'],
    ],
];
```

The controller does not assume a session; without middleware, every
caller that reaches the endpoint gets aggregate cost data.

### Query parameters

| key         | type   | default | notes                                              |
| ----------- | ------ | ------- | -------------------------------------------------- |
| `group_by`  | string | `day`   | one of `day`, `model`, `provider`, `thread`, `backend`, `task_type` |
| `days`      | int    | `30`    | clamped to ≥ 1                                     |
| `model`     | string | —       | exact-match filter on `ai_usage_logs.model`         |
| `task_type` | string | —       | exact-match filter on `ai_usage_logs.task_type`     |
| `user_id`   | string | —       | exact-match filter on `ai_usage_logs.user_id`       |
| `backend`   | string | —       | exact-match filter on `ai_usage_logs.backend`       |

Unknown `group_by` returns 422 with the allowed list.

### Response shape

```json
{
    "group_by": "model",
    "from":     "2026-04-04T00:00:00+00:00",
    "to":       "2026-05-04T17:21:48+00:00",
    "buckets": [
        {
            "bucket":            "claude-opus-4-7",
            "runs":              412,
            "cost_usd":          12.847291,
            "shadow_cost_usd":   12.847291,
            "input_tokens":      1837421,
            "output_tokens":     291038,
            "cache_read_tokens": 4129873,
            "cache_hit_rate":    0.6921
        },
        …
    ]
}
```

`cache_hit_rate` is computed inside the bucket — `cache_read /
(input + cache_read)` — rather than averaged from the per-row stamp,
so it stays correct regardless of which subset of rows had the metadata
key set.

### Curl examples

```bash
# Daily spend by model, last 7 days
curl -H "Authorization: Bearer $TOKEN" \
    'https://app.example.com/super-ai-core/v1/usage?group_by=day&days=7'

# Per-thread cost over the last month, scoped to the SuperAgent backend
curl -H "Authorization: Bearer $TOKEN" \
    'https://app.example.com/super-ai-core/v1/usage?group_by=thread&backend=superagent&days=30'

# Provider-level breakdown for a single task type
curl -H "Authorization: Bearer $TOKEN" \
    'https://app.example.com/super-ai-core/v1/usage?group_by=provider&task_type=email_summary'
```

### Grafana JSON datasource

The shape is compatible with the JSON-based Grafana datasource — point
the panel at `/super-ai-core/v1/usage?group_by=day&days=$__range_days`,
field map `bucket → time` and pick `cost_usd` / `cache_hit_rate` as
metrics. The 5000-row hard cap inside the controller keeps a runaway
date range from breaking the Grafana fetch.

### Limits

- `limit(5000)` on the underlying query — outside that window the bucket totals stay correct but the exact slice is the most-recent 5000 rows. Tighten the date range or filter by `backend` / `model` if you need a wider window.
- Filters are exact-match only; no `LIKE` / `IN` / regex. For richer queries the HTML dashboard's `UsageController` has the full filter surface, or build your own controller on top of `AiUsageLog`.

---

## 26. `cache_hit_rate` aggregation (0.9.1)

*Since 0.9.1 — companion to DeepSeek-TUI's per-turn cache-rate display.*

Every `ai_usage_logs` row whose `metadata` carries a non-zero cache
slice now also carries `metadata.cache_hit_rate ∈ [0, 1]`.

### Why the GROSS denominator

```
cache_hit_rate = cache_read_tokens / (input_tokens + cache_read_tokens)
                                       └── uncached input ──┘
```

The denominator is the **gross** prompt — the total prompt size before
the cache discount, not just the cached portion. Group-and-average
across rows works correctly because every row uses the same denominator
shape: aggregate `cache_read` and `input` separately, then divide.

```php
// Grouping by model — reads correctly without re-deriving:
$rows = AiUsageLog::where('model', 'claude-opus-4-7')
    ->where('created_at', '>=', now()->subDays(7))
    ->get();

$rates = $rows->avg(fn ($r) => $r->metadata['cache_hit_rate'] ?? null);
// vs. the ground truth recompute, which gives the same number:
$cacheRead = $rows->sum(fn ($r) => $r->metadata['cache_read_tokens'] ?? 0);
$gross     = $rows->sum('input_tokens') + $cacheRead;
$truth     = $gross > 0 ? $cacheRead / $gross : 0;
```

### Absent vs zero — semantic difference

| state                              | `cache_hit_rate` value | meaning                            |
| ---------------------------------- | ---------------------- | ---------------------------------- |
| no cache key sent, cache disabled  | absent (key not set)   | "no cache eligible"                |
| cache key sent, full cache miss    | `0.0`                  | "0% hit — cold cache or churn"     |
| cache key sent, partial hit        | `0.42`                 | "42% of paid prompt was free"      |
| cache key sent, full hit           | `1.0`                  | "100% hit — sticky session"        |

Dashboards that filter on `cache_hit_rate IS NOT NULL` cleanly separate
"feature in use, just cold" from "feature not used at all".

### DeepSeek V3 / R1 alias

Older DeepSeek wires (V3, R1) stamped `cache_hit_tokens` instead of
`cache_read_tokens`. `UsageRecorder` accepts both — the alias is read
on the way in, the canonical key is written on the way out.

```php
// Both of these produce the same row:
$recorder->record(['cache_read_tokens' => 1500, …]);
$recorder->record(['cache_hit_tokens'  => 1500, …]);   // legacy alias
```

Host code that historically stamped the alias on usage records is
forward-compatible — no migration needed.

### `total_cache_read_tokens` summary card

The `/usage` page's session-summary block now carries
`total_cache_read_tokens` alongside the existing cold-cache and
ambient-cost slices. This is the absolute count, not the rate — the
rate appears per-model and per-row.

### Reading from a queue worker

The `cache_hit_rate` column is part of `metadata` (JSON), not a
top-level column, so MySQL 5.7 / SQLite installs without JSON-path
indexing read it inline:

```php
AiUsageLog::query()
    ->where('created_at', '>=', now()->subDay())
    ->whereNotNull('metadata')
    ->get()
    ->filter(fn ($r) => isset($r->metadata['cache_hit_rate']))
    ->groupBy('model')
    ->map(fn ($rows) => [
        'avg_rate' => round($rows->avg(fn ($r) => $r->metadata['cache_hit_rate']), 4),
        'runs'     => $rows->count(),
    ]);
```

The `/v1/usage` endpoint (§25) does the same calculation server-side
across the six group-by axes — usually easier than rolling your own.

---

## 27. TaskRunner reliability wave (0.9.2)

*Since 0.9.2 — `Runner\TaskRunner` only.*

TaskRunner can hand a task to another backend when the primary backend
fails with quota/rate-limit style output. This is designed for long
operator jobs where a CLI subscription or API key can run out mid-task,
but the host still wants the same prompt to continue on Codex, Gemini,
Kimi, or an HTTP backend.

Fallback is **per run**. The requested backend is always tried first, so
there is no sticky failover state to reset when the primary recovers.

### Per-call chain

```php
use SuperAICore\Runner\TaskRunner;

$envelope = app(TaskRunner::class)->run('claude_cli', $prompt, [
    'fallback_profile' => 'coding',
    'fallback_chain' => ['claude_cli', 'codex_cli', 'gemini_cli', 'kimi_cli'],
    'fallback_on' => ['rate limit', 'usage limit', 'quota', '429'],
    'inherit_failure_context' => true,
    'log_file' => storage_path('logs/tasks/123.log'),
]);
```

If `claude_cli` fails with matching output, TaskRunner retries on
`codex_cli`. The second prompt is the original prompt plus a compact
handoff block containing the previous backend, exit code, and a tail of
the previous output/log. Set `inherit_failure_context=false` when the next
backend should receive the original prompt only.

### Automatic chain

```php
$envelope = app(TaskRunner::class)->run('claude_cli', $prompt, [
    'fallback_chain' => 'auto',
]);
```

`auto` uses registered/enabled backends, defaulting to:

```text
claude_cli -> codex_cli -> gemini_cli -> kimi_cli -> copilot_cli ->
kiro_cli -> superagent -> anthropic_api -> openai_api -> gemini_api
```

Set `AI_CORE_TASK_FALLBACK_CHECK_AVAILABILITY=true` to ask each registered
backend whether its binary or credentials appear usable before it enters
the auto chain.

### Global defaults

```dotenv
AI_CORE_TASK_FALLBACK_AUTO=false
AI_CORE_TASK_FALLBACK_CHAIN=claude_cli,codex_cli,gemini_cli
AI_CORE_TASK_FALLBACK_CHECK_AVAILABILITY=false
AI_CORE_TASK_FALLBACK_INHERIT_CONTEXT=true
```

The matching config block is `super-ai-core.task_fallback`:

```php
'task_fallback' => [
    'auto_enabled' => false,
    'check_availability' => false,
    'chain' => [],
    'auto_chain' => ['claude_cli', 'codex_cli', 'gemini_cli', /* ... */],
    'fallback_on' => ['rate limit', 'usage limit', 'quota', '429'],
    'inherit_failure_context' => true,
],
```

Per-call options override config. `fallback_chain` can be a comma-separated
string, an array, or `'auto'`.

When `fallback_chain` is omitted, TaskRunner resolves workload policy in
this order:

```text
fallback_profile / chains_by_profile
-> task_type / chains_by_task_type
-> capability / chains_by_capability
-> metadata task_kind / priority / requires_tools via chains_by_metadata
-> task_fallback.chain
-> auto_enabled / auto_chain
```

Example config:

```php
'task_fallback' => [
    'chains_by_profile' => [
        'coding' => ['claude_cli', 'codex_cli', 'gemini_cli'],
        'research' => ['claude_cli', 'kimi_cli', 'gemini_cli'],
    ],
    'chains_by_task_type' => [
        'tasks.run' => ['claude_cli', 'codex_cli'],
    ],
    'chains_by_capability' => [
        'summarise' => ['claude_cli', 'kimi_cli'],
    ],
    'chains_by_metadata' => [
        'priority' => [
            'cheap' => ['gemini_cli', 'kimi_cli', 'openai_api'],
            'fast' => ['codex_cli', 'gemini_cli', 'openai_api'],
        ],
    ],
],
```

Built-in profiles are `coding`, `research`, `summarise`, `maintenance`,
`cheap`, `fast`, and `headless`; hosts can override any of them in config.

### Limits, cooldown, callbacks, and quality guard

```php
$envelope = app(TaskRunner::class)->run('claude_cli', $prompt, [
    'fallback_profile' => 'coding',
    'fallback_max_attempts' => 2,
    'fallback_max_cost_usd' => 0.50,
    'fallback_backoff_ms' => 250,
    'fallback_backoff_strategy' => 'exponential',
    'fallback_success_min_chars' => 200,
    'fallback_success_forbidden_patterns' => ['I cannot complete', 'try again later'],
    'onAttemptStart' => fn (array $e) => $task->markAttempting($e['backend']),
    'onFallback' => fn (array $e) => $task->markFallback($e['from_backend'], $e['to_backend']),
]);
```

Enable cooldown in config when repeated primary quota hits are noisy:

```php
'cooldown' => [
    'enabled' => true,
    'seconds' => 300,
    'min_failures' => 2,
],
```

Cooldown skips are included in `fallbackDecision.skipped`.

### Matching semantics

Fallback only continues when the failed envelope contains a configured
fragment in `error`, `output`, `summary`, the tail of `log_file`, or the
exit code string. Defaults include:

- `rate limit`, `rate_limit`, `usage limit`
- `quota`, `quota_exceeded`, `insufficient_quota`
- `too many requests`, `429`
- `billing`, `budget`, `limit reached`
- `usage_not_included`

This keeps prompt validation errors, missing files, tool failures, and
other non-quota errors on the original backend unless the host explicitly
adds them to `fallback_on`.

### Attempt report

When fallback is active, the returned envelope includes:

```php
$envelope->fallbackReport === [
    [
        'attempt' => 1,
        'backend' => 'claude_cli',
        'success' => false,
        'retryable' => true,
        'next_backend' => 'codex_cli',
        'exit_code' => 1,
        'model' => null,
        'duration_ms' => 0,
        'usage_log_id' => null,
        'cost_usd' => null,
        'billing_model' => null,
        'log_file' => '/path/to/log',
        'error' => 'Claude usage limit reached. Try again later.',
    ],
    [
        'attempt' => 2,
        'backend' => 'codex_cli',
        'success' => true,
        'retryable' => false,
        'next_backend' => null,
        'exit_code' => 0,
        'model' => 'gpt-5.2',
        'duration_ms' => 1500,
        'usage_log_id' => 123,
        'cost_usd' => 0.01,
        'billing_model' => 'usage',
        'log_file' => '/path/to/log',
        'error' => null,
    ],
];
```

`TaskResultEnvelope::toArray()` exposes the same data under
`fallback_report`, so hosts that persist the envelope can store it without
special casing.

Each Dispatcher attempt also receives metadata suitable for usage-row
analytics:

```php
[
    'fallback_active' => true,
    'fallback_chain' => ['claude_cli', 'codex_cli'],
    'fallback_attempt' => 2,
    'fallback_primary_backend' => 'claude_cli',
    'fallback_backend' => 'codex_cli',
    'fallback_chain_index' => 1,
]
```

The decision report explains chain-level behaviour:

```php
$envelope->fallbackDecision === [
    'source' => 'profile',
    'chain' => ['claude_cli', 'codex_cli'],
    'skipped' => [],
    'events' => [
        ['event' => 'fallback', 'from_backend' => 'claude_cli', 'to_backend' => 'codex_cli'],
        ['event' => 'stop', 'backend' => 'codex_cli', 'reason' => 'success'],
    ],
    'total_cost_usd' => 0.01,
];
```

For dry-runs and operator UI:

```php
$plan = app(TaskRunner::class)->explainFallbackChain('claude_cli', [
    'fallback_profile' => 'coding',
]);
```

Artisan exposes the same policy:

```bash
php artisan super-ai-core:fallback-policy claude_cli --profile=coding
php artisan super-ai-core:fallback-policy claude_cli --profile=coding --json
```

### Related implementation directions

Use the fallback primitives as a reliability layer, not only as a
last-ditch retry:

- **Per task type chains** — keep different defaults for coding,
  research, summarisation, and background maintenance. Coding chains often
  start with `claude_cli` or `codex_cli`; summarisation can safely include
  `kimi_cli`; direct HTTP backends work well as the final headless stop.
- **UI status badges** — persist `fallback_report` with the host task row
  and render compact states such as "primary limited", "continued on
  codex", or "stopped on non-retryable error". Link each attempt to its
  `log_file` when present.
- **Queue retry policy** — use TaskRunner fallback before queue-level retry.
  A queue retry repeats the whole job; fallback keeps the same logical run
  moving and preserves context from the failed backend.
- **Reliability analytics** — group `fallback_report[*].backend` with
  `ai_usage_logs.backend` to find primaries that frequently hit quota and
  secondaries that actually finish work. This gives operators a clean input
  for reordering `auto_chain`.
- **Safety reviews** — keep `fallback_on` narrow. Add fragments only for
  errors your host considers retryable; validation failures and tool-policy
  denials should usually remain terminal.
- **Progressive rollout** — start with per-call `fallback_chain` on one task
  class, then move stable chains into `super-ai-core.task_fallback.chain`,
  and enable `AI_CORE_TASK_FALLBACK_AUTO=true` only after the host has
  reviewed backend availability and billing behaviour.

---

## 28. Squad multi-agent + SDK 1.0.0 companion bindings (0.9.6)

*Since 0.9.6 — SDK constraint moves to `^1.0`.*

0.9.6 lands the SDK 1.0.0 `Squad` peer-collaboration pipeline as a
tenth dispatcher adapter and wraps the SDK 0.9.8 companion primitives
(`AutoModelStrategy`, `CacheAwareCompressor`, `UntrustedInput`,
`TokenBucket`, `AdHocMemoryProvider`, `Conversation\Fork`,
`AgentDepthGuard`, DeepSeek FIM) behind first-class host services so
they're addressable from any dispatch path. Every binding is additive
and opt-in.

### Squad pipeline — adaptive cross-model dispatch

```php
use SuperAICore\Services\Dispatcher;

$result = app(Dispatcher::class)->dispatch([
    'backend' => 'squad',
    'prompt'  => 'Refactor the AuthController to use Laravel Sanctum.',

    // Optional — defaults to heuristic decomposition via TaskDecomposer.
    // Each subtask carries a difficulty class (trivial/easy/moderate/hard/expert)
    // that ModelTierMap maps to one of the tiered providers.
    'subtasks' => [
        ['role' => 'planner',   'description' => 'Propose the file changes',     'difficulty' => 'moderate'],
        ['role' => 'editor',    'description' => 'Apply the diff',               'difficulty' => 'hard'],
        ['role' => 'reviewer',  'description' => 'Sanity-check the result',      'difficulty' => 'easy'],
    ],

    // Optional — override the global tier map for this dispatch.
    'tier_map' => [
        'trivial'  => ['provider' => 'anthropic', 'model' => 'claude-haiku-4-5'],
        'easy'     => ['provider' => 'deepseek',  'model' => 'deepseek-v4-flash'],
        'moderate' => ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6'],
        'hard'     => ['provider' => 'anthropic', 'model' => 'claude-opus-4-7'],
    ],

    'max_cost_usd'   => 2.50,                // optional — downshift at 80%
    'checkpoint_dir' => storage_path('app/squad/auth-refactor'),
    'squad_id'       => 'auth-refactor-2026-05-16',  // re-dispatch with same id to resume
]);

// Envelope surface
$result['text'];                       // merged outputs across steps
$result['cost_usd'];                   // sum across step dispatches
$result['turns'];                      // number of steps that ran
$result['squad']['squad_id'];
$result['squad']['step_count'];
$result['squad']['completed'];         // list of subtask roles that finished
$result['squad']['roles'];             // list<{name, provider, model, tier}>
$result['squad']['checkpoint_path'];   // on disk — feed back as `checkpoint_dir` to resume
$result['squad']['mailbox_log'];       // peer-message audit trail
```

The pipeline writes a checkpoint after every step. If the process is
killed mid-run, re-dispatching with the same `squad_id` and
`checkpoint_dir` resumes from the last successful step — earlier
roles aren't re-executed.

The cost cap (`max_cost_usd`) is enforced per-step. When the
cumulative cost crosses 80% of the cap, future steps are pushed
down one tier (`hard → moderate`, `moderate → easy`, etc.) until
the pipeline completes or hits the hard ceiling. The envelope's
`squad.roles` array reflects the final tier each step ran at, so
the host UI can render "step 3 downshifted from `hard` to
`moderate`".

When the heuristic `TaskDecomposer` is sufficient (most tasks),
omit `subtasks` entirely. The decomposer reads the prompt, splits
it into planner / editor / verifier / etc. subtasks, and assigns
difficulty classes based on prompt keywords + length heuristics.
Pre-decomposed `subtasks` are most useful when the host has
domain-specific knowledge about how to break a task down (e.g.
a code-review workflow that always wants planner → diff → reviewer
→ doc-writer).

### `smart` and `squad` console commands

Both commands are passthroughs to the vendor `superagent` binary:

```bash
./vendor/bin/superaicore smart "audit this diff"
./vendor/bin/superaicore smart show --last
./vendor/bin/superaicore smart replay <run-id> --max-cost=1.50

./vendor/bin/superaicore squad "refactor the auth module" --max-cost=2.0
./vendor/bin/superaicore squad --no-squad "compare against legacy path"
```

Pass `--binary=/abs/path/to/superagent` when the SDK is installed
outside `vendor/forgeomni/superagent/`.

### `AutoModelRouter` — `/model auto` heuristic

Resolve the service from the container and feed it the same
`Message[]` / `systemPrompt` / `options` triplet the Agent would
see:

```php
use SuperAgent\Messages\Message;
use SuperAICore\Services\AutoModelRouter;

$router = app(AutoModelRouter::class);

$messages = [
    Message::user('Review the migration plan for the user_schema rewrite.'),
    Message::user('Specifically, check whether the backfill is concurrency-safe.'),
];

$pickedModel = $router->select($messages, systemPrompt: 'You are a senior reviewer.', options: [
    'reasoning_effort' => 'max',   // forces Pro tier
]);
// → 'claude-opus-4-7' (when auto_model.pro_model is rebound) or 'deepseek-v4-pro'

$depth = $router->trailingToolChainDepth($messages);  // 0 here — no tool calls
```

Hosts that wire this into their own dispatcher / planner get
escalation on:

- **Long context** — total tokens across messages > `long_context_tokens`
  (default 32,000).
- **Deep tool chains** — trailing run of N+ `tool_use` blocks
  exceeds `tool_chain_threshold` (default 3).
- **Explicit `reasoning_effort=max`** — caller has asked for max
  reasoning; route to Pro.
- **Intent keywords** — system prompt contains `review` / `audit` /
  `design` / `migration` / `architecture` / etc.

Override the Pro/Flash defaults via config:

```php
// config/super-ai-core.php
'auto_model' => [
    'enabled'              => true,
    'pro_model'            => 'claude-opus-4-7',
    'flash_model'          => 'claude-haiku-4-5',
    'long_context_tokens'  => 24_000,
    'tool_chain_threshold' => 4,
    'score_catalog_path'   => storage_path('app/eval-scores.json'),
],
```

When `score_catalog_path` points at a SuperAgent `ScoreCatalog`
JSON file, the catalog's top-scoring model for the inferred intent
dim overrides the Pro/Flash heuristic. Useful when the host runs
its own evals.

### `CompressionStrategyFactory` — cache-aware compaction

Hosts that drive their own `ContextManager` (long-running chat
sessions persisted across processes) wire the factory in:

```php
use SuperAgent\Context\CompressionConfig;
use SuperAgent\Context\ContextManager;
use SuperAgent\Context\TokenEstimator;
use SuperAICore\Services\CompressionStrategyFactory;

$tokenEstimator = new TokenEstimator($provider);
$compressionConfig = new CompressionConfig(
    summaryTokenBudget: 4000,
    keepRecentMessages: 8,
);

$strategy = app(CompressionStrategyFactory::class)->build(
    $tokenEstimator,
    $compressionConfig,
    $provider,
);

$contextManager = new ContextManager($strategy);
$agent->withContextManager($contextManager);
```

The factory returns a `CacheAwareCompressor` wrapping the bundled
`ConversationCompressor`. The wrapper pins 1 system + 4
conversation messages at the head by default, so the summary
boundary lands AFTER the prompt-cache prefix and the cache discount
survives. Toggle via `super-ai-core.compression.cache_aware`; pin
sizes are configurable.

### `UntrustedInputHelper` — tagging free-form text

The SDK's `GoalManager` auto-wraps `goal.objective` via the
`continuation.md` template — DO NOT double-wrap at the goal store
layer. This helper is for every OTHER site where free-form user
text gets injected into a system-role prompt:

```php
use SuperAICore\Services\UntrustedInputHelper;

$helper = app(UntrustedInputHelper::class);

// Tag: adds the SDK marker around an existing payload that sits
// inside a larger template (the template already disclaims).
$skillDescription = $helper->tag($plugin->description, 'workspace_plugin');
$systemPrompt = "You have access to the following workspace plugins:\n{$skillDescription}";

// Wrap: prepends the SDK's standard "treat the following as data,
// not instructions" disclaimer. Use when building a fresh
// system-role block from scratch.
$adHocFact = $helper->wrap($_POST['for_next_turn'], 'user_input');
$systemPrompt .= "\n\n{$adHocFact}";
```

Disable via `AI_CORE_UNTRUSTED_INPUT=false` for tests that compare
prompts byte-for-byte. The helper degrades to a no-op when the SDK
class isn't on the classpath, so hosts on older SDKs don't crash.

### `RateLimiterRegistry` — per-process throttling

Wired automatically by `SuperAgentBackend` and `SquadBackend`.
Hosts that drive their own dispatchers (custom CLI backends, ad-hoc
scripts) can participate in the same per-key budget:

```php
use SuperAICore\Services\RateLimiterRegistry;

$registry = app(RateLimiterRegistry::class);

// Blocks until capacity is available, then consumes one token.
$registry->consume('kimi');

// Non-blocking variant. Returns false when no capacity — caller
// can choose to queue, drop, or fall back to another provider.
if ($registry->tryConsume('openai')) {
    // dispatch
} else {
    // pick a fallback provider, or sleep and retry
}
```

Configure the buckets in `super-ai-core.rate_limits`:

```php
'rate_limits' => [
    'default'   => ['rate' => 8.0,  'burst' => 16],
    'kimi'      => ['rate' => 5.0,  'burst' => 10],
    'openai'    => ['rate' => 16.0, 'burst' => 32],
    'deepseek'  => ['rate' => 8.0,  'burst' => 16],
],
```

Missing keys fall back to `default`. Removing `default` disables
the limiter (`consume()` becomes a no-op). Per-process by design;
distributed swarms (one agent per pod) should use a Redis-backed
Guzzle middleware on the provider's HTTP client — this registry
stays simple and DOES NOT compete with that path.

### `AdHocMemoryRegistry` — per-session "for the next turn" facts

A chat UI exposes an "Inject fact for next turn" textarea. The
controller pushes onto the session's provider; on the next
dispatch the SuperAgent backend renders the inbox block ahead of
the prompt:

```php
use SuperAICore\Services\AdHocMemoryRegistry;

$registry = app(AdHocMemoryRegistry::class);

// In the controller: user typed "ignore the deprecated /v1 endpoints"
$noteId = $registry->push(
    sessionId: $chatSession->id,
    content:   $request->input('for_next_turn'),
    ttlSeconds: 600,           // 10-minute TTL
    untrusted:  true,
    kind:       'note',
);

// Forget the entire session's pool on chat-close
$registry->forget($chatSession->id);
```

Memory is process-local — entries die on shutdown. Durable facts
belong in `MEMORY.md` / `BuiltinMemoryProvider`, not here. The
provider class is the SDK's `AdHocMemoryProvider`; hosts that want
to render the inbox directly can resolve `forSession($id)` and
inspect the queue.

### `ConversationForkService` — codex `/side` semantics

```php
use SuperAICore\Services\ConversationForkService;

$forks = app(ConversationForkService::class);

// Branch the conversation. Store the fork handle under a UUID
// in the URL so the user can revisit it.
$fork = $forks->start($chatSession->messages);
session()->put("fork:{$forkId}", $fork);

// User runs a few messages on the side, comparing models...

// Discard the side: parent is untouched.
$newParent = $forks->finish($fork, 'discard');

// Promote specific side messages back into the parent.
$newParent = $forks->finish($fork, 'promote', [3, 5, 7]);

// Promote everything.
$newParent = $forks->finish($fork, 'promoteAll');

$chatSession->update(['messages' => $newParent]);
```

The service is stateless — fork lifetime is the host's
responsibility. Useful for chat UIs that want "branch this
conversation, try a different model on the side, promote only the
useful side messages back".

### `DeepSeekFimService` — fill-in-the-middle completion

DeepSeek's FIM endpoint lives on the `beta` region. The
chat-shaped `Backend` abstraction doesn't fit (no `messages`,
just a prefix + suffix), so hosts building IDE-style completion
features call this service directly:

```php
use SuperAICore\Services\DeepSeekFimService;

$fim = app(DeepSeekFimService::class);

if ($fim->isAvailable()) {
    $body = $fim->complete(
        prefix: "function calculateTax(\$amount, \$rate) {\n    ",
        suffix: "\n    return \$amount * \$rate;\n}",
        options: [
            'max_tokens'  => 64,
            'temperature' => 0.1,
            'stop'        => ['}'],
        ],
    );
}
```

Set `DEEPSEEK_API_KEY` (or `super-ai-core.deepseek.api_key`) to
enable. The service constructs a per-call provider against the
`beta` region — the chat-region DeepSeek provider explicitly
refuses FIM calls.

### `reasoning_effort` three-tier dial

Per-call option on `Dispatcher::dispatch()`:

```php
$result = $dispatcher->dispatch([
    'backend'          => 'superagent',
    'prompt'           => 'Audit this migration for race conditions.',
    'reasoning_effort' => 'max',   // off | high | max
]);
```

Routes to the right body shape per upstream:
- Most providers: top-level `reasoning_effort` field.
- NVIDIA NIM: `chat_template_kwargs.thinking`.
- Providers without the capability: silently ignored.

Also feeds the `AutoModelRouter` escalation heuristic when set to
`max`.

### `Agent::switchProvider()` handoff

```php
$result = $dispatcher->dispatch([
    'backend' => 'superagent',
    'prompt'  => '...continue this conversation...',
    'handoff' => [
        'provider' => 'kimi',
        'config'   => [
            'api_key' => env('KIMI_API_KEY'),
            'region'  => 'cn',
        ],
        'policy'   => 'preserveAll',   // default | preserveAll | freshStart
    ],
]);

// Envelope warns when the historic conversation won't fit under
// the new model's context window — host can render a "compress
// before next turn" prompt.
$result['handoff_token_status'];
// → ['tokens' => 142_000, 'window' => 128_000, 'fits' => false, 'model' => 'moonshot-v1-128k']
```

`HandoffPolicy::default()` keeps recent turns and discards old
tool outputs. `preserveAll` keeps everything (may not fit the new
window — check `handoff_token_status`). `freshStart` only carries
the system prompt forward.

### Sub-agent depth cap

```php
// config/super-ai-core.php
'agents' => [
    'max_depth' => 3,   // SDK default is 5
],
```

Forwarded to `Swarm\AgentDepthGuard::setMax()` during service
provider boot. Per-process override via `SUPERAGENT_MAX_AGENT_DEPTH`
env var.

### When to reach for which binding

| Binding | When to use it |
| --- | --- |
| `SquadBackend` | Multi-step task that benefits from different models per step (planner → editor → reviewer). Cost cap matters. Want crash-resume via checkpoints. |
| `AutoModelRouter` | You're building a custom dispatcher or planner and want the SDK's Pro/Flash heuristic without coupling to `SuperAgentBackend`. |
| `CompressionStrategyFactory` | You drive your own `ContextManager` for long multi-turn sessions and want the cache prefix to survive summarisation. |
| `UntrustedInputHelper` | You concat free-form text into a system prompt at a site the SDK's `GoalManager` doesn't already own. |
| `RateLimiterRegistry` | Provider has already throttled you upstream and you want belt-and-suspenders client-side. |
| `AdHocMemoryRegistry` | Chat UI exposes "for the next turn" facts and you want per-session isolation. |
| `ConversationForkService` | Chat UI offers branching / "try a different model on the side". |
| `DeepSeekFimService` | IDE-style prefix-completion / inline-fill. Chat-shaped `Backend` won't fit. |
| `reasoning_effort` | You want extra thinking on one specific dispatch without globally rebinding the model. |
| `Agent::switchProvider` handoff | You wrap `SuperAgentBackend` directly and want mid-conversation provider switching. |

---

## 29. SDK 1.0.5 bump + opencode-borrowed feature wave (0.9.7)

Ten patterns lifted from [opencode](https://github.com/sst/opencode)
on top of the SDK 1.0.5 capability release. The headline is a
visibility-first dispatch envelope: every SuperAgent dispatch now
records per-file diffs against a pre/post shadow-git snapshot, the UI
gets a +/- banner and a revert button, and the agent can interrupt to
ask the operator a clarifying question without the host having to
build a side channel. The rest is operational scaffolding: per-agent
permission rulesets, sub-agent permission inheritance, snapshot
retention, plan/build mode, PTY shell sessions, and session sharing.

### 29.1 Per-file diff summary + revert button

**Goal**: every dispatch that touches the worktree should leave a
machine-readable record of what changed, with a one-click revert path
when the model wrote something it shouldn't have.

**Wiring**:

```bash
# SDK 1.0.5 (via 0.9.7 SuperAICore bump) — automatic, no config needed
php artisan migrate                       # picks up the three new ai_usage_logs columns

# Verify the snapshot store is reachable
php -r 'require "vendor/autoload.php"; var_dump((new SuperAgent\Checkpoint\GitShadowStore(getcwd()))->shadowDir());'
```

The Dispatcher writes three new columns on `ai_usage_logs`:

| Column | Type | Meaning |
|---|---|---|
| `pre_snapshot` | varchar(64) | Shadow-git commit captured BEFORE the dispatch ran. Used by `POST /usage/{id}/revert`. |
| `post_snapshot` | varchar(64) | Shadow-git commit captured AFTER the dispatch ran. Used as the `to` side of the per-file diff. |
| `file_diff_summary` | json | `{additions, deletions, files, diffs: [{file, additions, deletions, status, patch, truncated}], truncated}` envelope. |

**Reading the diff envelope from PHP**:

```php
use SuperAICore\Models\AiUsageLog;

$row = AiUsageLog::find($usageLogId);
$diff = $row->file_diff_summary;
echo "+ {$diff['additions']} − {$diff['deletions']} across {$diff['files']} files\n";

foreach ($diff['diffs'] as $f) {
    echo "  {$f['status']} {$f['file']}   +{$f['additions']} −{$f['deletions']}\n";
    if ($f['truncated']) {
        echo "    (patch truncated at 256 KB)\n";
    }
}
```

**Reverting**:

```bash
# UI: click the ↩ button on a /usage row that has a pre_snapshot.
# Headless:
curl -X POST -H "X-CSRF-TOKEN: $TOKEN" "$BASE_URL/usage/$ID/revert"
# → {"ok":true,"message":"Worktree restored to snapshot ab1c2d3.","snapshot":"ab1c2d3…"}
```

Untracked files added since the snapshot are LEFT in place — the
restore matches SuperAgent SDK's `GitShadowStore::restore()` contract.
This is intentional: it lets you keep new logs / artifacts while still
reverting tracked source files.

**Tuning**:

- `AI_CORE_SNAPSHOT_PROJECT_ROOT` — override the path the shadow store
  mirrors. Default resolution: `options['project_root']` →
  `super-ai-core.snapshot.project_root` → `base_path()` → `getcwd()`.
- `AI_CORE_SNAPSHOT_ENABLED=false` — disable diff recording entirely
  for a byte-identical pre-0.9.7 envelope.
- `AI_CORE_SNAPSHOT_REVERT_ENABLED=false` — keep recording but disable
  the revert endpoint (returns 403).
- Per-file patches truncate at 256 KB; the whole diff caps at 200 files.

**Daily prune**:

```bash
php artisan super-ai-core:snapshot-prune --days=7 --dry-run
php artisan super-ai-core:snapshot-prune --days=7
```

Or schedule from `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    $schedule->command('super-ai-core:snapshot-prune')->dailyAt('02:00');
}
```

### 29.2 Mid-run HITL `ask_user` tool

**Goal**: the agent should be able to interrupt and ask the operator
for a decision when it discovers a fork in the road, instead of
guessing wrong or waiting for the next prompt.

**Wiring**:

```dotenv
AI_CORE_TOOLS_ASK_USER=true
```

That's it. `SuperAgentBackend` attaches `AskUserTool` to every dispatch
when the flag is on. The tool inserts an `ai_user_questions` row, polls
every 500ms, and returns the operator's answer (or an error when the
timeout fires).

**What the model emits** (visible in the agent's tool-use trace):

```json
{
  "name": "ask_user",
  "input": {
    "question": "The migration adds a NOT NULL column to a 50M-row table. Apply with `IF NOT EXISTS` or write a batched backfill?",
    "options": [
      {"label": "IF NOT EXISTS", "description": "One-shot; brief table lock"},
      {"label": "Batched backfill", "description": "Slow but no lock; safer in production"}
    ],
    "timeout_seconds": 1200
  }
}
```

**What the operator sees** on `/processes`: an inline warning card with
the question, optional buttons for the predefined choices, and a
free-form text input when no choices were supplied. The card polls
`/processes/questions` every 4 seconds and disappears when the row's
status flips to `answered`.

**Programmatic answer** (when you need a non-UI client):

```bash
curl -X POST -H "Content-Type: application/json" -H "X-CSRF-TOKEN: $TOKEN" \
  "$BASE_URL/processes/questions/$QUESTION_ID/answer" \
  -d '{"answer": "Batched backfill"}'
```

**When NOT to use it**:

- Long-running unattended queue workers — the polling loop blocks the
  agent for up to `timeout_seconds` (default 600s, capped at 3600s).
  Leave `AI_CORE_TOOLS_ASK_USER` off for jobs that run without a human
  present.
- Branchless decisions the conversation context already disambiguates.
  The tool description tells the model not to ask for guesses the user
  could infer; trust that gate.

### 29.3 Session reminders

**Goal**: prepend context-sensitive guidance to the system prompt
without the caller having to know about it. Useful for plan-mode
markers, security-sensitive areas, project-specific conventions, etc.

**Config** (`config/super-ai-core.php`):

```php
'reminders' => [
    'rules' => [
        [
            'name' => 'plan-mode-active',
            'when' => ['agent' => 'plan'],
            'text' => "## Plan mode active\nWrite the plan to `.superagent/plans/{session}.md`. Do NOT call any edit/write tool against the project worktree.",
        ],
        [
            'name' => 'security-sensitive-area',
            'when' => ['metadata.path' => 'src/Auth/*'],
            'text' => "## Security note\nThis directory holds auth + permission code. Prefer additive changes; flag anything that touches token storage for human review.",
        ],
        [
            'name' => 'compliance-region-eu',
            'when' => ['metadata.region' => 'eu'],
            'text' => "## Compliance\nThis dispatch runs in the EU region — GDPR rules apply. Do not include user PII in any prompt body.",
        ],
    ],
],
```

**Match semantics**:

- `when` keys are dotted-path lookups into `$options` passed to
  `Dispatcher::dispatch()`. Empty `when` (or omitted) means "always
  match" — useful for a global compliance banner.
- Values support shell-style globs (`fnmatch`) so `'metadata.path' =>
  'src/Auth/*'` matches anything under `src/Auth/`.
- Rules fire in declaration order; matching bodies are joined with a
  blank line and prepended to the caller's system prompt.

### 29.4 Per-agent permission ruleset

**Goal**: declarative per-agent tool gating. The `plan` agent should
only be able to write `.md` plan files; the `explore` agent should be
read-only; the `build` agent should have the full surface.

**Config** (`config/super-ai-core.php`):

```php
'agents' => [
    'plan' => [
        'permission' => [
            '*'     => 'allow',
            'edit'  => ['*' => 'deny', '*.md' => 'allow'],
            'write' => ['*' => 'deny', '*.md' => 'allow'],
        ],
    ],
    'explore' => [
        'permission' => [
            '*'     => 'deny',
            'read'  => 'allow',
            'grep'  => 'allow',
            'glob'  => 'allow',
            'list'  => 'allow',
            'bash'  => 'allow',
        ],
    ],
    'build' => [
        'permission' => [
            '*' => 'allow',
        ],
    ],
],
```

**Evaluation semantics** (opencode `permission/evaluate.ts`):

- Rule shape: `{permission, pattern, action}`. Values can be strings
  (broadcast action for that tool) or per-pattern maps.
- LAST matching rule wins. A broad `'*' => 'allow'` followed by a
  specific `'edit' => 'deny'` results in `edit` denied.
- Default action when nothing matches: `ask`. The evaluator's
  `project()` method surfaces three lists — `allowed_tools`,
  `denied_tools`, `ask_tools` — and SuperAgentBackend wires the first
  two onto the agent. `ask_tools` lets a host build its own HITL hook
  on a narrower surface.

**Triggering**: pass `options['agent']` (or `metadata.agent`) on the
dispatch. SuperAgentBackend reads it, consults the evaluator, and
attaches the resulting allowed/denied lists to the SDK `Agent` —
unless the caller passed explicit `allowed_tools` / `denied_tools`,
which always wins.

### 29.5 Plan mode workflow

**Goal**: the model writes a plan to a markdown file; the operator
approves; the build agent executes the approved plan. Same shape as
opencode's plan_enter / plan_exit pattern.

**Dispatch**:

```php
use SuperAICore\Modes\CliModeRouter;
use SuperAgent\Modes\ModeContext;

$ctx = ModeContext::root('plan');
$result = app(CliModeRouter::class)->dispatch(
    'plan',
    "Refactor the auth middleware to drop the legacy session-token storage path.",
    $ctx,
);

echo $result->text;                // build phase output (or plan text if rejected)
echo $result->modeSpecific['plan_file'];   // .superagent/plans/{session}.md
echo $result->modeSpecific['phase'];       // completed | plan_rejected
```

**What happens in order**:

1. **Plan phase**: dispatched against `super-ai-core.modes.plan.plan_backend`
   (default `cli:claude_cli`). Synthetic system prompt + the
   `super-ai-core.agents.plan.permission` ruleset (when declared) deny
   edits outside the plan file. The model writes
   `.superagent/plans/{session}.md`.
2. **Approval phase**: an `ai_user_questions` row goes up asking the
   operator to `[Approve, Reject]`. The orchestrator polls every 500ms
   until `approval_timeout` (default 600s). When HITL is disabled
   (`tools.ask_user_enabled=false`), auto-approves so the orchestrator
   stays usable in CI.
3. **Build phase**: dispatched against `super-ai-core.modes.plan.build_backend`
   with a synthetic prompt that points at the approved plan file +
   includes its full text.

**Config** (`config/super-ai-core.php`):

```php
'modes' => [
    'plan' => [
        'enabled'          => true,
        'plan_backend'     => 'cli:claude_cli',
        'build_backend'    => 'cli:claude_cli',
        'plan_dir'         => '.superagent/plans',
        'auto_approve'     => null,           // null = auto-detect
        'approval_timeout' => 600,
    ],
],
```

### 29.6 Sub-agent permission derivation

**Goal**: when a parent agent dispatches a sub-agent (via SuperAgent's
`AgentTool` or any nested dispatch), the child must inherit the
parent's deny list. A read-only parent always produces read-only
children.

**Two signal sources**:

```php
// Option A: explicit pass-through (use this from your own dispatcher when
// you know exactly what the parent denied)
$child = $dispatcher->dispatch([
    'prompt'              => $task,
    'agent'               => 'explore',
    'parent_denied_tools' => ['edit', 'write', 'bash'],
]);

// Option B: agent-name resolution (let the PermissionEvaluator look up
// the parent's ruleset from config). Cleaner when the parent is one
// of the agents declared in super-ai-core.agents.{name}.permission.
$child = $dispatcher->dispatch([
    'prompt'   => $task,
    'agent'    => 'explore',
    'metadata' => ['parent_agent' => 'plan'],
]);
```

**Merge semantics**: child's effective deny set is
`union(explicit_child_denied, agent_rule_denied, parent_denied)`. It's
monotonic — children can never elevate.

### 29.7 PTY long-lived shell sessions (Phase 1)

**Goal**: stream long-running shell processes (test watchers, `tail
-f`, `npm run dev`) into the UI without blocking the agent loop.

**Wiring**:

```dotenv
AI_CORE_PTY_ENABLED=true
```

**Spawn**:

```bash
curl -X POST -H "Content-Type: application/json" -H "X-CSRF-TOKEN: $TOKEN" \
  "$BASE_URL/pty/sessions" \
  -d '{"command":"npm run dev","cwd":"/srv/app","title":"vite watcher"}'
# → {"ok":true,"session":{"id":42,"pid":12345,"status":"running","log_path":"..."}}
```

**Poll**:

```bash
curl "$BASE_URL/pty/sessions/42/poll?cursor=0"
# → {"ok":true,"id":42,"chunk":"vite v5.4.0 ready in 184ms\n  ➜  Local:   http://...","cursor":48,"status":"running","exit_code":null}

# Next poll resumes from the returned cursor
curl "$BASE_URL/pty/sessions/42/poll?cursor=48"
```

**Terminate**:

```bash
curl -X POST -H "X-CSRF-TOKEN: $TOKEN" "$BASE_URL/pty/sessions/42/kill"
```

**Phase 1 limitations**:

- No stdin. The `write` endpoint returns 501. PHP can't keep a pipe
  alive across HTTP requests without a persistent worker.
- No real TTY. We spawn via `proc_open`, not `openpty`. Consumers that
  need real terminal semantics (curses-mode TUIs, escape-sequence
  cursor positioning) won't render right.

**Phase 2 (deferred)** will upgrade the wire to WebSocket via Laravel
Reverb / Soketi, keeping the cursor-keyed protocol unchanged.

### 29.8 Session share host queue

**Goal**: mint a shareable URL for a session so a colleague can review
the agent's audit trail without DB access.

**REMOTE mode** (push to an external sharer):

```dotenv
AI_CORE_SHARE_ENABLED=true
AI_CORE_SHARE_REMOTE_URL=https://share.acme.example.com
AI_CORE_SHARE_SECRET=opaque-bearer-token-the-sharer-accepts
```

```bash
curl -X POST -H "X-CSRF-TOKEN: $TOKEN" "$BASE_URL/share/sessions/$SESSION_ID/create"
# → {"ok":true,"share_id":"abc123…","share_url":"https://share.acme.example.com/shares/abc123…","status":"active","message":"Share ready."}
```

**LOCAL mode** (intranet — host's own SuperAICore serves the share):

```dotenv
AI_CORE_SHARE_ENABLED=true
AI_CORE_SHARE_LOCAL_URL_TEMPLATE=https://internal.acme.example.com/super-ai-core/shares/{share_id}
```

The local URL template is rendered by substituting `{share_id}` into
the placeholder. ShareSessionService writes the row but does NOT push
anywhere; the host is expected to surface a route that reads the row
and renders the session by its `share_id`.

**Revocation**:

```bash
curl -X POST -H "X-CSRF-TOKEN: $TOKEN" "$BASE_URL/share/sessions/$SESSION_ID/destroy"
```

The local row flips to `revoked`. For REMOTE mode, a best-effort DELETE
also fires against `<remote_url>/api/shares/<share_id>` — failures are
silenced because the local revocation alone is sufficient to stop
surfacing the link.

### 29.9 SDK 1.0.5 plumbing — LSP, structured compactor, Gemini 3.5

- **LSP tool** — set `AI_CORE_TOOLS_LSP=true` and SuperAgentBackend
  prepends `lsp` to the implicit `load_tools` list. The agent gets
  `lsp.diagnostics($file)` / `lsp.hover($file, $line, $col)` /
  `lsp.definition($file, $line, $col)` / `lsp.touch($file)` against
  any of the SDK's 9 bundled language servers (phpactor, intelephense,
  gopls, rust-analyzer, pyright, typescript-language-server, clangd,
  bash-language-server, zls). Per-server root markers are
  composer.json / go.mod / Cargo.toml / etc.
- **Structured compactor summary** — set
  `AI_CORE_COMPRESSION_SUMMARY_PROMPT=structured` to opt every
  dispatch into SDK 1.0.5's 7-section template (Goal / Constraints /
  Progress / Decisions / Next Steps / Critical Context / Relevant
  Files). ~30-50% smaller than the default; preserves blocked-item
  state across consecutive compactions. Per-call
  `options['summary_prompt']` wins.
- **Gemini 3.5 features** — pass `thinking`, `grounding` /
  `google_search`, `url_context` as per-call options on
  `Dispatcher::dispatch()`. SuperAgentBackend forwards them to
  `Agent::run($prompt, $options)`; the SDK's `GeminiProvider` gates on
  `modelSupportsThinking()` for the thinking branch and only appends
  `{googleSearch: {}}` / `{urlContext: {}}` to its own `tools[]`.
  Silently ignored by non-Gemini providers.
- **Cross-provider handoff transcoder fixes** — SDK 0.9.5's
  `ChatCompletionsProvider::convertMessage()` early-return bug
  (corrupted multi-turn tool-use traces against Kimi / GLM / MiniMax /
  Qwen / OpenAI / OpenRouter / LMStudio) is fixed in the SDK 1.0.5
  pin. Hosts using `max_turns > 1` against any of those providers
  upgrade silently — no code changes.
- **`gemini-3.5-pro / -flash / -flash-lite` in `EngineCatalog`** — the
  three Gemini 3.5 slugs are now available models for the gemini-cli
  engine and surface in the dropdown. The system gemini CLI may not
  accept the 3.5 slugs yet; SDK callers using `sdk:` tags drive them
  fine today.

### 29.10 When to use each

| You want… | Reach for… |
|---|---|
| "show me what this dispatch actually changed" | Per-file diff summary (§29.1) |
| "undo this run's worktree edits" | Revert endpoint (§29.1) |
| "agent should ask the user before doing X" | `ask_user` tool (§29.2) |
| "context-sensitive system-prompt prepend" | Session reminders (§29.3) |
| "this agent must be read-only" | Per-agent ruleset (§29.4) |
| "plan first, build only after approval" | Plan mode (§29.5) |
| "sub-agents must inherit parent's deny set" | Sub-agent perm derivation (§29.6) |
| "stream a long-running shell into the UI" | PTY sessions (§29.7) |
| "share this session with a colleague" | Session share queue (§29.8) |
| "agent needs LSP diagnostics mid-loop" | LSP tool (§29.9) |
| "smaller compactor summary for long sessions" | `summary_prompt: structured` (§29.9) |
| "Gemini 3.5 thinking + grounding" | Gemini 3.5 per-call options (§29.9) |

---

## 30. Opus 4.8 + Grok + Cursor (1.0.0 / SDK 1.0.9)

The 1.0.0 stable cut takes SDK `^1.0.9` and adds the Claude Opus 4.8
generation, xAI Grok on two independent channels, and two new
subscription CLI engines (Cursor Composer + Grok Build). Everything below
is additive — no schema changes, no config publish.

### 30.1 Claude Opus 4.8 routing

`claude-opus-4-8` is the new Anthropic flagship: it owns the `opus` alias,
native 1M context, interleaved thinking, fast mode, and effort control, at
the Opus tier ($15 / $75 per 1M). The alias resolves automatically:

```php
use SuperAICore\Services\ClaudeModelResolver;

ClaudeModelResolver::resolve('opus');            // 'claude-opus-4-8'
ClaudeModelResolver::resolve('claude-opus-4-8'); // passthrough
```

The `claude` engine catalog, `model_pricing`, and the `squad` / `cli_squad`
**expert** tiers all point at 4.8. Pin an older Opus explicitly when you
need it — older ids stay in the catalog:

```php
app(\SuperAICore\Dispatcher::class)->dispatch([
    'prompt'  => $task,
    'backend' => 'anthropic_api',
    'model'   => 'claude-opus-4-8',   // or 'claude-opus-4-7' to pin
]);
```

### 30.2 Two Grok channels — API vs CLI (don't mix them up)

"Grok" is reachable two ways, and they are deliberately separate:

| | Provider type `grok` (API) | Engine `grok_cli` (CLI) |
|---|---|---|
| Backend | `superagent` → SDK `GrokProvider` | `grok_cli` (binary `grok`) |
| Endpoint | `https://api.x.ai/v1` | grok.com (Grok Build) |
| Auth | `XAI_API_KEY` / `GROK_API_KEY` | `grok login` (`~/.grok`) |
| Default model | `grok-4.3` (1M ctx) | `grok-build` |
| Billing | metered (usage) | subscription ($0 rows) |

```php
// (a) Metered xAI API — provider row, type=grok, routed via superagent:
$provider = \SuperAICore\Models\AiProvider::create([
    'backend' => 'superagent',
    'type'    => 'grok',
    'name'    => 'xAI Grok',
    'api_key' => env('XAI_API_KEY'),   // GROK_API_KEY also accepted
]);

// (b) Subscription CLI — no key; `grok login` owns auth:
app(\SuperAICore\Dispatcher::class)->dispatch([
    'prompt'  => $task,
    'backend' => 'grok_cli',
    'model'   => 'grok-build',
    'effort'  => 'high',   // low | medium | high | xhigh | max
]);
```

`api:status` probes the API channel (filtered to configured keys); the CLI
channel shows up in `cli:status` and the `/providers` engine cards.

### 30.3 Cursor Composer CLI onboarding

```bash
curl https://cursor.com/install -fsS | bash   # installs cursor-agent
cursor-agent login                             # browser OAuth → ~/.cursor
./vendor/bin/superaicore cli:status            # confirms "logged in"
```

Dispatch through it (subscription-billed; `--force` auto-approves tools so
headless runs don't block on per-tool confirmation):

```php
app(\SuperAICore\Dispatcher::class)->dispatch([
    'prompt'  => $task,
    'backend' => 'cursor_cli',
    'model'   => 'composer-2.5-fast',   // or 'composer-2.5', 'auto', etc.
    'cwd'     => base_path(),            // mapped to --workspace
]);
```

MCP servers sync to `.cursor/mcp.json` via
`McpManager::syncAllBackends()`. Headless runners without a browser export
`CURSOR_API_KEY` instead of `cursor-agent login`. Model picker is driven by
`CursorModelResolver` (with `liveCatalog()` re-probing `cursor-agent models`).

### 30.4 Grok Build CLI + effort control

```bash
curl -fsSL https://grok.com/install.sh | bash  # installs grok
grok login                                      # grok.com OAuth → ~/.grok
```

```php
app(\SuperAICore\Dispatcher::class)->dispatch([
    'prompt'           => $task,
    'backend'          => 'grok_cli',
    'model'            => 'grok-build',
    'effort'           => 'max',          // → --effort
    // 'reasoning_effort' => '...',       // → --reasoning-effort
]);
```

Scripted spawns use `--prompt-file` (no argv length limit); the backend
emits the standard envelope with parsed `usage.input_tokens` /
`output_tokens`. Grok has native sub-agents (`--agents` / `create-subagent`)
and manages MCP via `grok mcp add` (no host-writable config file).

### 30.5 Where they show up

Because `EngineCatalog`, `ProviderTypeRegistry`, and the per-engine model
resolvers feed everything, both CLIs auto-appear in: the `/providers` UI
(engine cards, builtin rows, add-provider dropdowns, version + login
badges), model pickers (`modelOptions('cursor')` / `modelOptions('grok')`),
`cli:status`, the cost dashboard (under "Subscription engines", $0 rows),
the Process Monitor (live rows + scan keywords), and `McpManager` sync.

---

## 31. kimi-cli + kimi-code dual-CLI support (1.0.2 / SDK 1.0.10)

Moonshot's new `@moonshot-ai/kimi-code` (TypeScript) replaces the legacy Python
`MoonshotAI/kimi-cli`. Both publish the same `kimi` binary but expose an
incompatible headless surface, so the `kimi_cli` backend now detects which is
installed and adapts. The pin also moves SDK `^1.0.9` → `^1.0.10`. Additive —
no schema changes, no config publish; the `kimi_cli` Dispatcher backend id is
unchanged.

### 31.1 Variant detection + override

`KimiCliBackend` resolves the dialect once per binary (cached) via a one-shot
`kimi --help` probe — the legacy CLI advertises a `--print` flag, kimi-code
does not. Pin it to skip probing during the transition:

```php
// config/super-ai-core.php — backends.kimi_cli.variant
'variant' => env('AI_CORE_KIMI_CLI_VARIANT', 'auto'),  // auto | kimi-code | kimi-cli
```

```bash
AI_CORE_KIMI_CLI_VARIANT=kimi-code   # force the new CLI (already upgraded)
AI_CORE_KIMI_CLI_VARIANT=kimi-cli    # force the legacy CLI (still on Python)
AI_CORE_KIMI_CLI_VARIANT=auto        # default: probe `kimi --help`
```

Dispatch is identical either way — the backend id never changes:

```php
app(\SuperAICore\Dispatcher::class)->dispatch([
    'prompt'  => $task,
    'backend' => 'kimi_cli',
    'model'   => 'kimi-k2-turbo',   // optional; --model on both dialects
]);
```

### 31.2 The flag matrix (why detection is needed)

| | legacy `kimi-cli` (`--print`) | new `kimi-code` (`--prompt`) |
|---|---|---|
| Headless trigger | `--print` (boolean, implies yolo) | `--prompt` (print mode) |
| Output format | `--output-format=stream-json` | `--output-format stream-json` |
| Step cap | `--max-steps-per-turn N` | — (config.toml) |
| Per-run MCP | `--mcp-config-file F` | — (config.toml) |
| Work dir | `-w <dir>` | — (process cwd) |
| Unknown options | tolerated | hard-rejected |
| assistant `content` | block array (`text` / `think`) | plain string |
| resume hint | stderr | `{"role":"meta",…}` NDJSON line |

The parser is tolerant of both `content` shapes and ignores the kimi-code
`role:meta` resume line, so it stays correct even if detection guesses wrong.
The legacy command sent to kimi-code would be rejected outright (unknown
`--print`), which is exactly why the backend adapts argv per dialect. kimi-code
has no per-run `--mcp-config-file` flag, so a passed `mcp_config_file` is
silently dropped (MCP is config.toml-driven there).

### 31.3 SDK 1.0.10 — transparent Kimi/OpenAI-compatible fixes

The pin to `^1.0.10` reaches the `superagent` backend with no SuperAICore code
change. The direct-HTTP `kimi` / `qwen` / `glm` / `deepseek` / `grok` /
`openrouter` / `openai` provider types now get:

- **Streaming usage accounting** — `stream_options.include_usage` is sent, so
  streamed responses carry a `usage` block again. Before, streamed calls through
  these types recorded $0 token/cost/cache on `ai_usage` rows and the
  `/providers` dashboard.
- **Strict tool-schema normalization** — local `$ref`/`$defs` are inlined and
  typeless enum-only properties get a `type`, so MCP / Skill / Agent tools
  survive Moonshot's validator.
- **`max_completion_tokens`** for Kimi reasoning models (no more empty answers
  when the reasoning channel eats the budget) + `reasoning_content` round-trip.
- **Per-model capability discovery** — `thinking` / `vision` / `tools` /
  `structured_output` flags read from the provider's `/models` response feed
  capability routing.
- **`SUPERAGENT_KIMI_SWARM_ENABLED`** (new, opt-in) — the speculative Kimi
  Agent-Swarm REST tool is gated off by default.

Design notes for the dual-CLI backend live in `docs/kimi-cli-backend.md` §8.

---

## 32. SmartFlow — cross-CLI dynamic workflows + superagent federation (1.0.5 / SDK 1.1.0)

SmartFlow is SuperAICore's port of Claude Code's built-in `Workflow` engine,
retargeted so the unit of routing is a **CLI/backend** rather than an API model.
It tracks the SuperAgent SDK's cross-*model* SmartFlow (SDK 1.1.0) but drives the
backends SuperAICore already manages, and it can **delegate** a sub-flow to the
SDK's engine for genuine cross-CLI → cross-model federation. Additive: the
Dispatcher, AgentSpawn, and the Squad/Team/Smart/Auto orchestrators are
untouched. Full reference: [docs/smartflow.md](smartflow.md).

### 32.1 The primitives

A flow body is `callable(Flow $flow): mixed` (or a YAML file compiled to one).
`$flow` exposes: `agent($prompt, $opts)` (one cross-CLI call → validated array
with `schema`, raw string, or `$flow->SKIP`), `call()` (deferred, for fan-out),
`parallel([...])` (barrier; deferred calls run concurrently via a process pool),
`pipeline($items, ...$stages)` (per-item / per-stage), `gate($name, $check,
$opts)` (acceptance with `fallback`/`relay`/`required`), `council($claim,
$lenses)` (perspective-diverse vote, each lens pinnable to a different CLI),
`budget`, and `log()`/`phase()`. `$opts` keys: `backend` (the CLI — `provider`
is an accepted alias), `model`, `role` (persona), `system`, `schema`,
`temperature`, `max_tokens`, `label`, `provider_config`.

```php
use SuperAICore\SmartFlow\{FlowEngine, FlowDefinition, FlowOptions};

$def = FlowDefinition::make('review', 'cross-CLI review', function ($flow) {
    $flow->phase('Summarize');
    $summary = $flow->agent("Summarize:\n{$flow->args['diff']}", ['backend' => 'claude_cli']);

    $flow->phase('Review');
    $reviews = $flow->parallel([
        $flow->call("Correctness:\n$summary", ['role' => 'reviewer', 'backend' => 'codex_cli']),
        $flow->call("Security:\n$summary",    ['role' => 'reviewer', 'backend' => 'gemini_cli']),
    ]);

    return $flow->agent("Decide:\n" . json_encode($flow->keep($reviews)), [
        'backend' => 'claude_cli',
        'schema'  => ['type' => 'object', 'required' => ['decision'],
            'properties' => ['decision' => ['type' => 'string', 'enum' => ['approve', 'request_changes']]]],
    ]);
});

$result = (new FlowEngine())->run($def, ['diff' => $diff]);   // ->value, ->costUsd(), ->ledger, ->runId
```

### 32.2 Structured output — the 3-layer safety net

CLIs return prose, so a requested `schema` is baked into the prompt and a valid
value recovered through three escalating layers — whole-reply JSON
(`native`/`submitted`) → fenced ```` ```json ```` block (`submitted`) →
regex-sniffed object/array (`extracted`) — validated by a dependency-free
`SchemaValidator`. If none validates, the call returns the `SKIP` sentinel
instead of crashing, so a fan-out can `$flow->keep(...)` bad replies out.

### 32.3 Resume, ledger, rehearsal

Every run appends a JSONL ledger under `~/.superaicore/flows/<runId>.jsonl`
(override: `SUPERAICORE_FLOW_DIR` or `super-ai-core.smartflow.ledger_dir`). Each
call gets a content-addressed signature from what you *declared*; `--resume
<runId>` replays the longest unchanged prefix at zero cost and runs live only
from the first changed call (gates occupy a ledger slot so post-gate calls stay
aligned). `--rehearse` / `--dry-run` run a flow end-to-end with **no CLI
invoked** — schema calls get deterministic schema-conforming stubs, cost is `$0`
— so flows are testable on a bare machine.

### 32.4 Federation — delegate a sub-flow to superagent

A SuperAICore flow can hand a sub-flow to superagent's own (cross-model)
SmartFlow. This is the intended layering: SuperAICore fans out across CLIs; the
`superagent` leg fans out across model providers. Two modes:

```php
// named — superagent runs one of its OWN flows; it self-dispatches across providers
$findings = $flow->delegate('research-trio', [
    'flow_args'        => ['topic' => $flow->args['goal']],
    'delegate_provider' => 'openai',     // steer superagent's model tier
]);

// spec — superagent runs a flow SuperAICore AUTHORED (provider-based, cross-model)
$brief = $flow->delegate('', ['spec' => [
    'name'  => 'mini-brief',
    'steps' => [
        ['name' => 'gather', 'role' => 'researcher', 'provider' => 'openai',    'prompt' => 'research {{args.q}}'],
        ['name' => 'write',  'role' => 'writer',     'provider' => 'anthropic', 'prompt' => "summarize:\n{{steps.gather.output}}"],
    ],
    'return' => 'write',
], 'flow_args' => ['q' => $flow->args['goal']]]);
```

A delegated call uses the same ledger / budget / resume / `parallel()` machinery,
so its cost federates into the parent budget and it rehearses with the parent.
The inline **spec uses the SDK's schema** (steps route across model `provider`s,
not CLIs) and is executed by superagent's engine. A named delegation requires the
flow to exist in the SDK registry (`superagent flow list`); a missing SDK or
unknown flow fails gracefully (empty / `SKIP`) without crashing the parent. Under
the hood: `SuperAICore\SmartFlow\Delegation` + `SuperAgentFlowBridge` (in-process
via `SuperAgent\SmartFlow\FlowEngine`).

### 32.5 YAML authoring

Static flows live in `resources/flows/*.yaml` (compiled by `YamlFlowLoader`).
Drop your own under `./flows`, `./.superaicore/flows`, or
`super-ai-core.smartflow.flows_dir`. Templating: `{{args.x}}`,
`{{steps.<name>.output}}`, `{{item}}`, dotted paths. Strategies: `solo`
(default), `parallel`, `pipeline`, `gate`, `delegate`.

```yaml
- name: research            # hand the research leg to superagent
  strategy: delegate
  delegate: research-trio   # named SDK flow (or `spec: {...}` to author inline)
  provider: "{{args.research_provider}}"
  flow_args: {topic: "{{args.goal}}"}
```

Built-in flows: `cross-cli-review`, `cross-cli-dev`, `cross-cli-council`,
`cross-cli-federated`. CLI: `superaicore flow list|show|plan|run` (and
`php artisan flow ...`).

### 32.6 When to reach for SmartFlow

Smart / Squad / Auto decompose a task heuristically and route subtasks;
AgentSpawn is the 3-phase spawn-plan protocol for CLIs without a native Agent
tool. Reach for **SmartFlow** when you want *explicitly authored* multi-step
control flow (fan-out, pipelines, gates, councils), per-step CLI routing,
structured output, budgets, rehearsal, resume, and superagent federation — the
same shape as Claude Code's `Workflow`, made cross-CLI.

---

## 33. CLI skill bridge — `superaicore:sync-cli` + the `SkillLibrary` contract (1.0.6)

SuperAICore already bridges **MCP** into every CLI backend's native config
(`McpManager::syncAllBackends()`, §13). 1.0.6 gives **skills + agents** the same
treatment with one generic bridge, so a host stops hand-rolling a separate sync
per CLI (a Codex wrapper installer, a Gemini custom-command sync, a Kimi
translator, …).

The split of responsibility is the whole point:

- **SuperAICore knows WHERE / HOW / WHEN.** Where each CLI keeps its skills, how
  to install a wrapper there *safely* (never through a symlink), and when to
  re-sync (fingerprint drift only).
- **The host knows WHAT.** It implements `SuperAICore\Contracts\SkillLibrary` and
  binds it. SuperAICore carries zero host assumptions — when nothing is bound the
  bridge is a silent no-op, so the package stays host-agnostic.

### The contract

```php
namespace SuperAICore\Contracts;

interface SkillLibrary
{
    /** @return array<int,array{name:string,description:string}> */
    public function skills(): array;

    /** @return array<int,array{name:string,description?:string}> */
    public function agents(): array;

    /** Full SKILL.md for a backend's NATIVE skill dir (codex/gemini/…). */
    public function skillWrapper(string $backend, string $skillName): string;

    /** Markdown digest for backends with no skill dir (copilot/kimi/kiro). */
    public function instructionsDigest(string $backend): string;

    /** Stable hash of the whole library; drives the lazy re-sync. */
    public function fingerprint(): string;
}
```

Bind it in a service provider:

```php
$this->app->singleton(
    \SuperAICore\Contracts\SkillLibrary::class,
    \App\Services\SuperTeamSkillLibrary::class,
);
```

### Thin wrappers keep the source authoritative

The recommended `skillWrapper()` body is a **thin** SKILL.md that shells out to
the host's loader instead of duplicating the real skill body — so edits to the
canonical `.claude/skills/<name>/SKILL.md` need no re-sync, and the wrapper can
never drift from (or clobber) the source:

```php
public function skillWrapper(string $backend, string $skill): string
{
    return <<<MD
    ---
    name: super-team-{$skill}
    description: Runtime wrapper for `{$skill}`; loads the latest definition from source.
    ---
    Load the canonical definition (do not duplicate it here):
    ```bash
    php /path/to/host/artisan super-team:skill {$skill} --format=markdown
    ```
    MD;
}
```

### Three install shapes

`CliSkillBridge::BACKENDS` maps each backend to one mode — adding a CLI is a
one-line change:

| Mode | Backends | What lands | $HOME path |
|------|----------|-----------|-----------|
| `native_dir`  | codex, gemini, grok, cursor, qwen | one prefixed wrapper dir per skill (`super-team-<name>/SKILL.md`) | `.codex/skills`, `.gemini/skills`, `.grok/skills`, `.cursor/skills-cursor`, `.qwen/skills` |
| `instructions`| copilot, kimi, kiro | one digest file (how to load any skill on demand + the list) | `.copilot/super-team-skills.md`, `.kimi/…`, `.kiro/…` |
| `source`      | claude | nothing — reads the host's `.claude/skills` directly | — |
| `none`        | superagent | nothing | — |

### Symlink-safe writes (the clobber fix)

The bridge **never writes through a symlink**. Before writing any wrapper dir,
`SKILL.md`, digest, or manifest it `is_link()`-checks the target and unlinks a
stale link first (leaving the link's *target* intact). This closes the
write-through-symlink hole where a leftover
`~/.codex/skills/super-team-x -> …/.claude/skills/x` link let a wrapper write
overwrite the real source skill body.

### Lazy on-dispatch sync

Each sync stamps `fingerprint()` into a per-backend manifest
(`.superteam-skill-sync.json`) alongside the list of wrappers it installed.
`TaskRunner` calls the bridge before every CLI dispatch:

```php
// TaskRunner::ensureCliSkillsSynced() — normalizes codex_cli → codex, best-effort
(new \SuperAICore\Services\CliSkillBridge())->ensureSynced($engine);
```

`ensureSynced()` is cheap: `needsSync()` compares one hash and returns early when
the library hasn't changed, so the per-dispatch cost is a single compare. Pruning
is **manifest-scoped** — only wrappers this bridge installed before and no longer
wants are removed; the user's own skills are never touched. Any failure is
swallowed so a sync hiccup can never block a dispatch.

### `superaicore:sync-cli` — the manual / cron full refresh

```bash
php artisan superaicore:sync-cli                       # skills + MCP → every installed CLI
php artisan superaicore:sync-cli --skills-only         # skip the MCP step
php artisan superaicore:sync-cli --mcp-only            # only MCP (= mcp:sync-backends)
php artisan superaicore:sync-cli --backends=codex,gemini
php artisan superaicore:sync-cli --project-root=/path  # override .mcp.json discovery
```

Skills go through `CliSkillBridge`; MCP reuses `McpManager::syncAllBackends()`.
The per-dispatch `TaskRunner` hook keeps backends fresh during normal use — reach
for this command for a one-shot refresh from a git hook, cron, or after editing
the library.

### Programmatic use

```php
$bridge = new \SuperAICore\Services\CliSkillBridge();   // resolves the bound library
if ($bridge->active()) {
    $report = $bridge->syncAll(['codex', 'gemini']);    // [['backend'=>…,'installed'=>189,'pruned'=>0,'path'=>…], …]
}
```

---

## See also

- [docs/smartflow.md](smartflow.md) — SmartFlow cross-CLI workflows + superagent federation (1.0.5)
- [docs/idempotency.md](idempotency.md) — 60s dedup window, repository-level contract
- [docs/streaming-backends.md](streaming-backends.md) — per-CLI stream formats
- [docs/task-runner-quickstart.md](task-runner-quickstart.md) — `TaskRunner` option reference
- [docs/spawn-plan-protocol.md](spawn-plan-protocol.md) — codex/gemini agent emulation
- [docs/mcp-sync.md](mcp-sync.md) — catalog-driven MCP sync
- [docs/api-stability.md](api-stability.md) — SemVer contract
