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
  - `cursor-agent` 在 `$PATH` 中（再跑 `cursor-agent login`；或设置 `CURSOR_API_KEY` 走 headless）—— Cursor Composer CLI 后端（1.0.0+）
  - `grok` 在 `$PATH` 中（再跑 `grok login`）—— xAI Grok Build CLI 后端（1.0.0+）
  - Anthropic API Key —— `anthropic_api`
  - OpenAI API Key —— `openai_api`
  - Google AI Studio Key —— `gemini_api`
  - xAI API Key（`XAI_API_KEY` / `GROK_API_KEY`）—— 经 `superagent` 走按量计费的 `grok` provider type（1.0.0+）

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

配置文件会放到 `config/super-ai-core.php`。迁移会创建 10 张表：

- `integration_configs`
- `ai_capabilities`
- `ai_services`
- `ai_service_routing`
- `ai_providers`
- `ai_model_settings`
- `ai_usage_logs`
- `ai_processes`
- `skill_executions`（0.8.6+）
- `skill_evolution_candidates`（0.8.6+）

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
CURSOR_CLI_BIN=cursor-agent
GROK_CLI_BIN=grok
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
# Cursor Composer + Grok Build CLI（1.0.0+）。订阅制引擎 —— 各自管自己的
# 登录（~/.cursor、~/.grok）。`force`/`always_approve` 会在 headless 运行中
# 自动批准工具；置 false 可恢复逐工具确认。
AI_CORE_CURSOR_CLI_ENABLED=true
AI_CORE_CURSOR_FORCE=true
AI_CORE_GROK_CLI_ENABLED=true
AI_CORE_GROK_ALWAYS_APPROVE=true
# CURSOR_API_KEY=...   # headless Cursor（否则用 `cursor-agent login`）
# 经 superagent 走按量计费的 `grok` provider type 所需的 xAI API key（1.0.0+）。
# 与上面 grok.com 订阅制的 `grok` CLI 引擎是两回事。
# XAI_API_KEY=xai-...  # 也接受 GROK_API_KEY 作为后备名
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

### 派单冒烟测试（1.1.0）

```bash
# 聚合体检：引擎、认证、backend、别名、偏好文件、运行存档
./vendor/bin/superaicore doctor

# 短名一步派单，返回完整 JSON 路由契约
./vendor/bin/superaicore send sonnet "ping" --json-result

# 查看路由池与运行存档
./vendor/bin/superaicore aliases
./vendor/bin/superaicore runs list
```

预期：`doctor` 以 `N ok, 0 warn, 0 fail` 之类的汇总结尾（warn 无碍——只是
标记你没装的引擎）；`send` 返回的 JSON 契约中 `ok` 为 `true`。详见
[docs/ai-dispatch-parity.md](docs/ai-dispatch-parity.md)。

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

在绑定了 `SkillLibrary` 的 Laravel 宿主里（1.0.6+），一条 artisan 命令就能把你
整个 skill + agent 库 fan-out 到每个已安装 CLI 的原生形态 ——
codex/gemini/grok/cursor/qwen 的 skill 目录,以及 copilot/kimi/kiro 的指令文件 ——
并在同一趟里重新传播 MCP。它是 symlink 安全、指纹惰性的,所以重跑既便宜又幂等：

```bash
php artisan superaicore:sync-cli                         # skills + MCP → 每个已安装的 CLI
php artisan superaicore:sync-cli --skills-only --backends=codex,gemini
```

`TaskRunner` 也会在每次 CLI 分发前惰性跑一次这个 skill sync（只比对一次指纹），
所以这条命令是给手动 / cron / git-hook 刷新用的。没有绑定 `SkillLibrary` 时,
它打印一行 skip 信息,什么都不做。

不需要额外配置。不带 `--dry-run` 时会 shell out 到真实的后端 CLI（`claude`、`codex`、`gemini`、`copilot`、`kiro-cli`、`cursor-agent`、`grok`）—— 按需装：

```bash
npm i -g @anthropic-ai/claude-code
brew install codex        # 或 cargo install codex
npm i -g @google/gemini-cli
npm i -g @github/copilot   # 然后 `copilot login`（OAuth device flow）
# kiro-cli —— 按 https://kiro.dev/cli/ 安装，然后 `kiro-cli login`
# （或 export KIRO_API_KEY=ksk_... 走 Pro / Pro+ / Power 订阅的 headless 模式）
curl https://cursor.com/install -fsS | bash   # 然后 `cursor-agent login`（1.0.0+）
curl -fsSL https://grok.com/install.sh | bash  # 然后 `grok login`（1.0.0+）
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

## 9. 通过 `provider_types` config 扩展 provider type（0.6.2+）

SuperAICore 内置 15 种 provider type（`builtin` / `moonshot-builtin` / `anthropic` / `anthropic-proxy` / `bedrock` / `vertex` / `google-ai` / `openai` / `openai-compatible` / `openai-responses` / `lmstudio` / `deepseek` / `qwen-anthropic` / `grok` / `kiro-api`）—— 每种都在 `Services\ProviderTypeRegistry::bundled()` 里带有 label、图标、表单字段、env 键名、base-url env、允许的 backend、`extra_config → env` 映射。宿主应用可以重命名已有 type（例如把 `label_key` 指到宿主自己的 lang 命名空间），也可以加全新的 type，一段 config 搞定,不用 fork:

```php
// config/super-ai-core.php
return [
    // …其他键…

    'provider_types' => [
        // 重命名已有 type,其余字段继承默认值
        \SuperAICore\Models\AiProvider::TYPE_ANTHROPIC => [
            'label_key' => 'integrations.ai_provider_anthropic',
            'icon'      => 'bi-key',
        ],

        // 新增一个 bundle 里没有的 type。字段形状照着
        // ProviderTypeDescriptor::fromArray() 即可。registry 会自动
        // 驱动 /providers UI、env builder、AiProvider::requiresApiKey()
        // 以及各 backend 的 buildEnv() 调用,无需别处再改。
        'xai-api' => [
            'label_key'        => 'integrations.ai_provider_xai',
            'icon'             => 'bi-x-lg',
            'fields'           => ['api_key'],
            'default_backend'  => \SuperAICore\Models\AiProvider::BACKEND_SUPERAGENT,
            'allowed_backends' => [\SuperAICore\Models\AiProvider::BACKEND_SUPERAGENT],
            'env_key'          => 'XAI_API_KEY',
        ],
    ],
];
```

以后 SuperAICore 上游再加新 type(例如 `TYPE_ANTHROPIC_VERTEX_V2`),宿主只要跑一次 `composer update` 就会看到 —— **完全零代码改动**。Registry 在容器里注册为 `app(\SuperAICore\Services\ProviderTypeRegistry::class)`,常用三个入口:`get($type)` / `all()` / `forBackend($backend)`。

宿主应用过去复制过 SuperAICore provider-type 矩阵的（SuperTeam 0.6.2 之前在 `IntegrationController::PROVIDER_TYPES` + `ClaudeRunner::providerEnvVars()` 里维护过），现在可以把那些拷贝**替换为对 `ProviderTypeRegistry` + `ProviderEnvBuilder` 的一行代理**。[CHANGELOG.md](CHANGELOG.md) 的 "Host-app migration" 一节有 before/after 的代码片段。

## 10. 从 runner 类自动记录 usage（0.6.5+）

如果宿主有用 `Runner\Concerns\MonitoredProcess` trait spawn CLI 子进程的类（SuperTeam 的 `ClaudeRunner` 就是典型例子），任何一条 spawn 路径都可以把 `runMonitored()` 换成 `runMonitoredAndRecord()` 来自动写 `ai_usage_logs`。新方法会 buffer stdout、退出时用 `CliOutputParser` 解析、然后调 `UsageRecorder::record()` —— 一次方法调用替掉宿主 runner 每个 backend 通常要写的 20–40 行 parser + recorder 胶水。

```php
use Symfony\Component\Process\Process;

class MyRunner {
    use \SuperAICore\Runner\Concerns\MonitoredProcess;

