/Users/xiyang/PhpstormProjects/SuperAICore/docs/copilot-followups.md# Copilot CLI backend — deferred follow-ups

**状态**：Copilot 主体已闭合（MVP-1 + MVP-2 + 收尾的 e2e + ProcessRegistrar）。本文档收录 v1 之外的潜在工作，按优先级排序。

**日期**：2026-04-18
**基线版本**：0.5.6（不自动 bump）

**2026-04-18 更新**：#1 / #3 / #4 / #6 已落地 — 见下文各节的 ✅ 标记。#2 / #5 / #7 仍按原优先级搁置。

---

## 1. CLI usage extraction for Claude / Codex / Gemini  ★最高价值 ✅ done

> **2026-04-18**：已实装。三家 backend 都改走 JSON 输出格式并解析 usage：
> - Claude：`--output-format=json` → `parseJson()` 取 `usage.input_tokens` / `output_tokens` / cache 指标；`model` 从 `modelUsage` 里挑 costUSD 最高的键
> - Codex：`exec --json` → `parseJsonl()` 吃 `turn.completed.usage`；text 从 `item.completed`(agent_message) 累积
> - Gemini：`--output-format=json` → `parseJson()` 识别 `roles.main` 的模型作为主答模型
>
> Dispatcher / CostCalculator 下游无需改动（已按 `input_tokens`/`output_tokens` 读）。
> 测试：`tests/Unit/ClaudeCliBackendTest.php`、`CodexCliBackendTest.php`、`GeminiCliBackendTest.php`。

**问题**：仪表盘大面积显示 `$0` 的真正根因不是缺价格，而是 CLI 后端把 `input_tokens => 0, output_tokens => 0` 写进 usage：

```php
// src/Backends/ClaudeCliBackend.php:78
'usage' => ['input_tokens' => 0, 'output_tokens' => 0],  // CLI doesn't report tokens
```

`CodexCliBackend`、`GeminiCliBackend` 同样的占位。Copilot 这次解决了，但其它三家没动。

**为什么 Copilot 先做**：它的 `--output-format=json` 在 GA 文档里写得清清楚楚，本机一探就 work。其它三家需要本机实测：

| CLI | 候选 flag | 已知问题 |
|---|---|---|
| `claude` | `--output-format=stream-json --verbose` | Claude Code 的 stream-json 已经有解析器（`ProcessMonitor::parseStreamJsonIfNeeded`），但当前 `ClaudeCliBackend::generate()` 没用。重构成本：低 |
| `codex` | `codex exec --json` | Codex 0.10+ 据说支持，需本机验证 schema |
| `gemini` | `gemini --output-format=json` | gemini-cli 文档未明确。可能要走 stderr 解析 |

**设计**：每个 backend 类加一个 `parseUsage(string $output): array` 方法，跟 `CopilotCliBackend::parseJsonl()` 同形。Dispatcher 已经会把 `usage.input_tokens / output_tokens / model` 喂给 `CostCalculator`，无需改下游。

**收益**：
- USD 成本在 dashboard 真实化（之前所有 CLI 路径都 $0）
- model 名字会更准确（路由器实际选的是 `gpt-5.4` 而不是配置里的 `gpt-5.1-codex`）
- 回归测试：`Dispatcher::dispatch()` 返回的 `usage` 不再是占位

**估算**：每个 CLI 半天到一天，含本机实测 + 单元测试。3-CLI ≈ 2-3 天。

**风险**：
- Claude `--output-format=stream-json` 在某些 claude-code 版本下要 `--verbose` 才生效；老版本可能不支持
- Codex `exec --json` schema 在 0.x 版本变化频繁，可能需要 version probe
- Gemini 可能干脆不支持 JSON 输出，需要回退到 regex 解析 stderr 的 token 计数

---

## 2. Copilot plugin-skill scanning gap

**问题**：Copilot CLI 文档列出的 skill 扫描目录是：

- Project：`.github/skills/` / `.claude/skills/` / `.agents/skills/`
- User：`~/.copilot/skills/` / `~/.claude/skills/` / `~/.agents/skills/`

**遗漏**：`~/.claude/plugins/*/skills/`（Claude Code 的 plugin skill 路径）。

`SkillRegistry` 会发现 plugin skills 并通过 `skill:run --backend=copilot` 透传，但 Copilot 自身的 `.copilot help skills` 看不到它们 —— 用户在 Copilot 的 `/skill:` 自动补全里找不到 plugin skills。

**两条路**：

A. **Symlink 同步**：增加 `copilot:sync-skills` 子命令，把 `~/.claude/plugins/*/skills/<name>` 软链到 `~/.copilot/skills/<name>`。轻量，但跨平台 symlink 不可靠（Windows 没默认权限）

