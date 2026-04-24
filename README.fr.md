# forgeomni/superaicore

[![tests](https://github.com/ForgeOmni/SuperAICore/actions/workflows/tests.yml/badge.svg)](https://github.com/ForgeOmni/SuperAICore/actions/workflows/tests.yml)
[![license](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![php](https://img.shields.io/badge/php-%E2%89%A58.1-blue.svg)](composer.json)
[![laravel](https://img.shields.io/badge/laravel-10%20%7C%2011%20%7C%2012-orange.svg)](composer.json)

[English](README.md) · [简体中文](README.zh-CN.md) · [Français](README.fr.md)

Package Laravel pour l'exécution unifiée d'IA sur sept moteurs d'exécution — **Claude Code CLI**, **Codex CLI**, **Gemini CLI**, **GitHub Copilot CLI**, **AWS Kiro CLI**, **Moonshot Kimi Code CLI** et **SuperAgent SDK**. Livré avec une CLI indépendante du framework, un dispatcher par capacité, la gestion des serveurs MCP, le suivi d'usage, l'analyse des coûts et une interface d'administration complète.

Fonctionne de façon autonome dans une installation Laravel neuve. L'UI est optionnelle et entièrement remplaçable — elle peut être intégrée dans une application hôte (par ex. SuperTeam) ou désactivée si seuls les services sont nécessaires.

## Sommaire

- [Relation avec SuperAgent](#relation-avec-superagent)
- [Fonctionnalités](#fonctionnalités)
  - [Moteurs d'exécution + types de provider](#moteurs-dexécution--types-de-provider)
  - [Exécuteur de skills & sous-agents](#exécuteur-de-skills--sous-agents)
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

- **Sept moteurs d'exécution** unifiés derrière un même contrat `Dispatcher` :
  - **Claude Code CLI** — types de provider : `builtin` (connexion locale), `anthropic`, `anthropic-proxy`, `bedrock`, `vertex`.
  - **Codex CLI** — `builtin` (connexion ChatGPT), `openai`, `openai-compatible`.
  - **Gemini CLI** — `builtin` (OAuth Google), `google-ai`, `vertex`.
  - **GitHub Copilot CLI** — `builtin` uniquement (le binaire `copilot` gère OAuth/keychain/refresh). Lit `.claude/skills/` nativement (passage sans traduction). **Facturation par abonnement** — suivie séparément sur le tableau de bord.
  - **AWS Kiro CLI** (depuis 0.6.1) — `builtin` (connexion locale `kiro-cli login`), `kiro-api` (clé stockée injectée comme `KIRO_API_KEY` pour le headless). Offre l'ensemble de fonctionnalités CLI le plus riche — agents, skills, MCP et **orchestration DAG native de sous-agents** (aucune émulation `SpawnPlan`). Lit le format `SKILL.md` de Claude sans traduction. **Facturation par abonnement** — forfaits Pro / Pro+ / Power.
  - **Moonshot Kimi Code CLI** (depuis 0.6.8) — `builtin` (`kimi login` OAuth via `auth.kimi.com`). Complémentaire du `KimiProvider` HTTP direct du SDK pour couvrir le chemin agentic-loop sur abonnement OAuth, miroir du split `anthropic_api` ↔ `claude_cli`. Fanout `Agent` natif par défaut ; basculez vers la Pipeline à trois phases d'AICore via `use_native_agents=false`. **Facturation par abonnement** — forfaits Moonshot Pro / Power.
  - **SuperAgent SDK** — types de provider : `anthropic`, `anthropic-proxy`, `openai`, `openai-compatible`, plus `openai-responses` (depuis 0.7.0) et `lmstudio` (depuis 0.7.0).
- **Type de provider `openai-responses`** (depuis 0.7.0) — route via le `OpenAIResponsesProvider` du SDK contre `/v1/responses`. Auto-détecte les déploiements Azure OpenAI depuis le pattern `base_url` (ajoute la query `api-version=2025-04-01-preview` ; surchargez via `extra_config.azure_api_version`). Quand la ligne stocke un `access_token` issu d'un flux OAuth ChatGPT côté hôte au lieu d'une clé API, le SDK bascule la base URL sur `chatgpt.com/backend-api/codex`, donc les abonnés Plus / Pro / Business touchent leur quota d'abonnement.
- **Type de provider `lmstudio`** (depuis 0.7.0) — serveur LM Studio local (défaut `http://localhost:1234`). Protocole OpenAI-compat ; pas de vraie clé API requise — le SDK synthétise un header `Authorization` de substitution.
- **Dix adaptateurs dispatcher** derrière les sept moteurs (`claude_cli`, `codex_cli`, `gemini_cli`, `copilot_cli`, `kiro_cli`, `kimi_cli`, `superagent`, `anthropic_api`, `openai_api`, `gemini_api`). Adaptateur CLI quand le provider utilise `builtin` / `kiro-api` ; adaptateur HTTP quand il utilise une clé API. Directement adressable depuis la CLI si nécessaire.
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

### Installateur CLI & santé

- **`cli:status`** — montre quels CLI sont installés / connectés, avec indice d'installation pour ce qui manque.
- **`cli:install [backend] [--all-missing]`** — délègue au gestionnaire de paquets canonique (`npm` / `brew` / `script`) avec confirmation par défaut. Explicite par choix — aucun CLI n'est jamais auto-installé comme effet de bord.
- **`api:status`** (depuis 0.6.8) — sonde cURL 5s contre chaque provider API HTTP direct (anthropic / openai / openrouter / gemini / kimi / qwen / glm / minimax). Renvoie `{ok, latency_ms, reason}` par provider pour distinguer en un coup d'œil auth rejetée (401/403), timeout réseau, clé absente. Flags `--all` / `--providers=a,b,c` / `--json`. Sœur parallèle de `cli:status`.

### Dispatcher & streaming

- **Routage par capacité** — `Dispatcher::dispatch(['task_type' => 'tasks.run', 'capability' => 'summarise'])` résout le bon backend + identifiants via `RoutingRepository` → `ProviderResolver` → chaîne de repli.
- **`Contracts\StreamingBackend`** (depuis 0.6.6) — chaque backend CLI stream des chunks via un callback `onChunk` tout en les tee'ant sur disque et en enregistrant une ligne `ai_processes` pour l'UI Monitor. `Dispatcher::dispatch(['stream' => true, ...])` opt-in transparent. Honore `timeout` / `idle_timeout` / `mcp_mode` par appel (`'empty'` pour claude empêche les MCP globaux de bloquer la sortie). Voir `docs/streaming-backends.md`.
- **`Runner\TaskRunner` — exécution de tâche en un appel** (depuis 0.6.6) — wrapper de `Dispatcher::dispatch(['stream' => true, ...])` qui retourne un `TaskResultEnvelope` typé. Remplace ~150 lignes de « build prompt → spawn → tee log → extract usage → wrap result » côté hôte par un seul appel. Identique pour les 6 CLI. Voir `docs/task-runner-quickstart.md`.
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

### Intégration SuperAgent SDK

- **Vraie boucle agentique** (depuis 0.6.8) — `SuperAgentBackend` honore `max_turns`, `max_cost_usd` → `Agent::withMaxBudget()`, filtres `allowed_tools` / `denied_tools`, `mcp_config_file` (charge un `.mcp.json`, déconnecte en `finally{}`), et `provider_config.region` routé via `ProviderRegistry::createWithRegion()` pour le découpage régional Kimi / Qwen / GLM / MiniMax. L'enveloppe gagne `usage.cache_read_input_tokens`, `usage.cache_creation_input_tokens`, `cost_usd` (auto-calculé par le SDK) et `turns`.
- **`AgentTool` productivity propagé** (depuis 0.6.8) — quand les appelants activent le dispatch sub-agent SDK (`load_tools: ['agent', …]`), l'enveloppe ajoute une clé optionnelle `subagents` portant les infos productivity (`filesWritten`, `toolCallsByName`, `productivityWarning`, `status: completed|completed_empty`).
- **Trois options 0.9.0 transmises** (depuis 0.6.9) — `extra_body` (deep-merge au niveau top du body de chaque requête `ChatCompletionsProvider`), `features` (routées par le `FeatureDispatcher` SDK ; clés utiles : `prompt_cache_key.session_id`, `thinking.*`, `dashscope_cache_control`), `loop_detection: true|array` (enveloppe le streaming handler dans `LoopDetectionHarness`). Raccourci : `prompt_cache_key: '<sessionId>'` accepté directement.
- **Sous-classes `ProviderException` classifiées** (depuis 0.7.0) — `SuperAgentBackend::generate()` attrape six sous-classes typées SDK 0.9.1 (`ContextWindowExceeded`, `QuotaExceeded`, `UsageNotIncluded`, `CyberPolicy`, `ServerOverloaded`, `InvalidPrompt`), chacune loggée avec un tag `error_class` stable + verdict `retryable`. Le contrat reste inchangé (toujours `null`) ; une seam `logProviderError()` permet aux sous-classes de router sur la classification.
- **SDK épinglé sur 0.9.1** (depuis 0.7.0) — contrainte Composer `^0.9.1`. Round-trip de la clé d'idempotence, passthrough W3C `traceparent`, injection `http_headers` / `env_http_headers`, plus côté SDK le provider `openai-responses` + détection Azure + LM Studio — tout est repris sans glu SDK-level supplémentaire.

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
- Clé API Anthropic / OpenAI / Google AI Studio pour les backends HTTP

Vous ne voulez pas mémoriser les noms de paquets ? Lancez `./vendor/bin/superaicore cli:status` pour voir ce qui manque puis `./vendor/bin/superaicore cli:install --all-missing` pour tout installer en une passe (confirmation par défaut).

## Installation

```bash
composer require forgeomni/superaicore
php artisan vendor:publish --tag=super-ai-core-config
php artisan vendor:publish --tag=super-ai-core-migrations
php artisan migrate
```

Guide complet étape par étape : [INSTALL.fr.md](INSTALL.fr.md).

## Démarrage rapide — CLI

```bash
# Lister les adaptateurs Dispatcher et leur disponibilité
./vendor/bin/superaicore list-backends

# Piloter les sept moteurs depuis la CLI
./vendor/bin/superaicore call "Bonjour" --backend=claude_cli                              # Claude Code CLI (connexion locale)
./vendor/bin/superaicore call "Bonjour" --backend=codex_cli                               # Codex CLI (connexion ChatGPT)
./vendor/bin/superaicore call "Bonjour" --backend=gemini_cli                              # Gemini CLI (OAuth Google)
./vendor/bin/superaicore call "Bonjour" --backend=copilot_cli                             # GitHub Copilot CLI (abonnement)
./vendor/bin/superaicore call "Bonjour" --backend=kiro_cli                                # AWS Kiro CLI (abonnement)
./vendor/bin/superaicore call "Bonjour" --backend=kimi_cli                                # Moonshot Kimi Code CLI (abonnement OAuth)
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

# Exécuter la même tâche sur N agents Copilot en parallèle
./vendor/bin/superaicore copilot:fleet "refactoriser auth" --agents planner,reviewer,tester
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

Retourne un `TaskResultEnvelope` typé avec `success` / `output` / `summary` / `usage` / `costUsd` / `shadowCostUsd` / `billingModel` / `logFile` / `usageLogId` / `spawnReport` / `error`. API identique pour les 6 moteurs CLI.

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
  SuperAgent SDK  ──────────▶ anthropic(-proxy) /      ────▶ superagent
                              openai(-compatible) /
                              openai-responses /       (0.7.0+)
                              lmstudio                 (0.7.0+)

  Dispatcher ← BackendRegistry   (contient les 10 adaptateurs ci-dessus)
             ← ProviderResolver  (provider actif depuis ProviderRepository)
             ← RoutingRepository (task_type + capability → service)
             ← UsageTracker      (écrit dans UsageRepository)
             ← CostCalculator    (tarification modèle → USD)
```

Tous les repositories sont des interfaces. Le service provider lie automatiquement les implémentations Eloquent ; remplacez-les par des fichiers JSON, Redis ou une API externe sans toucher au dispatcher.

## Usage avancé

- **[Guide d'usage avancé](docs/advanced-usage.fr.md)** — round-trip d'idempotence, trace context W3C, exceptions provider classifiées, `openai-responses` + Azure OpenAI + OAuth ChatGPT, LM Studio, surcharges `http_headers` / `env_http_headers`, features SDK (`extra_body` / `features` / `loop_detection`), migration hôte `ScriptedSpawnBackend`.
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