    public function run(Task $task): int
    {
        $process = Process::fromShellCommandline(
            'claude -p "…" --output-format=stream-json --verbose'
        );

        // runMonitored() —— spawn + 注册到进程监控。旧行为,不碰输出格式。
        // runMonitoredAndRecord() —— 同上,外加退出时自动记一条 usage。
        return $this->runMonitoredAndRecord(
            process:         $process,
            backend:         'claude_cli',
            commandSummary:  'claude -p "review" --output-format=stream-json',
            externalLabel:   "task:{$task->id}",
            engine:          'claude',          // 驱动 CliOutputParser 选择
            context:         [
                'task_type'  => 'tasks.run',
                'capability' => 'agent_spawn',
                'user_id'    => $task->user_id,
                'provider_id'=> $task->provider_id,
                'metadata'   => ['task_id' => $task->id],
            ],
        );
    }
}
```

CLI 的 exit code 始终原样返回。如果 `CliOutputParser` 没能匹配当前流的格式(Codex / Copilot 的纯文本输出就会这样),不写任何行、打一条 `debug` 级 log —— 这是 opt-in 设计,就是为了避免静默改变 runner 的既有输出形态。

## 11. 升级

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

**0.6.6 迁移** —— 给 `ai_usage_logs` 加一列可空列 + 一个复合索引：`idempotency_key varchar(80)` + `(idempotency_key, created_at)`。为 Dispatcher 60s 去重窗口服务。

**0.6.7 —— 无迁移。** 纯运行时行为改动。两点值得复核：

1. **父进程是 `claude` shell 再起 Laravel server 的场景**（例如在 Claude Code 会话里敲 `php artisan serve`），升级后 claude 会开始正常认证。如果之前你自己的 runner 里做了手工 env-scrub 来绕这个坑,现在它冗余但无害。
2. **自带 `ProcessSource` 的宿主**应该把自己的 label 前缀加到新配置键,免得 `AiProcessSource` 在你已经渲染了富行之外再输出一条裸行:

   ```php
   // config/super-ai-core.php
   'process_monitor' => [
       'enabled' => env('AI_CORE_PROCESS_MONITOR', false),
       'host_owned_label_prefixes' => ['task:'],   // SuperTeam 约定
   ],
   ```

`AiProcessSource::list()` 的契约现在明确为 **live-only** —— 只返回当前在跑的 OS 进程。之前靠 `list()` 拿历史行的宿主,请直接查 `ai_processes` 表（仍然是每次 spawn 的完整审计日志）。

**0.6.8 —— 无迁移。** 纯增量功能。三点值得复核:

1. **接入 catalog 驱动的 MCP 同步是 opt-in 的。** 在 `.mcp-servers/mcp-catalog.json` 放一份目录,在 `.claude/mcp-host.json` 里选好项目 tier / agent tier 的 server 分配,然后 `php artisan claude:mcp-sync --dry-run` 预览。不跑这个命令的宿主零变化 —— 你不调用,它不改盘。shape 详见 `docs/mcp-sync.md`。

2. **升级 `SuperAgentBackend` 调用方。** 原来的 one-shot 调用方零改动(`max_turns` 仍默认 1,envelope 里新 key 都是加法)。SDK 已锁到 **`forgeomni/superagent` 0.8.9**。要真正吃到 in-process 路径的新能力,传这些:
   ```php
   $dispatcher->dispatch([
       'prompt'          => '…',
       'backend'         => 'superagent',
       'max_turns'       => 10,              // 真正跑 agentic loop
       'max_cost_usd'    => 1.50,            // Agent::withMaxBudget() 硬卡
       'mcp_config_file' => base_path('.mcp.json'),
       'provider_config' => ['provider' => 'kimi', 'region' => 'cn'],  // 区域感知
       'load_tools'      => ['agent'],       // 可选:开启 SDK 子 agent dispatch(AgentTool)
   ]);

   // 当传了 load_tools: ['agent'] 且 run 里实际分发了子 agent 时,envelope 多一个可选的
   // subagents key(SDK 0.8.9 productivity):
   //   [
   //     ['agentId' => 'research-jordan',
   //      'status' => 'completed',           // 或 'completed_empty' —— 零工具调用
   //      'filesWritten' => ['/abs/path.md'],
   //      'toolCallsByName' => ['Read' => 3, 'Write' => 1],
   //      'productivityWarning' => null,     // 调了工具但没写文件时有 advisory 字符串
   //      'totalToolUseCount' => 4],
   //     …,
   //   ]
   // `status === 'completed_empty'` 或 productivityWarning 非 null 时建议重 dispatch。
   // 没子 agent 运行时 key 不出现 —— 不走 AgentTool 的调用方零改动。
   ```

3. **一条命令调试 API provider。** `bin/superaicore api:status` 对所有有 API-key env 的 provider 做 5s cURL 探测;`--all` 覆盖全部 DEFAULT_PROVIDERS、`--json` 输出给 dashboard。auth 被拒(HTTP 401/403)、网络超时、key 未配,三种情况每个都有独立的 `reason`。

4. **弱模型 agent-spawn 加固自动生效。** 用 `AgentSpawn\Pipeline`(包括所有 `TaskRunner` + `spawn_plan_dir` 的宿主)的 caller 升级后零改动拿到五层防御:宿主端 `task_prompt` guard 注入(CJK 检测自动分中英文)、canonical ASCII `output_subdir`、fanout 前清早产 consolidator 文件、fanout 后 contract 审计、语言感知的 consolidation prompt(禁止自创错误文件名)。两个副作用值得注意:
   - 每个 agent 的 `run.log` / 提示词 / 执行脚本现在写到 `$TMPDIR/superaicore-spawn-<date>-<hex>/<agent>/`,而不是 `$outputRoot/<agent>/`。用户可见输出目录只放真交付物(`.md` / `.csv` / `.png`)。宿主里有 glob `$outputRoot/<agent>/run.log` 的地方需要更新路径。
   - `Orchestrator::run()` 返回的每行 report 现在可能带 `warnings[]`。只读 `exit` / `log` / `duration_ms` / `error` 的旧调用方源码兼容(key 在 PHPDoc 里标了 optional)。

**0.6.9 —— 无迁移。** 新增表面 + SDK bump 自带的五项自动修复。Composer 约束从 `^0.8.0` 抬到 `^0.9.0`。四点值得复核:

1. **Qwen provider key 重绑（SDK 层）。** SDK 0.9.0 把 `qwen` 注册键重绑到 OpenAI-兼容 provider(`<region>/compatible-mode/v1/chat/completions`),原 DashScope 原生 body shape 搬到 `qwen-native`。如果你在 wire 层面依赖 `parameters.thinking_budget` / `parameters.enable_code_interpreter` 等 DashScope 原生字段,切换 provider 配置:
   ```php
   'provider_config' => ['provider' => 'qwen-native', 'region' => 'cn'],
   ```
   两个 key 共享 `QWEN_API_KEY` / `DASHSCOPE_API_KEY` env。新的默认 `qwen` 就是 Alibaba 自家 `qwen-code` CLI 生产里用的路径 —— 没动 DashScope 原生字段的就别改。`ApiHealthDetector::DEFAULT_PROVIDERS` 现在包含这两个 key,`api:status` 会并排显示两条线路。

2. **`SuperAgentBackend` 三个新选项。** 全部增量、全部可选:
   ```php
   $dispatcher->dispatch([
       'prompt'           => '…',
       'backend'          => 'superagent',
       'provider_config'  => ['provider' => 'kimi', 'region' => 'cn'],

       // 新增 —— 厂商私有 wire 字段,深合并到请求 body
       'extra_body'       => ['custom_vendor_field' => 'value'],

       // 新增 —— capability-routed features;不支持的 provider 静默跳过
       'features'         => [
           'prompt_cache_key' => ['session_id' => $sessionId],  // Kimi 会话级 prompt cache
           'thinking'         => ['budget' => 4000],             // CoT 带兜底
       ],
       // 便捷写法: 'prompt_cache_key' => $sessionId 自动映射到
       // features.prompt_cache_key.session_id。

       // 新增 —— 流处理 handler 外再包一层 loop-detection harness
       'loop_detection'   => true,    // 或: ['tool_loop_threshold' => 7, ...]
   ]);
   ```
   `loop_detection` 捕获 `TOOL_LOOP`(5 次同工具+同参数)、`STAGNATION`(8 次同名称)、`FILE_READ_LOOP`(最近 15 次里 8 次读取类,带冷启动豁免)、`CONTENT_LOOP`(50 字符滑窗 10 次)、`THOUGHT_LOOP`(3 次同 thinking 文本)。违规通过 SDK wire event 发出 —— 不开启的调用方 AICore envelope 字节级不变。

3. **实时模型 catalog 刷新。** 新子命令:
   ```bash
   ./bin/superaicore super-ai-core:models refresh              # 刷新所有有 env 凭据的 provider
   ./bin/superaicore super-ai-core:models refresh --provider=kimi
   php artisan super-ai-core:models refresh --provider=qwen
   ```
   把每个 provider 的实时 `GET /models` 拉进 `~/.superagent/models-cache/<provider>.json`。overlay 在用户 override 之上、`register()` 运行时注册之下,bundled 定价在厂商 `/models` 不返回费率时保留。`CostCalculator` / `ModelResolver` 下一次调用自动接上 —— 无需重启,无需重新 publish 配置。

4. **Kimi Code / Qwen Code OAuth。** 没有 API key 的话,通过 SDK CLI 交互登录:
   ```bash
   ./vendor/bin/superagent auth login kimi-code     # 对 auth.kimi.com 走 RFC 8628 device flow
   ./vendor/bin/superagent auth login qwen-code     # 对 chat.qwen.ai 走 device flow + PKCE S256
   ```
   token 会落到 `~/.superagent/credentials/<kimi-code|qwen-code>.json`。`ApiHealthDetector::filterToConfigured()` 现在会把这两个文件也识别为 "已配置",即便没有 `KIMI_API_KEY` / `QWEN_API_KEY` env,`api:status` 和 `/providers` 依然会显示。Anthropic OAuth 刷新路径现在跨进程 `flock` 串行化,Laravel 多 worker 共用已存的 OAuth 凭据时不会再互相覆盖 refresh token。

**针对 mcp.json 里声明 `oauth:` 块的 MCP 服务器**,UI 可以调用 `McpManager::oauthStatus($key)` / `oauthLogin($key)` / `oauthLogout($key)`。`oauthLogin()` 在 device-flow 轮询期间阻塞 stdio —— 放队列任务里跑,别在 request 内联。既有的 `startAuth()` / `clearAuth()` / `testConnection()`(处理 LinkedIn scraper 这类浏览器登录 / session-dir 服务器)不受影响。

**0.7.0 —— 无迁移。** 新增接口 + 修复一处长期存在的映射问题。Composer 约束从 `^0.9.0` 抬到 `^0.9.1`。五件事值得留意:

1. **两个新 provider 类型:`openai-responses` 和 `lmstudio`。** 都走 `superagent` 后端（SDK 键分别为 `openai-responses` / `lmstudio`）。
   - **OpenAI Responses API** —— 按量模式:添加一个 `type = openai-responses` 且带 API key 的 provider 行。ChatGPT 订阅模式:把 `api_key` 留空，将 `access_token`（来自宿主 ChatGPT OAuth 流程）存进 `extra_config.access_token` —— SDK 会自动把 base URL 切到 `chatgpt.com/backend-api/codex`。Azure OpenAI:`base_url` 填成 `https://<name>.openai.azure.com/openai/deployments/<deployment>` —— SDK 自动追加 `api-version=2025-04-01-preview` query（通过 `extra_config.azure_api_version` 覆盖）。
   - **LM Studio** —— `base_url` 指向本地 LM Studio 服务（默认 `http://localhost:1234/v1`）。无需真 API key；SDK 自动合成占位 `Authorization` 头。适合断网 / on-prem 场景。

2. **幂等 key 通过 SDK 往返。** 如果原本就在 `Dispatcher::dispatch()` 选项里传 `idempotency_key`，业务代码不用改 —— 但值现在会随 SDK 的 `AgentResult` 流转，而不是旁路经 UsageRecorder。Dispatcher 和 UsageRecorder 即便跑在不同进程上，写入侧也无需重算 key。基于 `external_label` 派生的 auto-key 同样适用:Dispatcher 先算出 `"{backend}:{external_label}"` 转发给 `Agent::run()`，写 `ai_usage_logs` 时优先采用 envelope 回显值。

3. **W3C trace context 透传。** 宿主若有中间件读取入站 `traceparent` 头，转发给 Dispatcher 即可:
   ```php
   $dispatcher->dispatch([
       'prompt'       => '…',
       'backend'      => 'superagent',
       'provider_config' => ['type' => 'openai-responses', 'api_key' => env('OPENAI_API_KEY')],
       'traceparent'  => $request->header('traceparent'),  // null 时静默跳过
       'tracestate'   => $request->header('tracestate'),
   ]);
   ```
   SDK 把这些投影到 Responses API 的 `client_metadata`，OpenAI 侧日志就能和宿主分布式 trace 对上。非法字符串静默丢弃 —— 无条件传也安全。

4. **分类的 `ProviderException` 子类。** `SuperAgentBackend` 的 catch 阶梯现在在通用 `\Throwable` 之前先分 SDK 的六个子类（`ContextWindowExceeded` / `QuotaExceeded` / `UsageNotIncluded` / `CyberPolicy` / `ServerOverloaded` / `InvalidPrompt`），每个都以稳定的 `error_class` tag + `retryable` 标记记日志。契约不变 —— 失败仍然返回 `null` —— 调用点不破。需要更聪明路由的宿主继承 `SuperAgentBackend` 并 override `logProviderError(\Throwable $e, string $code)` seam。

