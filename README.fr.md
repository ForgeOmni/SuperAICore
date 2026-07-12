# forgeomni/superaicore

[![tests](https://github.com/ForgeOmni/SuperAICore/actions/workflows/tests.yml/badge.svg)](https://github.com/ForgeOmni/SuperAICore/actions/workflows/tests.yml)
[![license](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![php](https://img.shields.io/badge/php-%E2%89%A58.1-blue.svg)](composer.json)
[![laravel](https://img.shields.io/badge/laravel-10%20%7C%2011%20%7C%2012-orange.svg)](composer.json)

[English](README.md) · [简体中文](README.zh-CN.md) · [Français](README.fr.md)

Package Laravel pour l'exécution unifiée d'IA sur dix moteurs d'exécution — **Claude Code CLI**, **Codex CLI**, **Gemini CLI**, **GitHub Copilot CLI**, **AWS Kiro CLI**, **Moonshot Kimi Code CLI**, **Alibaba Qwen Code CLI**, **Cursor Composer CLI**, **xAI Grok Build CLI** et **SuperAgent SDK**. Livré avec une CLI indépendante du framework, un dispatcher par capacité, la gestion des serveurs MCP, le suivi d'usage, l'analyse des coûts, un proxy OpenAI-compatible, un ring-buffer de traçage style magic-trace et une interface d'administration complète.

Fonctionne de façon autonome dans une installation Laravel neuve. L'UI est optionnelle et entièrement remplaçable — elle peut être intégrée dans une application hôte (par ex. SuperTeam) ou désactivée si seuls les services sont nécessaires.

## Sommaire

- [Relation avec SuperAgent](#relation-avec-superagent)
- [Fonctionnalités](#fonctionnalités)
  - [Moteurs d'exécution + types de provider](#moteurs-dexécution--types-de-provider)
  - [Exécuteur de skills & sous-agents](#exécuteur-de-skills--sous-agents)
  - [Moteur de skills — télémétrie, ranking, évolution](#moteur-de-skills--télémétrie-ranking-évolution)
  - [Vague d'outils jcode (0.9.0 / SDK 0.9.7)](#vague-doutils-jcode-090--sdk-097)
  - [Vague d'alignement DeepSeek-TUI (0.9.1 / SDK 0.9.8)](#vague-dalignement-deepseek-tui-091--sdk-098)
  - [Vague de fiabilité TaskRunner (0.9.2)](#vague-de-fiabilité-taskrunner-092)
  - [Vague Squad multi-agent + SDK 1.0.0 (0.9.6)](#vague-squad-multi-agent--sdk-100-096)
  - [Vague de fonctionnalités inspirées d'opencode (0.9.7 / SDK 1.0.5)](#vague-de-fonctionnalités-inspirées-dopencode-097--sdk-105)
  - [Vague Qwen + traçage + 9Router (0.9.8)](#vague-qwen--traçage--9router-098)
  - [Vague Opus 4.8 + Grok + Cursor (1.0.0 / SDK 1.0.9)](#vague-opus-48--grok--cursor-100--sdk-109)
  - [Vague kimi-cli + kimi-code (1.0.2 / SDK 1.0.10)](#vague-kimi-cli--kimi-code-102--sdk-1010)
  - [Vague workflows cross-CLI SmartFlow (1.0.5 / SDK 1.1.0)](#vague-workflows-cross-cli-smartflow-105--sdk-110)
  - [Vague pont de skills CLI (1.0.6)](#vague-pont-de-skills-cli-106)
  - [Vague MiniMax M3 + retarification du catalogue (1.0.7 / SDK 1.1.1)](#vague-minimax-m3--retarification-du-catalogue-107--sdk-111)
  - [Vague streamChat MCP (1.0.8)](#vague-streamchat-mcp-108)
  - [Vague vaisseau amiral natif GLM-5.2 (1.0.10 / SDK 1.1.2)](#vague-vaisseau-amiral-natif-glm-52-1010--sdk-112)
  - [Vague Fable 5 & Sonnet 5 (1.0.11 / SDK 1.1.5)](#vague-fable-5--sonnet-5-1011--sdk-115)
  - [Vague parité ai-dispatch (1.1.0)](#vague-parité-ai-dispatch-110)
  - [Vague GPT-5.6 + Grok 4.5 rafraîchissement du catalogue (1.1.6 / SDK 1.1.6)](#vague-gpt-56--grok-45-rafraîchissement-du-catalogue-116--sdk-116)
  - [Installateur CLI & santé](#installateur-cli--santé)
  - [Dispatcher & streaming](#dispatcher--streaming)
  - [Catalogue de modèles](#catalogue-de-modèles)
  - [Système de types de provider](#système-de-types-de-provider)
  - [Suivi d'usage & coûts](#suivi-dusage--coûts)
  - [Idempotence & traçage](#idempotence--traçage)
  - [Gestionnaire de serveurs MCP](#gestionnaire-de-serveurs-mcp)
  - [Intégration SuperAgent SDK](#intégration-superagent-sdk)
  - [Durcissement agent-spawn](#durcissement-agent-spawn)
  - [Moniteur de processus & UI admin](#moniteur-de-processus--ui-admin)
  - [Intégration hôte](#intégration-hôte)
- [Prérequis](#prérequis)
- [Installation](#installation)
- [Démarrage rapide — CLI](#démarrage-rapide--cli)
- [Démarrage rapide — PHP](#démarrage-rapide--php)
- [Architecture](#architecture)
- [Usage avancé](#usage-avancé)
- [Configuration](#configuration)
- [Licence](#licence)

## Relation avec SuperAgent

`forgeomni/superaicore` et `forgeomni/superagent` sont des **packages frères, pas une relation parent-enfant** :

- **SuperAgent** est un SDK PHP léger, en processus, qui pilote une seule boucle LLM avec tool-use (un agent, une conversation).
- **SuperAICore** est une couche d'orchestration à l'échelle de Laravel — elle choisit le backend, résout les identifiants du provider, route par capacité, suit l'usage, calcule les coûts, gère les serveurs MCP et fournit une UI d'administration.

**SuperAICore ne dépend pas de SuperAgent pour fonctionner.** Le SDK n'est que l'un des backends disponibles. Les six moteurs CLI et les trois backends HTTP fonctionnent sans lui, et `SuperAgentBackend` se déclare poliment indisponible via `class_exists(Agent::class)` quand le SDK est absent. Définissez `AI_CORE_SUPERAGENT_ENABLED=false` dans votre `.env` et le Dispatcher se rabat sur les backends restants.

L'entrée `forgeomni/superagent` dans `composer.json` est là pour que le backend SuperAgent compile tel quel. Si vous ne l'utilisez jamais, retirez-la du `composer.json` de votre hôte avant `composer install` — aucun autre code de SuperAICore n'importe l'espace de noms SuperAgent.

## Fonctionnalités

Chaque fonctionnalité ci-dessous est marquée par la version où elle a été introduite. Les fonctionnalités sans étiquette existaient déjà avant 0.6.0.

### Moteurs d'exécution + types de provider

- **Dix moteurs d'exécution** unifiés derrière un même contrat `Dispatcher` :
  - **Claude Code CLI** — types de provider : `builtin` (connexion locale), `anthropic`, `anthropic-proxy`, `bedrock`, `vertex`.
  - **Codex CLI** — `builtin` (connexion ChatGPT), `openai`, `openai-compatible`.
  - **Gemini CLI** — `builtin` (OAuth Google), `google-ai`, `vertex`.
  - **GitHub Copilot CLI** — `builtin` uniquement (le binaire `copilot` gère OAuth/keychain/refresh). Lit `.claude/skills/` nativement (passage sans traduction). **Facturation par abonnement** — suivie séparément sur le tableau de bord.
  - **AWS Kiro CLI** (depuis 0.6.1) — `builtin` (connexion locale `kiro-cli login`), `kiro-api` (clé stockée injectée comme `KIRO_API_KEY` pour le headless). Offre l'ensemble de fonctionnalités CLI le plus riche — agents, skills, MCP et **orchestration DAG native de sous-agents** (aucune émulation `SpawnPlan`). Lit le format `SKILL.md` de Claude sans traduction. **Facturation par abonnement** — forfaits Pro / Pro+ / Power.
  - **Moonshot Kimi Code CLI** (depuis 0.6.8) — `builtin` (`kimi login` OAuth via `auth.kimi.com`). Complémentaire du `KimiProvider` HTTP direct du SDK pour couvrir le chemin agentic-loop sur abonnement OAuth, miroir du split `anthropic_api` ↔ `claude_cli`. Fanout `Agent` natif par défaut ; basculez vers la Pipeline à trois phases d'AICore via `use_native_agents=false`. **Facturation par abonnement** — forfaits Moonshot Pro / Power.
  - **Alibaba Qwen Code CLI** (depuis 0.9.8) — fork de gemini-cli (`QwenLM/qwen-code` v0.16.0) adapté à la famille Qwen. Clé API uniquement (`DASHSCOPE_API_KEY` / `QWEN_API_KEY`) ; l'OAuth a été EOL'd le 2026-04-15. Modèle par défaut `qwen3.7-max` — 1M de contexte, $2.50/$7.50 par 1M, parle nativement le protocole Anthropic `/v1/messages` (substitut direct de Claude dans les chaînes de fallback). **Facturation à l'usage.**
  - **Cursor Composer CLI** (depuis 1.0.0) — `builtin` (`cursor-agent login` OAuth navigateur → `~/.cursor` ; les runners headless peuvent exporter `CURSOR_API_KEY`). L'agent Composer headless de Cursor (`cursor-agent`). Modèle par défaut `composer-2.5-fast` ; relaie aussi les SKU Anthropic (`claude-opus-4-8-thinking-high`) et OpenAI (`gpt-5.x-codex`) + un routeur `auto`. MCP via `.cursor/mcp.json`. **Facturation par abonnement** — forfait Cursor.
  - **xAI Grok Build CLI** (depuis 1.0.0) — `builtin` (`grok login` OAuth grok.com → `~/.grok`). La CLI agentique « Grok Build » de xAI (`grok`). Modèle par défaut `grok-build` ; sous-agents natifs, contrôle d'effort (`--effort low…max`), MCP via `grok mcp add`. **Facturation par abonnement** — forfait grok.com. *(Distinct du type de provider **API** xAI mesuré ci-dessous.)*
  - **SuperAgent SDK** — types de provider : `anthropic`, `anthropic-proxy`, `openai`, `openai-compatible`, plus `openai-responses` (depuis 0.7.0), `lmstudio` (depuis 0.7.0), `deepseek` (depuis 0.9.0), `qwen-anthropic` (depuis 0.9.8) et `grok` (depuis 1.0.0 — API xAI mesurée, `XAI_API_KEY`/`GROK_API_KEY`, défaut `grok-4.3`, 1M de contexte).
- **Type de provider `openai-responses`** (depuis 0.7.0) — route via le `OpenAIResponsesProvider` du SDK contre `/v1/responses`. Auto-détecte les déploiements Azure OpenAI depuis le pattern `base_url` (ajoute la query `api-version=2025-04-01-preview` ; surchargez via `extra_config.azure_api_version`). Quand la ligne stocke un `access_token` issu d'un flux OAuth ChatGPT côté hôte au lieu d'une clé API, le SDK bascule la base URL sur `chatgpt.com/backend-api/codex`, donc les abonnés Plus / Pro / Business touchent leur quota d'abonnement.
- **Type de provider `lmstudio`** (depuis 0.7.0) — serveur LM Studio local (défaut `http://localhost:1234`). Protocole OpenAI-compat ; pas de vraie clé API requise — le SDK synthétise un header `Authorization` de substitution.
- **Treize adaptateurs dispatcher** derrière les dix moteurs (`claude_cli`, `codex_cli`, `gemini_cli`, `copilot_cli`, `kiro_cli`, `kimi_cli`, `qwen_cli`, `cursor_cli`, `grok_cli`, `superagent`, `anthropic_api`, `openai_api`, `gemini_api`). Adaptateur CLI quand le provider utilise `builtin` / `kiro-api` ; adaptateur HTTP quand il utilise une clé API. Directement adressable depuis la CLI si nécessaire.
- **`EngineCatalog` source unique de vérité** — labels, icônes, backends Dispatcher, types de provider, modèles disponibles et `ProcessSpec` déclaratif vivent dans un service PHP unique. Ajouter un nouveau moteur CLI revient à éditer `EngineCatalog::seed()` et chaque picker se met à jour automatiquement. Les hôtes surchargent via la config `super-ai-core.engines`. `modelOptions($key)` / `modelAliases($key)` (depuis 0.5.9) pilotent les dropdowns de modèles côté hôte.

### Exécuteur de skills & sous-agents

- **Détection skill / sous-agent** — auto-détecte les skills Claude Code (`.claude/skills/<nom>/SKILL.md`) et sous-agents (`.claude/agents/<nom>.md`) depuis trois sources pour les skills (projet > plugin > user) et deux pour les agents. Expose chacun comme sous-commande CLI de première classe (`skill:list`, `skill:run`, `agent:list`, `agent:run`).
- **Exécution native inter-backends** — `--exec=native` exécute un skill sur le CLI du backend choisi ; `CompatibilityProbe` signale les skills incompatibles ; `SkillBodyTranslator` réécrit les noms d'outils canoniques (`` `Read` `` → `read_file`, …) et injecte le préambule backend (Gemini / Codex).
- **Chaîne de repli verrouillée sur effet-de-bord** — `--exec=fallback --fallback-chain=gemini,claude` essaie les sauts dans l'ordre, saute les incompatibles et **verrouille dur** sur le premier qui écrit dans le cwd (diff mtime + événements `tool_use` stream-json).
- **`gemini:sync`** — miroir des skills / agents en commandes personnalisées Gemini (`/skill:init`, `/agent:reviewer`). Respecte les éditions manuelles via `~/.gemini/commands/.superaicore-manifest.json`.
- **`copilot:sync`** — miroir des agents dans `~/.copilot/agents/*.agent.md`. S'exécute automatiquement avant `agent:run --backend=copilot`.
- **`copilot:sync-hooks`** — fusionne les hooks style Claude (`.claude/settings.json:hooks`) dans `~/.copilot/config.json:hooks`.
- **`copilot:fleet`** — exécute la même tâche sur N sous-agents Copilot en parallèle, agrège les résultats, enregistre chaque enfant dans le moniteur.
- **`kiro:sync`** (depuis 0.6.1) — traduit la frontmatter d'agent Claude en `~/.kiro/agents/*.json` pour exécution DAG native Kiro.
- **`kimi:sync`** (depuis 0.6.8) — traduit les listes d'outils de `.claude/agents/*.md` en `~/.kimi/agents/*.yaml` + `~/.kimi/mcp.json`. `claude:mcp-sync` fan-out vers Kimi automatiquement.

### Moteur de skills — télémétrie, ranking, évolution

Trois services orthogonaux *(depuis 0.8.6)* qui transforment le catalogue de skills statique en boucle de feedback. Inspiré du `skill_engine` de HKUDS/OpenSpace, taillé au sous-ensemble safe pour la prod — modes DERIVED / CAPTURED volontairement omis (Day 0 : les humains curatent les nouveaux skills) ; pas de registry cloud (pas de besoin de partage inter-projets pour l'instant).

- **`SkillTelemetry`** *(depuis 0.8.6)* — une ligne par invocation Skill de Claude Code dans `sac_skill_executions`. Hook PreToolUse → `php artisan skill:track-start` (insert ligne `in_progress`, retourne id). Hook Stop → `php artisan skill:track-stop` (ferme toutes les lignes encore ouvertes pour la session). Les deux commandes acceptent le payload JSON du hook Claude Code sur stdin — le câblage vit donc dans `.claude/settings.local.json`, pas dans du PHP. Seam d'agrégation : `SkillTelemetry::metrics(?since, ?skillName)` retourne par skill `applied / completed / failed / orphaned / interrupted / completion_rate / failure_rate / last_used_at`. `sweepOrphaned(maxAgeSeconds=7200)` récupère après les sessions crashées.
- **`SkillRanker`** *(depuis 0.8.6)* — BM25 pur PHP sur le catalogue `SkillRegistry` (Robertson-Walker `K1=1.5`, `B=0.75`, IDF BM25-Plus). Tokeniseur CJK-aware qui émet chaque caractère Han comme un token (les descriptions de skill chinoises sont courtes — les char-grams suffisent), petite stoplist EN+zh, mots avec tirets éclatés en parties. Boost télémétrie pondéré par confiance : `final = bm25 * (1 + 0.4 * (success_rate - 0.5) * applied_signal)`, où `applied_signal = min(1, applied / 10)` sature vers 10 runs. Skills sans télémétrie reçoivent `boost = 1.0`. Pilote `php artisan skill:rank "votre description de tâche"` — sortie table ou JSON, avec breakdown complet per-term IDF×TF pour le debug.
- **`SkillEvolver`** *(depuis 0.8.6)* — mode FIX uniquement. Lit les échecs récents + le SKILL.md actuel, construit un prompt LLM contraint (« plus petit patch possible », « ne pas inventer d'échecs que les preuves ne supportent pas », « ne pas restructurer les sections / renommer / changer le `name` du frontmatter / ajouter de nouveaux outils à `allowed-tools` sauf si les preuves l'exigent »), puis persiste un `SkillEvolutionCandidate` en statut `pending`. **Ne modifie jamais SKILL.md directement** — les humains review via `php artisan skill:candidates --id=N --show-prompt --show-diff`. Le mode `--dispatch` (off par défaut — coûte des tokens) route le prompt via le Dispatcher avec `capability: 'reasoning'`, parse le bloc `\`\`\`diff`, et stocke à la fois `proposed_body` et `proposed_diff`. `--sweep --threshold=0.30 --min-applied=5` met en queue des candidats pour chaque skill qui dépasse le seuil ; dédupliqué contre les lignes pending existantes — sûr à lancer quotidiennement. Triggers : `manual` / `failure` / `metric_degradation`.
- **Six commandes artisan** : `skill:track-start`, `skill:track-stop`, `skill:stats`, `skill:rank`, `skill:evolve`, `skill:candidates`. Toutes enregistrées via `SuperAICoreServiceProvider::boot()` — `php artisan skill:*` fonctionne dans n'importe quel hôte qui monte le package.
- **Deux nouvelles tables** : `sac_skill_executions` (skill_name, host_app, session_id, status, started_at, completed_at, duration_ms, transcript_path, error_summary, cwd, metadata json) et `sac_skill_evolution_candidates` (skill_name, trigger_type, execution_id, status, rationale, proposed_diff, proposed_body, llm_prompt, context json, reviewed_at, reviewed_by). Les deux honorent `super-ai-core.table_prefix` via `HasConfigurablePrefix`. `php artisan migrate` pour les créer.

### Vague GPT-5.6 + Grok 4.5 rafraîchissement du catalogue (1.1.6 / SDK 1.1.6)

Le pin SDK passe de `^1.1.5` à `^1.1.6`. SuperAgent 1.1.6 fait atterrir
**GPT-5.6** (Sol / Terra / Luna — le nouveau défaut `openai-responses`) et
**Grok 4.5** (le nouveau défaut `grok`) avec leurs surfaces de requête, et
corrige le catalogue Gemini / DeepSeek / MiniMax / GLM / Qwen aux tarifs
officiels ; SuperAICore transmet les nouvelles options par appel, reflète les
tarifs corrigés dans sa propre table `model_pricing` et corrige la dérive du
sélecteur Gemini (`gemini-3.5-pro` / `gemini-3.5-flash-lite` n'ont jamais été
publiés). Additif et non cassant — aucune migration, aucun changement de
config.

- **Surface GPT-5.6 / Gemini 3.5 transmise** — `SuperAgentBackend` transmet
  désormais `reasoning_mode` (`standard`|`pro` — Sol Pro),
  `reasoning_context` (`auto`|`all_turns`|`current_turn`) et
  `prompt_cache_options` (cache explicite : écritures 1,25×, lectures −90 %)
  à l'`OpenAIResponsesProvider` du SDK, plus `thinking_level`
  (`minimal`…`high`, le contrôle qui remplace `thinkingBudget`) au
  `GeminiProvider`. Les quatre sont ignorés silencieusement par les
  providers qui ne les parlent pas. Le cadran `reasoning_effort` existant
  gagne `none`/`max` sur GPT-5.6 et le cadran trois niveaux toujours actif
  sur Grok 4.5 — côté SDK, aucun changement SuperAICore requis.
- **Nouveaux modèles tarifés** — `gpt-5.6-sol` **5 $ / 0,50 $ en cache /
  30 $** par 1M, `gpt-5.6-terra` **2,50 $ / 0,25 $ / 15 $**, `gpt-5.6-luna`
  **1 $ / 0,10 $ / 6 $** (tous 1,05M de contexte) ; `grok-4.5` **2 $ /
  0,50 $ en cache / 6 $** (500K de contexte, le nouveau défaut `grok` ;
  `grok-4.3` reste joignable) ; `gemini-3.5-flash` (le vrai vaisseau amiral)
  **1,50 $ / 0,15 $ en cache / 9 $** ; `gemini-3.1-pro-preview` 2 $/12 $ ;
  `gemini-3.1-flash-lite` 0,25 $/1,50 $ ; `kimi-k2.7-code` 0,95 $ / 0,19 $
  cache-hit / 4 $ (+`-highspeed` à 2×) ; `glm-5-turbo` / `glm-5v-turbo`
  1,20 $/4 $.
- **Corrections tarifaires reflétées** — `gpt-5` à son tarif officiel
  **1,25 $/10 $** (était une estimation 5 $/15 $), sortie
  `deepseek-v4-flash` **0,55 $ → 0,28 $** (+0,0028 $ cache-hit),
  `MiniMax-M3` au tarif par paliers permanent **0,30 $/1,20 $** (lecture
  cache 0,06 $), `qwen3.7-plus` au tarif GA **0,40 $/1,60 $**. Re-publiez la
  config si votre hôte porte une copie plus ancienne.
- **Catalogue Gemini corrigé à la réalité** — `gemini-3.5-pro` et
  `gemini-3.5-flash-lite` n'ont jamais été publiés et sont retirés du
  sélecteur du moteur `gemini` ; `gemini-3.5-flash` /
  `gemini-3.1-pro-preview` / `gemini-3.1-flash-lite` atterrissent dans
  `EngineCatalog` et `GeminiModelResolver`. Les défauts zéro-config du SDK
  bougent (`openai-responses` → `gpt-5.6-sol`, `grok` → `grok-4.5`,
  `gemini` → `gemini-3.5-flash`) ; chaque id déjà publié reste joignable en
  config explicite.
- **Catalogues des CLI par abonnement re-vérifiés en direct (2026-07-12)** —
  le plan Grok Build (grok CLI 0.2.93) route désormais `grok-4.5` comme
  défaut d'abonnement plus `grok-composer-2.5-fast` (`grok-build` conservé
  en ligne héritée), et la gamme Cursor Composer (~189 slugs) fait de
  `composer-2.5` le choix « current » et proxifie Fable 5 / Sonnet 5 /
  GPT-5.6 Sol / Grok 4.5 / Gemini 3.5 Flash / Kimi K2.7 Code / GLM 5.2 —
  `GrokModelResolver`, `CursorModelResolver` (nouveaux alias
  `fable`/`sonnet`/`grok`/`gemini`/`kimi`/`glm`), les seeds moteurs et les
  lignes d'abonnement à 0 $ `grok:*`/`cursor:*` suivent. ZCode (l'IDE de
  bureau de Z.ai) a été évalué puis écarté — aucune surface CLI headless à
  intégrer.

### Vague parité ai-dispatch (1.1.0)

Empruntée à [rennzhang/ai-dispatch](https://github.com/rennzhang/ai-dispatch) :
permettre à un agent IA de déléguer une tâche à un autre moteur IA local sans
connaître ses drapeaux. Un nom court se résout désormais en un pool ordonné de
candidats `{backend, model}` avec dégradation transparente, les sessions
peuvent réellement être reprises, et chaque envoi est archivé. Additif et
non cassant — voir [docs/ai-dispatch-parity.md](docs/ai-dispatch-parity.md).

```bash
superaicore send opus "review the diff in HEAD~1" --cwd "$PWD" --json-result
superaicore resume --session-id <id> "question de suivi" --json-result
```

- **`superaicore send <cible> "<tâche>"`** — la cible est un alias (`opus`,
  `kimi`, `gemini-pro`, …), un nom de backend ou un id de modèle ;
  `AliasRouter` la résout (config utilisateur → registre intégré →
  passthrough → inférence) et les candidats sont essayés dans l'ordre. Les
  échecs quota / rate-limit / auth / réseau basculent au candidat suivant
  (`degraded: true` + `route_trace[]` complet) ; tout le reste échoue
  franchement. `--json-result` renvoie `ok / status / backend_used /
  model_used / route_trace / degraded / failure_class / session_id / run_id`.
- **`superaicore resume --session-id <id>`** — vraie continuation de session :
  `claude --resume` / `codex exec resume <thread_id>` ; le run store sait
  quel moteur possède la session, l'appelant n'envoie que le delta.
- **`superaicore runs list|show`** — archive des exécutions sur le système de
  fichiers (`~/.superaicore/runs`), sans base de données.
- **`superaicore aliases [cible]`** — inspecter ou résoudre le pool de
  routage ; extensible via `super-ai-core.dispatch.aliases`.
- **`superaicore preferences init|show|path`** — préférences scénario→modèle
  en langage naturel (`~/.superaicore/preferences.md`) lues par l'agent
  APPELANT avant de choisir une cible.
- **`superaicore skill:install-dispatch`** — installe la SKILL
  `superaicore-dispatch` dans les répertoires de skills des agents pour que
  des agents externes délèguent VERS SuperAICore (l'inverse de
  `superaicore:sync-cli`). Couvre `~/.claude/skills` / `~/.codex/skills` /
  `~/.gemini/skills`, et *(1.1.5)* `~/.grok/skills` /
  `~/.cursor/skills-cursor` / `~/.qwen/skills` ; par défaut claude seul,
  `--agent all` installe partout, `--uninstall` annule une installation sans
  toucher à vos propres skills *(1.1.5)*.
- **`superaicore doctor [--json]`** — diagnostic agrégé : moteurs, auth,
  backends, alias, préférences, archive des runs.

### Vague Fable 5 & Sonnet 5 (1.0.11 / SDK 1.1.5)

Le pin SDK passe de `^1.1.2` à `^1.1.5`. SuperAgent 1.1.5 fait atterrir
**Claude Fable 5** (`claude-fable-5`, le modèle le plus capable d'Anthropic) et
**Claude Sonnet 5** (`claude-sonnet-5`, le nouveau vaisseau amiral `sonnet`)
comme modèles `anthropic` de première classe, dote `AnthropicProvider` d'un
cadran `reasoning_effort` et corrige des tarifs Anthropic périmés ; SuperAICore
reflète les tarifs officiels dans sa propre table `model_pricing` et sème les
nouveaux ids dans le moteur `superagent`, pour que les tableaux de bord de
coûts et les sélecteurs restent exacts hors ligne. Additif et non cassant —
aucune migration, aucun changement de config.

- **Tarification native Fable 5 + Sonnet 5** *(1.0.11)* — `claude-fable-5`
  (contexte 1M, sortie max 128K, vision haute résolution, pensée adaptative
  permanente) au tarif officiel **10 $ en entrée / 50 $ en sortie** par 1M —
  au-dessus du palier Opus — et `claude-sonnet-5` (même surface adaptative de
  génération Claude 5, proche d'Opus 4.8 au prix Sonnet) à **3 $ / 15 $**
  (tarif de lancement 2 $/10 $ jusqu'au 2026-08-31 ; la table porte le tarif
  officiel). Les deux ids sont semés dans les `available_models` du moteur
  `superagent` pour apparaître dans les sélecteurs hors ligne.
- **Gamme Opus retarifée aux tarifs officiels** *(1.0.11)* — l'Opus courant
  (`claude-opus-4-5`→`4-8`) passe du périmé 15 $/75 $ à **5 $/25 $** par 1M ;
  seul l'instantané daté `claude-opus-4-20250514` garde l'historique
  15 $/75 $. Republiez la config si votre hôte en porte une copie plus
  ancienne, sinon `CostCalculator` continue de facturer Opus 3× trop cher.
- **Cadran `reasoning_effort` Anthropic** *(1.0.11)* — le SDK 1.1.5 fait
  implémenter `SupportsReasoningEffort` à `AnthropicProvider`, mappant
  l'option par appel vers le `output_config.effort` GA d'Anthropic
  (`low`/`medium`/`high`/`xhigh`/`max`) sur Fable 5 / Sonnet 5 / Opus 4.5+ /
  Sonnet 4.6 — les modèles non pris en charge et `off` ne produisent aucun
  `output_config`, donc un effort égaré ne provoque jamais de 400. Transite
  tel quel par `SuperAgentBackend`.
- **Surface adaptative-seule gérée côté SDK** *(1.0.11)* — Fable 5 / Sonnet 5
  émettent `thinking: {type: "adaptive"}` (jamais `budget_tokens`) et
  abandonnent `temperature`/`top_p`/`top_k` ainsi que les prefills assistant
  finaux ; les mêmes garde-fous corrigent des 400 latents qu'Opus 4.7/4.8
  rencontrait déjà. Le `anthropic` zéro-config résout désormais vers
  `claude-opus-4-8` ; le palier EXPERT du Squad SDK route vers
  `claude-fable-5` (la config `squad.tiers` propre à SuperAICore reste
  inchangée).
- **Tests Kiro rendus hermétiques** *(1.0.11)* — `KiroModelResolverTest` et le
  cas kiro d'`EngineCatalogTest` ne lisent plus le
  `~/.cache/superaicore/kiro-models.json` de la machine du développeur et ne
  sondent plus `kiro-cli` en direct ; un nouveau trait de test
  `IsolatesKiroCatalog` plus `KiroModelResolver::resetMemo()` les épinglent
  sur le fallback statique déterministe. Comportement en production inchangé.

### Vague vaisseau amiral natif GLM-5.2 (1.0.10 / SDK 1.1.2)

Le pin SDK passe de `^1.1.1` à `^1.1.2`. SuperAgent 1.1.2 promeut **GLM-5.2** au
rang de vaisseau amiral natif `glm` et dote `GlmProvider` d'un cadran
`reasoning_effort` ; SuperAICore reflète les tarifs officiels de Z.ai dans sa
propre table `model_pricing` et sème le nouvel id dans le moteur `superagent`,
pour que les tableaux de bord de coûts et les sélecteurs restent exacts hors
ligne. Additif et non cassant — aucune migration, aucun changement de config.

- **Tarification native GLM-5.2** *(1.0.10)* — `glm-5.2` (vaisseau amiral
  agentique orienté code de Z.ai : contexte 1M, sortie max 128K, texte
  uniquement) et `glm-5.1` (contexte 200K) au tarif PAYG officiel **1,40 $ en
  entrée / 4,40 $ en sortie** par 1M, avec un palier **0,26 $ en entrée
  cache-hit** (porté par `cache_read_input`) ; `glm-5` garde son ancien tarif
  1,00 $ / 3,20 $. `CostCalculator` retombe déjà sur le `ModelCatalog` du SDK ;
  ces lignes gardent simplement la comptabilité exacte hors ligne, et `glm-5.2`
  est aussi semé dans les `available_models` du moteur `superagent` pour
  apparaître dans les sélecteurs hors ligne.
- **Cadran `reasoning_effort` GLM-5.2** *(1.0.10)* — le SDK 1.1.2 fait
  implémenter `SupportsReasoningEffort` à `GlmProvider`, rejoignant MiniMax M3.
  L'option par appel `reasoning_effort` (`off` → pensée désactivée ; `low…high`
  → `reasoning_effort high` ; `max` → `reasoning_effort max`) et le simple
  interrupteur `thinking` transitent tels quels par `SuperAgentBackend` — ils
  étaient déjà transmis de façon générique, donc le cadran fonctionne dès que le
  SDK est en place.
- **Catalogue conservé** *(1.0.10)* — GLM-5.1 (longue portée, contexte 200K) et
  l'ancienne ligne `glm-5` restent joignables par id ; seuls le raccourci `glm`
  nu et le défaut zéro-config résolvent désormais vers GLM-5.2.

### Vague streamChat MCP (1.0.8)

`ClaudeCliBackend::streamChat()` peut désormais exposer à un tour de chat un
ensemble de serveurs MCP délimité par l'appelant. Avant 1.0.8, le chemin chat
codait en dur une config MCP vide verrouillée — alors que le chemin de
dispatch (`prepareScriptedProcess()`) supportait déjà `mcp_mode` ; 1.0.8
reflète ce contrat sur la méthode sœur. Additif et non cassant — le défaut
reste la surface vide verrouillée, aucune migration, pin SDK inchangé.

- **`mcp_mode: 'empty'|'file'|'inherit'`** *(1.0.8)* — défaut `'empty'` (le
  comportement pré-1.0.8, argv identique à l'octet près). `'file'` passe
  `mcp_config_file` (chemin JSON `{"mcpServers":{...}}`) en
  `--mcp-config <path> --strict-mcp-config`, exposant exactement ce
  sous-ensemble au tour ; `'inherit'` n'ajoute aucun flag MCP. `'file'` sans
  chemin utilisable retombe sur `'empty'` — n'hérite jamais silencieusement
  de toute la surface MCP de l'utilisateur.
- **`extra_cli_flags: string[]`** *(1.0.8)* — ajoutés tels quels ; échappatoire
  reflétant `prepareScriptedProcess()`.
- **`buildChatArgs()`** *(1.0.8)* — constructeur argv pur public extrait de
  `streamChat()` ; la matrice tools / MCP / modèle / flags extra est
  désormais testée unitairement sans spawn de processus.
- **Ajout automatique de ToolSearch** *(1.0.9)* — les CLI Claude actuels
  diffèrent les outils MCP derrière le méta-outil `ToolSearch` (les serveurs
  affichent « pending » à l'init et leurs outils sont absents de la liste
  initiale), et `--tools` restreint **toute** la surface d'outils — une
  allowlist sans ToolSearch rend donc chaque outil MCP inaccessible. Dès que
  la surface MCP effective est non vide (`'file'` avec chemin, ou
  `'inherit'`), `ToolSearch` est garanti dans l'allowlist ; les anciens CLI
  ignorent les entrées `--tools` inconnues, c'est donc sûr partout. Côté
  hôte : écrire un fichier de config sous-ensemble, passer
  `mcp_mode: 'file'`, et le modèle charge `mcp__<serveur>__<outil>` pour
  exactement les serveurs choisis — voir `docs/advanced-usage.fr.md` §12.

### Vague MiniMax M3 + retarification du catalogue (1.0.7 / SDK 1.1.1)

Le pin SDK passe de `^1.1.0` à `^1.1.1`. SuperAgent 1.1.1 introduit **MiniMax
M3** comme modèle natif de première classe et retarife le catalogue DeepSeek
V4 Pro / MiniMax aux tarifs réels des fournisseurs ; SuperAICore reflète ces
corrections dans sa propre table `model_pricing` et la graine du moteur, pour
que les tableaux de bord de coûts et les sélecteurs restent exacts hors ligne.
Additif et non cassant — aucune migration, aucun changement de config.

- **Tarification native MiniMax M3** *(1.0.7)* — `MiniMax-M3` (vaisseau amiral
  MSA : contexte 1M, sortie max 512K, entrée image/vidéo native, pensée
  entrelacée) au tarif PAYG standard **0,60 $ en entrée / 2,40 $ en sortie**
  par 1M, avec les lignes explicites `MiniMax-M2.7` / `M2.5` / `M2` (0,30 $ /
  1,20 $). `CostCalculator` retombe déjà sur le `ModelCatalog` du SDK ; ces
  lignes gardent simplement la comptabilité exacte hors ligne, et `MiniMax-M3`
  est aussi semé dans les `available_models` du moteur `superagent` pour
  apparaître dans les sélecteurs hors ligne.
- **DeepSeek V4 Pro retarifé** *(1.0.7)* — au tarif officiel actuel **0,435 $**
  en entrée (cache-miss) / **0,003625 $** en entrée (cache-hit,
  `cache_read_input`) / **0,87 $** en sortie par 1M, en baisse depuis les
  obsolètes 0,55 $ / 2,20 $. L'alias déprécié `deepseek-reasoner` (routé vers
  V4 Pro) suit le mouvement.
- **SmartFlow conservé** *(1.0.7)* — le pin 1.1.1 inclut toujours le moteur
  SmartFlow 1.1.0 auquel délègue le `SuperAgentFlowBridge` existant ; les flux
  cross-CLI qui se déploient vers `superagent` restent inchangés.

### Vague pont de skills CLI (1.0.6)

Un pont générique unique, sûr face aux symlinks et empreinté, qui diffuse la
bibliothèque de skills + agents d'un hôte vers la surface native de chaque CLI
backend — exactement la forme que `McpManager::syncAllBackends()` donne déjà au
MCP. Avant 1.0.6, chaque hôte bricolait son propre sync séparé par CLI ; 1.0.6
les unifie derrière un contrat + un service + une commande, plus un sync paresseux
au dispatch. Additif et non cassant : quand aucune `SkillLibrary` n'est bindée, le
pont est un no-op silencieux.

- **Contrat `SkillLibrary`** *(1.0.6)* — l'hôte implémente cinq méthodes
  (`skills()`, `agents()`, `skillWrapper($backend,$name)`,
  `instructionsDigest($backend)`, `fingerprint()`) et la bind
  (`$this->app->singleton(SkillLibrary::class, MyLibrary::class)`). SuperAICore
  sait OÙ / COMMENT / QUAND ; l'hôte fournit le QUOI. Aucune hypothèse d'hôte
  n'est figée dans le package.
- **Trois formes d'installation** *(1.0.6)* — `CliSkillBridge` diffuse la
  bibliothèque par backend : **`native_dir`** (codex / gemini / grok / cursor /
  qwen) dépose un répertoire de wrapper préfixé par skill dans le répertoire de
  skills du CLI ; **`instructions`** (copilot / kimi / kiro) écrit un unique
  fichier digest qui indique au modèle comment charger n'importe quel skill à la
  demande ; **`source`** (claude) lit `.claude/skills` directement et n'installe
  rien.
- **Sûr face aux symlinks** *(1.0.6)* — le pont **n'écrit jamais à travers un
  symlink**. Chaque répertoire de wrapper / `SKILL.md` / digest / manifeste est
  vérifié via `is_link()` et le lien périmé est délié (cible intacte) avant toute
  écriture — ce qui ferme la faille d'écriture-à-travers-symlink qui avait un jour
  écrasé des corps de skills source.
- **Sync paresseux au dispatch** *(1.0.6)* — chaque sync inscrit le
  `fingerprint()` de la bibliothèque dans un manifeste par backend
  (`.superteam-skill-sync.json`) ; `TaskRunner` réinstalle un backend avant un
  dispatch uniquement quand l'empreinte a dérivé, de sorte que le hot path est une
  seule comparaison de hash. Le pruning est borné au manifeste — il ne touche
  jamais aux skills propres à l'utilisateur.
- **`superaicore:sync-cli`** *(1.0.6)* — une seule commande propage toute la
  surface de capacités (skills + MCP) vers chaque CLI installé :
  `--skills-only` / `--mcp-only` / `--backends=codex,gemini` / `--project-root=`.
- **Correctif intégré** *(1.0.6)* — les runs `builtin` (abonnement/OAuth) sur les
  backends claude / codex / gemini / cursor / grok nettoient désormais toute clé
  console périmée héritée pour qu'elle ne puisse pas court-circuiter le login et
  provoquer un 401 ; le token OAuth Keychain de Claude est injecté comme
  `CLAUDE_CODE_OAUTH_TOKEN`, et non `ANTHROPIC_API_KEY`.

### Vague workflows cross-CLI SmartFlow (1.0.5 / SDK 1.1.0)

Le pin SDK passe à `^1.1.0`. SuperAICore 1.0.5 porte le moteur `Workflow`
intégré de Claude Code sous le nom **SmartFlow** — workflows dynamiques cross-CLI —
et le fédère avec le SmartFlow (cross-modèle) propre à superagent. Là où le
SmartFlow du SDK route un flow à travers les providers de modèles, celui de
SuperAICore le route à travers les **CLIs/backends** qu'il gère déjà, de sorte que
différents CLIs collaborent sur une même tâche. Additif et non cassant : le
Dispatcher, AgentSpawn et les orchestrateurs Squad/Team/Smart/Auto restent
intacts. Nouveau module `src/SmartFlow/`, nouvelle commande `superaicore flow`,
nouvelle doc `docs/smartflow.md`.

- **Un flow, plusieurs CLIs** *(1.0.5)* — les mêmes primitives
  (`agent()` / `parallel()` / `pipeline()` / `gate()` / `council()` / `budget` /
  `schema` / `SKIP`) pilotent n'importe quel backend enregistré, donc un flow peut
  planifier sur `claude_cli` et faire relire par `codex_cli` + `gemini_cli` en
  parallèle. `backend` est le levier cross-CLI sur chaque étape ; les `personas`
  réutilisables portent le system prompt et peuvent épingler un backend/modèle.
- **Filet de sécurité structuré à 3 couches** *(1.0.5)* — les CLIs renvoient de la
  prose, donc un `schema` est intégré au prompt et récupéré via les couches
  native → bloc balisé ```` ```json ```` → reniflage regex, validé par un
  `SchemaValidator` sans dépendance ; un échec total produit une sentinelle `SKIP`
  au lieu d'un crash.
- **Resume + journal d'appels** *(1.0.5)* — chaque exécution écrit un journal JSONL
  sous `~/.superaicore/flows` ; `--resume <id>` rejoue le plus long préfixe
  inchangé depuis le cache à coût nul (signatures adressées par contenu ; les
  gates restent alignées).
- **Vrai parallélisme** *(1.0.5)* — les lots `parallel()` / `pipeline()`
  s'exécutent comme sous-processus `bin/flow-agent-runner.php` concurrents
  (`proc_open` + `stream_select`, fallback polling sous Windows), avec dégradation
  en in-process quand c'est indisponible.
- **Répétition à coût nul** *(1.0.5)* — `flow run --rehearse` exécute n'importe
  quel flow de bout en bout sans aucun CLI invoqué (stubs déterministes conformes
  au schéma), donc les flows sont testables sur une machine vierge ; chaque flow
  intégré répète au vert.
- **Fédération avec superagent** *(1.0.5)* — `Flow::delegate()` (et
  `strategy: delegate` en YAML) confie un sous-flow au SmartFlow cross-modèle de
  superagent : le mode **named** exécute l'un des flows propres à superagent, qui
  se distribue lui-même à travers les providers ; le mode **spec** exécute un flow
  dont SuperAICore a écrit la structure, que superagent exécute selon
  l'instruction. La dépense déléguée se fédère dans le budget parent ; toute
  l'exécution imbriquée répète à coût nul.
- **4 flows cross-CLI intégrés + écriture YAML** *(1.0.5)* — `cross-cli-review`,
  `cross-cli-dev`, `cross-cli-council` et `cross-cli-federated` (qui délègue la
  recherche à superagent), compilés par `YamlFlowLoader` ; déposez les vôtres sous
  `./flows` ou `./.superaicore/flows`. Bloc de config
  `super-ai-core.smartflow.*`.

### Vague d'outils jcode (0.9.0 / SDK 0.9.7)

Cinq primitives inspirées de jcode livrées dans SuperAgent SDK 0.9.7 et
exposées par SuperAICore 0.9.0. Chacune est opt-in via env flag et dégrade
en no-op quand son câblage hôte est absent — comportement pré-0.9.7
strictement préservé sauf si vous activez le drapeau correspondant. Seul
`agent_grep` est **activé par défaut** (lecture seule, sans dépendance
externe). Contrainte Composer remontée à `^0.9.7`.

- **Outil `agent_grep` — activé par défaut** *(0.9.0)* — quand l'appelant ne fournit pas de `load_tools`, `SuperAgentBackend` ajoute automatiquement `'agent_grep'` à la liste de chargement (`AI_CORE_TOOLS_AGENT_GREP=true` par défaut). L'outil injecte le contexte du symbole englobant (PHP/JS/TS/Py/Go) dans chaque résultat de grep et tronque les chunks que l'agent a déjà vus dans la session. Sur-ensemble strict de `grep` ; ne se déclenche que sur les dispatches qui entrent réellement dans une boucle agentique avec outils. Mettez `AI_CORE_TOOLS_AGENT_GREP=false` pour un comportement octet-à-octet identique à 0.9.7.
- **Câblage de l'outil `browser`** *(0.9.0)* — `AI_CORE_TOOLS_BROWSER=true` fait que `SuperAgentBackend` instancie `FirefoxBridgeTool` du SDK 0.9.7 (pilote Firefox / Chromium via Native Messaging) et le `Agent::addTool()`. Nécessite que `SUPERAGENT_BROWSER_BRIDGE_PATH` pointe sur le launcher ; sinon chaque action renvoie une erreur explicative pour que l'agent arrête de boucler.
- **Boucle `BrowserScreenshotStore`** *(0.9.0)* — quand l'outil `browser` émet un PNG base64, `SuperAgentBackend` l'écrit dans `BrowserScreenshotStore` indexé par `process_id` / `external_label` / `metadata.session_id`, et expose l'URL sur l'envelope dispatch comme `latest_screenshot_url`. `AiProcessSource` lit `latest()` contre l'`external_label` de la ligne (puis l'id composite) en construisant `ProcessEntry`, et `purgeFor()` ces clés au reap. Boucle bouclée sans glue côté hôte. Disque + répertoire configurables via `super-ai-core.browser_screenshots`.
- **`SemanticSkillReranker` via SPI `EmbeddingProvider`** *(0.9.0)* — la passe sémantique optionnelle au-dessus du top-N BM25 de `SkillRanker` résout maintenant un `EmbeddingProvider` du SDK 0.9.7 via `EmbeddingProviderFactory` (`super-ai-core.embeddings.{provider,callback,ollama_url}`). Le reranker, le `SemanticSkillRouter` du SDK et tout `OnnxEmbeddingProvider` fourni par l'hôte partagent un singleton conteneur unique + un cache unique. Un échec par ligne (vecteur `[]`) garde le score BM25 pour ce hit au lieu de planter tout l'appel. Retombe sur l'ordre BM25 quand aucun embedder n'est configuré.
- **Découpage `usage_source` pour l'attribution des coûts** *(0.9.0)* — `Dispatcher::resolveUsageSource()` promeut `options['usage_source']` / `options['metadata']['usage_source']` en clé top-level `metadata.usage_source` (défaut `'user'`). `/usage` gagne une carte « By Source » avec un badge « N ambient · $X » pour que les ticks de dédoublonnage / staleness étiquetés par l'`AmbientWorker` de SuperAgent soient visibles d'un coup d'œil sans réinstrumenter le code de coûts hôte.
- **Reprise de session cross-harness** *(0.9.0)* — `HarnessSessionResolver` enveloppe la famille `Conversation\HarnessImporter` du SDK 0.9.7 (`ClaudeCodeImporter` lit `~/.claude/projects/<hash>/<uuid>.jsonl`, `CodexImporter` lit `~/.codex/sessions/**/*.jsonl`). `/processes` gagne une liste déroulante « Resume from… » + modal de transcription gardé par `super-ai-core.resume.enabled`. L'hôte câble `super-ai-core.resume.on_load` (callable) pour réellement re-dispatcher dans un backend ; sinon le modal montre la transcription inline pour inspection.

Recettes complètes (câblage Ollama, mise en place launcher browser, boucle
tick AmbientWorker, callback de reprise harness) : [docs/advanced-usage.fr.md
§17–§21](docs/advanced-usage.fr.md).

### Vague d'alignement DeepSeek-TUI (0.9.1 / SDK 0.9.8)

Cinq bindings compagnons SDK 0.9.8 ont atterri dans SuperAICore 0.9.1,
plus un correctif de durcissement du backend. Contrainte Composer
remontée à `^0.9.8`. Aucune des nouvelles pièces SDK
(`Goals\GoalManager`, `Security\UntrustedInput`, `Swarm\AgentDepthGuard`,
`Providers\Transport\TokenBucket`, `Conversation\Fork`,
`Memory\AdHocMemoryProvider`, l'enforcement V4 Interleaved-Thinking de
DeepSeek, `Routing\AutoModelStrategy`,
`Context\Strategies\CacheAwareCompressor`) ne change la forme d'appel
SDK — toutes sont additives et opt-in.

- **`Goals\EloquentGoalStore` + modèle `AiGoal` + migration** *(0.9.1)* — backend persistant pour le SPI `Goals\Contracts\GoalStore` du SDK 0.9.8. Chaque thread peut avoir au plus une ligne en statut non-terminal (`active` / `paused` / `budget_limited`) ; les goals en pause restent en pause après redémarrage du processus hôte. Le service provider lie `GoalStore::class → EloquentGoalStore::class` et enregistre `GoalManager` en singleton, donc `app(GoalManager::class)` se résout avec le store persistant injecté automatiquement. Les hôtes qui gèrent déjà les goals dans leur propre table substituent leur implémentation `GoalStore` — pas de fork. `php artisan migrate` pour la table `ai_goals` ; si vous n'utilisez pas `Goals\GoalManager`, le binding reste inerte.
- **Portail d'approbation à trois niveaux** *(0.9.1)* — `Runner\ApprovalMode` (`Auto` / `Suggest` / `Never`) + `ApprovalGate` + `ApprovalDecision` reflètent la commande `/permissions` de codex. Allowlist en lecture seule (`agent_grep` / `agent_glob` / `agent_read` / `agent_ls` / `web_search` / `web_fetch` / `agent_get_goal`) passe dans tous les modes. Les mutations en `Suggest` retournent `canRetry: true` avec code `mutation_pending_approval` (ou `destructive_pending_approval` quand le `Guidance\Gates\DestructiveCommandScanner` existant signale l'appel) ; un token override `tool_use_id` à usage unique débloque un retry — flux `/approve` de codex porté en forme API. Le mode `Auto` laisse passer les mutations ordinaires mais s'arrête toujours pour `/approve` sur les opérations destructives ; `Never` est en lecture seule. Résolution via `app(ApprovalGate::class)`.
- **`Plugins\WorkspacePluginRegistry`** *(0.9.1)* — pattern « workspace plugin sharing » de codex. Une équipe commit `.superaicore/workspace-plugins.json` dans le repo ; le registry diffe contre les noms de plugins installés localement et retourne `missing_required` (scope=`workspace`, à installer chez tout le monde) vs `missing_recommended` (scope=`user`, informationnel). `git clone` met les nouveaux arrivants sur l'outillage complet de l'équipe sans doc d'onboarding par machine. Lié en singleton sur `base_path()`.
- **Endpoint JSON headless `GET /v1/usage`** *(0.9.1)* — `Http\Controllers\UsageApiController` reflète la forme `/v1/usage` de l'app-server codex. Un axe par requête : `group_by=day | model | provider | thread | backend | task_type`. Mêmes filtres que le contrôleur HTML (`model`, `task_type`, `user_id`, `backend`, `days`). L'auth est l'affaire de l'hôte — encapsulez le groupe de routes dans votre middleware. Les buckets portent `runs / cost_usd / shadow_cost_usd / input_tokens / output_tokens / cache_read_tokens / cache_hit_rate`.
- **`metadata.cache_hit_rate` sur chaque ligne d'usage** *(0.9.1)* — `UsageRecorder` estampille `cache_hit_rate ∈ [0, 1]` dès qu'une ligne porte une part de cache non nulle. Le dénominateur est le prompt BRUT (input non caché + lectures cache) pour que les tableaux de bord regroupent par modèle / jour / backend et fassent la moyenne sans redériver le dénominateur. Absent quand aucune activité cache n'a eu lieu — distingue « pas de cache éligible » de « 0% de hit rate ». Accepte aussi l'alias legacy `cache_hit_tokens` des wires DeepSeek V3 / R1. La page `/usage` répond maintenant à « quelle fraction de mon prompt payé était gratuite cette période ? » — la même question que pose DeepSeek-TUI à la fin de chaque tour, en agrégé. Nouvelle carte sommaire `total_cache_read_tokens`.

Recettes complètes (override de GoalStore, câblage du portail
d'approbation, manifeste de plugin workspace, recette `/v1/usage`,
tableaux de bord cache-hit-rate) : [docs/advanced-usage.fr.md
§22–§26](docs/advanced-usage.fr.md).

### Vague de fiabilité TaskRunner (0.9.2)

Les longues tâches opérateur peuvent maintenant changer de backend quand
le CLI/API primaire atteint une limite de quota ou de débit. 0.9.2 le
traite comme une couche de fiabilité TaskRunner : chaînes explicites/auto,
handoff du contexte d'échec, rapport de tentatives, hooks de persistance UI
et frontières de retry sûres. Le fallback est par run : le backend demandé
est toujours essayé en premier, donc un primaire rétabli reprend
naturellement le trafic.

- **Chaînes explicites** — passez `fallback_chain` comme
  `['claude_cli', 'codex_cli', 'gemini_cli']`; TaskRunner préfixe le
  backend demandé s'il manque et déduplique la chaîne.
- **Politiques workload** — passez `fallback_profile` ou laissez
  `task_type` / `capability` résoudre `chains_by_profile`,
  `chains_by_task_type` ou `chains_by_capability` depuis la config.
- **Chaînes automatiques** — `fallback_chain => 'auto'` construit la
  chaîne depuis les backends enregistrés/activés, avec checks
  d'availability optionnels via
  `AI_CORE_TASK_FALLBACK_CHECK_AVAILABILITY=true`.
- **Handoff conscient des limites** — `fallback_on` couvre par défaut
  les signaux quota/rate-limit (`rate limit`, `usage limit`, `quota`,
  `429`, `too many requests`, `usage_not_included`). Les échecs non
  correspondants restent sur le backend d'origine.
- **Héritage du contexte d'échec** — le backend suivant reçoit le prompt
  original plus un court extrait sortie/log, sauf
  `inherit_failure_context=false`.
- **`TaskResultEnvelope::$fallbackReport`** enregistre chaque tentative
  (backend, numéro, succès, exit code, modèle, fichier log, erreur).
- **Politique par workload** — les hôtes peuvent garder des chaînes
  distinctes pour code, recherche/synthèse et maintenance de fond au lieu
  d'une règle de retry globale pour tous les types de tâches.
- **Observabilité opérateur** — le rapport compact et les metadata Dispatcher
  par tentative peuvent être stockés sur les lignes de tâche ou d'usage et
  rendus comme « primary limited, continued on codex », avec liens directs
  vers les logs par tentative.
- **Analytics de fiabilité** — combinez `fallbackReport` avec
  `ai_usage_logs.backend` pour trouver les primaires qui touchent souvent le
  quota et les secondaires qui terminent réellement le travail.
- **Déploiement sûr** — commencez par des chaînes par appel, promouvez les
  politiques stables en config, puis activez le fallback automatique après
  revue de l'availability et de la facturation.

Les defaults globaux vivent sous `super-ai-core.task_fallback`; les env
sont `AI_CORE_TASK_FALLBACK_AUTO`, `AI_CORE_TASK_FALLBACK_CHAIN`,
`AI_CORE_TASK_FALLBACK_CHECK_AVAILABILITY` et
`AI_CORE_TASK_FALLBACK_INHERIT_CONTEXT`. Voir
[docs/advanced-usage.fr.md §27](docs/advanced-usage.fr.md) et
[docs/task-runner-quickstart.md](docs/task-runner-quickstart.md).

### Vague Squad multi-agent + SDK 1.0.0 (0.9.6)

La contrainte SDK passe à `^1.0`. SuperAICore 0.9.6 livre le pipeline
de peer-collaboration `Squad` du SDK 1.0.0 comme dixième adaptateur
Dispatcher et enveloppe les primitives compagnons SDK 0.9.8
(`AutoModelStrategy`, `CacheAwareCompressor`, `UntrustedInput`,
`TokenBucket`, `AdHocMemoryProvider`, `Conversation\Fork`,
`AgentDepthGuard`, DeepSeek FIM) derrière des services hôtes
first-class adressables depuis n'importe quel chemin de dispatch.
Chaque binding est additif et opt-in — le comportement pré-0.9.6 est
préservé tant que vous n'activez pas un flag, ne passez pas une
nouvelle option ou ne résolvez pas un nouveau service depuis le
container. Aucune migration.

- **`SquadBackend` — pipeline cross-modèle adaptatif SDK 1.0.0**
  (0.9.6) — enregistré comme dixième adaptateur Dispatcher quand
  `super-ai-core.squad.enabled=true` et que les classes SDK 1.0.0
  sont sur le classpath. Pilote un pipeline heuristiquement décomposé
  via `Squad\TaskDecomposer` + `Squad\PeerOrchestrator`, avec un
  modèle par sous-tâche (mappé par `Squad\ModelTierMap`), des
  écritures `SquadCheckpointStore` par étape, du messaging peer-à-peer
  via le `PeerMailbox` du SDK et un plafond de coût optionnel avec
  downshift automatique à 80% du budget. Les échecs en cours
  laissent le checkpoint sur disque ; reprenez en redispatchant avec
  le même `squad_id` et `checkpoint_dir`. L'enveloppe porte
  `squad: {squad_id, step_count, completed, roles, checkpoint_path,
  mailbox_log}`. Le tier map est livré avec des defaults sensés
  (`trivial` → `claude-haiku-4-5`, `easy` → `deepseek-v4-flash`,
  `moderate` → `claude-sonnet-4-6`, `hard` → `deepseek-v4-pro`,
  `expert` → `claude-opus-4-7`) ; override par appel via
  `options.tier_map` ou globalement via
  `super-ai-core.squad.tier_map`.
- **Service `AutoModelRouter`** (0.9.6) — heuristique `/model auto`
  pour tout chemin de dispatch. Enveloppe SDK 0.9.8
  `Routing\AutoModelStrategy` pour que les backends CLI Claude /
  Codex / Gemini puissent opter pour le routage Pro/Flash dès que
  leur `provider_config` déclare `auto_models: {pro, flash}`.
  Escalade Flash → Pro sur long contexte (>32k tokens), profondeur
  de tool chain trainante (≥3), `reasoning_effort=max` explicite ou
  mots-clés d'intention dans le system prompt
  (review/audit/design/migration/architecture/…). Quand
  `super-ai-core.auto_model.score_catalog_path` est câblé, le modèle
  top-scoré du catalogue l'emporte sur l'heuristique. Re-mappez
  Pro/Flash à n'importe quelle paire (e.g. `claude-opus` /
  `claude-haiku`) via `auto_model.{pro_model, flash_model}` — sans
  forker le SDK.
- **`CompressionStrategyFactory`** (0.9.6) — compaction cache-aware
  pour les hôtes qui pilotent leur propre `ContextManager`. Enveloppe
  le `ConversationCompressor` standard dans le
  `CacheAwareCompressor` SDK 0.9.8 pour que les frontières de summary
  atterrissent APRÈS le préfixe de prompt cache au lieu de le
  clobber. Les hôtes qui font tourner des longues sessions multi-tour
  (boucles sous-agent, sessions browser-tool, refactos multi-étapes)
  appellent
  `app(CompressionStrategyFactory::class)->build($estimator, $config, $provider)`
  en construisant leur propre `ContextManager`. Pin 1 message system
  + 4 messages de conversation par défaut.
- **`UntrustedInputHelper`** (0.9.6) — wrapper hôte
  `Security\UntrustedInput` pour le texte libre injecté dans les
  system prompts. Le `GoalManager` du SDK enveloppe déjà
  `goal.objective` ; cet helper couvre les autres sites — entrées de
  mémoire ad-hoc, descriptions de workspace plugin, docs d'outil MCP
  importées de serveurs tiers, input de formulaire UI hôte. Deux
  méthodes : `tag()` ajoute le marqueur ; `wrap()` ajoute le préambule
  « traiter comme données, pas comme instructions ». Désactivable via
  `AI_CORE_UNTRUSTED_INPUT=false` pour les tests qui comparent les
  prompts byte-à-byte.
- **`RateLimiterRegistry`** (0.9.6) — pool de token-bucket per-process
  enveloppant SDK 0.9.8 `Providers\Transport\TokenBucket`.
  `SuperAgentBackend` et `SquadBackend` appellent `consume()` avant
  chaque dispatch provider. Les clés manquantes retombent sur
  `default` (8 RPS / 16 burst) ; les overrides par provider vont
  dans `super-ai-core.rate_limits.<provider>`. Une config vide
  désactive entièrement le rate limiting — le SDK conserve son retry
  per-call 429.
- **`AdHocMemoryRegistry`** (0.9.6) — pool per-session
  `Memory\AdHocMemoryProvider`. Les UI chat appellent
  `forSession($id)->push($text, $ttlSeconds)` (ou le shortcut
  `$registry->push($id, $text, $ttl)`) pour injecter un fait « pour
  le prochain tour » que le backend SuperAgent rend avant le prompt.
  L'isolement per-session prévient toute fuite cross-chat. Mémoire
  process-local — les faits durables vont dans `MEMORY.md` /
  `BuiltinMemoryProvider`.
- **`ConversationForkService`** (0.9.6) — sémantique codex `/side`
  par-dessus SDK 0.9.8 `Conversation\Fork`. `start($parentMessages)`
  snapshot la liste et retourne un fork handle ;
  `finish($fork, $action, $indexes?)` collapse avec `discard` /
  `promote(...indexes)` / `promoteAll`. Utile pour les UI chat qui
  veulent « brancher et essayer un modèle différent à côté, ne
  promouvoir que les messages utiles ».
- **`DeepSeekFimService`** (0.9.6) — wrapper standalone autour de
  SDK 0.9.8 `DeepSeekProvider::completeFim()` contre la région
  `beta`. L'abstraction chat-shape `Backend` ne convient pas pour
  FIM, donc les hôtes qui bâtissent des features de complétion IDE-
  style appellent ce service directement :
  `app(DeepSeekFimService::class)->complete($prefix, $suffix,
  ['max_tokens' => 64])`.
- **Cadran trois niveaux `reasoning_effort` sur `SuperAgentBackend`**
  (0.9.6) — `reasoning_effort: 'off' | 'high' | 'max'` par appel
  transmis comme option per-call SDK `reasoning_effort`. Route vers
  la bonne forme de body selon l'upstream via l'interface
  `SupportsReasoningEffort` du SDK. Silencieusement ignoré par les
  providers qui ne l'implémentent pas. Nourrit aussi l'heuristique
  d'escalation `AutoModelRouter` quand mis à `max`.
- **Handoff `Agent::switchProvider()`** (0.9.6) — passez
  `options.handoff: {provider, config, policy}` et
  `SuperAgentBackend` appelle `Agent::switchProvider()` avant le
  dispatch. L'enveloppe gagne `handoff_token_status: {tokens, window,
  fits, model}` pour que les dashboards puissent avertir
  « l'historique ne tient pas sous <target_model> — compresser avant
  le prochain tour ». Échec de construction du nouveau provider →
  l'agent original reste utilisable.
- **Commandes console `smart` / `squad`** (0.9.6) — passthrough vers
  vendor `superagent smart` / `superagent auto --squad`. Réutilise
  les credentials SuperAgent existantes de l'opérateur et le
  comportement de la CLI SDK plutôt que de réimplémenter
  l'orchestrateur en PHP :
  ```bash
  ./vendor/bin/superaicore smart "audite ce diff"
  ./vendor/bin/superaicore smart show --last
  ./vendor/bin/superaicore squad "refactore le module auth" --max-cost=2.0
  ./vendor/bin/superaicore squad --no-squad "compare avec le chemin legacy"
  ```
- **`super-ai-core.agents.max_depth`** (0.9.6) — transmis à SDK 0.9.8
  `Swarm\AgentDepthGuard::setMax()` pendant le boot du service
  provider. Valeur négative / non définie → default SDK (5).
  Override per-process : variable env `SUPERAGENT_MAX_AGENT_DEPTH`.

Recettes complètes (pipelines Squad, intégration AutoModelRouter,
câblage CacheAwareCompressor, overrides RateLimiterRegistry,
intégration UI chat AdHocMemoryRegistry, side-panels
ConversationForkService, endpoints de complétion DeepSeek FIM) :
[docs/advanced-usage.fr.md §28](docs/advanced-usage.fr.md).

### Vague de fonctionnalités inspirées d'opencode (0.9.7 / SDK 1.0.5)

La contrainte SDK passe de `^1.0` à `^1.0.5`, intégrant les correctifs
de transcoder pour le handoff cross-provider, le matching de permissions
opencode `BashArity`, le template de résumé compacté à 7 sections,
le vrai client LSP du SDK (`LSPTool`), la détection sémantique de
boucle `LlmLoopChecker`, le serveur ACP v1 stdio, et la famille
Gemini 3.5 / 3.x avec thinking + grounding + thought-parts. Sur ce
bump SDK, dix patterns d'[opencode](https://github.com/sst/opencode)
(`packages/opencode/src/`) sont portés et exposés comme fonctionnalités
de première classe. Lancez `php artisan migrate` après mise à jour —
0.9.7 livre 3 nouvelles tables + 3 nouvelles colonnes sur
`ai_usage_logs`.

- **Résumé de diff par fichier sur chaque dispatch** (0.9.7) —
  `SuperAgentBackend` photographie le worktree avant et après chaque
  appel via le `GitShadowStore` du SDK, puis
  `Services\SnapshotDiffService` produit un envelope structuré
  `{additions, deletions, files, diffs[]}` où chaque diff porte
  `{file, additions, deletions, status, patch, truncated}`. Persisté
  sur `ai_usage_logs.file_diff_summary` à côté des deux hashs de
  snapshot (`pre_snapshot`, `post_snapshot`). La page `/usage`
  affiche un badge `+N −M` par ligne + un side-panel pour visualiser
  le diff. Modélisé sur opencode `session/summary.ts` +
  `snapshot.diffFull()`.
- **Outil HITL `ask_user` mid-run** (0.9.7) —
  `Services\Tools\AskUserTool` (opt-in via
  `AI_CORE_TOOLS_ASK_USER=true`) permet à l'agent d'interrompre et
  poser une question de clarification à l'opérateur avec options
  prédéfinies. Les lignes atterrissent dans la nouvelle table
  `ai_user_questions` et sont rendues comme cartes inline sur
  `/processes` (polling 4s). Modélisé sur opencode `tool/question.ts`.
  Endpoints : `/processes/questions{,/{id}/answer,/{id}/cancel}`.
- **Revert du worktree au snapshot pré-dispatch** (0.9.7) —
  `POST /usage/{id}/revert` lit `pre_snapshot` sur la ligne UsageLog
  et restaure le worktree via le `GitShadowStore::restore()` du SDK.
  Les fichiers trackés sont restaurés ; les fichiers non-trackés sont
  laissés en place. Gardé par `AI_CORE_SNAPSHOT_REVERT_ENABLED`
  (défaut on). La page `/usage` affiche un bouton ↩ sur chaque ligne
  ayant un snapshot.
- **Rétention shadow-git** (0.9.7) —
  Commande Artisan `super-ai-core:snapshot-prune` parcourt chaque
  `shadow.git` sous `~/.superagent/history/`, expire le reflog des
  commits plus vieux que `--days` (défaut 7), puis lance
  `git gc --prune=now`. Supporte `--dry-run`. À programmer depuis
  `Kernel.php` :
  `$schedule->command('super-ai-core:snapshot-prune')->daily()`.
  Modélisé sur la politique `prune = "7.days"` d'opencode.
- **Rappels de session par injection synthétique** (0.9.7) —
  `Services\RemindersResolver` lit
  `super-ai-core.reminders.rules` et préfixe des blocs synthétiques de
  system prompt quand le prédicat `when` (chemins en notation
  pointée → globs fnmatch contre options/metadata) correspond.
  Modélisé sur opencode `session/reminders.ts`.
- **Ruleset de permissions par agent** (0.9.7) —
  `Services\PermissionEvaluator` porte opencode
  `permission/evaluate.ts` (règles `{permission, pattern, action}`,
  last-match-wins, wildcards fnmatch, action par défaut `ask`).
  Configurez par agent dans `super-ai-core.agents.{name}.permission` ;
  `SuperAgentBackend` projette le ruleset sur le
  `withAllowedTools()` / `withDeniedTools()` de l'agent SDK quand
  l'appelant n'a pas passé de listes explicites.
- **Mode plan (`Modes\CliPlanOrchestrator`)** (0.9.7) —
  workflow en trois phases plan → approve → build. Phase 1 dispatche
  une run plan-only (outils d'édition refusés sauf pour le fichier
  plan) et écrit un markdown plan dans
  `.superagent/plans/{session}.md`. Phase 2 ouvre une ligne
  `ai_user_questions` avec `[Approve, Reject]`. Phase 3 délègue au
  build backend avec un prompt synthétique contenant le plan
  approuvé. Enregistré avec `CliModeRouter` sous le nom de mode
  `plan`. Auto-approuve quand HITL est désactivé pour rester
  utilisable en CI. Config : `super-ai-core.modes.plan.*`. Modélisé
  sur opencode `agent/agent.ts` + `tool/plan.ts`.
- **Dérivation de permissions sous-agent** (0.9.7) —
  `Services\SubagentPermissionDeriver` fusionne les `denied_tools` du
  parent dans ceux de l'enfant pour qu'un parent read-only produise
  toujours des enfants read-only. Lit
  `options['parent_denied_tools']` (explicite) ou
  `options['metadata']['parent_agent']` (résolu via le
  `PermissionEvaluator`). Modélisé sur opencode
  `agent/subagent-permissions.ts`.
- **Sessions shell PTY long-terme, Phase 1** (0.9.7) —
  `Services\PtyService` + `Http\Controllers\PtyController`
  spawnent des sessions `proc_open` et streamment stdout via
  long-poll par cursor. Endpoints :
  `POST /pty/sessions` (spawn),
  `GET /pty/sessions/{id}/poll?cursor=N` (poll),
  `POST /pty/sessions/{id}/kill` (terminer). Opt-in via
  `AI_CORE_PTY_ENABLED=true`. Phase 2 (différée) passera le transport
  à WebSocket via Reverb / Soketi sans changer le protocole cursor.
- **File d'attente hôte pour partage de session** (0.9.7) —
  `Services\ShareSessionService` génère un triplet `{share_id,
  secret, share_url}` par session et POST les lignes UsageLog + les
  payloads `file_diff_summary` attachés vers un sharer distant
  configurable (`AI_CORE_SHARE_REMOTE_URL`) avec un Bearer token. Se
  rabat sur un template d'URL local
  (`AI_CORE_SHARE_LOCAL_URL_TEMPLATE`, avec placeholder
  `{share_id}`) si aucune URL distante n'est configurée. Modélisé sur
  opencode `share/share-next.ts`.
- **Outil LSP SDK 1.0.5** (0.9.7) — opt-in via
  `AI_CORE_TOOLS_LSP=true` ; `SuperAgentBackend` ajoute `lsp` au
  `load_tools` implicite. L'agent récupère le client LSP intégré du
  SDK (phpactor / intelephense / gopls / rust-analyzer / pyright /
  tsserver / clangd / bash-language-server / zls). Chargé en lazy par
  le `BuiltinToolRegistry` classMap du SDK.
- **Résumé compacté structuré (opencode)** (0.9.7) — réglez
  `AI_CORE_COMPRESSION_SUMMARY_PROMPT=structured` pour activer le
  template Markdown 7 sections du SDK sur chaque dispatch
  (Goal / Constraints / Progress / Decisions / Next Steps / Critical
  Context / Relevant Files). ~30-50% plus court que le résumé 9
  sections par défaut et préserve l'état blocked entre compactions.
  `options['summary_prompt']` per-call l'emporte.
- **Gemini 3.5 thinking + grounding + URL context** (0.9.7) —
  les options per-call `thinking`, `grounding` / `google_search`, et
  `url_context` passent directement au `GeminiProvider` du SDK
  (ignorées silencieusement par les autres providers).
  `EngineCatalog` liste désormais `gemini-3.5-pro / -flash /
  -flash-lite` pour le moteur gemini-cli ; `CopilotModelResolver`
  reçoit un alias famille `gemini` résolvant vers
  `gemini-3-pro-preview`.

Recettes complètes (tableau de bord diff par fichier, intégration
AskUserTool, workflow mode plan, rappels de session, permissions par
agent, sessions PTY, partage de session) :
[docs/advanced-usage.fr.md §29](docs/advanced-usage.fr.md).

### Vague Qwen + traçage + 9Router (0.9.8)

Le huitième moteur d'exécution, un ring-buffer de traçage Dispatcher
toujours actif (lisible dans `chrome://tracing` / `ui.perfetto.dev`,
auto-dump sur quota / résultat nul / auto-rotation), un proxy
OpenAI-compatible à `/super-ai-core/v1/chat/completions`, un
round-robin multi-comptes avec cooldowns, le vrai streaming SSE sur
les trois backends HTTP, des rafraîchisseurs OAuth pré-emptifs pour
Claude / Codex / Copilot / Kiro, le branchement d'arbre de session
style Pi, un index de skills à divulgation progressive pour les CLI
non-skill-native, un exporteur JSONL pi v3, et un moteur de réaction
GitHub PR / CI `gh-watch`. **La contrainte SDK passe à `^1.0.6`** —
intègre le vrai `RtkPipeline` (6 compresseurs intégrés), le hook
`Hooks\HookEvent::PR_EVENT` (déclenché automatiquement par
`gh-watch`), le contrôle mid-turn `Agent::steer()` / `followUp()`
(exposé via les options de `SuperAgentBackend`), et le provider SDK
`qwen-anthropic` (nouveau `AiProvider::TYPE_QWEN_ANTHROPIC` pour
l'endpoint Anthropic-protocol de DashScope — substitut direct de Claude).

- **Qwen Code CLI comme 8ème moteur (`qwen_cli`)** *(0.9.8)* — fork
  de `gemini-cli` adapté à la famille Qwen d'Alibaba. Implémente
  `Backend`, `StreamingBackend` et `ScriptedSpawnBackend` donc
  s'intègre dans tous les chemins de dispatch existants. Auth par
  clé API uniquement (`DASHSCOPE_API_KEY` / `QWEN_API_KEY`) ; OAuth
  EOL'd le 2026-04-15. Modèle par défaut `qwen3.7-max` (1M de
  contexte, $2.50/$7.50 par 1M, protocole Anthropic
  `/v1/messages` natif — substitut direct de Claude dans les chaînes
  de fallback). Activation via `AI_CORE_QWEN_CLI_ENABLED`.
- **Ring-buffer de traçage Dispatcher (`Tracing\TraceCollector`)**
  *(0.9.8)* — ring lock-free toujours actif des événements `llm` /
  `cache` / `provider` / `tool` / `error`. ~150 KB à 1024 événements ;
  zéro coût filesystem quand désactivé. Auto-dump en JSON Chrome Trace
  Event (lisible dans `chrome://tracing`, `https://ui.perfetto.dev`
  ou le `trace-viewer.html` fourni) sur les triggers `error` /
  `rotate` / `timeout`. `SuperAgentBackend` auto-dump avec
  `trigger=rotate` sur `quota_exceeded` / `usage_not_included` /
  `server_overloaded` / `cyber_policy` pour que le post-mortem
  capture l'enveloppe défaillante. Flush manuel :
  `php artisan dispatcher:dump-trace`. UI : `/super-ai-core/traces`.
- **Proxy OpenAI-compatible** *(0.9.8)* —
  `Http\Controllers\OpenAiCompatibleController` expose
  `GET /v1/models` + `POST /v1/chat/completions` (streaming +
  non-streaming). `model` accepte soit un id littéral, soit un
  `ai_routing_combos.name`, donc Cursor / Cline / Roo / Kiro /
  continue.dev / le SDK OpenAI s'intègrent sans modification. Chunks
  de streaming formatés exactement comme OpenAI.
- **Vrai streaming SSE sur les trois backends HTTP** *(0.9.8)* —
  `AnthropicApiBackend`, `OpenAiApiBackend`, `GeminiApiBackend`
  implémentent la nouvelle interface `Contracts\StreamableTextBackend`,
  yieldent des enveloppes canoniques
  (`{type:'text'|'thinking'|'tool_use_delta'|'usage'|'stop'}`).
  Le proxy OpenAI-compat les consomme directement.
- **Combos de routing nommés (`ai_routing_combos`)** *(0.9.8)* —
  un combo est une liste ordonnée `[{provider, model}, ...]` résolue
  au dispatch. Se place au-dessus du `tier_map` statique. CRUD :
  `/super-ai-core/routing/combos[/{name}]`. Surcharge par appel via
  `--combo=NAME` sur `smart` / `squad` / `auto`.
- **Round-robin multi-comptes (`AccountRoundRobin`)** *(0.9.8)* —
  choisit le compte actif non cooled-down avec le tuple
  `(priority, last_used_at)` le plus bas via un compare-and-update
  atomique. `cooldown()` met les comptes en pause 10 min sur
  `QuotaExceededException` / résultat nul. Adossé à la nouvelle table
  `ai_provider_accounts`.
- **Registre de rafraîchisseurs OAuth** *(0.9.8)* — refresh
  pré-emptif de tokens pour les quatre CLI qui possèdent leur état
  OAuth dans un JSON local (Claude / Codex / Copilot / Kiro). Pilote
  via `php artisan super-ai-core:oauth-refresh` ; planifiez depuis
  `app/Console/Kernel.php` avec `->everyTenMinutes()`.
- **Branchement d'arbre de session style Pi** *(0.9.8)* —
  `Services\SessionBranchManager` + table `ai_session_branches`.
  Forker crée une nouvelle branche depuis une entrée ancienne ;
  basculer auto-résume la branche abandonnée pour que le contexte ne
  soit pas perdu. Endpoints : `/sessions/{session}/tree`,
  `/sessions/{session}/fork`, `/sessions/{session}/switch`.
- **Index de skills à divulgation progressive** *(0.9.8)* —
  `Services\SkillIndexBuilder` émet un index XML compact de chaque
  `SKILL.md` (name + description, sans corps) que `CodexCliBackend` /
  `GeminiCliBackend` pré-injectent à chaque prompt. Le modèle lit le
  corps via son outil de lecture de fichiers existant uniquement
  lorsqu'il pioche un skill. Permet aux CLI non-skill-native
  d'utiliser le catalogue de skills SuperAICore au même coût que le
  protocole natif de Claude. Désactivez par appel avec
  `options['skills_disabled']=true` ou `--no-skills`.
- **Discriminateur `kind` Pi sur `ask_user`** *(0.9.8)* — `select` /
  `confirm` / `input` / `editor` pour que l'UI
  `/processes/questions` rende le bon widget par appel (défaut
  `select` préserve le comportement 0.9.7).
- **Mode Caveman (`--caveman`)** *(0.9.8)* — rappel de compression de
  tokens en sortie porté depuis 9Router. Empiriquement 30-65% de
  tokens en sortie économisés sur les tâches de raisonnement rapide
  (pas pour l'écriture longue forme).
- **Watcher PR / CI GitHub (`super-ai-core:gh-watch`)** *(0.9.8)* —
  pattern claude-octopus. Polle chaque ligne `ai_pr_watchers` active
  (cache ETag), déclenche les actions par ligne (`ask_user` /
  `spawn_squad` / `webhook` / `log`). Planifiez via
  `->everyFiveMinutes()` ou démonisez avec `--loop=30`.
- **Exporteur JSONL de session pi v3** *(0.9.8)* —
  `php artisan task-results:export-jsonl` émet un fichier par
  `metadata.session_id`. Opt-in via `--i-understand` (le format est
  avec pertes) ; supporte `--anonymize`, `--since`.
- **Round-trip Apache Arrow tabulaire (`Arrow\ArrowSerializer`)**
  *(0.9.8)* — writer de stream Arrow IPC minimal (sans dépendance
  PECL `apache/arrow`). Opt-in par dispatch avec
  `output_format: 'arrow'` ; l'enveloppe reçoit un stream Arrow
  base64-encodé. 10–100× plus rapide que JSON pour les charges
  tabulaires larges entre agents.
- **Navigateur d'agents SuperTeam (`/super-ai-core/agents`)**
  *(0.9.8)* — lit `.claude/agents/*.md` depuis des racines
  configurables et groupe par catégorie (Strategy / Product /
  Engineering / Business / Security / …). Config :
  `super-ai-core.agent_catalog.paths`.
- **Câblages SDK 1.0.6** *(0.9.8)* — quatre câblages ciblés sur le
  bump SDK : (1) `RtkCompressorService` retourne maintenant de
  vraies économies d'octets dès la sortie de la boîte (le SDK livre
  six compresseurs intégrés — git diff / grep / find / ls / tree /
  Bash) ; (2) `GhWatchCommand` déclenche
  `Hooks\HookEvent::PR_EVENT` avec un payload `PrWatchHookData` sur
  chaque événement, donc les écouteurs hooks SDK observent le même
  flux que le handler d'action local ; (3) `SuperAgentBackend`
  accepte deux nouvelles options de dispatch :
  `follow_up_queue: ['prompt suivant', 'et encore un']` pré-amorce
  la queue de follow-up de l'agent, et `on_agent_built: fn(Agent)`
  passe l'Agent construit au caller avant `run()` pour qu'un processus
  voisin (endpoint HTTP question-answer, RPC ACP `session/steer`)
  puisse appeler `Agent::steer()` mid-run ; (4) nouveau type de
  provider `AiProvider::TYPE_QWEN_ANTHROPIC` adossé à
  `QwenAnthropicProvider` du SDK 1.0.6 — Qwen 3.7 Max via l'endpoint
  Anthropic-protocol de DashScope, substitut direct de Claude.

Recettes complètes (installation Qwen CLI, configuration du viewer de
trace, intégration de client OpenAI proxy, CRUD combo de routing,
onboarding multi-comptes, planning OAuth refresher, forking de
session branch, schéma de table gh-watch, câblages SDK 1.0.6) :
[docs/advanced-usage.fr.md §30](docs/advanced-usage.fr.md).

### Vague kimi-cli + kimi-code (1.0.2 / SDK 1.0.10)

Moonshot a publié `@moonshot-ai/kimi-code` (une réécriture TypeScript) pour
**remplacer** l'ancien `MoonshotAI/kimi-cli` (Python). Les deux publient le
**même binaire `kimi`** mais exposent une surface headless incompatible ; la
1.0.2 fait donc chevaucher la transition au backend `kimi_cli` — et passe le
pin SDK à `^1.0.10`. Additif — aucun changement de schéma, aucune migration,
aucun config publish ; l'id de backend Dispatcher `kimi_cli` est inchangé.

- **Backend `kimi_cli` bi-dialecte** *(1.0.2)* — `KimiCliBackend` détecte
  automatiquement quel `kimi` est installé via une sonde `kimi --help` unique
  et mise en cache (l'ancien expose un flag `--print` ; kimi-code non) et
  adapte l'argv sur les quatre chemins de spawn. L'ancien garde
  `--print --output-format=stream-json --max-steps-per-turn N
  [--mcp-config-file F] --prompt …` ; kimi-code utilise le mode print déclenché
  par `--prompt` — pas de `--print`/`--yolo`, ni `--max-steps-per-turn` /
  `--mcp-config-file` / `-w` (pilotés par config.toml, options inconnues
  rejetées). Épinglez le dialecte avec `AI_CORE_KIMI_CLI_VARIANT` (`auto` par
  défaut / `kimi-code` / `kimi-cli`).
- **Parsing stream-json tolérant** *(1.0.2)* — le parseur accepte les deux
  formes de fil : `content` assistant en chaîne simple (kimi-code) ou en
  tableau de blocs typés `text`/`think` (ancien), et traite la nouvelle ligne
  `{"role":"meta","type":"session.resume_hint",…}` comme trace. Robuste même si
  la détection se trompe.
- **SDK `^1.0.9` → `^1.0.10`** *(1.0.2)* — durcissement du chemin HTTP
  Kimi/Moonshot, généralisé à tout provider compatible OpenAI et atteignant le
  backend `superagent` de façon transparente : comptage `usage` en streaming
  restauré (`stream_options.include_usage` — fini les tokens/coûts/cache mis
  silencieusement à zéro sur les appels streamés `kimi` / `qwen` / `glm` /
  `deepseek` / `grok` / `openrouter` / `openai`), normalisation stricte des
  schémas d'outils (les outils MCP / Skill / Agent passent le validateur de
  Moonshot), `max_completion_tokens` pour les modèles de raisonnement Kimi, et
  découverte de capacités par modèle. Nouveau garde opt-in
  `SUPERAGENT_KIMI_SWARM_ENABLED`.
- **Surfaces inchangées** *(1.0.2)* — l'id de backend `kimi_cli`, la carte
  moteur `/providers`, les sélecteurs de modèle, `cli:status`, le tableau de
  bord des coûts et le moniteur de processus n'ont besoin de rien ; seul le
  dialecte CLI sous-jacent s'adapte. (La parité agent-sync pour le modèle
  `.agents/` de kimi-code est un suivi tracé.)

Recettes complètes (détection + override de variante, matrice des flags
kimi-cli/kimi-code, correctifs transparents SDK 1.0.10) :
[docs/advanced-usage.fr.md §31](docs/advanced-usage.fr.md) et
`docs/kimi-cli-backend.md` §8.

### Vague Opus 4.8 + Grok + Cursor (1.0.0 / SDK 1.0.9)

La version stable 1.0.0 passe au SDK `^1.0.9` et fait atterrir la génération
Opus 4.8, xAI Grok sur deux canaux, et deux nouveaux moteurs CLI sur
abonnement. Additif — aucun changement de schéma, aucune migration, aucun
config publish.

- **Claude Opus 4.8 (flagship)** *(1.0.0)* — le SDK 1.0.9 promeut
  `claude-opus-4-8` au rang de flagship Anthropic : il prend l'alias `opus`,
  1M de contexte natif, thinking entrelacé, mode fast, contrôle d'effort et
  l'orchestration workflow / multi-agents dynamique, au tarif Opus
  ($15 / $75 par 1M). `ClaudeModelResolver` résout `opus → claude-opus-4-8` et
  liste `claude-opus-4-8` / `claude-opus-4-8[1m]` en tête ; le catalog du
  moteur `claude`, `model_pricing` et les paliers **expert** de `squad` /
  `cli_squad` pointent tous vers 4.8.
- **Provider API xAI Grok (type `grok`)** *(1.0.0)* — type de provider de
  première classe routé via le backend `superagent` vers le `GrokProvider` du
  SDK 1.0.9 (endpoint OpenAI-compatible `https://api.x.ai/v1` de xAI).
  `XAI_API_KEY` (canonique) avec `GROK_API_KEY` en alias ; modèle par défaut
  `grok-4.3` (1M de contexte). Exposé dans `ApiHealthDetector` (`api:status` +
  sonde du tableau de bord) et le catalogue de coûts
  (grok-4.3 / grok-4-fast / grok-code-fast-1 / grok-3-mini).
- **Cursor Composer CLI (`cursor_cli`)** *(1.0.0)* — l'agent `cursor-agent`
  headless de Cursor (Composer 2.5). Moteur sur abonnement ; connexion
  `builtin` dans `~/.cursor`. Streaming + scripted-spawn + chat one-shot,
  parsing JSON / stream-json façon Claude-Code avec suivi des tokens, MCP via
  `.cursor/mcp.json`, approbation d'outils headless `--force`. Modèle par
  défaut `composer-2.5-fast`.
- **Grok Build CLI (`grok_cli`)** *(1.0.0)* — la CLI agentique `grok`
  « Grok Build » de xAI. Moteur sur abonnement ; connexion `builtin` dans
  `~/.grok`. Contrôle d'effort (`--effort low…max` / `--reasoning-effort`),
  scripted spawn `--prompt-file`, sous-agents natifs. Modèle par défaut
  `grok-build`. **Distinct du type de provider API Grok mesuré** — même
  marque, canal différent.
- **Surfaces pilotées par les données** — comme `EngineCatalog`,
  `ProviderTypeRegistry` et les ModelResolver par moteur alimentent tout,
  l'UI `/providers` (cartes moteur, lignes builtin, dropdowns d'ajout de
  provider, badges version + connexion), les sélecteurs de modèle,
  `cli:status`, le tableau de bord des coûts, le moniteur de processus et la
  sync `McpManager` reprennent automatiquement les nouveaux moteurs.

Recettes complètes (onboarding CLI Cursor / Grok, routing Opus 4.8,
distinction canal API vs CLI Grok, contrôle d'effort) :
[docs/advanced-usage.fr.md §30](docs/advanced-usage.fr.md).

### Installateur CLI & santé

- **`cli:status`** — montre quels CLI sont installés / connectés, avec indice d'installation pour ce qui manque.
- **`cli:install [backend] [--all-missing]`** — délègue au gestionnaire de paquets canonique (`npm` / `brew` / `script`) avec confirmation par défaut. Explicite par choix — aucun CLI n'est jamais auto-installé comme effet de bord.
- **`api:status`** (depuis 0.6.8) — sonde cURL 5s contre chaque provider API HTTP direct (anthropic / openai / openrouter / gemini / kimi / qwen / glm / minimax). Renvoie `{ok, latency_ms, reason}` par provider pour distinguer en un coup d'œil auth rejetée (401/403), timeout réseau, clé absente. Flags `--all` / `--providers=a,b,c` / `--json`. Sœur parallèle de `cli:status`.

### Dispatcher & streaming

- **Routage par capacité** — `Dispatcher::dispatch(['task_type' => 'tasks.run', 'capability' => 'summarise'])` résout le bon backend + identifiants via `RoutingRepository` → `ProviderResolver` → chaîne de repli.
- **`Contracts\StreamingBackend`** (depuis 0.6.6) — chaque backend CLI stream des chunks via un callback `onChunk` tout en les tee'ant sur disque et en enregistrant une ligne `ai_processes` pour l'UI Monitor. `Dispatcher::dispatch(['stream' => true, ...])` opt-in transparent. Honore `timeout` / `idle_timeout` / `mcp_mode` par appel (`'empty'` pour claude empêche les MCP globaux de bloquer la sortie). Voir `docs/streaming-backends.md`.
- **`Runner\TaskRunner` — exécution de tâche en un appel** (depuis 0.6.6) — wrapper de `Dispatcher::dispatch(['stream' => true, ...])` qui retourne un `TaskResultEnvelope` typé avec spawn report et fallback report. Remplace ~150 lignes de « build prompt → spawn → tee log → extract usage → wrap result » côté hôte par un seul appel. 0.9.2 ajoute la vague de fiabilité TaskRunner : fallback backend opt-in, contexte de continuation, observabilité des tentatives et politiques par workload. Identique pour les 6 CLI. Voir `docs/task-runner-quickstart.md`.
- **`AgentSpawn\Pipeline` — protocole spawn-plan codex/gemini** (depuis 0.6.6) — chorégraphie en trois phases (préambule → fanout parallèle → ré-invocation de consolidation) remontée upstream dans SuperAICore. `TaskRunner` l'active quand `spawn_plan_dir` est passé. Les nouveaux CLI qui ont besoin du protocole implémentent `BackendCapabilities::spawnPreamble()` + `consolidationPrompt()` une seule fois. Voir `docs/spawn-plan-protocol.md`.
- **`cwd` par appel sur chaque CLI** (depuis 0.6.7) — les hôtes dont le processus PHP tourne depuis `web/public` peuvent quand même spawner un `claude` qui trouve `artisan` + `.claude/` à la racine. Les options Claude-only (`permission_mode`, `allowed_tools`, `session_id`) permettent de contourner les prompts d'approbation interactifs et de restreindre la surface d'outils.
- **Claude headless depuis PHP-FPM fonctionne** (depuis 0.6.7) — `ClaudeCliBackend` retire `CLAUDECODE` / `CLAUDE_CODE_ENTRYPOINT` / … de l'env enfant, pour qu'un serveur Laravel lancé depuis un shell parent `claude` ne déclenche plus le garde de récursion. Sur macOS, l'auth `builtin` bascule sur `security find-generic-password` pour lire le token OAuth et l'injecter comme `ANTHROPIC_API_KEY` — seule voie qui marche pour les workers web.
- **`Contracts\ScriptedSpawnBackend`** (depuis 0.7.1) — frère de `StreamingBackend` pour les hôtes qui détachent l'enfant (nohup / job de fond) et sondent le log en asynchrone. `prepareScriptedProcess([...])` retourne un `Symfony\Component\Process\Process` configuré qui lit `prompt_file` en stdin, tee combiné stdout+stderr vers `log_file`, applique le scrub d'env + capability transform (renommage d'outils Gemini) et honore `timeout` / `idle_timeout`. `streamChat($prompt, $onChunk, $options)` est le jumeau bloquant one-shot — le backend possède la composition d'argv, le passage prompt-vs-argv, le parsing de sortie et le strip ANSI (Kiro / Copilot). Les six backends CLI (claude / codex / gemini / copilot / kiro / kimi) implémentent le contrat en 0.7.1 ; les hôtes collapsent un `match` par backend en un appel polymorphe via `BackendRegistry::forEngine($engineKey)`. `Support\CliBinaryLocator` (singleton) centralise la sonde filesystem des binaires CLI (`~/.npm-global/bin`, `/opt/homebrew/bin`, chemins nvm, `%APPDATA%/npm` Windows). Le trait `Backends\Concerns\BuildsScriptedProcess` fournit les helpers de script wrapper pour les implémenteurs. Voir [docs/host-spawn-uplift-roadmap.md](docs/host-spawn-uplift-roadmap.md).

### Catalogue de modèles

- **Catalogue dynamique** (depuis 0.6.0) — `CostCalculator`, `ClaudeModelResolver`, `GeminiModelResolver` et l'`available_models` d'`EngineCatalog::seed()` se rabattent sur le `ModelCatalog` de SuperAgent (`resources/models.json` embarqué + override utilisateur `~/.superagent/models.json`).
- **`super-ai-core:models update`** (depuis 0.6.0) — récupère `$SUPERAGENT_MODELS_URL` et rafraîchit prix + listes de modèles pour chaque Anthropic / OpenAI / Gemini / Bedrock / OpenRouter sans `composer update`.
- **`super-ai-core:models refresh [--provider <p>]`** (depuis 0.6.9) — tire l'endpoint `GET /models` live de chaque provider dans un cache overlay par-provider à `~/.superagent/models-cache/<provider>.json`. Supporte anthropic / openai / openrouter / kimi / glm / minimax / qwen. L'overlay se place au-dessus de l'override utilisateur et en-dessous des `register()` runtime, donc les prix bundled sont préservés quand le `/models` du vendor omet les tarifs (la règle). La sortie `status` gagne une ligne `refresh cache`.

### Système de types de provider

- **`ProviderTypeRegistry` + `ProviderEnvBuilder`** (depuis 0.6.2) — chaque type de provider (Anthropic / OpenAI / Google / Kiro / …) vit dans un seul registre intégré portant libellé, icône, champs de formulaire, variable d'env, env de base-url, backends autorisés, table `extra_config → env`. Source unique pour UI `/providers` + injection d'env backend CLI + `AiProvider::requiresApiKey()`. Les hôtes surchargent via `super-ai-core.provider_types`. Les nouveaux types apparaissent après `composer update` sans changement de code.
- **`sdkProvider` sur le descripteur** (depuis 0.7.0) — les types wrapper (`anthropic-proxy`, `openai-compatible`) déclarent maintenant explicitement la clé `ProviderRegistry` SDK vers laquelle ils routent. `SuperAgentBackend::buildAgent()` consulte le descripteur quand `provider_config.provider` n'est pas défini, corrigeant une lacune de longue date où les types wrapper revenaient par défaut à `'anthropic'`.
- **`http_headers` / `env_http_headers` sur le descripteur** (depuis 0.7.0) — injection HTTP-header déclarative via les knobs 0.9.1 de `ChatCompletionsProvider`. `http_headers` sont littéraux ; `env_http_headers` référencent des variables d'env et sont silencieusement omis quand la variable n'est pas définie. Les hôtes injectent `OpenAI-Project`, `LangSmith-Project`, `OpenRouter-App` etc. sans changement de code.

### Suivi d'usage & coûts

- **`ai_usage_logs`** — chaque appel persiste tokens prompt/réponse, durée et coût. Les lignes portent aussi `shadow_cost_usd` + `billing_model` (depuis 0.6.2), pour que les moteurs à abonnement (Copilot, Kiro, Claude Code builtin) affichent une estimation USD pay-as-you-go exploitable au lieu d'une ligne à $0.
- **Coût shadow conscient du cache** (depuis 0.6.5) — `cache_read_tokens` facturé à 0.1× et `cache_write_tokens` à 1.25× du tarif `input` de base (les prix explicites du catalogue l'emportent). Les sessions Claude à gros cache collent à la vraie facture au lieu de surestimer d'un facteur ~10.
- **`total_cost_usd` rapporté par le CLI** (depuis 0.6.5) — quand l'enveloppe backend porte son propre `total_cost_usd` (le CLI Claude le fait), Dispatcher prend ce chiffre et marque la ligne avec `metadata.cost_source=cli_envelope`. Seul le CLI sait si la session est sur abonnement ou sur clé API.
- **`UsageRecorder` pour runners hôtes** (depuis 0.6.2) — façade mince sur `UsageTracker` + `CostCalculator`. Les hôtes qui lancent eux-mêmes les CLI appellent après chaque tour pour écrire une ligne `ai_usage_logs`.
- **`CliOutputParser`** — extrait `{text, model, input_tokens, output_tokens, …}` depuis un stdout capturé (`parseClaude()` / `parseCodex()` / `parseCopilot()` / `parseGemini()`) sans construire d'objet backend complet.
- **`MonitoredProcess::runMonitoredAndRecord()`** (depuis 0.6.5) — variante opt-in du `runMonitored()` : bufférise stdout, parse, écrit une ligne `ai_usage_logs` via `UsageRecorder` à la sortie du process. Les échecs de parsing ne remontent jamais.
- **Tableau de bord coûts** — table de tarification par modèle, cumuls USD, carte « By Task Type » + badge `usage`/`sub` par ligne + colonne shadow cost sur chaque ventilation (depuis 0.6.2). Les lignes à 0 tokens et `test_connection` sont masquées par défaut.

### Idempotence & traçage

- **Fenêtre de dédup 60s sur `ai_usage_logs.idempotency_key`** (depuis 0.6.6) — `EloquentUsageRepository::record()` honore une `idempotency_key` ; les clés correspondantes dans les 60s renvoient l'id existant au lieu d'insérer. `Dispatcher::dispatch()` génère automatiquement `"{backend}:{external_label}"`, donc les hôtes qui double-enregistrent le même turn logique se réduisent à une ligne sans changement de code. Migration : `php artisan migrate` ajoute une colonne nullable + index composite. Voir `docs/idempotency.md`.
- **Round-trip de la clé via le SDK** (depuis 0.7.0) — Dispatcher calcule la clé *avant* `generate()` et la transmet à `SuperAgentBackend`, qui la passe via `Agent::run($prompt, ['idempotency_key' => $k])` → `AgentResult::$idempotencyKey` (SDK 0.9.1). Le backend l'echoe sur l'enveloppe comme `idempotency_key` ; l'écriture Dispatcher vers `ai_usage_logs` préfère la valeur echoée. Effet net : les hôtes dont le Dispatcher tourne sur un processus PHP différent de l'écriture UsageRecorder observent toujours la même clé que le SDK a vue.
- **Passthrough W3C `traceparent` / `tracestate`** (depuis 0.7.0) — passez `traceparent: '<string-w3c>'` sur les options de `Dispatcher::dispatch()`. `SuperAgentBackend` transmet à `Agent::run()` ; le SDK le projette dans l'enveloppe `client_metadata` de l'API Responses, donc les logs côté OpenAI corrèlent avec la trace distribuée de l'hôte. `tracestate` et les instances `TraceContext` préconstruites sont aussi acceptées. Les chaînes vides sont filtrées.

### Gestionnaire de serveurs MCP

- **Gestionnaire piloté par UI** — installer, activer, configurer les serveurs MCP depuis l'UI admin.
- **Sync piloté par catalogue** (depuis 0.6.8) — `claude:mcp-sync` lit `.mcp-servers/mcp-catalog.json` + un fin mapping `.claude/mcp-host.json` et distribue le bon sous-ensemble au `.mcp.json` projet, aux blocs `mcpServers:` dans chaque agent `.claude/agents/*.md`, et à la config user-scope de chaque backend CLI installé. `mcp:sync-backends` est le point d'entrée autonome pour un `.mcp.json` édité à la main ou une auto-sync par file-watcher. Non destructif : les fichiers édités par l'utilisateur sont repérés par un manifeste sha256 et laissés intacts. Voir `docs/mcp-sync.md`.
- **Helpers OAuth pour serveurs mcp.json** (depuis 0.6.9) — `McpManager::oauthStatus(key)` / `oauthLogin(key)` / `oauthLogout(key)` enveloppent `McpOAuth` du SDK 0.9.0 pour les serveurs MCP déclarant un bloc `oauth: {client_id, device_endpoint, token_endpoint, scope?}`. Les UI hôtes affichent un bouton OAuth par serveur.
- **Écritures `.mcp.json` portables** (depuis 0.8.1) — opt-in en posant `AI_CORE_MCP_PORTABLE_ROOT_VAR=SUPERTEAM_ROOT` (ou tout nom de variable d'env que votre runtime MCP exporte) ; chaque writer `McpManager::install*()` émet alors des commandes nues (`node`, `php`, `uvx`, `uv`, `python`) et remplace les chemins absolus sous la racine projet par des placeholders `${SUPERTEAM_ROOT}/<rel>`. Le fichier généré survit à un copie / sync entre machines / utilisateurs / couches de container sans repollution par `which()` / `PHP_BINARY`. À l'égress vers les cibles per-machine (Codex `~/.codex/config.toml`, configs MCP user-scope Gemini / Claude / Copilot / Kiro / Kimi, flags runtime `codex exec -c`), les placeholders sont matérialisés en chemins absolus pour que les backends qui ne dépendent pas de `${VAR}` puissent quand même spawner. Default reste `null` — comportement legacy "chemins absolus partout" préservé pour les hôtes qui n'ont pas opt-in. Voir `docs/advanced-usage.md` §13.

### Intégration SuperAgent SDK

- **Vraie boucle agentique** (depuis 0.6.8) — `SuperAgentBackend` honore `max_turns`, `max_cost_usd` → `Agent::withMaxBudget()`, filtres `allowed_tools` / `denied_tools`, `mcp_config_file` (charge un `.mcp.json`, déconnecte en `finally{}`), et `provider_config.region` pour le découpage régional Kimi / Qwen / GLM / MiniMax. L'enveloppe gagne `usage.cache_read_input_tokens`, `usage.cache_creation_input_tokens`, `cost_usd` (auto-calculé par le SDK) et `turns`.
- **`AgentTool` productivity propagé** (depuis 0.6.8) — quand les appelants activent le dispatch sub-agent SDK (`load_tools: ['agent', …]`), l'enveloppe ajoute une clé optionnelle `subagents` portant les infos productivity (`filesWritten`, `toolCallsByName`, `productivityWarning`, `status: completed|completed_empty`).
- **Trois options 0.9.0 transmises** (depuis 0.6.9) — `extra_body` (deep-merge au niveau top du body de chaque requête `ChatCompletionsProvider`), `features` (routées par le `FeatureDispatcher` SDK ; clés utiles : `prompt_cache_key.session_id`, `thinking.*`, `dashscope_cache_control`), `loop_detection: true|array` (enveloppe le streaming handler dans `LoopDetectionHarness`). Raccourci : `prompt_cache_key: '<sessionId>'` accepté directement.
- **Sous-classes `ProviderException` classifiées** (depuis 0.7.0) — `SuperAgentBackend::generate()` attrape six sous-classes typées SDK (`ContextWindowExceeded`, `QuotaExceeded`, `UsageNotIncluded`, `CyberPolicy`, `ServerOverloaded`, `InvalidPrompt`), chacune loggée avec un tag `error_class` stable + verdict `retryable`. Le contrat reste inchangé (toujours `null`) ; une seam `logProviderError()` permet aux sous-classes de router sur la classification.
- **Migration vers l'adaptateur host-config `createForHost`** (depuis 0.8.5) — `SuperAgentBackend::buildAgent()` se réduit à un seul appel `ProviderRegistry::createForHost($sdkKey, $hostConfig)` au lieu de brancher sur `region` et de bricoler la forme du constructeur par provider. L'adaptateur per-key côté SDK (par défaut pour les providers ChatCompletions ; un adaptateur dédié pour `bedrock` qui éclate les credentials AWS ; auto-détection Azure intégrée à `openai-responses` ; auth synthétique LMStudio) possède la map de constructeur. Les futures clés provider du SDK atterrissent ici sans changement de code backend — l'adaptateur est le point d'extension.
- **SDK épinglé sur 0.9.5** (depuis 0.8.5) — contrainte Composer `^0.9.5`. Les replays multi-tour de `tool_use` contre des providers non-Anthropic fonctionnent enfin correctement (avant 0.9.5, `ChatCompletionsProvider::convertMessage()` faisait un early-return sur le premier bloc `tool_use` — il perdait le texte voisin et les tool calls parallèles — et lisait des propriétés `ContentBlock` inexistantes : tous les replays vers Kimi / GLM / MiniMax / Qwen / OpenAI / OpenRouter / LMStudio partaient en `{id: null, name: null, arguments: "null"}`) ; le SDK met maintenant les six wire families derrière un seul `Conversation\Transcoder` donc le fix atterrit en un seul endroit. De plus, `Agent::switchProvider($name, $config, $policy)` est désormais disponible pour le handoff in-process en milieu de conversation (presets `HandoffPolicy::default() / preserveAll() / freshStart()`) — utile pour les hôtes qui wrappent `SuperAgentBackend` directement.

### Durcissement agent-spawn

Cinq couches empilées (depuis 0.6.8) pour qu'un enfant Gemini Flash / GLM Air qui ignore son contrat soit attrapé avant de polluer la vue du consolidateur :

1. **`SpawnPlan::appendGuards()`** — bloc de guard injecté côté hôte à la fin du `task_prompt` de chaque enfant (six règles : reste dans ta voie, pas de noms de fichier consolidateur, uniformité de langue, whitelist d'extensions, chemin canonique `_signals/<name>.md`, ne t'excuse pas des échecs d'outil). Sensible à la langue via regex CJK — chinois vs anglais.
2. **`SpawnPlan::fromFile()` `output_subdir` ASCII canonique** — force `output_subdir = agent.name`, Flash émettant `首席执行官` à la place de `ceo-bezos` ne peut plus casser le parcours d'audit.
3. **`Pipeline::cleanPrematureConsolidatorFiles()`** — avant le fanout, supprime tout `摘要.md` / `思维导图.md` / `流程图.md` + variantes anglaises prématurément écrits au top-level.
4. **`Orchestrator::auditAgentOutput()`** — après fanout, signale les extensions hors whitelist, les noms de fichier réservés au consolidateur dans les sous-répertoires, et les sous-répertoires de rôles frères ; warnings dans `report[N].warnings[]`, **sans modifier le disque**. La plomberie par agent passe vers `$TMPDIR/superaicore-spawn-<date>-<hex>/<agent>/`.
5. **`SpawnConsolidationPrompt::build()` sensible à la langue** — encode en dur la table titre anglais → chinois pour les runs zh, interdit les noms de fichier d'erreur inventés comme `Error_No_Agent_Outputs_Found.md`. `GeminiCliBackend::parseJson()` tolère le préambule « YOLO mode is enabled. » / « MCP issues detected. » du CLI Gemini.

### Moniteur de processus & UI admin

- **Moniteur live-only** (depuis 0.6.7) — `AiProcessSource::list()` interroge le snapshot `ps aux` live en premier et n'émet que les lignes `ai_processes` dont le PID est vivant. Les runs terminés / en échec / tués disparaissent du Process Monitor dès que leur sous-processus sort.
- **`host_owned_label_prefixes`** (depuis 0.6.7) — les hôtes avec leur propre `ProcessSource` (par ex. les lignes `task:` de SuperTeam) revendiquent un namespace de labels pour qu'`AiProcessSource` ne double-rende pas le même run logique.
- **Pages admin** — `/integrations`, `/providers`, `/services`, `/ai-models`, `/usage`, `/costs`, `/processes`. `/processes` admin uniquement, désactivé par défaut.

### Intégration hôte

- **UI trilingue** — anglais, chinois simplifié, français, commutable à l'exécution.
- **Désactiver routes / vues** — intégrer dans l'app parente, changer le layout Blade ou réutiliser le lien retour + sélecteur de langue.
- **Trait `BackendCapabilitiesDefaults`** (depuis 0.6.6) — les implémenteurs hôtes `use` le trait pour hériter de défauts no-op sûrs pour toute méthode ajoutée dans les futures versions mineures. La classe hôte continue à satisfaire l'interface sans changement. Voir `docs/api-stability.md` pour le contrat SemVer.

## Prérequis

- PHP ≥ 8.1
- Laravel 10, 11 ou 12
- Guzzle 7, Symfony Process 6/7

Optionnel, uniquement quand le backend correspondant est activé :

- `claude` CLI dans `$PATH` — `npm i -g @anthropic-ai/claude-code`
- `codex` CLI dans `$PATH` — `brew install codex`
- `gemini` CLI dans `$PATH` — `npm i -g @google/gemini-cli`
- `copilot` CLI dans `$PATH` — `npm i -g @github/copilot` (puis `copilot login`)
- `kiro-cli` dans `$PATH` — [installer depuis kiro.dev](https://kiro.dev/cli/) (puis `kiro-cli login`, ou définir `KIRO_API_KEY` pour le mode headless Pro / Pro+ / Power)
- `kimi` CLI dans `$PATH` (depuis 0.6.8) — [installer depuis kimi.com](https://kimi.com/code) (puis `kimi login`)
- `qwen` CLI dans `$PATH` (depuis 0.9.8) — `npm i -g @qwen-code/qwen-code` (puis exporter `DASHSCOPE_API_KEY` — l'OAuth a été EOL'd le 2026-04-15)
- Clé API Anthropic / OpenAI / Google AI Studio / DashScope pour les backends HTTP

Vous ne voulez pas mémoriser les noms de paquets ? Lancez `./vendor/bin/superaicore cli:status` pour voir ce qui manque puis `./vendor/bin/superaicore cli:install --all-missing` pour tout installer en une passe (confirmation par défaut).

## Installation

```bash
composer require forgeomni/superaicore
php artisan vendor:publish --tag=super-ai-core-config
php artisan vendor:publish --tag=super-ai-core-migrations
php artisan migrate
```

Vous mettez à jour depuis 0.9.7 ? Lancez juste
`composer update forgeomni/superaicore` puis `php artisan migrate` —
0.9.8 livre cinq migrations additives (colonne `kind` sur
`ai_user_questions`, plus quatre nouvelles tables :
`ai_session_branches`, `ai_routing_combos`, `ai_provider_accounts`,
`ai_pr_watchers`). Re-publiez la config pour récupérer les nouveaux
blocs `tracing.*`, `agent_catalog.*` et `backends.qwen_cli.*` :

```bash
php artisan vendor:publish --tag=super-ai-core-config --force
```

Entrées cron optionnelles (`app/Console/Kernel.php` côté hôte) :

```php
$schedule->command('super-ai-core:snapshot-prune')->dailyAt('02:00');   // 0.9.7
$schedule->command('super-ai-core:oauth-refresh')->everyTenMinutes();   // 0.9.8
$schedule->command('super-ai-core:gh-watch')->everyFiveMinutes();       // 0.9.8
```

Guide complet étape par étape : [INSTALL.fr.md](INSTALL.fr.md).

## Démarrage rapide — CLI

```bash
# Lister les adaptateurs Dispatcher et leur disponibilité
./vendor/bin/superaicore list-backends

# Piloter les huit moteurs depuis la CLI
./vendor/bin/superaicore call "Bonjour" --backend=claude_cli                              # Claude Code CLI (connexion locale)
./vendor/bin/superaicore call "Bonjour" --backend=codex_cli                               # Codex CLI (connexion ChatGPT)
./vendor/bin/superaicore call "Bonjour" --backend=gemini_cli                              # Gemini CLI (OAuth Google)
./vendor/bin/superaicore call "Bonjour" --backend=copilot_cli                             # GitHub Copilot CLI (abonnement)
./vendor/bin/superaicore call "Bonjour" --backend=kiro_cli                                # AWS Kiro CLI (abonnement)
./vendor/bin/superaicore call "Bonjour" --backend=kimi_cli                                # Moonshot Kimi Code CLI (abonnement OAuth)
./vendor/bin/superaicore call "Bonjour" --backend=qwen_cli --api-key=sk-...                # Alibaba Qwen Code CLI (0.9.8+)
./vendor/bin/superaicore call "Bonjour" --backend=superagent --api-key=sk-ant-...         # SuperAgent SDK

# Court-circuiter la CLI et appeler directement les API HTTP
./vendor/bin/superaicore call "Bonjour" --backend=anthropic_api --api-key=sk-ant-...      # Moteur Claude en mode HTTP
./vendor/bin/superaicore call "Bonjour" --backend=openai_api --api-key=sk-...             # Moteur Codex en mode HTTP
./vendor/bin/superaicore call "Bonjour" --backend=gemini_api --api-key=AIza...            # Moteur Gemini en mode HTTP

# Santé + installation
./vendor/bin/superaicore cli:status                           # tableau installé / version / auth / indice
./vendor/bin/superaicore api:status                           # sonde 5s contre chaque API HTTP direct (0.6.8+)
./vendor/bin/superaicore cli:install --all-missing            # npm/brew/script avec confirmation

# Catalogue de modèles
./vendor/bin/superaicore super-ai-core:models status                     # sources, mtime de l'override, total
./vendor/bin/superaicore super-ai-core:models list --provider=anthropic  # prix par 1M tokens + alias
./vendor/bin/superaicore super-ai-core:models update                     # récupère $SUPERAGENT_MODELS_URL (0.6.0+)
./vendor/bin/superaicore super-ai-core:models refresh --provider=kimi    # overlay GET /models live (0.6.9+)
```

### SmartFlow — workflows cross-CLI (1.0.5)

```bash
# Lister / inspecter les flows cross-CLI
./vendor/bin/superaicore flow list
./vendor/bin/superaicore flow show cross-cli-review

# Répéter de bout en bout à coût NUL (aucun CLI invoqué — stubs déterministes)
./vendor/bin/superaicore flow run cross-cli-review --args diff=@my.diff --rehearse

# Pour de vrai : Claude résume, Codex + Gemini relisent en parallèle, Claude décide
./vendor/bin/superaicore flow run cross-cli-review --args diff=@my.diff --concurrency 4

# Fédéré : plan sur Claude, DÉLÈGUE la recherche au flow cross-modèle de superagent, build/review sur les CLIs
./vendor/bin/superaicore flow run cross-cli-federated --args goal="add caching" --args research_provider=openai

# Reprendre une exécution antérieure — le préfixe inchangé rejoue depuis le cache, coût nul
./vendor/bin/superaicore flow run cross-cli-dev --args goal="add caching" --resume <runId>
```

Également disponible via `php artisan flow ...` dans un hôte Laravel. Voir
[docs/smartflow.md](docs/smartflow.md) pour les primitives, l'écriture YAML et
la fédération superagent (modes `delegate` named/spec).

### CLI Skills & sous-agents

```bash
# Lister ce qui est installé
./vendor/bin/superaicore skill:list
./vendor/bin/superaicore agent:list

# Exécuter un skill sur Claude (par défaut)
./vendor/bin/superaicore skill:run init

# Exécuter nativement sur Gemini — sonde + traduction + préambule
./vendor/bin/superaicore skill:run simplify --backend=gemini --exec=native

# Essayer Gemini d'abord, retomber sur Claude, verrouiller dur sur le saut qui touche le cwd
./vendor/bin/superaicore skill:run simplify --exec=fallback --fallback-chain=gemini,claude

# Exécuter un sous-agent ; backend déduit du `model:` du frontmatter
./vendor/bin/superaicore agent:run security-reviewer "audit this diff"

# Synchroniser les moteurs
./vendor/bin/superaicore gemini:sync                          # expose skills/agents comme commandes Gemini
./vendor/bin/superaicore copilot:sync                         # ~/.copilot/agents/*.agent.md
./vendor/bin/superaicore copilot:sync-hooks                   # fusionner hooks style Claude dans Copilot
./vendor/bin/superaicore kiro:sync --dry-run                  # ~/.kiro/agents/*.json (0.6.1+)
./vendor/bin/superaicore kimi:sync                            # ~/.kimi/agents/*.yaml + mcp.json (0.6.8+)

# …ou, dans un hôte Laravel avec une SkillLibrary bindée, faire tout ce qui précède de façon générique (1.0.6+) :
php artisan superaicore:sync-cli                              # skills + MCP → chaque CLI installé
php artisan superaicore:sync-cli --skills-only --backends=codex,gemini

# Exécuter la même tâche sur N agents Copilot en parallèle
./vendor/bin/superaicore copilot:fleet "refactoriser auth" --agents planner,reviewer,tester
```

### Moteur de skills — commandes artisan (depuis 0.8.6)

Monté sur Laravel artisan via le service provider du package — invoquer avec `php artisan` depuis n'importe quel hôte :

```bash
# Cibles de hooks — lisent le payload Claude Code sur stdin
php artisan skill:track-start --json     # PreToolUse(Skill) — insert ligne in_progress
php artisan skill:track-stop  --json     # Stop — ferme les lignes ouvertes de la session

# Lire la table
php artisan skill:stats --since=7d --sort=failure_rate
php artisan skill:stats --skill=research --format=json

# Ranker les skills face à une description de tâche (BM25 + boost télémétrie)
php artisan skill:rank "estimer l'effort pour un projet outsourcé"
php artisan skill:rank "重构认证模块" --no-telemetry --format=json

# Mettre en queue un candidat FIX (review-only — jamais auto-appliqué)
php artisan skill:evolve --skill=research                          # trigger manuel
php artisan skill:evolve --skill=research --dispatch               # invoque aussi le LLM (coûte des tokens)
php artisan skill:evolve --sweep --threshold=0.30 --min-applied=5  # tous les skills dégradés

# Inspecter la queue
php artisan skill:candidates                                       # lister les pending
php artisan skill:candidates --id=42 --show-prompt --show-diff     # détail complet
```

## Démarrage rapide — PHP

### Tâche longue — `TaskRunner` (depuis 0.6.6)

Pour tout ce qui veut un log tail-able, une ligne Process Monitor, des previews UI live, un enregistrement d'usage automatique, et l'émulation spawn-plan pour codex/gemini :

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

Retourne un `TaskResultEnvelope` typé avec `success` / `output` / `summary` / `usage` / `costUsd` / `shadowCostUsd` / `billingModel` / `logFile` / `usageLogId` / `spawnReport` / `fallbackReport` / `error`. API identique pour les 6 moteurs CLI.

Ajoutez un fallback pour les échecs quota/rate-limit :

```php
$envelope = app(TaskRunner::class)->run('claude_cli', $prompt, [
    'fallback_chain' => ['claude_cli', 'codex_cli', 'gemini_cli'],
    'fallback_on' => ['rate limit', 'usage limit', 'quota', '429'],
    'inherit_failure_context' => true,
]);
```

Quand le fallback est actif, `$envelope->fallbackReport` contient la chaîne
de backends tentée et l'état final succès/échec.

### Appel court — `Dispatcher::dispatch()`

```php
use SuperAICore\Services\BackendRegistry;
use SuperAICore\Services\CostCalculator;
use SuperAICore\Services\Dispatcher;

$dispatcher = new Dispatcher(new BackendRegistry(), new CostCalculator());

$result = $dispatcher->dispatch([
    'prompt' => 'Bonjour',
    'backend' => 'anthropic_api',
    'provider_config' => ['api_key' => 'sk-ant-...'],
    'model' => 'claude-sonnet-4-5-20241022',
    'max_tokens' => 200,
]);

echo $result['text'];
```

Accepte aussi `'stream' => true` pour opt-in transparent vers le chemin streaming de `TaskRunner`.

Options avancées (idempotence, traçage, features SDK, erreurs classifiées) : voir [docs/advanced-usage.fr.md](docs/advanced-usage.fr.md).

## Architecture

```
  Moteurs (côté utilisateur)  Types de provider              Adaptateurs Dispatcher
  ────────────────────────    ──────────────────────         ──────────────────────
  Claude Code CLI ──────────▶ builtin                  ────▶ claude_cli
                              anthropic / bedrock /    ────▶ anthropic_api
                              vertex / anthropic-proxy
  Codex CLI       ──────────▶ builtin                  ────▶ codex_cli
                              openai / openai-compat   ────▶ openai_api
  Gemini CLI      ──────────▶ builtin / vertex         ────▶ gemini_cli
                              google-ai                ────▶ gemini_api
  Copilot CLI     ──────────▶ builtin                  ────▶ copilot_cli
  Kiro CLI        ──────────▶ builtin / kiro-api       ────▶ kiro_cli
  Kimi Code CLI   ──────────▶ builtin                  ────▶ kimi_cli
  Qwen Code CLI   ──────────▶ dashscope-api            ────▶ qwen_cli       (0.9.8+)
  SuperAgent SDK  ──────────▶ anthropic(-proxy) /      ────▶ superagent
                              openai(-compatible) /
                              openai-responses /       (0.7.0+)
                              lmstudio                 (0.7.0+)

  Dispatcher ← BackendRegistry   (contient les 11 adaptateurs ci-dessus)
             ← ProviderResolver  (provider actif depuis ProviderRepository)
             ← RoutingRepository (task_type + capability → service)
             ← AccountRoundRobin (picker multi-comptes avec cooldowns, 0.9.8+)
             ← TraceCollector    (ring magic-trace ; auto-dump sur error/rotate, 0.9.8+)
             ← UsageTracker      (écrit dans UsageRepository)
             ← CostCalculator    (tarification modèle → USD)
```

Tous les repositories sont des interfaces. Le service provider lie automatiquement les implémentations Eloquent ; remplacez-les par des fichiers JSON, Redis ou une API externe sans toucher au dispatcher.

## Usage avancé

- **[Guide d'usage avancé](docs/advanced-usage.fr.md)** — round-trip d'idempotence, trace context W3C, exceptions provider classifiées, `openai-responses` + Azure OpenAI + OAuth ChatGPT, LM Studio, surcharges `http_headers` / `env_http_headers`, features SDK (`extra_body` / `features` / `loop_detection`), migration hôte `ScriptedSpawnBackend`, moteur de skills — télémétrie / ranker BM25 / évolution mode FIX (depuis 0.8.6), la **vague jcode 0.9.0**, la **vague d'alignement DeepSeek-TUI 0.9.1**, la **vague de fiabilité TaskRunner 0.9.2**, la **vague Squad multi-agent + SDK 1.0.0 0.9.6**, la **vague opencode 0.9.7**, la **vague Qwen + traçage + 9Router 0.9.8** et la **vague cross-CLI SmartFlow 1.0.5**.
- **[SmartFlow — workflows cross-CLI](docs/smartflow.md)** *(1.0.5)* — le portage multi-CLI du `Workflow` de Claude Code : les primitives `agent`/`parallel`/`pipeline`/`gate`/`council`/`budget`/`schema`, l'écriture YAML, l'échelle de sortie structurée à 3 couches, resume + journal d'appels, répétition à coût nul, et **fédération avec le SmartFlow cross-modèle de superagent** (modes `delegate` named/spec).
- **[Cookbook](examples/cookbook/README.md)** *(0.9.8+)* — cinq exemples narratifs style gs-quant : bases dispatcher, prompt caching, rotation provider, reprise cross-harness, démarrage rapide du traçage.
- **[Paliers de commercialisation](docs/commercialization-tiers.md)** *(0.9.8+)* — document de référence sur la façon dont une offre par paliers (Cloud Dashboard / Managed Dispatcher / Enterprise overlays) pourrait s'articuler au-dessus du noyau MIT. Rien dans ce document n'est implémenté à ce jour.
- **[Politique de chaîne d'approvisionnement](SUPPLY_CHAIN.md)** *(0.9.8+)* — pas de scripts de cycle de vie Composer, `composer install --no-scripts` par défaut, `composer audit` hebdomadaire.
- **[Démarrage Task runner](docs/task-runner-quickstart.md)** — référence d'options complète de `TaskRunner`.
- **[Streaming backends](docs/streaming-backends.md)** — `mcp_mode`, formats de stream par backend, `onChunk`.
- **[Protocole spawn plan](docs/spawn-plan-protocol.md)** — émulation agent codex/gemini.
- **[Feuille de route uplift spawn hôte](docs/host-spawn-uplift-roadmap.md)** — pourquoi `ScriptedSpawnBackend` existe + les 700 lignes de glu qu'il remplace.
- **[Idempotence](docs/idempotency.md)** — fenêtre de dédup 60s, dérivation auto de clé.
- **[Sync MCP](docs/mcp-sync.md)** — catalogue + host map → chaque backend.
- **[Stabilité d'API](docs/api-stability.md)** — le contrat SemVer.

## Configuration

Le fichier de configuration publié (`config/super-ai-core.php`) couvre intégration hôte, sélecteur de langue, enregistrement routes/vues, bascules par backend, backend par défaut, rétention d'usage, dossier MCP, bascule moniteur, tarification par modèle. Chaque clé est documentée par un commentaire.

## Licence

MIT. Voir [LICENSE](LICENSE).
