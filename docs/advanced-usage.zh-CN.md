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
27. [TaskRunner 可靠性波次（0.9.2）](#27-taskrunner-可靠性波次092)
28. [Squad 多智能体 + SDK 1.0.0 配套绑定（0.9.6）](#28-squad-多智能体--sdk-100-配套绑定096)
29. [SDK 1.0.5 升级 + opencode 借鉴特性波次（0.9.7）](#29-sdk-105-升级--opencode-借鉴特性波次097)
30. [Opus 4.8 + Grok + Cursor（1.0.0 / SDK 1.0.9）](#30-opus-48--grok--cursor100--sdk-109)
31. [kimi-cli + kimi-code 双轨支持（1.0.2 / SDK 1.0.10）](#31-kimi-cli--kimi-code-双轨支持102--sdk-1010)
32. [SmartFlow —— 跨 CLI 动态工作流 + superagent 联邦（1.0.5 / SDK 1.1.0）](#32-smartflow--跨-cli-动态工作流--superagent-联邦105--sdk-110)
33. [CLI skill 桥接 —— `superaicore:sync-cli` + `SkillLibrary` contract（1.0.6）](#33-cli-skill-桥接--superaicoresync-cli--skilllibrary-contract106)
34. [Fable 5 与 Sonnet 5 —— 自适应请求面与 Anthropic effort 档位（1.0.11 / SDK 1.1.5）](#34-fable-5-与-sonnet-5--自适应请求面与-anthropic-effort-档位1011--sdk-115)
35. [ai-dispatch 对齐 —— 短名派单、会话续聊、运行存档（1.1.0）](#35-ai-dispatch-对齐--短名派单会话续聊运行存档110)

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

#### 在 chat 轮次里挂 MCP server（1.0.8）

默认 chat 轮次运行在**锁死的空 MCP 面**上（`mcp_mode: 'empty'`——1.0.8 之前
的硬编码行为）。1.0.8 起调用方可以把限定的一组 MCP server 工具暴露给模型——
这是宿主做"与所选 MCP server 对话"功能的积木：

```php
// 1. 写一个子集配置——只含本会话勾选的 server。
$configFile = storage_path('app/chat-mcp-' . uniqid() . '.json');
file_put_contents($configFile, json_encode([
    'mcpServers' => [
        'fetch'  => ['type' => 'stdio', 'command' => 'uvx', 'args' => ['mcp-server-fetch']],
        'sqlite' => ['type' => 'stdio', 'command' => 'uvx', 'args' => ['mcp-server-sqlite']],
    ],
]));

try {
    $response = $backend->streamChat($prompt, $onChunk, [
        'mcp_mode'        => 'file',        // 只暴露下面这个子集
        'mcp_config_file' => $configFile,
        'idle_timeout'    => 600,           // stdio server 冷启动慢
    ]);
} finally {
    @unlink($configFile);
}
```

语义（Claude backend；其它 CLI 忽略这些键，与 `allowed_tools` 同约定）：

- `'empty'`（默认）—— `--mcp-config '{"mcpServers":{}}' --strict-mcp-config`。
- `'file'` —— `--mcp-config <mcp_config_file> --strict-mcp-config`。模型加载
  所列 server 的 `mcp__<server>__<tool>` 工具。`--permission-mode
  bypassPermissions`（始终传入）自动批准其调用。`'file'` 缺可用路径时回退
  `'empty'`，绝不静默继承用户的全部配置。
- `'inherit'` —— 不加 MCP flag；CLI 加载用户自己的配置。
- **ToolSearch 自动追加（1.0.9）**—— 当前 Claude CLI 把 MCP 工具延迟在
  `ToolSearch` 元工具后面，且 `--tools` 限制的是**全部**工具面（help 文本
  的 "built-in set" 措辞有误导性；`--tools` 里的 `mcp__x__*` 模式会被静默
  忽略）。有效 MCP 面非空时，`ToolSearch` 会被保证加进 allowlist，模型才
  真正够得到 MCP 工具。老版本 CLI 忽略未知 `--tools` 项——处处安全。
- `extra_cli_flags: string[]` —— 原样追加（应对未来 CLI flag 变化的逃生舱）。
- 配置 schema 注意：`env` 必须序列化成 JSON **对象**——PHP 空数组会变成
  `[]`，`--strict-mcp-config` 会拒掉该 server。生成子集文件时把空 `env`
  键删掉（或强转 object）。

`buildChatArgs(string $cliPath, array $options): array` 是 `streamChat()`
背后的公开纯 argv 构建器，需要检查或单测 flag 矩阵时无需拉起进程。

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

## 27. TaskRunner 可靠性波次（0.9.2）

*自 0.9.2 起 —— 仅 `Runner\TaskRunner`。*

当主 backend 输出 quota/rate-limit 类失败时,TaskRunner 可以把同一任务交给
下一个 backend。这个能力面向长任务:CLI 订阅或 API key 中途撞限时,host 仍想
让同一 prompt 继续由 Codex、Gemini、Kimi 或 HTTP backend 完成。

Fallback 是**每次运行级别**。调用方请求的 backend 永远先尝试,所以主 backend
恢复后不需要清 sticky 状态,下一次运行自然切回。

### 每次调用指定链

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

如果 `claude_cli` 以匹配输出失败,TaskRunner 会重试 `codex_cli`。第二个 prompt
是原 prompt 加上一段紧凑 handoff 块,包含上一个 backend、exit code、输出/log
尾部。若下一 backend 应只拿原 prompt,传 `inherit_failure_context=false`。

### 自动链

```php
$envelope = app(TaskRunner::class)->run('claude_cli', $prompt, [
    'fallback_chain' => 'auto',
]);
```

`auto` 使用已注册/启用 backend,默认顺序:

```text
claude_cli -> codex_cli -> gemini_cli -> kimi_cli -> copilot_cli ->
kiro_cli -> superagent -> anthropic_api -> openai_api -> gemini_api
```

设置 `AI_CORE_TASK_FALLBACK_CHECK_AVAILABILITY=true` 后,每个注册 backend
进入 auto 链前会先报告二进制或凭证是否看起来可用。

### 全局默认

```dotenv
AI_CORE_TASK_FALLBACK_AUTO=false
AI_CORE_TASK_FALLBACK_CHAIN=claude_cli,codex_cli,gemini_cli
AI_CORE_TASK_FALLBACK_CHECK_AVAILABILITY=false
AI_CORE_TASK_FALLBACK_INHERIT_CONTEXT=true
```

对应配置块是 `super-ai-core.task_fallback`:

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

每次调用的 option 覆盖配置。`fallback_chain` 可传逗号分隔字符串、数组或
`'auto'`。

省略 `fallback_chain` 时,TaskRunner 按以下顺序解析 workload 策略:

```text
fallback_profile / chains_by_profile
-> task_type / chains_by_task_type
-> capability / chains_by_capability
-> task_fallback.chain
-> auto_enabled / auto_chain
```

示例配置:

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
],
```

### 匹配语义

只有失败 envelope 的 `error`、`output`、`summary`、`log_file` 尾部或 exit
code 字符串里包含配置片段时才继续 fallback。默认片段包括:

- `rate limit`、`rate_limit`、`usage limit`
- `quota`、`quota_exceeded`、`insufficient_quota`
- `too many requests`、`429`
- `billing`、`budget`、`limit reached`
- `usage_not_included`

这样 prompt 校验、文件缺失、tool 失败和其他非 quota 错误会停在原 backend,
除非 host 显式把它们加入 `fallback_on`。

### 尝试报告

启用 fallback 后,返回 envelope 包含:

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

`TaskResultEnvelope::toArray()` 以 `fallback_report` 暴露同一份数据,持久化
envelope 的 host 不需要特殊分支即可存下来。

每次 Dispatcher attempt 也会收到适合 usage-row 分析的 metadata:

```php
[
    'fallback_active' => true,
    'fallback_chain' => ['claude_cli', 'codex_cli'],
    'fallback_attempt' => 2,
    'fallback_primary_backend' => 'claude_cli',
    'fallback_backend' => 'codex_cli',
]
```

### 相关落地方向

把 fallback 原语当作可靠性层使用,而不只是最后一次 retry:

- **按任务类型分链** —— coding、research、summarisation、后台维护可以有不同默认
  链。Coding 常以 `claude_cli` 或 `codex_cli` 开头;摘要类可以安全加入
  `kimi_cli`;直连 HTTP backend 适合放在最后做 headless 兜底。
- **UI 状态 badge** —— 把 `fallback_report` 存到宿主 task 行,渲染
  "primary limited"、"continued on codex"、"stopped on non-retryable
  error" 这类紧凑状态。有 `log_file` 时把每次 attempt 链到对应日志。
- **队列 retry 策略** —— 先用 TaskRunner fallback,再考虑 queue-level retry。
  队列 retry 会重跑整个 job;fallback 保持同一个逻辑运行继续,并继承失败 backend
  的上下文。
- **可靠性分析** —— 把 `fallback_report[*].backend` 与
  `ai_usage_logs.backend` 联合分组,找出经常撞 quota 的主 backend,以及实际完成
  工作的次级 backend。这个结果可以直接反哺 `auto_chain` 排序。
- **安全复核** —— 保持 `fallback_on` 窄。只加入 host 认为可重试的错误片段;
  validation 失败和 tool-policy deny 通常应该保持终态。
- **渐进发布** —— 先在一个任务类型上用 per-call `fallback_chain`,稳定后移入
  `super-ai-core.task_fallback.chain`;只有在确认 backend availability 与计费行为后,
  再开启 `AI_CORE_TASK_FALLBACK_AUTO=true`。

---

## 28. Squad 多智能体 + SDK 1.0.0 配套绑定（0.9.6）

*0.9.6 起 —— SDK 约束移到 `^1.0`。*

0.9.6 把 SDK 1.0.0 的 `Squad` peer-collaboration pipeline 作为第十个
dispatcher adapter 落地，并把 SDK 0.9.8 配套原语
（`AutoModelStrategy`、`CacheAwareCompressor`、`UntrustedInput`、
`TokenBucket`、`AdHocMemoryProvider`、`Conversation\Fork`、
`AgentDepthGuard`、DeepSeek FIM）封装到一等公民宿主服务后，让任意
dispatch 路径都能寻址。每个绑定都是加性且 opt-in。

### Squad pipeline —— 自适应跨模型 dispatch

```php
use SuperAICore\Services\Dispatcher;

$result = app(Dispatcher::class)->dispatch([
    'backend' => 'squad',
    'prompt'  => '把 AuthController 重构为 Laravel Sanctum。',

    // 可选 —— 默认走 TaskDecomposer 的启发式分解。
    // 每个子任务带难度等级 (trivial/easy/moderate/hard/expert)，
    // ModelTierMap 把它映射到对应分层 provider。
    'subtasks' => [
        ['role' => 'planner',  'description' => '提出文件改动方案', 'difficulty' => 'moderate'],
        ['role' => 'editor',   'description' => '应用 diff',         'difficulty' => 'hard'],
        ['role' => 'reviewer', 'description' => '审查结果',           'difficulty' => 'easy'],
    ],

    // 可选 —— 本次 dispatch 覆盖全局 tier map。
    'tier_map' => [
        'trivial'  => ['provider' => 'anthropic', 'model' => 'claude-haiku-4-5'],
        'easy'     => ['provider' => 'deepseek',  'model' => 'deepseek-v4-flash'],
        'moderate' => ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6'],
        'hard'     => ['provider' => 'anthropic', 'model' => 'claude-opus-4-7'],
    ],

    'max_cost_usd'   => 2.50,                // 可选 —— 在 80% 处 downshift
    'checkpoint_dir' => storage_path('app/squad/auth-refactor'),
    'squad_id'       => 'auth-refactor-2026-05-16',  // 用同样的 id 重新 dispatch 即可恢复
]);

// Envelope 表面
$result['text'];                       // 跨步骤合并的输出
$result['cost_usd'];                   // 跨步骤累加的成本
$result['turns'];                      // 实际运行的步骤数
$result['squad']['squad_id'];
$result['squad']['step_count'];
$result['squad']['completed'];         // 已完成的子任务 role 列表
$result['squad']['roles'];             // list<{name, provider, model, tier}>
$result['squad']['checkpoint_path'];   // 磁盘路径 —— 作为 `checkpoint_dir` 回喂可恢复
$result['squad']['mailbox_log'];       // peer-message 审计轨迹
```

Pipeline 在每一步后写 checkpoint。如果进程被中途 kill，用同样的
`squad_id` 和 `checkpoint_dir` 重新 dispatch 会从上一个成功步骤
恢复 —— 前置 role 不会重跑。

成本上限（`max_cost_usd`）按步骤执行。累计成本越过 80% 上限时，
后续步骤会自动 downshift 一档（`hard → moderate`、`moderate → easy`
等），直到 pipeline 完成或撞硬天花板。Envelope 的 `squad.roles`
数组反映每步最终运行的档位，所以宿主 UI 可以显示 "第 3 步从
`hard` 降到 `moderate`"。

启发式 `TaskDecomposer` 足够时（多数任务），完全省略 `subtasks`。
分解器读 prompt，按 prompt 关键词 + 长度启发式拆成 planner /
editor / verifier / 等子任务并打难度等级。预分解的 `subtasks`
在宿主有领域专门知识知道怎么拆分时最有用（比如永远是 planner →
diff → reviewer → doc-writer 的代码审查工作流）。

### `smart` 和 `squad` 控制台命令

两者都是对 vendor `superagent` binary 的透传：

```bash
./vendor/bin/superaicore smart "审计这个 diff"
./vendor/bin/superaicore smart show --last
./vendor/bin/superaicore smart replay <run-id> --max-cost=1.50

./vendor/bin/superaicore squad "重构 auth 模块" --max-cost=2.0
./vendor/bin/superaicore squad --no-squad "对比 legacy 路径"
```

SDK 装在 `vendor/forgeomni/superagent/` 之外时，传
`--binary=/abs/path/to/superagent`。

### `AutoModelRouter` —— `/model auto` 启发式

从容器解析服务并喂给它 Agent 会看到的 `Message[]` / `systemPrompt`
/ `options` 三元组：

```php
use SuperAgent\Messages\Message;
use SuperAICore\Services\AutoModelRouter;

$router = app(AutoModelRouter::class);

$messages = [
    Message::user('审查 user_schema 重写的迁移方案。'),
    Message::user('特别要确认 backfill 是否并发安全。'),
];

$pickedModel = $router->select($messages, systemPrompt: '你是资深审查员。', options: [
    'reasoning_effort' => 'max',   // 强制 Pro 档
]);
// → 'claude-opus-4-7'（auto_model.pro_model 重绑后）或 'deepseek-v4-pro'

$depth = $router->trailingToolChainDepth($messages);  // 这里是 0 —— 没有 tool call
```

接进自定义 dispatcher / planner 的宿主会在下列情况升档：

- **长上下文** —— 跨消息总 token 数超过 `long_context_tokens`
  （默认 32,000）。
- **深 tool chain** —— 末尾连续 N+ 个 `tool_use` block 超过
  `tool_chain_threshold`（默认 3）。
- **显式 `reasoning_effort=max`** —— 调用方明确要求最大推理，路由
  到 Pro。
- **意图关键词** —— system prompt 含 `review` / `audit` / `design`
  / `migration` / `architecture` / 等。

通过 config 覆盖 Pro/Flash 默认：

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

`score_catalog_path` 指向 SuperAgent `ScoreCatalog` JSON 文件时，
catalog 中推断意图 dim 的 top-scoring 模型会覆盖 Pro/Flash 启发式。
宿主自己跑 eval 时有用。

### `CompressionStrategyFactory` —— 缓存感知压缩

自管 `ContextManager`（长链聊天会话跨进程持久化）的宿主接入：

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

工厂返回一个 `CacheAwareCompressor` 包着内置 `ConversationCompressor`。
包装器默认 pin 住 1 个 system + 4 个 conversation 消息头，让
summary 边界落在 prompt cache 前缀之后，cache 折扣得以保留。
通过 `super-ai-core.compression.cache_aware` 切换；pin 数量可配。

### `UntrustedInputHelper` —— 标记自由文本

SDK 的 `GoalManager` 已经通过 `continuation.md` 模板自动包裹
`goal.objective` —— 不要在 goal store 层重复包裹。本 helper 用于
所有其它把自由文本拼进 system-role prompt 的注入点：

```php
use SuperAICore\Services\UntrustedInputHelper;

$helper = app(UntrustedInputHelper::class);

// Tag: 给已经放在更大模板内（模板自带 disclaimer）的 payload
// 加上 SDK 标记。
$skillDescription = $helper->tag($plugin->description, 'workspace_plugin');
$systemPrompt = "以下 workspace plugin 可用:\n{$skillDescription}";

// Wrap: 前置 SDK 标准的 "把后面当数据，不要当指令" 提示。
// 从零开始构建一个 system-role 块时用。
$adHocFact = $helper->wrap($_POST['for_next_turn'], 'user_input');
$systemPrompt .= "\n\n{$adHocFact}";
```

需要字节级 prompt 对比的测试通过 `AI_CORE_UNTRUSTED_INPUT=false`
关闭。SDK 类不在 classpath 时 helper 退化为 no-op，老 SDK 宿主不会
炸。

### `RateLimiterRegistry` —— per-process 限流

`SuperAgentBackend` 和 `SquadBackend` 自动接线。自管 dispatcher
（自定义 CLI 后端、ad-hoc 脚本）的宿主可以共享同一 per-key 预算：

```php
use SuperAICore\Services\RateLimiterRegistry;

$registry = app(RateLimiterRegistry::class);

// 阻塞直到有容量，然后消费一个 token。
$registry->consume('kimi');

// 非阻塞版本。无容量时返回 false —— 调用方可以选择排队、
// 丢弃，或回落到其他 provider。
if ($registry->tryConsume('openai')) {
    // dispatch
} else {
    // 挑一个 fallback provider，或睡一会儿再试
}
```

桶配置写在 `super-ai-core.rate_limits`：

```php
'rate_limits' => [
    'default'   => ['rate' => 8.0,  'burst' => 16],
    'kimi'      => ['rate' => 5.0,  'burst' => 10],
    'openai'    => ['rate' => 16.0, 'burst' => 32],
    'deepseek'  => ['rate' => 8.0,  'burst' => 16],
],
```

缺失 key 回落到 `default`。删掉 `default` 完全禁用限流器
（`consume()` 变 no-op）。设计上 per-process；分布式 swarm（每
pod 一个 agent）应该用 Redis-backed Guzzle 中间件挂在 provider
HTTP client 上 —— 本 registry 保持简单，与之不冲突。

### `AdHocMemoryRegistry` —— per-session "下一轮用" 事实

聊天 UI 暴露 "为下一轮注入事实" 文本框。控制器 push 到 session
的 provider；下次 dispatch 时 SuperAgent 后端把 inbox 块渲染在
prompt 之前：

```php
use SuperAICore\Services\AdHocMemoryRegistry;

$registry = app(AdHocMemoryRegistry::class);

// 在控制器里：用户输入 "忽略 deprecated 的 /v1 端点"
$noteId = $registry->push(
    sessionId: $chatSession->id,
    content:   $request->input('for_next_turn'),
    ttlSeconds: 600,           // 10 分钟 TTL
    untrusted:  true,
    kind:       'note',
);

// 关闭聊天时清空整个 session 的池
$registry->forget($chatSession->id);
```

内存 process-local —— 条目在进程退出时消失。持久化事实归
`MEMORY.md` / `BuiltinMemoryProvider`，不归这里。Provider 类就是
SDK 的 `AdHocMemoryProvider`；想直接渲染 inbox 的宿主可以解析
`forSession($id)` 并查看队列。

### `ConversationForkService` —— codex `/side` 语义

```php
use SuperAICore\Services\ConversationForkService;

$forks = app(ConversationForkService::class);

// 分支对话。把 fork handle 存到 URL 里某个 UUID 下面，
// 让用户能重新进入。
$fork = $forks->start($chatSession->messages);
session()->put("fork:{$forkId}", $fork);

// 用户在侧支跑几条消息，对比模型……

// 丢弃侧支：父链不变。
$newParent = $forks->finish($fork, 'discard');

// 把指定侧支消息 promote 回父链。
$newParent = $forks->finish($fork, 'promote', [3, 5, 7]);

// 全部 promote。
$newParent = $forks->finish($fork, 'promoteAll');

$chatSession->update(['messages' => $newParent]);
```

服务无状态 —— fork 生命周期归宿主管理。适合 "分支对话、在侧支
试不同模型、只把有用的侧支消息 promote 回来" 的聊天 UI。

### `DeepSeekFimService` —— 中段填充

DeepSeek 的 FIM 端点在 `beta` 区域。chat-shaped `Backend`
抽象不适配（没有 `messages`，只有 prefix + suffix），所以构建
IDE 风格补全功能的宿主直接调本服务：

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

设置 `DEEPSEEK_API_KEY`（或 `super-ai-core.deepseek.api_key`）
启用。服务按调用构造 `beta` region 的 provider —— chat region
的 DeepSeek provider 显式拒 FIM。

### `reasoning_effort` 三档拨盘

`Dispatcher::dispatch()` 的按调用 option：

```php
$result = $dispatcher->dispatch([
    'backend'          => 'superagent',
    'prompt'           => '审计这个迁移中的竞态条件。',
    'reasoning_effort' => 'max',   // off | high | max
]);
```

按 upstream 路由到正确的 body shape：
- 多数 provider：顶层 `reasoning_effort` 字段。
- NVIDIA NIM：`chat_template_kwargs.thinking`。
- Anthropic（SDK 1.1.5+）：`output_config.effort`,覆盖 Fable 5 / Sonnet 5 /
  Opus 4.5+ / Sonnet 4.6 —— 见 §34。
- 不实现该能力的 provider：静默忽略。

设为 `max` 时同时喂给 `AutoModelRouter` 的升级启发式。

### `Agent::switchProvider()` handoff

```php
$result = $dispatcher->dispatch([
    'backend' => 'superagent',
    'prompt'  => '……继续这个对话……',
    'handoff' => [
        'provider' => 'kimi',
        'config'   => [
            'api_key' => env('KIMI_API_KEY'),
            'region'  => 'cn',
        ],
        'policy'   => 'preserveAll',   // default | preserveAll | freshStart
    ],
]);

// 历史对话装不下新模型上下文窗口时 envelope 给出预警 ——
// 宿主可以渲染 "下一轮前先压缩" 的提示。
$result['handoff_token_status'];
// → ['tokens' => 142_000, 'window' => 128_000, 'fits' => false, 'model' => 'moonshot-v1-128k']
```

`HandoffPolicy::default()` 保留近期 turn 并丢老 tool 输出。
`preserveAll` 保留全部（可能装不下新窗口 —— 看
`handoff_token_status`）。`freshStart` 只带 system prompt 前进。

### 子智能体深度上限

```php
// config/super-ai-core.php
'agents' => [
    'max_depth' => 3,   // SDK 默认 5
],
```

服务提供者启动时转发到 `Swarm\AgentDepthGuard::setMax()`。
per-process 覆盖：env 变量 `SUPERAGENT_MAX_AGENT_DEPTH`。

### 该用哪个绑定

| 绑定 | 何时用 |
| --- | --- |
| `SquadBackend` | 多步任务受益于每步用不同模型（planner → editor → reviewer）。需要成本上限。希望 checkpoint 崩溃恢复。 |
| `AutoModelRouter` | 你在自建 dispatcher / planner 并希望复用 SDK 的 Pro/Flash 启发式而不绑死 `SuperAgentBackend`。 |
| `CompressionStrategyFactory` | 你自管 `ContextManager` 跑长链多轮会话并希望 cache 前缀在 summary 后存活。 |
| `UntrustedInputHelper` | 你在 SDK 的 `GoalManager` 没覆盖的注入点把自由文本拼进 system prompt。 |
| `RateLimiterRegistry` | provider 已经在上游对你限流，你希望客户端再加一道防线。 |
| `AdHocMemoryRegistry` | 聊天 UI 暴露 "下一轮用" 事实，需要 per-session 隔离。 |
| `ConversationForkService` | 聊天 UI 提供 branch / "在侧支试不同模型"。 |
| `DeepSeekFimService` | IDE 风格 prefix completion / inline fill。chat-shaped `Backend` 不适配。 |
| `reasoning_effort` | 你想给某个 dispatch 加思考量而不全局重绑模型。 |
| `Agent::switchProvider` handoff | 你直接包 `SuperAgentBackend` 并希望对话中途切 provider。 |

---

## 29. SDK 1.0.5 升级 + opencode 借鉴特性波次（0.9.7）

在 SDK 1.0.5 能力包之上从 [opencode](https://github.com/sst/opencode)
移植的 10 个模式。主线是 visibility-first 的 dispatch envelope：每次
SuperAgent 调度记录 pre/post shadow-git 快照下的逐文件 diff，UI 上每行
显示 `+N −M` 条带 + revert 按钮，agent 也可中断流程问操作者要决策，
不再需要宿主自己搭旁路。其余是运维脚手架：按 agent 权限规则集、子
agent 权限继承、snapshot 保留策略、plan/build 模式、PTY shell 会话、
会话分享。

### 29.1 逐文件 diff 摘要 + revert 按钮

**目标**：每次触及工作区的调度都留下可机读的变更记录，模型写错时
一键回滚。

**接入**：

```bash
# SDK 1.0.5（经 0.9.7 SuperAICore bump）—— 全自动，无需配置
php artisan migrate                       # 拉取 ai_usage_logs 上的 3 列

# 验证 shadow 仓库可达
php -r 'require "vendor/autoload.php"; var_dump((new SuperAgent\Checkpoint\GitShadowStore(getcwd()))->shadowDir());'
```

Dispatcher 在 `ai_usage_logs` 上写 3 列：

| 列 | 类型 | 含义 |
|---|---|---|
| `pre_snapshot` | varchar(64) | 调度运行**前**抓的 shadow-git commit。`POST /usage/{id}/revert` 使用。 |
| `post_snapshot` | varchar(64) | 调度运行**后**抓的 shadow-git commit。作为逐文件 diff 的 `to` 端。 |
| `file_diff_summary` | json | `{additions, deletions, files, diffs: [{file, additions, deletions, status, patch, truncated}], truncated}` 信封。 |

**从 PHP 读 diff 信封**：

```php
use SuperAICore\Models\AiUsageLog;

$row = AiUsageLog::find($usageLogId);
$diff = $row->file_diff_summary;
echo "+ {$diff['additions']} − {$diff['deletions']}，{$diff['files']} 个文件\n";

foreach ($diff['diffs'] as $f) {
    echo "  {$f['status']} {$f['file']}   +{$f['additions']} −{$f['deletions']}\n";
    if ($f['truncated']) {
        echo "    （patch 截断在 256 KB）\n";
    }
}
```

**回滚**：

```bash
# UI：在 /usage 里点带有 pre_snapshot 行上的 ↩ 按钮。
# Headless：
curl -X POST -H "X-CSRF-TOKEN: $TOKEN" "$BASE_URL/usage/$ID/revert"
# → {"ok":true,"message":"Worktree restored to snapshot ab1c2d3.","snapshot":"ab1c2d3…"}
```

快照之后新增的未追踪文件**不**会被动 —— 匹配 SuperAgent SDK
`GitShadowStore::restore()` 的契约。这是有意为之：你保留新生成的日志
/ 产物，同时回滚已追踪的源文件。

**可调参数**：

- `AI_CORE_SNAPSHOT_PROJECT_ROOT` —— 重写 shadow 镜像的路径。默认解析
  顺序：`options['project_root']` →
  `super-ai-core.snapshot.project_root` → `base_path()` → `getcwd()`。
- `AI_CORE_SNAPSHOT_ENABLED=false` —— 完全关闭 diff 记录，回到 0.9.7
  之前的 byte-identical 信封。
- `AI_CORE_SNAPSHOT_REVERT_ENABLED=false` —— 继续记录但 revert 端点
  返回 403。
- 每个文件的 patch 截断在 256 KB；整个 diff 上限 200 个文件。

**每日 prune**：

```bash
php artisan super-ai-core:snapshot-prune --days=7 --dry-run
php artisan super-ai-core:snapshot-prune --days=7
```

或在 `app/Console/Kernel.php` 里排程：

```php
protected function schedule(Schedule $schedule)
{
    $schedule->command('super-ai-core:snapshot-prune')->dailyAt('02:00');
}
```

### 29.2 运行中 HITL `ask_user` 工具

**目标**：模型遇到分叉时能中断流程问操作者要决定，而不是猜错或者
等到下一次 prompt。

**接入**：

```dotenv
AI_CORE_TOOLS_ASK_USER=true
```

就这一行。flag 开启后 `SuperAgentBackend` 在每次调度里都挂上
`AskUserTool`。工具向 `ai_user_questions` 写一行，每 500ms 查一次状态，
拿到操作者的回答（或 timeout 报错）就返回。

**模型发出的 tool-use**（在 trace 里可见）：

```json
{
  "name": "ask_user",
  "input": {
    "question": "迁移要给 5000 万行的表加 NOT NULL 列。用 `IF NOT EXISTS` 单次跑，还是写分批回填？",
    "options": [
      {"label": "IF NOT EXISTS", "description": "一次性；短时间锁表"},
      {"label": "分批回填", "description": "慢但无锁；生产更安全"}
    ],
    "timeout_seconds": 1200
  }
}
```

**操作者在 `/processes` 看到的**：一张警告卡片，含问题、预定义选项
按钮（或无选项时的自由文本输入框）。卡片每 4 秒轮询
`/processes/questions`；行状态翻为 `answered` 后自动消失。

**非 UI 客户端答复**：

```bash
curl -X POST -H "Content-Type: application/json" -H "X-CSRF-TOKEN: $TOKEN" \
  "$BASE_URL/processes/questions/$QUESTION_ID/answer" \
  -d '{"answer": "分批回填"}'
```

**何时**不**适合用**：

- 长时间无人值守的 queue worker —— 轮询循环会阻塞 agent 最多
  `timeout_seconds`（默认 600s，上限 3600s）。无人盯的任务请不要开
  `AI_CORE_TOOLS_ASK_USER`。
- 上下文已能消歧的无分叉决策。工具描述里明确告诉模型"不要问用户
  自己能推出来的问题"，相信这个 gate。

### 29.3 会话提醒

**目标**：根据上下文给 system prompt 加前缀（plan 模式标志、安全
敏感区域、项目专属约定 …），不需要调用方关心。

**配置**（`config/super-ai-core.php`）：

```php
'reminders' => [
    'rules' => [
        [
            'name' => 'plan-mode-active',
            'when' => ['agent' => 'plan'],
            'text' => "## Plan 模式已激活\n请把计划写到 `.superagent/plans/{session}.md`，不要对工作区文件调用任何 edit/write 工具。",
        ],
        [
            'name' => 'security-sensitive-area',
            'when' => ['metadata.path' => 'src/Auth/*'],
            'text' => "## 安全提示\n该目录涉及鉴权 + 权限代码。优先做增量改动；任何触及 token 存储的改动请标记需人工 review。",
        ],
        [
            'name' => 'compliance-region-eu',
            'when' => ['metadata.region' => 'eu'],
            'text' => "## 合规\n本次调度在 EU 区域 —— 适用 GDPR。任何 prompt 都不要包含用户 PII。",
        ],
    ],
],
```

**匹配语义**：

- `when` 的 key 是 dotted-path，在 `Dispatcher::dispatch()` 传入的
  `$options` 上查找。空 `when`（或不写）意味"始终匹配" —— 适合做
  全局合规横幅。
- 值支持 shell 风格通配符（`fnmatch`），所以
  `'metadata.path' => 'src/Auth/*'` 会匹配 `src/Auth/` 下的所有文件。
- 规则按声明顺序触发；命中的 body 之间空一行拼接，并前置到调用方
  的 system prompt。

### 29.4 按 agent 权限规则集

**目标**：声明式的每 agent 工具门禁。`plan` agent 只能写 `.md` 计划
文件；`explore` agent 只读；`build` agent 全开。

**配置**（`config/super-ai-core.php`）：

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

**求值语义**（opencode `permission/evaluate.ts`）：

- 规则形状：`{permission, pattern, action}`。值可以是 string（对该
  工具广播 action）或按 pattern 分支的 map。
- **最后一条匹配的规则胜出**。先 `'*' => 'allow'`、再
  `'edit' => 'deny'`，`edit` 就被拒。
- 没规则匹配时默认 action 是 `ask`。evaluator 的 `project()` 方法
  输出三个 list —— `allowed_tools`、`denied_tools`、`ask_tools`，
  SuperAgentBackend 把前两个接到 agent。`ask_tools` 让宿主在更窄的
  范围上接 HITL 钩子。

**触发**：在 dispatch 里传 `options['agent']`（或
`metadata.agent`）。SuperAgentBackend 读到后查 evaluator，把结果接到
SDK `Agent` —— 除非调用方显式传了 `allowed_tools` / `denied_tools`，
那就以显式为准。

### 29.5 Plan mode 工作流

**目标**：模型把计划写到 markdown 文件；操作者批准；build agent
照计划执行。对应 opencode 的 plan_enter / plan_exit。

**调度**：

```php
use SuperAICore\Modes\CliModeRouter;
use SuperAgent\Modes\ModeContext;

$ctx = ModeContext::root('plan');
$result = app(CliModeRouter::class)->dispatch(
    'plan',
    "重构 auth middleware，去掉旧的 session-token 存储路径。",
    $ctx,
);

echo $result->text;                // build 阶段输出（或被拒绝则是计划全文）
echo $result->modeSpecific['plan_file'];   // .superagent/plans/{session}.md
echo $result->modeSpecific['phase'];       // completed | plan_rejected
```

**按顺序发生的事**：

1. **Plan 阶段**：调度到 `super-ai-core.modes.plan.plan_backend`
   （默认 `cli:claude_cli`）。合成的 system prompt +
   `super-ai-core.agents.plan.permission` 规则集（如声明）拒绝写
   plan 文件以外的内容。模型写
   `.superagent/plans/{session}.md`。
2. **审批阶段**：开 `ai_user_questions` 行让操作者
   `[Approve, Reject]`。编排器每 500ms 查一次，直到
   `approval_timeout`（默认 600s）。HITL 关时
   （`tools.ask_user_enabled=false`）自动通过，确保 CI 可用。
3. **Build 阶段**：调度到 `super-ai-core.modes.plan.build_backend`，
   合成的 prompt 指向已批准的 plan 文件 + 包含其全文。

**配置**（`config/super-ai-core.php`）：

```php
'modes' => [
    'plan' => [
        'enabled'          => true,
        'plan_backend'     => 'cli:claude_cli',
        'build_backend'    => 'cli:claude_cli',
        'plan_dir'         => '.superagent/plans',
        'auto_approve'     => null,           // null = 自动检测
        'approval_timeout' => 600,
    ],
],
```

### 29.6 子 agent 权限推导

**目标**：父 agent 调度子 agent（通过 SuperAgent 的 `AgentTool` 或
任何嵌套调度）时，子必须继承父的 deny 集。read-only 父必产生
read-only 子。

**两种信号源**：

```php
// 方案 A：显式 pass-through（你自己写 dispatcher 且确切知道父禁了什么时用）
$child = $dispatcher->dispatch([
    'prompt'              => $task,
    'agent'               => 'explore',
    'parent_denied_tools' => ['edit', 'write', 'bash'],
]);

// 方案 B：按 agent 名解析（让 PermissionEvaluator 自己查父的规则集）。
// 父是 super-ai-core.agents.{name}.permission 里已声明的 agent 时
// 这条更干净。
$child = $dispatcher->dispatch([
    'prompt'   => $task,
    'agent'    => 'explore',
    'metadata' => ['parent_agent' => 'plan'],
]);
```

**合并语义**：子有效 deny 集 =
`union(explicit_child_denied, agent_rule_denied, parent_denied)`。
单调 —— 子永不能 elevate。

### 29.7 PTY 长连接 shell 会话（Phase 1）

**目标**：把长时间运行的 shell 进程（测试 watcher、`tail -f`、
`npm run dev`）流到 UI，不阻塞 agent 循环。

**接入**：

```dotenv
AI_CORE_PTY_ENABLED=true
```

**Spawn**：

```bash
curl -X POST -H "Content-Type: application/json" -H "X-CSRF-TOKEN: $TOKEN" \
  "$BASE_URL/pty/sessions" \
  -d '{"command":"npm run dev","cwd":"/srv/app","title":"vite watcher"}'
# → {"ok":true,"session":{"id":42,"pid":12345,"status":"running","log_path":"..."}}
```

**Poll**：

```bash
curl "$BASE_URL/pty/sessions/42/poll?cursor=0"
# → {"ok":true,"id":42,"chunk":"vite v5.4.0 ready in 184ms\n  ➜  Local:   http://...","cursor":48,"status":"running","exit_code":null}

# 下次 poll 从返回的 cursor 续传
curl "$BASE_URL/pty/sessions/42/poll?cursor=48"
```

**Terminate**：

```bash
curl -X POST -H "X-CSRF-TOKEN: $TOKEN" "$BASE_URL/pty/sessions/42/kill"
```

**Phase 1 限制**：

- 不支持 stdin。`write` 端点返回 501。PHP 没法在 HTTP 请求间保持 pipe
  存活（除非常驻 worker）。
- 不是真 TTY。我们用 `proc_open` 而不是 `openpty`。需要真正终端语义
  的（curses 风格 TUI、escape 序列移动光标）渲染不正确。

**Phase 2（暂缓）** 把传输换成 Laravel Reverb / Soketi 的
WebSocket，cursor 协议不变。

### 29.8 会话分享主机队列

**目标**：为会话生成可分享 URL，让同事不通过 DB 就能 review agent
审计 trail。

**REMOTE 模式**（推到外部 sharer）：

```dotenv
AI_CORE_SHARE_ENABLED=true
AI_CORE_SHARE_REMOTE_URL=https://share.acme.example.com
AI_CORE_SHARE_SECRET=opaque-bearer-token-the-sharer-accepts
```

```bash
curl -X POST -H "X-CSRF-TOKEN: $TOKEN" "$BASE_URL/share/sessions/$SESSION_ID/create"
# → {"ok":true,"share_id":"abc123…","share_url":"https://share.acme.example.com/shares/abc123…","status":"active","message":"Share ready."}
```

**LOCAL 模式**（内网 —— 宿主自己的 SuperAICore 当 share 视图）：

```dotenv
AI_CORE_SHARE_ENABLED=true
AI_CORE_SHARE_LOCAL_URL_TEMPLATE=https://internal.acme.example.com/super-ai-core/shares/{share_id}
```

本地 URL 模板把 `{share_id}` 占位符替换出实际 URL。ShareSessionService
仅写行，**不**做任何推送；宿主需要自己实现一条路由按 `share_id` 读
行并渲染会话。

**Revoke**：

```bash
curl -X POST -H "X-CSRF-TOKEN: $TOKEN" "$BASE_URL/share/sessions/$SESSION_ID/destroy"
```

本地行翻为 `revoked`。REMOTE 模式下还会 best-effort DELETE
`<remote_url>/api/shares/<share_id>` —— 失败静默忽略，因为本地撤销
本身已经足够停止暴露链接。

### 29.9 SDK 1.0.5 配套 —— LSP、结构化压缩摘要、Gemini 3.5

- **LSP 工具** —— 设 `AI_CORE_TOOLS_LSP=true`，SuperAgentBackend 在
  隐式 `load_tools` 里加上 `lsp`。agent 即可调用
  `lsp.diagnostics($file)` / `lsp.hover($file, $line, $col)` /
  `lsp.definition($file, $line, $col)` / `lsp.touch($file)`，背后是
  SDK 自带的 9 个语言服务器（phpactor、intelephense、gopls、
  rust-analyzer、pyright、typescript-language-server、clangd、
  bash-language-server、zls）。各服务器的 root marker 是
  composer.json / go.mod / Cargo.toml 等。
- **结构化压缩摘要** —— 设
  `AI_CORE_COMPRESSION_SUMMARY_PROMPT=structured` 让每次调度都用 SDK
  1.0.5 的 7 段模板（Goal / Constraints / Progress / Decisions /
  Next Steps / Critical Context / Relevant Files）。比默认小 30-50%，
  跨多次压缩能保留 blocked 状态。每次调用的
  `options['summary_prompt']` 优先级更高。
- **Gemini 3.5 特性** —— `thinking`、`grounding` / `google_search`、
  `url_context` 作为 per-call 选项传给 `Dispatcher::dispatch()`。
  SuperAgentBackend 把它们转发给 `Agent::run($prompt, $options)`；
  SDK 的 `GeminiProvider` 在 thinking 分支上看
  `modelSupportsThinking()` gate，仅在自己的 `tools[]` 里追加
  `{googleSearch: {}}` / `{urlContext: {}}`。非 Gemini provider 静默
  忽略。
- **跨 provider handoff transcoder 修复** —— SDK 0.9.5 的
  `ChatCompletionsProvider::convertMessage()` 在第一个 `tool_use`
  block 提前 return（破坏 Kimi / GLM / MiniMax / Qwen / OpenAI /
  OpenRouter / LMStudio 的多轮 tool-use 回放）的 bug 已在 SDK 1.0.5
  pin 修复。`max_turns > 1` 用上述任一 provider 的宿主升级即可，
  无需改代码。
- **`gemini-3.5-pro / -flash / -flash-lite` 进 `EngineCatalog`** ——
  3 个 Gemini 3.5 slug 成为 gemini-cli 引擎的 available_models 并
  出现在下拉。系统 gemini CLI 可能还不识别 3.5 slug；用 `sdk:` 标签的
  SDK 调用方今天就能驱动。

### 29.10 各项适用场景速查

| 你想做 … | 用 … |
|---|---|
| "告诉我这次调度到底改了啥" | 逐文件 diff 摘要（§29.1） |
| "撤销这次 run 的工作区改动" | revert 端点（§29.1） |
| "让 agent 在做 X 之前问用户" | `ask_user` 工具（§29.2） |
| "按上下文给 system prompt 加前缀" | 会话提醒（§29.3） |
| "这个 agent 必须只读" | 按 agent 规则集（§29.4） |
| "先 plan、批准后再 build" | Plan mode（§29.5） |
| "子 agent 必须继承父的 deny 集" | 子 agent 权限推导（§29.6） |
| "把长跑 shell 流到 UI" | PTY 会话（§29.7） |
| "把会话分享给同事" | 会话分享队列（§29.8） |
| "agent 想中途看 LSP 诊断" | LSP 工具（§29.9） |
| "长会话想要更小的压缩摘要" | `summary_prompt: structured`（§29.9） |
| "Gemini 3.5 thinking + grounding" | Gemini 3.5 per-call 选项（§29.9） |

---

## 30. Opus 4.8 + Grok + Cursor（1.0.0 / SDK 1.0.9）

1.0.0 稳定版把 SDK 约束提到 `^1.0.9`，并新增 Claude Opus 4.8 世代、
两条独立通道的 xAI Grok，以及两个新的订阅 CLI 引擎（Cursor Composer +
Grok Build）。下面的一切都是增量的 —— 没有 schema 变更，也不需要
config publish。

### 30.1 Claude Opus 4.8 路由

`claude-opus-4-8` 是 Anthropic 新的旗舰:它占据 `opus` 别名，原生
1M 上下文、interleaved thinking、fast mode 与 effort control，定价在 Opus
档（每 1M $15 / $75）。别名会自动解析:

```php
use SuperAICore\Services\ClaudeModelResolver;

ClaudeModelResolver::resolve('opus');            // 'claude-opus-4-8'
ClaudeModelResolver::resolve('claude-opus-4-8'); // passthrough
```

`claude` 引擎目录、`model_pricing`，以及 `squad` / `cli_squad` 的 **expert**
档全都指向 4.8。需要旧版 Opus 时显式 pin 即可 —— 旧 id 仍保留在目录里:

```php
app(\SuperAICore\Dispatcher::class)->dispatch([
    'prompt'  => $task,
    'backend' => 'anthropic_api',
    'model'   => 'claude-opus-4-8',   // or 'claude-opus-4-7' to pin
]);
```

### 30.2 两条 Grok 通道 —— API vs CLI（别搞混）

"Grok" 有两种接入方式，而且它们是刻意分开的:

| | Provider type `grok`（API） | Engine `grok_cli`（CLI） |
|---|---|---|
| Backend | `superagent` → SDK `GrokProvider` | `grok_cli`（binary `grok`） |
| Endpoint | `https://api.x.ai/v1` | grok.com（Grok Build） |
| Auth | `XAI_API_KEY` / `GROK_API_KEY` | `grok login`（`~/.grok`） |
| Default model | `grok-4.3`（1M ctx） | `grok-build` |
| Billing | 计量（usage） | 订阅（$0 行） |

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

`api:status` 探测的是 API 通道（仅过滤已配置 key 的）；CLI 通道出现在
`cli:status` 与 `/providers` 引擎卡片里。

### 30.3 Cursor Composer CLI 上手

```bash
curl https://cursor.com/install -fsS | bash   # installs cursor-agent
cursor-agent login                             # browser OAuth → ~/.cursor
./vendor/bin/superaicore cli:status            # confirms "logged in"
```

通过它 dispatch（按订阅计费；`--force` 自动批准工具，这样无头运行不会
卡在逐工具确认上）:

```php
app(\SuperAICore\Dispatcher::class)->dispatch([
    'prompt'  => $task,
    'backend' => 'cursor_cli',
    'model'   => 'composer-2.5-fast',   // or 'composer-2.5', 'auto', etc.
    'cwd'     => base_path(),            // mapped to --workspace
]);
```

MCP servers 通过 `McpManager::syncAllBackends()` 同步到
`.cursor/mcp.json`。没有浏览器的无头 runner 改为导出 `CURSOR_API_KEY`，
而不是 `cursor-agent login`。模型选择由 `CursorModelResolver` 驱动（其
`liveCatalog()` 会重新探测 `cursor-agent models`）。

### 30.4 Grok Build CLI + effort 控制

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

脚本化 spawn 使用 `--prompt-file`（没有 argv 长度限制）；后端发出标准
envelope，并带有解析好的 `usage.input_tokens` / `output_tokens`。Grok 有
原生子 agent（`--agents` / `create-subagent`），并通过 `grok mcp add`
管理 MCP（没有宿主可写的 config 文件）。

### 30.5 它们会出现在哪里

因为 `EngineCatalog`、`ProviderTypeRegistry` 与各引擎的 model resolver
喂给了一切，两个 CLI 都会自动出现在:`/providers` UI（引擎卡片、builtin
行、add-provider 下拉、version + login 徽标）、模型选择器
（`modelOptions('cursor')` / `modelOptions('grok')`）、`cli:status`、成本面板
（在 "Subscription engines" 下，$0 行）、Process Monitor（实时行 + 扫描
关键字），以及 `McpManager` sync。

---

## 31. kimi-cli + kimi-code 双轨支持（1.0.2 / SDK 1.0.10）

Moonshot 的新 `@moonshot-ai/kimi-code`（TypeScript）取代旧的 Python
`MoonshotAI/kimi-cli`。两者发布同一个 `kimi` binary，但 headless 接口不兼容，
因此 `kimi_cli` 后端现在会探测装的是哪一种并适配。约束同时从 SDK `^1.0.9` 升到
`^1.0.10`。纯增量 —— 无 schema 变更、无需 config publish；`kimi_cli` 这个 Dispatcher
backend id 不变。

### 31.1 变体探测 + 覆盖

`KimiCliBackend` 按 binary 缓存、用一次性 `kimi --help` 探测解析 dialect ——
legacy CLI 有 `--print` flag，kimi-code 没有。想在过渡期跳过探测可固定:

```php
// config/super-ai-core.php —— backends.kimi_cli.variant
'variant' => env('AI_CORE_KIMI_CLI_VARIANT', 'auto'),  // auto | kimi-code | kimi-cli
```

```bash
AI_CORE_KIMI_CLI_VARIANT=kimi-code   # 强制新 CLI（已升级）
AI_CORE_KIMI_CLI_VARIANT=kimi-cli    # 强制 legacy CLI（仍在 Python 版）
AI_CORE_KIMI_CLI_VARIANT=auto        # 默认:探测 `kimi --help`
```

无论哪种,调用方式一致 —— backend id 永不改变:

```php
app(\SuperAICore\Dispatcher::class)->dispatch([
    'prompt'  => $task,
    'backend' => 'kimi_cli',
    'model'   => 'kimi-k2-turbo',   // 可选；两种 dialect 都用 --model
]);
```

### 31.2 flag 对照表（为什么需要探测）

| | legacy `kimi-cli`（`--print`） | new `kimi-code`（`--prompt`） |
|---|---|---|
| headless 触发 | `--print`（布尔，隐式 yolo） | `--prompt`（print 模式） |
| 输出格式 | `--output-format=stream-json` | `--output-format stream-json` |
| 步数上限 | `--max-steps-per-turn N` | —（config.toml） |
| 每次运行 MCP | `--mcp-config-file F` | —（config.toml） |
| 工作目录 | `-w <dir>` | —（进程 cwd） |
| 未知选项 | 容忍 | 硬拒绝 |
| assistant `content` | block 数组（`text` / `think`） | 纯字符串 |
| resume 提示 | stderr | `{"role":"meta",…}` NDJSON 行 |

解析器兼容两种 `content` 形状,并忽略 kimi-code 的 `role:meta` resume 行,所以即便
探测判错也稳。legacy 命令打到 kimi-code 会被直接拒绝（未知 `--print`）—— 这正是
后端要按 dialect 适配 argv 的原因。kimi-code 没有每次运行的 `--mcp-config-file`
flag,所以传入的 `mcp_config_file` 会被静默丢弃（那边 MCP 由 config.toml 驱动）。

### 31.3 SDK 1.0.10 —— 透明的 Kimi/OpenAI 兼容修复

约束升到 `^1.0.10` 透明地惠及 `superagent` 后端,无需 SuperAICore 代码改动。直连
HTTP 的 `kimi` / `qwen` / `glm` / `deepseek` / `grok` / `openrouter` / `openai`
provider type 现在拿到:

- **流式 usage 计量** —— 发送 `stream_options.include_usage`,流式响应重新带 `usage`
  块。此前经这些类型的流式调用在 `ai_usage` 行和 `/providers` 面板上记 $0
  token/成本/缓存。
- **严格的工具 schema 归一化** —— 内联本地 `$ref`/`$defs`,给 enum-only 无类型属性
  补 `type`,使 MCP / Skill / Agent 工具能过 Moonshot 校验器。
- **Kimi 推理模型用 `max_completion_tokens`**（推理通道不再吃掉整个预算导致空答）
  + `reasoning_content` 跨轮回传。
- **按模型的能力发现** —— 从 provider `/models` 响应读 `thinking` / `vision` /
  `tools` / `structured_output` flag,喂给能力路由。
- **`SUPERAGENT_KIMI_SWARM_ENABLED`**（新,opt-in）—— 推测性的 Kimi Agent-Swarm
  REST 工具默认关闭。

双 CLI 后端的设计说明见 `docs/kimi-cli-backend.md` §8。

---

## 32. SmartFlow —— 跨 CLI 动态工作流 + superagent 联邦（1.0.5 / SDK 1.1.0）

SmartFlow 是 SuperAICore 对 Claude Code 内置 `Workflow` 引擎的移植,重新定位为
以 **CLI/backend** 而非 API 模型作为路由单元。它跟随 SuperAgent SDK 的跨*模型*
SmartFlow（SDK 1.1.0),但驱动的是 SuperAICore 已经管理的各 backend,并且可以把
一个子 flow **委派**给 SDK 的引擎,实现真正的跨 CLI → 跨模型联邦。纯增量:
Dispatcher、AgentSpawn 以及 Squad/Team/Smart/Auto 编排器都原封不动。完整参考:
[docs/smartflow.md](smartflow.md)。

### 32.1 原语

一个 flow body 是 `callable(Flow $flow): mixed`（或一个编译成它的 YAML 文件）。
`$flow` 暴露:`agent($prompt, $opts)`（一次跨 CLI 调用 → 用 `schema` 校验过的数组、
原始字符串,或 `$flow->SKIP`）、`call()`（延迟,用于 fan-out）、
`parallel([...])`（barrier;延迟调用经进程池并发执行）、
`pipeline($items, ...$stages)`（按 item / 按 stage）、`gate($name, $check,
$opts)`（带 `fallback`/`relay`/`required` 的验收）、`council($claim,
$lenses)`（视角多样的投票,每个 lens 可钉到不同 CLI）、
`budget`,以及 `log()`/`phase()`。`$opts` 的 key:`backend`（那个 CLI —— `provider`
是被接受的别名）、`model`、`role`（persona）、`system`、`schema`、
`temperature`、`max_tokens`、`label`、`provider_config`。

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

### 32.2 结构化输出 —— 3 层安全网

CLI 返回的是散文,所以请求的 `schema` 会被烤进 prompt,再通过三层逐级升级回收
出一个有效值 —— 整条回复的 JSON
(`native`/`submitted`) → 围栏 ```` ```json ```` 块（`submitted`） →
正则嗅探的对象/数组（`extracted`）—— 由零依赖的
`SchemaValidator` 校验。若都无法校验通过,调用返回 `SKIP` 哨兵而非崩溃,
于是 fan-out 可以用 `$flow->keep(...)` 把坏回复剔掉。

### 32.3 Resume、账本、彩排

每次运行都在 `~/.superaicore/flows/<runId>.jsonl` 下追加一份 JSONL 账本
（覆盖:`SUPERAICORE_FLOW_DIR` 或 `super-ai-core.smartflow.ledger_dir`）。每次
调用都根据你*声明*的内容获得一个内容寻址签名;`--resume
<runId>` 以零成本重放最长的未变更前缀,只从第一个变更的调用起实跑
（gate 占一个账本槽位,所以 gate 之后的调用保持对齐）。`--rehearse`
/ `--dry-run` 在**不调用任何 CLI**的情况下端到端跑完一条 flow —— schema 调用
拿到确定性的符合 schema 的桩,成本为 `$0` —— 所以 flow 在裸机上也可测。

### 32.4 联邦 —— 把子 flow 委派给 superagent

一条 SuperAICore flow 可以把子 flow 交给 superagent 自己的（跨模型）
SmartFlow。这正是预期的分层:SuperAICore 在各 CLI 间 fan-out;
`superagent` 这一支在各模型 provider 间 fan-out。两种模式:

```php
// named —— superagent 运行它自己的某条 flow;由它在各 provider 间自行分发
$findings = $flow->delegate('research-trio', [
    'flow_args'        => ['topic' => $flow->args['goal']],
    'delegate_provider' => 'openai',     // 引导 superagent 的模型档位
]);

// spec —— superagent 运行一条由 SuperAICore 编写的 flow（基于 provider、跨模型）
$brief = $flow->delegate('', ['spec' => [
    'name'  => 'mini-brief',
    'steps' => [
        ['name' => 'gather', 'role' => 'researcher', 'provider' => 'openai',    'prompt' => 'research {{args.q}}'],
        ['name' => 'write',  'role' => 'writer',     'provider' => 'anthropic', 'prompt' => "summarize:\n{{steps.gather.output}}"],
    ],
    'return' => 'write',
], 'flow_args' => ['q' => $flow->args['goal']]]);
```

被委派的调用复用同一套账本 / 预算 / resume / `parallel()` 机制,
所以它的开销联邦进父预算,并随父 flow 一起彩排。内联的 **spec 使用 SDK 的
schema**（step 在各模型 `provider` 间路由,而非 CLI),由 superagent 的引擎
执行。named 委派要求该 flow 存在于 SDK registry 中（`superagent flow list`）;
SDK 缺失或 flow 未知时优雅失败（空 / `SKIP`),不会拖垮父 flow。底层:
`SuperAICore\SmartFlow\Delegation` + `SuperAgentFlowBridge`（经
`SuperAgent\SmartFlow\FlowEngine` 进程内执行）。

### 32.5 YAML 编写

静态 flow 位于 `resources/flows/*.yaml`（由 `YamlFlowLoader` 编译）。
把你自己的放在 `./flows`、`./.superaicore/flows` 或
`super-ai-core.smartflow.flows_dir` 下。模板:`{{args.x}}`、
`{{steps.<name>.output}}`、`{{item}}`、点号路径。策略:`solo`
（默认）、`parallel`、`pipeline`、`gate`、`delegate`。

```yaml
- name: research            # hand the research leg to superagent
  strategy: delegate
  delegate: research-trio   # named SDK flow (or `spec: {...}` to author inline)
  provider: "{{args.research_provider}}"
  flow_args: {topic: "{{args.goal}}"}
```

内置 flow:`cross-cli-review`、`cross-cli-dev`、`cross-cli-council`、
`cross-cli-federated`。CLI:`superaicore flow list|show|plan|run`（以及
`php artisan flow ...`）。

### 32.6 何时该用 SmartFlow

Smart / Squad / Auto 用启发式拆解任务并路由子任务;
AgentSpawn 是给没有原生 Agent 工具的 CLI 用的 3 阶段 spawn-plan 协议。
当你想要*显式编写*的多步控制流（fan-out、pipeline、gate、council）、
按 step 的 CLI 路由、结构化输出、预算、彩排、resume 以及 superagent 联邦时,
就该用 **SmartFlow** —— 与 Claude Code 的 `Workflow` 同一形态,做成跨 CLI 的。

---

## 33. CLI skill 桥接 —— `superaicore:sync-cli` + `SkillLibrary` contract（1.0.6）

SuperAICore 早就把 **MCP** 桥接进了每个 CLI backend 的原生配置
(`McpManager::syncAllBackends()`,见 §13)。1.0.6 用一座通用的桥给
**skill + agent** 也做了同样的事,这样宿主就不必再为每个 CLI 手搓一套独立的
sync（一个 Codex wrapper 安装器、一个 Gemini 自定义命令 sync、一个 Kimi
翻译器……）。

职责的切分正是关键所在:

- **SuperAICore 知道 WHERE / HOW / WHEN。** 每个 CLI 把它的 skill 放在哪、
  如何*安全地*在那里装一个 wrapper（绝不写穿 symlink）、以及何时该重新 sync
  （仅当指纹漂移时）。
- **宿主知道 WHAT。** 它实现 `SuperAICore\Contracts\SkillLibrary` 并绑定它。
  SuperAICore 不携带任何宿主假设 —— 没有绑定时这座桥就是个静默的 no-op,
  所以这个包保持宿主无关。

### contract

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

在 service provider 里绑定它:

```php
$this->app->singleton(
    \SuperAICore\Contracts\SkillLibrary::class,
    \App\Services\SuperTeamSkillLibrary::class,
);
```

### 薄 wrapper 让源保持权威

推荐的 `skillWrapper()` 实现是一份**薄**的 SKILL.md,它 shell out 到宿主的
loader,而不是把真正的 skill 正文复制一份 —— 这样对权威的
`.claude/skills/<name>/SKILL.md` 的编辑无需重新 sync,wrapper 也永远不会
漂移出(或覆盖掉)源:

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

### 三种安装形态

`CliSkillBridge::BACKENDS` 把每个 backend 映射到一种模式 —— 加一个 CLI 只是
改一行:

| Mode | Backends | What lands | $HOME path |
|------|----------|-----------|-----------|
| `native_dir`  | codex, gemini, grok, cursor, qwen | one prefixed wrapper dir per skill (`super-team-<name>/SKILL.md`) | `.codex/skills`, `.gemini/skills`, `.grok/skills`, `.cursor/skills-cursor`, `.qwen/skills` |
| `instructions`| copilot, kimi, kiro | one digest file (how to load any skill on demand + the list) | `.copilot/super-team-skills.md`, `.kimi/…`, `.kiro/…` |
| `source`      | claude | nothing — reads the host's `.claude/skills` directly | — |
| `none`        | superagent | nothing | — |

### symlink 安全的写入（覆盖问题的修复）

这座桥**绝不写穿 symlink**。在写入任何 wrapper 目录、`SKILL.md`、摘要或
manifest 之前,它先对目标做 `is_link()` 检查,把陈旧的 link 先 unlink
（保留 link 的 *target* 完好无损）。这堵上了那个"写穿 symlink"的漏洞 ——
一个残留的 `~/.codex/skills/super-team-x -> …/.claude/skills/x` link 曾让
wrapper 的写入覆盖掉真正的源 skill 正文。

### 惰性 on-dispatch sync

每次 sync 都把 `fingerprint()` 盖进一份 per-backend manifest
(`.superteam-skill-sync.json`),连同它装过的 wrapper 列表一起。
`TaskRunner` 在每次 CLI 分发前都调用这座桥:

```php
// TaskRunner::ensureCliSkillsSynced() —— 把 codex_cli 归一化成 codex,尽力而为
(new \SuperAICore\Services\CliSkillBridge())->ensureSynced($engine);
```

`ensureSynced()` 很便宜:`needsSync()` 比对一个哈希,库没变就提前返回,
所以每次分发的开销只是一次比对。剪枝是 **manifest 范围内的** —— 只移除这座桥
之前装过、如今不再需要的 wrapper;用户自己的 skill 绝不会被碰。任何失败都被
吞掉,所以一次 sync 打嗝永远不会阻塞分发。

### `superaicore:sync-cli` —— 手动 / cron 的全量刷新

```bash
php artisan superaicore:sync-cli                       # skills + MCP → 每个已安装的 CLI
php artisan superaicore:sync-cli --skills-only         # 跳过 MCP 步骤
php artisan superaicore:sync-cli --mcp-only            # 只做 MCP（= mcp:sync-backends）
php artisan superaicore:sync-cli --backends=codex,gemini
php artisan superaicore:sync-cli --project-root=/path  # 覆盖 .mcp.json 的发现路径
```

skill 走 `CliSkillBridge`;MCP 复用 `McpManager::syncAllBackends()`。正常使用时
每次分发的 `TaskRunner` hook 已经把各 backend 保持新鲜 —— 在 git hook、cron
里,或编辑库之后,想做一次性刷新时再用这条命令。

### 编程式用法

```php
$bridge = new \SuperAICore\Services\CliSkillBridge();   // 解析已绑定的库
if ($bridge->active()) {
    $report = $bridge->syncAll(['codex', 'gemini']);    // [['backend'=>…,'installed'=>189,'pruned'=>0,'path'=>…], …]
}
```

---

## 34. Fable 5 与 Sonnet 5 —— 自适应请求面与 Anthropic effort 档位（1.0.11 / SDK 1.1.5）

SDK 1.1.5 把 **Claude Fable 5**（`claude-fable-5`）与 **Claude Sonnet 5**
（`claude-sonnet-5`）落地为一等 `anthropic` 模型。Fable 5 是 Anthropic 最强
模型 —— 1M 上下文、128K 最大输出、高清视觉 —— 定价高于 Opus 档,为每 1M
**$10/$50**;Sonnet 5 是新的 `sonnet` 旗舰,同属 Claude 5 代请求面,定价
**$3/$15**（2026-08-31 前首发价 $2/$10）。SuperAICore 1.0.11 把两者镜像进
`model_pricing` 与 `superagent` 引擎 seed;宿主侧无其他变化。

### 选择模型

```php
$result = $dispatcher->dispatch([
    'backend'         => 'superagent',
    'prompt'          => '为这次 schema 变更设计迁移方案。',
    'provider_config' => ['provider' => 'anthropic', 'model' => 'claude-fable-5'],
]);
```

SDK 侧别名:`fable` / `claude-fable` / `claude-fable-5` 解析到 Fable 5
（独立 family,与 `opus` 不冲突）;`sonnet` 及所有 sonnet 系别名现在解析到
Sonnet 5。零配置 `anthropic` 解析到 `claude-opus-4-8`。先前所有 Claude id
继续可用。

### 仅自适应的请求面（SDK 替你处理）

Claude 5 代只接受**自适应思考**。SDK 的 `AnthropicProvider` 会为 Fable 5、
Sonnet 5 与 Opus 4.6+ 自动调整请求形态:

- 发送 `thinking: {type: "adaptive"}` —— 绝不发 `budget_tokens`。配置了
  `thinking_budget_tokens` 简写时会被改道到自适应形态,而不是 400。
- 丢弃 `temperature` / `top_p` / `top_k`（Fable 5 / Sonnet 5 / Opus 4.7 /
  4.8 会拒绝它们）。
- 对 4.6+ 家族与 Claude 5 代跳过尾部 assistant prefill。

这些防护顺带修复了 Opus 4.7/4.8 在 `budget_tokens` 与采样参数上已存在的
潜在 400 —— 继承的 bugfix,无需任何操作。

### Anthropic `reasoning_effort` 档位

`AnthropicProvider` 现在实现 `SupportsReasoningEffort`（与 MiniMax M3、
GLM-5.2 并列）,把逐调用选项映射到 Anthropic GA 的 `output_config.effort`:

```php
$result = $dispatcher->dispatch([
    'backend'          => 'superagent',
    'prompt'           => '审计这个迁移的竞态条件。',
    'provider_config'  => ['provider' => 'anthropic', 'model' => 'claude-fable-5'],
    'reasoning_effort' => 'max',   // off | low | medium | high | xhigh | max
]);
```

- 支持的模型:Fable 5、Sonnet 5、Opus 4.5+、Sonnet 4.6。
- `off` / 未知值 / 不支持的模型 → 完全不产生 `output_config`,杂散的 effort
  绝不 400。
- 与 GLM、MiniMax 一样,该选项原样经 `SuperAgentBackend` 透传 —— 本就是
  通用转发（§28）。

### 成本核算

`model_pricing` 按官方价承载:Fable 5 $10/$50、Sonnet 5 $3/$15、现役 Opus
（`4-5`→`4-8`）**$5/$25**（从过期的 $15/$75 重定价 —— 仅带日期的
`claude-opus-4-20250514` 快照保留历史价）、Haiku 4.5 $1/$5。若宿主发布过旧的
配置副本,请重新发布（`php artisan vendor:publish --tag=super-ai-core-config`）,
否则看板会继续把 Opus 的成本算高 3 倍。SuperAICore 的 `squad.tiers` 映射有意
保持不变（`expert` 仍指向 `claude-opus-4-8`）;SDK 自己的 Squad EXPERT 档
路由到 `claude-fable-5` —— 想要宿主侧同样分档,自行把配置指向
`claude-fable-5`。

数据保留提示:Fable 5 的 API 流量目前在 Anthropic 侧带 30 天保留窗口 ——
把受监管的工作负载路由过去之前,先核对你的合规要求。

---

## 35. ai-dispatch 对齐 —— 短名派单、会话续聊、运行存档（1.1.0）

借鉴 [rennzhang/ai-dispatch](https://github.com/rennzhang/ai-dispatch)；完整
设计笔记见 [docs/ai-dispatch-parity.md](ai-dispatch-parity.md)。一个短名解析
为有序的 `{backend, model}` 候选池，`superaicore send` 依次尝试并透明降级；
会话可真正续聊；每次派单都有存档。

### 自定义别名池

`AliasRouter` 优先级：`super-ai-core.dispatch.aliases`（用户配置）→ 内置注册
表（`fable`/`opus`/`sonnet`/`haiku`、`codex`、`gemini-pro` 等）→ backend 名
透传 → 模型 id 推断 → 默认 backend。配置支持完整 map、紧凑的
`backend:model` 字符串或单个字符串：

```php
'dispatch' => [
    'aliases' => [
        'reviewer' => [
            ['backend' => 'claude_cli', 'model' => 'opus'],
            'gemini_cli:pro',                        // 第二顺位
        ],
        'mimo' => 'superagent:mimo-v2.5-pro',
    ],
    // 允许落到下一候选的失败类别。与 task_fallback.failure_classes 共用
    // 分类表；其余类别（tool_policy / validation / 未匹配的运行时错误）
    // 一律 fail-closed。
    'retry_on_classes' => ['quota', 'rate_limit', 'auth', 'network'],
],
```

### 结果契约

`send`/`resume --json-result` 返回一个扁平、Agent 可直接消费的对象 —— 永远
不要假设请求的目标就是应答者；要读 `backend_used` / `model_used` /
`route_trace[]`（每个候选的 status、reason、failure_class、耗时）/
`degraded` + `degrade_reason` / `failure_class` / `session_id` / `run_id`。
派单走正常的 Dispatcher 流式路径，usage 行（`usage_source:
dispatch_send`）、成本归因、tracing、进程监控全都可见。

### 会话续聊机制

- `ClaudeCliBackend` —— 传 `resume_session_id` 时用 `--resume <id>` 替代
  `--session-id`；`generate()`/`stream()` 信封现在都带 `session_id`。
- `CodexCliBackend` —— 从 JSONL 流捕获 `thread.started` → `thread_id`（作为
  `session_id` 暴露），续聊走 `codex exec resume <id>`。
- `superaicore resume --session-id <id> "<增量问题>"` 从运行存档反查所属
  backend/model；续聊绝不会落到别的引擎。引擎可能在每次 resume 时派生新
  id —— 后续追问永远用**最新**结果的 `session_id`。

在 PHP（Laravel 宿主）里，同一套循环就是一个服务：

```php
use SuperAICore\Services\{AliasRouter, BackendRegistry, CostCalculator,
    Dispatcher, DispatchSender, RunStore};

$backends = new BackendRegistry();
$sender   = new DispatchSender(new Dispatcher($backends, new CostCalculator()), $backends, new RunStore());
$route    = (new AliasRouter($backends))->resolve('reviewer');

$result = $sender->send($route['requested'], $route['source'], $route['candidates'],
    '评审 HEAD~1 的 diff', ['cwd' => base_path(), 'task_name' => 'review']);
// $result['ok'], $result['route_trace'], $result['session_id'], …
```

### 运行存档、偏好文件、反向 SKILL、doctor

- `~/.superaicore/runs`（配置 `dispatch.runs_path` / 环境变量
  `AI_CORE_RUNS_PATH`）—— 每次派单一个 JSON；`superaicore runs
  list|show <id>`；`RunStore::findBySession()` 支撑 resume。
- `~/.superaicore/preferences.md`（配置 `dispatch.preferences_path` /
  环境变量 `AI_CORE_PREFERENCES_PATH`）—— 自然语言的场景→模型偏好，由发起
  调用的 Agent 阅读；SuperAICore 从不解析它。`superaicore preferences
  init|show|path`。
- `superaicore skill:install-dispatch --agent claude|codex|gemini` —— 把
  `resources/skills/superaicore-dispatch/SKILL.md` 装进各 Agent 的 skill
  目录，让外部 Agent 把任务派**进** SuperAICore（与 `superaicore:sync-cli`
  方向相反）。
- `superaicore doctor [--json]` —— 引擎 + 认证、已注册 backend、别名可解析
  性、偏好文件、运行存档可写性，一次跑完。

---

## 另见

- [docs/ai-dispatch-parity.md](ai-dispatch-parity.md) —— 短名派单 / 会话续聊 / 运行存档 / 偏好文件 / doctor（1.1.0）
- [docs/smartflow.md](smartflow.md) —— SmartFlow 跨 CLI 工作流 + superagent 联邦（1.0.5）
- [docs/idempotency.md](idempotency.md) —— 60 秒去重窗口、repository 层契约
- [docs/streaming-backends.md](streaming-backends.md) —— 各 CLI 流格式
- [docs/task-runner-quickstart.md](task-runner-quickstart.md) —— `TaskRunner` 选项参考
- [docs/spawn-plan-protocol.md](spawn-plan-protocol.md) —— codex/gemini agent 模拟
- [docs/mcp-sync.md](mcp-sync.md) —— catalog 驱动的 MCP sync
- [docs/api-stability.md](api-stability.md) —— SemVer 契约
