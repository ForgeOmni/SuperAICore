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
  - Anthropic API Key —— `anthropic_api`
  - OpenAI API Key —— `openai_api`

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
AI_CORE_SUPERAGENT_ENABLED=true
AI_CORE_ANTHROPIC_API_ENABLED=true
AI_CORE_OPENAI_API_ENABLED=true
AI_CORE_GEMINI_API_ENABLED=true
CLAUDE_CLI_BIN=claude
CODEX_CLI_BIN=codex
GEMINI_CLI_BIN=gemini
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
./vendor/bin/super-ai-core list-backends

# 通过 Anthropic API 来回测一次
./vendor/bin/super-ai-core call "ping" --backend=anthropic_api --api-key="$ANTHROPIC_API_KEY"
```

预期：返回一段短文本以及用量信息。

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

## 8. 升级

```bash
composer update forgeomni/superaicore
php artisan vendor:publish --tag=super-ai-core-migrations --force
php artisan migrate
```

在使用 `--force` 覆盖配置前，请查看 [CHANGELOG.md](CHANGELOG.md) 中的破坏性变更。

## 常见问题

- **`Class 'SuperAgent\Agent' not found`** —— 你移除了 `forgeomni/superagent`，但仍保留 `AI_CORE_SUPERAGENT_ENABLED=true`。设为 `false` 或重新安装 SDK。
- **CLI 后端不可用** —— 执行 `which claude` / `which codex`。若为空，安装对应 CLI，或在 `CLAUDE_CLI_BIN` / `CODEX_CLI_BIN` 中填写绝对路径。
- **`ai_usage_logs` 没有记录** —— 检查 `AI_CORE_USAGE_TRACKING=true` 且迁移已执行。
- **`vendor:publish` 提示不明确** —— 显式传入上面列表中的 `--tag`。