B. **文档警告**：在 `copilot:sync` 命令的 help text 和 README 加一行说明，建议用户把 plugin skills 手动符号链接

**估算**：A=半天；B=10 分钟

**建议**：先 B，等用户反馈说真的有人在用 plugin skill + Copilot 双跑场景再做 A。

---

## 3. `/fleet` 并行子代理暴露 ✅ done（host-side fan-out）

> **2026-04-18**：实测确认 `/fleet` 只在交互模式可用，`-p` 会把 `/fleet` 当字面量传给模型。改走 host-side fan-out：
> - `CopilotFleetRunner`：并行 `start()` N 个 `copilot --agent X -p <task> --output-format=json`，统一 poll `getIncrementalOutput()`，按 `[<agent>]` 前缀流式回吐
> - 每个子进程都注册到 `ai_processes`（external_label=`fleet:<name>`），Process Monitor 看得到
> - 每个子输出过 `CopilotCliBackend::parseJsonl()` 聚合；结果数组含 `{agent, text, model, output_tokens, premium_requests, exit_code}`
> - 新命令 `copilot:fleet <task> --agents a,b,c [--model ...] [--json] [--dry-run]`；exit code = 子进程里最坏的那个
> - 测试：`tests/Unit/Runner/CopilotFleetRunnerTest.php`（dry-run 覆盖）
>
> 未来若 Copilot CLI 暴露真正的 `--fleet` 非交互标志，再切到原生路径。

**背景**：Copilot CLI 原生有 `/fleet` 命令，可以一次启动 N 个子 agent 并行跑同一任务，最后聚合。我们目前只暴露 `--agent`（单 agent），fleet 没暴露。

**与 SuperAICore 现有架构的关系**：
- AgentSpawn 模块（`src/AgentSpawn/`）已经有 spawn-plan 协议（让 Codex/Gemini 写 `_spawn_plan.json` 然后 host 来 fan-out）
- Copilot 的 fleet 是 native，不需要协议层

**设计**：
- 新命令 `copilot:fleet <prompt> --agents <name1>,<name2>,...`
- 内部翻译成 `copilot --fleet "..." --agent <a1> --agent <a2> ...`（待实测 fleet 实际语法）
- 输出聚合走 `--output-format=json` parser

**估算**：1-2 天。需要先实测 `/fleet` 的非交互式调用语法。

**风险**：fleet 在文档里主要走交互式 `/fleet` 命令，`-p` 模式可能不支持。

---

## 4. Copilot Hooks 对接 ✅ done（首版）

> **2026-04-18**：Copilot changelog 确认 PascalCase 事件名（PreToolUse / PostToolUse / SessionStart …）Copilot 原生识别，payload 走 VS Code / Claude 兼容的 snake_case。所以 hook **不需要内容翻译**，只需要把 Claude 的 `hooks` 块搬到 Copilot 的 config.json。
> - `Sync/CopilotHookWriter`：读源 `hooks` 数组 → 合并进 `~/.copilot/config.json` 的 `hooks` 键（保留 trustedFolders / banner / firstLaunchAt 等）。manifest 记哈希，重跑无副作用；手改过的 hooks 块检测到即中止（返回 `STATUS_USER_EDITED`）
> - Hash 对 key 做 deep-ksort，和 PHP 的关联数组顺序无关
> - 新命令 `copilot:sync-hooks [--source <path>] [--clear] [--dry-run]`，默认源是 `./.claude/settings.json`
> - 测试：`tests/Unit/Sync/CopilotHookWriterTest.php`（8 例覆盖 written/unchanged/user-edited/cleared/hash-stability/settings-reader）
>
> 尚未做：跨 host-app 的 settings.json 聚合（Claude + SuperAgent + 其它）。目前只吃 `.claude/settings.json.hooks`，若 host app 用别的 path，通过 `--source` 传。

**背景**：Copilot CLI 有自己的 hook 系统（`pre-tool-use` / `post-tool-use` / `session-end` 等）。SuperAICore 也有 host 层的 hook（通过 settings.json 配置 Claude Code hooks）。

**目标**：让 Copilot 触发的 tool call 也能命中 host 层的 hook 链。

**复杂度**：高。Copilot hook 注册在 `~/.copilot/config.json`，需要：
- 在 syncAllBackends 里多一类 hook 同步
- Hook script 需要兼容 Copilot 的事件 payload 格式（与 Claude 不同）
- 跨语言：Claude Code hooks 通常是 shell script，Copilot 的 hook 接口待确认

**估算**：3-5 天，含 schema 调研。

