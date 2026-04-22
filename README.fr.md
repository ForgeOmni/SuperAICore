# forgeomni/superaicore

[![tests](https://github.com/ForgeOmni/SuperAICore/actions/workflows/tests.yml/badge.svg)](https://github.com/ForgeOmni/SuperAICore/actions/workflows/tests.yml)
[![license](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![php](https://img.shields.io/badge/php-%E2%89%A58.1-blue.svg)](composer.json)
[![laravel](https://img.shields.io/badge/laravel-10%20%7C%2011%20%7C%2012-orange.svg)](composer.json)

[English](README.md) · [简体中文](README.zh-CN.md) · [Français](README.fr.md)

Package Laravel pour l'exécution unifiée d'IA sur six moteurs d'exécution : **Claude Code CLI**, **Codex CLI**, **Gemini CLI**, **GitHub Copilot CLI**, **AWS Kiro CLI** et **SuperAgent SDK**. Livré avec une CLI indépendante du framework, un dispatcher par capacité, la gestion des serveurs MCP, le suivi d'usage, l'analyse des coûts et une interface d'administration complète.

Fonctionne de façon autonome dans une installation Laravel neuve. L'UI est optionnelle et entièrement remplaçable : elle peut être intégrée dans une application hôte (par ex. SuperTeam) ou désactivée si seuls les services sont nécessaires.

## Relation avec SuperAgent

`forgeomni/superaicore` et `forgeomni/superagent` sont des **packages frères, pas une relation parent-enfant** :

- **SuperAgent** est un SDK PHP léger, en processus, qui pilote une seule boucle LLM avec tool-use (un agent, une conversation).
- **SuperAICore** est une couche d'orchestration à l'échelle de Laravel — elle choisit le backend, résout les identifiants du provider, route par capacité, suit l'usage, calcule les coûts, gère les serveurs MCP et fournit une UI d'administration.

**SuperAICore ne dépend pas de SuperAgent pour fonctionner.** SuperAgent n'est que l'un des backends disponibles. Les moteurs CLI (Claude / Codex / Gemini / Copilot / Kiro) et les backends HTTP (Anthropic / OpenAI / Google) fonctionnent sans lui, et `SuperAgentBackend` se déclare poliment indisponible via un contrôle `class_exists(Agent::class)` lorsque le SDK est absent. Si vous n'avez pas besoin de SuperAgent, définissez `AI_CORE_SUPERAGENT_ENABLED=false` dans votre `.env` et le Dispatcher se rabat sur les backends restants.

L'entrée `forgeomni/superagent` dans `composer.json` est présente pour que le backend SuperAgent compile tel quel. Si vous ne l'utilisez jamais, vous pouvez la retirer du `composer.json` de votre application hôte avant `composer install` — aucun autre code de SuperAICore n'importe l'espace de noms SuperAgent.

## Fonctionnalités

- **Exécuteur de skills & sous-agents** — détecte les skills Claude Code (`.claude/skills/<nom>/SKILL.md`) et les sous-agents (`.claude/agents/<nom>.md`) et les expose comme sous-commandes CLI (`skill:list`, `skill:run`, `agent:list`, `agent:run`). Exécution par défaut sur Claude ; en option, natif sur Codex/Gemini/Copilot avec sonde de compatibilité, traduction des noms d'outils, injection du préambule backend et chaîne de repli verrouillée sur effet-de-bord. `gemini:sync` duplique chaque skill/agent en commande personnalisée Gemini ; `copilot:sync` miroir les agents dans `~/.copilot/agents/*.agent.md` (ou s'exécute automatiquement avant `agent:run --backend=copilot`) ; `copilot:sync-hooks` fusionne les hooks style Claude dans la configuration Copilot.
- **Installateur CLI en une commande** — `cli:status` liste ce qui est installé / connecté + un indice d'installation ; `cli:install [backend] [--all-missing]` délègue au gestionnaire de paquets canonique (`npm`/`brew`/`script`) avec confirmation par défaut. Explicite par choix — aucun CLI n'est jamais auto-installé comme effet de bord d'un dispatch.
- **Fan-out parallèle Copilot** — `copilot:fleet <tâche> --agents a,b,c` exécute la même tâche sur N sous-agents Copilot en parallèle, agrège les résultats par agent, et enregistre chaque enfant dans le moniteur de processus.
- **Six moteurs d'exécution** — Claude Code CLI, Codex CLI, Gemini CLI, GitHub Copilot CLI, AWS Kiro CLI et SuperAgent SDK — unifiés derrière un même contrat `Dispatcher`. Chaque moteur accepte un jeu fixe de types de provider :
  - **Claude Code CLI** : `builtin` (connexion locale), `anthropic`, `anthropic-proxy`, `bedrock`, `vertex`
  - **Codex CLI** : `builtin` (connexion ChatGPT), `openai`, `openai-compatible`
  - **Gemini CLI** : `builtin` (connexion Google OAuth), `google-ai`, `vertex`
  - **GitHub Copilot CLI** : `builtin` uniquement (le binaire `copilot` gère OAuth/keychain/refresh). Lit `.claude/skills/` nativement (passage sans traduction). **Facturation par abonnement** — suivie séparément des moteurs par token sur le tableau de bord.
  - **AWS Kiro CLI** (0.6.1+) : `builtin` (connexion locale `kiro-cli login`), `kiro-api` (la clé stockée est injectée comme `KIRO_API_KEY` pour le mode headless). Offre l'ensemble de fonctionnalités CLI le plus riche — agents, skills, MCP et **orchestration DAG native de sous-agents** (aucune émulation `SpawnPlan`). Lit le format `SKILL.md` de Claude sans traduction. **Facturation par abonnement** — forfaits Pro / Pro+ / Power basés sur des crédits.
  - **SuperAgent SDK** : `anthropic`, `anthropic-proxy`, `openai`, `openai-compatible`
- Les moteurs se déploient en interne sur neuf adaptateurs Dispatcher (`claude_cli`, `codex_cli`, `gemini_cli`, `copilot_cli`, `kiro_cli`, `superagent`, `anthropic_api`, `openai_api`, `gemini_api`) — adaptateur CLI quand le provider est `builtin` / `kiro-api`, adaptateur HTTP quand il utilise une clé API. Détail d'implémentation ; tous les noms restent adressables depuis la CLI si besoin.
- **EngineCatalog, source unique de vérité** — labels, icônes, backends Dispatcher, matrices de types de provider, catalogues de modèles et **`ProcessSpec`** déclaratif (binaire, args de version / statut d'auth, flags prompt/output/model, flags par défaut) vivent dans un service PHP unique. Ajouter un nouveau moteur CLI revient à éditer `EngineCatalog::seed()` et tout (UI, scan du moniteur, matrice de bascules, forme de commande CLI par défaut) se met à jour. Le même catalogue pilote aussi les dropdowns de modèles côté hôte via `modelOptions($key)` / `modelAliases($key)` (0.5.9+), ce qui supprime les `switch` par backend dans les apps hôtes — les modèles d'un nouveau moteur apparaissent automatiquement dans chaque picker. Les applications hôtes peuvent surcharger chaque champ (y compris `process_spec`) via la config `super-ai-core.engines`.
- **Catalogue de modèles dynamique** (0.6.0+) — `CostCalculator`, `ClaudeModelResolver`, `GeminiModelResolver` et l'`available_models` de `EngineCatalog::seed()` se rabattent tous sur le `ModelCatalog` de SuperAgent (`resources/models.json` embarqué + override utilisateur `~/.superagent/models.json`). Exécuter `superagent models update` (ou la nouvelle commande `super-ai-core:models update`) rafraîchit les prix et les IDs pour chaque modèle Anthropic / OpenAI / Gemini / Bedrock / OpenRouter sans `composer update` ni `vendor:publish`. Le `model_pricing` publié par l'hôte et les `available_models` explicites restent prioritaires.
- **OAuth Gemini visible sur `/providers`** (0.6.0+) — `CliStatusDetector::detectAuth('gemini')` lit `~/.gemini/oauth_creds.json` via le `GeminiCliCredentials` de SuperAgent, se rabat sur `GEMINI_API_KEY` / `GOOGLE_API_KEY`, et expose `{loggedIn, method, expires_at}` sur la carte du provider, comme Claude Code / Codex.
- **CliProcessBuilderRegistry** — assemble les `argv` depuis la `ProcessSpec` d'un moteur (`build($key, ['prompt' => …, 'model' => …])`). Les builders par défaut couvrent tous les moteurs préconfigurés ; les hôtes appellent `register($key, $callable)` pour brancher une forme personnalisée sans forker. Expose aussi `versionCommand()` et `authStatusCommand()` pour la sonde de statut. Enregistré en singleton.
- **Modèle Provider / Service / Routing** — associer des capacités abstraites (`summarize`, `translate`, `code_review`…) à des services concrets, puis les services à des identifiants provider.
- **Gestionnaire de serveurs MCP** — installer, activer et configurer les serveurs MCP depuis l'UI d'administration.
- **Suivi d'usage** — chaque appel persiste les tokens prompt/réponse, la durée et le coût dans `ai_usage_logs`. Les lignes portent aussi `shadow_cost_usd` + `billing_model` (0.6.2+), pour que les moteurs à abonnement (Copilot, Kiro, Claude Code builtin) affichent sur le tableau de bord une estimation USD pay-as-you-go exploitable au lieu d'une ligne à $0.
- **`UsageRecorder` pour les runners côté hôte** (0.6.2+) — façade mince au-dessus de `UsageTracker` + `CostCalculator` que les applications hôtes lançant elles-mêmes les CLIs (`App\Services\ClaudeRunner`, jobs d'étape PPT, `ExecuteTask`, …) peuvent appeler après chaque tour pour écrire une ligne `ai_usage_logs` avec `cost_usd` / `shadow_cost_usd` / `billing_model` auto-remplis depuis le catalogue. Complément : `CliOutputParser::parseClaude()` / `::parseCodex()` / `::parseCopilot()` / `::parseGemini()` extrait l'enveloppe `{text, model, input_tokens, output_tokens, …}` depuis un stdout déjà capturé sans construire un objet backend complet.
- **`ProviderTypeRegistry` + `ProviderEnvBuilder` — source unique pour les types d'API** (0.6.2+) — chaque type de provider (Anthropic / OpenAI / Google / Kiro / …) vit dans un seul registre intégré portant son libellé, icône, champs de formulaire, nom de variable d'env, env de base-url, backends autorisés et table `extra_config → env`. `ProviderEnvBuilder::buildEnv($provider)` remplace le switch à 7 branches que les apps hôtes (SuperTeam, …) dupliquaient. Les hôtes étendent via la clé `provider_types` de `config/super-ai-core.php` — **quand SuperAICore ajoute un nouveau type d'API, les hôtes le voient après un `composer update`, sans aucun changement de code**. `CliStatusDetector::detectAuth()` a aussi un fallback générique pour que les nouveaux moteurs CLI affichent un statut d'auth sur `/providers` dès leur arrivée.
- **Coût shadow conscient du cache + `total_cost_usd` rapporté par le CLI** (0.6.5+) — `CostCalculator::shadowCalculate()` facture `cache_read_tokens` à 0.1× et `cache_write_tokens` à 1.25× du tarif `input` de base (les prix explicites du catalogue l'emportent quand ils existent), pour que les sessions Claude à gros cache collent à la vraie facture Anthropic au lieu de surestimer d'un facteur ~10. Quand l'enveloppe du backend porte son propre `total_cost_usd` (le CLI Claude le fait), Dispatcher prend ce chiffre comme coût facturé et marque la ligne avec `metadata.cost_source=cli_envelope` — c'est seul le CLI qui sait si une session donnée est sur abonnement ou sur clé API.
- **Helper runner `MonitoredProcess::runMonitoredAndRecord()`** (0.6.5+) — variante opt-in du `runMonitored()` existant du trait : bufférise stdout, le parse avec `CliOutputParser`, et écrit une ligne `ai_usage_logs` via `UsageRecorder` à la sortie du process. Les runners hôtes arrêtent de câbler à la main le parser et l'enregistreur à chaque point d'appel. Les échecs de parsing ne remontent jamais (sortie texte brute Codex / Copilot : note `debug` au lieu d'une ligne, code de sortie du CLI renvoyé tel quel). Le mode texte brut `runMonitored()` est inchangé.
- **`Runner\TaskRunner` — exécution de tâche en un appel** (0.6.6+) — wrapper de `Dispatcher::dispatch(['stream' => true, ...])` qui retourne un `TaskResultEnvelope` typé (success / output / summary / usage / cost / log file / spawn report). Remplace ~150 lignes de "build prompt → spawn → tee log → extract usage → wrap result" côté hôte par un seul appel. Identique pour les 5 CLI (claude / codex / gemini / kiro / copilot) — plus de branches par backend dans votre code. Voir `docs/task-runner-quickstart.md`.
- **`Contracts\StreamingBackend` — chaque CLI obtient tee live + Process Monitor + onChunk** (0.6.6+) — nouvelle interface sœur de `Backend::generate()` qui stream les chunks via un callback tout en les tee'ant sur disque et en enregistrant une ligne `ai_processes` pour l'UI Monitor. Les 5 backends CLI l'implémentent ; `Dispatcher::dispatch(['stream' => true, ...])` opt-in transparent. Honore les `timeout` / `idle_timeout` / `mcp_mode` par appel (`'empty'` pour claude empêche les MCP globaux de bloquer la sortie). Voir `docs/streaming-backends.md`.
- **`AgentSpawn\Pipeline` — émulation spawn-plan remontée upstream** (0.6.6+) — la chorégraphie en trois phases (Phase 1 préambule / Phase 2 fanout parallèle / Phase 3 ré-invocation de consolidation) pour codex / gemini, qui vivait avant dans chaque hôte, est maintenant livrée dans SuperAICore. `TaskRunner` l'active de façon transparente quand `spawn_plan_dir` est passé. Les hôtes peuvent supprimer leurs `maybeRunSpawnPlan` + `runConsolidationPass` (~150 lignes). Les nouveaux CLI qui ont besoin du protocole implémentent `BackendCapabilities::spawnPreamble()` + `consolidationPrompt()` une seule fois et héritent du reste. Voir `docs/spawn-plan-protocol.md`.
- **Fenêtre de dédup 60s sur `ai_usage_logs.idempotency_key`** (0.6.6+) — `EloquentUsageRepository::record()` honore une `idempotency_key` ; les clés correspondantes dans les 60s renvoient l'id de la ligne existante au lieu d'en insérer une nouvelle. `Dispatcher::dispatch()` génère automatiquement `"{backend}:{external_label}"`, donc les hôtes qui double-enregistrent le même turn logique (par exemple `Dispatcher` écrivant + un `UsageRecorder::record()` côté hôte pour le même turn) se réduisent automatiquement à une ligne sans changement de code. Migration : `php artisan migrate` ajoute une colonne nullable + index composite. Voir `docs/idempotency.md`.
- **Stabilité d'API + trait `BackendCapabilitiesDefaults`** (0.6.6+) — `docs/api-stability.md` déclare formellement quelles API suivent une SemVer stricte (`StreamingBackend`, `TaskRunner`, `TaskResultEnvelope`, `Pipeline`, `TeeLogger`, `BackendCapabilities`, formes de `Dispatcher::dispatch()` / `UsageRecorder::record()`, etc.) et quelles surfaces restent évolutives. Les hôtes implémentant un `BackendCapabilities` personnalisé devraient `use BackendCapabilitiesDefaults;` pour hériter de défauts no-op sûrs pour toute méthode ajoutée dans les futures versions mineures — la classe hôte continue à satisfaire l'interface sans changement de code. Voir `docs/api-stability.md`.
- **Lancements headless de Claude CLI depuis PHP-FPM fonctionnent enfin** (0.6.7+) — `ClaudeCliBackend` retire maintenant `CLAUDECODE` / `CLAUDE_CODE_ENTRYPOINT` / `CLAUDE_CODE_SSE_PORT` / `CLAUDE_CODE_EXECPATH` / `CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS` de l'env enfant, pour qu'un serveur Laravel lancé depuis un shell parent `claude` ne déclenche plus le garde de récursion de claude avec `"Not logged in · Please run /login"`. Sur macOS, l'auth `builtin` bascule sur la lecture du token OAuth via `security find-generic-password -s "Claude Code-credentials"` injecté comme `ANTHROPIC_API_KEY` — seule voie qui fonctionne pour les workers web, car l'appel Keychain natif de claude est limité à l'audit session qui a exécuté `claude login`. Aucun changement pour les providers API-key / bedrock / vertex ni les hôtes Linux.
- **`cwd` par appel sur chaque CLI + `permission_mode` / `allowed_tools` / `session_id` spécifiques Claude** (0.6.7+) — `StreamingBackend::stream()` honore désormais `cwd` sur les 5 CLI, donc un hôte dont le processus PHP tourne depuis `web/public` peut quand même spawner un `claude` qui trouve `artisan` + `.claude/` à la racine du projet. Les options Claude-only permettent aux appelants headless de contourner les prompts d'approbation interactifs (`permission_mode=bypassPermissions` requis en headless), de restreindre la surface d'outils (`allowed_tools`) et de propager un `session_id` explicite pour la corrélation des logs. Les autres CLI acceptent silencieusement ces trois clés (no-op).
- **Process Monitor live-only + `host_owned_label_prefixes`** (0.6.7+) — `AiProcessSource::list()` interroge maintenant le snapshot `ps aux` live en premier et n'émet que les lignes `ai_processes` dont le PID est réellement vivant, tout en récupérant les lignes mortes au passage. Les runs terminés / en échec / tués disparaissent du Process Monitor dès que leur sous-processus sort, au lieu de s'accumuler. Nouveau `super-ai-core.process_monitor.host_owned_label_prefixes` : les hôtes avec leur propre `ProcessSource` (par ex. les lignes `task:` de SuperTeam) revendiquent un namespace de labels pour qu'`AiProcessSource` ne double-rende pas le même run logique. Les hôtes qui veulent un historique doivent interroger `ai_processes` directement — la table reste le journal d'audit complet de chaque spawn.
- **Sync MCP piloté par catalogue** (0.6.8+) — `claude:mcp-sync` lit `.mcp-servers/mcp-catalog.json` + un fin mapping `.claude/mcp-host.json` et distribue le bon sous-ensemble de serveurs au `.mcp.json` projet, aux blocs `mcpServers:` dans la frontmatter de chaque agent `.claude/agents/*.md` (entre les marqueurs `# superaicore:mcp:begin` / `:end`), et à la config user-scope de chaque backend CLI installé (Claude / Codex / Gemini / Copilot / Kiro). `mcp:sync-backends` est le point d'entrée de propagation autonome pour un `.mcp.json` édité à la main ou une auto-sync par file-watcher. Non destructif : les fichiers édités par l'utilisateur sont repérés via un manifeste sha256 et laissés intacts (pour le fichier projet) ; les éditions dans la frontmatter d'agent hors marqueurs sont préservées. Voir `docs/mcp-sync.md`.
- **`SuperAgentBackend` promu en véritable boucle agentique** (0.6.8+) — honore `max_turns` (défaut 1, préserve le one-shot), `max_cost_usd` → `Agent::withMaxBudget()` pour plafond budget dur dans la loop, filtres `allowed_tools` / `denied_tools`, `mcp_config_file` (charge un `.mcp.json` via `MCPManager::loadFromJsonFile()` + `autoConnect()`, déconnecte en `finally{}`), et `provider_config.region` routé via `ProviderRegistry::createWithRegion()` pour le nouveau découpage régional Kimi / Qwen / GLM / MiniMax de SuperAgent 0.8.8. L'enveloppe gagne `usage.cache_read_input_tokens`, `usage.cache_creation_input_tokens`, `cost_usd` (coût auto-calculé par le SDK — Dispatcher préfère cette valeur) et `turns`.
- **`api:status` — sonde cURL 5s pour les providers API HTTP directs** (0.6.8+) — frère parallèle de `cli:status`. Enveloppe `ProviderRegistry::healthCheck()` de SuperAgent contre anthropic / openai / openrouter / gemini / kimi / qwen / glm / minimax et renvoie un triplet `{ok, latency_ms, reason}` par provider, pour distinguer en un coup d'œil auth rejetée (HTTP 401/403), timeout réseau, clé absente. Par défaut filtre aux providers dont la variable d'env API-key est définie ; `--all` sonde tout DEFAULT_PROVIDERS, `--providers=a,b,c` restreint, `--json` pour pipelines dashboard.
- **Durcissement agent-spawn face aux modèles faibles** (0.6.8+) — cinq couches de défense empilées pour qu'un enfant Gemini Flash qui ignore son contrat soit attrapé avant de polluer la vue du consolidateur :
  1. `SpawnPlan::appendGuards()` — bloc de guard injecté côté hôte à la fin du `task_prompt` de chaque enfant (six règles : reste dans ta voie, pas de noms de fichier de consolidation, uniformité de langue y compris noms de fichier, whitelist d'extensions, chemin canonique `_signals/<name>.md`, ne t'excuse pas des échecs d'outil). Sensible à la langue via regex CJK — chinois vs anglais. Idempotent. Retire aussi les phrases `CRITICAL OUTPUT RULE: …` que le modèle émetteur de plan avait intégrées — elles entrent en conflit avec la version faisant autorité que ChildRunner ajoute à partir de `$outputRoot/$output_subdir`.
  2. `SpawnPlan::fromFile()` — force un `output_subdir = agent.name` canonique ASCII. Flash émettant `首席执行官` à la place de `ceo-bezos` en `$LANGUAGE=zh-CN` ne peut plus casser silencieusement le parcours d'audit ni la passe de consolidation (RUN 70).
  3. `Pipeline::cleanPrematureConsolidatorFiles()` — avant le fanout, supprime tout `摘要.md` / `思维导图.md` / `流程图.md` / `summary.md` / `mindmap.md` / `flowchart.md` prématurément écrit au top-level de `$outputDir` par le modèle premier-passage qui a violé la règle « emit plan and STOP » (RUN 70).
  4. `Orchestrator::auditAgentOutput()` — après fanout, signale les extensions hors whitelist, les noms de fichier réservés au consolidateur dans les sous-répertoires d'agents, et les sous-répertoires de rôles frères ; warnings dans `report[N].warnings[]`, **sans modifier le disque**. La plomberie par agent (`run.log` / prompt / script d'exécution) passe du répertoire de sortie visible vers `$TMPDIR/superaicore-spawn-<date>-<hex>/<agent>/` pour que le founder ne voie que les vrais livrables.
  5. `SpawnConsolidationPrompt::build()` est maintenant sensible à la langue et encode en dur la table titre anglais → chinois pour les runs zh (`# Executive Summary` → `# 执行摘要`, …) avec interdiction explicite des noms de fichier d'erreur inventés comme `Error_No_Agent_Outputs_Found.md` — les erreurs vont dans une section `## 警告` dans `摘要.md`, le contrat trois-fichiers reste intact (RUN 71). Les préambules `CodexCapabilities` / `GeminiCapabilities` instruisent aussi le backend émetteur du plan d'intégrer les quatre règles de guard verbatim dans chaque `task_prompt` généré — ceinture + bretelles avec #1. `GeminiCliBackend::parseJson()` tolère le préambule bruit de la CLI Gemini avant le JSON (« YOLO mode is enabled. », « MCP issues detected. » et avertissements de dépréciation — RUN 65).
- **Analyse des coûts** — table de tarification par modèle, cumuls en USD, tableau de bord avec graphiques. Carte « By Task Type » + badge `usage`/`sub` par ligne + colonne shadow cost sur chaque ventilation (0.6.2+). Par défaut, les tableaux de bord masquent les lignes à 0 tokens et les lignes `test_connection` ; les boutons « Test » de `/providers` s'auto-étiquettent désormais en `task_type=test_connection` pour ne plus polluer la vue principale.
- **Moniteur de processus** — inspecter les processus IA en cours, suivre les logs, terminer les processus orphelins.
- **UI trilingue** — anglais, chinois simplifié, français, commutable à l'exécution.
- **Compatible hôte** — désactiver routes/vues, changer le layout Blade ou réutiliser le lien de retour et le sélecteur de langue dans l'application parente.

## Prérequis

- PHP ≥ 8.1
- Laravel 10, 11 ou 12
- Guzzle 7, Symfony Process 6/7

Optionnel, uniquement quand le backend correspondant est activé :

- `claude` CLI dans `$PATH` — `npm i -g @anthropic-ai/claude-code`
- `codex` CLI dans `$PATH` — `brew install codex`
- `gemini` CLI dans `$PATH` — `npm i -g @google/gemini-cli`
- `copilot` CLI dans `$PATH` — `npm i -g @github/copilot` (puis `copilot login`)
- `kiro-cli` dans `$PATH` pour le backend Kiro CLI — [installer depuis kiro.dev](https://kiro.dev/cli/) (puis `kiro-cli login`, ou définir `KIRO_API_KEY` pour le mode headless Pro / Pro+ / Power)
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

# Piloter les six moteurs depuis la CLI
./vendor/bin/superaicore call "Bonjour" --backend=claude_cli                              # Claude Code CLI (connexion locale)
./vendor/bin/superaicore call "Bonjour" --backend=codex_cli                               # Codex CLI (connexion ChatGPT)
./vendor/bin/superaicore call "Bonjour" --backend=gemini_cli                              # Gemini CLI (OAuth Google)
./vendor/bin/superaicore call "Bonjour" --backend=copilot_cli                             # GitHub Copilot CLI (abonnement)
./vendor/bin/superaicore call "Bonjour" --backend=kiro_cli                                # AWS Kiro CLI (abonnement)
./vendor/bin/superaicore call "Bonjour" --backend=superagent --api-key=sk-ant-...         # SuperAgent SDK

# Court-circuiter la CLI et appeler directement les API HTTP
./vendor/bin/superaicore call "Bonjour" --backend=anthropic_api --api-key=sk-ant-...      # Moteur Claude en mode HTTP
./vendor/bin/superaicore call "Bonjour" --backend=openai_api --api-key=sk-...             # Moteur Codex en mode HTTP
./vendor/bin/superaicore call "Bonjour" --backend=gemini_api --api-key=AIza...            # Moteur Gemini en mode HTTP
```

## CLI Skills & sous-agents

Les skills Claude Code (`.claude/skills/<nom>/SKILL.md`) et les sous-agents (`.claude/agents/<nom>.md`) sont détectés automatiquement depuis trois sources pour les skills (projet > plugin > utilisateur) et deux pour les agents (projet > utilisateur). Chacun devient une sous-commande CLI de première classe :

```bash
# Lister ce qui est installé
./vendor/bin/superaicore skill:list
./vendor/bin/superaicore agent:list

# Exécuter un skill sur Claude (par défaut)
./vendor/bin/superaicore skill:run init

# Exécuter un skill nativement sur Gemini — sonde + traduction + préambule
./vendor/bin/superaicore skill:run simplify --backend=gemini --exec=native

# Essayer Gemini en premier, retomber sur Claude en cas d'incompatibilité ;
# verrouiller dur sur le premier backend qui touche le cwd
./vendor/bin/superaicore skill:run simplify --exec=fallback --fallback-chain=gemini,claude

# Exécuter un sous-agent ; backend déduit du `model:` du frontmatter
./vendor/bin/superaicore agent:run security-reviewer "audit this diff"

# Exposer chaque skill/agent comme commande personnalisée Gemini
# (/skill:init, /agent:security-reviewer, …)
./vendor/bin/superaicore gemini:sync

# GitHub Copilot CLI : les skills sont en passage sans traduction
# (Copilot lit .claude/skills/ nativement). Les agents se synchronisent
# automatiquement lors de agent:run ; point d'entrée manuel :
./vendor/bin/superaicore copilot:sync                         # écrit ~/.copilot/agents/*.agent.md
./vendor/bin/superaicore agent:run reviewer "audit" --backend=copilot

# Exécuter la même tâche sur N agents Copilot en parallèle
./vendor/bin/superaicore copilot:fleet "refactoriser auth" --agents planner,reviewer,tester

# Refléter vos hooks style Claude (.claude/settings.json:hooks) vers Copilot
./vendor/bin/superaicore copilot:sync-hooks                   # écrit ~/.copilot/config.json:hooks

# AWS Kiro CLI (0.6.1+) : skills en passage sans traduction (Kiro lit
# .claude/skills/ nativement) ; les agents sont traduits automatiquement
# en ~/.kiro/agents/<nom>.json lors de agent:run --backend=kiro, puis
# exécutés par l'orchestrateur natif de sous-agents en DAG de Kiro.
./vendor/bin/superaicore kiro:sync --dry-run                  # prévisualiser ~/.kiro/agents/*.json
./vendor/bin/superaicore agent:run reviewer "audit" --backend=kiro

# Bootstrapper les CLIs manquants (explicite — jamais auto)
./vendor/bin/superaicore cli:status                           # tableau installé / version / auth / indice
./vendor/bin/superaicore cli:install --all-missing            # npm/brew/script avec confirmation

# Inspecter ou rafraîchir le catalogue de modèles (0.6.0+)
./vendor/bin/superaicore super-ai-core:models status                     # sources, mtime de l'override, total chargé
./vendor/bin/superaicore super-ai-core:models list --provider=anthropic  # prix par 1M tokens + alias
./vendor/bin/superaicore super-ai-core:models update                     # récupère $SUPERAGENT_MODELS_URL
```

Comportements clés :

- `--exec=claude` (par défaut) — exécute sur Claude quel que soit `--backend`.
- `--exec=native` — exécute sur le CLI de `--backend`. `CompatibilityProbe` signale les skills qui utilisent l'outil `Agent` sur les backends sans support de sous-agent ; `SkillBodyTranslator` réécrit les noms d'outils canoniques (`` `Read` `` → `read_file`, …) dans les formes explicites et injecte le préambule backend (Gemini / Codex). La prose nue comme « Read the config » reste intacte.
- `--exec=fallback` — parcourt une chaîne ; saute les sauts incompatibles ; **verrouille dur** sur le premier saut qui touche le cwd (diff mtime + événements `tool_use` stream-json). Chaîne par défaut : `<backend>,claude`.
- Le frontmatter `arguments:` est analysé (free-form / positionnel / nommé), validé et rendu comme bloc XML structuré `<arg name="...">` ajouté au prompt.
- Le frontmatter `allowed-tools:` est transmis à `claude --allowedTools` ; codex/gemini affichent un `[note]` puisqu'aucun de leurs CLI n'a de flag d'application.
- `gemini:sync` refuse d'écraser les TOML que vous avez modifiés à la main et recrée ceux que vous avez supprimés (suivi via `~/.gemini/commands/.superaicore-manifest.json`).

## Démarrage rapide — PHP

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
  SuperAgent SDK  ──────────▶ anthropic(-proxy) /      ────▶ superagent
                              openai(-compatible)

  Dispatcher ← BackendRegistry   (contient les 9 adaptateurs ci-dessus)
             ← ProviderResolver  (provider actif depuis ProviderRepository)
             ← RoutingRepository (task_type + capability → service)
             ← UsageTracker      (écrit dans UsageRepository)
             ← CostCalculator    (tarification modèle → USD)
```

Tous les repositories sont des interfaces. Le service provider lie automatiquement les implémentations Eloquent ; remplacez-les par des fichiers JSON, Redis ou une API externe sans toucher au dispatcher.

## Interface d'administration

Quand `views_enabled` vaut `true`, le package monte ces pages sous le préfixe de route configuré (défaut `/super-ai-core`) :

- `/integrations` — providers, services, clés API, serveurs MCP
- `/providers` — identifiants et modèles par défaut par backend
- `/services` — routage par type de tâche
- `/ai-models` — surcharges de tarification modèle
- `/usage` — journal des appels avec filtres
- `/costs` — tableau de bord des coûts
- `/processes` — moniteur de processus en direct (admin uniquement, désactivé par défaut)

## Configuration

Le fichier de configuration publié (`config/super-ai-core.php`) couvre l'intégration hôte, le sélecteur de langue, l'enregistrement des routes/vues, les bascules par backend, le backend par défaut, la rétention d'usage, le dossier MCP, la bascule du moniteur de processus et la tarification par modèle. Chaque clé est documentée par un commentaire en ligne.

## Licence

MIT. Voir [LICENSE](LICENSE).