5. **按 provider 类型声明 HTTP 头。** descriptor 新增两个字段 —— `http_headers`（字面量 header → value）和 `env_http_headers`（header → env 变量名，请求时读），无需改代码就能给某个类型的每次 SDK 调用注入 `OpenAI-Project`、`LangSmith-Project`、`OpenRouter-App` 等头。示例:
   ```php
   // config/super-ai-core.php
   'provider_types' => [
       // 每次 OpenAI 调用都打上自己的 app id，同时识别 OPENAI_PROJECT 环境变量
       // （SDK 在 env 变量未设时静默跳过这个 header，没配置 project-scoped key
       // 的宿主也不会出错）。
       \SuperAICore\Models\AiProvider::TYPE_OPENAI => [
           'http_headers'     => ['X-App' => 'my-host-app'],
           'env_http_headers' => ['OpenAI-Project' => 'OPENAI_PROJECT'],
       ],

       // 新 Responses API 类型同理 —— 注入 LangSmith project 头，跨 provider 追踪
       // 不需要额外的 wrapper 层。
       \SuperAICore\Models\AiProvider::TYPE_OPENAI_RESPONSES => [
           'env_http_headers' => ['Langsmith-Project' => 'LANGSMITH_PROJECT'],
       ],
   ],
   ```

**已经存在的 `openai-compatible` / `anthropic-proxy` provider。** 0.7.0 之前，这类行在 `provider_config.provider` 没手工设时会静默路由到 SDK 的 `anthropic` provider —— `anthropic-proxy` 恰好对，`openai-compatible` 就会失败。0.7.0 开始，descriptor 的 `sdk_provider` 负责正确映射（`anthropic` / `openai`）。如果宿主显式设了 `provider_config.provider`，没变化。如果你依赖过那个 bug 默认，`openai-compatible` 行现在开始按正确路由工作。

深入示例（多轮 Responses、LangSmith 追踪、LAN 内 LM Studio、宿主级异常路由、per-provider HTTP 头覆盖）见 `docs/advanced-usage.zh-CN.md`。

**0.7.1 —— 无迁移。** 纯增量契约 —— `Contracts\ScriptedSpawnBackend` 和 `StreamingBackend` 并列（不是替代）。同一版本里六个 CLI 后端（`Claude` / `Codex` / `Gemini` / `Copilot` / `Kiro` / `Kimi`）全部实现之。宿主里此前为每个后端写的 `match ($backend) { 'claude' => buildClaudeProcess(…), 'codex' => buildCodexProcess(…), … }`（任务 spawn 一份、one-shot chat 再一份）可以整体塌缩成一次多态调用:

```php
use SuperAICore\Services\BackendRegistry;

$backend = app(BackendRegistry::class)->forEngine($engineKey);  // 可空 —— 引擎关掉时返回 null
$process = $backend->prepareScriptedProcess([
    'prompt_file'  => $promptFile,
    'log_file'     => $logFile,
    'project_root' => $projectRoot,
    'model'        => $model,
    'env'          => $env,                     // 宿主构造（读 IntegrationConfig）
    'disable_mcp'  => $disableMcp,              // 主要是 Claude 用
    'codex_extra_config_args' => $codexArgs,    // 主要是 Codex 用
]);
$process->start();

// 一次性 chat 的兄弟方法 —— argv 组装、输出解析、ANSI 去色都在 backend 自己做:
$response = $backend->streamChat($prompt, function (string $chunk) {
    echo $chunk;
});

// 1.0.8+ —— 把限定的 MCP server 子集暴露给本轮 chat（仅 Claude；
// 默认仍是锁死的空 MCP 面）。详见 docs/advanced-usage.zh-CN.md §12。
$response = $backend->streamChat($prompt, $onChunk, [
    'mcp_mode'        => 'file',
    'mcp_config_file' => $subsetJsonPath,   // {"mcpServers": {...}}
]);
```

迁移完成后，未来新增的 CLI 引擎只要实现 `ScriptedSpawnBackend` 契约，就会在宿主每条代码路径里自动出现 —— 再无需要加新 `match` 分支。`Support\CliBinaryLocator` 在 service provider 注册为单例，宿主侧 CLI 路径探测与包内各 backend 走一套（`~/.npm-global/bin` / `/opt/homebrew/bin` / nvm 路径 / Windows `%APPDATA%/npm`）。`ClaudeCliBackend::CLAUDE_SESSION_ENV_MARKERS` 现在是公开常量，仍自行组装 `claude` 进程的宿主可以直接拿规范的五标记 scrub 列表。

完整 before/after 迁移模式见 `docs/advanced-usage.zh-CN.md` §12；上下文见 `docs/host-spawn-uplift-roadmap.md`。

**0.8.1 —— 无迁移。** 两个 opt-in 改动，升级时不启用也完全无感。

1. **通过 `mcp.portable_root_var` 让 `.mcp.json` 写入可移植。** 默认仍为 `null`，"路径全部写绝对值" 的 legacy 行为保留。当你希望生成的 `.mcp.json` 跨机器 / 跨用户 / 跨容器复制或同步都不会失效时（典型场景:`mcp` 安装目录就在项目树内，跟项目一起搬迁），就启用它:

   ```dotenv
   # .env —— 任何宿主 MCP runtime 会导出的环境变量名都可以
   AI_CORE_MCP_PORTABLE_ROOT_VAR=SUPERTEAM_ROOT
   ```

   ```jsonc
   // .claude/settings.local.json —— Claude Code 在 spawn MCP 时展开 ${SUPERTEAM_ROOT}
   { "env": { "SUPERTEAM_ROOT": "${PWD}" } }
   ```

   开启后，所有 `McpManager::install*()` 写入路径会输出裸命令（`node` / `php` / `uvx` / `uv` / `python`），并把 `projectRoot()` 下的路径改写成 `${SUPERTEAM_ROOT}/<rel>`。三个 backend-sync 辅助方法（`superfeedMcpConfig` / `codexOcrMcpConfig` / `codexPdfExtractMcpConfig`）也遵循同一开关。出口到 per-machine 目标时（Codex `~/.codex/config.toml`、Gemini / Claude / Copilot / Kiro / Kimi 的 user-scope MCP 配置、`codex exec -c` 运行时 flag），通过 `materializePortablePath()` 把占位符再展开回绝对路径，那些只接受字面值的 backend 一样能正常 spawn。`McpManager` 新增的辅助方法:`portablePath()` / `portableCommand()` / `portableRootVar()` / `materializePortablePath()` / `materializeServerSpec()`。完整 recipes（容器化宿主、多用户挂载、当环境变量未在运行时导出时的处理）见 `docs/advanced-usage.zh-CN.md` §13。

2. **`/providers` 页基于 CLI 可用性收敛 UI。** 纯 UI 修复 —— 不动 controller / 路由 / DB。`$PATH` 上找不到二进制的 CLI 引擎（`claude` / `codex` / `gemini` / `copilot` / `kiro` / `kimi`），引擎开关会渲染成 `disabled`（带提示 + 隐藏字段被钳制），下方 per-backend 表里那条合成的 "built-in (local CLI login)" 行也会在引擎关闭或 CLI 缺失时被隐藏。当 built-in 与任何外部 provider 都不适用时，表格底部会出现一行明确的空态，指出真正的原因。之前用户切到 "Engine on" 后再发现 runtime 静默失败的工单，可以从此免单。

**0.8.5 —— 无迁移。** SDK uptake + 一处正确性修复；不动 DB / config。Composer 约束从 `^0.9.0` 升到 `^0.9.5`。三件值得知道的事：

1. **针对 Kimi / GLM / MiniMax / Qwen / OpenAI / OpenRouter / LMStudio 的多轮 tool-use 回放终于能工作了。** 0.9.5 之前 SDK 的 `ChatCompletionsProvider::convertMessage()` 在第一个 `tool_use` block 提前 return（丢掉同级 text 和并行 tool call），并且访问根本不存在的 `ContentBlock` 属性 —— 每个回放的 tool call 都以 `{id: null, name: null, arguments: "null"}` 出去。任何用 `Dispatcher::dispatch(['backend' => 'superagent', 'max_turns' => 10, …])` 跑这些 provider 的宿主，升级前都是静默坏的。无需改调用代码；SDK 新加的 `Conversation\Transcoder` 把六种 wire family 全统一到一个 converter，一次修复全部 provider 同步生效。

2. **`SuperAgentBackend::buildAgent()` 现在永远把构造好的 `LLMProvider` 实例交给 SDK**（不再交字符串 provider 名 + 散开的 `llmConfig` 键）。生产路径走 `Dispatcher`，从来不检查 `$agentConfig['provider']`，因此对它无感。子类 `SuperAgentBackend` 并 override `makeAgent()` 的宿主，应该把之前断言 `$agentConfig['provider'] === 'sa-test'` 的测试改为 `instanceof \SuperAgent\Contracts\LLMProvider` —— 范例参见 `tests/Unit/SuperAgentBackendTest.php::test_no_region_still_hands_llmprovider_instance_to_agent`。`SuperAgentBackend` 新增的 `makeProvider()` seam 是测试替身的注入点，不必再走 `ProviderRegistry::register()`。

3. **`Agent::switchProvider($name, $config, $policy)` 现在可用。** 直接包 `SuperAgentBackend` 并希望进程内对话中途切 provider family 的宿主可以用。SuperAICore 自己的 `FallbackChain` 走的是 CLI 子进程级别（不同的关心面），没有用这个特性。`HandoffPolicy::default() / preserveAll() / freshStart()` 三种预设和跨家族 wire-format 编码规则见 SDK 的 `[0.9.5]` CHANGELOG。

0.8.1 引入的命名空间 typo 修复（`makeProvider()` 当时返回根本不存在的 `\SuperAgent\Providers\LLMProvider`，导致 SuperAgent in-process backend 在 0.8.1 → 0.8.2 期间静默全坏）也是这次发布的一部分。之前发现 `Dispatcher::dispatch(['backend' => 'superagent', …])` 每次都返回 `null` 的宿主，现在应该能看到真实的 envelope 了 —— 用 `bin/superaicore api:status` 对你 SuperAgent 路由的 provider 验证一下，或跑包内测试套件:480 tests / 1380 assertions。

**0.8.6 —— 新增两张表。** 0.6.6 之后第一次有 `php artisan migrate` 真正落新 schema 的版本。Skill engine 走 **hook 接线 opt-in** 路线 —— 安装包 + 跑迁移之后，Claude Code 的 `PreToolUse(Skill)` 与 `Stop` hook 不指向新的 artisan 命令的话，行为零变化。三件值得知道的事：

