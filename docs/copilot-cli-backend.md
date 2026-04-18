# Design: `copilot_cli` Backend

**状态**：MVP-1 + MVP-2 已实现（基线 0.5.6，未 bump 版本）
**日期**：2026-04-18 / MVP-2 完成 2026-04-18
**目标版本**：当前 0.5.6（按用户偏好不自动 bump）

---

## 1. 背景与决策

GitHub Copilot CLI（`github/copilot-cli`）于 2026-02 GA，是一个 agent 形态的终端 AI 助手，能力接近 Claude Code CLI / Codex CLI。SuperAICore 已有 `claude_cli` / `codex_cli` / `gemini_cli` 三个 CLI 引擎，新增 `copilot_cli` 与之平级。

### 路径选择

曾考虑在 `forgeomni/superagent` SDK 层通过 HTTP + OAuth 令牌交换接入 Copilot，最终决定放在 SuperAICore 做 **CLI backend**。原因：

- Copilot 的 OAuth → session token 刷新、keychain 存储、设备流交互全都由官方 `copilot` CLI 封装好，CLI 路线让这些复杂性留在 GitHub 二进制里
- CLI 路线可以直接复用现有 skill / agent runner 的 preamble、translator、side-effect locking 机制
- ToS 边界更清晰：`copilot` CLI 是官方支持程序化调用的入口（`-p` flag 明示）

**不做的事**：不在 `SuperAgentBackend` 的 provider 列表加 `github-copilot`；不实现 HTTP API path；不实现独立的 OAuth 交换。

---

## 2. Copilot CLI 能力清单（调研结果）

### 2.1 非交互调用

```bash
copilot -p "<prompt>"                 # headless 执行一次
copilot -p "<prompt>" -s              # 抑制 stats/装饰，仅输出 response —— MVP 默认
copilot -p "<prompt>" --allow-all-tools   # 等价 autopilot，不做逐工具确认
```

配合 `--model=<model>` 固定模型，`--allow-tool=` / `--deny-tool=` 精细控权。

### 2.2 模型路由

`--model=<model>` 启动时选，`/model <model>` 会话内切。支持模型（截至 2026-04）：

- Claude Sonnet 4.5（默认）、Claude Opus 4.5、Claude Haiku 4.5、Claude Sonnet 4
- GPT-5、GPT-5.1、GPT-5.1-Codex、GPT-5.1-Codex-Mini、GPT-5-Mini、GPT-4.1
- Gemini 3 Pro（Preview）

**含义**：Copilot CLI 本身就是个 multi-model router。SuperAICore 把它当成单个 backend，模型选择透传到 `--model`。

### 2.3 Tool 权限模型

Tool 分类：`shell` / `write` / `read` / `url` / `memory` / `MCP-SERVER`，均支持 glob 过滤。

例：`--allow-tool="shell(git:*),write(README.md),url(github.com)"`

粒度比 Claude Code 的 permission mode 更细，translator 比较好写。

### 2.4 认证与 Token

- **交互**：`copilot login`（OAuth device flow）
- **CI / headless**：环境变量 `COPILOT_GITHUB_TOKEN` / `GH_TOKEN` / `GITHUB_TOKEN`（CLI 自动挑选）
- **存储**：优先 keychain（macOS Keychain / Windows Credential Manager / Linux libsecret）；无 keychain 时降级写 `~/.copilot/` 明文，CLI 会在登录时提示
- **配置目录**：`~/.copilot/`（`config.json`、`mcp-config.json`、`command-history-state.json`、`session-state/`）
- **覆盖**：`XDG_CONFIG_HOME` 生效，则改为 `$XDG_CONFIG_HOME/copilot/`

### 2.5 输出格式

- 默认人类可读文本
- `-s` 仅输出 response 正文
- `--output-format=json` 宣称给 JSONL（每行一个 event，含 tool use / assistant message），**但官方 programmatic-reference 文档页未正式列出该 flag**。MVP-1 按纯文本处理；MVP-2 前需本机实测确认。

### 2.6 Skills

**Copilot CLI 原生读取 Claude 格式 skills**。扫描目录：

- Project-level：`.github/skills/` / `.claude/skills/` / `.agents/skills/`
- User-level：`~/.copilot/skills/` / `~/.claude/skills/` / `~/.agents/skills/`

SKILL.md front-matter 兼容字段：`name`（必填，小写 + 连字符）、`description`（必填）、`license`（可选）、`allowed-tools`（可选）。

**含义**：SuperAICore 现有的 `.claude/skills/**/SKILL.md` 发现逻辑、用户安装的 plugin skills，Copilot 全部能直接读。skill 执行走**零翻译透传**。

### 2.7 Custom Agents

Copilot 的 custom agent 格式与 Claude **不兼容**：

| 维度 | Claude Code | Copilot CLI |
|---|---|---|
| 路径 | `.claude/agents/<name>.md` | `.github/agents/<name>.agent.md` 或 `~/.copilot/agents/<name>.agent.md` |
| 扩展名 | `.md` | `.agent.md` |
| Front-matter | `name`, `description`, `tools`, `model` | `name`, `description`, `instructions`, `tools` |
| 调用 | 内置 Task 工具 | `copilot --agent <name> -p "..."` 或 `/agent` 交互 |

