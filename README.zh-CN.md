# forgeomni/superaicore

[![tests](https://github.com/ForgeOmni/SuperAICore/actions/workflows/tests.yml/badge.svg)](https://github.com/ForgeOmni/SuperAICore/actions/workflows/tests.yml)
[![license](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![php](https://img.shields.io/badge/php-%E2%89%A58.1-blue.svg)](composer.json)
[![laravel](https://img.shields.io/badge/laravel-10%20%7C%2011%20%7C%2012-orange.svg)](composer.json)

[English](README.md) · [简体中文](README.zh-CN.md) · [Français](README.fr.md)

用于统一调度十种 AI 执行引擎的 Laravel 包 —— **Claude Code CLI**、**Codex CLI**、**Gemini CLI**、**GitHub Copilot CLI**、**AWS Kiro CLI**、**Moonshot Kimi Code CLI**、**Alibaba Qwen Code CLI**、**Cursor Composer CLI**、**xAI Grok Build CLI**、**SuperAgent SDK**。内置独立于框架的 CLI、基于能力（capability）的调度器、MCP 服务器管理、使用量记录、成本分析、OpenAI 兼容代理、magic-trace 风格的环形追踪缓冲，以及一套完整的后台管理 UI。

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
  - [Qwen + 追踪 + 9Router 波次（0.9.8）](#qwen--追踪--9router-波次098)
  - [Opus 4.8 + Grok + Cursor 波次（1.0.0 / SDK 1.0.9）](#opus-48--grok--cursor-波次100--sdk-109)
  - [kimi-cli + kimi-code 双轨波次（1.0.2 / SDK 1.0.10）](#kimi-cli--kimi-code-双轨波次102--sdk-1010)
  - [SmartFlow 跨 CLI 工作流波次（1.0.5 / SDK 1.1.0）](#smartflow-跨-cli-工作流波次105--sdk-110)
  - [CLI skill 桥接波次（1.0.6）](#cli-skill-桥接波次106)
  - [MiniMax M3 + 目录重定价波次（1.0.7 / SDK 1.1.1）](#minimax-m3--目录重定价波次107--sdk-111)
  - [streamChat MCP 波次（1.0.8）](#streamchat-mcp-波次108)
  - [GLM-5.2 原生旗舰波次（1.0.10 / SDK 1.1.2）](#glm-52-原生旗舰波次1010--sdk-112)
  - [Fable 5 与 Sonnet 5 波次（1.0.11 / SDK 1.1.5）](#fable-5-与-sonnet-5-波次1011--sdk-115)
  - [ai-dispatch 对齐波次（1.1.0）](#ai-dispatch-对齐波次110)
  - [GPT-5.6 + Grok 4.5 目录刷新波次（1.1.6 / SDK 1.1.6）](#gpt-56--grok-45-目录刷新波次116--sdk-116)
  - [Kimi K3 波次（1.1.7 / SDK 1.1.7）](#kimi-k3-波次117--sdk-117)
  - [Kimi Code 0.27 支持刷新波次（1.1.8）](#kimi-code-027-支持刷新波次118)
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

- **十个执行引擎**，统一实现同一套 `Dispatcher` 契约：
  - **Claude Code CLI** —— provider 类型：`builtin`（本地登录）、`anthropic`、`anthropic-proxy`、`bedrock`、`vertex`。
  - **Codex CLI** —— `builtin`（ChatGPT 登录）、`openai`、`openai-compatible`。
  - **Gemini CLI** —— `builtin`（Google OAuth）、`google-ai`、`vertex`。
  - **GitHub Copilot CLI** —— 仅 `builtin`（`copilot` 二进制自行处理 OAuth / keychain / 刷新）。原生读取 `.claude/skills/`（零翻译直通）。**订阅计费** —— 仪表盘独立统计。
  - **AWS Kiro CLI**（0.6.1+）—— `builtin`（本机 `kiro-cli login` 登录态）、`kiro-api`（DB 存的 key 注入成 `KIRO_API_KEY` 走 headless 模式）。CLI 后端里自带能力最全的一家 —— 原生 agents、skills、MCP，以及**原生 subagent DAG 编排**（不走 `SpawnPlan` 模拟）。Skill 直接复用 Claude 的 `SKILL.md` 格式。**按 credits 订阅计费**（Pro / Pro+ / Power 套餐）。
  - **Moonshot Kimi Code CLI**（0.6.8+）—— `builtin`（`kimi login` 走 `auth.kimi.com` OAuth）。与 SDK 内置的直连 HTTP `KimiProvider` 互补，专门覆盖 OAuth 订阅的 agentic-loop 路径，和 `anthropic_api` ↔ `claude_cli` 是同样的分工。默认走 Kimi 原生 `Agent` fanout；需要切到 AICore 三阶段 Pipeline 时设 `use_native_agents=false`。**订阅计费** —— Moonshot Pro / Power。
  - **Alibaba Qwen Code CLI**（0.9.8+）—— gemini-cli 的分支（`QwenLM/qwen-code` v0.16.0），适配 Qwen 模型家族。仅支持 API key（`DASHSCOPE_API_KEY` / `QWEN_API_KEY`），OAuth 免费层已于 2026-04-15 EOL。默认模型 `qwen3.7-max`：1M 上下文、$2.50/$7.50 per 1M、原生支持 Anthropic `/v1/messages` 协议（在 fallback 链里可作为 Claude 的无缝替代）。**用量计费。**
  - **Cursor Composer CLI**（1.0.0+）—— `builtin`（`cursor-agent login` 浏览器 OAuth → `~/.cursor`；headless 可导出 `CURSOR_API_KEY`）。Cursor 的 headless Composer 智能体（`cursor-agent`）。默认模型 `composer-2.5-fast`，同时代理 Anthropic（`claude-opus-4-8-thinking-high`）与 OpenAI（`gpt-5.x-codex`）模型及 `auto` 路由。MCP 走 `.cursor/mcp.json`。**订阅计费** —— Cursor 套餐。
  - **xAI Grok Build CLI**（1.0.0+）—— `builtin`（`grok login` grok.com OAuth → `~/.grok`）。xAI 的「Grok Build」agentic CLI（`grok`）。默认模型 `grok-build`；原生 sub-agents、effort 控制（`--effort low…max`）、MCP 走 `grok mcp add`。**订阅计费** —— grok.com 套餐。*（与下方计量的 xAI **API** provider 类型是两条独立通道。）*
  - **SuperAgent SDK** —— provider 类型：`anthropic`、`anthropic-proxy`、`openai`、`openai-compatible`，加上 `openai-responses`（0.7.0+）、`lmstudio`（0.7.0+）、`deepseek`（0.9.0+）、`qwen-anthropic`（0.9.8+），以及 `grok`（1.0.0+ —— 计量的 xAI API，`XAI_API_KEY`/`GROK_API_KEY`，默认 `grok-4.3`，1M 上下文）。
- **`openai-responses` provider 类型**（0.7.0+）—— 通过 SDK 的 `OpenAIResponsesProvider` 走 `/v1/responses`。依据 `base_url` 形状自动识别 Azure OpenAI 部署（自动追加 `api-version=2025-04-01-preview`；可通过 `extra_config.azure_api_version` 覆盖）。若此行没存 API key 而是 `extra_config.access_token`（来自宿主 ChatGPT-OAuth 流程），SDK 会自动把 base URL 切到 `chatgpt.com/backend-api/codex`，让 Plus / Pro / Business 订阅用户走自家订阅配额。
- **`lmstudio` provider 类型**（0.7.0+）—— 本地 LM Studio 服务（默认 `http://localhost:1234`）。走 OpenAI-compat 接线，无需真 API key —— SDK 自动合成占位 `Authorization` 头。
- **十三个 dispatcher 适配器**对应十个引擎（`claude_cli`、`codex_cli`、`gemini_cli`、`copilot_cli`、`kiro_cli`、`kimi_cli`、`qwen_cli`、`cursor_cli`、`grok_cli`、`superagent`、`anthropic_api`、`openai_api`、`gemini_api`）—— `builtin` / `kiro-api` 走 CLI 适配器，API Key 走 HTTP 适配器。CLI 也可以直接指定这些适配器名。
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

### Kimi Code 0.27 支持刷新波次（1.1.8）

无 SDK bump —— 纯 SuperAICore 刷新,对照 Moonshot kimi-code v0.27.0 真机复验。
新 CLI 把整个状态目录从 `~/.kimi/`(legacy Python kimi-cli)搬到了
`$KIMI_CODE_HOME`(默认 `~/.kimi-code/`);以下各面现在按目录布局自动选路径,
legacy 装机行为原样保留。

- **登录检测修复** —— `doctor` / providers UI 此前只查 `~/.kimi/credentials/`,
  已登录的 kimi-code 装机(凭证在 `~/.kimi-code/credentials/kimi-code.json`)
  被误报未登录。现在两代路径都查。
- **MCP 同步写对文件了** —— `claude:mcp-sync` fan-out 原来写 `~/.kimi/mcp.json`,
  新 CLI 根本不读;检测到新布局时改写 `~/.kimi-code/mcp.json`(同样的 Claude
  兼容 `mcpServers` JSON,手工编辑的键继续保留)。
- **非 login shell 也能找到二进制** —— 官方安装器把单文件二进制装到
  `~/.kimi-code/bin`,fpm / 队列 / cron 的 PATH 不含它;`CliBinaryLocator` 和
  `KimiCliBackend::isAvailable()` 三平台直接探测该目录。
- **安装器默认源现代化** —— `cli:install kimi` 现在跑 Moonshot 官方安装脚本;
  `--via=uv` / `--via=pip` 保留给 legacy Python CLI。
- **技能原生落地** —— kimi-code 自动发现 `~/.kimi-code/skills/`(SKILL.md 包),
  技能桥把 Kimi 从"instructions 摘要文件"升级为逐技能一等安装,与
  Codex / Gemini / Grok / Cursor 同级。
- **工具名映射修正** —— 线上抓包证实 kimi-code 的工具名已是 Claude Code 风格
  (`Bash`,不再是 legacy 的 `Shell`);翻译映射现在只作用于 legacy 装机。
- **dispatch 零改动** —— headless 契约(`--prompt` +
  `--output-format stream-json`)在 0.27 完全一致,`KimiCliBackend` 只更新了
  注释。完整调研见 `docs/kimi-cli-backend.md` §9。

### Kimi K3 波次（1.1.7 / SDK 1.1.7）

SDK 约束从 `^1.1.6` 移到 `^1.1.7`。SuperAgent 1.1.7 带来 **Kimi K3** ——
Moonshot 新的开源通用旗舰（2026-07-16 发布），也是 SDK 新的零配置 `kimi`
默认模型。纯增量、不破坏 —— 无迁移、无配置变更。

- **Kimi K3 已定价** —— `kimi-k3`（2.8T 开源权重 MoE、1M 上下文、常开思考、
  图像 + 视频输入）按官方计量 API 价格 **输入 $3 / 缓存 $0.30 / 输出 $15**
  （每 1M）种入 `model_pricing`，`CostCalculator` 无需回查目录即可离线计价。
  编码向的 `kimi-k2.7-code` 保持不变；退役的 `kimi-k2-6` 仍可按 id 调用
  （通过 SDK 的 `ModelCatalog` 解析）。Kimi 原生零配置默认（`kimi` →
  `kimi-k3`）由 SDK 侧的 `KimiProvider` 拥有，SuperAICore 原样转发；订阅制
  `kimi` CLI 引擎（kimi-code OAuth，$0/token）是独立的一面，保持不变。
- **修复：`superaicore --version`** 现在正确报告 `1.1.7`（此前卡在 `1.1.5`
  —— 1.1.6 发布时漏改）。
- **内部清理** —— `SuperAgentBackend::buildPerCallOptions` 现在把重复的字符串
  转发收敛到两个辅助方法（`putRawString` / `putLoweredString`）；行为保持不变，
  并带回归测试。

### GPT-5.6 + Grok 4.5 目录刷新波次（1.1.6 / SDK 1.1.6）

SDK 约束从 `^1.1.5` 移到 `^1.1.6`。SuperAgent 1.1.6 带来 **GPT-5.6**
（Sol / Terra / Luna —— 新的 `openai-responses` 默认）与 **Grok 4.5**
（新的 `grok` 默认）及其请求面，并把 Gemini / DeepSeek / MiniMax / GLM /
Qwen 目录修正为官方价格；SuperAICore 转发新的每次调用选项、把修正后的价格
镜像进自己的 `model_pricing` 表，并修复 Gemini 选择器漂移
（`gemini-3.5-pro` / `gemini-3.5-flash-lite` 从未上线）。纯增量、不破坏 ——
无迁移、无配置变更。

- **转发 GPT-5.6 / Gemini 3.5 请求面** —— `SuperAgentBackend` 现在把
  `reasoning_mode`（`standard`|`pro`，Sol Pro）、`reasoning_context`
  （`auto`|`all_turns`|`current_turn`）与 `prompt_cache_options`（显式
  缓存：写 1.25×，读保持 −90%）转发给 SDK 的 `OpenAIResponsesProvider`，
  并把 `thinking_level`（`minimal`…`high`，取代 `thinkingBudget` 的控制项）
  转发给 `GeminiProvider`。四个选项在不支持的 provider 上都会被静默忽略。
  既有的 `reasoning_effort` 档位在 GPT-5.6 上获得 `none`/`max`、在
  Grok 4.5 上获得常开三级档 —— 均在 SDK 侧完成，SuperAICore 无需改动。
- **新模型定价** —— `gpt-5.6-sol` 每 1M **$5 / 缓存 $0.50 / $30**、
  `gpt-5.6-terra` **$2.50 / $0.25 / $15**、`gpt-5.6-luna`
  **$1 / $0.10 / $6**（均 1.05M 上下文）；`grok-4.5` **$2 / 缓存 $0.50 /
  $6**（500K 上下文，新 `grok` 默认；`grok-4.3` 仍可按 id 调用）；
  `gemini-3.5-flash`（真正的旗舰）**$1.50 / 缓存 $0.15 / $9**；
  `gemini-3.1-pro-preview` $2/$12；`gemini-3.1-flash-lite` $0.25/$1.50；
  `kimi-k2.7-code` $0.95 / 缓存命中 $0.19 / $4（`-highspeed` 为 2×）；
  `glm-5-turbo` / `glm-5v-turbo` $1.20/$4。
- **镜像全线价格修正** —— `gpt-5` 修正为官方 **$1.25/$10**（原为 $5/$15
  估价）、`deepseek-v4-flash` 输出 **$0.55 → $0.28**（+$0.0028 缓存命中
  档）、`MiniMax-M3` 改为永久分层价 **$0.30/$1.20**（缓存读 $0.06）、
  `qwen3.7-plus` 改为 GA 价 **$0.40/$1.60**。若宿主还带着旧配置副本，
  请重新发布配置。
- **Gemini 目录修正回现实** —— `gemini-3.5-pro` 与 `gemini-3.5-flash-lite`
  从未公开上线，已从 `gemini` 引擎选择器移除；`gemini-3.5-flash` /
  `gemini-3.1-pro-preview` / `gemini-3.1-flash-lite` 进入 `EngineCatalog`
  与 `GeminiModelResolver`。SDK 零配置默认迁移（`openai-responses` →
  `gpt-5.6-sol`、`grok` → `grok-4.5`、`gemini` → `gemini-3.5-flash`）；
  所有已上线过的 id 都仍可显式配置调用。
- **订阅 CLI 目录实测刷新（2026-07-12）** —— Grok Build 计划（grok CLI
  0.2.93）现以 `grok-4.5` 为订阅默认并新增 `grok-composer-2.5-fast`
  （`grok-build` 保留为遗留行）；Cursor Composer 阵容（约 189 个 slug）
  以 `composer-2.5` 为 "current"，并代理 Fable 5 / Sonnet 5 / GPT-5.6
  Sol / Grok 4.5 / Gemini 3.5 Flash / Kimi K2.7 Code / GLM 5.2 ——
  `GrokModelResolver`、`CursorModelResolver`（新增
  `fable`/`sonnet`/`grok`/`gemini`/`kimi`/`glm` 别名）、引擎种子与
  `grok:*`/`cursor:*` $0 订阅行全部跟进。ZCode（Z.ai 桌面 IDE）已评估
  但跳过 —— 没有可集成的无头 CLI 面。

### ai-dispatch 对齐波次（1.1.0）

借鉴 [rennzhang/ai-dispatch](https://github.com/rennzhang/ai-dispatch)：让一个
AI Agent 把任务顺手派给另一个本机 AI 引擎，而无需了解那个引擎的命令行参数。
一个短名即可解析为有序的 `{backend, model}` 候选池并透明降级，会话可真正续聊，
每次派单都有存档。纯增量、不破坏现有 API —— 详见
[docs/ai-dispatch-parity.md](docs/ai-dispatch-parity.md)。

```bash
superaicore send opus "评审 HEAD~1 的 diff" --cwd "$PWD" --json-result
superaicore resume --session-id <id> "追问" --json-result
```

- **`superaicore send <目标> "<任务>"`** —— 目标可以是别名（`opus`、`kimi`、
  `gemini-pro` 等）、backend 名或模型 id；`AliasRouter` 按用户配置 → 内置注册表
  → backend 透传 → 模型推断的顺序解析，候选依次尝试。quota / 限流 / 认证 / 网络
  类失败自动落到下一个候选（`degraded: true` + 完整 `route_trace[]`）；其余失败
  一律 fail-closed。`--json-result` 返回 `ok / status / backend_used /
  model_used / route_trace / degraded / failure_class / session_id / run_id`。
- **`superaicore resume --session-id <id>`** —— 真正的会话续聊：
  `claude --resume` / `codex exec resume <thread_id>`；run store 记录了会话属于
  哪个引擎，调用方只需发送增量问题。
- **`superaicore runs list|show`** —— 文件系统运行存档（`~/.superaicore/runs`），
  零数据库依赖。
- **`superaicore aliases [目标]`** —— 查看或解析路由池；通过
  `super-ai-core.dispatch.aliases` 扩展。
- **`superaicore preferences init|show|path`** —— 自然语言的场景→模型偏好文件
  （`~/.superaicore/preferences.md`），由发起调用的 Agent 在选择目标前阅读。
- **`superaicore skill:install-dispatch`** —— 把内置的 `superaicore-dispatch`
  SKILL 安装到各 Agent 的技能目录，让外部 Agent 能把任务派**进** SuperAICore
  （与 `superaicore:sync-cli` 方向相反）。覆盖 `~/.claude/skills` /
  `~/.codex/skills` / `~/.gemini/skills`，*（1.1.5）*新增 `~/.grok/skills` /
  `~/.cursor/skills-cursor` / `~/.qwen/skills`；默认只装 claude，
  `--agent all` 一次装全，`--uninstall` 可干净卸载且不碰你自己的技能*（1.1.5）*。
- **`superaicore doctor [--json]`** —— 聚合体检：引擎、认证、backend、别名、
  偏好文件、运行存档。

### Fable 5 与 Sonnet 5 波次（1.0.11 / SDK 1.1.5）

SDK 约束从 `^1.1.2` 移到 `^1.1.5`。SuperAgent 1.1.5 把 **Claude Fable 5**
（`claude-fable-5`,Anthropic 最强模型）与 **Claude Sonnet 5**
（`claude-sonnet-5`,新的 `sonnet` 旗舰）落地为一等 `anthropic` 模型,给
`AnthropicProvider` 加上 `reasoning_effort` 档位,并修正过期的 Anthropic
定价;SuperAICore 把官方价镜像进自己的 `model_pricing` 表,并把新 id 种入
`superagent` 引擎,让成本看板与模型选择器在离线时也保持准确。纯增量、无破坏
—— 无迁移、无配置变更。

- **Fable 5 + Sonnet 5 原生定价**（1.0.11）—— `claude-fable-5`（1M 上下文、
  128K 最大输出、高清视觉、常驻自适应思考）按官方 **$10 入 / $50 出** 每 1M
  —— 高于 Opus 档;`claude-sonnet-5`（同属 Claude 5 代自适应请求面,能力逼近
  Opus 4.8 但价格在 Sonnet 档）按 **$3 / $15**（2026-08-31 前有 $2/$10 的
  首发价;表内保留官方价）。两个 id 都种入 `superagent` 引擎的
  `available_models`,以便离线时也出现在选择器里。
- **Opus 系按官方价重定价**（1.0.11）—— 现役 Opus（`claude-opus-4-5`→`4-8`）
  从过期的 $15/$75 降到 **$5/$25** 每 1M;仅带日期的 `claude-opus-4-20250514`
  快照保留历史价 $15/$75。若宿主还留着旧配置副本,请重新发布 —— 否则
  `CostCalculator` 会继续把 Opus 按 3 倍价计费。
- **Anthropic `reasoning_effort` 档位**（1.0.11）—— SDK 1.1.5 让
  `AnthropicProvider` 实现 `SupportsReasoningEffort`,把逐调用选项映射到
  Anthropic GA 的 `output_config.effort`（`low`/`medium`/`high`/`xhigh`/
  `max`),覆盖 Fable 5 / Sonnet 5 / Opus 4.5+ / Sonnet 4.6 —— 不支持的模型与
  `off` 不产生 `output_config`,杂散的 effort 绝不 400。原样经
  `SuperAgentBackend` 透传。
- **仅自适应请求面由 SDK 侧处理**（1.0.11）—— Fable 5 / Sonnet 5 发送
  `thinking: {type: "adaptive"}`（绝不发 `budget_tokens`）,并丢弃
  `temperature`/`top_p`/`top_k` 与尾部 assistant prefill;同一套防护顺带修复
  了 Opus 4.7/4.8 已存在的潜在 400。SDK 侧零配置 `anthropic` 现在解析到
  `claude-opus-4-8`;SDK Squad 的 EXPERT 档路由到 `claude-fable-5`
  （SuperAICore 自己的 `squad.tiers` 配置保持不变）。
- **Kiro 测试封闭化**（1.0.11）—— `KiroModelResolverTest` 与
  `EngineCatalogTest` 的 kiro 用例不再读取开发机的
  `~/.cache/superaicore/kiro-models.json`、也不再实测 `kiro-cli`;新增
  `IsolatesKiroCatalog` 测试 trait 加 `KiroModelResolver::resetMemo()`,把
  它们钉在确定性的静态 fallback 上。生产行为不变。

### GLM-5.2 原生旗舰波次（1.0.10 / SDK 1.1.2）

SDK 约束从 `^1.1.1` 移到 `^1.1.2`。SuperAgent 1.1.2 把 **GLM-5.2** 提升为原生
`glm` 旗舰,并给 `GlmProvider` 加上 `reasoning_effort` 档位；SuperAICore 把
Z.ai 的官方价镜像进自己的 `model_pricing` 表,并把新 id 种入 `superagent`
引擎,让成本看板与模型选择器在离线时也保持准确。纯增量、无破坏 —— 无迁移、无
配置变更。

- **GLM-5.2 原生定价**（1.0.10）—— `glm-5.2`（Z.ai 编码优先的智能体旗舰:1M
  上下文、128K 最大输出、纯文本）与 `glm-5.1`（200K 上下文）按官方 PAYG
  **$1.40 入 / $4.40 出** 每 1M,并附 **$0.26 cache-hit 入** 档位（以
  `cache_read_input` 承载）；`glm-5` 保留其早先的 $1.00 / $3.20。
  `CostCalculator` 本就回退到 SDK 的 `ModelCatalog`,这些行只是让离线计费保持
  准确;`glm-5.2` 同时种入 `superagent` 引擎的 `available_models`,以便离线时
  也出现在选择器里。
- **GLM-5.2 `reasoning_effort` 档位**（1.0.10）—— SDK 1.1.2 让 `GlmProvider`
  实现 `SupportsReasoningEffort`,与 MiniMax M3 并列。逐调用的
  `reasoning_effort` 选项（`off` → 关闭思考;`low…high` →
  `reasoning_effort high`;`max` → `reasoning_effort max`）与裸 `thinking`
  开关都原样经 `SuperAgentBackend` 透传 —— 它们本就是通用转发,所以 SDK 一落地
  档位即可用。
- **目录延续**（1.0.10）—— GLM-5.1（长时程,200K 上下文）与早先的 `glm-5`
  系列仍可按 id 访问;只有裸 `glm` 简写与零配置默认值现在解析到 GLM-5.2。

### streamChat MCP 波次（1.0.8）

`ClaudeCliBackend::streamChat()` 现在可以把调用方限定的一组 MCP server 工具
暴露给单轮对话。1.0.8 之前 chat 路径硬编码锁死的空 MCP 配置——即使派发路径
（`prepareScriptedProcess()`）早已支持 `mcp_mode`；1.0.8 把同一契约镜像到
chat 兄弟方法上。增量、不破坏——默认仍是锁空面，无迁移，SDK 约束不变。

- **`mcp_mode: 'empty'|'file'|'inherit'`**（1.0.8）—— 默认 `'empty'`
  （1.0.8 之前的行为，argv 逐字节一致）。`'file'` 把 `mcp_config_file`
  （`{"mcpServers":{...}}` JSON 路径）作为 `--mcp-config <path>
  --strict-mcp-config` 传入，本轮对话只看到该子集；`'inherit'` 不加任何
  MCP flag。`'file'` 缺路径时回退 `'empty'`——绝不静默继承用户的全部 MCP 面。
- **`extra_cli_flags: string[]`**（1.0.8）—— 原样追加；镜像
  `prepareScriptedProcess()` 的逃生舱。
- **`buildChatArgs()`**（1.0.8）—— 从 `streamChat()` 抽出的公开纯 argv
  构建器；tools / MCP / model / extra-flags 矩阵现在无需拉进程即可单测。
- **ToolSearch 自动追加**（1.0.9）—— 当前版本的 Claude CLI 把 MCP 工具
  延迟在 `ToolSearch` 元工具后面（init 时 server 显示 "pending"，工具不在
  前置列表里），而 `--tools` 限制的是**全部**工具面——allowlist 里没有
  ToolSearch 时模型永远够不到任何 MCP 工具。因此只要有效 MCP 面非空
  （`'file'` 带路径或 `'inherit'`），`ToolSearch` 就会被保证加进 allowlist；
  老版本 CLI 忽略未知的 `--tools` 项，所以处处安全。Host 侧：写一个子集
  配置文件、传 `mcp_mode: 'file'`，模型就能加载所选 server 的
  `mcp__<server>__<tool>` 工具——见 `docs/advanced-usage.zh-CN.md` §12。

### MiniMax M3 + 目录重定价波次（1.0.7 / SDK 1.1.1）

SDK 约束从 `^1.1.0` 移到 `^1.1.1`。SuperAgent 1.1.1 把 **MiniMax M3** 作为
一等原生模型引入,并把 DeepSeek V4 Pro / MiniMax 目录重定价到厂商实时价；
SuperAICore 把这些修正镜像进自己的 `model_pricing` 表与引擎种子,让成本看板
与模型选择器在离线时也保持准确。纯增量、无破坏 —— 无迁移、无配置变更。

- **MiniMax M3 原生定价**（1.0.7）—— `MiniMax-M3`（MSA 旗舰:1M 上下文、
  512K 最大输出、原生图像/视频输入、交错思考）按标准 PAYG **$0.60 入 /
  $2.40 出** 每 1M,并附 `MiniMax-M2.7` / `M2.5` / `M2` 显式行（$0.30 /
  $1.20）。`CostCalculator` 本就回退到 SDK 的 `ModelCatalog`,这些行只是让
  离线计费保持准确;`MiniMax-M3` 同时种入 `superagent` 引擎的
  `available_models`,以便离线时也出现在选择器里。
- **DeepSeek V4 Pro 重定价**（1.0.7）—— 改到当前官方价 **$0.435** 入
  (cache-miss) / **$0.003625** 入 (cache-hit,`cache_read_input`) /
  **$0.87** 出 每 1M,从过时的 $0.55 / $2.20 下调。已弃用的
  `deepseek-reasoner` 别名（路由到 V4 Pro）一并跟进。
- **SmartFlow 延续**（1.0.7）—— 1.1.1 的 pin 仍包含现有
  `SuperAgentFlowBridge` 所委派的 1.1.0 SmartFlow 引擎,fan-out 到
  `superagent` 的跨 CLI flow 行为不变。

### CLI skill 桥接波次（1.0.6）

一座通用、symlink 安全、带指纹校验的桥,把宿主的 skill + agent 库
fan-out 到每个 CLI backend 的原生形态 —— 跟 `McpManager::syncAllBackends()`
已经给 MCP 的形态一模一样。1.0.6 之前每个宿主都各自手搓一套 per-CLI 的
sync;1.0.6 用一个 contract + service + 命令把它们统一起来,再加一个
按需的惰性 on-dispatch sync。纯增量、无破坏:没有绑定 `SkillLibrary` 时,
这座桥就是个静默的 no-op。

- **`SkillLibrary` contract**（1.0.6）—— 宿主实现五个方法
  (`skills()`、`agents()`、`skillWrapper($backend,$name)`、
  `instructionsDigest($backend)`、`fingerprint()`) 并绑定它
  (`$this->app->singleton(SkillLibrary::class, MyLibrary::class)`)。SuperAICore
  知道 WHERE / HOW / WHEN;宿主提供 WHAT。包里不烤任何宿主假设。
- **三种安装形态**（1.0.6）—— `CliSkillBridge` 按 backend 把库 fan-out:
  **`native_dir`**（codex / gemini / grok / cursor / qwen）为每个 skill 往
  CLI 的 skills 目录里放一个带前缀的 wrapper 目录;**`instructions`**
  （copilot / kimi / kiro）写一份摘要文件,告诉模型如何按需加载任意 skill;
  **`source`**（claude）直接读 `.claude/skills`,什么都不装。
- **symlink 安全**（1.0.6）—— 这座桥**绝不写穿 symlink**。每个 wrapper
  目录 / `SKILL.md` / 摘要 / manifest 在写入前都先 `is_link()` 检查,把陈旧
  的 link 先 unlink 掉(target 完好无损),从而堵上那个曾经把源 skill 正文
  覆盖掉的"写穿 symlink"漏洞。
- **惰性 on-dispatch sync**（1.0.6）—— 每次 sync 都把库的 `fingerprint()`
  盖进一份 per-backend manifest(`.superteam-skill-sync.json`);只有指纹漂移
  时,`TaskRunner` 才会在分发前重装某个 backend,所以热路径只是一次哈希比对。
  剪枝是 manifest 范围内的 —— 绝不碰用户自己的 skill。
- **`superaicore:sync-cli`**（1.0.6）—— 一条命令把整个能力面
  (skills + MCP) 传播到每个已安装的 CLI:
  `--skills-only` / `--mcp-only` / `--backends=codex,gemini` / `--project-root=`。
- **顺带修的一处**（1.0.6）—— `builtin`（订阅 / OAuth）在
  claude / codex / gemini / cursor / grok 这几个 backend 上运行时,现在会清掉
  任何继承下来的陈旧 console key,免得它覆盖登录态导致 401;Claude 的 Keychain
  OAuth token 现在注入为 `CLAUDE_CODE_OAUTH_TOKEN`,而不是 `ANTHROPIC_API_KEY`。

### SmartFlow 跨 CLI 工作流波次（1.0.5 / SDK 1.1.0）

SDK 约束移到 `^1.1.0`。SuperAICore 1.0.5 把 Claude Code 内置的
`Workflow` 引擎移植为 **SmartFlow** —— 跨 CLI 的动态工作流 —— 并把它
与 superagent 自己的（跨模型）SmartFlow 联邦化。SDK 的 SmartFlow 把
一条 flow 路由到不同的模型 provider 上，而 SuperAICore 的 SmartFlow
把一条 flow 路由到它已经管理的 **CLI/backend** 上，让不同 CLI 协同
完成同一个任务。纯增量、无破坏:Dispatcher、AgentSpawn 以及
Squad/Team/Smart/Auto 编排器都原封不动。新模块 `src/SmartFlow/`、新命令
`superaicore flow`、新文档 `docs/smartflow.md`。

- **一条 flow,多个 CLI**（1.0.5）—— 同一套原语
  (`agent()` / `parallel()` / `pipeline()` / `gate()` / `council()` / `budget` /
  `schema` / `SKIP`) 驱动任意已注册的 backend,所以一条 flow 可以在
  `claude_cli` 上做规划,同时在 `codex_cli` + `gemini_cli` 上并发评审。每个
  step 上的 `backend` 就是那个跨 CLI 的旋钮;可复用的 `personas` 承载 system
  prompt,并可钉住某个 backend/model。
- **3 层结构化输出安全网**（1.0.5）—— CLI 返回的是散文,所以会把
  `schema` 烤进 prompt,再通过 native → 围栏
  ```` ```json ```` → 正则嗅探这几层逐级回收,由零依赖的
  `SchemaValidator` 校验;彻底失败时产出一个 `SKIP` 哨兵而非崩溃。
- **Resume + 调用账本**（1.0.5）—— 每次运行都在 `~/.superaicore/flows`
  下写一份 JSONL 账本;`--resume <id>` 以零成本从缓存重放最长的未变更
  前缀（内容寻址签名;gate 保持对齐）。
- **真并行**（1.0.5）—— `parallel()` / `pipeline()` 批次以并发的
  `bin/flow-agent-runner.php` 子进程运行（`proc_open` +
  `stream_select`,Windows 轮询回退）,不可用时降级为进程内执行。
- **零成本彩排**（1.0.5）—— `flow run --rehearse` 在不调用任何 CLI 的
  情况下端到端跑完任意 flow（确定性的符合 schema 的桩),所以 flow 在裸机
  上也可测;每个内置 flow 彩排都是绿的。
- **与 superagent 联邦**（1.0.5）—— `Flow::delegate()`（以及 YAML 中的
  `strategy: delegate`）把一个子 flow 交给 superagent 的跨模型
  SmartFlow:**named** 模式运行 superagent 自己的某条 flow,由它在各
  provider 间自行分发;**spec** 模式运行一条结构由 SuperAICore 编写的
  flow,由 superagent 按照本项目的指示分发。被委派的开销联邦进父预算;
  整个嵌套运行以零成本彩排。
- **4 条内置跨 CLI flow + YAML 编写**（1.0.5）—— `cross-cli-review`、
  `cross-cli-dev`、`cross-cli-council`、`cross-cli-federated`（后者把研究
  委派给 superagent),由 `YamlFlowLoader` 编译;把你自己的放在 `./flows`
  或 `./.superaicore/flows` 下即可。配置块
  `super-ai-core.smartflow.*`。

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

### Qwen + 追踪 + 9Router 波次（0.9.8）

第八个执行引擎、始终运行的 Dispatcher 追踪环（`chrome://tracing`
可直接打开，在配额/空结果/自动轮换时自动落盘）、`/super-ai-core/v1/chat/completions`
处的 OpenAI 兼容代理、带冷却的多账号轮询、三个 HTTP 后端的真流式
SSE、Claude / Codex / Copilot / Kiro 的预先 OAuth 刷新、Pi 风格的
会话树分支、为非 skill 原生 CLI 设计的渐进披露 skill 索引、pi v3
JSONL 导出器，以及 `gh-watch` GitHub PR / CI 反应引擎。**SDK 约束升至
`^1.0.6`** —— 引入真实的 `RtkPipeline`（6 个内置压缩器）、
`Hooks\HookEvent::PR_EVENT` 钩子（`gh-watch` 自动触发）、
`Agent::steer()` / `followUp()` 运行中转向（经 `SuperAgentBackend`
options 暴露），以及 `qwen-anthropic` SDK provider（新的
`AiProvider::TYPE_QWEN_ANTHROPIC` 类型，DashScope 的 Anthropic
协议端点 —— Claude 的无缝替代）。

- **第 8 个引擎 Qwen Code CLI（`qwen_cli`）**（0.9.8）—— gemini-cli
  的分支，适配阿里 Qwen 家族。实现 `Backend`、`StreamingBackend`、
  `ScriptedSpawnBackend` 三个契约，自动接入所有既有 dispatch 路径。
  仅 API key 认证（`DASHSCOPE_API_KEY` / `QWEN_API_KEY`）；OAuth 已
  于 2026-04-15 EOL。默认模型 `qwen3.7-max`（1M 上下文、$2.50/$7.50
  per 1M、原生 Anthropic `/v1/messages` 协议 —— fallback 链里可以直
  接当 Claude 用）。`AI_CORE_QWEN_CLI_ENABLED` 控制开关。
- **Dispatcher 追踪环（`Tracing\TraceCollector`）**（0.9.8）—— 始
  终运行的 lock-free 事件环（`llm` / `cache` / `provider` / `tool` /
  `error` 类别）。1024 条事件约 150 KB；关闭时零文件系统开销。在
  `error` / `rotate` / `timeout` 触发时自动落盘成 Chrome Trace Event
  JSON（`chrome://tracing`、`https://ui.perfetto.dev`、自带的
  `trace-viewer.html` 都能打开）。`SuperAgentBackend` 在
  `quota_exceeded` / `usage_not_included` / `server_overloaded` /
  `cyber_policy` 异常时自动以 `trigger=rotate` 落盘，post-mortem 就
  能看到出问题的整条 envelope。手动落盘:`php artisan
  dispatcher:dump-trace`。UI:`/super-ai-core/traces`。
- **OpenAI 兼容代理**（0.9.8）——
  `Http\Controllers\OpenAiCompatibleController` 暴露 `GET /v1/models`
  与 `POST /v1/chat/completions`（流式 + 非流式）。`model` 字段既
  可填字面 id，也可填 `ai_routing_combos.name`，所以 Cursor / Cline /
  Roo / Kiro / continue.dev / OpenAI SDK 都能零改动接入。流式 chunk
  形状与 OpenAI 完全一致。
- **三个 HTTP 后端的真 SSE 流式**（0.9.8）——
  `AnthropicApiBackend`、`OpenAiApiBackend`、`GeminiApiBackend` 实
  现新契约 `Contracts\StreamableTextBackend`，yield 出统一格式的
  envelope（`{type:'text'|'thinking'|'tool_use_delta'|'usage'|'stop'}`）。
  OpenAI 兼容代理直接消费。
- **命名路由 combo（`ai_routing_combos`）**（0.9.8）—— combo 是
  调度时解析的有序 `[{provider, model}, ...]` 列表，位于静态
  `tier_map` 之上。CRUD 端点:`/super-ai-core/routing/combos[/{name}]`。
  按调用覆盖:`smart` / `squad` / `auto` 的 `--combo=NAME` 标志。
- **多账号轮询（`AccountRoundRobin`）**（0.9.8）—— 以原子 CAS
  挑出 `(priority, last_used_at)` 最小且未冷却的激活账号；
  `QuotaExceededException` / 空结果时调 `cooldown()` 给账号冷却 10
  分钟。新增 `ai_provider_accounts` 表支撑。
- **OAuth 刷新器注册表**（0.9.8）—— 为四个把 OAuth 状态写到本地
  JSON 的 CLI（Claude / Codex / Copilot / Kiro）做预先 token 刷新。
  通过 `php artisan super-ai-core:oauth-refresh` 驱动；从
  `app/Console/Kernel.php` 用 `->everyTenMinutes()` 排程。
- **Pi 风格会话树分支**（0.9.8）—— `Services\SessionBranchManager`
  + `ai_session_branches` 表。从老消息 fork 创建新分支；切走时自动
  对被抛弃分支生成 summary，避免丢失上下文。端点:
  `/sessions/{session}/tree`、`/sessions/{session}/fork`、
  `/sessions/{session}/switch`。
- **渐进披露 skill 索引**（0.9.8）—— `Services\SkillIndexBuilder`
  把每个 `SKILL.md` 的 name + description（不含正文）压成紧凑 XML
  索引，`CodexCliBackend` / `GeminiCliBackend` 在每次 prompt 前注入。
  模型只在真要用某个 skill 时才通过既有的 file-read 工具读 body。
  让非 skill 原生的 CLI 也能用 SuperAICore 的 skill 目录，成本与
  Claude 原生 skill 协议相当。按调用关闭:
  `options['skills_disabled']=true` 或 `--no-skills`。
- **`ask_user` 的 Pi `kind` 鉴别字段**（0.9.8）—— `select` /
  `confirm` / `input` / `editor`，让 `/processes/questions` UI 按
  调用渲染正确的控件。默认 `select` 保持 0.9.7 行为。
- **Caveman 模式（`--caveman`）**（0.9.8）—— 从 9Router 借来的
  输出 token 压缩提示词。对于推理快但输出冗长的任务，实测能省
  30-65% 的输出 token（不适合长篇写作）。
- **GitHub PR / CI 监控（`super-ai-core:gh-watch`）**（0.9.8）——
  借鉴自 claude-octopus。按 ETag 缓存 GitHub API 调用，对每个激活
  的 `ai_pr_watchers` 行按配置触发动作（`ask_user` / `spawn_squad`
  / `webhook` / `log`）。可通过 `->everyFiveMinutes()` 排程，也可
  `--loop=30` 守护进程化。
- **Pi v3 会话 JSONL 导出器**（0.9.8）—— `php artisan
  task-results:export-jsonl` 按 `metadata.session_id` 一文件输出。
  需要 `--i-understand`（格式是有损的）；支持 `--anonymize`、`--since`。
- **Apache Arrow 表格往返（`Arrow\ArrowSerializer`）**（0.9.8）——
  最小化 Arrow IPC 流写入器（不依赖 `apache/arrow` PECL 扩展）。
  按调用启用:`output_format: 'arrow'`，envelope 会带 base64 编码
  的 Arrow 流。对于宽表格 agent 负载，比 JSON 快 10–100 倍。
- **SuperTeam agents 浏览器（`/super-ai-core/agents`）**（0.9.8）
  —— 读取可配置根目录下的 `.claude/agents/*.md`，按类别（Strategy /
  Product / Engineering / Business / Security / …）分组展示。
  配置:`super-ai-core.agent_catalog.paths`。
- **SDK 1.0.6 接线**（0.9.8）—— 四处针对性接线: (1)
  `RtkCompressorService` 开箱即返回真实的字节节省（SDK 内置 6 个
  压缩器:git diff / grep / find / ls / tree / Bash）；(2)
  `GhWatchCommand` 每个事件都额外触发 `Hooks\HookEvent::PR_EVENT`
  钩子，挂载 `PrWatchHookData` 负载，注册了 SDK 侧 listener 的 host
  与本地 action handler 看到同一份事件流；(3) `SuperAgentBackend`
  新接受两个 dispatch options:`follow_up_queue`（预填 agent 的
  follow-up 队列，主 `run()` 返回后按 FIFO 自动续跑）和
  `on_agent_built: fn(Agent)`（构造完成后回调，让 sibling 进程把
  Agent 注册进 session-keyed broker，HTTP/ACP `session/steer` 即可
  在运行中调用 `Agent::steer()` 注入修正）；(4) 新 provider 类型
  `AiProvider::TYPE_QWEN_ANTHROPIC`，由 SDK 1.0.6 的
  `QwenAnthropicProvider` 驱动 —— Qwen 3.7 Max 经 DashScope
  Anthropic-protocol 端点，Claude 的无缝替代。

完整菜谱（Qwen CLI 安装、追踪查看器配置、OpenAI 代理客户端接入、
路由 combo CRUD、多账号上线流程、OAuth 刷新排程、会话分支 fork、
gh-watch 表结构、SDK 1.0.6 接线）见
[docs/advanced-usage.zh-CN.md §30](docs/advanced-usage.zh-CN.md)。

### kimi-cli + kimi-code 双轨波次（1.0.2 / SDK 1.0.10）

Moonshot 发布了 `@moonshot-ai/kimi-code`（TypeScript 重写）来**取代**旧的 Python
`MoonshotAI/kimi-cli`。两者发布**同一个 `kimi` binary**，但 headless 接口不兼容，
因此 1.0.2 让 `kimi_cli` 后端**同时支持两种 CLI**跨过过渡期 —— 并把 SDK 约束提到
`^1.0.10`。纯增量 —— 无 schema 变更、无迁移、无需 config publish；`kimi_cli` 这个
Dispatcher backend id 不变。

- **双 dialect 的 `kimi_cli` 后端**（1.0.2）—— `KimiCliBackend` 通过一次性、按
  binary 缓存的 `kimi --help` 探测自动判别装的是哪一种（legacy 有 `--print` flag，
  kimi-code 没有），并在全部四条 spawn 路径上适配 argv。legacy 保持
  `--print --output-format=stream-json --max-steps-per-turn N [--mcp-config-file F]
  --prompt …`；kimi-code 用 `--prompt` 触发 print 模式 —— 无 `--print`/`--yolo`，
  也无 `--max-steps-per-turn` / `--mcp-config-file` / `-w`（由 config.toml 驱动，
  未知选项硬拒绝）。用 `AI_CORE_KIMI_CLI_VARIANT`（默认 `auto` / `kimi-code` /
  `kimi-cli`）可固定 dialect。
- **stream-json 解析兼容两种形状**（1.0.2）—— 解析器同时接受两种线上形状:assistant
  `content` 为纯字符串（kimi-code）或 typed `text`/`think` block 数组（legacy），
  并把新的 `{"role":"meta","type":"session.resume_hint",…}` 行当作 trace。即便探测
  判错也稳。
- **SDK `^1.0.9` → `^1.0.10`**（1.0.2）—— Kimi/Moonshot HTTP 路径加固，泛化到每一个
  OpenAI 兼容 provider，并透明地惠及 `superagent` 后端:恢复流式 `usage` 计量
  （`stream_options.include_usage` —— 流式 `kimi` / `qwen` / `glm` / `deepseek` /
  `grok` / `openrouter` / `openai` 调用的 token/成本/缓存不再被静默清零）、严格的
  工具 schema 归一化（MCP / Skill / Agent 工具能过 Moonshot 校验器）、Kimi 推理模型
  改用 `max_completion_tokens`、按模型的能力发现。新增 opt-in
  `SUPERAGENT_KIMI_SWARM_ENABLED` 开关。
- **不变的各处入口**（1.0.2）—— `kimi_cli` backend id、`/providers` 引擎卡片、模型
  选择器、`cli:status`、成本仪表盘、进程监视器都无需改动;变的只是底层 CLI dialect。
  （kimi-code `.agents/` 模型的 agent-sync 平价是一项已记录的后续。）

完整菜谱（变体探测 + 覆盖、kimi-cli/kimi-code flag 对照、SDK 1.0.10 透明修复）见
[docs/advanced-usage.zh-CN.md §31](docs/advanced-usage.zh-CN.md) 与
`docs/kimi-cli-backend.md` §8。

### Opus 4.8 + Grok + Cursor 波次（1.0.0 / SDK 1.0.9）

1.0.0 稳定版升级到 SDK `^1.0.9`，落地 Opus 4.8 代际、两条通道的 xAI Grok，
以及两个新的订阅型 CLI 引擎。纯增量 —— 无 schema 变更、无迁移、无需 config publish。

- **Claude Opus 4.8 旗舰**（1.0.0）—— SDK 1.0.9 将 `claude-opus-4-8` 提升为
  Anthropic 旗舰：接管 `opus` 别名，原生 1M 上下文、交错思考（interleaved
  thinking）、fast 模式、effort 控制、动态工作流 / 多智能体编排，Opus 档定价
  （$15 / $75 per 1M）。`ClaudeModelResolver` 将 `opus → claude-opus-4-8`，
  catalog 顶部列出 `claude-opus-4-8` / `claude-opus-4-8[1m]`；`claude` 引擎
  catalog、`model_pricing`、`squad` / `cli_squad` 的 **expert** 档全部指向 4.8。
- **xAI Grok API provider（`grok` 类型）**（1.0.0）—— 经 `superagent` 后端
  路由到 SDK 1.0.9 的 `GrokProvider`（xAI OpenAI 兼容端点 `https://api.x.ai/v1`）。
  `XAI_API_KEY`（规范名）+ `GROK_API_KEY`（别名）；默认模型 `grok-4.3`（1M 上下文）。
  已接入 `ApiHealthDetector`（`api:status` + 仪表盘探测）与成本目录
  （grok-4.3 / grok-4-fast / grok-code-fast-1 / grok-3-mini）。
- **Cursor Composer CLI（`cursor_cli`）**（1.0.0）—— Cursor 的 headless
  `cursor-agent`（Composer 2.5）。订阅型引擎；`builtin` 登录态在 `~/.cursor`。
  支持 streaming + scripted-spawn + 一次性 chat，按 Claude-Code 形状解析
  JSON / stream-json 并跟踪 token，MCP 走 `.cursor/mcp.json`，`--force` headless
  工具放行。默认模型 `composer-2.5-fast`。
- **Grok Build CLI（`grok_cli`）**（1.0.0）—— xAI 的 `grok`「Grok Build」
  agentic CLI。订阅型引擎；`builtin` 登录态在 `~/.grok`。effort 控制
  （`--effort low…max` / `--reasoning-effort`）、`--prompt-file` scripted spawn、
  原生 sub-agents。默认模型 `grok-build`。**与计量的 Grok API provider 类型相互独立**
  —— 同一品牌、两条通道。
- **数据驱动的各处入口** —— 因为 `EngineCatalog`、`ProviderTypeRegistry` 与各引擎
  ModelResolver 驱动一切，`/providers` UI（引擎卡片、builtin 行、新增 provider
  下拉、版本 + 登录徽章）、模型选择器、`cli:status`、成本仪表盘、进程监视器、
  `McpManager` 同步都会自动拾取新引擎。

完整菜谱（Cursor / Grok CLI 上线、Opus 4.8 路由、Grok API 与 CLI 通道区分、effort
控制）见 [docs/advanced-usage.zh-CN.md §30](docs/advanced-usage.zh-CN.md)。

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
- `qwen` CLI 在 `$PATH`（0.9.8+）—— `npm i -g @qwen-code/qwen-code`，然后导出 `DASHSCOPE_API_KEY`（OAuth 已于 2026-04-15 EOL）
- Anthropic / OpenAI / Google AI Studio / DashScope API Key（HTTP 后端）

不想记包名？跑 `./vendor/bin/superaicore cli:status` 看缺什么，再 `./vendor/bin/superaicore cli:install --all-missing` 一键装齐（默认带确认提示）。

## 安装

```bash
composer require forgeomni/superaicore
php artisan vendor:publish --tag=super-ai-core-config
php artisan vendor:publish --tag=super-ai-core-migrations
php artisan migrate
```

从 0.9.7 升级？只需 `composer update forgeomni/superaicore` 后跑
`php artisan migrate` —— 0.9.8 新增 5 条迁移（`ai_user_questions`
新增 `kind` 列，加上 4 张新表:`ai_session_branches`、`ai_routing_combos`、
`ai_provider_accounts`、`ai_pr_watchers`），均为加性变更。重新发布
配置文件以拾取 `tracing.*`、`agent_catalog.*`、`backends.qwen_cli.*`
新块:

```bash
php artisan vendor:publish --tag=super-ai-core-config --force
```

宿主 `app/Console/Kernel.php` 推荐排程:

```php
$schedule->command('super-ai-core:snapshot-prune')->dailyAt('02:00');   // 0.9.7
$schedule->command('super-ai-core:oauth-refresh')->everyTenMinutes();   // 0.9.8
$schedule->command('super-ai-core:gh-watch')->everyFiveMinutes();       // 0.9.8
```

完整步骤见 [INSTALL.zh-CN.md](INSTALL.zh-CN.md)。

## CLI 快速上手

```bash
# 查看 Dispatcher 适配器及其可用状态
./vendor/bin/superaicore list-backends

# 从 CLI 驱动八个引擎
./vendor/bin/superaicore call "你好" --backend=claude_cli                              # Claude Code CLI（本地登录）
./vendor/bin/superaicore call "你好" --backend=codex_cli                               # Codex CLI（ChatGPT 登录）
./vendor/bin/superaicore call "你好" --backend=gemini_cli                              # Gemini CLI（Google OAuth）
./vendor/bin/superaicore call "你好" --backend=copilot_cli                             # GitHub Copilot CLI（订阅）
./vendor/bin/superaicore call "你好" --backend=kiro_cli                                # AWS Kiro CLI（订阅）
./vendor/bin/superaicore call "你好" --backend=kimi_cli                                # Moonshot Kimi Code CLI（OAuth 订阅）
./vendor/bin/superaicore call "你好" --backend=qwen_cli --api-key=sk-...                # Alibaba Qwen Code CLI（0.9.8+）
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

### SmartFlow —— 跨 CLI 工作流（1.0.5）

```bash
# 列出 / 查看跨 CLI flow
./vendor/bin/superaicore flow list
./vendor/bin/superaicore flow show cross-cli-review

# 端到端零成本彩排（不调用任何 CLI —— 确定性桩）
./vendor/bin/superaicore flow run cross-cli-review --args diff=@my.diff --rehearse

# 真跑:Claude 做摘要,Codex + Gemini 并行评审,Claude 拍板
./vendor/bin/superaicore flow run cross-cli-review --args diff=@my.diff --concurrency 4

# 联邦:在 Claude 上规划,把研究 DELEGATE 给 superagent 的跨模型 flow,在各 CLI 上构建/评审
./vendor/bin/superaicore flow run cross-cli-federated --args goal="add caching" --args research_provider=openai

# 续跑之前的运行 —— 未变更的前缀从缓存重放,零成本
./vendor/bin/superaicore flow run cross-cli-dev --args goal="add caching" --resume <runId>
```

在 Laravel 宿主里也可用 `php artisan flow ...`。原语、YAML 编写以及
superagent 联邦（`delegate` 的 named/spec 模式）详见
[docs/smartflow.md](docs/smartflow.md)。

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

# …或者,在绑定了 SkillLibrary 的 Laravel 宿主里,用一条命令通用地做完上面所有事（1.0.6+）:
php artisan superaicore:sync-cli                              # skills + MCP → 每个已安装的 CLI
php artisan superaicore:sync-cli --skills-only --backends=codex,gemini

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
  Qwen Code CLI   ────────▶ dashscope-api              ────▶ qwen_cli       (0.9.8+)
  SuperAgent SDK  ────────▶ anthropic(-proxy) /        ────▶ superagent
                            openai(-compatible) /
                            openai-responses /          (0.7.0+)
                            lmstudio                    (0.7.0+)

  Dispatcher ← BackendRegistry   （管理上述 11 个适配器）
             ← ProviderResolver  （从 ProviderRepository 读取当前 provider）
             ← RoutingRepository （task_type + capability → service）
             ← AccountRoundRobin （多账号轮询 + 冷却，0.9.8+）
             ← TraceCollector    （magic-trace 环；error/rotate 时自动落盘，0.9.8+）
             ← UsageTracker      （写入 UsageRepository）
             ← CostCalculator    （模型价格表 → USD）
```

所有 Repository 都是接口。ServiceProvider 默认绑定 Eloquent 实现；可替换为 JSON 文件、Redis 或外部 API，调度器无需改动。

## 高级用法

- **[高级用法指南](docs/advanced-usage.zh-CN.md)** —— 幂等 key 往返、W3C trace context、分类的 provider exception、`openai-responses` + Azure OpenAI + ChatGPT OAuth、LM Studio、`http_headers` / `env_http_headers` 覆盖、SDK features（`extra_body` / `features` / `loop_detection`）、`ScriptedSpawnBackend` 宿主迁移、Skill engine 遥测 / BM25 ranker / FIX 模式演化（0.8.6+）、**0.9.0 jcode 波次**、**0.9.1 DeepSeek-TUI 对齐波次**、**0.9.2 TaskRunner 可靠性波次**、**0.9.6 Squad 多智能体 + SDK 1.0.0 波次**、**0.9.7 opencode 借鉴波次**、**0.9.8 Qwen + 追踪 + 9Router 波次**、**1.0.0 Opus 4.8 + Grok + Cursor 波次**，以及 **1.0.5 SmartFlow 跨 CLI 波次**。
- **[SmartFlow —— 跨 CLI 工作流](docs/smartflow.md)**（1.0.5）—— Claude Code `Workflow` 的多 CLI 移植版:`agent`/`parallel`/`pipeline`/`gate`/`council`/`budget`/`schema` 原语、YAML 编写、3 层结构化输出阶梯、resume + 调用账本、零成本彩排,以及**与 superagent 跨模型 SmartFlow 的联邦**（`delegate` 的 named/spec 模式）。
- **[Cookbook](examples/cookbook/README.md)**（0.9.8+）—— gs-quant 风格的五个叙事型示例:dispatcher 基础、prompt 缓存、provider 轮换、跨 harness 恢复、追踪快速入门。
- **[商业化分层](docs/commercialization-tiers.md)**（0.9.8+）—— 关于在 MIT 内核之上如何分层（Cloud Dashboard / Managed Dispatcher / Enterprise overlays）的参考文档。该文档描述的内容今日尚未实现。
- **[供应链策略](SUPPLY_CHAIN.md)**（0.9.8+）—— Composer lifecycle scripts 全部禁止、`composer install --no-scripts` 默认启用、每周跑 `composer audit`。
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