1. **跑迁移。** 两张新表:`skill_executions`（每次 Claude Code Skill 工具调用一行 —— 遥测）与 `skill_evolution_candidates`（仅供审核的 FIX 模式 patch 候选）。两张表都通过 `HasConfigurablePrefix` 尊重 `super-ai-core.table_prefix`，`up()` 都用 `Schema::hasTable()` 守卫 —— 重复跑迁移幂等。`down()` 把两张表都 drop —— dev 环境重置安全。

   ```bash
   composer update forgeomni/superaicore
   php artisan vendor:publish --tag=super-ai-core-migrations --force
   php artisan migrate
   ```

2. **接线 hook（宿主侧，可选）。** 包只发 artisan 接入点 —— Claude Code 的 `.claude/settings.local.json` 才是把 hook 真正绑到命令上的地方:

   ```jsonc
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

   两个命令都从 stdin 读 Claude Code hook JSON 负载（1.0s 软超时 + 200KB 上限，非阻塞读，遥测出错绝不让 hook 失败），并通过向上找 `.claude/` 目录来自动识别 `host_app`（取上级目录的 basename）。SuperTeam 的同步 commit 演示完整接线。

3. **可选:每天 cron 一次 `skill:evolve --sweep`。** 一旦遥测数据流起来，evolver 可以扫出指标退化的 skill 并入队仅供审核的 candidate，**默认不烧 token**（不调 LLM）。审核队列见 `php artisan skill:candidates`。

   ```php
   // app/Console/Kernel.php
   $schedule->command('skill:evolve --sweep --threshold=0.30 --min-applied=5')
            ->daily()
            ->withoutOverlapping();
   ```

   `--sweep` 按现有 `pending` 行去重，跨次运行幂等。加 `--dispatch` 还会通过 `Dispatcher`（`capability: 'reasoning'`）调一次 LLM —— 烧 token，但能给审核者一份真正的 diff。evolver **永不**直接改 SKILL.md。DERIVED / CAPTURED 模式（自动从成功 run 派生新 skill / 把用户演示的工作流捕获成新 skill）有意省略 —— Day 0 的策略是人来策展。

六个 artisan 命令（`skill:track-start` / `skill:track-stop` / `skill:stats` / `skill:rank` / `skill:evolve` / `skill:candidates`）都通过 `SuperAICoreServiceProvider::boot()` 注册。**没有**挂到独立的 `bin/superaicore` 控制台 —— 从 Laravel 宿主用 `php artisan` 调用即可。`SkillRanker` 的集成模式（宿主侧 skill 选择器 UI、加权检索、感知遥测的 fallback 链）参见 `docs/advanced-usage.zh-CN.md` §16。

**0.9.0 —— 不需要 migration;SDK 约束升至 `^0.9.7`。** 6 项基于 SuperAgent SDK 0.9.7 的 jcode 启发增量集成。全部按需通过 env 开关激活,关闭时行为与 0.9.7 之前完全一致 —— 仅 `agent_grep` **默认开启**(只读、无外部依赖)。

```bash
composer update forgeomni/superaicore forgeomni/superagent
# 0.9.0 没有 schema 变更,无需 php artisan migrate
```

新增 env 开关(全部可选,除非另注明):

```dotenv
# ─── 内置 SuperAgent 工具(0.9.7) ───
# jcode 风格 `agent_grep` —— 包含符号上下文 + 会话内 chunk 截断。
# 默认开启,因为它是只读且只在真正进入工具循环的 dispatch 上生效。
# 设为 false 可恢复 0.9.7 之前的行为。
AI_CORE_TOOLS_AGENT_GREP=true

# SDK 0.9.7 FirefoxBridgeTool(`browser`) —— 通过 Native Messaging 驱动
# Firefox / Chromium。默认关闭;装好 launcher 后再 true。
AI_CORE_TOOLS_BROWSER=false
# WebExtension 期待的 launcher 二进制路径。launcher 缺失时工具自身返回
# 解释性错误,所以提前打开 AI_CORE_TOOLS_BROWSER=true 也不会让循环崩。
SUPERAGENT_BROWSER_BRIDGE_PATH=/abs/path/to/forgeomni-bridge-launcher

# ─── 浏览器截图存储(0.9.7) ───
# 支撑 ProcessEntry::$latest_screenshot_url。SuperAgentBackend 写入
# `browser` 工具返回的每张 base64 PNG;AiProcessSource 在 reap 时清理。
AI_CORE_BROWSER_SHOTS_DISK=local
AI_CORE_BROWSER_SHOTS_DIR=super-ai-core/browser-screenshots

# ─── Embedding(SemanticSkillReranker + SDK SemanticSkillRouter 共用) ───
# 可选 Ollama daemon。未设置时 reranker 退化到 BM25 排序 —— 没接入的
# host 行为不变。
AI_CORE_EMBEDDINGS_OLLAMA_URL=http://127.0.0.1:11434
AI_CORE_EMBEDDINGS_OLLAMA_MODEL=nomic-embed-text
AI_CORE_EMBEDDINGS_TIMEOUT_MS=10000

