# Analysis: `block/ai-rules` as a Canonical Sync Spec

**状态**：已评估，**不采纳**作为我们的 sync 规范
**日期**：2026-04-18
**相关文档**：`docs/copilot-cli-backend.md`、`docs/goose-cli-backend.md`

---

## 1. 背景

SuperAICore 已经在往多 engine 同步 `.claude/skills/` 和 `.claude/agents/` 资产（`gemini:sync` 已实现，`copilot:sync` 和 `goose:sync` 规划中）。自然的问题：能不能对齐一个**社区规范**，避免为每个后端各写一套 translator？

调研目标锁定 [`block/ai-rules`](https://github.com/block/ai-rules) —— 出自 Block（Goose 的东家），号称"Manage AI rules, commands, and skills across multiple coding agents from one place"，支持 11 个 agent。

## 2. `block/ai-rules` 画像

| 维度 | 情况 |
|---|---|
| 版本 / 活跃度 | v1.6.0（2026-03-31），9 次 release，93 stars，19 forks |
| 语言 / 协议 | Rust 97.7% / Apache-2.0 |
| 支持 agent | AMP、Claude Code、Cline、Codex、Copilot、Cursor、Firebender、Gemini、Goose、Kilocode、Roo |
| 源文件 | `ai-rules/*.md`，可选 frontmatter |
| 配置 | `ai-rules/ai-rules-config.yaml`（设默认行为） |
| CLI | `ai-rules init` / `generate` / `status` / `clean` / `list-agents` |

### Rule frontmatter schema

```yaml
---
description: Context description for when to apply this rule
alwaysApply: true | false
fileMatching: "**/*.ts"
---
```

只有三个字段，全 optional。

### 生成机制

**concatenation + symlink**：

- 所有 rule 合并为 `ai-rules-generated-AGENTS.md`
- `CLAUDE.md` / `AGENTS.md` / 各 agent 原生 rule 文件统一 symlink 到这一份
- Skills：`.claude/skills/ai-rules-generated-{name}` 也是 symlink，**不翻译 frontmatter**（部分 agent 剥离 frontmatter，如 AMP/Cursor）
- 命令（commands）frontmatter 仅支持 `allowed-tools`（Claude-only）、`description`、`model`（Claude-only）

## 3. 适用性评估

### ✅ 对齐点

- **生态信号**：Block 作为 Goose 维护方给出这个工具，说明"多 agent 统一管理"的需求被市场承认
- **目录约定**：`.claude/skills/` / `.agents/skills/` 的路径选择与 Copilot / Claude 一致

### ❌ 不适用于我们的原因

#### 3.1 Schema 太薄，覆盖不到核心工作

我们的主要 sync 工作不是"同一份 markdown 在不同地方各复制一份"，而是**字段重排 + 语义翻译**：

| 源 | 目标 | 工作内容 |
|---|---|---|
| `.claude/agents/*.md`（`name/description/tools/model/body`）| `.github/agents/*.agent.md`（`name/description/instructions/tools`）| 字段重命名、`tools` 列表 → Copilot 的 `--allow-tool` 表达式 |
| `.claude/agents/*.md` | Goose recipe YAML（`parameters/extensions/prompt/provider/model`）| 从 markdown 变成 YAML，结构重构 |
| `.claude/skills/<x>/SKILL.md` | 各家 `skills/` 目录 | 格式兼容，但 `allowed-tools` 字段在各家语义不同 |

`block/ai-rules` 的 3 字段 frontmatter（`description`/`alwaysApply`/`fileMatching`）对这些工作**无能为力**。

#### 3.2 Symlink 策略假装格式相同

Symlink 意味着「一份文件，多处读取」—— 这对 rules / context 文件勉强可行（都是 markdown），对 agent 定义不可行：

- Copilot 的 `.agent.md` 需要 `instructions:` 字段，Claude agent 没有
- Goose recipe 是 YAML，不是 markdown
- Claude agent 的 `tools:` 是工具名列表，Copilot 的 `tools:` 是权限表达式

强行 symlink 会让目标 agent 读到**无效配置**。

#### 3.3 生态未统一，押注过早

- `agent-rules/agent-rules` 社区标准**已 deprecate**，官方建议转向 [`openai/agents.md`](https://github.com/openai/agents.md)
- 另有 `lbb00/ai-rules-sync`（TypeScript 竞品）覆盖 Cursor/Claude/Copilot/OpenCode/Trae/Codex/Gemini/Warp
- `block/ai-rules` 目前 93 stars，尚未成为事实标准

**结论**：现在对齐任何一个都可能押错；`AGENTS.md` 文件名本身是唯一有一定共识的产物。

## 4. 建议

### 4.1 采纳：`AGENTS.md` 作为项目级 context

- SuperAICore 的 skill runner / agent runner preamble 注入时，若存在 `AGENTS.md`，自动纳入上下文
- 与现有的 `.claude/CLAUDE.md` 并列支持，不互斥
- 原因：Copilot、Goose、Aider、Codex、Gemini（多数）已接受，是跨 agent 共识程度最高的一个约定

### 4.2 拒绝：`block/ai-rules` schema

- 不将 `ai-rules/*.md` + 三字段 frontmatter 作为规范输入
- 不使用 symlink 作为 sync 策略

### 4.3 保持：自研 translator

继续走"每个 backend 一个 sync"的路径：

- `gemini:sync`（已实现）
- `copilot:sync`（规划中）
- `goose:sync`（规划中）

每个 translator 处理目标格式的特有字段。未来若出现 3 个以上 translator 有明显共同结构，再抽象 `AbstractBackendSync` 基类。

### 4.4 观察：`openai/agents.md` 与标准演进

- 每季度复查一次 OpenAI agents.md 是否扩展到 skill/agent 字段层
- 观察 `block/ai-rules` 是否重写为 translator 路径
- 若 12 个月内出现社区真正采纳的 agent 级 schema，重新评估此决定

## 5. 参考资料

- [block/ai-rules](https://github.com/block/ai-rules)
- [block/ai-rules rule format docs](https://github.com/block/ai-rules/blob/main/docs/rule-format.md)
- [block/ai-rules commands and skills docs](https://github.com/block/ai-rules/blob/main/docs/commands-and-skills.md)
- [agent-rules/agent-rules (deprecated)](https://github.com/agent-rules/agent-rules)
- [openai/agents.md](https://github.com/openai/agents.md)
- [lbb00/ai-rules-sync (TypeScript alternative)](https://github.com/lbb00/ai-rules-sync)
- [AGENTS.md Configuration overview](https://skywork.ai/blog/agent/agents-md-configuration-standardizing-ai-agent-instructions-across-teams/)