**建议**：等到 host app 真有 hook 跨引擎需求再做。当前还没人提。

---

## 5. HTTP path（直接调 Copilot Chat endpoint）

**为什么之前否决**：OAuth device flow + session token refresh + keychain 存储全在 `copilot` 二进制里，从 PHP 重新实现等于重建一个 `copilot` CLI。ToS 边界也模糊（GitHub 没明示这个 endpoint 对外开放）。

**唯一会重新评估的场景**：GitHub 公开发布 Copilot Chat API 给第三方使用（带文档化的 endpoint + API key 管理）。目前没看到这个动向。

**建议**：长期搁置，标记为 wontfix 直到 GitHub 官方动作。

---

## 6. Process registration cross-engine 推广 ✅ done

> **2026-04-18**：抽了 `Runner/Concerns/MonitoredProcess` trait（`runMonitored()` 做 start → register → wait-with-tee → end 的全链路），8 个 runner（Claude/Codex/Gemini/Copilot × Skill/Agent）统一切换。Copilot 的两个也从内联实现迁移到 trait，减少 ~40 行重复。
> - `emit()` 可见性从 `private` 改到 `protected`，trait 调用；现有测试全部绿
> - 每个 runner 都会给 `ai_processes` 行打上 `{kind: skill|agent, name}` 的 metadata
> - 测试：`MonitoredProcess` 本身不需要独立测试（trait 无状态），既有 runner 测试覆盖了契约

**已完成**：Copilot runners (Skill + Agent) 已通过 `ProcessRegistrar` 写 `ai_processes` 表 + tee 日志到 tmp 文件。

**未完成**：`ClaudeSkillRunner` / `ClaudeAgentRunner` / `CodexSkillRunner` / `CodexAgentRunner` / `GeminiSkillRunner` / `GeminiAgentRunner` 还在用 `Process::run()`，没注册。

**复制 Copilot 的实现到其它六个 runner** 是机械重构，1 小时左右。但要顺便决定：要不要把 `start() + wait()` 提取到一个 trait（`HasMonitoredProcess` 之类）。

**估算**：1-2 小时（含 trait 抽取 + 6 处替换 + 测试更新）。

---

## 7. CopilotMcpSync 的 XDG 写入路径

**当前局限**：`BackendCapabilities::mcpConfigPath()` 返回相对 HOME 的路径，`McpManager::syncAllBackends()` 总是写到 `$HOME/.copilot/mcp-config.json`。

**问题**：用户设置了 `XDG_CONFIG_HOME` 时，Copilot 实际读 `$XDG_CONFIG_HOME/copilot/mcp-config.json`，而我们写的是另一个路径，配置失效。

**修复方向**：让 `BackendCapabilities` 接口加个 `resolveConfigPath(): string` 返回绝对路径（默认实现 `$HOME / mcpConfigPath()`，Copilot 实现里检查 XDG_CONFIG_HOME）。

**估算**：半天，含跨 capability 接口变更和向后兼容（默认方法）。

---

## 优先级建议

按「ROI / 风险」排：

| #  | 项目                                       | ROI | 风险         | 状态 / 建议时机                               |
| -- | ------------------------------------------ | --- | ------------ | -------------------------------------------- |
| 1  | CLI usage extraction (Claude/Codex/Gemini) | 高  | 中           | ✅ 2026-04-18 已落地                         |
| 6  | Process registration 推广到其它 runner     | 中  | 低           | ✅ 2026-04-18 已落地（提取 trait）           |
| 3  | `/fleet` 暴露                              | 中  | 中           | ✅ 2026-04-18 已落地（host-side fan-out）    |
| 4  | Hooks 对接                                 | 低  | 高           | ✅ 2026-04-18 已落地（首版）                 |
| 7  | XDG 写入路径修复                           | 低  | 低           | 等到有用户在 macOS / Linux 用 XDG 跑 Copilot 时 |
| 2  | Plugin-skill 警告文档（B 方案）            | 低  | 零           | 加在下次文档更新                             |
| 5  | HTTP path                                  | 零  | 高           | wontfix 直到 GitHub 公开 endpoint            |
| 2A | Plugin-skill symlink 同步                  | 低  | 中（跨平台） | wontfix 除非 A 方案有真实诉求                |

---

## 与设计文档的关系

`docs/copilot-cli-backend.md` §5「明确不做（v1 范围外）」列了 #3 / #4 / #5 / Windows 路径优化。本文档把那 4 项展开 + 加了 #1（最大遗漏）+ #6 / #7（v1 边界发现的）。

`docs/copilot-cli-backend.md` 是「Copilot 怎么做」，本文档是「Copilot 之后还能做什么」。两者互补，不替换。