# ─── 跨 harness 会话恢复(0.9.7) ───
# 默认关闭 —— importer 在共享机器上能看到所有 operator 的
# ~/.claude / ~/.codex 历史,所以默认 opt-in。
AI_CORE_RESUME_ENABLED=false
```

升级时值得复核的 6 件事:

1. **`agent_grep` 自动加进每个 SuperAgent 工具循环 dispatch。** 工具在 SDK 的 `BuiltinToolRegistry::classMap` 里,`load_tools` 经 `ToolLoader` 解析 —— 只在真正跑工具的 dispatch 上生效(即 `max_turns > 1` 或显式给了 `load_tools` 数组)。一次性调用和 CLI 后端的 dispatch 完全不受影响。如果你有断言确切工具列表的测试,设 `AI_CORE_TOOLS_AGENT_GREP=false`。

2. **`browser` 工具需手动安装。** SDK 自带 `FirefoxBridgeTool`,但 WebExtension 与 launcher 二进制要 host 自己装。安装步骤见 SDK 类 docblock:`vendor/forgeomni/superagent/src/Tools/Browser/FirefoxBridge.php`。launcher 装好且 `SUPERAGENT_BROWSER_BRIDGE_PATH` 配好之前,工具返回解释性错误避免 agent 死循环 —— 提前打开 flag 是安全的。

3. **截图 round-trip 通过 `external_label` 联动。** `SuperAgentBackend::resolveScreenshotKey()` 与 `AiProcessSource::screenshotKeys()` 都优先用 `external_label`,然后回退到 `aiprocess.<id>` 复合 key。Host 在 `Dispatcher::dispatch()` 上传 `external_label`(0.6.6 起的标准约定)就能自动 round-trip。没传的会按随机 key 存,Process Monitor 显示不出来 —— 在 dispatch 里加上 `external_label` 即可对齐。

4. **`SemanticSkillReranker` 现在通过 SDK 解析。** 0.9.0 之前手写的 Ollama HTTP 客户端和 callback 适配代码都删了 —— reranker 从 `EmbeddingProviderFactory` 构造的容器单例里取 SuperAgent SDK 0.9.7 的 `EmbeddingProvider`。三种解析路径:显式 `super-ai-core.embeddings.provider`(host 自带实现)、`super-ai-core.embeddings.callback`(自动包成 `CallableEmbeddingProvider`)、`super-ai-core.embeddings.ollama_url`(`OllamaEmbeddingProvider`)。都没设时 reranker 是干净 no-op —— 契约不变。

5. **`/usage` 多了 "By Source" 卡片。** `Dispatcher::resolveUsageSource()` 写入 `metadata.usage_source`(默认 `'user'`)。SuperAgent 的 `AmbientWorker` 通过 `tagUsage` 回调把后台 tick 标成 `'ambient'` —— 接好 worker,这些行就出现在新卡片里,不需要重写 host 成本统计。宽屏布局自动切到 `col-lg-3`,原有的 By Task Type / By Model / By Backend 卡片也仍然清楚。

6. **`/processes` Resume 下拉默认关闭。** `AI_CORE_RESUME_ENABLED=false` 时下拉隐藏、controller 接口返回 403。仅在"暴露所有 operator 的 `~/.claude` / `~/.codex` 历史可接受"的机器上设 `true`。要让 host 真正"恢复进 backend",而不只是显示文字记录,把 `super-ai-core.resume.on_load` 设为返回 `{redirect: '<url>'}` 的 callable:

    ```php
    // config/super-ai-core.php
    'resume' => [
        'enabled' => env('AI_CORE_RESUME_ENABLED', true),
        'on_load' => function (string $harness, string $sessionId, array $messages) {
            // $messages 是 list<\SuperAgent\Messages\Message> —— 投喂给你的 runner
            $task = MyChatSession::createFromHarnessImport($harness, $sessionId, $messages);
            return ['redirect' => route('chat.show', $task)];
        },
    ],
    ```

完整菜谱(Ollama embedder 接线、browser launcher 准备、AmbientWorker tick 循环、harness 恢复回调模式)见 [docs/advanced-usage.zh-CN.md §17–§21](docs/advanced-usage.zh-CN.md)。

**0.9.1 —— 一张新表;SDK 约束升至 `^0.9.8`。** 5 项 SuperAgent SDK 0.9.8
配套绑定(持久化 goal store、三档审批闸门、workspace plugin manifest、
无头 `/v1/usage` JSON、`cache_hit_rate` 聚合)外加 1 个 backend 加固
修复(`SuperAgentBackend::resolveEmbeddingProvider()` 在 ServiceProvider
未启动时不再抛 `BindingResolutionException`)。

```bash
composer update forgeomni/superaicore forgeomni/superagent
php artisan migrate    # 创建唯一新表 `ai_goals`
```

无强制新 env 开关 —— 每个绑定都是基于容器解析的单例,带合理默认。
要覆盖 goal store、锁紧审批策略,或预置 workspace plugin manifest,
在代码里改:

```php
// config/super-ai-core.php (可选)
return [
    // …已有键…

    // 审批闸门默认模式。Suggest = 变更需要 /approve;
    // Auto = 除破坏性 shell 外全放行;
    // Never = 纯只读。Per-thread 覆盖落在 AiProcess.approval_mode
    // (要持久化的话 host 侧加迁移)。
    'runner' => [
        'approval_mode' => env('AI_CORE_APPROVAL_MODE', 'suggest'),
    ],
];
```

升级时值得复核的六件事:

1. **`ai_goals` 表是 opt-in 的。** `php artisan migrate` 创建这张表,
   但仅在某处解析 `app(\SuperAgent\Goals\GoalManager::class)` 并调用
   `setActiveGoal()` / `pause()` 等方法时才会写入。不用 goal 原语的
   host 这张表保持空闲,`Dispatcher::dispatch()` 不会自动写。

2. **自定义 `GoalStore` 通过容器重绑替换。** 如果你已经在自家表里
   维护 goal,在 `app(GoalManager::class)` 第一次解析之前覆盖绑定:

    ```php
    // app/Providers/AppServiceProvider.php::register()
    $this->app->bind(
        \SuperAgent\Goals\Contracts\GoalStore::class,
        \App\Goals\MyGoalStore::class,
    );
    ```

    本包内的 `EloquentGoalStore` 是参考实现,不是硬依赖。

3. **`ApprovalGate` 已就绪,但循环由 host 拥有。** 闸门是纯决策函数 ——
   `evaluate($toolName, $arguments, $mode, $toolUseId, $approvedToolUseId)`
   返回 `ApprovalDecision::allow()` / `suggestApproval()` /
   `hardDeny()`。host 在自己的 tool-dispatch 包装层里调用它,先于转发
   到 backend,把 suggestion 渲染到 UI,用户 `/approve` 后把 token
   作为 `$approvedToolUseId` 传回重试。backend 端**没有**强制执行 ——
   接进来只需在你的 runner 里加一层包装。

4. **`/v1/usage` 默认未鉴权。** 路由注册在 `routes/web.php` 的包标准
   前缀下。把外层 route group(或 per-route 中间件)挂在你 host 的
   API 鉴权上 —— `auth:sanctum`、签名 URL、内网 IP 白名单。控制器
   不假设有 session,任何能命中它的调用方都能拿到聚合成本数据。
   注册位置参见 `routes/web.php`。

5. **`cache_hit_rate` 落到每条带非零 cache 切片的行。** 老仪表盘照常
   工作,新代码可以直接读 `metadata.cache_hit_rate` 而不需要重新推导
   分母。区分"无 cache 可用"(键缺失)与"0% 命中率"(键存在,
   值 `0.0`)。也接受 DeepSeek V3 / R1 老 wire 的 `cache_hit_tokens`
   别名 —— 老 host 代码向前兼容。

6. **Backend 加固修复消除一个潜在崩溃。** `SuperAgentBackend::resolveEmbeddingProvider()`
   和 `configBool()` 现在用 try/catch 包裹容器查找。在 ServiceProvider
   启动前就跑 backend 的 host(纯 PHPUnit 测试、自定义 CLI 入口)
   原本会撞 `BindingResolutionException`;现在静默降级到"无 embedder"/
   配置默认值。host 侧无需改动 —— 单纯是不会再崩溃。

完整菜谱(GoalStore 自定义、审批闸门在 host runner 里的接线方式、
workspace plugin manifest 格式 + diff 循环、`/v1/usage` 调用示例
(curl 与 Grafana JSON datasource)、`cache_hit_rate` 仪表盘配方)
见 [docs/advanced-usage.zh-CN.md §22–§26](docs/advanced-usage.zh-CN.md)。

**0.9.2 —— 无迁移；TaskRunner 可靠性波次为 opt-in。** 本版给
`Runner\TaskRunner` 增加运行级 backend fallback:主 backend 输出 quota /
rate-limit 类失败时,可以交给链上的下一个 backend 继续;同时补齐长任务所需的
策略、continuation、观测和渐进发布模式。现有调用保持原来的单 backend 行为,
除非传 `fallback_chain`、配置 `super-ai-core.task_fallback.chain`,或开启自动
fallback。

```bash
composer update forgeomni/superaicore
php artisan vendor:publish --tag=super-ai-core-config --force   # 可选:拾取 task_fallback 默认配置
```

可选 env:

```dotenv
AI_CORE_TASK_FALLBACK_AUTO=false
AI_CORE_TASK_FALLBACK_CHAIN=claude_cli,codex_cli,gemini_cli
AI_CORE_TASK_FALLBACK_CHECK_AVAILABILITY=false
AI_CORE_TASK_FALLBACK_INHERIT_CONTEXT=true
```

升级时建议看六点:

1. **Fallback 是每次运行级别,不是粘性 failover。** 每次仍先尝试调用方请求的
   backend,所以主 backend 恢复后下一次任务会自然切回。

2. **按 workload 维护 fallback 链。** Coding 任务可优先
   `claude_cli → codex_cli → gemini_cli`;research/summarisation 可加入
   `kimi_cli`;直连 HTTP backend 更适合放在最后做 headless 兜底。先用 per-call
   `fallback_chain`,稳定后再提升到全局配置。

3. **只有匹配失败才继续 handoff。** 默认覆盖常见 quota/rate-limit 文案
   (`rate limit`、`usage limit`、`quota`、`429`、`too many requests`、
   `usage_not_included`)。Prompt 校验、tool 失败和其他不匹配错误会停在原
   backend,除非你扩展 `fallback_on`。

4. **先用 TaskRunner fallback,再考虑队列 retry。** 队列 retry 会重跑整个 job;
   fallback 保持同一个逻辑运行继续,并可把失败输出/log 摘要传给下一 backend。
   对长任务来说,这通常是更合适的第一恢复步骤。

5. **宿主可持久化尝试报告。** `TaskResultEnvelope` 新增
   `fallbackReport`,`toArray()` 包含 `fallback_report`。如果宿主存 envelope
   metadata,允许这个新的 nullable key。UI 可渲染 "primary limited,
   continued on codex",并把每次 attempt 链到对应 `log_file`。

6. **用报告做可靠性分析。** 将 `fallback_report[*].backend` 与
   `ai_usage_logs.backend` 关联,识别经常撞 quota 的主 backend 和真正完成工作的
   次级 backend。`auto_chain` 的排序应该由这些证据驱动,而不是猜。

完整 TaskRunner fallback 菜谱见
[docs/advanced-usage.zh-CN.md §27](docs/advanced-usage.zh-CN.md) 和
[docs/task-runner-quickstart.md](docs/task-runner-quickstart.md)。

**0.9.5 —— 无迁移；视图渲染修复。** 修复 `/processes` 与 `/usage`
索引页两处 Blade 属性编码问题。后端、config 和 API 表面均未变动。
若宿主自行覆盖了 `resources/views/processes/index.blade.php` 或
`resources/views/usage/index.blade.php`，重新引入覆盖时应改用新的
`@php($var = …)` + `@json($var)` 块模式 —— 在单引号 HTML 属性内
内联拼装 side-panel payload，会让某些含引号 / `&` 的截图 URL / metadata
渲染出错。纯运行时变更。

**0.9.6 —— 无迁移；SDK 约束升至 `^1.0`。** Squad 多智能体后端 +
六个 SDK 0.9.8 / 1.0.0 配套绑定。每个绑定均为加性且需主动启用 ——
未开 flag、未传新 option、未从容器解析新服务的宿主，0.9.6 之前的
行为完全保留。

```bash
composer update forgeomni/superaicore forgeomni/superagent
php artisan vendor:publish --tag=super-ai-core-config --force   # 可选；用于挑选新的 config 块
# 0.9.6 无 schema 变更，无需 php artisan migrate
```

可选 env（全部带安全默认；包不强制设任何一个）：

```dotenv
# ─── Squad 多智能体（SDK 1.0.0）───
AI_CORE_SQUAD_ENABLED=true
AI_CORE_SQUAD_BACKEND_ENABLED=true
AI_CORE_SQUAD_MAX_COST=0              # 0 表示不设上限
AI_CORE_SQUAD_CHECKPOINT_DIR=         # 默认: storage/app/squad/

# ─── /model auto 路由（SDK 0.9.8）───
AI_CORE_AUTO_MODEL=true
AI_CORE_AUTO_MODEL_PRO=               # null → SDK 默认 (deepseek-v4-pro)
AI_CORE_AUTO_MODEL_FLASH=             # null → SDK 默认 (deepseek-v4-flash)
AI_CORE_AUTO_MODEL_LONG_CTX=32000
AI_CORE_AUTO_MODEL_TOOL_DEPTH=3
AI_CORE_AUTO_MODEL_SCORE_CATALOG=     # 可选 ScoreCatalog JSON 路径

# ─── 缓存感知压缩（SDK 0.9.8）───
AI_CORE_COMPRESSION_CACHE_AWARE=true
AI_CORE_COMPRESSION_PIN_HEAD=4
AI_CORE_COMPRESSION_PIN_SYSTEM=true

# ─── 每 provider token-bucket 限流（SDK 0.9.8）───
AI_CORE_RL_DEFAULT_RATE=8.0
AI_CORE_RL_DEFAULT_BURST=16

# ─── 不可信输入包裹（SDK 0.9.8）───
AI_CORE_UNTRUSTED_INPUT=true

# ─── 子智能体深度上限（SDK 0.9.8）───
AI_CORE_AGENT_MAX_DEPTH=0             # 0 → SDK 默认 (5)

