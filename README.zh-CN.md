# forgeomni/superaicore

[![tests](https://github.com/ForgeOmni/SuperAICore/actions/workflows/tests.yml/badge.svg)](https://github.com/ForgeOmni/SuperAICore/actions/workflows/tests.yml)
[![license](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![php](https://img.shields.io/badge/php-%E2%89%A58.1-blue.svg)](composer.json)
[![laravel](https://img.shields.io/badge/laravel-10%20%7C%2011%20%7C%2012-orange.svg)](composer.json)

[English](README.md) · [简体中文](README.zh-CN.md) · [Français](README.fr.md)

用于统一调度七种 AI 执行引擎的 Laravel 包：**Claude Code CLI**、**Codex CLI**、**Gemini CLI**、**GitHub Copilot CLI**、**AWS Kiro CLI**、**Moonshot Kimi Code CLI**、**SuperAgent SDK**。内置独立于框架的 CLI、基于能力（capability）的调度器、MCP 服务器管理、使用量记录、成本分析，以及一套完整的后台管理 UI。

在干净的 Laravel 项目中可独立运行。UI 可选、可完全替换，既能嵌入宿主应用（例如 SuperTeam），也可以在仅使用服务层时关掉。

## 与 SuperAgent 的关系

`forgeomni/superaicore` 和 `forgeomni/superagent` 是**兄弟包，并非父子依赖关系**：

- **SuperAgent** 是一个轻量级的 PHP 进程内 SDK，专注于驱动单个 LLM 的 tool-use 循环（一个 agent、一段会话）。
- **SuperAICore** 是 Laravel 级的编排层 —— 负责挑选后端、解析 provider 凭证、按能力路由、记录用量、计算成本、管理 MCP 服务器，并提供后台 UI。

**SuperAICore 并不依赖 SuperAgent 才能工作。** SuperAgent 只是众多后端之一。CLI 引擎（Claude / Codex / Gemini / Copilot / Kiro / Kimi）与 HTTP 后端（Anthropic / OpenAI / Google）都不需要它，且 `SuperAgentBackend` 在 SDK 缺失时会通过 `class_exists(Agent::class)` 检查优雅地报告为不可用。如果你不需要 SuperAgent，只需在 `.env` 中设置 `AI_CORE_SUPERAGENT_ENABLED=false`，Dispatcher 会自动回退到其余后端。

`composer.json` 中的 `forgeomni/superagent` 依赖只是为了开箱即用地启用 SuperAgent 后端；若你从不使用它，可以在宿主项目 `composer install` 之前从 `composer.json` 中移除该条目 —— SuperAICore 的其余代码都不会引用 SuperAgent 命名空间。

## 特性

- **Skill 与 sub-agent 运行器** —— 自动发现 Claude Code skill（`.claude/skills/<name>/SKILL.md`）和 sub-agent（`.claude/agents/<name>.md`），并将其暴露为 CLI 子命令（`skill:list`、`skill:run`、`agent:list`、`agent:run`）。默认跑在 Claude 上，可选在 Codex / Gemini / Copilot 上原生执行（带兼容性探测、工具名翻译、后端 preamble 注入），并支持"有副作用即硬锁定"的多后端回退链。`gemini:sync` 把每个 skill / agent 镜像成 Gemini 自定义命令；`copilot:sync` 把 agent 镜像成 `~/.copilot/agents/*.agent.md`（或在 `agent:run --backend=copilot` 时自动触发）；`copilot:sync-hooks` 把 Claude 风格的 hooks 合并到 Copilot 配置。
- **一键 CLI 安装器** —— `cli:status` 列出每家 CLI 的安装/登录状态与安装提示；`cli:install [backend] [--all-missing]` 走规范的包管理器（`npm`/`brew`/`script`）安装缺失项，默认带确认提示。显式触发 —— 永不因为调度失败自动安装。
- **Copilot 并行 fan-out** —— `copilot:fleet <task> --agents a,b,c` 将同一任务并发分发给 N 个 Copilot sub-agent，聚合每 agent 结果，每个子进程都注册到 Process Monitor。
- **七个执行引擎** —— Claude Code CLI、Codex CLI、Gemini CLI、GitHub Copilot CLI、AWS Kiro CLI、Moonshot Kimi Code CLI、SuperAgent SDK，统一实现同一套 `Dispatcher` 契约。每个引擎只接受固定几类 provider：
  - **Claude Code CLI**：`builtin`（本地登录）、`anthropic`、`anthropic-proxy`、`bedrock`、`vertex`
  - **Codex CLI**：`builtin`（ChatGPT 登录）、`openai`、`openai-compatible`
  - **Gemini CLI**：`builtin`（Google OAuth 登录）、`google-ai`、`vertex`
  - **GitHub Copilot CLI**：仅 `builtin`（`copilot` 二进制自行处理 OAuth / keychain / 刷新）。原生读取 `.claude/skills/`（零翻译直通）。**订阅计费** —— 仪表盘独立统计，不混入按 token 计费引擎。
  - **AWS Kiro CLI**（0.6.1+）：`builtin`（本机 `kiro-cli login` 登录态）、`kiro-api`（DB 存的 key 注入成 `KIRO_API_KEY` 走 headless 模式）。CLI 后端里自带能力最全的一家——原生 agents、skills、MCP，以及**原生 subagent DAG 编排**（不走 `SpawnPlan` 模拟）。skill 直接复用 Claude 的 `SKILL.md` 格式。**按 credits 订阅计费**（Pro / Pro+ / Power 套餐）。
  - **Moonshot Kimi Code CLI**（0.6.8+）：`builtin`（`kimi login` 走 `auth.kimi.com` OAuth）。第七个执行引擎；与 SDK 内置的直连 HTTP `KimiProvider` 互补，专门覆盖 OAuth 订阅的 agentic-loop 路径，和 `anthropic_api` ↔ `claude_cli` 是同样的分工。默认走 Kimi 原生 `Agent` fanout（`AgentSpawn\Pipeline` 快速退出，和 Claude / Kiro 同一姿态）；需要 per-child 流式观测或 >500 step 的工作负载时通过 `use_native_agents=false` 切到 AICore 三阶段 Pipeline。**订阅计费** —— Moonshot Pro / Power 套餐。
  - **SuperAgent SDK**：`anthropic`、`anthropic-proxy`、`openai`、`openai-compatible`
- 七个引擎在 Dispatcher 内部扇出成十个适配器（`claude_cli`、`codex_cli`、`gemini_cli`、`copilot_cli`、`kiro_cli`、`kimi_cli`、`superagent`、`anthropic_api`、`openai_api`、`gemini_api`）—— provider 为 `builtin` / `kiro-api` 时走 CLI 适配器，持有 API Key 时走 HTTP 适配器。这是实现细节，一般无需关心；如需低层直调，CLI 也能直接指定这些适配器名。
- **EngineCatalog 单一数据源** —— 引擎的标签、图标、Dispatcher 后端、支持的 provider 类型、可用模型，以及声明式的 **`ProcessSpec`**（二进制名、版本/登录状态参数、prompt/output/model flag、默认 flag）都集中在一个 PHP 服务里。新增一个 CLI 引擎只需改 `EngineCatalog::seed()`，providers UI、进程扫描、开关矩阵、默认 CLI 命令形状全部自动跟进。同一份 catalog 也通过 `modelOptions($key)` / `modelAliases($key)`（0.5.9+）驱动宿主应用的模型下拉，宿主不再需要针对每个 backend 写 `switch` —— 新引擎的模型自动出现在所有 picker 里。宿主应用可通过 `super-ai-core.engines` 配置覆盖每个引擎字段（包括 `process_spec`）。
- **动态模型目录**（0.6.0+）—— `CostCalculator`、`ClaudeModelResolver`、`GeminiModelResolver` 和 `EngineCatalog::seed()` 的 `available_models` 都会回退到 SuperAgent 的 `ModelCatalog`（内置 `resources/models.json` + 用户覆盖 `~/.superagent/models.json`）。跑 `superagent models update`（或新增的 `super-ai-core:models update`）即可刷新所有 Anthropic / OpenAI / Gemini / Bedrock / OpenRouter 模型的价格和 ID 列表，不需要 `composer update` 或 `vendor:publish`。宿主显式发布的 `model_pricing` 和 `available_models` 仍然优先生效。
- **`/providers` 显示 Gemini OAuth 登录态**（0.6.0+）—— `CliStatusDetector::detectAuth('gemini')` 通过 SuperAgent 的 `GeminiCliCredentials` 读取 `~/.gemini/oauth_creds.json`，回退到 `GEMINI_API_KEY` / `GOOGLE_API_KEY`，在 provider 卡片上按 Claude Code / Codex 同样的方式显示 `{loggedIn, method, expires_at}`。
- **CliProcessBuilderRegistry** —— 基于引擎的 `ProcessSpec` 组装 `argv`（`build($key, ['prompt' => …, 'model' => …])`）。默认 builder 覆盖全部内置引擎；宿主可 `register($key, $callable)` 无需 fork 就替换成自定义形状。另暴露 `versionCommand()` / `authStatusCommand()` 给状态探测。以单例注册。
- **Provider / Service / Routing 模型** —— 将抽象能力（`summarize`、`translate`、`code_review` 等）映射到具体服务，再将服务绑定到 provider 凭证。
- **MCP 服务器管理器** —— 在后台 UI 中安装、启用、配置 MCP 服务器。
- **使用量追踪** —— 每次调用将 prompt / response tokens、耗时、成本写入 `ai_usage_logs` 表。0.6.2+ 起每行还带 `shadow_cost_usd` 与 `billing_model`，让订阅型引擎（Copilot、Kiro、Claude Code builtin）在仪表盘上呈现有意义的"如果按 token 计费"USD 估值，而不是一排 $0。
- **`UsageRecorder` —— 宿主侧 runner 的回写入口**（0.6.2+）—— 对 `UsageTracker` + `CostCalculator` 的薄封装；宿主自己 spawn CLI（例如 `App\Services\ClaudeRunner`、PPT 阶段任务、`ExecuteTask`）的场景下，每轮结束调用一次就能写入一条 `ai_usage_logs`，`cost_usd` / `shadow_cost_usd` / `billing_model` 全部按 catalog 自动补齐。搭配工具：`CliOutputParser::parseClaude()` / `::parseCodex()` / `::parseCopilot()` / `::parseGemini()` 可以从已捕获的 stdout 中抽出 `{text, model, input_tokens, output_tokens, …}`，不必构造完整后端对象。
- **`ProviderTypeRegistry` + `ProviderEnvBuilder` —— API type 的唯一数据源**（0.6.2+）—— 每种 provider type（Anthropic / OpenAI / Google / Kiro / …）的 label、图标、表单字段、env 键名、base_url env、允许的 backend、`extra_config → env` 映射全部收口到一个内置 registry。`ProviderEnvBuilder::buildEnv($provider)` 替代了宿主（SuperTeam 等）过去自己维护的 7-case env switch。宿主通过 `config/super-ai-core.php` 的 `provider_types` 覆盖键扩展 —— **以后 SuperAICore 新增 API type，宿主只要 `composer update` 就会看到新卡,完全零代码改动**。`CliStatusDetector::detectAuth()` 同步加了泛化 fallback，新 CLI 引擎落地那天就能在 `/providers` 上显示登录状态。
- **Cache-aware shadow cost + 优先采用 CLI 自报的 `total_cost_usd`**（0.6.5+）—— `CostCalculator::shadowCalculate()` 现在把 `cache_read_tokens` 按 0.1× 计、`cache_write_tokens` 按 1.25× 计(catalog 里有显式行时优先用 catalog 价格),重 cache 的 Claude 会话不再把用量一股脑算进 input 导致虚高 ~10×。当后端响应里带 `total_cost_usd`(Claude CLI 就带),Dispatcher 直接采纳它作为计费金额,并在 `metadata.cost_source=cli_envelope` 打标 —— 重要因为只有 CLI 知道某次会话走的是订阅还是 API key。
- **`MonitoredProcess::runMonitoredAndRecord()` runner 辅助方法**（0.6.5+）—— 既有 `runMonitored()` trait 方法的 opt-in 版,在子进程退出时自动 buffer stdout → 用 `CliOutputParser` 解析 → 经 `UsageRecorder` 写一行 `ai_usage_logs`。宿主 runner 不用再每个调用点手写 parser + recorder 的胶水。解析失败不会冒泡(纯文本的 Codex / Copilot 输出会被 `debug` 级 log 记一笔、跳过落行,CLI 的 exit code 正常返回)。`runMonitored()` 纯文本模式保持不变。
- **`Runner\TaskRunner` —— 一行调用执行任务**（0.6.6+）—— `Dispatcher::dispatch(['stream' => true, ...])` 的封装,返回类型化的 `TaskResultEnvelope`(success / output / summary / usage / cost / log file / spawn report)。把宿主 ~150 行的 "build prompt → spawn → tee log → extract usage → wrap result" 胶水折叠成一次调用。6 个 CLI(claude / codex / gemini / kiro / copilot / kimi)接口完全一致,业务代码不再 per-backend 分支。详见 `docs/task-runner-quickstart.md`。
- **`Contracts\StreamingBackend` —— 每个 CLI 都获得实时 tee + Process Monitor + onChunk**（0.6.6+）—— `Backend::generate()` 的同级新接口,通过 callback 流式接收 chunks 同时把它们 tee 到磁盘并登记 `ai_processes` 行供 Monitor UI 跟读。6 个 CLI backend 全部实现;`Dispatcher::dispatch(['stream' => true, ...])` 透明 opt-in。支持每次调用配 `timeout` / `idle_timeout` / `mcp_mode`(claude 用 `'empty'` 防止全局 MCP 卡退出)。详见 `docs/streaming-backends.md`。
- **`AgentSpawn\Pipeline` —— spawn-plan 协议上移**（0.6.6+）—— codex / gemini 的三阶段编排(Phase 1 preamble / Phase 2 并行 fanout / Phase 3 consolidation 复调)以前住在每个宿主里,现在 SuperAICore 内置。`TaskRunner` 见到 `spawn_plan_dir` 自动激活。宿主可以删掉自己的 `maybeRunSpawnPlan` + `runConsolidationPass`(~150 行)。新 CLI 想用协议时实现 `BackendCapabilities::spawnPreamble()` + `consolidationPrompt()` 即可,其他逻辑继承。详见 `docs/spawn-plan-protocol.md`。
- **`ai_usage_logs.idempotency_key` 60 秒去重窗口**（0.6.6+）—— `EloquentUsageRepository::record()` 看见 `idempotency_key` 时,会查 60s 内同 key 的最新行,命中就返回它的 id 不重复插入。`Dispatcher::dispatch()` 自动用 `"{backend}:{external_label}"` 作为 key,所以宿主双写同一逻辑 turn(例如 `Dispatcher` 写一行 + 宿主自己又调 `UsageRecorder::record()`)会自动合并成一行,零代码改动。需要 `php artisan migrate` 加列 + 复合索引。详见 `docs/idempotency.md`。
- **API 稳定性 + `BackendCapabilitiesDefaults` trait**（0.6.6+）—— `docs/api-stability.md` 正式声明哪些 API 走严格 SemVer(`StreamingBackend` / `TaskRunner` / `TaskResultEnvelope` / `Pipeline` / `TeeLogger` / `BackendCapabilities` / `Dispatcher::dispatch()` / `UsageRecorder::record()` 形状等)、哪些 surface 仍在演进。宿主自定义 `BackendCapabilities` 实现时建议 `use BackendCapabilitiesDefaults;` 来继承未来新增方法的安全默认 —— 这样宿主类无需任何代码改动就能继续满足接口。详见 `docs/api-stability.md`。
- **PHP-FPM 里起 Claude CLI 现在就能跑**（0.6.7+）—— `ClaudeCliBackend` 现在会在子进程 env 里主动移除 `CLAUDECODE` / `CLAUDE_CODE_ENTRYPOINT` / `CLAUDE_CODE_SSE_PORT` / `CLAUDE_CODE_EXECPATH` / `CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS`,以免从父 `claude` shell 里启动的 Laravel server 触发 claude 的递归守卫,报 `"Not logged in · Please run /login"`。macOS 上,`builtin` 登录新增 fallback:通过 `security find-generic-password -s "Claude Code-credentials"` 读出 OAuth token 注入成 `ANTHROPIC_API_KEY` —— 这是 Web worker 唯一能走通的路径,因为 claude 原生 Keychain 调用被绑死在运行 `claude login` 的那个 audit session 里。API-key / bedrock / vertex / Linux 部署零变化。
- **每个 CLI 都支持 `cwd` + Claude 专属 `permission_mode` / `allowed_tools` / `session_id`**（0.6.7+）—— `StreamingBackend::stream()` 现在在 6 个 CLI 上都认 `cwd`,宿主 PHP 从 `web/public` 起也能 spawn 到能正确找到项目根下 `artisan` + `.claude/` 的 `claude`。Claude 专属选项让 headless 调用方能绕过交互审批(`permission_mode=bypassPermissions` 是 headless 必需)、限制工具面(`allowed_tools`)、显式传 `session_id` 用于日志相关性。其他 CLI 接受这三个 key 但 no-op。
- **Process Monitor 改为 live-only + `host_owned_label_prefixes`**（0.6.7+）—— `AiProcessSource::list()` 现在先查活的 `ps aux` 快照,只输出 PID 还活着的 `ai_processes` 行,同时顺手回收死行。完成 / 失败 / 被杀的运行一退出就从 Monitor UI 消失,不再累积。新增 `super-ai-core.process_monitor.host_owned_label_prefixes` 配置,让自带 `ProcessSource` 的宿主(例如 SuperTeam 的 `task:` 行)圈一个 label 命名空间,AiProcessSource 就不会给同一逻辑运行渲染重复的裸行。需要历史运行的宿主应直接查 `ai_processes` 表 —— 表仍保留每次 spawn 的完整审计日志。
- **Moonshot Kimi Code CLI —— 第七个执行引擎**（0.6.8+）—— 完整接入 Moonshot `kimi` CLI(已验证 v1.38.0),作为 `kimi_cli` 与原有 6 个后端并列。`KimiCliBackend` spawn `kimi --print --output-format=stream-json` 并解析三种事件 shape(`role=assistant` 带 `content[].type=think|text` + 可选 `tool_calls[]`;`role=tool` 携带 `tool_call_id`;final-turn assistant text 覆盖前面的 partials);`think` block 仅内部保留,不会泄漏到 envelope 文本。max-steps-per-turn、per-run MCP 配置文件、`cwd` 都通过 `StreamingBackend` 契约流通。agent-team 路由策略由运维决定:默认走 Kimi 原生 `Agent` fanout(`spawnPreamble()` / `consolidationPrompt()` 返回空字符串,`AgentSpawn\Pipeline` 快速退出 —— 和 Claude / Kiro 同姿态),或 `use_native_agents=false` 切到 AICore 三阶段 Pipeline,继承 0.6.8 的弱模型加固(guard 注入、canonical `output_subdir`、fanout 前清理、`auditAgentOutput`、语言感知 consolidation)。新 `kimi:sync` 命令把 `.claude/agents/*.md` 的 tool 列表翻译为 `~/.kimi/agents/*.yaml` + `~/.kimi/mcp.json`(Claude 兼容);`claude:mcp-sync` 自动 fan-out 到 Kimi。与 SDK 直连 HTTP `KimiProvider` 互补,专门覆盖 OAuth 订阅的 agentic-loop 路径,和 `anthropic_api` ↔ `claude_cli` 是同样的分工。配置位于 `backends.kimi_cli.{enabled,binary,timeout,max_steps_per_turn,use_native_agents}`。
- **catalog 驱动的 MCP 同步**（0.6.8+）—— `claude:mcp-sync` 读 `.mcp-servers/mcp-catalog.json` + 一份薄薄的 `.claude/mcp-host.json` 映射,把正确的 server 子集分发到项目 `.mcp.json`、每个 agent `.claude/agents/*.md` frontmatter 里的 `mcpServers:` 块(在 `# superaicore:mcp:begin` / `:end` 标记之间),以及每个已安装 CLI backend 的 user-scope 配置(Claude / Codex / Gemini / Copilot / Kiro / Kimi)。`mcp:sync-backends` 是给手改 `.mcp.json` 或 file-watcher 自动同步用的独立入口。非破坏性:用户编辑过的文件按 sha256 manifest 标记为 `user-edited` 并保留(项目文件层面);agent frontmatter 标记外的改动全部保留。详见 `docs/mcp-sync.md`。
- **`SuperAgentBackend` 升级为真正的 agentic loop**（0.6.8+）—— 支持 `max_turns`(默认 1 保持单轮)、`max_cost_usd` → `Agent::withMaxBudget()` 在 loop 内硬性卡预算、`allowed_tools` / `denied_tools` 工具面过滤、`mcp_config_file`(加载 `.mcp.json` via `MCPManager::loadFromJsonFile()` + `autoConnect()`,`finally{}` 中 disconnect)、`provider_config.region` 通过 `ProviderRegistry::createWithRegion()` 路由以适配 SuperAgent 0.8.8 的 Kimi / Qwen / GLM / MiniMax 多区域分流。envelope 新增 `usage.cache_read_input_tokens` / `cache_creation_input_tokens`、`cost_usd`(SDK 自算的跨 turn 汇总 —— Dispatcher 优先用这个)、`turns`。**SDK 已锁到 0.9.0（0.6.9+）**:调用方开启 SDK 子 agent dispatch(`load_tools: ['agent', …]`)时,envelope 会多一个可选的 `subagents` key,携带 0.8.9 `AgentTool` 的 productivity 信息(`filesWritten` / `toolCallsByName` / `productivityWarning` / `status: completed|completed_empty`)—— 用来识别只写了 prose 没动工具的子 agent,不用再扒 narrative。没跑子 agent 时这个 key 不出现,shape 字节级兼容。
- **`api:status` —— 直连 HTTP API provider 的 5 秒可达性探测**（0.6.8+）—— `cli:status` 的平行兄弟。封装 SuperAgent `ProviderRegistry::healthCheck()`,对 anthropic / openai / openrouter / gemini / kimi / qwen / glm / minimax 每个 provider 返回 `{ok, latency_ms, reason}` 三元组,让运维一眼分清 auth 被拒(HTTP 401/403)、网络超时、key 没配。默认只探有 API-key env 的 provider;`--all` 覆盖全部 DEFAULT_PROVIDERS、`--providers=a,b,c` 缩窄、`--json` 给 dashboard 吃。
- **SuperAgent SDK 升级到 0.9.0**（0.6.9+）—— Composer 约束从 `^0.8.0` 抬到 `^0.9.0`。**自动修复**(无需改代码):Kimi `thinking` 线路矫正(0.9.0 之前 SDK 会伪造 `kimi-k2-thinking-preview` 模型 id —— Moonshot 从未发布过这个 id,所有 thinking 调用都会 400,现在改发真模型 + `reasoning_effort` + `thinking: {type: enabled}`);流式 tool_call 分片组装修复(一个 tool call 分片成 N 块曾经会被解析成 N 个 ContentBlock —— 现在按 index 累积,`subagents[]` 计数在所有 OpenAI-兼容 provider 上都变准);`finish_reason: "error_finish"` 现在抛可重试 429 而不是把错误文本静默附加到 result;usage cached-token 同时读 `usage.prompt_tokens_details.cached_tokens`(Kimi 发的新 OpenAI shape)和 `usage.cached_tokens`(旧 shape),`cache_read_input_tokens` 在 Kimi 上从长期 0 变成准数;Anthropic OAuth 刷新现在靠 `flock` 串行化,Laravel 多 worker 共用存好的 OAuth 凭据时不会再互相覆盖 refresh token。
- **`super-ai-core:models refresh [--provider <p>]`**（0.6.9+）—— 新子命令,和 `list / update / status / reset` 并列。封装 SDK 0.9.0 的 `ModelCatalogRefresher`,把每个 provider 的实时 `GET /models` 拉进 `~/.superagent/models-cache/<provider>.json` 的 per-provider overlay cache。支持 anthropic / openai / openrouter / kimi / glm / minimax / qwen。overlay 在用户 override 之上、`register()` 运行时注册之下,bundled 的 pricing 在厂商 `/models` 不返回定价时(通常不返回)保留。`status` 输出多一行 `refresh cache`。
- **`SuperAgentBackend` 透传三个 0.9.0 选项**（0.6.9+）—— `Dispatcher::dispatch()` 在 `backend=superagent` 下新增透传键:`extra_body`(深合并到每个 `ChatCompletionsProvider` 请求 body 顶层,给未封装 capability adapter 的厂商私有字段一个逃生舱口)、`features`(走 SDK `FeatureDispatcher`;常用键:`prompt_cache_key.session_id` 开 Kimi 会话级 prompt cache、`thinking.*` 在所有 provider 上带 CoT 兜底、`dashscope_cache_control` 给 Qwen 走 Anthropic 风格 cache 标记)、`loop_detection: true|array`(把流处理 handler 包进 `LoopDetectionHarness`,捕获 `TOOL_LOOP` / `STAGNATION` / `FILE_READ_LOOP` / `CONTENT_LOOP` / `THOUGHT_LOOP`)。便捷写法:`prompt_cache_key: '<sessionId>'` 直接当 session_id shorthand。
- **Qwen 双 provider + Kimi/Qwen Code OAuth 识别**（0.6.9+）—— SDK 0.9.0 把 `qwen` 注册键重绑到 OpenAI-兼容 provider(`<region>/compatible-mode/v1/chat/completions` —— Alibaba 自家 `qwen-code` 生产里用的就是这条路径),原来的 DashScope 原生 body shape 搬到 `qwen-native`。两个 key 共用 `QWEN_API_KEY`。`ApiHealthDetector::DEFAULT_PROVIDERS` 现在把 `qwen-native` 和 `qwen` 并列,继续需要 `parameters.thinking_budget` / `parameters.enable_code_interpreter` 的 host 能在 dashboard 同时看到两条线。`filterToConfigured()` 另外会把 `~/.superagent/credentials/kimi-code.json` / `qwen-code.json`(`superagent auth login kimi-code` / `qwen-code` 写的 token 文件)也认作"已配置",运维只走 OAuth 没设 API key 的情况下依然会在 `api:status` / `/providers` 里出现。
- **`McpManager` 针对 mcp.json 的 OAuth 辅助方法**（0.6.9+）—— `oauthStatus(key) → 'ok'|'needed'|'n/a'`、`oauthLogin(key)`、`oauthLogout(key)`,薄封装 SDK 0.9.0 的 `McpOAuth`,针对 mcp.json 条目里声明了 `oauth: {client_id, device_endpoint, token_endpoint, scope?}` 块的 MCP 服务器。和既有的 `startAuth()` / `clearAuth()` / `testConnection()`(处理 LinkedIn scraper 这类浏览器登录 / session-dir 服务器)互补。前端就可以给每个声明 device-code flow 的 MCP 服务器画一个 OAuth 按钮。登录过程在 stdio 上阻塞等待 device-flow 轮询 —— 用队列任务跑,别在 request 内联调。
- **弱模型 agent-spawn 加固**（0.6.8+）—— 五层防线叠上去,避免 Gemini Flash 这类弱模型违约后污染 consolidator:
  1. `SpawnPlan::appendGuards()` —— 宿主端往每个子 agent 的 `task_prompt` 末尾追加 guard 块(六条硬规则:不越界、不写 consolidation 文件、语言统一含文件名、扩展名白名单、`_signals/<name>.md` 固定路径、工具失败别道歉)。CJK 正则检测,中英文自动分叉。幂等。同时剥掉上游模型塞进来的 `CRITICAL OUTPUT RULE: …` 句,避免和 ChildRunner 后补的权威版本路径打架。
  2. `SpawnPlan::fromFile()` —— 强制 `output_subdir = agent.name`(canonical ASCII),Flash 在 zh-CN 下发 `首席执行官` 代替 `ceo-bezos` 不再能让审计目录走空、consolidation 再入 "找不到输出" 幻觉(RUN 70)。
  3. `Pipeline::cleanPrematureConsolidatorFiles()` —— fanout 前先扫 `$outputDir` 顶层,把早产的 `摘要.md` / `思维导图.md` / `流程图.md` / `summary.md` / `mindmap.md` / `flowchart.md` 清掉 —— 上游模型违反 "emit plan and STOP" 规则写的东西不会和真实 consolidation pass 打架(RUN 70)。
  4. `Orchestrator::auditAgentOutput()` —— fanout 完成后审计每个 agent 的输出子目录,标记非白名单扩展名、consolidator 专用文件名、sibling-role 子目录;warnings 进 `report[N].warnings[]`,**不改盘**。每个 agent 的 plumbing 文件(`run.log` / 提示词 / 执行脚本)从用户可见输出目录移到 `$TMPDIR/superaicore-spawn-<date>-<hex>/<agent>/`,创始人浏览运行输出时只看到真交付物。
  5. `SpawnConsolidationPrompt::build()` 改为语言感知,中文跑硬编英→中 section 标题映射(`# Executive Summary` → `# 执行摘要` / `## Key Findings` → `## 关键发现` / …)并明确禁止自创 `Error_No_Agent_Outputs_Found.md` 这类错误文件名(错误写进 `摘要.md` 顶部 `## 警告` section,保持三文件契约)(RUN 71)。`CodexCapabilities` / `GeminiCapabilities` preamble 也明示上游模型在生成 `task_prompt` 时必须内嵌四条 guard,和 #1 的宿主端注入互为 belt-and-braces。`GeminiCliBackend::parseJson()` 容忍 Gemini CLI 在 JSON 输出前的噪声前缀("YOLO mode is enabled."、"MCP issues detected." 以及弃用告警 —— RUN 65)。
- **成本分析** —— 按模型价格表汇总 USD 费用，并提供带图表的仪表盘。0.6.2+ 新增 "By Task Type" 卡片、每行 `usage`/`sub` 计费模式徽章，以及每张分组表里的 shadow cost 列。仪表盘默认隐藏 0-token 行与 `test_connection` 行；`/providers` 的 "Test" 按钮现在会自我标记为 `task_type=test_connection`，不再污染主视图。
- **进程监控** —— 查看正在运行的 AI 进程、跟踪日志、终止僵尸进程。
- **三语 UI** —— 英文、简体中文、法文，可在运行时切换。
- **宿主友好** —— 支持关闭路由/视图、替换 Blade 布局，或在父应用中复用返回链接与语言切换器。

## 环境要求

- PHP ≥ 8.1
- Laravel 10、11 或 12
- Guzzle 7、Symfony Process 6/7

下列为可选，仅当启用对应后端时需要：

- `claude` CLI 在 `$PATH` 中 —— `npm i -g @anthropic-ai/claude-code`
- `codex` CLI 在 `$PATH` 中 —— `brew install codex`
- `gemini` CLI 在 `$PATH` 中 —— `npm i -g @google/gemini-cli`
- `copilot` CLI 在 `$PATH` 中 —— `npm i -g @github/copilot`（然后运行 `copilot login`）
- `kiro-cli` 在 `$PATH` 中（Kiro CLI 后端）—— 按 [kiro.dev](https://kiro.dev/cli/) 安装后运行 `kiro-cli login`；或设置 `KIRO_API_KEY` 走 headless（需 Pro / Pro+ / Power 订阅）
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
```

## Skill 与 sub-agent CLI

Claude Code 的 skill（`.claude/skills/<name>/SKILL.md`）和 sub-agent（`.claude/agents/<name>.md`）会被自动发现——skill 三个来源（项目 > plugin > 用户），agent 两个来源（项目 > 用户），各自作为一等 CLI 子命令暴露出来：

```bash
# 查看已安装内容
./vendor/bin/superaicore skill:list
./vendor/bin/superaicore agent:list

# 在 Claude 上跑一个 skill（默认）
./vendor/bin/superaicore skill:run init

# 在 Gemini 上原生跑——探测 + 翻译 + preamble 注入
./vendor/bin/superaicore skill:run simplify --backend=gemini --exec=native

# 先试 Gemini，不兼容就回退 Claude；哪个后端先写文件就硬锁在它上面
./vendor/bin/superaicore skill:run simplify --exec=fallback --fallback-chain=gemini,claude

# 跑一个 sub-agent；后端从 frontmatter 的 `model:` 推断
./vendor/bin/superaicore agent:run security-reviewer "审查这份 diff"

# 把每个 skill / agent 同步成 Gemini 自定义命令
# （/skill:init、/agent:security-reviewer、…）
./vendor/bin/superaicore gemini:sync

# GitHub Copilot CLI：skill 零翻译直通（Copilot 原生读 .claude/skills/）。
# agent 在 agent:run 时自动同步；手工入口：
./vendor/bin/superaicore copilot:sync                         # 写 ~/.copilot/agents/*.agent.md
./vendor/bin/superaicore agent:run reviewer "audit" --backend=copilot

# 同一个任务并发分发给 N 个 Copilot agent
./vendor/bin/superaicore copilot:fleet "重构 auth" --agents planner,reviewer,tester

# 把 Claude 风格 hooks（.claude/settings.json:hooks）合并到 Copilot
./vendor/bin/superaicore copilot:sync-hooks                   # 写 ~/.copilot/config.json:hooks

# AWS Kiro CLI（0.6.1+）：skill 零翻译直通（Kiro 原生读 .claude/skills/）；
# agent 在 agent:run --backend=kiro 时自动翻译成 ~/.kiro/agents/<name>.json，
# 然后交给 Kiro 原生 subagent DAG 编排执行。
./vendor/bin/superaicore kiro:sync --dry-run                  # 预览 ~/.kiro/agents/*.json
./vendor/bin/superaicore agent:run reviewer "审查" --backend=kiro

# 一键安装缺失的引擎 CLI（显式 —— 永不自动触发）
./vendor/bin/superaicore cli:status                           # 安装/版本/登录/提示一览
./vendor/bin/superaicore cli:install --all-missing            # npm/brew/script 安装，默认带确认

# 查看或刷新模型目录（0.6.0+）
./vendor/bin/superaicore super-ai-core:models status                     # 来源、用户覆盖 mtime、加载行数
./vendor/bin/superaicore super-ai-core:models list --provider=anthropic  # 每百万 token 的价格 + 别名
./vendor/bin/superaicore super-ai-core:models update                     # 拉取 $SUPERAGENT_MODELS_URL
```

关键行为：

- `--exec=claude`（默认）—— 不管 `--backend` 是什么，都跑在 Claude 上。
- `--exec=native` —— 跑在 `--backend` 指定的 CLI 上。`CompatibilityProbe` 会标记在无 sub-agent 能力的后端上使用 `Agent` 工具的 skill；`SkillBodyTranslator` 把规范工具名（`` `Read` `` → `read_file` 等）按显式形状替换，并注入后端 preamble（Gemini / Codex）。散文里的"Read the config"这类裸词不会被改。
- `--exec=fallback` —— 走一条链，跳过不兼容的跳；**硬锁定**在第一个触碰 cwd 的跳上（mtime 快照差分 + stream-json `tool_use` 事件）。默认链 `<backend>,claude`。
- `arguments:` frontmatter 会被解析（free-form / positional / named）、校验，并渲染成结构化 `<arg name="...">` XML 追加到 prompt。
- `allowed-tools:` frontmatter 会传给 `claude --allowedTools`；codex / gemini 无对应强制 flag，只打一条 `[note]`。
- `gemini:sync` 不会覆盖用户手编辑过的 TOML，删除过的会被下次 sync 重建（通过 `~/.gemini/commands/.superaicore-manifest.json` 追踪）。

## PHP 调用示例

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
  SuperAgent SDK  ────────▶ anthropic(-proxy) /        ────▶ superagent
                            openai(-compatible)

  Dispatcher ← BackendRegistry   （管理上述 9 个适配器）
             ← ProviderResolver  （从 ProviderRepository 读取当前 provider）
             ← RoutingRepository （task_type + capability → service）
             ← UsageTracker      （写入 UsageRepository）
             ← CostCalculator    （模型价格表 → USD）
```

所有 Repository 都是接口。ServiceProvider 默认绑定 Eloquent 实现；你可以替换为 JSON 文件、Redis 或外部 API，调度器无需改动。

## 后台 UI

当 `views_enabled` 为真时，包会在配置的路由前缀（默认 `/super-ai-core`）下挂载以下页面：

- `/integrations` —— Provider、服务、API Key、MCP 服务器
- `/providers` —— 按后端维护凭证与默认模型
- `/services` —— 任务类型路由
- `/ai-models` —— 模型价格覆盖
- `/usage` —— 可筛选的调用日志
- `/costs` —— 成本仪表盘
- `/processes` —— 实时进程监控（仅管理员，默认关闭）

## 配置

发布后的配置文件 `config/super-ai-core.php` 覆盖：宿主集成（返回链接、图标、名称）、语言切换器、路由/视图注册开关、逐个后端的开关、默认后端、使用量保留天数、MCP 安装目录、进程监控开关，以及每个模型的价格。所有字段均有内嵌注释说明。

## 许可证

MIT。见 [LICENSE](LICENSE)。
