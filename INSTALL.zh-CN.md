# 安装指南 — forgeomni/superaicore

[English](INSTALL.md) · [简体中文](INSTALL.zh-CN.md) · [Français](INSTALL.fr.md)

本文介绍如何将 `forgeomni/superaicore` 完整安装到现有的 Laravel 10/11/12 项目中。

## 1. 环境要求

- PHP ≥ 8.1，启用 `ext-json`、`ext-mbstring`、`ext-pdo`
- Composer 2.x
- Laravel 10、11 或 12（全新项目同样适用）
- SQL 数据库（MySQL 8+、PostgreSQL 13+ 或 SQLite 3.35+）
- 按后端选择性安装：
  - `claude` CLI 在 `$PATH` 中 —— Claude CLI 后端
  - `codex` CLI 在 `$PATH` 中 —— Codex CLI 后端
  - `gemini` CLI 在 `$PATH` 中 —— Gemini CLI 后端
  - `copilot` CLI 在 `$PATH` 中（再跑 `copilot login`）—— GitHub Copilot CLI 后端
  - `kiro-cli` 在 `$PATH` 中（再跑 `kiro-cli login`；或设置 `KIRO_API_KEY` 走 headless，需 Pro / Pro+ / Power）—— Kiro CLI 后端（0.6.1+）
  - Anthropic API Key —— `anthropic_api`
  - OpenAI API Key —— `openai_api`
  - Google AI Studio Key —— `gemini_api`

## 2. 通过 Composer 安装

```bash
composer require forgeomni/superaicore
```

如果你**不需要** SuperAgent 后端，可以在安装前移除兄弟包依赖：

```bash
# 可选 —— 移除 SuperAgent SDK 依赖
composer remove forgeomni/superagent
# 然后在 .env 中：
# AI_CORE_SUPERAGENT_ENABLED=false
```

当 SDK 缺失时，`SuperAgentBackend` 会自我标记为不可用，Dispatcher 将自动回退到其余四个后端。

## 3. 发布配置与迁移

```bash
php artisan vendor:publish --tag=super-ai-core-config
php artisan vendor:publish --tag=super-ai-core-migrations
php artisan vendor:publish --tag=super-ai-core-views    # 仅在需要覆写 Blade 模板时执行
```

配置文件会放到 `config/super-ai-core.php`。迁移会创建 8 张表：

- `integration_configs`
- `ai_capabilities`
- `ai_services`
- `ai_service_routing`
- `ai_providers`
- `ai_model_settings`
- `ai_usage_logs`
- `ai_processes`

执行迁移：

```bash
php artisan migrate
```

## 4. 环境变量

最简 `.env`（启用 HTTP 后端）：

```dotenv
AI_CORE_DEFAULT_BACKEND=anthropic_api
ANTHROPIC_API_KEY=sk-ant-...
# 或使用 OpenAI：
OPENAI_API_KEY=sk-...
```

完整环境变量列表（默认值见 `config/super-ai-core.php`）：

```dotenv
# 路由与 UI
AI_CORE_ROUTES_ENABLED=true
AI_CORE_ROUTE_PREFIX=super-ai-core
AI_CORE_VIEWS_ENABLED=true
SUPER_AI_CORE_LAYOUT=super-ai-core::layouts.app

# 宿主集成（可选）
SUPER_AI_CORE_HOST_BACK_URL=https://your-host.app/dashboard
SUPER_AI_CORE_HOST_NAME="你的宿主应用"
SUPER_AI_CORE_HOST_ICON=bi-arrow-left
SUPER_AI_CORE_LOCALE_COOKIE=locale

# 后端
AI_CORE_CLAUDE_CLI_ENABLED=true
AI_CORE_CODEX_CLI_ENABLED=true
AI_CORE_GEMINI_CLI_ENABLED=true
AI_CORE_COPILOT_CLI_ENABLED=true
AI_CORE_KIRO_CLI_ENABLED=true
AI_CORE_SUPERAGENT_ENABLED=true
AI_CORE_ANTHROPIC_API_ENABLED=true
AI_CORE_OPENAI_API_ENABLED=true
AI_CORE_GEMINI_API_ENABLED=true
CLAUDE_CLI_BIN=claude
CODEX_CLI_BIN=codex
GEMINI_CLI_BIN=gemini
COPILOT_CLI_BIN=copilot
KIRO_CLI_BIN=kiro-cli
AI_CORE_COPILOT_ALLOW_ALL_TOOLS=true
# Kiro 的 --no-interactive 模式默认拒绝未预先授权的工具；除非使用
# --trust-tools=<categories> 预置白名单，否则保持 true（0.6.1+）。
AI_CORE_KIRO_TRUST_ALL_TOOLS=true
# Kiro API key 鉴权（headless，需 Pro / Pro+ / Power 订阅）。设置该变量
# 后 kiro-cli 会跳过浏览器登录流程。通常通过 provider type=kiro-api 存到 DB
# 里使用，仅当直接调 kiro-cli（不经 superaicore dispatcher）时才需要导出
# 这个 env（0.6.1+）。
# KIRO_API_KEY=ksk_...
# 0.5.8+：cli:status 中 copilot 行的可选 liveness 探测，默认关闭
# （每次状态轮询 spawn 一次 `copilot --help` 成本过高）。
SUPERAICORE_COPILOT_PROBE=false
# 0.6.0+：CLI 启动时可选的模型目录自动刷新。两个都要设置才会触发，
# 且本地覆盖文件超过 7 天才会真正执行；网络错误会被吞掉。
# SUPERAGENT_MODELS_URL=https://your-cdn/models.json
# SUPERAGENT_MODELS_AUTO_UPDATE=1
ANTHROPIC_BASE_URL=https://api.anthropic.com
OPENAI_BASE_URL=https://api.openai.com
GEMINI_BASE_URL=https://generativelanguage.googleapis.com

# 表名前缀（默认 sac_，置空则保留原始 ai_* 名称）
AI_CORE_TABLE_PREFIX=sac_

# 使用量、MCP、监控
AI_CORE_USAGE_TRACKING=true
AI_CORE_USAGE_RETAIN_DAYS=180
AI_CORE_MCP_ENABLED=true
AI_CORE_MCP_INSTALL_DIR=/var/lib/mcp
AI_CORE_PROCESS_MONITOR=false
```