**含义**：需要 translator + `copilot:sync` 命令（对称于现有 `gemini:sync`）。

### 2.8 MCP

一等公民。配置文件 `~/.copilot/mcp-config.json`，交互命令 `/mcp add` / `/mcp show`，内建 GitHub MCP。支持 STDIO / HTTP / SSE 三种传输。

### 2.9 其他

- **Sub-agents / 并行**：`/fleet` 原生支持多子代理并行跑同一任务
- **Hooks**：官方 hook 系统
- **Install**：npm / Homebrew / WinGet / shell 脚本 / 单文件可执行

---

## 3. 对 SuperAICore 现有架构的影响

### 3.1 新增文件

```
src/Backends/CopilotCliBackend.php         # 新 backend 类
src/Sync/CopilotAgentSync.php              # agents 翻译 & 写文件
src/Sync/CopilotMcpSync.php                # MCP 配置同步
src/Console/CopilotSyncCommand.php         # copilot:sync CLI 入口
src/Translator/CopilotToolPermissions.php  # Claude tools → --allow-tool 表达
```

### 3.2 修改点

| 文件 | 修改 |
|---|---|
| `src/SuperAICoreServiceProvider.php` | 注册 `CopilotCliBackend` 到 Dispatcher |
| `src/Registry/Backends.php`（或等价） | `list-backends` 显示 `copilot_cli` |
| `config/super-ai-core.php` | 新段 `backends.copilot_cli`：`enabled` / `binary_path` / `default_model` / `timeout` / `allow_all_tools` |
| `.env.example` / INSTALL.md | 加 `AI_CORE_COPILOT_CLI_ENABLED=false` 说明 |
| `src/Runner/SkillRunner.php` | 分支：`copilot` backend 走零翻译路径 |
| `src/Runner/AgentRunner.php` | 分支：`copilot` backend 要求先跑过 `copilot:sync`，否则提示 |
| Pricing migration | `ai_model_pricing` 加 `billing_model` 列（`usage` / `subscription`），Copilot 行标 `subscription` |
| Dashboard Blade | Copilot 订阅引擎不混进 USD 总额，单独列「Subscription engines」区块 |

### 3.3 不动的

- `Dispatcher` 契约
- 现有七个 adapter 的命名和路由
- SuperAgent backend（明确不加 Copilot provider）
- `gemini:sync` 逻辑（`copilot:sync` 独立实现，不强行抽公共基类，三个实现差异大）

---

## 4. Provider 模型

| Provider | 实现 | UI 行为 |
|---|---|---|
| `builtin` | 委托 `copilot` CLI 自身登录态；env 变量透传 | 显示「Login status: ✓ / ✗」+ 登录指引，不收 API key |

暂不暴露 `copilot-api` 或 `github-pat`。

---

## 5. MVP 分期

### MVP-1（目标 1–2 天）

- [ ] `CopilotCliBackend` 骨架：`name()` / `isAvailable()` / `call()`
- [ ] `isAvailable()` 实现：`which copilot` + 探测登录态（`copilot` 无命令跑通 → 视为未登录）
- [ ] `call()` 实现：`copilot -p "<prompt>" -s --model=<model>`，通过 `Symfony\Process`
- [ ] Dispatcher 注册 + ServiceProvider 绑定
- [ ] `list-backends` 能列出 `copilot_cli` 及状态
- [ ] Config + env var：`AI_CORE_COPILOT_CLI_ENABLED`、`AI_CORE_COPILOT_CLI_BINARY`、`AI_CORE_COPILOT_CLI_DEFAULT_MODEL`
- [ ] `skill:run --backend=copilot` **零翻译透传**（只是调 `call()` 并把「请运行 <skill-name> skill」作为 prompt；Copilot 自己发现 `.claude/skills/`）
- [ ] Feature test：mock `Symfony\Process`，断言命令行与 stdout 解析
- [ ] README / INSTALL 加 Copilot 一行；engine 列表更新

**交付后可用范围**：`./vendor/bin/superaicore call "..." --backend=copilot_cli` + `skill:run --backend=copilot`。

### MVP-2 ✅ 已完成

- [x] `copilot:sync` 命令：扫描 `.claude/agents/*.md` → 翻译并写 `~/.copilot/agents/*.agent.md`
  - Front-matter 重排：`tools`（Claude 列表）→ Copilot `tools`（`shell()/write()/read()/url()` 表达式）
  - `model` 字段丢弃（Copilot 按订阅服务端路由）
  - body 原样保留作为 system prompt
