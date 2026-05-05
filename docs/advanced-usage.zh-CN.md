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
13. [可移植的 `.mcp.json` 写入](#13-可移植的-mcpjson-写入)
14. [SuperAgent host-config adapter —— `createForHost`](#14-superagent-host-config-adapter--createforhost)
15. [对话中途切换 provider（`Agent::switchProvider`）](#15-对话中途切换-provideragentswitchprovider)
16. [Skill engine —— 遥测、排序、FIX 模式演化](#16-skill-engine--遥测排序fix-模式演化)
17. [SemanticSkillReranker 改用 `EmbeddingProvider` SPI（0.9.0）](#17-semanticskillreranker-改用-embeddingprovider-spi090)
18. [`agent_grep` + `browser` 工具开关（0.9.0）](#18-agent_grep--browser-工具开关090)
19. [浏览器截图闭环（0.9.0）](#19-浏览器截图闭环090)
20. [`usage_source` 切分 —— `user` 与 `ambient`（0.9.0）](#20-usage_source-切分--user-与-ambient090)
21. [跨 harness 会话恢复（0.9.0）](#21-跨-harness-会话恢复090)
22. [持久化 goal store（0.9.1）](#22-持久化-goal-store091)
23. [三档审批闸门（0.9.1）](#23-三档审批闸门091)
24. [Workspace plugin manifest（0.9.1）](#24-workspace-plugin-manifest091)
25. [无头 `/v1/usage` JSON 端点（0.9.1）](#25-无头-v1usage-json-端点091)
26. [`cache_hit_rate` 聚合（0.9.1）](#26-cache_hit_rate-聚合091)

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

## 13. 可移植的 `.mcp.json` 写入

*自 0.8.1 起。*

只要你**手工**编辑 `.mcp.json` 时使用裸命令名（`node`、`uvx` 等）和 `${SUPERTEAM_ROOT}/<rel>` 占位路径，文件本来就是可移植的。但只要走 UI 触发的写入路径，`McpManager::install*()` 都会通过 `which()` / `PHP_BINARY` 解出绝对路径，再把项目相对路径拼成绝对值才落盘 —— 一旦用户点 "Install" 或 "Install All"，文件马上被 `C:\Program Files\nodejs\node.exe`、`/Users/jane/projects/foo/.mcp-servers/bar/dist/index.js`、venv-bin 路径等再次污染。这个文件被同步进同事的 checkout、挂载进容器，或者复制到不同的 `${HOME}` 时立即报废。

0.8.1 引入一个由单一配置项驱动的 **可移植模式**（opt-in）:

```dotenv
# .env —— 任意你的 MCP runtime 会导出的环境变量名都可以
AI_CORE_MCP_PORTABLE_ROOT_VAR=SUPERTEAM_ROOT
```

设置之后（默认仍为 `null`，意味着 legacy "处处绝对路径" 的行为），所有 writer 同时翻转两件事:

1. **裸命令** —— `which('node')` / `PHP_BINARY` / `which('uvx')` 等被替换成 `node` / `php` / `uvx`。CLI 引擎在 spawn 时按各自 PATH 决定真正运行哪个二进制，不绑定具体机器路径。
2. **路径占位符** —— `projectRoot()` 下的所有绝对路径被改写为 `${SUPERTEAM_ROOT}/<rel>`。树外的路径（`/usr/share/...`、`/var/lib/...` 等）保持绝对值。宿主的 MCP runtime 在 spawn 时展开占位符。

### 让 MCP runtime 展开 `${SUPERTEAM_ROOT}`

大多数 MCP runtime（Claude Code、Codex、Gemini 等）从 `.claude/settings.local.json` 或等价位置读取 project-scope env，再注入到 spawn 出来的 MCP server 进程里。把 `SUPERTEAM_ROOT` 接到项目根:

```jsonc
// .claude/settings.local.json
{
  "env": {
    "SUPERTEAM_ROOT": "${PWD}"
  }
}
```

具体怎么填取决于宿主怎么跑 —— 用 `php artisan serve` 起的 Laravel 应用，`${PWD}` 就够了。容器部署时在容器 env 文件里设 `SUPERTEAM_ROOT=/srv/app`（或你容器内的项目根）。从不同 cwd 启动的队列 worker 要在 systemd unit / supervisord 配置里 export。

### writer 输出对比 —— before / after

```jsonc
// Before —— legacy 绝对路径
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
// After —— 启用 AI_CORE_MCP_PORTABLE_ROOT_VAR=SUPERTEAM_ROOT
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

### 出口规则 —— 占位符在 per-machine 目标处展开

project-scope 的 `.mcp.json` 保留占位符以维持可移植性。但有三类目标**无法**保持可移植:

- **user-scope backend 配置** —— `~/.codex/config.toml`（TOML 完全不展开 `${VAR}`）、`~/.gemini/settings.json`、`~/.claude/settings.json`、`~/.copilot/...`、`~/.kiro/...`、`~/.kimi/...` 本质上都是 per-machine。
- **Codex `exec -c` 运行时 flag** —— 作为命令行参数传给 `codex exec`，不会做 env 展开。
- **在 `.mcp.json` 之上合成 MCP 条目的辅助方法** —— `superfeedMcpConfig` / `codexOcrMcpConfig` / `codexPdfExtractMcpConfig` 产出的 spec 会被 `syncAllBackends()` 和 `codexMcpConfigArgs()` 消费。

针对这些场景，`codexMcpServers()` 在返回前对每个 spec 都跑一遍 `materializeServerSpec()`。materializer 的行为:

1. 用环境变量的运行时值（`getenv('SUPERTEAM_ROOT')`）替换 `${SUPERTEAM_ROOT}`。
2. 若当前进程没 export 该变量，回退到 `projectRoot()` —— 不继承 web 请求 env 的队列 worker 常见。
3. 可移植模式关闭时为 no-op（spec 本来就是绝对值）。

净效果:一份 project-scope 的 `.mcp.json` 同时携带裸命令 + 占位符；每个消费它的 backend writer 在真正需要时拿到的是裸命令 + 真实绝对路径。

### 编程辅助方法

如果宿主有自己的 MCP-spec writer 想走同样的处理，`McpManager` 上五个辅助方法都是公开的:

```php
use SuperAICore\Services\McpManager;

$mcp = app(McpManager::class);

// 正向 —— writer 用
$cmd  = $mcp->portableCommand('uvx', $resolvedUvxPath);   // 'uvx' 或绝对路径
$path = $mcp->portablePath('/Users/jane/proj/.mcp/foo');  // '${SUPERTEAM_ROOT}/.mcp/foo'

// 反向 —— 出口到不可移植目标时用
$abs  = $mcp->materializePortablePath('${SUPERTEAM_ROOT}/foo'); // '/srv/app/foo'
$spec = $mcp->materializeServerSpec([                            // 遍历 command + args + env
    'command' => 'python',
    'args'    => ['${SUPERTEAM_ROOT}/script.py'],
    'env'     => ['DATA_DIR' => '${SUPERTEAM_ROOT}/data'],
]);

// 配置访问 —— 关闭时返回 null
$varName = $mcp->portableRootVar(); // 'SUPERTEAM_ROOT' 或 null
```

### 注意事项

- **pyproject Python server 的 `uv run` 替换。** 可移植模式 + `pyproject.toml` + `entrypoint_script` 三者同时成立时，writer 会改路由策略 —— 不再把 `command` 钉到 `<projectRoot>/.venv/bin/<script>`（per-machine 路径），而是输出 `command: "uv"`、`args: ["run", "<script>"]`，让 `uv` 在 spawn 时解析 venv。如果宿主在 MCP spawn 时机的 PATH 上没有 `uv`，对该 server 关掉可移植模式（或装上 `uv`）。
- **`PHP_BINARY` 注册项。** `pdf-extract` 注册项保持 `'command' => PHP_BINARY` 直接写，注册表形状不变。开启可移植模式后 `installArtisan` 在写盘时把它规范化成 `'php'`。如果你有自定义注册项把绝对二进制路径硬写进 `command`，也要在 writer 里先过一遍 `portableCommand($bare, $resolved)`。
- **树外路径保持绝对。** `portablePath('/usr/share/foo')` 直接返回 `/usr/share/foo`，因为它不在 `projectRoot()` 下。这是设计 —— 占位符只对树内相对路径有意义。
- **Codex 辅助（`codexOcrMcpConfig` / `codexPdfExtractMcpConfig` / `superfeedMcpConfig`）写到 project-scope `.mcp.json`。** 可移植模式开启时它们输出占位符；出口 materializer 在抵达 `~/.codex/config.toml` 之前再展开成绝对路径。0.8.1 之前的"始终绝对"行为在关闭可移植模式时被原样保留。

---

## 14. SuperAgent host-config adapter —— `createForHost`

*自 0.8.5 起。*

`SuperAgentBackend::buildAgent()` 不再手工拼装 SDK provider 的构造形状。region / 无 region 双分支 + 手动注入 HTTP header 折叠成一个调用：

```php
// src/Backends/SuperAgentBackend.php（节选 —— backend 内部实际行为）
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

SDK 侧的 per-key adapter 拥有构造形状的映射权：

- **默认 adapter** —— 把 `api_key` / `base_url` / `model` / `max_tokens` / `region` 直接透传，再把 `extra` 在它们之后深合并（`extra` 不会意外覆盖顶层字段）。覆盖 Anthropic / OpenAI / OpenAI-Responses / OpenRouter / Ollama / LMStudio / Gemini / Kimi / Qwen / Qwen-native / GLM / MiniMax。
- **`bedrock` adapter**（SDK 内置）—— 把 `credentials.aws_access_key_id` / `aws_secret_access_key` / `aws_region` 拆进 BedrockProvider 构造器的 `access_key` / `secret_key` / `region` 字段。当结构化凭据块缺失时，`access_key` 回退到 `host['api_key']`。
- **未来的 provider key** —— 各自带 adapter（或走默认 adapter）。SDK 后续新增的 provider type 在这里零代码改动就能落地。

### 绕过 `Dispatcher` 的宿主该怎么做

绝大多数宿主走 `Dispatcher::dispatch(['backend' => 'superagent', …])`，根本不碰这一层。但有些宿主直接构造 `Agent` —— 通常是想用 SDK 的 `withSystemPrompt()` / `addTool()` / 流式 hook 而不要 Dispatcher 的 envelope —— 可以套同一个形状：

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
        // descriptor 声明的静态 + env 驱动 HTTP header 通过 `extra` 在
        // 默认 adapter 中透传。宿主也可以在这里塞任何 SDK 接受的
        // provider 专属 knob:`organization` / `reasoning` /
        // `verbosity` / `store` / `extra_body` / `prompt_cache_key` /
        // `azure_api_version` 等等。
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

### 为什么重要

- **每个 provider 一次工厂调用**，无论 provider key 是什么。之前抗着 `match ($providerType) { 'bedrock' => new BedrockProvider([...]), 'openai' => new OpenAIProvider([...]), … }` 的宿主，现在折叠成一行。SDK 后续新增的 provider key（`openai-responses` 和 `lmstudio` 在 0.7.0 落地；以后的也会透明落地）零代码上线。
- **region-aware provider 依然 region-aware**，调用方不需要知道 region 映射表。Kimi / Qwen / GLM / MiniMax 上传 `'region' => 'cn'`，SDK 的 per-provider `regionToBaseUrl()` 解析正确端点。Kimi / Qwen 上的 `'region' => 'code'` 走 OAuth 凭据存储（`KimiCodeCredentials` / `QwenCodeCredentials`），缓存里没 token 时回退到 `api_key`。
- **自定义 adapter 是扩展点。** 维护内部 provider 类（比如非标准认证的内部代理）的宿主，注册一次 adapter 后剩下的代码就能像处理任意 key 一样处理它：

  ```php
  use SuperAgent\Providers\ProviderRegistry;

  ProviderRegistry::registerHostConfigAdapter('my-internal-proxy', static function (array $host): array {
      return [
          'api_key'  => $host['credentials']['internal_token'] ?? null,
          'base_url' => 'https://llm-proxy.internal/v1',
          'model'    => $host['model'] ?? 'gpt-4o',
          // …任何具体 provider 类需要的字段
      ];
  });

  // 宿主代码里别处都这么用:
  $provider = ProviderRegistry::createForHost('my-internal-proxy', $hostConfig);
  ```

### 测试替身 —— `makeProvider()` seam

如果 backend 子类需要不动全局 `ProviderRegistry` 注入一个 fake `LLMProvider`，直接 override `makeProvider()`：

```php
class FakeSuperAgentBackend extends \SuperAICore\Backends\SuperAgentBackend
{
    protected function makeProvider(string $providerName, array $hostConfig): \SuperAgent\Contracts\LLMProvider
    {
        return new MyFakeProvider($hostConfig);
    }
}
```

0.8.5 之后 `SuperAgentBackend::makeAgent()` 永远收到一个构造好的 `LLMProvider`（不再是字符串 + 散开的 llmConfig 键），所以测试断言应该写 `$agentConfig['provider'] instanceof \SuperAgent\Contracts\LLMProvider`，不要再跟 provider 名字符串比。

---

## 15. 对话中途切换 provider（`Agent::switchProvider`）

*自 0.8.5（通过 SDK 0.9.5）起。*

这个特性 **SuperAICore 自己没用** —— `FallbackChain` 走 CLI 子进程级别的 backend，不是进程内 SDK provider；`Dispatcher` 也不在调用之间携带对话状态。但是直接包 `SuperAgentBackend` 并自己驱动 `Agent` 的宿主可以把一段活的对话中途切到另一个 provider 而不丢消息历史。适合 "便宜模型起步、上下文压力大时升级" 或者 "一个模型跑歪了用另一个模型重来" 这类场景。

```php
use SuperAgent\Agent;
use SuperAgent\Conversation\HandoffPolicy;

$agent = new Agent(['provider' => 'anthropic', 'api_key' => $key, 'model' => 'claude-opus-4-7']);
$agent->run('analyse this codebase');

// 切到一个更便宜 / 更快的模型继续:
$agent->switchProvider('kimi', ['api_key' => $kimiKey, 'model' => 'kimi-k2-6'])
      ->run('write the unit tests');

// 检查历史是否塞得进新模型的 context window —— 不同 tokenizer
// 数同一段历史会差 20–30%:
$status = $agent->lastHandoffTokenStatus();
if ($status !== null && ! $status['fits']) {
    // 在下次调用前触发你已有的 IncrementalContext 压缩
}

// 想把 Anthropic 签名 thinking block 留着，万一切回去用？
$agent->switchProvider('kimi', [...], HandoffPolicy::preserveAll());

// 对话跑歪了 —— 用另一个模型干净开局再来一次:
$agent->switchProvider('openai', [...], HandoffPolicy::freshStart());
```

三种策略预设：

- **`HandoffPolicy::default()`** —— 保留 tool 历史，丢弃签名 thinking，附加 handoff system marker，重置 continuation id。"换一个模型继续干" 的合理默认值。
- **`HandoffPolicy::preserveAll()`** —— 内部表示里什么都保留。encoder 仍然会丢掉目标 wire 形状装不下的东西（Anthropic 签名 thinking、Kimi `prompt_cache_key`、Responses-API 加密 reasoning item、Gemini `cachedContent` 引用），但这些会被存进 `AssistantMessage::$metadata['provider_artifacts'][$providerKey]`，后续切回原 family 时可以重新拼回去。
- **`HandoffPolicy::freshStart()`** —— 把历史压缩到只剩最后一个 user turn，让另一个模型干净开局。

### 哪些东西会丢

跨 family 编码总是会丢目标 wire 形状装不下的 artifact。切换是原子的 —— 新 provider 构造失败（`api_key` 缺失、region 未知、网络探测被拒）时，agent 留在旧 provider 上，消息列表保持原样。Gemini 是唯一在 wire 上不暴露 tool-call id 的 family；SDK 的 encoder 每次 call 时从 assistant 历史重建 `toolUseId → toolName` 索引，所以从 Gemini 起头的对话经过其他 provider 再切回 Gemini 不需要外部映射表。完整编码规则见 SDK 的 `[0.9.5]` CHANGELOG —— 在该节里搜 "Notes"。

### SuperAICore 宿主什么时候用得上这个

通过包直接用，几乎用不到。Dispatcher 是 one-shot 模型。但是宿主侧自己构造 `Agent` 的 runner（比如 SuperTeam 的 PPT pipeline，想用 Claude 规划、Kimi 执行，又不想付两次完整 context replay 的钱）可以用。如果你发现自己想从 SuperAICore 的 `SuperAgentBackend` 内部用它，先开 issue —— 当前没有进程内多轮 handoff 的产品面，加这个会动 Dispatcher 契约。

---

## 16. Skill engine —— 遥测、排序、FIX 模式演化

*自 0.8.6 起。*

三个正交的服务，把静态的 `.claude/skills/` 目录变成一个反馈闭环:

- **`SkillTelemetry`** —— 每次 Claude Code Skill 工具调用在 `sac_skill_executions` 落一行。
- **`SkillRanker`** —— 在注册表上跑纯 PHP BM25，按近期成功率加权。
- **`SkillEvolver`** —— 给失败的 skill 提出 FIX 模式 patch，仅以审核 candidate 入队。**永不自动应用。**

DERIVED / CAPTURED 演化模式（自动从成功 run 派生新 skill / 把用户演示的工作流捕获成新 skill）有意省略 —— Day 0 的策略是人来策展。云端 registry 也省略（暂无跨项目共享需求）。整个引擎精神上借鉴自 HKUDS/OpenSpace 的 `skill_engine`，砍到生产可用的安全子集。

### 通过 Claude Code hook 接遥测

包只发 artisan 接入点。hook 契约属于 Claude Code:

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

两个命令都从 stdin 读 hook JSON 负载 —— `PreToolUse` 期望 `session_id` / `transcript_path` / `cwd` / `tool_name` / `tool_input.skill`；`Stop` 期望 `session_id` / `stop_hook_active` / `user_interrupted`。负载读取使用 1.0s 软超时 + 200KB 上限的非阻塞读，避免病态管道挂起 session。遥测出错被静默吞掉 —— hook 永不失败。手动场景下命令行 fallback 选项也都齐全（`--skill` / `--session` / `--host-app` / `--status` / `--error`）。

`host_app` 通过向上找 `.claude/` 目录、取上级目录 basename 自动识别 —— 同一个包同时挂在 SuperTeam / SuperFeed 等多个宿主时很有用。

### 聚合接口:`SkillTelemetry::metrics()`

```php
use SuperAICore\Services\SkillTelemetry;
use Carbon\Carbon;

// 全时段、全 skill
$metrics = SkillTelemetry::metrics();

// 最近 7 天
$metrics = SkillTelemetry::metrics(Carbon::now()->subDays(7));

// 单个 skill
$metrics = SkillTelemetry::metrics(null, 'research');

// 返回:
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

一次查询、一次 GROUP BY。`recentFailures($skillName, $limit = 5)` 喂给 FIX 模式的 prompt 构造器。

### 排序:`SkillRanker`

纯 PHP BM25（Robertson-Walker `K1=1.5`、`B=0.75`，BM25-Plus IDF）。skill 名字在 doc bag 里重复一次以加权 intent 信号；description 加上 SKILL.md body 头 600 字符提供其余的 lexical 表面。CJK 感知的 tokenizer 把每个汉字切单独 token（中文 skill 描述很短，char-grams 够用）。带置信度加权的遥测加成:`final = bm25 * (1 + 0.4 * (success_rate - 0.5) * applied_signal)`，其中 `applied_signal = min(1, applied / 10)` 在 10 次后饱和。

```php
use SuperAICore\Registry\SkillRegistry;
use SuperAICore\Services\SkillRanker;

$ranker = new SkillRanker(new SkillRegistry(base_path()));

$results = $ranker->rank('外包项目工作量评估', limit: 5);
foreach ($results as $r) {
    echo "{$r['skill']->name}  score={$r['score']}  boost={$r['breakdown']['tel_boost']}\n";
    // breakdown 还包含:bm25、matched（per-term IDF×TF）、metrics（原始遥测行）
}

// 关掉遥测加成做纯 lexical 排序（比如刚开始接遥测、样本不够）:
$ranker = new SkillRanker(new SkillRegistry(base_path()), useTelemetry: false);

// 限制成 skill 子集（宿主侧 picker UI 用）:
$results = $ranker->rank($query, limit: 10, skillNames: ['research', 'plan', 'init']);
```

CLI 同源:`php artisan skill:rank "你的任务" --no-telemetry --format=json --cwd=/abs/path`。`--cwd` 覆盖对从 `web/public` 起跑的宿主很重要 —— 项目根可能在上级几层。

### FIX 模式演化:`SkillEvolver`

evolver 用当前 SKILL.md（截到 8K 字符）+ 遥测里的最近 5 次失败构造受约束的 LLM prompt，把结果写成 `pending` 状态的 `SkillEvolutionCandidate`，**永不直接改 SKILL.md**。人类通过 `php artisan skill:candidates` 审核队列。

```php
use SuperAICore\Services\Dispatcher;
use SuperAICore\Services\SkillEvolver;
use SuperAICore\Registry\SkillRegistry;
use SuperAICore\Models\SkillEvolutionCandidate;

$evolver = new SkillEvolver(
    new SkillRegistry(base_path()),
    app(Dispatcher::class),   // 可选 —— 只在 dispatch=true 时需要
);

// 手动触发 —— 不调 LLM，只是把 prompt 写成 candidate
$candidate = $evolver->proposeFix('research');

// 把 candidate 锚到具体的失败 run
$candidate = $evolver->proposeFix(
    skillName: 'research',
    triggerType: SkillEvolutionCandidate::TRIGGER_FAILURE,
    executionId: 1234,
    dispatch: false,
);

// 烧 token —— 调一次 LLM，把完整响应 + 抽出的 diff 都写回
$candidate = $evolver->proposeFix('research', dispatch: true);
echo $candidate->proposed_diff;   // LLM 回 NO_FIX_RECOMMENDED 时为 null

// 扫一遍 —— 把所有 failure_rate > threshold 的 skill 入队（要求至少 N 次）
// 按现有 pending 行去重。
$ids = $evolver->sweepDegraded(failureRateThreshold: 0.30, minApplied: 5);
```

prompt 里硬编的约束:

- "产出**最小可行 patch**，不要重写。"
- "如果证据中无法识别具体根因，回 `NO_FIX_RECOMMENDED`。"
- "不要凭证据之外的内容编造失败。"
- "不要重排 section、不要改 skill 名、不要改 frontmatter `name`、不要往 `allowed-tools` 里加新工具，除非证据明确要求。"
- 输出格式锁死两节:`Diagnosis`（2-4 句）+ `Patch`（单个 \`\`\`diff fenced block，或字面字符串 `NO_FIX_RECOMMENDED`）。

`--dispatch` 模式走 `Dispatcher::dispatch()`，参数 `capability: 'reasoning'`、`task_type: 'skill_evolution_fix'` —— 你 `RoutingRepository` 里答 `reasoning` 的 provider 来负责。无新 env 变量、无新 config 键。

推荐节奏:夜间 cron，不调 LLM。审核者看到一份遥测打标记的 skill 队列，prompt 已经构造好；他们决定哪些值得烧 token，再用 `php artisan skill:evolve --skill=<name> --dispatch` 单独跑。

```php
// app/Console/Kernel.php
$schedule->command('skill:evolve --sweep --threshold=0.30 --min-applied=5')
         ->daily()
         ->withoutOverlapping();
```

### 审核 candidate

```bash
# 列出 pending
php artisan skill:candidates

# 过滤
php artisan skill:candidates --skill=research --status=pending

# 看一个
php artisan skill:candidates --id=42 --show-prompt --show-diff

# 给 tooling 用的 JSON
php artisan skill:candidates --id=42 --format=json
```

状态:`pending`（刚入队）→ `reviewing` → `applied | rejected | superseded`。人工流程直接管道接到 `git apply`:

```bash
php artisan skill:candidates --id=42 --show-diff --format=text \
  | sed -n '/^=== Proposed Diff ===$/,$p' \
  | tail -n +2 \
  | git apply --check                  # dry-run 校验
```

应用之后把 candidate 标记为 done:

```php
SkillEvolutionCandidate::find(42)->update([
    'status' => SkillEvolutionCandidate::STATUS_APPLIED,
    'reviewed_at' => now(),
    'reviewed_by' => auth()->user()->email,
]);
```

### 有意没发的东西

- **DERIVED 模式**（自动从成功 run 派生新 skill）—— 需要 LLM 判官来决定一个多轮 run 是否值得提升成 skill，外加策展队列。0.8.6 不在范围。
- **CAPTURED 模式**（把用户演示的工作流捕获成新 skill）—— 同样的 blocker，加上需要打标 demo 的 UX 面。0.8.6 不在范围。
- **云端 registry / 跨项目 skill 共享** —— 当前没需求，需要 registry 服务和 skill 签名。
- **自动应用** —— evolver 永远只入队，永不应用。设计如此 —— 错的 patch 进 SKILL.md 会污染该 skill 此后的每一次执行。
- **挂到 `bin/superaicore`** —— 六个 artisan 命令只在 `SuperAICoreServiceProvider::boot()` 里注册。独立控制台不自动挂载，因为 skill 遥测是宿主关心的事，不是 Composer-CLI 的事。如果你需要在 Laravel 之外用它们，自己手动注册到 Symfony Console。

---

## 17. SemanticSkillReranker 改用 `EmbeddingProvider` SPI（0.9.0）

*自 0.9.0 起 —— SuperAgent SDK 0.9.7 的 `Memory\Embeddings\EmbeddingProvider`。*

`SkillRanker` 跑完 BM25 之后的可选语义重排,在 0.9.0 里把手写的 Ollama HTTP 客户端 + callable 适配代码全删了,改成统一通过 SDK 的 `EmbeddingProvider` SPI 解析。reranker、SDK 自带的 `SemanticSkillRouter`、host 自定义的 `OnnxEmbeddingProvider` 全部共用一个容器单例 + 一份缓存。

### 最省事的路径 —— Ollama

```dotenv
AI_CORE_EMBEDDINGS_OLLAMA_URL=http://127.0.0.1:11434
AI_CORE_EMBEDDINGS_OLLAMA_MODEL=nomic-embed-text
```

```bash
ollama pull nomic-embed-text   # 768 维,约 270MB
```

够了。`EmbeddingProviderFactory` 在首次调用时懒构造一个 `SuperAgent\Memory\Embeddings\OllamaEmbeddingProvider`,`SemanticSkillReranker` 透明消费。skill 排序开始在 BM25 词法匹配之上叠加意图语义 —— `php artisan skill:rank "重构认证模块"` 现在哪怕没有字面的中文 token 出现,也会更倾向于一个描述写"auth refactor"的 skill。

### 自带 —— `EmbeddingProvider` 实例

ONNX、OpenAI、Cohere、预制缓存或任何非 Ollama 路径,直接注册类型化 provider:

```php
// app/Providers/AppServiceProvider.php
use SuperAgent\Memory\Embeddings\OnnxEmbeddingProvider;

$this->app->bind(
    \SuperAgent\Memory\Embeddings\EmbeddingProvider::class,
    fn () => new OnnxEmbeddingProvider('/abs/path/to/all-MiniLM-L6-v2.onnx', dimensions: 384)
);
```

然后让 factory 指向这个绑定(或在已发布 config 中把 `super-ai-core.embeddings.provider` 设为实例)。SDK 的 `OnnxEmbeddingProvider` 需要 `ext-onnxruntime` 或 `ankane/onnxruntime` userland 绑定 + 模型文件 —— 安装错误路径见其构造函数 docblock。

### 自带 —— 闭包(legacy 形态)

如果你在 0.9.0 之前已经有一个 embedder 闭包,把它放到 `super-ai-core.embeddings.callback` 里。SDK 的 `CallableEmbeddingProvider` 自动检测闭包接 `array $texts`(优先批处理形态)还是 `string $text`(legacy 单条形态),原有 host 代码继续工作:

```php
// config/super-ai-core.php —— 两种形态都行
return [
    'embeddings' => [
        // 批处理形态 —— 推荐
        'callback'    => fn (array $texts) => $myBatchEmbedder->embedAll($texts),
        // 或单条形态(legacy VectorMemoryProvider 形态)
        // 'callback' => fn (string $text) => $myEmbedder->embed($text),
        'fingerprint' => 'my-bge-large-v1.5',  // 模型变更时改这个失效缓存
    ],
];
```

`fingerprint` 是缓存失效 key —— 切换底层模型时改它,缓存向量会干净 flush 而不污染无关条目。

### 单条失败优雅降级

embedder 对某条文本返回 `[]`(Ollama daemon 抖、ONNX 在某条上 OOM)时,reranker 保留该 hit 的 BM25 分数,而不是整批回退。其他行仍获得余弦提升。query 向量按 `fingerprint() . sha1(query)` 缓存,因此相同 query 的重复调用(批量排序 / 测试 harness 常见)不会重复 embed。

### 与 SDK 自带的 `SemanticSkillRouter` 共享

直接驱动 SDK 的 host(不走 SuperAICore Dispatcher)从容器里取同一实例,reranker 与 SDK router 共用一份缓存:

```php
use SuperAICore\Services\EmbeddingProviderFactory;
use SuperAgent\Skills\SemanticSkillRouter;

$embedder = app(EmbeddingProviderFactory::class)->make();
if ($embedder !== null) {
    $router = new SemanticSkillRouter(
        skillManager: $myManager,
        embedder: $embedder,           // reranker 用的同一实例
        threshold: 0.55,
        topK: 3,
    );
}
```

`SuperAgentBackend` 还会把解析到的 `EmbeddingProvider` 转发进 `Agent` 的 forward options bag(键 `embedding_provider`),方便未来 SDK 消费者通过 `Agent::getOptions()` 拿,无需逐次调用接线。

---

## 18. `agent_grep` + `browser` 工具开关(0.9.0)

*自 0.9.0 起 —— SuperAgent SDK 0.9.7 的 `AgentGrepTool` + `FirefoxBridgeTool`。*

`super-ai-core.tools` 上的两个工具注入开关。两者都**只在**调用方未传显式 `load_tools` 数组时生效(调用方主权优先)。两者都**只在**真正进入工具循环的 SuperAgent backend dispatch 上生效 —— 一次性调用(`max_turns=1`,无 `load_tools`)和 CLI backend dispatch(`claude_cli` / `codex_cli` 等)完全不受影响。

### `agent_grep` —— 默认开启

```dotenv
AI_CORE_TOOLS_AGENT_GREP=true   # 默认值,设 false 关闭
```

打开时,`SuperAgentBackend` 把 `'agent_grep'` 加到隐式 `load_tools` 列表前面。该工具在 SDK 的 `BuiltinToolRegistry::classMap` 中,`ToolLoader` 在 agent 第一次工具调用时懒解析。

相比普通 `grep` 多了什么:

1. **包含符号注入** —— 每条匹配行附上其所在的 `class::function`(或顶层 `function`),覆盖 PHP / JS / TS / Python / Go。默认提取器是纯 PHP 正则 —— 在典型代码上 `~95%` 准确,无外部依赖。
2. **会话内 chunk 截断** —— 同一会话内对同一 `(file, line range, sha)` 元组的重复查询被截断为 `... (lines N–M previously shown to you in this session)` 标记。状态存在 `ToolStateManager`,按 `(file, lineRange, sha)` 索引,因此 swarm 隔离工作时不会泄漏一个 agent 的 seen-chunk 账本到另一个。

要 tree-sitter 精度(在正则提取器覆盖不好的 Rust / Ruby / Java / C++ 代码库上值得),子类化 `AgentGrepTool` 并传入 `CompositeSymbolExtractor([new TreeSitterSymbolExtractor(), new RegexSymbolExtractor()])` —— 安装路径见 SDK 类 docblock `vendor/forgeomni/superagent/src/Tools/Builtin/AgentGrepTool.php`。需要 `tree-sitter` CLI 二进制在 `$PATH` 上 + 对应 grammar;SuperAICore 不自动 vendor。

要纯 `grep` 一致(比如脚本要消化原始 ripgrep 输出)?直接传一个不含 `agent_grep` 的显式 `load_tools` —— 调用方列表赢:

```php
$dispatcher->dispatch([
    'backend'    => 'superagent',
    'load_tools' => ['grep', 'read_file', 'web_fetch'],   // 显式 —— 没有 agent_grep
    'max_turns'  => 5,
    // …
]);
```

### `browser` —— 需手动安装

```dotenv
AI_CORE_TOOLS_BROWSER=true
SUPERAGENT_BROWSER_BRIDGE_PATH=/abs/path/to/forgeomni-bridge-launcher
```

`browser` 工具不在 `BuiltinToolRegistry::classMap` 中,因此 `load_tools` 够不到。flag 打开且 SDK 类可用时,`SuperAgentBackend::attachBrowserTool()` 实例化 `FirefoxBridgeTool` 并直接 `Agent::addTool()`。

工具通过 Native Messaging 驱动真实 Firefox 或 Chromium tab —— 动作:`navigate` / `screenshot` / `click` / `type` / `eval` / `wait` / `close`。PHP 侧(`FirefoxBridgeTool` + `NativeMessagingTransport` + `FirefoxBridge`)在 SDK 内自包含;host 装三样东西:

1. **Firefox**(或任何支持 WebExtension 的 Chromium-based 浏览器)。
2. **Forgeomni Bridge WebExtension** —— 极简 `manifest.json` + ~150 行后台脚本,通过 `runtime.connectNative('forgeomni_bridge')` 打开,把入消息分派到 `tabs.*` / `webNavigation.*` API。详见 `vendor/forgeomni/superagent/src/Tools/Browser/FirefoxBridge.php` 类 docblock。
3. **Native Messaging launcher 二进制** —— 任何把长度前缀 JSON 在 Firefox 与 PHP 进程间管道的可执行文件。jcode 的 Rust 二进制开箱即用,或写个 50 行 Node / Go shim。

launcher 装好且 `SUPERAGENT_BROWSER_BRIDGE_PATH` 配好之前,所有动作返回解释性错误,让 agent 学会请求安装帮助而非死循环。flag 提前打开是安全的。

要为某次 dispatch 显式覆盖 env 查找(罕见),传 `launcherArgv`:

```php
$dispatcher->dispatch([
    'backend'              => 'superagent',
    'browser_launcher_argv' => ['/opt/bridge/launcher', '--profile=staging'],
    // …
]);
```

### 紧凑能力面

`FirefoxBridgeTool` 故意只暴露上面 7 个动作。没有 tab 管理、cookie、history、downloads、extension API —— 那些会显著扩大滥用爆炸半径,且典型"像人一样用页面"工作量也用不上。需要更多功能的 host 通过自定义工具直接调 `FirefoxBridge::evalJs()`。

---

## 19. 浏览器截图闭环(0.9.0)

*自 0.9.0 起。*

`browser` 工具跑 `action: 'screenshot'` 时,`FirefoxBridgeTool::execute()` 返回 `ToolResult::success(['format' => 'png', 'base64' => $data, 'bytes' => N])`。返回内容被 JSON 编码后存到 `AgentResult` 消息流的 `ToolResultMessage` 内容块。

`SuperAgentBackend::persistLatestScreenshot()` 在 dispatch 后扫描该流:

1. 索引每一个 `toolName === 'browser'` 的 `tool_use` 块(按 `toolUseId`)。
2. 对每一个后续的、`toolUseId` 匹配且 `isError !== true` 的 `tool_result` 块,解码 JSON content 读 `base64`。
3. 留住最后一张成功的 —— 长 agent run 可能截多张图,只有最近的有运营意义。
4. 按 dispatch 的 process_id 写入 `BrowserScreenshotStore`(优先级:`options['process_id']` → `external_label` → `metadata.session_id` → `session_id` → 随机 hex)。
5. 把 URL 挂到 dispatch envelope 的 `latest_screenshot_url` 字段。

### 与 `AiProcessSource` 的 round-trip

`AiProcessSource::list()` 构造每个 `ProcessEntry` 时,按 `ai_processes` 行的 `external_label`(再回退到 `aiprocess.<id>` 复合 key)读 `BrowserScreenshotStore::latest()`。`/processes` 视图给有截图的行渲染黄色 `📷 screenshot` 徽章;点击在侧面板(B1 offcanvas)打开内嵌图。

reap 时(PID 死掉、状态切到 FINISHED),`AiProcessSource` 用同样 keys 调 `BrowserScreenshotStore::purgeFor()`,截图不会超过 run 的有效寿命累积。

### 配置存储 backend

```dotenv
AI_CORE_BROWSER_SHOTS_DISK=local                                # 任意 Laravel filesystem disk
AI_CORE_BROWSER_SHOTS_DIR=super-ai-core/browser-screenshots     # disk root 下的相对路径
```

生产建议:用 per-pod tmpfs disk(把 `tmpfs` 挂到 `/var/cache/super-ai-core/screenshots`,配一个指向那里的 `local` disk),或带短 lifecycle rule 的 S3 disk。`local` 默认值在单机 Laravel 安装和开发阶段都够用。

### 自定义 UI

要自家截图渲染(carousel、history、OCR pipeline)的 host 直接读 store:

```php
use SuperAICore\Services\BrowserScreenshotStore;

$store = app(BrowserScreenshotStore::class);
$url = $store->latest($externalLabel);   // 没有截图返回 null
```

要多帧存档(而非只留最新),在调用点 wrap 一层,用带逐帧后缀的 key 自己写每个 slot(比如 `"task:42:frame:7"`)。

---

## 20. `usage_source` 切分 —— `user` 与 `ambient`(0.9.0)

*自 0.9.0 起。*

SuperAgent SDK 0.9.7 的 `Swarm\AmbientWorker` 在 tick 上跑后台 memory dedup + staleness 扫描。它的 `tagUsage` 回调每完成一次 pass 触发,带 `usage_source: 'ambient'`,但 0.9.0 之前 SuperAICore 没办法在 `/usage` 上把这些行单独 bucket —— 它们混入用户面向的开销。

`Dispatcher::resolveUsageSource()` 现在从 `options['usage_source']` 或 `options['metadata']['usage_source']` 提取,写入顶层 `metadata.usage_source` 键(默认 `'user'`)。约束在 `[a-z0-9_-]{1,32}` 防 typo 泄漏成幻影 bucket。

### 接线 AmbientWorker

worker 本身在 SDK 里;SuperAICore 接一个 `tagUsage` 回调发一个 no-op 记账调用记录开销:

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
                'dedup_interval_seconds'       => 600,   // 10 分钟
                'stale_check_interval_seconds' => 3600,  // 1 小时
                'pass_budget_seconds'          => 5,
            ],
            tagUsage: function (string $passName, array $stats) use ($dispatcher) {
                // 记一行打 'ambient' 标签的合成行,这样 /usage 能 group 出来。
                // 没有 prompt,没有 model 调用 —— 只是想要 metadata 行。
                // 多数 host 已经直接通过 UsageRecorder 写自己的 ambient 行;
                // 这里 dispatch 路径只是示意。
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

通过 `Dispatcher` 驱动 ambient-mode dispatch 的 host(比如长 memory drawer 的后台再总结)在每次调用上传 source 即可:

```php
$dispatcher->dispatch([
    'prompt'   => 'Summarise drawers above 20K tokens.',
    'backend'  => 'superagent',
    'metadata' => ['usage_source' => 'ambient'],
    // …
]);
```

### 在 `/usage` 上读取切分

仪表盘的 "By Source" 卡片和 By Task Type / By Model / By Backend 并排。表头在 ambient 活动出现时显示 "N ambient · $X" 徽章,operator 一眼看到当前窗口的后台开销占比。宽屏布局自动切到 `col-lg-3`,原有卡片仍清楚。

不需要 `whereJsonContains` / JSON path —— Dispatcher 写入器把每行的 `usage_source` 拍平到顶层 metadata 键,因此 controller 在 PHP 通过 Eloquent collection 方法 group。在 MySQL 5.7、PostgreSQL 9、SQLite 上都不需要 driver 特定 JSON ops。

### 自定义 source bucket

allowlist 接受任何 `[a-z0-9_-]{1,32}` 字符串。host 自定义 source(`'eval'` / `'audit'` / `'replay'`)直接工作:

```php
$dispatcher->dispatch([
    'metadata' => ['usage_source' => 'eval'],   // 在 /usage 显示成自己的 bucket
    // …
]);
```

allowlist 之外的(大写、特殊字符、>32 字符)被规范化 —— 写入器执行 `mb_strtolower(preg_replace('/[^a-z0-9_-]+/i', '', $c))` 然后截到 32 字符。完全无法解析的值回退到 `'user'`。

---

## 21. 跨 harness 会话恢复(0.9.0)

*自 0.9.0 起 —— SuperAgent SDK 0.9.7 的 `Conversation\HarnessImporter` 系列。*

`super-ai-core.resume.enabled` 打开时,`/processes` 页面增加"Resume from…"下拉。Operator 从 picker 里选一个 Claude Code(`~/.claude/projects/<hash>/<uuid>.jsonl`)或 Codex(`~/.codex/sessions/**/*.jsonl`)会话,然后或在 modal 里查看 transcript,或让 host 把它再分发回 backend。

### 启用功能

默认关闭 —— 在共享机器上 importer 能看到所有 operator 的会话历史:

```dotenv
AI_CORE_RESUME_ENABLED=true
```

这会在 `/processes` 上把下拉揭开,并打开 `/super-ai-core/resume` 下的 3 个 endpoints:

- `GET /resume` —— 列出本机可用 harness
- `GET /resume/{harness}` —— 列出会话(最新优先,`limit` query 参数,默认 30,最大 200)
- `POST /resume/{harness}/load` —— 加载某会话,返回 transcript + 可选 host payload

### `on_load` 回调 —— host 端再分发钩子

没有回调时,`/load` endpoint 仅返回解析后的 transcript JSON。要"一键恢复进 X provider 的聊天"的 host 接一个返回 `{redirect: '<url>'}` 的 callable:

```php
// config/super-ai-core.php
use SuperAgent\Messages\Message;

return [
    'resume' => [
        'enabled' => env('AI_CORE_RESUME_ENABLED', false),
        'on_load' => function (string $harness, string $sessionId, array $messages): array {
            // $messages 是 list<Message> —— 直接喂 Agent::loadMessages($messages)
            // 或经过 Conversation\Transcoder::encode() 切到不同 wire family。
            $session = ChatSession::createFromHarnessImport($harness, $sessionId, $messages);
            return [
                'redirect' => route('chat.show', $session),
                'session_id' => $session->id,
            ];
        },
    ],
];
```

前端 modal 检查 `host_payload.redirect` —— 存在时 navigate 过去,而不是把 transcript 内嵌渲染。

### 控制器端编程访问

要自家 "Resume" UI 的 host 直接 resolve resolver,自己造流程:

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

        // 用户选了一个之后:
        $payload = $this->resolver->loadTranscript($harness, $sessionId);
        // → ['harness' => 'claude', 'session' => '8e2c-…',
        //    'transcript' => [['role' => 'user', 'content' => '…'], …],
        //    'host_payload' => /* on_load 返回的内容 */]

        return view('my.resume.review', compact('payload'));
    }
}
```

### 通过 SDK Transcoder 跨 wire 续聊

importer 返回 SDK 内部表示的 SuperAgent `Message[]`。要在不同 provider family 上续聊(在 Claude 起,在 Kimi 续),把消息过一遍 SDK 0.9.5 的 `Conversation\Transcoder`:

```php
use SuperAgent\Agent;
use SuperAgent\Conversation\Transcoder;

$messages = $this->resolver->loadTranscript('claude', $sessionId)['transcript'];
// 如果你 JSON 序列化过,把 transcript 数组 hydrate 回 Message 实例。
// (HarnessImporter::load() 直接返回 Message 实例 —— host 进程不跨 JSON 边界
//  时用这条路径再分发。)

$agent = new Agent([
    'provider' => /* host-built 的 Kimi LLMProvider */,
    'max_turns' => 10,
]);
$agent->loadMessages($messages);   // Transcoder 处理 wire-shape 转换
$agent->run('继续上次没做完的 —— 写单测。');
```

### 跨 harness 有损吗

importer 故意宽容 —— 格式错的行 / 未知 event 类型被静默跳过,而不是拒掉整个会话。真实 harness 的真实 session log 是脏的(Claude Code 1.x vs 2.x schema 漂移、Codex CLI rollout 格式变迁)。Transcoder 会剥掉目标 wire shape 不能携带的产物 —— Anthropic 签名的 thinking block 跳到 OpenAI 时活不下来,OpenAI Responses 加密 reasoning items 跳到 Anthropic 也活不下来。Tool-call ids 自 0.9.5 起跨所有 family round-trip 正确。

### 没有的功能

- **自动发现 jcode / pi / OpenCode session 文件** —— SDK 0.9.7 的 importer 集合覆盖 Claude Code 与 Codex。需要其他 harness 的 host 自己实现 `HarnessImporter` 并 drop 一个 service-provider 绑定登记实现。
- **SuperAICore 自家 `ai_processes` 历史的 re-dispatch UI** —— `/processes` 自 0.6.7 起按合约就是 live-only(只显示运行中 PID,不显示 finished 行)。Resume 下拉是为跨 harness session 拾取设计的,不是为重放此前 SuperAICore 运行设计。要"用不同 provider 重跑这个 finished task"的 host 在 `ai_processes` 审计日志之上自建 UI。

---

## 22. 持久化 goal store（0.9.1）

*自 0.9.1 起 —— SuperAgent SDK 0.9.8 `Goals\Contracts\GoalStore` SPI。*

SDK 0.9.8 引入 `Goals\GoalManager` —— "本对话正在朝 X 推进"的 thread
作用域原语。manager 需要持久化才能跨进程重启存活(codex 在用户敲
`/pause` 时把 goal 暂停,host 进程回收后必须保持暂停)。
SuperAICore 0.9.1 自动接入默认 Eloquent 后端。

### 默认接线

`SuperAICoreServiceProvider::register()` 绑定:

```php
$this->app->bind(
    \SuperAgent\Goals\Contracts\GoalStore::class,
    \SuperAICore\Goals\EloquentGoalStore::class,
);
$this->app->singleton(\SuperAgent\Goals\GoalManager::class);
```

`app(GoalManager::class)` 自动注入持久化 store。跑 `php artisan migrate`
创建 `ai_goals` 表(`thread_id`、`description`、`status`、`metadata`、
时间戳)。`super-ai-core.table_prefix` 同样生效。

```php
use SuperAgent\Goals\GoalManager;

$manager = app(GoalManager::class);
$manager->setActiveGoal($threadId, '把结账流程重构以适配新税引擎');

// 之后 —— agent 通过只读工具 agent_get_goal 中途读取目标,
// 或者 host 在预算超限时把它暂停:
$manager->pause($threadId);
// …host 进程重启…
$active = $manager->getActiveGoal($threadId);   // 仍为 null —— 暂停中
$manager->resume($threadId);
```

### 约束:每个 thread 至多一行非终态

`EloquentGoalStore::setActiveGoal()` 在插入新行前,把该 thread 现存的
`active` / `paused` / `budget_limited` 行置为 `superseded`。终态
(`completed` / `cancelled` / `superseded`)自由累积作为审计轨迹。

### 自定义 store —— host 已经在自家表里维护 goal

已经建模 goal 的 host(比如 SuperTeam 在 `objectives` 表里按项目存)
替换自己的实现。契约很小,`GoalStore` 五个方法:

```php
namespace App\Goals;

use SuperAgent\Goals\Contracts\GoalStore;
use SuperAgent\Goals\Goal;

final class MyGoalStore implements GoalStore
{
    public function setActiveGoal(string $threadId, string $description, array $metadata = []): Goal
    { /* upsert 到自家 `objectives` 表,把先前 active 行标记 superseded */ }

    public function getActiveGoal(string $threadId): ?Goal
    { /* 暂停 / 完成 / 不存在时返回 null,否则返回 Goal::active(...) */ }

    public function pause(string $threadId): void           { /* … */ }
    public function resume(string $threadId): void          { /* … */ }
    public function complete(string $threadId, ?string $result = null): void { /* … */ }
}
```

在 host 服务 provider 的 `register()` 里,**在任何东西解析 `GoalManager`
之前**重绑:

```php
$this->app->bind(
    \SuperAgent\Goals\Contracts\GoalStore::class,
    \App\Goals\MyGoalStore::class,
);
```

包内的 `EloquentGoalStore` 在你的 host 视角下成为 dead code —— 它是
参考实现,不是硬依赖。

---

## 23. 三档审批闸门（0.9.1）

*自 0.9.1 起。*

`Runner\ApprovalGate` 对齐 codex `/permissions` 命令(从 `/approvals`
更名)。三种模式 —— `Auto` / `Suggest` / `Never` —— 加上一次性的
`/approve` override token 实现 codex 风格的"放这一个调用过"流程。

### 模式差异

```
                只读工具         普通变更             破坏性 shell
   ──────────────────────────────────────────────────────────────────────
   Auto         allow             allow               SUGGEST APPROVAL
   Suggest      allow             SUGGEST APPROVAL    SUGGEST APPROVAL
   Never        allow             hard deny           hard deny
```

只读白名单硬编码在 enum 上:

```php
ApprovalMode::readOnlyAllowlist();
// → ['agent_grep', 'agent_glob', 'agent_read', 'agent_ls',
//    'agent_status', 'web_search', 'web_fetch', 'agent_get_goal']
```

破坏性 shell 检测走现有的 `Guidance\Gates\DestructiveCommandScanner` ——
本包从 0.7 之前就在用的同一组正则。Auto 模式即便对普通变更放行,仍把
scanner 当作安全底线。

### 在 host runner 里接线

闸门是纯决策函数 —— host 在转发 tool call 到 backend 前调用它,
suggestion 在自家 UI 里渲染。backend 端没有强制执行;接进来只需在
runner 里加一层包装:

```php
use SuperAICore\Runner\ApprovalGate;
use SuperAICore\Runner\ApprovalMode;
use SuperAICore\Runner\ApprovalDecision;

$gate    = app(ApprovalGate::class);
$mode    = ApprovalMode::parse($thread->approval_mode ?? 'suggest');
$pending = $thread->pending_approval_tool_use_id;   // host 端存,见下文

$decision = $gate->evaluate(
    toolName:           $call->name,
    arguments:          $call->arguments,
    mode:               $mode,
    toolUseId:          $call->id,
    approvedToolUseId:  $pending,
);

if ($decision->allow) {
    $thread->forget('pending_approval_tool_use_id');   // 一次性 override 已消费
    return $backend->dispatchTool($call);
}

if ($decision->canRetry) {
    // Suggest 模式 —— 上抛给用户。用户点 /approve,host 写
    // $thread->pending_approval_tool_use_id = $call->id,然后重发
    // 同一调用。
    return [
        'error' => $decision->reason,
        'code'  => $decision->errorCode,    // 'mutation_pending_approval' 或
                                            // 'destructive_pending_approval'
        'tool_use_id' => $call->id,
    ];
}

// 硬拒 —— Never 模式拦下了变更。告诉用户切模式;不要自动重试。
throw new RuntimeException($decision->reason);
```

### `/approve` 流程

1. agent 发出一个产生变更的 `tool_use` block。
2. 闸门返回 `canRetry: true`、码 `mutation_pending_approval`,带上调用的 `tool_use_id`。
3. host UI 显示"批准这个调用?",带 diff / shell 命令。
4. 用户点 `/approve`。host 把 `tool_use_id` 写到 `pending_approval_tool_use_id`。
5. host 重跑同一 agent 回合。闸门看到 `approvedToolUseId === toolUseId`,返回 `allow`。
6. host 清掉 `pending_approval_tool_use_id`。一次性 —— agent 下一次调用重新走闸门。

override 用 `hash_equals($approvedToolUseId, $toolUseId)` —— 字符串
相等,无编码花招。host 拥有存储与清理纪律;闸门无状态。

### 自定义破坏性扫描器

闸门构造函数可选传入 `DestructiveCommandScanner`。要覆盖(比如加 SQL
DROP 检测)重绑:

```php
$this->app->singleton(\SuperAICore\Runner\ApprovalGate::class, function ($app) {
    return new \SuperAICore\Runner\ApprovalGate(
        scanner: new \App\Guidance\StrictScanner(),
    );
});
```

---

## 24. Workspace plugin manifest（0.9.1）

*自 0.9.1 起。*

codex"workspace plugin sharing"模式的 PHP 移植。团队把
`.superaicore/workspace-plugins.json` 提交到 repo,新人 `git clone`
即拿到团队全套 plugin,不用一份机器特定的 onboarding 文档。

### Manifest 格式

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

- `scope: "workspace"` → 所有人在此 repo 工作时必装。registry 返回为 `missing_required`。
- `scope: "user"` → 仅推荐。返回为 `missing_recommended`;host UI 应当提示开发者,而不是自动安装。

### Sync 循环

```php
use SuperAICore\Plugins\WorkspacePluginRegistry;

$registry = app(WorkspacePluginRegistry::class);

// 收集 host 已装 plugin 名 —— 这是 host 特定逻辑(自家 PluginInstaller
// 知道它们在哪)。
$installedNames = collect(app(\App\Plugins\PluginInstaller::class)->list())
    ->pluck('name')
    ->all();

$pending = $registry->pendingInstalls($installedNames);
// → [
//     'missing_required'    => [['name' => 'team-pr-review',  …]],
//     'missing_recommended' => [['name' => 'team-jira-helper', …]],
//   ]

foreach ($pending['missing_required'] as $entry) {
    // 自动安装 —— 不弹确认;这是 workspace 作用域要求。
    app(\App\Plugins\PluginInstaller::class)->install(
        $entry['name'], $entry['source'], $entry['version'],
    );
}

if ($pending['missing_recommended']) {
    // 提示开发者,不要自动安装。
    $this->info(sprintf(
        "本 workspace 推荐这些 plugin: %s。运行 `php artisan plugin:install --recommended` 添加。",
        collect($pending['missing_recommended'])->pluck('name')->implode(', '),
    ));
}
```

### 从 PHP 增删条目

```php
$registry->add(
    name:    'team-deploy-helper',
    source:  'github.com/our-org/agent-skill-deploy',
    version: '2.1.0',
    scope:   WorkspacePluginRegistry::SCOPE_WORKSPACE,
);

$registry->remove('team-jira-helper');   // 返回 true / false
```

registry 写出 pretty-print 的 JSON,键序稳定 —— manifest 进 PR 时
review 友好。

### Manifest 路径

硬编码在 `<workspace_root>/.superaicore/workspace-plugins.json`。
默认 `workspaceRoot` 是 `base_path()`。如果 repo 布局把 workspace
root 放在别处,重绑单例:

```php
$this->app->singleton(\SuperAICore\Plugins\WorkspacePluginRegistry::class, function () {
    return new \SuperAICore\Plugins\WorkspacePluginRegistry(
        workspaceRoot: '/var/www/myapp',
    );
});
```

---

## 25. 无头 `/v1/usage` JSON 端点（0.9.1）

*自 0.9.1 起。*

`Http\Controllers\UsageApiController` 对齐 codex app-server `/v1/usage`
形状 —— 每次请求一个轴,bucket 模式一致。给不想刮 HTML 仪表盘的
billing pipeline / Grafana / CI 成本闸门用。

### 路由注册 + 鉴权

路由注册在包标准前缀下(默认 `super-ai-core`):

```
GET /super-ai-core/v1/usage
```

鉴权由 host 负责。把外层 route group 或 per-route 中间件挂在配置里:

```php
// config/super-ai-core.php
return [
    'route' => [
        'middleware' => ['web', 'auth:sanctum', 'can:view-billing'],
    ],
];
```

控制器不假设有 session;不挂中间件的话,任何能命中的调用方都拿得到
聚合成本数据。

### 查询参数

| key         | 类型   | 默认    | 说明                                             |
| ----------- | ------ | ------- | ------------------------------------------------ |
| `group_by`  | string | `day`   | `day` / `model` / `provider` / `thread` / `backend` / `task_type` 之一 |
| `days`      | int    | `30`    | 钳制到 ≥ 1                                       |
| `model`     | string | —       | `ai_usage_logs.model` 精确匹配过滤              |
| `task_type` | string | —       | `ai_usage_logs.task_type` 精确匹配过滤          |
| `user_id`   | string | —       | `ai_usage_logs.user_id` 精确匹配过滤            |
| `backend`   | string | —       | `ai_usage_logs.backend` 精确匹配过滤            |

未知 `group_by` 返回 422,带允许列表。

### 响应形状

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

`cache_hit_rate` 在 bucket 内部按 `cache_read / (input + cache_read)`
重新计算 —— 而不是把 per-row 戳的值平均 —— 因此无论哪一部分的行
带有 metadata 键,结果都正确。

### Curl 示例

```bash
# 按模型的近 7 天日开销
curl -H "Authorization: Bearer $TOKEN" \
    'https://app.example.com/super-ai-core/v1/usage?group_by=day&days=7'

# 近一个月按 thread 的成本,限 SuperAgent backend
curl -H "Authorization: Bearer $TOKEN" \
    'https://app.example.com/super-ai-core/v1/usage?group_by=thread&backend=superagent&days=30'

# 按 task type 看 provider 拆分
curl -H "Authorization: Bearer $TOKEN" \
    'https://app.example.com/super-ai-core/v1/usage?group_by=provider&task_type=email_summary'
```

### Grafana JSON datasource

形状跟 Grafana JSON 数据源直接兼容 —— 把 panel 指向
`/super-ai-core/v1/usage?group_by=day&days=$__range_days`,字段映射
`bucket → time`,选 `cost_usd` / `cache_hit_rate` 作为 metric。控制器
内 5000 行硬上限避免日期范围跑飞。

### 限制

- 底层 query 上 `limit(5000)` —— 超出窗口时 bucket 总额仍然正确,但精确切片只是最近 5000 行。需要更宽窗口时收紧日期或按 `backend` / `model` 过滤。
- 过滤仅精确匹配,无 `LIKE` / `IN` / regex。要更复杂的查询走 HTML 仪表盘的 `UsageController`(全过滤面),或在 `AiUsageLog` 之上自建控制器。

---

## 26. `cache_hit_rate` 聚合（0.9.1）

*自 0.9.1 起 —— DeepSeek-TUI 每轮 cache-rate 显示的姊妹。*

`metadata` 中带非零 cache 切片的每行 `ai_usage_logs` 现在也带有
`metadata.cache_hit_rate ∈ [0, 1]`。

### 为什么用 GROSS 分母

```
cache_hit_rate = cache_read_tokens / (input_tokens + cache_read_tokens)
                                       └── 未命中输入 ──┘
```

分母是**毛**prompt —— cache 折扣前的总 prompt size,不是仅 cache 部分。
跨行 group-and-average 正确工作,因为每行用同一分母形状:分别聚合
`cache_read` 与 `input`,再做除法。

```php
// 按模型分组 —— 不需要重新推导即可读:
$rows = AiUsageLog::where('model', 'claude-opus-4-7')
    ->where('created_at', '>=', now()->subDays(7))
    ->get();

$rates = $rows->avg(fn ($r) => $r->metadata['cache_hit_rate'] ?? null);
// 与 ground truth 重新计算给出同一结果:
$cacheRead = $rows->sum(fn ($r) => $r->metadata['cache_read_tokens'] ?? 0);
$gross     = $rows->sum('input_tokens') + $cacheRead;
$truth     = $gross > 0 ? $cacheRead / $gross : 0;
```

### 缺失 vs 零 —— 语义差异

| 状态                              | `cache_hit_rate` 值 | 含义                          |
| --------------------------------- | ------------------- | ------------------------------ |
| 没发 cache key,cache 关闭         | 缺失(键未设置)    | "cache 不适用"                 |
| 发了 cache key,完全 miss          | `0.0`               | "0% 命中 —— 冷启动或翻搅"     |
| 发了 cache key,部分命中           | `0.42`              | "42% 的付费 prompt 是免费的"   |
| 发了 cache key,完全命中           | `1.0`               | "100% 命中 —— 粘性 session"    |

按 `cache_hit_rate IS NOT NULL` 过滤的仪表盘干净分离"功能在用,只是
冷启动"与"功能根本没用"。

### DeepSeek V3 / R1 别名

老 DeepSeek wire(V3、R1)戳 `cache_hit_tokens` 而非
`cache_read_tokens`。`UsageRecorder` 两者都接受 —— 入口处读别名,
出口处写规范键。

```php
// 这两个产生同一行:
$recorder->record(['cache_read_tokens' => 1500, …]);
$recorder->record(['cache_hit_tokens'  => 1500, …]);   // legacy 别名
```

历史上戳别名的 host 代码向前兼容 —— 不需要迁移。

### `total_cache_read_tokens` 汇总卡

`/usage` 页面 session-summary 块现在在原有冷 cache 与 ambient cost 切片
之外,带 `total_cache_read_tokens`。这是绝对计数,不是命中率 ——
命中率出现在 per-model 与 per-row。

### 从 queue worker 读

`cache_hit_rate` 列是 `metadata`(JSON)的一部分,不是顶层列,所以
没装 JSON-path 索引的 MySQL 5.7 / SQLite 也能内联读:

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

§25 的 `/v1/usage` 端点在六个 group-by 轴上做同样的服务端计算 ——
通常比自己滚动算更省事。

---

## 另见

- [docs/idempotency.md](idempotency.md) —— 60 秒去重窗口、repository 层契约
- [docs/streaming-backends.md](streaming-backends.md) —— 各 CLI 流格式
- [docs/task-runner-quickstart.md](task-runner-quickstart.md) —— `TaskRunner` 选项参考
- [docs/spawn-plan-protocol.md](spawn-plan-protocol.md) —— codex/gemini agent 模拟
- [docs/mcp-sync.md](mcp-sync.md) —— catalog 驱动的 MCP sync
- [docs/api-stability.md](api-stability.md) —— SemVer 契约