# ─── DeepSeek FIM（SDK 0.9.8）───
DEEPSEEK_API_KEY=
```

升级时值得回顾的八件事：

1. **Squad 由 SDK 可用性决定是否注册。** `BackendRegistry` 仅在
   `super-ai-core.backends.squad.enabled` 开启且 SDK 1.0.0 类
   (`PeerOrchestrator`, `TaskDecomposer`, `ModelTierMap`,
   `SquadCheckpointStore`) 存在时才注册 `SquadBackend`。未升级到
   SDK 1.0.0 的宿主行为完全不变 —— Squad 自报不可用，Dispatcher
   回落到其余九个 adapter。

2. **Squad 流水线按步骤持久化 checkpoint。** 中途失败时
   checkpoint 留在磁盘；重新 dispatch 时传入同样的 `squad_id` 和
   `checkpoint_dir` 即可恢复。默认 `checkpoint_dir` 落在
   `storage/app/squad/`，Laravel 的 storage 权限已经覆盖。按调用
   覆盖：`options.checkpoint_dir`；全局覆盖：
   `AI_CORE_SQUAD_CHECKPOINT_DIR`。

3. **`AutoModelRouter` 是宿主服务，而非后端依赖。** 解析
   `app(\SuperAICore\Services\AutoModelRouter::class)` 并调用
   `select($messages, $systemPrompt, $options)` 返回本次 dispatch
   应使用的 model id。在你自定义的 dispatcher / planner 中接入，
   即可享受 SDK 的启发式而无需绑定 SuperAgent 后端。不解析该服务
   的宿主无任何变化。

4. **`CompressionStrategyFactory` 仅对自管 `ContextManager` 的宿主
   有意义。** 默认 `SuperAgentBackend` 流程是单次（`max_turns=1`），
   根本不构造 `ContextManager`。跑长链子智能体循环或 browser 工具
   会话的宿主，在自管 context manager 时调用
   `app(\SuperAICore\Services\CompressionStrategyFactory::class)->build(…)`；
   工厂返回包了 `CacheAwareCompressor` 的 `ConversationCompressor`，
   summary 边界落在 cache 前缀之后。

5. **`UntrustedInputHelper` 覆盖 SDK 未自动包裹的自由文本。** SDK
   0.9.8 的 `Goals\GoalManager` 已通过 `continuation.md` 模板自动
   包裹 `goal.objective` —— 不要在那里重复包裹。本 helper 用于
   ad-hoc memory 条目、workspace plugin 描述、来自第三方服务器的
   MCP 工具文档，以及任何拼进 system prompt 的宿主 UI 表单输入。
   测试 / dispatch 字节对比时通过 `AI_CORE_UNTRUSTED_INPUT=false`
   关闭。

6. **限流器是 per-process 的。** 分布式 swarm（每 pod 一个 agent）
   需要共享限流器 —— 干净的路径是给 provider HTTP client 挂
   Redis-backed Guzzle 中间件；本 registry 保持简单，与之不冲突。
   默认匹配 SDK 的 per-call 429 重试预算（8 RPS / 16 burst）；按
   provider 覆盖写在 `super-ai-core.rate_limits.<provider>`。

7. **`reasoning_effort` 是 `Dispatcher::dispatch()` 的按调用 option。**
   三档（`off` / `high` / `max`）。按 upstream 路由到正确的 body
   shape（多数 provider 用顶层 `reasoning_effort`，NVIDIA NIM 走
   `chat_template_kwargs` 等）。不实现 `SupportsReasoningEffort`
   的 provider 静默忽略。设为 `max` 时还会喂给 `AutoModelRouter`
   的升级启发式。

8. **`smart` 和 `squad` 控制台命令。** 都是对 vendor `superagent`
   binary（`vendor/forgeomni/superagent/bin/superagent`）的透传。
   复用 operator 现有的 SuperAgent 凭据和 SDK CLI 行为，而不是
   在 PHP 里重写编排：
   ```bash
   ./vendor/bin/superaicore smart "审计这个 diff"
   ./vendor/bin/superaicore smart show --last
   ./vendor/bin/superaicore squad "重构 auth 模块" --max-cost=2.0
   ./vendor/bin/superaicore squad --no-squad "对比 legacy 路径"
   ```
   SDK 安装在 `vendor/` 之外时，传
   `--binary=/abs/path/to/superagent`。

完整菜谱（Squad 流水线、AutoModelRouter 接入、CacheAwareCompressor
布线、RateLimiterRegistry 覆盖、AdHocMemoryRegistry 聊天 UI 接入、
ConversationForkService 侧边面板、DeepSeek FIM 补全端点）见
[docs/advanced-usage.zh-CN.md §28](docs/advanced-usage.zh-CN.md)。

**0.9.7 —— 4 个新迁移；SDK 约束升至 `^1.0.5`。** SDK 1.0.5 能力包
（跨 provider handoff transcoder 修复、opencode `BashArity` 权限匹配、
opencode 7 段结构化摘要、SDK 自带真实 LSP 客户端 + `LSPTool`、
`LlmLoopChecker` 语义循环检测、ACP v1 stdio 服务器、带 thinking +
grounding + thought-parts 的 Gemini 3.5 / 3.x）+ 10 个 opencode 借鉴
特性（逐文件 diff + revert、运行中 HITL 问询工具、snapshot 保留策略、
会话提醒、按 agent 权限规则集、子 agent 权限推导、plan mode、PTY 长
连接 shell、会话分享主机队列）。所有 binding 均为 additive + opt-in；
未启用对应 flag 的宿主，0.9.7 之前行为保持不变。

```bash
composer update forgeomni/superaicore forgeomni/superagent
php artisan vendor:publish --tag=super-ai-core-migrations
php artisan migrate
php artisan vendor:publish --tag=super-ai-core-config --force   # 可选；获取新 config 块
```

4 个迁移均为 additive + 可回滚：

- `2026_05_20_000001_add_diff_summary_and_snapshots_to_ai_usage_logs.php`
  —— `ai_usage_logs` 新增 `pre_snapshot`（varchar 64，可空）、
  `post_snapshot`（varchar 64，可空）、`file_diff_summary`（json，
  可空）。旧行为 NULL；通过 `SuperAgentBackend` 的新调用会自动填充。
- `2026_05_20_000002_create_ai_user_questions_table.php` —— 新表，
  支撑 `ask_user` HITL 工具。
- `2026_05_20_000003_create_ai_pty_sessions_table.php` —— 新表，
  支撑 PTY 长轮询端点。
- `2026_05_20_000004_create_ai_session_shares_table.php` —— 新表，
  支撑会话分享主机队列。

可选环境开关（默认全部安全 / 关闭）：

```dotenv
# ─── Shadow-git 快照 + 逐文件 diff 摘要 ───
AI_CORE_SNAPSHOT_ENABLED=true
AI_CORE_SNAPSHOT_PROJECT_ROOT=          # null → base_path() → getcwd()
AI_CORE_SNAPSHOT_RETENTION_DAYS=7
AI_CORE_SNAPSHOT_REVERT_ENABLED=true    # POST /usage/{id}/revert

# ─── 运行中 HITL `ask_user` 工具 ───
AI_CORE_TOOLS_ASK_USER=false            # 默认关，需要时打开

# ─── SDK 1.0.5 LSP 工具 ───
AI_CORE_TOOLS_LSP=false                 # 默认关；打开后 lsp 工具会被加入 load_tools

# ─── Opencode 结构化压缩摘要 ───
AI_CORE_COMPRESSION_SUMMARY_PROMPT=     # 设为 "structured" 全局启用

# ─── CLI plan mode（Modes\CliPlanOrchestrator）───
AI_CORE_PLAN_ENABLED=true
AI_CORE_PLAN_BACKEND=cli:claude_cli
AI_CORE_PLAN_BUILD_BACKEND=cli:claude_cli
AI_CORE_PLAN_DIR=.superagent/plans
AI_CORE_PLAN_AUTO_APPROVE=              # null → 自动检测（HITL 开 = 等审批，关 = 自动通过）
AI_CORE_PLAN_APPROVAL_TIMEOUT=600

# ─── PTY 长连接 shell ───
AI_CORE_PTY_ENABLED=false               # 默认关；按部署需要打开

# ─── 会话分享主机队列 ───
AI_CORE_SHARE_ENABLED=false
AI_CORE_SHARE_REMOTE_URL=               # 远端 sharer 基础 URL（POST /api/shares/{id}）
AI_CORE_SHARE_SECRET=                   # 发给远端的 Bearer token
AI_CORE_SHARE_LOCAL_URL_TEMPLATE=       # 无远端时的本地 fallback；含 {share_id} 占位
```

按 agent 权限规则集、会话提醒、snapshot prune 调度都放在
`super-ai-core.php`：

```php
// config/super-ai-core.php

'agents' => [
    'plan' => [
        'permission' => [
            '*'    => 'allow',
            'edit' => ['*' => 'deny', '*.md' => 'allow'],
            'write'=> ['*' => 'deny', '*.md' => 'allow'],
        ],
    ],
    'explore' => [
        'permission' => [
            '*'     => 'deny',
            'read'  => 'allow',
            'grep'  => 'allow',
            'glob'  => 'allow',
            'bash'  => 'allow',
        ],
    ],
],