## 5. 冒烟测试

```bash
# 查看当前环境下可用的后端
./vendor/bin/superaicore list-backends

# 通过 Anthropic API 来回测一次
./vendor/bin/superaicore call "ping" --backend=anthropic_api --api-key="$ANTHROPIC_API_KEY"
```

预期：返回一段短文本以及用量信息。

### Skill 与 sub-agent CLI 冒烟

如果本机已经装过 Claude Code 的 skill 或 sub-agent（项目 `./.claude/skills/`、`~/.claude/plugins/*/skills/`、用户 `~/.claude/skills/` 或 `~/.claude/agents/`），它们会被自动拾取：

```bash
./vendor/bin/superaicore skill:list
./vendor/bin/superaicore agent:list

# --dry-run 只打印解析出来的命令，不真的调后端 CLI
./vendor/bin/superaicore skill:run <name> --dry-run

# 为每个 skill/agent 生成 Gemini 自定义命令
# （写入 ~/.gemini/commands/skill/*.toml 与 agent/*.toml）
./vendor/bin/superaicore gemini:sync --dry-run

# 把 Claude 风格 agent 翻译成 Copilot 的 .agent.md 格式
# （`agent:run --backend=copilot` 会自动触发；这里是手动预览）
./vendor/bin/superaicore copilot:sync --dry-run

# 同样的契约对 Kiro 也成立（0.6.1+）：agent 翻译成 ~/.kiro/agents/<name>.json
# （`agent:run --backend=kiro` 会自动触发；这里是手动预览）
./vendor/bin/superaicore kiro:sync --dry-run
```

不需要额外配置。不带 `--dry-run` 时会 shell out 到真实的后端 CLI（`claude`、`codex`、`gemini`、`copilot`、`kiro-cli`）—— 按需装：

```bash
npm i -g @anthropic-ai/claude-code
brew install codex        # 或 cargo install codex
npm i -g @google/gemini-cli
npm i -g @github/copilot   # 然后 `copilot login`（OAuth device flow）
# kiro-cli —— 按 https://kiro.dev/cli/ 安装，然后 `kiro-cli login`
# （或 export KIRO_API_KEY=ksk_... 走 Pro / Pro+ / Power 订阅的 headless 模式）
```

一键替代（推荐）—— 让 superaicore 自己检测并安装：

```bash
./vendor/bin/superaicore cli:status                 # 看哪几个缺
./vendor/bin/superaicore cli:install --all-missing  # 一次装齐（带确认提示）
```

### 模型目录冒烟测试（0.6.0+）

每当宿主 config 没有枚举某个模型，`CostCalculator` 与各引擎的 `ModelResolver` 会回退到 SuperAgent 的模型目录。不用改 `composer.json` 也不用 `config/super-ai-core.php`，直接查看已加载内容并刷新用户覆盖文件：

