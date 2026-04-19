# forgeomni/superaicore

[![tests](https://github.com/ForgeOmni/SuperAICore/actions/workflows/tests.yml/badge.svg)](https://github.com/ForgeOmni/SuperAICore/actions/workflows/tests.yml)
[![license](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![php](https://img.shields.io/badge/php-%E2%89%A58.1-blue.svg)](composer.json)
[![laravel](https://img.shields.io/badge/laravel-10%20%7C%2011%20%7C%2012-orange.svg)](composer.json)

[English](README.md) · [简体中文](README.zh-CN.md) · [Français](README.fr.md)

用于统一调度五种 AI 执行引擎的 Laravel 包：**Claude Code CLI**、**Codex CLI**、**Gemini CLI**、**GitHub Copilot CLI**、**SuperAgent SDK**。内置独立于框架的 CLI、基于能力（capability）的调度器、MCP 服务器管理、使用量记录、成本分析，以及一套完整的后台管理 UI。

在干净的 Laravel 项目中可独立运行。UI 可选、可完全替换，既能嵌入宿主应用（例如 SuperTeam），也可以在仅使用服务层时关掉。

## 与 SuperAgent 的关系

`forgeomni/superaicore` 和 `forgeomni/superagent` 是**兄弟包，并非父子依赖关系**：

- **SuperAgent** 是一个轻量级的 PHP 进程内 SDK，专注于驱动单个 LLM 的 tool-use 循环（一个 agent、一段会话）。
- **SuperAICore** 是 Laravel 级的编排层 —— 负责挑选后端、解析 provider 凭证、按能力路由、记录用量、计算成本、管理 MCP 服务器，并提供后台 UI。

**SuperAICore 并不依赖 SuperAgent 才能工作。** SuperAgent 只是众多后端之一。CLI 引擎（Claude / Codex / Gemini / Copilot）与 HTTP 后端（Anthropic / OpenAI / Google）都不需要它，且 `SuperAgentBackend` 在 SDK 缺失时会通过 `class_exists(Agent::class)` 检查优雅地报告为不可用。如果你不需要 SuperAgent，只需在 `.env` 中设置 `AI_CORE_SUPERAGENT_ENABLED=false`，Dispatcher 会自动回退到其余后端。

`composer.json` 中的 `forgeomni/superagent` 依赖只是为了开箱即用地启用 SuperAgent 后端；若你从不使用它，可以在宿主项目 `composer install` 之前从 `composer.json` 中移除该条目 —— SuperAICore 的其余代码都不会引用 SuperAgent 命名空间。

## 特性

- **Skill 与 sub-agent 运行器** —— 自动发现 Claude Code skill（`.claude/skills/<name>/SKILL.md`）和 sub-agent（`.claude/agents/<name>.md`），并将其暴露为 CLI 子命令（`skill:list`、`skill:run`、`agent:list`、`agent:run`）。默认跑在 Claude 上，可选在 Codex / Gemini / Copilot 上原生执行（带兼容性探测、工具名翻译、后端 preamble 注入），并支持"有副作用即硬锁定"的多后端回退链。`gemini:sync` 把每个 skill / agent 镜像成 Gemini 自定义命令；`copilot:sync` 把 agent 镜像成 `~/.copilot/agents/*.agent.md`（或在 `agent:run --backend=copilot` 时自动触发）；`copilot:sync-hooks` 把 Claude 风格的 hooks 合并到 Copilot 配置。
- **一键 CLI 安装器** —— `cli:status` 列出每家 CLI 的安装/登录状态与安装提示；`cli:install [backend] [--all-missing]` 走规范的包管理器（`npm`/`brew`/`script`）安装缺失项，默认带确认提示。显式触发 —— 永不因为调度失败自动安装。
- **Copilot 并行 fan-out** —— `copilot:fleet <task> --agents a,b,c` 将同一任务并发分发给 N 个 Copilot sub-agent，聚合每 agent 结果，每个子进程都注册到 Process Monitor。
- **五个执行引擎** —— Claude Code CLI、Codex CLI、Gemini CLI、GitHub Copilot CLI、SuperAgent SDK，统一实现同一套 `Dispatcher` 契约。每个引擎只接受固定几类 provider：
  - **Claude Code CLI**：`builtin`（本地登录）、`anthropic`、`anthropic-proxy`、`bedrock`、`vertex`
  - **Codex CLI**：`builtin`（ChatGPT 登录）、`openai`、`openai-compatible`
  - **Gemini CLI**：`builtin`（Google OAuth 登录）、`google-ai`、`vertex`
  - **GitHub Copilot CLI**：仅 `builtin`（`copilot` 二进制自行处理 OAuth / keychain / 刷新）。原生读取 `.claude/skills/`（零翻译直通）。**订阅计费** —— 仪表盘独立统计，不混入按 token 计费引擎。
  - **SuperAgent SDK**：`anthropic`、`anthropic-proxy`、`openai`、`openai-compatible`
- 五个引擎在 Dispatcher 内部扇出成八个适配器（`claude_cli`、`codex_cli`、`gemini_cli`、`copilot_cli`、`superagent`、`anthropic_api`、`openai_api`、`gemini_api`）—— provider 为 `builtin` 时走 CLI 适配器，持有 API Key 时走 HTTP 适配器。这是实现细节，一般无需关心；如需低层直调，CLI 也能直接指定这些适配器名。
- **EngineCatalog 单一数据源** —— 引擎的标签、图标、Dispatcher 后端、支持的 provider 类型、可用模型，以及声明式的 **`ProcessSpec`**（二进制名、版本/登录状态参数、prompt/output/model flag、默认 flag）都集中在一个 PHP 服务里。新增一个 CLI 引擎只需改 `EngineCatalog::seed()`，providers UI、进程扫描、开关矩阵、默认 CLI 命令形状全部自动跟进。宿主应用可通过 `super-ai-core.engines` 配置覆盖每个引擎字段（包括 `process_spec`）。
- **CliProcessBuilderRegistry** —— 基于引擎的 `ProcessSpec` 组装 `argv`（`build($key, ['prompt' => …, 'model' => …])`）。默认 builder 覆盖全部内置引擎；宿主可 `register($key, $callable)` 无需 fork 就替换成自定义形状。另暴露 `versionCommand()` / `authStatusCommand()` 给状态探测。以单例注册。
- **Provider / Service / Routing 模型** —— 将抽象能力（`summarize`、`translate`、`code_review` 等）映射到具体服务，再将服务绑定到 provider 凭证。
- **MCP 服务器管理器** —— 在后台 UI 中安装、启用、配置 MCP 服务器。
- **使用量追踪** —— 每次调用将 prompt / response tokens、耗时、成本写入 `ai_usage_logs` 表。
- **成本分析** —— 按模型价格表汇总 USD 费用，并提供带图表的仪表盘。
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

# 从 CLI 驱动五个引擎
./vendor/bin/superaicore call "你好" --backend=claude_cli                              # Claude Code CLI（本地登录）
./vendor/bin/superaicore call "你好" --backend=codex_cli                               # Codex CLI（ChatGPT 登录）
./vendor/bin/superaicore call "你好" --backend=gemini_cli                              # Gemini CLI（Google OAuth）
./vendor/bin/superaicore call "你好" --backend=copilot_cli                             # GitHub Copilot CLI（订阅）
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

# 一键安装缺失的引擎 CLI（显式 —— 永不自动触发）
./vendor/bin/superaicore cli:status                           # 安装/版本/登录/提示一览
./vendor/bin/superaicore cli:install --all-missing            # npm/brew/script 安装，默认带确认
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
  SuperAgent SDK  ────────▶ anthropic(-proxy) /        ────▶ superagent
                            openai(-compatible)

  Dispatcher ← BackendRegistry   （管理上述 8 个适配器）
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