- [x] **Auto-sync** in `CopilotAgentRunner`：`agent:run --backend=copilot` 时自动 `syncOne()`，无需用户手动跑 `copilot:sync`；用户手改的目标文件会保留，runner 打印告知
- [x] `agent:run --backend=copilot` via `copilot --agent <name> -p ... -s --allow-all-tools`
- [x] `CopilotMcpSync`：通过 `McpManager::syncAllBackends()` 自动加进默认 backend 列表（catalog-derived）；`CopilotCapabilities::renderMcpConfig` 用「保守 merge」策略 —— 只覆盖我们持有的 server key，**保留** Copilot 内建 GitHub MCP 和用户手添加的条目；honors `XDG_CONFIG_HOME`
- [x] `CopilotToolPermissions`：完整翻译表（Read→read(*), Bash(git:*)→shell(git:*), mcp__github__*→github, etc.），收集未知 tool 名给 caller warning
- [x] Pricing：`config/super-ai-core.php` 加 `billing_model` 字段；Copilot 11 个模型用 `copilot:<model>` 前缀键标 `subscription`；同时全面补全 GPT-5/5.1/codex/mini、GPT-4.1、gemini-3-pro-preview、claude-opus-4-7/4-5、claude-sonnet-4-5 等之前缺失的价目；修复 CostCalculator 的 prefix-match bug（`gpt-5` 不再错配 `gpt-4o`）
- [x] Usage 日志：实测 `--output-format=json` 输出 JSONL；`CopilotCliBackend::generate()` 解析 `assistant.message` 拿 `outputTokens` 和 `result.usage.premiumRequests`，注入 `usage` 数组（input_tokens 始终 0，因为 Copilot 按 request 计费而非 token）
- [x] Dashboard：`CostDashboardController` 把 subscription rows 拆出独立面板，USD 总额只汇总 usage rows；订阅区显示 calls + tokens 而非 $

### 明确不做（v1 范围外）

- HTTP API path / 直接调 Copilot Chat endpoint
- `/fleet` 并行子代理的显式暴露
- Hook 系统对接
- Windows 专属路径优化（按 Symfony\Process 默认行为走）

---

## 6. 关键风险与未决项

| 项 | 风险 | 缓解 |
|---|---|---|
| `--output-format=json` 未在官方 reference 列出 | JSONL 解析可能依赖未稳定 flag | MVP-1 纯文本，MVP-2 前 `copilot --help` 实测再定 |
| Copilot 读不读 `.claude/agents/` | 目前官方文档只列 `.github/agents/` 和 `~/.copilot/agents/` | 上线前复查文档；若后续支持，`copilot:sync` 可改为可选 |
| 订阅计费与现有仪表盘不兼容 | USD 总额会出现"混账" | `billing_model` 列 + 仪表盘分区 |
| ToS | 官方 `-p` 明示支持程序化，风险低 | README 加一句声明「用户自带订阅、遵守 Copilot ToS」 |
| Token 刷新 | 完全由 `copilot` CLI 处理 | 无需应对 |
| 跨平台路径 | `~/.copilot/` 在 Windows 为 `%USERPROFILE%\.copilot\` | Symfony\Process 默认行为足够 |

---

## 7. 验收标准

MVP-1 完成的标志：

- `./vendor/bin/superaicore list-backends` 显示 `copilot_cli` 及状态
- 本机登录 Copilot 后，`call "Hello" --backend=copilot_cli` 返回响应
- `skill:run simplify --backend=copilot` 执行成功，Copilot 正确发现并运行 `.claude/skills/simplify`
- 未登录或未安装 CLI 时，`isAvailable()` 返回 false，错误信息明确

MVP-2 完成的标志：

- `copilot:sync` 无报错，翻译产物能被 `copilot` CLI 读到
- `agent:run <name> --backend=copilot` 触发 Copilot 的 `--agent` 模式
- Copilot 行的 usage 记录进 `ai_usage_logs`，dashboard 订阅分区可见

---

## 8. 参考资料

- [GitHub Copilot CLI is now generally available (2026-02-25)](https://github.blog/changelog/2026-02-25-github-copilot-cli-is-now-generally-available/)
- [About GitHub Copilot CLI](https://docs.github.com/copilot/concepts/agents/about-copilot-cli)
- [Copilot CLI programmatic reference](https://docs.github.com/en/copilot/reference/copilot-cli-reference/cli-programmatic-reference)
- [Authenticating Copilot CLI](https://docs.github.com/en/copilot/how-tos/copilot-cli/set-up-copilot-cli/authenticate-copilot-cli)
- [Adding agent skills for Copilot CLI](https://docs.github.com/en/copilot/how-tos/copilot-cli/customize-copilot/add-skills)
- [Creating custom agents for Copilot CLI](https://docs.github.com/en/copilot/how-tos/copilot-cli/customize-copilot/create-custom-agents-for-cli)
- [Adding MCP servers for Copilot CLI](https://docs.github.com/en/copilot/how-tos/copilot-cli/customize-copilot/add-mcp-servers)
- [Enhanced model selection changelog (2025-10-03)](https://github.blog/changelog/2025-10-03-github-copilot-cli-enhanced-model-selection-image-support-and-streamlined-ui/)
- [Where Copilot CLI Stores Configuration Files](https://inventivehq.com/knowledge-base/copilot/where-configuration-files-are-stored)