'reminders' => [
    'rules' => [
        [
            'name' => 'plan-mode-active',
            'when' => ['agent' => 'plan'],
            'text' => "## Plan 模式已激活\n请把计划写到 `.superagent/plans/{session}.md`，不要对工作区文件调用任何 edit/write 工具。",
        ],
    ],
],
```

在 `app/Console/Kernel.php` 排程 snapshot 清理：

```php
protected function schedule(Schedule $schedule)
{
    $schedule->command('super-ai-core:snapshot-prune')->dailyAt('02:00');
}
```

升级时值得过一遍的 11 件事：

1. **逐文件 diff 摘要自动启动。** 只要 SDK 1.0.5 的 `GitShadowStore`
   能为工作区打快照，每次 `SuperAgentBackend::generate()` 都会把
   `pre_snapshot` / `post_snapshot` / `file_diff_summary` 写到
   UsageLog 行；`/usage` 上每行渲染 `+N −M` 徽章。要保持 0.9.7 之前
   完全 byte-identical 行为可设 `AI_CORE_SNAPSHOT_ENABLED=false`。
2. **HITL `ask_user` 工具默认关。** `AskUserTool::execute()` 的轮询
   语义会阻塞 agent 最多 `timeout_seconds`（默认 600），适合人在
   `/processes` 前面回答的场景，不适合需要回收的 queue worker。只有
   当人会盯着 `/processes` 答题时才开 `AI_CORE_TOOLS_ASK_USER=true`。
3. **`/processes/questions` 轮询节奏。** UI 每 4 秒拉一次，服务端
   `AskUserTool` 每 500ms 查一次 DB。并发问询很高时主要成本在服务端
   DB 查询，不在浏览器扇出。
4. **Revert 是写操作 —— 按写操作鉴权。** 路由由
   `AI_CORE_SNAPSHOT_REVERT_ENABLED`（默认开）和
   `super-ai-core.route.middleware` 中间件链双重控制。多租户部署在
   `/usage/{id}/revert` 前再加授权中间件。
5. **`super-ai-core:snapshot-prune` 是 per-host 的。** 它遍历执行用户
   `~/.superagent/history/`。多用户机上请按用户分别排程，或者通过
   `SUPERAGENT_HISTORY_DIR=/var/lib/superagent/history` 把 shadow 目录
   归并到一处。
6. **按 agent 权限规则集只在调用方未传 `allowed_tools` /
   `denied_tools` 时生效。** 显式 per-call 列表覆盖 config-driven
   ruleset。这是有意为之 —— 上层（PPT / SuperTeam / codex）已经各自
   算好了 deny 列表，不应被静默覆盖。
7. **Plan mode 在 `CliModeRouter` 下以 mode 名 `plan` 注册。** 用
   `app(CliModeRouter::class)->dispatch('plan', $task, $ctx)` 调度。
   HITL 关时编排器自动批准（为 CI 留口子）。要强制等待审批设
   `AI_CORE_PLAN_AUTO_APPROVE=false`。
8. **子 agent 权限推导读两个信号。** 要么在子 dispatch options 显式
   传 `parent_denied_tools`，要么传 `metadata.parent_agent` 让
   `PermissionEvaluator` 自己解析父的规则集。deny 集在父 → 子链路上
   单调增长 —— 子 agent 永不能 elevate。
9. **PTY 是 Phase 1（长轮询，无 stdin）。**
   `/pty/sessions/{id}/write` 返回 501，因为 PHP 没法在 HTTP 请求间
   保持 pipe 存活（除非常驻 worker）。需要输入时客户端用 `expect`
   风格命令，或等 Phase 2（Laravel Reverb / Soketi 的 WebSocket）。
10. **会话分享有两种模式。** REMOTE（`AI_CORE_SHARE_REMOTE_URL` 已配）
    Bearer POST 到外部 sharer；LOCAL（`AI_CORE_SHARE_LOCAL_URL_TEMPLATE`
    已配）把 URL 渲染到宿主自身的 SuperAICore —— 内网"把会话分享给
    同事"通常用本地模式。
11. **SDK 1.0.5 Gemini 3.5 特性直通。** `thinking` / `grounding` /
    `google_search` / `url_context` per-call 选项透传给
    `Agent::run($prompt, $options)`，非 Gemini provider 静默忽略。
    `EngineCatalog` 为 gemini-cli 引擎列出 `gemini-3.5-pro / -flash /
    -flash-lite`。

完整菜谱（逐文件 diff 看板、AskUserTool 接入、revert 按钮、plan
mode 工作流、按 agent 权限规则集、子 agent 权限继承、PTY 长轮询
接入、会话分享主机队列、snapshot 保留调度）见
[docs/advanced-usage.zh-CN.md §29](docs/advanced-usage.zh-CN.md)。

**1.0.0 —— 首个稳定版；无迁移；SDK 约束升至 `^1.0.9`。**
全面增量 —— 无 schema 变更，无需 publish 配置。公开 API 现已按
SemVer 稳定（见 `docs/api-stability.md`）。四件值得知道的事：

1. **Claude Opus 4.8 成为新旗舰。** SDK 1.0.9 把
   `claude-opus-4-8` 提为旗舰（接管 `opus` 别名；原生 1M 上下文、
   交错 thinking、fast 模式、effort 控制）。`ClaudeModelResolver`、
   `claude` 引擎目录、`model_pricing`，以及 `squad` / `cli_squad` 的
   **expert** 档现在都指向 4.8。显式锁了更老 Opus id 的宿主照常工作 ——
   旧 id 仍保留在目录里。

2. **xAI Grok 走两条通道。** (a) 按量计费的 **API** provider type
   `grok` 经 `superagent` 后端路由（`XAI_API_KEY` / `GROK_API_KEY`，
   默认 `grok-4.3`）。(b) **订阅制 CLI** 引擎 `grok_cli`（二进制
   `grok`，grok.com 登录）是另一条独立通道。两者只共享品牌，别无其他。

3. **两个新的订阅制 CLI 引擎。** `cursor_cli`（Cursor Composer，
   `cursor-agent`）和 `grok_cli`（Grok Build）。均为 `builtin` 登录、
   订阅计费（usage 行记 $0，shadow cost 取自目录）。默认启用；通过
   `AI_CORE_CURSOR_CLI_ENABLED=false` / `AI_CORE_GROK_CLI_ENABLED=false`
   关闭。它们会自动出现在 `/providers`、`cli:status`、模型选择器、
   成本看板和进程监控里。

4. **无需回退任何东西。** 1.0.0 之前的调用方行为字节级一致；锁版本的
   宿主把 SDK 降回 1.0.7 也仍然可用。

Cursor / Grok CLI 上手菜谱、Opus 4.8 路由，以及 Grok API 与 CLI
通道拆分见 [docs/advanced-usage.zh-CN.md §30](docs/advanced-usage.zh-CN.md)。

**1.0.2 —— kimi-cli → kimi-code 过渡；无迁移；SDK 约束升至 `^1.0.10`。**
全面增量 —— 无 schema 变更，无需 publish 配置。两件值得知道的事：

1. **`kimi_cli` 后端现在同时支持两种 kimi CLI。** Moonshot 的新
   `@moonshot-ai/kimi-code`（TypeScript）取代旧的 Python
   `MoonshotAI/kimi-cli`；两者发布同一个 `kimi` binary，但 headless 接口与
   stream-json 形状不兼容。`KimiCliBackend` 自动判别装的是哪一种（按 binary
   缓存的 `kimi --help` 探测 —— legacy 有 `--print` flag，kimi-code 没有），并在
   全部四条 spawn 路径上适配 argv + 解析。想在过渡期跳过探测，用
   `AI_CORE_KIMI_CLI_VARIANT`（默认 `auto` / `kimi-code` / `kimi-cli`）固定
   dialect。`kimi_cli` Dispatcher backend id、`/providers` 卡片、模型选择器均不变。
   （kimi-code `.agents/` 模型的 agent-sync 是已记录的后续；`KimiAgentSync` 目前仍
   按 legacy `~/.kimi/agents/` 布局写文件。）

2. **SDK 1.0.10 加固 Kimi HTTP 路径 —— 且透明。** 约束从 `^1.0.9` 升到
   `^1.0.10`。直连 HTTP 的 `kimi` / `qwen` / `glm` / `deepseek` / `grok` /
   `openrouter` / `openai` provider type（经 `superagent` 后端路由）拿回流式
   `usage` 计量（`stream_options.include_usage` —— 流式调用不再记 $0）、严格的
   工具 schema 归一化、Kimi 推理模型改用 `max_completion_tokens`、按模型的能力
   发现。新增 opt-in `SUPERAGENT_KIMI_SWARM_ENABLED` 开关。无需回退 —— 1.0.2 之前
   的调用方行为一致。

变体探测菜谱与 kimi-cli/kimi-code flag 对照见
[docs/advanced-usage.zh-CN.md §31](docs/advanced-usage.zh-CN.md) 与
`docs/kimi-cli-backend.md` §8。

**1.0.5 —— SmartFlow 跨 CLI 工作流；无迁移；SDK 约束移到 `^1.1.0`。**
全面增量 —— 无 schema 变更；只有在你定制配置时才需要 publish
（`php artisan vendor:publish --tag=super-ai-core-config`）。三件值得知道的事：

1. **新 `flow` 命令 —— 跨 CLI 的动态工作流。** SuperAICore 把
   Claude Code 内置的 `Workflow` 移植为 **SmartFlow**（`src/SmartFlow/`）:一套
   原语（`agent` / `parallel` / `pipeline` / `gate` / `council` /
   `budget` / `schema`）驱动任意已注册的 backend,所以一条 flow 可以在
   `claude_cli` 上规划,同时在 `codex_cli` + `gemini_cli` 上并发评审。四条
   内置 flow 随 `resources/flows/*.yaml` 发布;在没装任何 CLI 的情况下也能
   零成本彩排其中任意一条:
   ```bash
   ./vendor/bin/superaicore flow list
   ./vendor/bin/superaicore flow run cross-cli-review --args diff=@my.diff --rehearse
   ./vendor/bin/superaicore flow run cross-cli-dev --args goal="add caching" --concurrency 4
   ```
   也挂在 artisan 上为 `php artisan flow ...`。每次运行的账本位于
   `~/.superaicore/flows`（用 `SUPERAICORE_FLOW_DIR` 覆盖）;`--resume <id>`
   以零成本重放未变更的前缀。新配置块
   `super-ai-core.smartflow.*`（`default_backend`、`concurrency`、`ledger_dir`、
   `flows_dir`、`budget`、`personas`）+ `AI_CORE_SMARTFLOW_*` env。

2. **与 superagent 联邦。** 一条 flow 可以把子 flow 委派给
   superagent 自己的（跨模型）SmartFlow —— `Flow::delegate()` 或 YAML 中的
   `strategy: delegate`。**named** 模式运行 superagent 自己的某条 flow
   （由它在各模型 provider 间自行分发）;**spec** 模式运行一条结构由
   SuperAICore 编写的 flow（superagent 按照本项目的指示分发）。
   需要 SDK 在 classpath 上（现在已经是了,pin `^1.1.0`）;SDK 缺失或
   flow 未知时优雅失败,不会拖垮父 flow。
   ```bash
   ./vendor/bin/superaicore flow run cross-cli-federated \
       --args goal="add caching" --args research_provider=openai --rehearse
   ```

3. **SDK 1.1.0 带来它自己的（跨模型）SmartFlow —— 透明地。** pin
   从 `^1.0.10` → `^1.1.0`;`superagent` 后端顺带拿到 SDK 的
   SmartFlow 以及 1.0.10→1.1.0 的 wire 级加固。除了可选的联邦桥,SuperAICore
   没有任何代码依赖 SDK 的 SmartFlow 类。无需回退 —— 1.0.5 之前的调用方行为一致。

完整的 SmartFlow 指南（原语、YAML 编写、结构化输出阶梯、resume,以及
superagent 联邦菜谱）见 [docs/advanced-usage.zh-CN.md §32](docs/advanced-usage.zh-CN.md)
与 [docs/smartflow.md](docs/smartflow.md)。

**1.0.10 —— GLM-5.2 原生旗舰;无迁移;SDK pin 移到 `^1.1.2`。** 全面纯增量
—— 无 schema 变更;仅当你想要刷新后的 `model_pricing` 表时才需发布配置
（`php artisan vendor:publish --tag=super-ai-core-config`）。两点值得了解:

1. **GLM-5.2 是新的 `glm` 默认值。** SDK 1.1.2 把 `glm-5.2`（Z.ai 编码优先的
   智能体旗舰:1M 上下文、128K 最大输出、纯文本）提升为原生 `glm` 旗舰,并新增
   `glm-5.1`（200K 上下文）。SuperAICore 把 Z.ai 官方价镜像进 `model_pricing`
   表 —— `glm-5.2` / `glm-5.1` 为 **$1.40 入 / $4.40 出** 每 1M,并附 **$0.26
   cache-hit** 入档位,`glm-5` 为 $1.00 / $3.20 —— 并把 `glm-5.2` 种入
   `superagent` 引擎的 `available_models`,使其离线时也出现在选择器里。
   `CostCalculator` 本就回退到 SDK 的 `ModelCatalog`,故未列出的 GLM SKU 仍可
   解析;显式行只是让看板在无需目录往返时保持准确。裸 `glm` 简写与零配置默认值
   现在解析到 GLM-5.2;`glm-5` / `glm-4.x` 仍可按 id 访问。

2. **`GlmProvider` 获得 `reasoning_effort` 档位 —— 透传式。** SDK 1.1.2 让
   `GlmProvider` 实现 `SupportsReasoningEffort`（与 MiniMax M3 并列）,因此现有
   的逐调用选项已直接路由到它:

   ```php
   $dispatcher->dispatch([
       'backend'          => 'superagent',
       'prompt'           => '把这个模块重构得更易测试。',
       'provider_config'  => ['provider' => 'glm'],   // → glm-5.2
       'reasoning_effort' => 'max',   // off | high | max（off ⇒ 关闭思考）
   ]);
   ```

   无需改调用点 —— `SuperAgentBackend` 本就通用转发 `reasoning_effort` /
   `thinking`,所以 SDK 一落地档位即可用。无需回退 —— 1.0.10 之前的调用方行为
   一致。

各 provider 的 wire 形态见 [docs/advanced-usage.zh-CN.md §28](docs/advanced-usage.zh-CN.md)
（`reasoning_effort` 三档拨盘）。

**1.0.11 —— Fable 5 与 Sonnet 5;无迁移;SDK pin 移到 `^1.1.5`。** 全面纯增量
—— 无 schema 变更;仅当你想要刷新后的 `model_pricing` 表时才需发布配置
（`php artisan vendor:publish --tag=super-ai-core-config`）。三点值得了解:

1. **Fable 5 与 Sonnet 5 成为原生 `anthropic` 模型。** SDK 1.1.5 新增
   `claude-fable-5`（Anthropic 最强模型:1M 上下文、128K 最大输出、常驻
   自适应思考、effort 档位）与 `claude-sonnet-5`（新的 `sonnet` 旗舰,同属
   Claude 5 代自适应请求面）。SuperAICore 把官方价镜像进 `model_pricing` ——
   Fable 5 **$10 入 / $50 出** 每 1M,Sonnet 5 **$3 / $15**（2026-08-31 前有
   $2/$10 的首发价;表内保留官方价）—— 并把两个 id 种入 `superagent` 引擎的
   `available_models`,使其离线时也出现在选择器里。SDK 侧 `sonnet` 别名现在
   解析到 Sonnet 5;先前所有 Claude id 仍可访问。

2. **Opus 系降价 3 倍 —— 看板需要新价格表。** SDK 1.1.5 修正过期定价:现役
   Opus（`claude-opus-4-5`→`4-8`）为 **$5/$25** 每 1M（原 $15/$75）;Haiku 4.5
   为 $1/$5。SuperAICore 的 `model_pricing` 同步跟进（仅带日期的
   `claude-opus-4-20250514` 快照保留历史价 $15/$75）。若宿主早前发布过旧配置
   副本,请重新发布或手动修改 —— 否则 `CostCalculator` 会继续按旧价计费。
   SDK 侧零配置 `anthropic` 现在解析到 `claude-opus-4-8`;SDK Squad 的 EXPERT
   档路由到 `claude-fable-5`,而 SuperAICore 自己的 `squad.tiers` 配置保持不变
   （想要 SDK 的分档,自行把 `expert` 指向 `claude-fable-5`）。

3. **`reasoning_effort` 档位现在也能触达 Anthropic 模型 —— 透传式。**
   SDK 1.1.5 让 `AnthropicProvider` 实现 `SupportsReasoningEffort`,把现有的
   逐调用选项映射到 Anthropic GA 的 `output_config.effort`（Fable 5 /
   Sonnet 5 / Opus 4.5+ / Sonnet 4.6;不支持的模型绝不 400）:

   ```php
   $dispatcher->dispatch([
       'backend'          => 'superagent',
       'prompt'           => '审计这个迁移的竞态条件。',
       'provider_config'  => ['provider' => 'anthropic', 'model' => 'claude-fable-5'],
       'reasoning_effort' => 'max',   // off | low…high | max
   ]);
   ```

   无需改调用点 —— `SuperAgentBackend` 本就通用转发 `reasoning_effort` /
   `thinking`。SDK 还会替你处理 Claude 5 代仅自适应的请求面（不发
   `budget_tokens`、不发采样参数、不发尾部 prefill）,顺带修复了 Opus 4.7/4.8
   上的潜在 400。

Fable 5 与 Sonnet 5 的自适应请求面与 Anthropic effort 档位详见
[docs/advanced-usage.zh-CN.md §34](docs/advanced-usage.zh-CN.md)。

**1.1.0 —— ai-dispatch 对齐波次；无迁移；SDK pin 不变。** 纯增量：新增
standalone + artisan 命令 `send`、`resume`、`runs`、`aliases`、
`preferences`、`doctor`、`skill:install-dispatch`，以及新的 `dispatch`
配置块（`aliases` / `retry_on_classes` / `runs_path` / `preferences_path`
—— 重新发布 config 才能看到，或直接用 `AI_CORE_RUNS_PATH` /
`AI_CORE_PREFERENCES_PATH` 环境变量）。Claude 模型表跟上 Claude 5 代
（新增 `fable` 家族；`sonnet` 现指向 `claude-sonnet-5`）。standalone 控制台
的容器安全加固（`Support\ConfigValue`）修复了 dev checkout 下
`bin/superaicore` 的崩溃。详见
[docs/ai-dispatch-parity.md](docs/ai-dispatch-parity.md) 与
[docs/advanced-usage.zh-CN.md §35](docs/advanced-usage.zh-CN.md)。

**1.1.5 —— 派发 SKILL 全覆盖；无迁移；SDK pin 不变。** 纯增量：
`skill:install-dispatch` 新增 Grok / Cursor / Qwen 目标
（`~/.grok/skills`、`~/.cursor/skills-cursor`、`~/.qwen/skills`），
`--agent all` 一次覆盖全部六个 Agent，默认仍只装 claude；新增
`--uninstall` 可撤销此前的安装且不会碰你自己编写的技能。无需任何配置，
也不用重新发布 config。

**1.1.6 —— GPT-5.6 + Grok 4.5 目录刷新；无迁移；SDK pin 移到 `^1.1.6`。**
全面纯增量 —— 无 schema 变更；只有想要刷新后的 `model_pricing` 表时才需要
重新发布 config。三件值得了解的事：

1. **SDK 零配置默认迁移。** `openai-responses` 现解析为 `gpt-5.6-sol`
   （原 `gpt-5`）、`grok` 解析为 `grok-4.5`（原 `grok-4.3`；注意新默认的
   上下文窗口从 1M 缩到 500K）、`gemini` 解析为 `gemini-3.5-flash`
   （2.0 系已于 2026-06-01 上游退役）。所有已上线过的 id 都仍可显式
   `model` 配置调用；如依赖 grok-4.3 的 1M 窗口请固定旧 id。

2. **价格表全线修正 —— 看板需要新副本。** 新增行：GPT-5.6
   Sol/Terra/Luna（$5/$30、$2.50/$15、$1/$6，含缓存输入档）、`grok-4.5`
   $2/$6、`gemini-3.5-flash` $1.50/$9、`gemini-3.1-pro-preview` $2/$12、
   `gemini-3.1-flash-lite` $0.25/$1.50、`kimi-k2.7-code` $0.95/$4、
   `glm-5-turbo`/`glm-5v-turbo` $1.20/$4。修正项：`gpt-5` $5/$15 →
   **$1.25/$10**、`deepseek-v4-flash` 输出 $0.55 → **$0.28**、
   `MiniMax-M3` $0.60/$2.40 → **$0.30/$1.20**、`qwen3.7-plus`
   $0.80/$2.40 → **$0.40/$1.60**。若宿主发布过旧 config 副本，请重新发布
   或手改 —— 否则 `CostCalculator` 会继续用过期价格。另注意
   `gemini-3.5-pro` / `gemini-3.5-flash-lite` 从未上线，已从选择器移除。

3. **GPT-5.6 / Gemini 3.5 请求面走既有 dispatch 选项。**
   `SuperAgentBackend` 现在转发 `reasoning_mode`（`standard`|`pro`）、
   `reasoning_context`（`auto`|`all_turns`|`current_turn`）、
   `prompt_cache_options` 与 `thinking_level`（`minimal`…`high`）：

   ```php
   $dispatcher->dispatch([
       'backend'          => 'superagent',
       'prompt'           => '证明这个调度器不会饿死任务。',
       'provider_config'  => ['provider' => 'openai-responses'],  // → gpt-5.6-sol
       'reasoning_effort' => 'max',      // GPT-5.6 新增 none/max
       'reasoning_mode'   => 'pro',      // Sol Pro
   ]);
   ```

   四个选项在不支持的 provider 上都被静默忽略；既有的 `reasoning_effort`
   档位由 SDK 按模型代次归一化 —— 传错值也不会 400。

**1.1.7 —— Kimi K3；无迁移；SDK pin 移到 `^1.1.7`。** 纯增量 —— 无 schema
变更；只有想要新的 `model_pricing` 行时才需要重新发布 config。两件值得了解的事：

1. **Kimi K3 已定价，且是新的零配置 Kimi 默认。** `kimi-k3`（Moonshot 新的
   2.8T 开源权重通用旗舰、1M 上下文、常开思考）在 SDK 侧解析为 `kimi` 原生
   默认，价格为官方 **输入 $3 / 缓存 $0.30 / 输出 $15**（每 1M）。该行已种入
   `model_pricing`，`CostCalculator` 可离线计价 —— 若宿主带着旧副本，请重新
   发布 config。编码向的 `kimi-k2.7-code` 保持不变，较旧的 `kimi-k2-6` 仍可
   通过显式 `model` 配置调用。订阅制 `kimi` CLI 引擎（kimi-code OAuth，
   $0/token）未受影响。

2. **`superaicore --version` 现在报告真实版本。** 它此前卡在 `1.1.5`
   （1.1.6 发布时漏改），现在正确读取为 `1.1.7`。

**1.1.8 —— Kimi Code 0.27 支持刷新；无迁移；SDK pin 不变。** 纯运行时行为
—— 无 schema、无新 config 键。kimi-code 把状态目录从 `~/.kimi/` 搬到了
`$KIMI_CODE_HOME`（默认 `~/.kimi-code/`）；所有 Kimi 支持面现在自动探测目录
布局。升级时三件值得了解的事：

1. **Kimi 登录状态变得真实。** 如果此前 `doctor` / providers UI 说你的
   kimi-code 未登录、但 `kimi` 本身用得好好的，那是旧的 `~/.kimi/credentials/`
   探针所致 —— 已修复，无需任何操作。`$KIMI_CODE_HOME/credentials/` 下的凭证
   会被识别。

2. **升级后重跑一次 MCP 同步。** 此前版本把 Kimi 的 MCP 配置写到
   `~/.kimi/mcp.json`，而 kimi-code 根本不读它。升级后运行一次
   `php artisan claude:mcp-sync`（或你惯用的同步入口），让服务器落到
   `~/.kimi-code/mcp.json`。残留的 `~/.kimi/mcp.json` 无害，只有 legacy
   Python kimi-cli 装机才会读。

3. **`cli:install kimi` 现在装的是 kimi-code** —— 走 Moonshot 官方安装脚本
   （二进制在 `~/.kimi-code/bin`，非 login shell 也能自动探到）。如确需已
   退役的 Python CLI，用 `--via=uv` 或 `--via=pip` 显式指定。

## 常见问题

- **`Class 'SuperAgent\Agent' not found`** —— 你移除了 `forgeomni/superagent`，但仍保留 `AI_CORE_SUPERAGENT_ENABLED=true`。设为 `false` 或重新安装 SDK。
- **CLI 后端不可用** —— 执行 `which claude` / `which codex`。若为空，安装对应 CLI，或在 `CLAUDE_CLI_BIN` / `CODEX_CLI_BIN` 中填写绝对路径。
- **`ai_usage_logs` 没有记录** —— 检查 `AI_CORE_USAGE_TRACKING=true` 且迁移已执行。
- **`vendor:publish` 提示不明确** —— 显式传入上面列表中的 `--tag`。
