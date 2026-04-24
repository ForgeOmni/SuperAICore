# 高级用法

[English](advanced-usage.md) · [简体中文](advanced-usage.zh-CN.md) · [Français](advanced-usage.fr.md)

SuperAICore 中塞不进 README 的进阶用法。本指南专注于 **superagent** 后端路径 —— 六个 CLI 后端另有独立文档（[streaming-backends](streaming-backends.md)、[spawn-plan-protocol](spawn-plan-protocol.md)、[task-runner-quickstart](task-runner-quickstart.md)）。

示例默认针对 0.7.0+。更早引入的特性会标注 `(自 X.Y.Z 起)`。

## 目录

1. [幂等 key 通过 SDK 往返](#1-幂等-key-通过-sdk-往返)
2. [W3C trace context 透传](#2-w3c-trace-context-透传)
3. [分类的 provider 异常](#3-分类的-provider-异常)
4. [OpenAI Responses API](#4-openai-responses-api)
5. [通过 OAuth 走 ChatGPT 订阅](#5-通过-oauth-走-chatgpt-订阅)
6. [Azure OpenAI 自动识别](#6-azure-openai-自动识别)
7. [LM Studio —— 本地 OpenAI-compat 服务](#7-lm-studio--本地-openai-compat-服务)
8. [按 provider 类型声明 HTTP 头](#8-按-provider-类型声明-http-头)
9. [SDK feature dispatcher —— `extra_body` / `features` / `loop_detection`](#9-sdk-feature-dispatcher)
10. [Prompt-cache keys（Kimi）](#10-prompt-cache-keyskimi)
11. [扩展 provider type 注册表](#11-扩展-provider-type-注册表)
12. [通过 `ScriptedSpawnBackend` 接管宿主 CLI spawn](#12-通过-scriptedspawnbackend-接管宿主-cli-spawn)

---

## 1. 幂等 key 通过 SDK 往返

*自 0.6.6（去重窗口）/ 0.7.0（SDK 往返）起。*

SuperAICore 从 0.6.6 起就在 `ai_usage_logs` 上有 60 秒的 `idempotency_key` 去重窗口 —— 在 `Dispatcher::dispatch()` 选项传 `idempotency_key`，重复调用会合并成一行。0.7.0 闭环到 SDK:key 现在随 `AgentResult::$idempotencyKey` 一起流转（SDK 0.9.1），写入时读的是 SDK 实际看到的 key，而不是重新算一遍。

```php
use SuperAICore\Services\Dispatcher;

$dispatcher = app(Dispatcher::class);

// 显式 key —— 调用方最清楚。
$r1 = $dispatcher->dispatch([
    'prompt'          => $prompt,
    'backend'         => 'superagent',
    'provider_config' => ['type' => 'anthropic', 'api_key' => env('ANTHROPIC_API_KEY')],
    'idempotency_key' => "checkout:{$order->id}:line:{$line->id}",
]);

// 60 秒内同样的调用 → 同一 ai_usage_logs 行 id。
$r2 = $dispatcher->dispatch([
    'prompt'          => $prompt,
    'backend'         => 'superagent',
    'provider_config' => ['type' => 'anthropic', 'api_key' => env('ANTHROPIC_API_KEY')],
    'idempotency_key' => "checkout:{$order->id}:line:{$line->id}",
]);

assert($r1['usage_log_id'] === $r2['usage_log_id']);
assert($r1['idempotency_key'] === 'checkout:…');  // 从 envelope 回显
```

基于 `external_label` 的自动派生依然工作 —— 没传 `idempotency_key` 但传了 `external_label` 时，Dispatcher 用 `"{backend}:{external_label}"`。传 `idempotency_key => false` 完全关掉自动去重（罕见 —— 每次调用都真的不同）。

**为什么闭环很重要:** Dispatcher 跑在 Web worker、UsageRecorder 写入跑在 queue worker（Laravel Horizon 常见）的宿主，现在不用再在 job payload 里夹带 key —— 它随 envelope 走。完整契约见 [docs/idempotency.md](idempotency.md)。

---

## 2. W3C trace context 透传

*自 0.7.0 起。*

把入站 `traceparent` 头转发到每次 LLM 调用，SDK 把它投影到 OpenAI Responses API 的 `client_metadata`。结果:OpenAI 侧日志 + 宿主分布式追踪自动对上，无需 wrapper 层。

```php
// app/Http/Middleware/AttachTraceContext.php
public function handle($request, Closure $next)
{
    // 通常这个中间件已经在控制器之前运行，
    // 贯穿请求生命周期传递 traceparent / tracestate。
    // SuperAICore 调用时从 Request 读 header 即可。
    return $next($request);
}

// 任何 request-scoped 调用处:
$result = app(Dispatcher::class)->dispatch([
    'prompt'          => $prompt,
    'backend'         => 'superagent',
    'provider_config' => ['type' => 'openai-responses', 'api_key' => env('OPENAI_API_KEY')],
    'traceparent'     => $request->header('traceparent'),  // null 时安全跳过
    'tracestate'      => $request->header('tracestate'),
]);
```

非 `openai-responses` provider 静默忽略这个头 —— 从共用 dispatcher 助手里无条件传是安全的。不符合 W3C `00-<32hex>-<16hex>-<2hex>` 格式的 traceparent 字符串会被静默丢弃，不抛错。

如果你已经在别处构造了 `SuperAgent\Support\TraceContext`（例如想起一条根 trace 的后台任务），直接传进去:

```php
use SuperAgent\Support\TraceContext;

$trace = TraceContext::fresh();   // 随机、已采样
$dispatcher->dispatch([
    ...,
    'trace_context' => $trace,
]);
```

---

## 3. 分类的 provider 异常

*自 0.7.0（宿主侧）/ SDK 0.9.1（分类）起。*

0.7.0 之前所有 SuperAgent 后端的失败都落到一个日志桶里:"SuperAgentBackend error: <message>"。现在 SDK 抛六个子类（`ContextWindowExceeded` / `QuotaExceeded` / `UsageNotIncluded` / `CyberPolicy` / `ServerOverloaded` / `InvalidPrompt`），`SuperAgentBackend::generate()` 分别捕获，各自以稳定的 `error_class` tag + `retryable` 标记记日志。

运维 telemetry 里读这个分类只需要一个日志 drain 索引 `error_class` 字段:

```
[warning] SuperAgentBackend error [context_window_exceeded]: context too long
    error_class=context_window_exceeded retryable=false
```

### 失败时更智能的路由

默认契约在失败时返回 `null`，调用方看到"没后端应答"继续 fall through。想根据具体失败模式反应（上下文爆了就压缩重试、配额用光切别的 provider、过载就退避），继承 `SuperAgentBackend`，override `logProviderError` seam 把分类塞到 envelope:

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

在 `AppServiceProvider` 里绑:

```php
$this->app->extend(\SuperAICore\Services\BackendRegistry::class, function ($registry) {
    $registry->register(new RoutingSuperAgentBackend(logger()));
    return $registry;
});
```

然后在自己的 dispatcher wrapper 里:

```php
$result = $dispatcher->dispatch($opts);
if ($result === null) {
    $backend = app(\SuperAICore\Backends\SuperAgentBackend::class);
    if ($backend->lastErrorClass === 'context_window_exceeded') {
        // 压缩历史重试
    } elseif ($backend->lastErrorClass === 'quota_exceeded') {
        // 切到另一行 provider 重试
    }
}
```

---

## 4. OpenAI Responses API

*自 0.7.0 起。*

`openai-responses` provider 类型通过 SDK 的 `OpenAIResponsesProvider` 走 `/v1/responses`。相较 Chat Completions 的几个优点:

- **通过 `previous_response_id` 实现有状态的多轮对话。** SDK 把 server-assigned response id 串到后续 turn，不用重发会话上下文。
- **细粒度 `reasoning.effort` 和 `text.verbosity`。** SDK 把 SuperAICore 的 `features.thinking.*` 翻译成 Responses API shape。
- **wire 层的 `prompt_cache_key`。** 同一个 `features.prompt_cache_key.session_id` 旋钮能用。

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

### 不重发 context 的多轮对话

SDK 默认服务器端存响应（`store: true`）。下一轮在 `extra_body` 传上一轮的 response id:

```php
$turn2 = $dispatcher->dispatch([
    'prompt'  => '那 X 呢?',
    'backend' => 'superagent',
    'provider_config' => ['type' => 'openai-responses', 'api_key' => env('OPENAI_API_KEY')],
    'extra_body' => [
        'previous_response_id' => $turn1['response_id'] ?? null,  // SDK 成功时 echo 这个
    ],
]);
```

无状态 turn（合规原因、秘密 prompt）第一次调用传 `extra_body.store = false`。

---

## 5. 通过 OAuth 走 ChatGPT 订阅

*自 0.7.0 起 —— 基于 SDK 0.9.1 的 ChatGPT backend 识别。*

交了 ChatGPT Plus / Pro / Business 月费?订阅配额可以通过 `chatgpt.com/backend-api/codex` 用于类 API 调用。SuperAICore 的 `openai-responses` 类型在 provider 行 `extra_config` 存的是 `access_token`（而不是顶层 `api_key`）时自动路由过去。

**宿主侧 OAuth 流程。** OAuth 对接你自己做 —— Anthropic 的 `codex` CLI 是这样做的，开源的 `codex` 客户端也是。拿到新鲜的 access_token 后:

```php
use SuperAICore\Models\AiProvider;

$provider = AiProvider::create([
    'scope'        => 'global',
    'backend'      => AiProvider::BACKEND_SUPERAGENT,
    'type'         => AiProvider::TYPE_OPENAI_RESPONSES,
    'name'         => 'ChatGPT Plus (OAuth)',
    'api_key'      => null,   // 留空
    'extra_config' => [
        'access_token'   => $tokens['access_token'],
        'refresh_token'  => $tokens['refresh_token'],
        'expires_at'     => $tokens['expires_at']->toIso8601String(),
    ],
    'is_active'    => true,
]);
```

SDK 把 `base_url` 切到 `https://chatgpt.com/backend-api/codex`（去掉 `/v1/` 前缀），以 `Authorization: Bearer …` 发送 access_token，请求计入订阅配额。速率限制和模型可用性都按 ChatGPT 套餐算，不按 API tier。

刷 token 要你自己搞个任务做 —— SDK 不会帮 `openai-responses` provider 刷 `access_token`（那是宿主的事）。

---

## 6. Azure OpenAI 自动识别

*自 0.7.0 起。*

`base_url` 指向你的 Azure 部署，SDK 通过六个 URL 标记自动识别（`openai.azure.`、`cognitiveservices.azure.`、`aoai.azure.`、`azure-api.`、`azurefd.`、`windows.net/openai`）。无需配置开关。

```php
AiProvider::create([
    'scope'        => 'global',
    'backend'      => AiProvider::BACKEND_SUPERAGENT,
    'type'         => AiProvider::TYPE_OPENAI_RESPONSES,
    'name'         => 'Azure OpenAI — eastus2',
    'api_key'      => env('AZURE_OPENAI_KEY'),
    'base_url'     => 'https://mycompany.openai.azure.com/openai/deployments/gpt-5',
    'extra_config' => [
        // 可选 —— 当部署滞后时覆盖默认 api-version:
        'azure_api_version' => '2024-10-21',
    ],
]);
```

Azure 模式的行为:

- 请求变成 `/openai/responses?api-version=2025-04-01-preview`（默认，可覆盖）。
- `Authorization: Bearer` 和 `api-key: <key>` 两个头都发，Azure 的任一 auth 路径都能通。
- 模型 id 用部署名，不是 OpenAI 的模型 id —— Azure 暴露自己的。

---

## 7. LM Studio —— 本地 OpenAI-compat 服务

*自 0.7.0 起。*

LM Studio 跑本地 OpenAI-compat 服务（通常在 `http://localhost:1234/v1`）。`lmstudio` provider 类型接过去，零 auth 仪式 —— SDK 自动合成占位 `Authorization` 头，Guzzle 不会抱怨。

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

使用场景:

- **完全离线 / on-prem** —— 没云端 key、没出口流量。
- **提示词工程** —— 在烧 API 开销之前先本地迭代。
- **CI 卡测试** —— 容器里起 LM Studio，`base_url` 指过去，跑真实模型输出的 Dispatcher 测试套件。

LAN 内另一台主机上的 LM Studio 也一样 —— `base_url` 指它的 IP 即可，其他不用改。

---

## 8. 按 provider 类型声明 HTTP 头

*自 0.7.0 起。*

`ProviderTypeDescriptor` 新增的两个字段让宿主按 provider 类型给每次 SDK 调用注入 HTTP 头:

- `http_headers` —— 字面量 header。适合静态识别:`X-App: my-host`。
- `env_http_headers` —— header 名 → env 变量名映射。SDK 请求时读 env；env 没设时静默跳过该 header。适合 project-scoped header:`OpenAI-Project: <来自 env>`。

```php
// config/super-ai-core.php
return [
    // …
    'provider_types' => [
        // Project-scoped OpenAI key 每次调用都带 OpenAI-Project header。
        \SuperAICore\Models\AiProvider::TYPE_OPENAI => [
            'env_http_headers' => ['OpenAI-Project' => 'OPENAI_PROJECT'],
        ],

        // 同样适用于 Responses API 类型。再给每次调用打上 LangSmith 标签。
        \SuperAICore\Models\AiProvider::TYPE_OPENAI_RESPONSES => [
            'http_headers'     => ['X-Service' => 'my-host-app'],
            'env_http_headers' => [
                'OpenAI-Project'     => 'OPENAI_PROJECT',
                'LangSmith-Project'  => 'LANGSMITH_PROJECT',
            ],
        ],

        // OpenRouter 识别（rate-limit 分层会用）。
        'openrouter' => [
            'http_headers' => [
                'HTTP-Referer' => 'https://myapp.example.com',
                'X-Title'      => 'My Host App',
            ],
        ],
    ],
];
```

header 在 SDK 的 `ChatCompletionsProvider` 里应用 —— 每次请求都带（chat + responses + model 列表 + 健康探测），telemetry 里一致。

---

## 9. SDK feature dispatcher —— `extra_body` / `features` / `loop_detection`

*自 0.6.9 起。*

`Dispatcher::dispatch()` 在 `backend=superagent` 下的三个透传键:

### `extra_body` —— 厂商私有 wire 逃生舱

深合并到每个 `ChatCompletionsProvider` 请求 body 顶层。用于 SDK 还没封装的字段:

```php
$dispatcher->dispatch([
    ...,
    'backend' => 'superagent',
    'extra_body' => [
        'response_format' => ['type' => 'json_object'],  // OpenAI JSON 模式
        'seed'            => 42,                         // 近乎确定的输出
    ],
]);
```

### `features` —— 走 capability 路由的 SDK 特性

走 SDK `FeatureDispatcher`。不支持的 provider 静默跳过 —— 无条件传是安全的。

```php
$dispatcher->dispatch([
    ...,
    'features' => [
        // CoT，在每个 provider 上都有优雅 fallback
        'thinking'                  => ['effort' => 'high', 'budget' => 8000],
        // Kimi 会话级 prompt cache（其他 provider 静默跳过）
        'prompt_cache_key'          => ['session_id' => $conversationId],
        // Qwen Anthropic 风格的 cache 标记（仅 DashScope native shape）
        'dashscope_cache_control'   => true,
    ],
]);
```

### `loop_detection` —— 捕捉失控 agent

把流处理 handler 包进 `LoopDetectionHarness`。`true` 用 SDK 默认；数组覆盖阈值:

```php
$dispatcher->dispatch([
    ...,
    'loop_detection' => [
        'tool_loop_threshold'     => 7,   // 默认 5 次同工具+参数
        'stagnation_threshold'    => 10,  // 默认 8 次同名
        'file_read_loop_recent'   => 20,
        'file_read_loop_triggered' => 12,
        'content_loop_window'     => 100,
        'content_loop_repeats'    => 15,
        'thought_loop_repeats'    => 4,
    ],
]);
```

违规事件走 SDK wire event —— AICore envelope 对不 opt-in 的调用方保持字节一致。

---

## 10. Prompt-cache keys（Kimi）

*自 0.6.9 起。*

Kimi 支持调用方提供 session id 的会话级 prompt cache（和 Anthropic 的 per-block 标记不同）。稳定的 session id 让 Kimi 在同一会话的多轮之间复用 prompt-prefix cache，多轮场景下 input token 成本大幅下降。

两种等价写法:

```php
// shorthand（单 session 场景首选）
$dispatcher->dispatch([
    ...,
    'backend'          => 'superagent',
    'provider_config'  => ['type' => 'openai-compatible', 'api_key' => env('KIMI_API_KEY'),
                           'base_url' => 'https://api.moonshot.ai/v1', 'provider' => 'kimi'],
    'prompt_cache_key' => $sessionId,
]);

// 显式（和别的 `features.*` 对称）
$dispatcher->dispatch([
    ...,
    'features' => [
        'prompt_cache_key' => ['session_id' => $sessionId],
    ],
]);
```

非 Kimi provider 静默跳过这个特性 —— 从共用 dispatcher 里传安全。

---

## 11. 扩展 provider type 注册表

*自 0.6.2 起。*

宿主通过 `super-ai-core.provider_types` 无需 fork 就能加全新 provider type。例如注册 xAI 的 Grok API:

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
            'sdk_provider'     => 'xai',                    // （0.7.0+）SDK 注册键
            'http_headers'     => ['X-App' => 'my-host'],   // （0.7.0+）可选
            'env_http_headers' => ['X-App-Version' => 'APP_VERSION'],
        ],
    ],
];
```

注册表可从 `app(ProviderTypeRegistry::class)` 取。`/providers` UI、`ProviderEnvBuilder`、`AiProvider::requiresApiKey()`、以及每个在意 provider type 的 backend 都会自动看到新条目。

完整 descriptor shape 见 `src/Support/ProviderTypeDescriptor.php`。

---

## 12. 通过 `ScriptedSpawnBackend` 接管宿主 CLI spawn

*自 0.7.1 起。*

对接 AICore 的宿主（SuperTeam、SuperPilot、shopify-autopilot 等）过去在 task spawn 路径上维护着一份 `match ($backend) { 'claude' => buildClaudeProcess(…), 'codex' => buildCodexProcess(…), 'gemini' => buildGeminiProcess(…) }`，one-shot chat 路径还要再抄一份。每加一个 CLI 引擎（kiro、copilot、kimi、以及未来的新引擎）都要求宿主打补丁。0.7.1 引入 `Contracts\ScriptedSpawnBackend` 契约 —— 和 `StreamingBackend` 并列，把两处 switch 压缩成一次多态调用。同一版本里六个 CLI 后端（`Claude` / `Codex` / `Gemini` / `Copilot` / `Kiro` / `Kimi`）全部实现之。

### 改造前（per-backend match，宿主 runner 里约 150 行）

```php
// 宿主 0.7.1 之前的 task-runner 胶水
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

每个 `buildXxxProcess()` 自己处理 argv 组装、`--output-format stream-json` 等 flag、MCP config 注入、env scrub（Claude 的 5 个 `CLAUDE_CODE_*` 标记、Codex 的 `--config` 透传）、capability transform（Gemini 工具名重写）、wrapper 脚本管道、cwd。

### 改造后（单次多态调用）

```php
use SuperAICore\Services\BackendRegistry;

$backend = app(BackendRegistry::class)->forEngine($engineKey);  // 可空 —— config 里引擎被关时返回 null
if ($backend === null) {
    throw new \RuntimeException("engine {$engineKey} has no scripted-spawn backend registered");
}

$process = $backend->prepareScriptedProcess([
    'prompt_file'             => $promptFile,
    'log_file'                => $logFile,
    'project_root'            => $projectRoot,
    'model'                   => $model,
    'env'                     => $env,          // 宿主构造 —— 读 IntegrationConfig
    'disable_mcp'             => $disableMcp,   // 主要 Claude 在用
    'codex_extra_config_args' => $codexArgs,    // 主要 Codex 在用
    'timeout'                 => 7200,
    'idle_timeout'            => 1800,
]);
$process->start();
```

`BackendRegistry::forEngine($engineKey)` 按引擎 `dispatcher_backends` 顺序（CLI 天然在前，例如 `claude → ['claude_cli', 'anthropic_api']`）遍历，返回第一个实现 `ScriptedSpawnBackend` 的后端。引擎没有注册 CLI 后端时返回 `null` —— 要么被 `AI_CORE_CLAUDE_CLI_ENABLED=false` 关掉，要么是不实现 scripted spawn 的 superagent-only 引擎。

### One-shot chat 兄弟方法 —— `streamChat()`

阻塞式一次性 chat（可显示文本通过 `onChunk` 出来，退出时返回累积响应）。argv、prompt 走 stdin 还是 argv、输出解析、ANSI 去色（Kiro / Copilot 输出带 ANSI，不去色会直接漏进宿主 UI）都由 backend 自己负责:

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
        'timeout'       => 0,            // 0 = 不设硬上限；idle_timeout 仍生效
        'idle_timeout'  => 300,
        'allowed_tools' => ['Read', 'Bash'],  // 仅 Claude 用；其它 CLI 忽略
    ]
);
```

### 实现新 backend 时的 wrapper-script 助手

要给新 CLI 引擎实现 `ScriptedSpawnBackend`，`Backends\Concerns\BuildsScriptedProcess` trait 提供共享的底座:

- `buildWrappedProcess(…)` —— 生成 sh 或 .bat wrapper 脚本,把 `prompt_file` 通过 stdin 喂进去、stdout+stderr tee 到 `log_file`、应用 cwd + env,返回配置好的 `Symfony\Component\Process\Process`。
- `applyCapabilityTransform()` —— 通过 `BackendCapabilities::transformPrompt()` 在 spawn 前原地重写 prompt file（后端需要工具名重写或 preamble 注入时用）。
- `escapeFlags([…])` —— 在 argv 列表上批量套 `escapeshellarg`。

### CLI 二进制定位

`Support\CliBinaryLocator` 在 service provider 注册为单例。它统一处理 CLI 二进制在 macOS / Linux / Windows 常见安装目录里的探测:

- `~/.npm-global/bin`（npm global prefix）
- `/opt/homebrew/bin` 和 `/usr/local/bin`（arm64 / x86_64 Homebrew）
- `~/.nvm/versions/node/<v>/bin`（每个已装的 nvm 版本）
- Windows `%APPDATA%/npm`

二进制名取自 `EngineCatalog->cliBinary` —— 无需 `match`。宿主若要复用同样的探测给自己管的 CLI 路径,注入这个 locator 即可:

```php
$locator = app(\SuperAICore\Support\CliBinaryLocator::class);
$claudePath = $locator->locate('claude');   // 绝对路径或 null
```

### Claude env-scrub 列表（仍自组 `claude` 进程的宿主）

`ClaudeCliBackend::CLAUDE_SESSION_ENV_MARKERS` 以公开常量列出 5 个 env 标记（`CLAUDECODE`、`CLAUDE_CODE_ENTRYPOINT`、`CLAUDE_CODE_SSE_PORT`、`CLAUDE_CODE_EXECPATH`、`CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS`）—— 这些必须从子进程 env 里 scrub 掉,否则 Claude 的父会话递归守卫会直接拒绝启动。仍然自己组装进程的宿主,直接读这个常量就好,不用再自己推:

```php
use SuperAICore\Backends\ClaudeCliBackend;

$env = array_diff_key($parentEnv, array_flip(ClaudeCliBackend::CLAUDE_SESSION_ENV_MARKERS));
```

### 长期视角

接入 `ScriptedSpawnBackend` 之后,任何新 CLI 引擎落到上游后都会自动出现在宿主的每条 runner 路径上 —— 宿主无需打补丁,不用加 `match` 分支。这是契约存在的意义:0.7.1 之后的每个新引擎（Moonshot Kimi、未来的阿里 Qwen、未来的 Mistral `le-chat` 等）只要 SuperAICore 注册 backend,宿主代码路径里马上可见。完整背景见 [docs/host-spawn-uplift-roadmap.md](host-spawn-uplift-roadmap.md) —— 它替掉了宿主里 700 行 per-backend 胶水、分阶段迁移计划、以及 pre-soak caveat。

---

## 另见

- [docs/idempotency.md](idempotency.md) —— 60 秒去重窗口、repository 层契约
- [docs/streaming-backends.md](streaming-backends.md) —— 各 CLI 流格式
- [docs/task-runner-quickstart.md](task-runner-quickstart.md) —— `TaskRunner` 选项参考
- [docs/spawn-plan-protocol.md](spawn-plan-protocol.md) —— codex/gemini agent 模拟
- [docs/mcp-sync.md](mcp-sync.md) —— catalog 驱动的 MCP sync
- [docs/api-stability.md](api-stability.md) —— SemVer 契约
