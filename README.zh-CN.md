# forgeomni/superaicore

[![tests](https://github.com/ForgeOmni/SuperAICore/actions/workflows/tests.yml/badge.svg)](https://github.com/ForgeOmni/SuperAICore/actions/workflows/tests.yml)
[![license](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![php](https://img.shields.io/badge/php-%E2%89%A58.1-blue.svg)](composer.json)
[![laravel](https://img.shields.io/badge/laravel-10%20%7C%2011%20%7C%2012-orange.svg)](composer.json)

[English](README.md) · [简体中文](README.zh-CN.md) · [Français](README.fr.md)

用于统一调度七种 AI 执行引擎的 Laravel 包 —— **Claude Code CLI**、**Codex CLI**、**Gemini CLI**、**GitHub Copilot CLI**、**AWS Kiro CLI**、**Moonshot Kimi Code CLI**、**SuperAgent SDK**。内置独立于框架的 CLI、基于能力（capability）的调度器、MCP 服务器管理、使用量记录、成本分析，以及一套完整的后台管理 UI。

在干净的 Laravel 项目中可独立运行。UI 可选、可完全替换 —— 既能嵌入宿主应用（例如 SuperTeam），也可以在仅使用服务层时关掉。

## 目录

- [与 SuperAgent 的关系](#与-superagent-的关系)
- [特性](#特性)
  - [执行引擎 + provider 类型](#执行引擎--provider-类型)
  - [Skill 与 sub-agent 运行器](#skill-与-sub-agent-运行器)
  - [CLI 安装器与健康检查](#cli-安装器与健康检查)
  - [Dispatcher 与流式输出](#dispatcher-与流式输出)
  - [模型目录](#模型目录)
  - [Provider type 系统](#provider-type-系统)
  - [使用量追踪与成本](#使用量追踪与成本)
  - [幂等与链路追踪](#幂等与链路追踪)
  - [MCP 服务器管理](#mcp-服务器管理)
  - [SuperAgent SDK 集成](#superagent-sdk-集成)
  - [弱模型 agent-spawn 加固](#弱模型-agent-spawn-加固)
  - [进程监控与后台 UI](#进程监控与后台-ui)
  - [宿主集成](#宿主集成)
- [环境要求](#环境要求)
- [安装](#安装)
- [CLI 快速上手](#cli-快速上手)
- [PHP 快速上手](#php-快速上手)
- [架构](#架构)
- [高级用法](#高级用法)
- [配置](#配置)
- [许可证](#许可证)

## 与 SuperAgent 的关系

`forgeomni/superaicore` 和 `forgeomni/superagent` 是**兄弟包，并非父子依赖关系**：

- **SuperAgent** 是一个轻量级的 PHP 进程内 SDK，专注于驱动单个 LLM 的 tool-use 循环（一个 agent、一段会话）。
- **SuperAICore** 是 Laravel 级的编排层 —— 负责挑选后端、解析 provider 凭证、按能力路由、记录用量、计算成本、管理 MCP 服务器，并提供后台 UI。

**SuperAICore 并不依赖 SuperAgent 才能工作。** SDK 只是众多后端之一。六个 CLI 引擎和三个 HTTP 后端都不需要它，且 `SuperAgentBackend` 在 SDK 缺失时会通过 `class_exists(Agent::class)` 检查优雅地报告为不可用。在 `.env` 中设置 `AI_CORE_SUPERAGENT_ENABLED=false`，Dispatcher 会自动回退到其余后端。

`composer.json` 中的 `forgeomni/superagent` 依赖只是为了开箱即用地启用 SuperAgent 后端。若从不使用它，可在宿主项目 `composer install` 之前从 `composer.json` 中移除 —— SuperAICore 其余代码不会引用 SuperAgent 命名空间。

## 特性

下面每个特性都标注了它开始支持的版本。未标注的特性在 0.6.0 之前就已存在。

### 执行引擎 + provider 类型

- **七个执行引擎**，统一实现同一套 `Dispatcher` 契约：
  - **Claude Code CLI** —— provider 类型：`builtin`（本地登录）、`anthropic`、`anthropic-proxy`、`bedrock`、`vertex`。
  - **Codex CLI** —— `builtin`（ChatGPT 登录）、`openai`、`openai-compatible`。
  - **Gemini CLI** —— `builtin`（Google OAuth）、`google-ai`、`vertex`。
  - **GitHub Copilot CLI** —— 仅 `builtin`（`copilot` 二进制自行处理 OAuth / keychain / 刷新）。原生读取 `.claude/skills/`（零翻译直通）。**订阅计费** —— 仪表盘独立统计。
  - **AWS Kiro CLI**（0.6.1+）—— `builtin`（本机 `kiro-cli login` 登录态）、`kiro-api`（DB 存的 key 注入成 `KIRO_API_KEY` 走 headless 模式）。CLI 后端里自带能力最全的一家 —— 原生 agents、skills、MCP，以及**原生 subagent DAG 编排**（不走 `SpawnPlan` 模拟）。Skill 直接复用 Claude 的 `SKILL.md` 格式。**按 credits 订阅计费**（Pro / Pro+ / Power 套餐）。
  - **Moonshot Kimi Code CLI**（0.6.8+）—— `builtin`（`kimi login` 走 `auth.kimi.com` OAuth）。与 SDK 内置的直连 HTTP `KimiProvider` 互补，专门覆盖 OAuth 订阅的 agentic-loop 路径，和 `anthropic_api` ↔ `claude_cli` 是同样的分工。默认走 Kimi 原生 `Agent` fanout；需要切到 AICore 三阶段 Pipeline 时设 `use_native_agents=false`。**订阅计费** —— Moonshot Pro / Power。
  - **SuperAgent SDK** —— provider 类型：`anthropic`、`anthropic-proxy`、`openai`、`openai-compatible`，加上 `openai-responses`（0.7.0+）和 `lmstudio`（0.7.0+）。
- **`openai-responses` provider 类型**（0.7.0+）—— 通过 SDK 的 `OpenAIResponsesProvider` 走 `/v1/responses`。依据 `base_url` 形状自动识别 Azure OpenAI 部署（自动追加 `api-version=2025-04-01-preview`；可通过 `extra_config.azure_api_version` 覆盖）。若此行没存 API key 而是 `extra_config.access_token`（来自宿主 ChatGPT-OAuth 流程），SDK 会自动把 base URL 切到 `chatgpt.com/backend-api/codex`，让 Plus / Pro / Business 订阅用户走自家订阅配额。
- **`lmstudio` provider 类型**（0.7.0+）—— 本地 LM Studio 服务（默认 `http://localhost:1234`）。走 OpenAI-compat 接线，无需真 API key —— SDK 自动合成占位 `Authorization` 头。
- **十个 dispatcher 适配器**对应七个引擎（`claude_cli`、`codex_cli`、`gemini_cli`、`copilot_cli`、`kiro_cli`、`kimi_cli`、`superagent`、`anthropic_api`、`openai_api`、`gemini_api`）—— `builtin` / `kiro-api` 走 CLI 适配器，API Key 走 HTTP 适配器。CLI 也可以直接指定这些适配器名。
- **`EngineCatalog` 单一数据源** —— 引擎的标签、图标、Dispatcher 后端、支持的 provider 类型、可用模型、声明式的 `ProcessSpec`（二进制名、版本/登录状态参数、prompt/output/model flag、默认 flag）都集中在一个 PHP 服务里。新增 CLI 引擎只需改 `EngineCatalog::seed()`，UI/扫描/开关矩阵全部自动跟进。宿主通过 `super-ai-core.engines` 配置覆盖。`modelOptions($key)` / `modelAliases($key)`（0.5.9+）驱动宿主应用模型下拉。

### Skill 与 sub-agent 运行器

- **Skill 与 sub-agent 自动发现** —— Skill 从三个来源（项目 > plugin > 用户）、agent 从两个来源（项目 > 用户）自动扫描，每个都成为一等 CLI 子命令（`skill:list`、`skill:run`、`agent:list`、`agent:run`）。
- **跨后端原生执行** —— `--exec=native` 在指定后端的 CLI 上原生跑；`CompatibilityProbe` 标记不兼容的 skill；`SkillBodyTranslator` 把规范工具名（`` `Read` `` → `read_file` 等）重写并注入后端 preamble（Gemini / Codex）。
- **带副作用硬锁定的 fallback 链** —— `--exec=fallback --fallback-chain=gemini,claude` 依次尝试，跳过不兼容的跳，**硬锁定**在第一个触碰 cwd 的跳上（mtime 差分 + stream-json `tool_use` 事件）。
- **`gemini:sync`** —— 把 skill / agent 镜像成 Gemini 自定义命令（`/skill:init`、`/agent:reviewer`）。通过 `~/.gemini/commands/.superaicore-manifest.json` 尊重用户编辑。
- **`copilot:sync`** —— 把 agent 镜像成 `~/.copilot/agents/*.agent.md`。在 `agent:run --backend=copilot` 前自动触发。
- **`copilot:sync-hooks`** —— 把 Claude 风格 hooks（`.claude/settings.json:hooks`）合并到 Copilot 的 `~/.copilot/config.json:hooks`。
- **`copilot:fleet`** —— 同一任务并发分发给 N 个 Copilot sub-agent，聚合每 agent 结果，每子进程都注册到 Process Monitor。
- **`kiro:sync`**（0.6.1+）—— 把 Claude agent frontmatter 翻译成 `~/.kiro/agents/*.json`，交给 Kiro 原生 DAG 编排执行。
- **`kimi:sync`**（0.6.8+）—— 把 `.claude/agents/*.md` 的 tool 列表翻译成 `~/.kimi/agents/*.yaml` + `~/.kimi/mcp.json`（Claude 兼容）。`claude:mcp-sync` 自动 fan-out 到 Kimi。

### CLI 安装器与健康检查

- **`cli:status`** —— 每家 CLI 的安装/登录状态与安装提示。
- **`cli:install [backend] [--all-missing]`** —— 走规范包管理器（`npm` / `brew` / `script`）安装缺失项，默认带确认。显式触发 —— 永不因为调度失败自动安装。
- **`api:status`**（0.6.8+）—— 对直连 HTTP API provider（anthropic / openai / openrouter / gemini / kimi / qwen / glm / minimax）做 5 秒 cURL 探测，每 provider 返回 `{ok, latency_ms, reason}`，让运维一眼分清 auth 被拒（401/403）、网络超时、key 没配。`--all` / `--providers=a,b,c` / `--json` 支持。与 `cli:status` 平行。

### Dispatcher 与流式输出

- **基于能力的路由** —— `Dispatcher::dispatch(['task_type' => 'tasks.run', 'capability' => 'summarise'])` 通过 `RoutingRepository` → `ProviderResolver` → 回退链解析出正确的后端 + provider 凭证。
- **`Contracts\StreamingBackend`**（0.6.6+）—— 每个 CLI 后端都能通过 `onChunk` callback 流式接收 chunks，同时 tee 到磁盘、登记 `ai_processes` 行供 Monitor UI 跟读。`Dispatcher::dispatch(['stream' => true, ...])` 透明 opt-in。支持每次调用配 `timeout` / `idle_timeout` / `mcp_mode`（claude 用 `'empty'` 防止全局 MCP 卡退出）。详见 `docs/streaming-backends.md`。
- **`Runner\TaskRunner` —— 一行调用执行任务**（0.6.6+）—— `Dispatcher::dispatch(['stream' => true, ...])` 的封装，返回类型化 `TaskResultEnvelope`（success / output / summary / usage / cost / log file / spawn report）。把宿主约 150 行"build prompt → spawn → tee log → extract usage → wrap result"胶水折叠成一次调用。六个 CLI 接口完全一致。详见 `docs/task-runner-quickstart.md`。
- **`AgentSpawn\Pipeline` —— codex/gemini 的 spawn-plan 协议**（0.6.6+）—— 三阶段编排（preamble → 并行 fanout → consolidation 复调）内置在 SuperAICore。`TaskRunner` 见到 `spawn_plan_dir` 自动激活。新 CLI 实现 `BackendCapabilities::spawnPreamble()` + `consolidationPrompt()` 一次即可继承。详见 `docs/spawn-plan-protocol.md`。
- **每个 CLI 的 per-call `cwd`**（0.6.7+）—— 宿主 PHP 从 `web/public` 起也能 spawn 到能正确找到项目根下 `artisan` + `.claude/` 的 `claude`。Claude 专属选项（`permission_mode`、`allowed_tools`、`session_id`）让 headless 调用方绕过交互审批、限制工具面、显式传会话 id。
- **PHP-FPM 里起 Claude CLI 现在可用**（0.6.7+）—— `ClaudeCliBackend` 在子进程 env 里主动移除 `CLAUDECODE` / `CLAUDE_CODE_ENTRYPOINT` / … 以免触发 claude 的递归守卫。macOS 上 `builtin` 登录新增 fallback:通过 `security find-generic-password` 读出 OAuth token 注入成 `ANTHROPIC_API_KEY` —— 这是 Web worker 唯一能走通的路径。
- **`Contracts\ScriptedSpawnBackend`**（0.7.1+）—— `StreamingBackend` 的兄弟契约，为 nohup / 后台 job 把子进程 detach 出去、然后轮询 log 的宿主服务。`prepareScriptedProcess([...])` 返回一个配置好的 `Symfony\Component\Process\Process`：把 `prompt_file` 通过 stdin 喂进 CLI、合并 stdout+stderr 落到 `log_file`、做 env scrub + capability transform（Gemini 工具名重写）、遵守 `timeout` / `idle_timeout`。`streamChat($prompt, $onChunk, $options)` 是阻塞式的 one-shot 对照实现 —— argv 组装、prompt 走 stdin 还是 argv、输出解析、ANSI 去色（Kiro / Copilot）都由 backend 自己负责。0.7.1 起六个 CLI 后端（claude / codex / gemini / copilot / kiro / kimi）全部实现该契约；宿主通过 `BackendRegistry::forEngine($engineKey)` 一次多态调用替掉 per-backend 的 `match` 语句。`Support\CliBinaryLocator`（单例）统一处理 CLI 二进制的文件系统探测（`~/.npm-global/bin`、`/opt/homebrew/bin`、nvm 路径、Windows 的 `%APPDATA%/npm`）。`Backends\Concerns\BuildsScriptedProcess` trait 为实现者提供共享的 wrapper 脚本构建工具。详见 [docs/host-spawn-uplift-roadmap.md](docs/host-spawn-uplift-roadmap.md)。

### 模型目录

- **动态模型目录**（0.6.0+）—— `CostCalculator`、`ClaudeModelResolver`、`GeminiModelResolver` 和 `EngineCatalog::seed()` 的 `available_models` 都回退到 SuperAgent 的 `ModelCatalog`（内置 `resources/models.json` + 用户覆盖 `~/.superagent/models.json`）。
- **`super-ai-core:models update`**（0.6.0+）—— 拉取 `$SUPERAGENT_MODELS_URL`，不需要 `composer update` 就能刷新所有 Anthropic / OpenAI / Gemini / Bedrock / OpenRouter 的价格和模型 ID。
- **`super-ai-core:models refresh [--provider <p>]`**（0.6.9+）—— 把每个 provider 的实时 `GET /models` 拉进 `~/.superagent/models-cache/<provider>.json` 的 per-provider overlay cache。支持 anthropic / openai / openrouter / kimi / glm / minimax / qwen。overlay 在用户 override 之上、`register()` 运行时注册之下，bundled pricing 在厂商 `/models` 不返回定价时保留。`status` 输出多一行 `refresh cache`。

### Provider type 系统

- **`ProviderTypeRegistry` + `ProviderEnvBuilder`**（0.6.2+）—— 每种 provider type（Anthropic / OpenAI / Google / Kiro / …）的 label、图标、表单字段、env 键名、base_url env、允许的 backend、`extra_config → env` 映射全部收口到一个内置 registry。`/providers` UI + CLI backend env 注入 + `AiProvider::requiresApiKey()` 共用一份真相。宿主通过 `super-ai-core.provider_types` 覆盖；新 type 在 `composer update` 之后零代码改动自动出现。
- **`sdkProvider` 字段**（0.7.0+）—— 外壳型 type（`anthropic-proxy`、`openai-compatible`）现在显式声明它们路由到的 SDK `ProviderRegistry` key。`SuperAgentBackend::buildAgent()` 在 `provider_config.provider` 为空时查 descriptor，修复了长期以来外壳型 type 默默 fallback 到 `'anthropic'` 的 bug。
- **`http_headers` / `env_http_headers` 字段**（0.7.0+）—— 基于 SDK 0.9.1 的 `ChatCompletionsProvider` 接口做声明式 HTTP header 注入。`http_headers` 是字面量；`env_http_headers` 引用 env 变量，env 变量未设或为空时静默跳过。宿主可以零代码改动注入 `OpenAI-Project`、`LangSmith-Project`、`OpenRouter-App` 等。

### 使用量追踪与成本

- **`ai_usage_logs`** —— 每次调用将 prompt / response tokens、耗时、成本写入。0.6.2+ 起每行还带 `shadow_cost_usd` 与 `billing_model`，让订阅型引擎（Copilot、Kiro、Claude Code builtin）在仪表盘上呈现有意义的"如果按 token 计费"USD 估值。
- **Cache-aware shadow cost**（0.6.5+）—— `cache_read_tokens` 按 0.1× 计、`cache_write_tokens` 按 1.25× 计（catalog 里有显式行时优先用）。重 cache 的 Claude 会话不再虚高 ~10×。
- **CLI 自报 `total_cost_usd`**（0.6.5+）—— 当后端响应里带 `total_cost_usd`（Claude CLI 就带），Dispatcher 直接采纳为计费金额并在 `metadata.cost_source=cli_envelope` 打标。重要，因为只有 CLI 知道这次会话走的是订阅还是 API key。
- **`UsageRecorder`** 宿主侧 runner 回写入口（0.6.2+）—— 对 `UsageTracker` + `CostCalculator` 的薄封装。宿主自己 spawn CLI 的场景下，每轮结束调一次就能写入一条 `ai_usage_logs`。
- **`CliOutputParser`** —— 从已捕获的 stdout 中抽出 `{text, model, input_tokens, output_tokens, …}`（`parseClaude()` / `parseCodex()` / `parseCopilot()` / `parseGemini()`）。
- **`MonitoredProcess::runMonitoredAndRecord()`**（0.6.5+）—— 既有 `runMonitored()` trait 的 opt-in 版；子进程退出时自动 buffer stdout → 解析 → 经 `UsageRecorder` 写一行 `ai_usage_logs`。解析失败不会冒泡。
- **成本仪表盘** —— 按模型价格表汇总 USD 费用，带图表。0.6.2+ 新增 "By Task Type" 卡片、每行 `usage`/`sub` 计费模式徽章、每张分组表的 shadow cost 列。0-token 行与 `test_connection` 行默认隐藏。

### 幂等与链路追踪

- **`ai_usage_logs.idempotency_key` 60 秒去重窗口**（0.6.6+）—— `EloquentUsageRepository::record()` 看见 `idempotency_key` 时，查 60s 内同 key 的最新行，命中就返回它的 id 不重复插入。`Dispatcher::dispatch()` 自动用 `"{backend}:{external_label}"` 作为 key，所以宿主双写同一逻辑 turn 会自动合并成一行，零代码改动。需要 `php artisan migrate` 加列 + 复合索引。详见 `docs/idempotency.md`。
- **key 通过 SDK 往返**（0.7.0+）—— Dispatcher 现在在 `generate()` 前就算好 key 并注入 `$callOptions['idempotency_key']`；`SuperAgentBackend` 透传到 `Agent::run($prompt, ['idempotency_key' => $k])`，SDK 0.9.1 把 key 原样（截断 80 字符）回显到 `AgentResult::$idempotencyKey`。后端把它塞进 envelope 的 `idempotency_key` 字段；Dispatcher 写 `ai_usage_logs` 时优先用 envelope 回显的值。效果:Dispatcher 和 UsageRecorder 即便跑在不同 PHP 进程，也能观察到 SDK 看到的同一个 key。
- **W3C `traceparent` / `tracestate` 透传**（0.7.0+）—— `Dispatcher::dispatch()` 支持 `traceparent: '<w3c-string>'` 选项。`SuperAgentBackend` 转发给 `Agent::run()`，SDK 把它放进 Responses API 的 `client_metadata`，OpenAI 侧日志就能和宿主分布式 trace 关联上。也接受 `tracestate` 和预构建的 `TraceContext` 实例。空字符串会被过滤掉。

### MCP 服务器管理

- **UI 驱动管理器** —— 在后台 UI 安装、启用、配置 MCP 服务器。
- **catalog 驱动的同步**（0.6.8+）—— `claude:mcp-sync` 读 `.mcp-servers/mcp-catalog.json` + 薄的 `.claude/mcp-host.json` 映射，把正确的 server 子集分发到项目 `.mcp.json`、每个 agent `.claude/agents/*.md` frontmatter 里的 `mcpServers:` 块，以及每个已安装 CLI backend 的 user-scope 配置。`mcp:sync-backends` 是给手改 `.mcp.json` 或 file-watcher 自动同步用的独立入口。非破坏性:按 sha256 manifest 标记用户编辑并保留。详见 `docs/mcp-sync.md`。
- **mcp.json 的 OAuth 辅助方法**（0.6.9+）—— `McpManager::oauthStatus(key)` / `oauthLogin(key)` / `oauthLogout(key)` 薄封装 SDK 0.9.0 的 `McpOAuth`，针对 mcp.json 条目里声明了 `oauth: {client_id, device_endpoint, token_endpoint, scope?}` 块的 MCP 服务器。前端可以给每个这类服务器画一个 OAuth 按钮。
- **可移植的 `.mcp.json` 写入**（0.8.1+）—— 设置 `AI_CORE_MCP_PORTABLE_ROOT_VAR=SUPERTEAM_ROOT`（或任意你的 MCP runtime 会导出的环境变量名）后，所有 `McpManager::install*()` 写入路径会输出裸命令名（`node` / `php` / `uvx` / `uv` / `python`），并把项目根下的绝对路径改写成 `${SUPERTEAM_ROOT}/<rel>` 占位符。生成出来的 `.mcp.json` 跨机器 / 跨用户 / 跨容器复制或同步都不会被 `which()` / `PHP_BINARY` 再次污染。出口到 per-machine 目标时（Codex `~/.codex/config.toml`、Gemini / Claude / Copilot / Kiro / Kimi 的 user-scope MCP 配置、`codex exec -c` 运行时 flag）会把占位符再展开回绝对路径，所以那些不解析 `${VAR}` 的 backend 也能正常 spawn。默认值依然是 `null`，未启用的宿主行为完全不变。详见 `docs/advanced-usage.md` §13。

### SuperAgent SDK 集成

- **真正的 agentic loop**（0.6.8+）—— `SuperAgentBackend` 支持 `max_turns`、`max_cost_usd` → `Agent::withMaxBudget()` 在 loop 内硬卡预算、`allowed_tools` / `denied_tools` 工具面过滤、`mcp_config_file`（加载 `.mcp.json`，`finally{}` 中 disconnect）、`provider_config.region` 通过 `ProviderRegistry::createWithRegion()` 路由以适配 SuperAgent 的 Kimi / Qwen / GLM / MiniMax 多区域分流。envelope 新增 `usage.cache_read_input_tokens` / `cache_creation_input_tokens`、`cost_usd`（SDK 自算）、`turns`。
- **`AgentTool` productivity 透传**（0.6.8+）—— 调用方开启 SDK 子 agent dispatch（`load_tools: ['agent', …]`）时，envelope 会多一个可选 `subagents` key，携带 `AgentTool` 的 productivity 信息（`filesWritten` / `toolCallsByName` / `productivityWarning` / `status: completed|completed_empty`）。
- **三个 0.9.0 选项透传**（0.6.9+）—— `extra_body`（深合并到每个 `ChatCompletionsProvider` 请求 body 顶层）、`features`（走 SDK `FeatureDispatcher`；常用键:`prompt_cache_key.session_id`、`thinking.*`、`dashscope_cache_control`）、`loop_detection: true|array`（把流处理 handler 包进 `LoopDetectionHarness`）。便捷写法:`prompt_cache_key: '<sessionId>'` 直接当 session_id shorthand。
- **分类的 `ProviderException` 子类**（0.7.0+）—— `SuperAgentBackend::generate()` 分别捕获 SDK 0.9.1 的六个子类（`ContextWindowExceeded` / `QuotaExceeded` / `UsageNotIncluded` / `CyberPolicy` / `ServerOverloaded` / `InvalidPrompt`），每个都以稳定的 `error_class` tag + `retryable` 标记记日志。契约不变（依然返回 `null`）；子类可 override `logProviderError()` seam 把分类塞到 envelope 里做更精细的路由。
- **SDK 锁到 0.9.1**（0.7.0+）—— Composer 约束 `^0.9.1`。幂等 key 往返、W3C `traceparent`、`http_headers` / `env_http_headers`，以及 SDK 端的 `openai-responses` + Azure 识别 + LM Studio —— 都不需要额外 SDK 级胶水就能吃到。

### 弱模型 agent-spawn 加固

五层防线（0.6.8+）叠上去，避免 Gemini Flash / GLM Air 这类弱模型违约后污染 consolidator:

1. **`SpawnPlan::appendGuards()`** —— 宿主端往每个子 agent 的 `task_prompt` 末尾追加 guard 块（六条硬规则:不越界、不写 consolidation 文件、语言统一含文件名、扩展名白名单、`_signals/<name>.md` 固定路径、工具失败别道歉）。CJK 正则检测中英文自动分叉。
2. **`SpawnPlan::fromFile()` canonical ASCII `output_subdir`** —— 强制 `output_subdir = agent.name`，Flash 在 zh-CN 下发 `首席执行官` 代替 `ceo-bezos` 不再能破坏审计走查。
3. **`Pipeline::cleanPrematureConsolidatorFiles()`** —— fanout 前先扫 `$outputDir` 顶层，把早产的 `摘要.md` / `思维导图.md` / `流程图.md` + 英文版清掉。
4. **`Orchestrator::auditAgentOutput()`** —— fanout 后标记非白名单扩展名、consolidator 专用文件名、sibling-role 子目录；warnings 进 `report[N].warnings[]`，**不改盘**。Per-agent plumbing 文件移到 `$TMPDIR/superaicore-spawn-<date>-<hex>/<agent>/`。
5. **语言感知的 `SpawnConsolidationPrompt::build()`** —— 中文跑硬编英→中 section 标题映射，禁止自创 `Error_No_Agent_Outputs_Found.md` 这类错误文件名。`GeminiCliBackend::parseJson()` 容忍 Gemini CLI 在 JSON 输出前的 "YOLO mode is enabled." / "MCP issues detected." 噪声前缀。

### 进程监控与后台 UI

- **Live-only 进程监控**（0.6.7+）—— `AiProcessSource::list()` 先查活的 `ps aux` 快照，只输出 PID 还活着的 `ai_processes` 行，同时顺手回收死行。完成 / 失败 / 被杀的运行一退出就从 Monitor UI 消失。
- **`host_owned_label_prefixes`**（0.6.7+）—— 让自带 `ProcessSource` 的宿主（例如 SuperTeam 的 `task:` 行）圈一个 label 命名空间，AiProcessSource 不会给同一逻辑运行渲染重复的裸行。
- **后台页面** —— `/integrations`、`/providers`、`/services`、`/ai-models`、`/usage`、`/costs`、`/processes`。`/processes` 仅管理员，默认关闭。

### 宿主集成

- **三语 UI** —— 英文、简体中文、法文，运行时切换。
- **关闭路由 / 视图** —— 嵌入父应用、替换 Blade 布局，或复用返回链接与语言切换器。
- **`BackendCapabilitiesDefaults` trait**（0.6.6+）—— 宿主自定义 `BackendCapabilities` 实现时 `use` 这个 trait 就能继承未来新增方法的安全默认 —— 宿主类无需任何代码改动就能继续满足接口。详见 `docs/api-stability.md` 的 SemVer 契约。

## 环境要求

- PHP ≥ 8.1
- Laravel 10、11 或 12
- Guzzle 7、Symfony Process 6/7

下列为可选，仅当启用对应后端时需要:

- `claude` CLI 在 `$PATH` —— `npm i -g @anthropic-ai/claude-code`
- `codex` CLI 在 `$PATH` —— `brew install codex`
- `gemini` CLI 在 `$PATH` —— `npm i -g @google/gemini-cli`
- `copilot` CLI 在 `$PATH` —— `npm i -g @github/copilot`（然后 `copilot login`）
- `kiro-cli` 在 `$PATH` —— 按 [kiro.dev](https://kiro.dev/cli/) 安装后 `kiro-cli login`；或设置 `KIRO_API_KEY` 走 headless（需 Pro / Pro+ / Power 订阅）
- `kimi` CLI 在 `$PATH`（0.6.8+）—— 按 [kimi.com](https://kimi.com/code) 安装后 `kimi login`
- Anthropic / OpenAI / Google AI Studio API Key（HTTP 后端）

不想记包名？跑 `./vendor/bin/superaicore cli:status` 看缺什么，再 `./vendor/bin/superaicore cli:install --all-missing` 一键装齐（默认带确认提示）。

## 安装

```bash
composer require forgeomni/superaicore
php artisan vendor:publish --tag=super-ai-core-config
php artisan vendor:publish --tag=super-ai-core-migrations
php artisan migrate
```

完整步骤见 [INSTALL.zh-CN.md](INSTALL.zh-CN.md)。

## CLI 快速上手

```bash
# 查看 Dispatcher 适配器及其可用状态
./vendor/bin/superaicore list-backends

# 从 CLI 驱动七个引擎
./vendor/bin/superaicore call "你好" --backend=claude_cli                              # Claude Code CLI（本地登录）
./vendor/bin/superaicore call "你好" --backend=codex_cli                               # Codex CLI（ChatGPT 登录）
./vendor/bin/superaicore call "你好" --backend=gemini_cli                              # Gemini CLI（Google OAuth）
./vendor/bin/superaicore call "你好" --backend=copilot_cli                             # GitHub Copilot CLI（订阅）
./vendor/bin/superaicore call "你好" --backend=kiro_cli                                # AWS Kiro CLI（订阅）
./vendor/bin/superaicore call "你好" --backend=kimi_cli                                # Moonshot Kimi Code CLI（OAuth 订阅）
./vendor/bin/superaicore call "你好" --backend=superagent --api-key=sk-ant-...         # SuperAgent SDK

# 跳过 CLI 包装，直接打 HTTP API
./vendor/bin/superaicore call "你好" --backend=anthropic_api --api-key=sk-ant-...      # Claude 引擎的 HTTP 模式
./vendor/bin/superaicore call "你好" --backend=openai_api --api-key=sk-...             # Codex 引擎的 HTTP 模式
./vendor/bin/superaicore call "你好" --backend=gemini_api --api-key=AIza...            # Gemini 引擎的 HTTP 模式

# 健康检查 + 安装
./vendor/bin/superaicore cli:status                           # 安装/版本/登录/提示一览
./vendor/bin/superaicore api:status                           # 每个直连 HTTP API 的 5 秒探测（0.6.8+）
./vendor/bin/superaicore cli:install --all-missing            # npm/brew/script 安装，默认带确认

# 模型目录
./vendor/bin/superaicore super-ai-core:models status                     # 来源、用户覆盖 mtime、加载行数
./vendor/bin/superaicore super-ai-core:models list --provider=anthropic  # 每百万 token 的价格 + 别名
./vendor/bin/superaicore super-ai-core:models update                     # 拉取 $SUPERAGENT_MODELS_URL（0.6.0+）
./vendor/bin/superaicore super-ai-core:models refresh --provider=kimi    # 实时 GET /models overlay（0.6.9+）
```

### Skill 与 sub-agent CLI

```bash
# 查看已安装
./vendor/bin/superaicore skill:list
./vendor/bin/superaicore agent:list

# 在 Claude 上跑一个 skill（默认）
./vendor/bin/superaicore skill:run init

# 在 Gemini 上原生跑 —— 探测 + 翻译 + preamble 注入
./vendor/bin/superaicore skill:run simplify --backend=gemini --exec=native

# 先试 Gemini，不兼容就回退 Claude；哪个后端先写文件就硬锁在它上面
./vendor/bin/superaicore skill:run simplify --exec=fallback --fallback-chain=gemini,claude

# 跑一个 sub-agent；后端从 frontmatter 的 `model:` 推断
./vendor/bin/superaicore agent:run security-reviewer "审查这份 diff"

# 同步到各引擎
./vendor/bin/superaicore gemini:sync                          # skill/agent 作为 Gemini 自定义命令
./vendor/bin/superaicore copilot:sync                         # ~/.copilot/agents/*.agent.md
./vendor/bin/superaicore copilot:sync-hooks                   # 合并 Claude 风格 hooks 到 Copilot
./vendor/bin/superaicore kiro:sync --dry-run                  # ~/.kiro/agents/*.json（0.6.1+）
./vendor/bin/superaicore kimi:sync                            # ~/.kimi/agents/*.yaml + mcp.json（0.6.8+）

# 同一任务并发分发给 N 个 Copilot agent
./vendor/bin/superaicore copilot:fleet "重构 auth" --agents planner,reviewer,tester
```

## PHP 快速上手

### 长任务 —— `TaskRunner`（0.6.6+）

需要日志可 tail、Process Monitor 一行、UI 实时预览、自动用量记录、codex/gemini spawn-plan 自动模拟时:

```php
use SuperAICore\Runner\TaskRunner;

$envelope = app(TaskRunner::class)->run('claude_cli', $prompt, [
    'log_file'       => $logFile,
    'timeout'        => 7200,
    'idle_timeout'   => 1800,
    'mcp_mode'       => 'empty',
    'spawn_plan_dir' => $outputDir,
    'task_type'      => 'tasks.run',
    'capability'     => $task->type,
    'user_id'        => auth()->id(),
    'external_label' => "task:{$task->id}",
    'metadata'       => ['task_id' => $task->id],
    'onChunk'        => fn ($chunk) => $taskResult->updateQuietly(['preview' => $chunk]),
]);

if ($envelope->success) {
    $taskResult->update([
        'content'    => $envelope->summary,
        'raw_output' => $envelope->output,
        'metadata'   => ['usage' => $envelope->usage, 'cost_usd' => $envelope->costUsd],
    ]);
}
```

返回类型化的 `TaskResultEnvelope`，六个 CLI 引擎接口完全一致，业务代码不再 per-backend 分支。

### 短调用 —— `Dispatcher::dispatch()`

```php
use SuperAICore\Services\BackendRegistry;
use SuperAICore\Services\CostCalculator;
use SuperAICore\Services\Dispatcher;

$dispatcher = new Dispatcher(new BackendRegistry(), new CostCalculator());

$result = $dispatcher->dispatch([
    'prompt' => '你好',
    'backend' => 'anthropic_api',
    'provider_config' => ['api_key' => 'sk-ant-...'],
    'model' => 'claude-sonnet-4-5-20241022',
    'max_tokens' => 200,
]);

echo $result['text'];
```

也支持 `'stream' => true` 透明 opt-in `TaskRunner` 内部用的流式路径。

高级选项（幂等、链路追踪、SDK features、错误分类）见 [docs/advanced-usage.zh-CN.md](docs/advanced-usage.zh-CN.md)。

## 架构

```
  面向用户的引擎            Provider 类型                     Dispatcher 适配器
  ────────────────         ──────────────────────            ──────────────────
  Claude Code CLI ────────▶ builtin                    ────▶ claude_cli
                            anthropic / bedrock /      ────▶ anthropic_api
                            vertex / anthropic-proxy
  Codex CLI       ────────▶ builtin                    ────▶ codex_cli
                            openai / openai-compat     ────▶ openai_api
  Gemini CLI      ────────▶ builtin / vertex           ────▶ gemini_cli
                            google-ai                  ────▶ gemini_api
  Copilot CLI     ────────▶ builtin                    ────▶ copilot_cli
  Kiro CLI        ────────▶ builtin / kiro-api         ────▶ kiro_cli
  Kimi Code CLI   ────────▶ builtin                    ────▶ kimi_cli
  SuperAgent SDK  ────────▶ anthropic(-proxy) /        ────▶ superagent
                            openai(-compatible) /
                            openai-responses /          (0.7.0+)
                            lmstudio                    (0.7.0+)

  Dispatcher ← BackendRegistry   （管理上述 10 个适配器）
             ← ProviderResolver  （从 ProviderRepository 读取当前 provider）
             ← RoutingRepository （task_type + capability → service）
             ← UsageTracker      （写入 UsageRepository）
             ← CostCalculator    （模型价格表 → USD）
```

所有 Repository 都是接口。ServiceProvider 默认绑定 Eloquent 实现；可替换为 JSON 文件、Redis 或外部 API，调度器无需改动。

## 高级用法

- **[高级用法指南](docs/advanced-usage.zh-CN.md)** —— 幂等 key 往返、W3C trace context、分类的 provider exception、`openai-responses` + Azure OpenAI + ChatGPT OAuth、LM Studio、`http_headers` / `env_http_headers` 覆盖、SDK features（`extra_body` / `features` / `loop_detection`）、`ScriptedSpawnBackend` 宿主迁移。
- **[Task runner 快速入门](docs/task-runner-quickstart.md)** —— 完整 `TaskRunner` 选项参考。
- **[Streaming backends](docs/streaming-backends.md)** —— `mcp_mode`、每后端流格式、`onChunk`。
- **[Spawn plan protocol](docs/spawn-plan-protocol.md)** —— codex/gemini agent 模拟。
- **[Host spawn uplift roadmap](docs/host-spawn-uplift-roadmap.md)** —— `ScriptedSpawnBackend` 为什么存在 + 它替掉了宿主里 700 行胶水。
- **[Idempotency](docs/idempotency.md)** —— 60 秒去重窗口、key 自动派生。
- **[MCP sync](docs/mcp-sync.md)** —— catalog + host map → 每个后端。
- **[API stability](docs/api-stability.md)** —— SemVer 契约。

## 配置

发布后的配置文件 `config/super-ai-core.php` 覆盖:宿主集成、语言切换器、路由/视图注册开关、逐个后端的开关、默认后端、使用量保留天数、MCP 安装目录、进程监控开关、每个模型的价格。所有字段均有内嵌注释。

## 许可证

MIT。见 [LICENSE](LICENSE)。