```bash
./vendor/bin/superaicore super-ai-core:models status                       # 内置 / 用户覆盖 / 远程 URL + 过期提示
./vendor/bin/superaicore super-ai-core:models list --provider=anthropic    # 每百万 token 价格 + 别名
./vendor/bin/superaicore super-ai-core:models update                       # 从 SUPERAGENT_MODELS_URL 拉到 ~/.superagent/models.json
./vendor/bin/superaicore super-ai-core:models update --url https://…       # 本次运行临时指定 URL
./vendor/bin/superaicore super-ai-core:models reset -y                     # 删除用户覆盖文件
```

## 6. 打开后台 UI

默认挂载点为 `/super-ai-core`。开箱即用的路由带有 `['web', 'auth']` 中间件，因此先登录你的 Laravel 应用再访问：

- `http://your-app.test/super-ai-core/integrations`
- `http://your-app.test/super-ai-core/providers`
- `http://your-app.test/super-ai-core/services`
- `http://your-app.test/super-ai-core/usage`
- `http://your-app.test/super-ai-core/costs`

若需启用进程监控（仅管理员），设置 `AI_CORE_PROCESS_MONITOR=true`。

## 7. 无 UI / 仅服务模式

完全跳过路由和视图，直接从容器解析服务：

```dotenv
AI_CORE_ROUTES_ENABLED=false
AI_CORE_VIEWS_ENABLED=false
```

```php
$dispatcher = app(\SuperAICore\Services\Dispatcher::class);
$result = $dispatcher->dispatch([
    'prompt' => '帮我总结这张工单',
    'task_type' => 'summarize',
]);
```

## 8. 宿主侧使用量追踪 —— `UsageRecorder`（0.6.2+）

如果宿主应用不是走 `Dispatcher::dispatch()`，而是自己 spawn CLI（例如 `App\Services\ClaudeRunner`、阶段任务、`ExecuteTask` 流水线），这些执行不会自动写 `ai_usage_logs` —— Dispatcher 是唯一写入者。在每个 CLI 完成路径上调用一次 `UsageRecorder::record()`，即可写入一条正规记录，`cost_usd` / `shadow_cost_usd` / `billing_model` 全部按 catalog 自动补齐：

```php
use SuperAICore\Services\UsageRecorder;

// 已从 CLI 的 stream-json / stdout 中解析出的 tokens：
app(UsageRecorder::class)->record([
    'task_type'     => 'ppt.strategist',      // 任意能聚合的分组键
    'capability'    => 'agent_spawn',
    'backend'       => 'claude_cli',
    'model'         => 'claude-sonnet-4-5-20241022',
    'input_tokens'  => 12345,
    'output_tokens' => 6789,
    'duration_ms'   => 45000,
    'user_id'       => auth()->id(),
    'metadata'      => ['ppt_job_id' => 42],
]);
```

如果手头只有原始 CLI stdout、还没自己抽 token，`CliOutputParser` 覆盖了常见格式：

```php
use SuperAICore\Services\CliOutputParser;

$env = CliOutputParser::parseClaude($stdout);    // 或 parseCodex / parseCopilot / parseGemini
// $env = ['text' => '…', 'model' => '…', 'input_tokens' => 12345, 'output_tokens' => 6789, …]；不匹配返回 null
```

`UsageRecorder` 以单例注册；当 `AI_CORE_USAGE_TRACKING=false` 时自动 no-op。

## 9. 升级

```bash
composer update forgeomni/superaicore
php artisan vendor:publish --tag=super-ai-core-migrations --force
php artisan migrate
```

在使用 `--force` 覆盖配置前，请查看 [CHANGELOG.md](CHANGELOG.md) 中的破坏性变更。

**0.6.2 迁移** —— 给 `ai_usage_logs` 加两列（均可空）：`shadow_cost_usd decimal(12,6)` 与 `billing_model varchar(20)`。安全、非破坏性。历史行值为 `NULL`（仪表盘显示 `—`）；新写入由 Dispatcher 自动填充。如需清掉 0.6.1 之前残留的 `task_type=NULL` 测试行：

```sql
DELETE FROM ai_usage_logs WHERE task_type IS NULL AND input_tokens = 0 AND output_tokens = 0;
```

## 常见问题

- **`Class 'SuperAgent\Agent' not found`** —— 你移除了 `forgeomni/superagent`，但仍保留 `AI_CORE_SUPERAGENT_ENABLED=true`。设为 `false` 或重新安装 SDK。
- **CLI 后端不可用** —— 执行 `which claude` / `which codex`。若为空，安装对应 CLI，或在 `CLAUDE_CLI_BIN` / `CODEX_CLI_BIN` 中填写绝对路径。
- **`ai_usage_logs` 没有记录** —— 检查 `AI_CORE_USAGE_TRACKING=true` 且迁移已执行。
- **`vendor:publish` 提示不明确** —— 显式传入上面列表中的 `--tag`。
