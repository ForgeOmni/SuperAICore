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
  - [Skill engine —— 遥测 / 排序 / 演化](#skill-engine--遥测--排序--演化)
  - [jcode 配套工具波次（0.9.0 / SDK 0.9.7）](#jcode-配套工具波次090--sdk-097)
  - [DeepSeek-TUI 对齐波次（0.9.1 / SDK 0.9.8）](#deepseek-tui-对齐波次091--sdk-098)
  - [TaskRunner 可靠性波次（0.9.2）](#taskrunner-可靠性波次092)
  - [Squad 多智能体 + SDK 1.0.0 波次（0.9.6）](#squad-多智能体--sdk-100-波次096)
  - [opencode 借鉴特性波次（0.9.7 / SDK 1.0.5）](#opencode-借鉴特性波次097--sdk-105)
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

### Skill engine —— 遥测 / 排序 / 演化

三个正交的服务（0.8.6+），把静态的 skill 目录变成一个反馈闭环。**精神上借鉴**自 HKUDS/OpenSpace 的 `skill_engine`，砍到生产可用的安全子集 —— DERIVED / CAPTURED 模式有意省略（Day 0 的策略是人来策展新 skill）；云端 registry 也省略（暂无跨项目共享需求）。

- **`SkillTelemetry`**（0.8.6+）—— 每次 Claude Code Skill 工具调用在 `sac_skill_executions` 落一行。PreToolUse hook → `php artisan skill:track-start`（写入 `in_progress` 行，返回 id）；Stop hook → `php artisan skill:track-stop`（把当前 session 还没关的行全关掉）。两个命令都从 stdin 读 Claude Code hook JSON 负载，所以接线只在 `.claude/settings.local.json` 里，不需要写 PHP。聚合接口:`SkillTelemetry::metrics(?since, ?skillName)` 按 skill 返回 `applied / completed / failed / orphaned / interrupted / completion_rate / failure_rate / last_used_at`。`sweepOrphaned(maxAgeSeconds=7200)` 用来回收崩溃 session 留下的孤儿行。
- **`SkillRanker`**（0.8.6+）—— 在 `SkillRegistry` 目录上跑纯 PHP BM25（Robertson-Walker `K1=1.5`、`B=0.75`，BM25-Plus IDF）。CJK 感知的 tokenizer 把每个汉字单独切一个 token（中文 skill 描述很短，char-grams 够用），EN+zh 极小停用词表，连字符词同时拆分成各部分。带置信度加权的遥测加成:`final = bm25 * (1 + 0.4 * (success_rate - 0.5) * applied_signal)`，其中 `applied_signal = min(1, applied / 10)` 在样本量到 10 后饱和；没有遥测的 skill 拿 `boost = 1.0`。驱动 `php artisan skill:rank "你的任务描述"`，table / JSON 输出，并提供完整的 per-term IDF×TF 拆分用于调试。
- **`SkillEvolver`**（0.8.6+）—— 只支持 FIX 模式。读最近若干失败 + 当前 SKILL.md，构造受约束的 LLM prompt（"产出最小可行 patch"、"不要凭证据之外的内容编造失败"、"不要重排 section / 改名 / 改 frontmatter `name` / 加新工具到 `allowed-tools`，除非证据明确要求"），把结果写成 `pending` 状态的 `SkillEvolutionCandidate`。**永不直接改 SKILL.md** —— 人类通过 `php artisan skill:candidates --id=N --show-prompt --show-diff` 审核。`--dispatch` 模式（默认关，烧 token）走 Dispatcher 用 `capability: 'reasoning'` 调 LLM，从响应里抽出 `\`\`\`diff` 块，把 `proposed_body` 和 `proposed_diff` 都写回 candidate。`--sweep --threshold=0.30 --min-applied=5` 把所有失败率超阈值的 skill 一次性入队；按 `pending` 行去重，每天跑也安全。触发类型:`manual` / `failure` / `metric_degradation`。
- **六个 artisan 命令**:`skill:track-start` / `skill:track-stop` / `skill:stats` / `skill:rank` / `skill:evolve` / `skill:candidates`。全都通过 `SuperAICoreServiceProvider::boot()` 注册 —— 任何挂载本包的宿主都能 `php artisan skill:*` 直接用。
- **两张新表**:`sac_skill_executions`（`skill_name` / `host_app` / `session_id` / `status` / `started_at` / `completed_at` / `duration_ms` / `transcript_path` / `error_summary` / `cwd` / `metadata` json）和 `sac_skill_evolution_candidates`（`skill_name` / `trigger_type` / `execution_id` / `status` / `rationale` / `proposed_diff` / `proposed_body` / `llm_prompt` / `context` json / `reviewed_at` / `reviewed_by`）。两张表都通过 `HasConfigurablePrefix` 尊重 `super-ai-core.table_prefix`。`php artisan migrate` 即可创建。

### jcode 配套工具波次（0.9.0 / SDK 0.9.7）

五项 jcode 启发的能力随 SuperAgent SDK 0.9.7 上线,SuperAICore 0.9.0
配套接入。**全部按需通过 env 开关激活**,关闭时行为与 0.9.7 之前完全一致 ——
仅 `agent_grep` 默认开启(只读、无外部依赖)。Composer 约束升至 `^0.9.7`。

- **`agent_grep` 工具 —— 默认开启** *(0.9.0)* —— 调用方未显式传 `load_tools` 时,`SuperAgentBackend` 自动把 `'agent_grep'` 加入加载列表(默认 `AI_CORE_TOOLS_AGENT_GREP=true`)。该工具为每条 grep 命中注入所在符号上下文(PHP/JS/TS/Py/Go),并截断本会话内已经返回过的 chunk。是 `grep` 的严格超集;只在真正进入工具循环的 dispatch 上生效。设 `AI_CORE_TOOLS_AGENT_GREP=false` 可恢复 0.9.7 之前的行为。
- **`browser` 工具接入** *(0.9.0)* —— `AI_CORE_TOOLS_BROWSER=true` 时,`SuperAgentBackend` 实例化 SDK 0.9.7 的 `FirefoxBridgeTool`(通过 Native Messaging 驱动 Firefox / Chromium)并经 `Agent::addTool()` 注册。需 `SUPERAGENT_BROWSER_BRIDGE_PATH` 指向启动器,否则每个动作都返回解释性错误,避免 agent 死循环。
- **`BrowserScreenshotStore` 闭环** *(0.9.0)* —— 当 `browser` 工具产出 base64 PNG 时,`SuperAgentBackend` 按 `process_id` / `external_label` / `metadata.session_id` 优先级写入 `BrowserScreenshotStore`,并在 dispatch envelope 上挂 `latest_screenshot_url`。`AiProcessSource` 构造 `ProcessEntry` 时按 `external_label`(再回退到复合 id)读 `latest()`,reap 时调 `purgeFor()`。无需 host 端胶水代码即可端到端联通。Disk + 目录可经 `super-ai-core.browser_screenshots` 配置。
- **`SemanticSkillReranker` 改用 `EmbeddingProvider` SPI** *(0.9.0)* —— 跑在 `SkillRanker` BM25 top-N 之后的可选语义重排,现在通过 `EmbeddingProviderFactory` 解析 SDK 0.9.7 的 `EmbeddingProvider`(`super-ai-core.embeddings.{provider,callback,ollama_url}`)。Reranker、SDK 自带的 `SemanticSkillRouter`、host 自带的 `OnnxEmbeddingProvider` 共用一个容器单例 + 一份缓存。某条向量返回空(`[]`)时保留该条 BM25 得分,而不是整批回退。无 embedder 时降级到纯 BM25 排序。
- **`usage_source` 成本归属切分** *(0.9.0)* —— `Dispatcher::resolveUsageSource()` 把 `options['usage_source']` / `options['metadata']['usage_source']` 拍平到 `metadata.usage_source` 顶层(默认 `'user'`)。`/usage` 增加"By Source"卡片 + "N ambient · $X" 徽章,这样 SuperAgent `AmbientWorker` 在去重 / 失效扫描时打的标签即可在仪表盘显示,host 不需要重写成本统计代码。
- **跨 harness 会话恢复** *(0.9.0)* —— `HarnessSessionResolver` 包装 SDK 0.9.7 的 `Conversation\HarnessImporter` 系列(`ClaudeCodeImporter` 读 `~/.claude/projects/<hash>/<uuid>.jsonl`,`CodexImporter` 读 `~/.codex/sessions/**/*.jsonl`)。`/processes` 增加"Resume from…"下拉 + 文字记录 modal,通过 `super-ai-core.resume.enabled` 开关。Host 实现 `super-ai-core.resume.on_load` callable 把消息再分发回某个 backend;否则 modal 仅显示文字记录供查看。

完整菜谱(Ollama embedder 接线、browser launcher 准备、ambient worker
tick 循环、harness 恢复回调):见 [docs/advanced-usage.zh-CN.md
§17–§21](docs/advanced-usage.zh-CN.md)。

### DeepSeek-TUI 对齐波次（0.9.1 / SDK 0.9.8）

五项 SDK 0.9.8 配套绑定在 SuperAICore 0.9.1 落地,外加一个 backend 加固
修复。Composer 约束升至 `^0.9.8`。SDK 端新增的 `Goals\GoalManager`、
`Security\UntrustedInput`、`Swarm\AgentDepthGuard`、
`Providers\Transport\TokenBucket`、`Conversation\Fork`、
`Memory\AdHocMemoryProvider`、DeepSeek V4 交错思考强校验、
`Routing\AutoModelStrategy`、`Context\Strategies\CacheAwareCompressor`
均为加性能力 —— 不动 SDK 调用形状,不打开开关就保持 0.9.7 行为。

- **`Goals\EloquentGoalStore` + `AiGoal` 模型 + 迁移** *(0.9.1)* —— 给 SDK 0.9.8 的 `Goals\Contracts\GoalStore` SPI 提供持久化后端。每个 thread 在非终态(`active` / `paused` / `budget_limited`)中至多一行;暂停的 goal 在宿主进程重启后仍保持暂停。`SuperAICoreServiceProvider` 把 `GoalStore::class → EloquentGoalStore::class` 绑死并把 `GoalManager` 注册为单例,`app(GoalManager::class)` 自动注入持久化 store。已经在自家表里维护 goal 的 host 直接换绑契约即可,不需要 fork。新增 `ai_goals` 表 —— 跑 `php artisan migrate` 拾取;不用 `Goals\GoalManager` 的 host 这张表保持空闲,本包代码不会主动写入。
- **三档审批闸门** *(0.9.1)* —— `Runner\ApprovalMode` (`Auto` / `Suggest` / `Never`) + `ApprovalGate` + `ApprovalDecision`,对齐 codex `/permissions` 命令。只读白名单(`agent_grep` / `agent_glob` / `agent_read` / `agent_ls` / `web_search` / `web_fetch` / `agent_get_goal`)在所有模式下放行。`Suggest` 模式下,变更类调用返回 `canRetry: true` + 错误码 `mutation_pending_approval`(若现有 `Guidance\Gates\DestructiveCommandScanner` 命中破坏性命令则为 `destructive_pending_approval`);带 `tool_use_id` 的一次性 override token 解锁单次重试 —— 把 codex 的 `/approve` 流程搬到 API 形状。`Auto` 模式让普通变更直接通过,但破坏性命令仍要 `/approve`;`Never` 完全只读。`app(ApprovalGate::class)` 解析。
- **`Plugins\WorkspacePluginRegistry`** *(0.9.1)* —— codex"workspace plugin sharing"模式。团队把 `.superaicore/workspace-plugins.json` 提交到 repo,registry 与本地已装 plugin 名单做 diff 后返回 `missing_required`(scope=`workspace`,所有人必装)与 `missing_recommended`(scope=`user`,仅提示)。新人 `git clone` 即拿到全套工具,不用一份机器特定的 onboarding 文档。基于 `base_path()` 注册为单例。
- **无头 `GET /v1/usage` JSON 端点** *(0.9.1)* —— `Http\Controllers\UsageApiController` 对齐 codex app-server `/v1/usage` 形状。每次请求一个轴:`group_by=day | model | provider | thread | backend | task_type`。复用 HTML 控制器的过滤参数(`model`、`task_type`、`user_id`、`backend`、`days`)。鉴权由 host 负责 —— 把路由组挂在自己的中间件后面。每个 bucket 含 `runs / cost_usd / shadow_cost_usd / input_tokens / output_tokens / cache_read_tokens / cache_hit_rate`。
- **每行 usage 自带 `metadata.cache_hit_rate`** *(0.9.1)* —— 只要行内 cache 切片非零,`UsageRecorder` 就把 `cache_hit_rate ∈ [0, 1]` 戳进 metadata。分母用 GROSS prompt(未命中输入 + cache 读),仪表盘按模型 / 日 / backend 分组求平均时不需要重新推导分母。无 cache 活动时不戳 —— 区分"无 cache 可用"与"0% 命中率"。也接受 DeepSeek V3 / R1 老 wire 的 `cache_hit_tokens` 别名。`/usage` 现在能直接回答"本周期有多少 paid prompt 是免费的?" —— 跟 DeepSeek-TUI 在每轮结束问的同一问题,只是聚合视角。新增 `total_cache_read_tokens` 汇总卡。

完整菜谱(GoalStore 自定义实现、审批闸门接线、workspace plugin manifest、
`/v1/usage` 调用示例、cache-hit-rate 仪表盘):见
[docs/advanced-usage.zh-CN.md §22–§26](docs/advanced-usage.zh-CN.md)。

### TaskRunner 可靠性波次（0.9.2）

长任务在主 CLI/API 撞上 quota 或 rate limit 时,现在可以按运行级别切到
下一个 backend。0.9.2 把它作为 TaskRunner 可靠性层来交付:显式/自动链、
失败上下文 handoff、attempt 报告、UI 持久化入口、安全 retry 边界。Fallback
不带粘性状态:每次仍先尝试调用方请求的 backend,所以主 backend 恢复后会自然接回流量。

- **显式链** —— `fallback_chain` 可传
  `['claude_cli', 'codex_cli', 'gemini_cli']`;缺少主 backend 时 TaskRunner
  自动前置,并去重。
- **Workload 策略** —— 可传 `fallback_profile`,也可通过 `task_type` /
  `capability` 解析配置里的 `chains_by_profile`、`chains_by_task_type`
  或 `chains_by_capability`。
- **自动链** —— `fallback_chain => 'auto'` 从已注册/启用 backend 构建链;
  `AI_CORE_TASK_FALLBACK_CHECK_AVAILABILITY=true` 时还会先跑 availability 检查。
- **限流感知 handoff** —— 默认 `fallback_on` 覆盖 `rate limit`、
  `usage limit`、`quota`、`429`、`too many requests`、
  `usage_not_included` 等常见信号。非匹配失败停在原 backend,避免掩盖真实
  prompt/tool 错误。
- **继承失败上下文** —— 默认把原 prompt 加上一小段失败输出/log 摘要交给下一
  backend;传 `inherit_failure_context=false` 可关闭。
- **`TaskResultEnvelope::$fallbackReport`** 记录每次尝试的 backend、序号、
  成功状态、exit code、model、log file 与错误摘要。
- **按 workload 分策略** —— coding、research/summarisation、后台维护可以各自有
  fallback 链,不必用一条全局 retry 规则覆盖所有任务类型。
- **Operator 观测** —— 紧凑 report 与每次 attempt 的 Dispatcher metadata
  可以存到 task 行或 usage 行,渲染成 "primary limited, continued on codex",
  并直接链接到每次 attempt 的日志。
- **可靠性分析** —— 把 `fallbackReport` 与 `ai_usage_logs.backend` 联合使用,
  找出经常撞 quota 的主 backend 和实际完成工作的次级 backend。
- **安全发布路径** —— 先用 per-call chain,稳定后提升到配置,确认 availability
  与计费行为后再开自动 fallback。

全局默认在 `super-ai-core.task_fallback`;env 开关包括
`AI_CORE_TASK_FALLBACK_AUTO`、`AI_CORE_TASK_FALLBACK_CHAIN`、
`AI_CORE_TASK_FALLBACK_CHECK_AVAILABILITY`、
`AI_CORE_TASK_FALLBACK_INHERIT_CONTEXT`。详见
[docs/advanced-usage.zh-CN.md §27](docs/advanced-usage.zh-CN.md) 和
[docs/task-runner-quickstart.md](docs/task-runner-quickstart.md)。

### Squad 多智能体 + SDK 1.0.0 波次（0.9.6）

SDK 约束移到 `^1.0`。SuperAICore 0.9.6 把 SDK 1.0.0 的 `Squad`
peer-collaboration pipeline 作为第十个 dispatcher adapter 落地，并把
SDK 0.9.8 的配套原语（`AutoModelStrategy`、`CacheAwareCompressor`、
`UntrustedInput`、`TokenBucket`、`AdHocMemoryProvider`、
`Conversation\Fork`、`AgentDepthGuard`、DeepSeek FIM）封装成一等公民
宿主服务，让任意 dispatch 路径都能寻址。每个绑定都是加性且 opt-in
—— 未启用 flag、未传新 option、未从容器解析新服务的宿主，0.9.6
之前的行为完全保留。无 schema 变更。

- **`SquadBackend` —— SDK 1.0.0 自适应跨模型 pipeline**（0.9.6）——
  `super-ai-core.squad.enabled=true` 且 SDK 1.0.0 类位于 classpath 时，
  注册为第十个 dispatcher adapter。通过 `Squad\TaskDecomposer` +
  `Squad\PeerOrchestrator` 驱动一条启发式分解的 pipeline，每个子任务
  分配独立模型（经 `Squad\ModelTierMap` 映射），按步骤写
  `SquadCheckpointStore`，节点间通过 SDK 的 `PeerMailbox` 通讯，可选
  cost cap 在 80% 预算时自动 downshift。中途失败 checkpoint 留在
  磁盘；用同样的 `squad_id` + `checkpoint_dir` 重新 dispatch 即可恢复。
  Envelope 携带 `squad: {squad_id, step_count, completed, roles,
  checkpoint_path, mailbox_log}`。Tier map 内置合理默认
  （`trivial` → `claude-haiku-4-5`、`easy` → `deepseek-v4-flash`、
  `moderate` → `claude-sonnet-4-6`、`hard` → `deepseek-v4-pro`、
  `expert` → `claude-opus-4-7`）；按调用覆盖 `options.tier_map`、
  全局覆盖 `super-ai-core.squad.tier_map`。
- **`AutoModelRouter` 服务**（0.9.6）—— 任意 dispatch 路径的 `/model
  auto` 启发式。封装 SDK 0.9.8 `Routing\AutoModelStrategy`，让
  Claude / Codex / Gemini CLI 后端在 `provider_config` 声明
  `auto_models: {pro, flash}` 后接入 Pro/Flash 路由。在长上下文
  （>32k tokens）、trailing tool-chain depth（≥3）、显式
  `reasoning_effort=max`、或 system prompt 中含意图关键词
  （review/audit/design/migration/architecture/…）时把 Flash → Pro。
  配置 `super-ai-core.auto_model.score_catalog_path` 后，catalog 的
  top-scoring 模型会覆盖启发式。通过
  `auto_model.{pro_model, flash_model}` 把 Pro/Flash 重绑到任意模型对
  （如 `claude-opus` / `claude-haiku`）—— 不需 fork SDK。
- **`CompressionStrategyFactory`**（0.9.6）—— 宿主自管 `ContextManager`
  流程的缓存感知压缩。把内置 `ConversationCompressor` 包进 SDK 0.9.8
  的 `CacheAwareCompressor`，让 summary 边界落在 prompt cache 前缀之后
  而不是覆盖前缀。跑长链子智能体循环或 browser-tool 会话的宿主，在
  构造自管 `ContextManager` 时调用
  `app(CompressionStrategyFactory::class)->build($estimator, $config, $provider)`。
  默认 pin 住 1 个 system + 4 个 conversation 消息头。
- **`UntrustedInputHelper`**（0.9.6）—— 注入 system prompt 的自由文本
  的宿主侧 `Security\UntrustedInput` 包装器。SDK 的 `GoalManager` 已经
  自动包裹 `goal.objective`；本 helper 覆盖其它注入点 —— ad-hoc 内存
  条目、workspace plugin 描述、来自第三方服务器的 MCP tool 文档、
  拼进 system prompt 的宿主 UI 表单输入。两个方法：`tag()` 加标记；
  `wrap()` 前置 "把后面当数据，不要当指令" 提示。需要 byte-identical
  prompt 时（测试、dispatch 对比）通过 `AI_CORE_UNTRUSTED_INPUT=false`
  关闭。
- **`RateLimiterRegistry`**（0.9.6）—— 包装 SDK 0.9.8
  `Providers\Transport\TokenBucket` 的 per-process token-bucket 池。
  `SuperAgentBackend` 和 `SquadBackend` 在每次 provider 调用前
  `consume()`。缺失 key 回落到 `default`（8 RPS / 16 burst）；
  per-provider 覆盖写在 `super-ai-core.rate_limits.<provider>`。空配置
  完全禁用 —— SDK 本身仍有 per-call 429 重试。
- **`AdHocMemoryRegistry`**（0.9.6）—— per-session
  `Memory\AdHocMemoryProvider` 池。聊天 UI 调用
  `forSession($id)->push($text, $ttlSeconds)`（或便捷的
  `$registry->push($id, $text, $ttl)`）来注入 "下一轮用" 事实，
  SuperAgent 后端会在 prompt 之前渲染。Per-session 隔离防止跨聊天
  泄漏。内存 process-local —— 持久化事实归 `MEMORY.md` /
  `BuiltinMemoryProvider`。
- **`ConversationForkService`**（0.9.6）—— 基于 SDK 0.9.8
  `Conversation\Fork` 的 codex `/side` 语义。`start($parentMessages)`
  快照消息列表并返回 fork handle；`finish($fork, $action, $indexes?)`
  以 `discard` / `promote(...indexes)` / `promoteAll` 收尾。适合
  "分支并用不同模型试一下、只把有用的侧支消息 promote 回来" 的聊天
  UI。
- **`DeepSeekFimService`**（0.9.6）—— SDK 0.9.8
  `DeepSeekProvider::completeFim()`（`beta` 区域）的独立封装。
  chat-shaped `Backend` 抽象不适配 FIM，所以构建 IDE 风格补全功能的
  宿主直接调本服务：
  `app(DeepSeekFimService::class)->complete($prefix, $suffix,
  ['max_tokens' => 64])`。
- **`SuperAgentBackend` 的 `reasoning_effort` 三档拨盘**（0.9.6）——
  按调用 `reasoning_effort: 'off' | 'high' | 'max'` 透传给 SDK 的
  `reasoning_effort` per-call option。通过 SDK 的
  `SupportsReasoningEffort` 能力接口按 upstream 路由到正确的 body
  shape。不实现该能力的 provider 静默忽略。设为 `max` 时同时喂给
  `AutoModelRouter` 的升级启发式。
- **`Agent::switchProvider()` handoff**（0.9.6）—— 传
  `options.handoff: {provider, config, policy}` 后，`SuperAgentBackend`
  在 dispatch 前调 `Agent::switchProvider()`。Envelope 多
  `handoff_token_status: {tokens, window, fits, model}`，前端可以
  预警 "下一轮的历史装不下 <target_model> —— 先压缩"。新 provider
  构造失败时原 agent 保持可用（SDK 契约）。
- **`smart` / `squad` 控制台命令**（0.9.6）—— 对 vendor
  `superagent smart` / `superagent auto --squad` 的透传。复用 operator
  现有的 SuperAgent 凭据和 SDK CLI 行为，不在 PHP 里重写编排器：
  ```bash
  ./vendor/bin/superaicore smart "审计这个 diff"
  ./vendor/bin/superaicore smart show --last
  ./vendor/bin/superaicore squad "重构 auth 模块" --max-cost=2.0
  ./vendor/bin/superaicore squad --no-squad "对比 legacy 路径"
  ```
- **`super-ai-core.agents.max_depth`**（0.9.6）—— 服务提供者启动时
  转发到 SDK 0.9.8 `Swarm\AgentDepthGuard::setMax()`。负数 / 未设
  保留 SDK 默认（5）。per-process 覆盖：`SUPERAGENT_MAX_AGENT_DEPTH`
  env 变量。

完整菜谱（Squad pipeline、AutoModelRouter 接入、
CacheAwareCompressor 布线、RateLimiterRegistry 覆盖、
AdHocMemoryRegistry 聊天 UI 接入、ConversationForkService
侧边面板、DeepSeek FIM 补全端点）见
[docs/advanced-usage.zh-CN.md §28](docs/advanced-usage.zh-CN.md)。

### opencode 借鉴特性波次（0.9.7 / SDK 1.0.5）

SDK 约束 `^1.0` → `^1.0.5`，引入跨 provider handoff 的 transcoder
修复、opencode `BashArity` 权限匹配、opencode 7 段紧凑摘要模板、
SDK 内置的真实 LSP 客户端（`LSPTool`）、`LlmLoopChecker` 语义循环
检测、ACP v1 stdio 服务器，以及带 thinking + grounding + thought-
parts 的 Gemini 3.5 / 3.x 系列。在 SDK bump 之上，又从
[opencode](https://github.com/sst/opencode)（`packages/opencode/src/`）
移植了 10 个模式作为 SuperAICore 的第一类特性。升级后请运行
`php artisan migrate` —— 0.9.7 新增 3 张表 + `ai_usage_logs` 上 3
个新字段。

- **每次调度的逐文件 diff 摘要**（0.9.7）—— `SuperAgentBackend`
  通过 SDK 的 `GitShadowStore` 在每次调用前后对工作区做快照，
  `Services\SnapshotDiffService` 生成结构化的
  `{additions, deletions, files, diffs[]}` envelope，每个 diff 含
  `{file, additions, deletions, status, patch, truncated}`。落库在
  `ai_usage_logs.file_diff_summary`，同时记录两个 snapshot 哈希
  （`pre_snapshot`、`post_snapshot`）。`/usage` 页在每行显示 `+N −M`
  badge + 侧边 diff 查看面板。对应 opencode `session/summary.ts` +
  `snapshot.diffFull()`。
- **运行中 HITL `ask_user` 工具**（0.9.7）——
  `Services\Tools\AskUserTool`（通过 `AI_CORE_TOOLS_ASK_USER=true`
  启用）允许 agent 在运行中中断并询问操作者，可附预定义选项。
  行写入新表 `ai_user_questions`，在 `/processes` 上以内联卡片渲染
  （4 秒轮询）。对应 opencode `tool/question.ts`。端点：
  `/processes/questions{,/{id}/answer,/{id}/cancel}`。
- **工作区回滚到调度前 snapshot**（0.9.7）——
  `POST /usage/{id}/revert` 读取 UsageLog 行的 `pre_snapshot` 并通过
  SDK 的 `GitShadowStore::restore()` 还原。已追踪文件回滚，未追踪文件
  保留。由 `AI_CORE_SNAPSHOT_REVERT_ENABLED` 控制（默认开）。
  `/usage` 页在记录有 snapshot 的行上显示 ↩ 按钮。
- **Shadow-git snapshot 保留策略**（0.9.7）——
  `super-ai-core:snapshot-prune` Artisan 命令遍历
  `~/.superagent/history/` 下每个 `shadow.git`，过期 `--days` 前的
  reflog（默认 7），随后执行 `git gc --prune=now`。支持 `--dry-run`。
  在宿主 `Kernel.php` 中用
  `$schedule->command('super-ai-core:snapshot-prune')->daily()`
  排程。对应 opencode 的 `prune = "7.days"` 策略。
- **会话提醒合成注入**（0.9.7）—— `Services\RemindersResolver`
  读取 `super-ai-core.reminders.rules`，当某条规则的 `when` 谓词
  （以 dotted-path → fnmatch 通配符匹配调度 options/metadata）命中
  时，把合成的 system-prompt 区块前置到调用方的系统提示。对应
  opencode `session/reminders.ts`。
- **按 agent 权限规则集**（0.9.7）—— `Services\PermissionEvaluator`
  移植 opencode `permission/evaluate.ts`（`{permission, pattern, action}`
  规则、last-match-wins、fnmatch 通配、默认 `ask`）。在
  `super-ai-core.agents.{name}.permission` 按 agent 配置；
  `SuperAgentBackend` 在调用方未显式传 `allowed_tools` / `denied_tools`
  时把规则集投影到 SDK agent 的 `withAllowedTools()` /
  `withDeniedTools()`。
- **Plan mode（`Modes\CliPlanOrchestrator`）**（0.9.7）——
  三阶段 plan → approve → build 流程。第 1 阶段以 plan-only 模式运行
  模型（仅允许写 plan 文件），把 markdown 计划写入
  `.superagent/plans/{session}.md`。第 2 阶段开 `ai_user_questions`
  行让操作者 `[Approve, Reject]`。第 3 阶段把任务交给 build backend，
  附带含已批准计划的合成 prompt。已通过 `CliModeRouter` 在
  `plan` 模式名下注册。HITL 关闭时自动批准，保证 CI 可用。配置：
  `super-ai-core.modes.plan.*`。对应 opencode `agent/agent.ts` +
  `tool/plan.ts`。
- **子 agent 权限推导**（0.9.7）—— `Services\SubagentPermissionDeriver`
  把父 agent 的 `denied_tools` 合并到子 agent，从而 read-only 父
  agent 永远产生 read-only 子 agent。读取
  `options['parent_denied_tools']`（显式）或
  `options['metadata']['parent_agent']`（通过 `PermissionEvaluator`
  解析）。对应 opencode `agent/subagent-permissions.ts`。
- **PTY 长连接 shell 会话，Phase 1**（0.9.7）——
  `Services\PtyService` + `Http\Controllers\PtyController`
  spawn `proc_open` 子进程，通过 cursor 长轮询把 stdout 流给客户端。
  端点：`POST /pty/sessions`（spawn）、
  `GET /pty/sessions/{id}/poll?cursor=N`（poll）、
  `POST /pty/sessions/{id}/kill`（terminate）。通过
  `AI_CORE_PTY_ENABLED=true` 启用。Phase 2（暂缓）将通过
  Reverb / Soketi 把传输升级为 WebSocket，cursor 协议不变。
- **会话分享主机队列**（0.9.7）—— `Services\ShareSessionService`
  为每个会话生成 `{share_id, secret, share_url}` 三元组，并把会话的
  UsageLog 行 + `file_diff_summary` 负载以 Bearer 鉴权的 POST 推送到
  远端分享服务（`AI_CORE_SHARE_REMOTE_URL`）。未配置远端时回退到
  本地 URL 模板（`AI_CORE_SHARE_LOCAL_URL_TEMPLATE`，含
  `{share_id}` 占位符）。对应 opencode `share/share-next.ts`。
- **SDK 1.0.5 LSP 工具**（0.9.7）—— 通过
  `AI_CORE_TOOLS_LSP=true` 启用，`SuperAgentBackend` 把 `lsp` 加入
  隐式 `load_tools`。agent 可调用 SDK 内置的 LSP 客户端（phpactor /
  intelephense / gopls / rust-analyzer / pyright / tsserver / clangd /
  bash-language-server / zls）。由 SDK 的 `BuiltinToolRegistry`
  classMap 懒加载。
- **Opencode 结构化紧凑摘要**（0.9.7）—— 设
  `AI_CORE_COMPRESSION_SUMMARY_PROMPT=structured` 让每次调度使用 SDK
  的 7 段 Markdown 摘要模板（Goal / Constraints / Progress /
  Decisions / Next Steps / Critical Context / Relevant Files）。比
  默认 9 段摘要小约 30-50%，跨多次紧凑能保留 blocked 状态。每次调用
  的 `options['summary_prompt']` 优先级更高。
- **Gemini 3.5 thinking + grounding + URL context**（0.9.7）——
  `thinking`、`grounding` / `google_search`、`url_context` 等 per-call
  选项直接转发给 SDK 的 `GeminiProvider`（其他 provider 静默忽略）。
  `EngineCatalog` 在 gemini-cli 引擎下列出
  `gemini-3.5-pro / -flash / -flash-lite`；`CopilotModelResolver`
  新增 `gemini` 家族别名映射到 `gemini-3-pro-preview`。

完整菜谱（逐文件 diff 仪表盘、AskUserTool 接入、plan mode 工作流、
会话提醒、按 agent 权限、PTY 会话、会话分享）见
[docs/advanced-usage.zh-CN.md §29](docs/advanced-usage.zh-CN.md)。

### CLI 安装器与健康检查

- **`cli:status`** —— 每家 CLI 的安装/登录状态与安装提示。
- **`cli:install [backend] [--all-missing]`** —— 走规范包管理器（`npm` / `brew` / `script`）安装缺失项，默认带确认。显式触发 —— 永不因为调度失败自动安装。
- **`api:status`**（0.6.8+）—— 对直连 HTTP API provider（anthropic / openai / openrouter / gemini / kimi / qwen / glm / minimax）做 5 秒 cURL 探测，每 provider 返回 `{ok, latency_ms, reason}`，让运维一眼分清 auth 被拒（401/403）、网络超时、key 没配。`--all` / `--providers=a,b,c` / `--json` 支持。与 `cli:status` 平行。

### Dispatcher 与流式输出

- **基于能力的路由** —— `Dispatcher::dispatch(['task_type' => 'tasks.run', 'capability' => 'summarise'])` 通过 `RoutingRepository` → `ProviderResolver` → 回退链解析出正确的后端 + provider 凭证。
- **`Contracts\StreamingBackend`**（0.6.6+）—— 每个 CLI 后端都能通过 `onChunk` callback 流式接收 chunks，同时 tee 到磁盘、登记 `ai_processes` 行供 Monitor UI 跟读。`Dispatcher::dispatch(['stream' => true, ...])` 透明 opt-in。支持每次调用配 `timeout` / `idle_timeout` / `mcp_mode`（claude 用 `'empty'` 防止全局 MCP 卡退出）。详见 `docs/streaming-backends.md`。
- **`Runner\TaskRunner` —— 一行调用执行任务**（0.6.6+）—— `Dispatcher::dispatch(['stream' => true, ...])` 的封装，返回类型化 `TaskResultEnvelope`（success / output / summary / usage / cost / log file / spawn report / fallback report）。把宿主约 150 行"build prompt → spawn → tee log → extract usage → wrap result"胶水折叠成一次调用。0.9.2 增加 TaskRunner 可靠性波次:opt-in backend fallback、continuation context、attempt 观测和按 workload 分策略。六个 CLI 接口完全一致。详见 `docs/task-runner-quickstart.md`。
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

- **真正的 agentic loop**（0.6.8+）—— `SuperAgentBackend` 支持 `max_turns`、`max_cost_usd` → `Agent::withMaxBudget()` 在 loop 内硬卡预算、`allowed_tools` / `denied_tools` 工具面过滤、`mcp_config_file`（加载 `.mcp.json`，`finally{}` 中 disconnect）、`provider_config.region` 适配 Kimi / Qwen / GLM / MiniMax 多区域分流。envelope 新增 `usage.cache_read_input_tokens` / `cache_creation_input_tokens`、`cost_usd`（SDK 自算）、`turns`。
- **`AgentTool` productivity 透传**（0.6.8+）—— 调用方开启 SDK 子 agent dispatch（`load_tools: ['agent', …]`）时，envelope 会多一个可选 `subagents` key，携带 `AgentTool` 的 productivity 信息（`filesWritten` / `toolCallsByName` / `productivityWarning` / `status: completed|completed_empty`）。
- **三个 0.9.0 选项透传**（0.6.9+）—— `extra_body`（深合并到每个 `ChatCompletionsProvider` 请求 body 顶层）、`features`（走 SDK `FeatureDispatcher`；常用键:`prompt_cache_key.session_id`、`thinking.*`、`dashscope_cache_control`）、`loop_detection: true|array`（把流处理 handler 包进 `LoopDetectionHarness`）。便捷写法:`prompt_cache_key: '<sessionId>'` 直接当 session_id shorthand。
- **分类的 `ProviderException` 子类**（0.7.0+）—— `SuperAgentBackend::generate()` 分别捕获 SDK 的六个子类（`ContextWindowExceeded` / `QuotaExceeded` / `UsageNotIncluded` / `CyberPolicy` / `ServerOverloaded` / `InvalidPrompt`），每个都以稳定的 `error_class` tag + `retryable` 标记记日志。契约不变（依然返回 `null`）；子类可 override `logProviderError()` seam 把分类塞到 envelope 里做更精细的路由。
- **`createForHost` host-config adapter 迁移完成**（0.8.5+）—— `SuperAgentBackend::buildAgent()` 折叠成单一的 `ProviderRegistry::createForHost($sdkKey, $hostConfig)` 调用，不再按 `region` 分支也不再手工拼装每个 provider 的构造形状。SDK 侧的 per-key adapter（默认 adapter 适配所有 ChatCompletions 类 provider；`bedrock` 有专属 adapter 拆分 AWS 凭据；`openai-responses` 内建 Azure 自动识别；LMStudio 自动合成 auth）拥有构造形状的映射权。SDK 后续新增的 provider key 在这里零代码改动就能落地 —— adapter 是扩展点。
- **SDK 锁到 0.9.5**（0.8.5+）—— Composer 约束 `^0.9.5`。修复了非 Anthropic provider 多轮 tool-use 回放的硬伤（0.9.5 之前 `ChatCompletionsProvider::convertMessage()` 在第一个 `tool_use` block 提前 return，丢掉同级 text 和并行 tool call，并访问根本不存在的 `ContentBlock` 属性 —— Kimi / GLM / MiniMax / Qwen / OpenAI / OpenRouter / LMStudio 上每个回放的 tool call 都以 `{id: null, name: null, arguments: "null"}` 出去）；SDK 把六种 wire family 全统一到一个 `Conversation\Transcoder` 后面，bug 修一次所有 provider 同步生效。另外 `Agent::switchProvider($name, $config, $policy)` 现在可用了，支持进程内对话中途切 provider（`HandoffPolicy::default() / preserveAll() / freshStart()` 三种预设策略）—— 直接包 `SuperAgentBackend` 的宿主可以用。

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

### Skill engine 命令（artisan，0.8.6+）

通过包的 service provider 挂载到 Laravel artisan —— 任何宿主都能 `php artisan` 直接用：

```bash
# Hook 接入点 —— 从 stdin 读 Claude Code hook 负载
php artisan skill:track-start --json     # PreToolUse(Skill) —— 插入 in_progress 行
php artisan skill:track-stop  --json     # Stop —— 关闭 session 里所有未完成的行

# 读统计
php artisan skill:stats --since=7d --sort=failure_rate
php artisan skill:stats --skill=research --format=json

# 按任务描述给 skill 排序（BM25 + 遥测加成）
php artisan skill:rank "estimate effort for an outsource project"
php artisan skill:rank "重构认证模块" --no-telemetry --format=json

# 入队一个 FIX 模式 candidate（仅审核，永不自动应用）
php artisan skill:evolve --skill=research                          # 手动触发
php artisan skill:evolve --skill=research --dispatch               # 顺便调用 LLM（烧 token）
php artisan skill:evolve --sweep --threshold=0.30 --min-applied=5  # 扫所有指标退化的 skill

# 查看队列
php artisan skill:candidates                                       # 列出 pending
php artisan skill:candidates --id=42 --show-prompt --show-diff     # 完整详情
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

返回类型化的 `TaskResultEnvelope`，包含 `success` / `output` / `summary` / `usage` / `costUsd` / `shadowCostUsd` / `billingModel` / `logFile` / `usageLogId` / `spawnReport` / `fallbackReport` / `error`。六个 CLI 引擎接口完全一致，业务代码不再 per-backend 分支。

给 quota/rate-limit 失败加 fallback:

```php
$envelope = app(TaskRunner::class)->run('claude_cli', $prompt, [
    'fallback_chain' => ['claude_cli', 'codex_cli', 'gemini_cli'],
    'fallback_on' => ['rate limit', 'usage limit', 'quota', '429'],
    'inherit_failure_context' => true,
]);
```

启用 fallback 时,`$envelope->fallbackReport` 会带上尝试过的 backend 链与最终成功/失败状态。

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

- **[高级用法指南](docs/advanced-usage.zh-CN.md)** —— 幂等 key 往返、W3C trace context、分类的 provider exception、`openai-responses` + Azure OpenAI + ChatGPT OAuth、LM Studio、`http_headers` / `env_http_headers` 覆盖、SDK features（`extra_body` / `features` / `loop_detection`）、`ScriptedSpawnBackend` 宿主迁移、Skill engine 遥测 / BM25 ranker / FIX 模式演化（0.8.6+）、**0.9.0 jcode 波次**、**0.9.1 DeepSeek-TUI 对齐波次**、**0.9.2 TaskRunner 可靠性波次**，以及 **0.9.6 Squad 多智能体 + SDK 1.0.0 波次**。
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
