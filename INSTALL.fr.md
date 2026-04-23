# Installation — forgeomni/superaicore

[English](INSTALL.md) · [简体中文](INSTALL.zh-CN.md) · [Français](INSTALL.fr.md)

Ce guide détaille l'installation complète de `forgeomni/superaicore` dans une application Laravel 10/11/12 existante.

## 1. Prérequis

- PHP ≥ 8.1 avec `ext-json`, `ext-mbstring`, `ext-pdo`
- Composer 2.x
- Laravel 10, 11 ou 12 (une installation neuve convient aussi)
- Une base SQL (MySQL 8+, PostgreSQL 13+ ou SQLite 3.35+)
- Optionnel, selon le backend :
  - `claude` CLI dans `$PATH` — pour le backend Claude CLI
  - `codex` CLI dans `$PATH` — pour le backend Codex CLI
  - `gemini` CLI dans `$PATH` — pour le backend Gemini CLI
  - `copilot` CLI dans `$PATH` (puis `copilot login`) — pour le backend GitHub Copilot CLI
  - `kiro-cli` dans `$PATH` (puis `kiro-cli login` ; ou définir `KIRO_API_KEY` pour le mode headless Pro / Pro+ / Power) — pour le backend Kiro CLI (0.6.1+)
  - Clé API Anthropic — pour `anthropic_api`
  - Clé API OpenAI — pour `openai_api`
  - Clé Google AI Studio — pour `gemini_api`

## 2. Installer via Composer

```bash
composer require forgeomni/superaicore
```

Si vous **ne voulez pas** le backend SuperAgent, retirez la dépendance sœur avant d'installer :

```bash
# optionnel — retirer le SDK SuperAgent
composer remove forgeomni/superagent
# puis dans .env :
# AI_CORE_SUPERAGENT_ENABLED=false
```

`SuperAgentBackend` se signale indisponible lorsque le SDK est absent, et le Dispatcher se rabat sur les quatre autres backends.

## 3. Publier la config et les migrations

```bash
php artisan vendor:publish --tag=super-ai-core-config
php artisan vendor:publish --tag=super-ai-core-migrations
php artisan vendor:publish --tag=super-ai-core-views    # seulement si vous voulez surcharger les vues Blade
```

La config se pose dans `config/super-ai-core.php`. Les migrations créent huit tables :

- `integration_configs`
- `ai_capabilities`
- `ai_services`
- `ai_service_routing`
- `ai_providers`
- `ai_model_settings`
- `ai_usage_logs`
- `ai_processes`

Exécutez-les :

```bash
php artisan migrate
```

## 4. Environnement

`.env` minimal pour les backends HTTP :

```dotenv
AI_CORE_DEFAULT_BACKEND=anthropic_api
ANTHROPIC_API_KEY=sk-ant-...
# ou pour OpenAI :
OPENAI_API_KEY=sk-...
```

Liste complète des variables d'environnement (valeurs par défaut dans `config/super-ai-core.php`) :

```dotenv
# routes & UI
AI_CORE_ROUTES_ENABLED=true
AI_CORE_ROUTE_PREFIX=super-ai-core
AI_CORE_VIEWS_ENABLED=true
SUPER_AI_CORE_LAYOUT=super-ai-core::layouts.app

# intégration hôte (optionnel)
SUPER_AI_CORE_HOST_BACK_URL=https://your-host.app/dashboard
SUPER_AI_CORE_HOST_NAME="Votre application hôte"
SUPER_AI_CORE_HOST_ICON=bi-arrow-left
SUPER_AI_CORE_LOCALE_COOKIE=locale

# backends
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
# Le mode --no-interactive de Kiro refuse d'exécuter des outils sans
# approbation préalable ; à moins d'utiliser --trust-tools=<catégories>,
# laisser à true (0.6.1+).
AI_CORE_KIRO_TRUST_ALL_TOOLS=true
# Auth par clé API Kiro (mode headless, réservé aux abonnés Pro / Pro+ /
# Power). Définir cette variable fait sauter le flux de login navigateur.
# Stockée normalement par provider en base via type=kiro-api ; à exporter
# uniquement quand kiro-cli est invoqué hors du dispatcher superaicore
# (0.6.1+).
# KIRO_API_KEY=ksk_...
# Sonde de liveness optionnelle pour la ligne copilot de `cli:status`
# (0.5.8+). Désactivée par défaut — un `copilot --help` à chaque sondage
# de statut serait du gaspillage.
SUPERAICORE_COPILOT_PROBE=false
# Rafraîchissement automatique optionnel du catalogue de modèles au
# démarrage de la CLI (0.6.0+). Les deux variables doivent être définies ;
# ne s'exécute que si l'override local a plus de 7 jours, les erreurs
# réseau sont silencieusement ignorées.
# SUPERAGENT_MODELS_URL=https://your-cdn/models.json
# SUPERAGENT_MODELS_AUTO_UPDATE=1
ANTHROPIC_BASE_URL=https://api.anthropic.com
OPENAI_BASE_URL=https://api.openai.com
GEMINI_BASE_URL=https://generativelanguage.googleapis.com

# préfixe de table (défaut sac_ — vider pour garder les noms ai_* bruts)
AI_CORE_TABLE_PREFIX=sac_

# usage + MCP + moniteur
AI_CORE_USAGE_TRACKING=true
AI_CORE_USAGE_RETAIN_DAYS=180
AI_CORE_MCP_ENABLED=true
AI_CORE_MCP_INSTALL_DIR=/var/lib/mcp
AI_CORE_PROCESS_MONITOR=false
```

## 5. Test de bon fonctionnement

```bash
# Voir quels backends sont atteignables
./vendor/bin/superaicore list-backends

# Aller-retour via l'API Anthropic
./vendor/bin/superaicore call "ping" --backend=anthropic_api --api-key="$ANTHROPIC_API_KEY"
```

Attendu : une courte réponse texte et un bloc d'usage.

### Test CLI skills & sous-agents

Si des skills ou sous-agents Claude Code sont déjà installés (sous `./.claude/skills/` dans le projet, `~/.claude/plugins/*/skills/`, ou `~/.claude/skills/` / `~/.claude/agents/`), ils sont détectés automatiquement :

```bash
./vendor/bin/superaicore skill:list
./vendor/bin/superaicore agent:list

# --dry-run affiche la commande résolue sans appeler le CLI
./vendor/bin/superaicore skill:run <nom> --dry-run

# Générer les commandes personnalisées Gemini pour chaque skill/agent
# (écrit dans ~/.gemini/commands/skill/*.toml et agent/*.toml)
./vendor/bin/superaicore gemini:sync --dry-run

# Traduire les sous-agents Claude vers le format `.agent.md` de Copilot.
# Déclenché automatiquement par `agent:run --backend=copilot` ; ce flag
# sert d'aperçu manuel.
./vendor/bin/superaicore copilot:sync --dry-run

# Même contrat pour Kiro (0.6.1+) : agents traduits en
# ~/.kiro/agents/<nom>.json. Déclenché automatiquement par
# `agent:run --backend=kiro` ; ce flag sert d'aperçu manuel.
./vendor/bin/superaicore kiro:sync --dry-run
```

Aucune configuration requise. Sans `--dry-run`, la commande passe la main aux CLI backends (`claude`, `codex`, `gemini`, `copilot`, `kiro-cli`) — installez ceux que vous comptez utiliser :

```bash
npm i -g @anthropic-ai/claude-code
brew install codex        # ou : cargo install codex
npm i -g @google/gemini-cli
npm i -g @github/copilot   # puis `copilot login` (OAuth device flow)
# kiro-cli — télécharger depuis https://kiro.dev/cli/ puis `kiro-cli login`
# (ou export KIRO_API_KEY=ksk_... pour le mode headless Pro / Pro+ / Power)
```

Raccourci en une commande (recommandé) — laissez superaicore détecter et installer :

```bash
./vendor/bin/superaicore cli:status                 # voir ce qui manque
./vendor/bin/superaicore cli:install --all-missing  # tout installer (confirmation par défaut)
```

### Test rapide du catalogue de modèles (0.6.0+)

`CostCalculator` et les `ModelResolver` de chaque moteur se rabattent sur le catalogue SuperAgent chaque fois que la config hôte n'énumère pas un modèle. Inspectez ce qui est chargé et rafraîchissez l'override utilisateur sans toucher à `composer.json` ni à `config/super-ai-core.php` :

```bash
./vendor/bin/superaicore super-ai-core:models status                       # embarqué / override utilisateur / URL distante + obsolescence
./vendor/bin/superaicore super-ai-core:models list --provider=anthropic    # prix par 1M tokens + alias
./vendor/bin/superaicore super-ai-core:models update                       # récupère SUPERAGENT_MODELS_URL → ~/.superagent/models.json
./vendor/bin/superaicore super-ai-core:models update --url https://…       # URL ad hoc pour cette exécution
./vendor/bin/superaicore super-ai-core:models reset -y                     # supprimer l'override utilisateur
```

## 6. Ouvrir l'interface d'administration

Point de montage par défaut : `/super-ai-core`. Les routes sont protégées par la pile middleware `['web', 'auth']`. Connectez-vous d'abord à votre application Laravel, puis visitez :

- `http://your-app.test/super-ai-core/integrations`
- `http://your-app.test/super-ai-core/providers`
- `http://your-app.test/super-ai-core/services`
- `http://your-app.test/super-ai-core/usage`
- `http://your-app.test/super-ai-core/costs`

Pour activer le moniteur de processus en direct (admin uniquement) : `AI_CORE_PROCESS_MONITOR=true`.

## 7. Installation sans UI / services uniquement

Sauter complètement les routes et les vues, puis résoudre les services depuis le conteneur :

```dotenv
AI_CORE_ROUTES_ENABLED=false
AI_CORE_VIEWS_ENABLED=false
```

```php
$dispatcher = app(\SuperAICore\Services\Dispatcher::class);
$result = $dispatcher->dispatch([
    'prompt' => 'Résume ce ticket',
    'task_type' => 'summarize',
]);
```

## 8. Suivi d'usage côté hôte avec `UsageRecorder` (0.6.2+)

Si votre application hôte lance les CLIs via son propre runner (par ex. `App\Services\ClaudeRunner`, des jobs d'étape, un pipeline `ExecuteTask`) au lieu de passer par `Dispatcher::dispatch()`, ces exécutions n'écrivent rien dans `ai_usage_logs` — le Dispatcher est l'unique rédacteur. Glissez un seul appel `UsageRecorder::record()` à chaque fin d'exécution CLI pour obtenir des lignes propres, avec `cost_usd`, `shadow_cost_usd` et `billing_model` auto-remplis depuis le catalogue :

```php
use SuperAICore\Services\UsageRecorder;

// Tokens déjà extraits du stream-json / stdout du CLI :
app(UsageRecorder::class)->record([
    'task_type'     => 'ppt.strategist',      // la clé d'agrégation de votre choix
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

Si vous n'avez que le stdout CLI brut et pas encore parsé les tokens, `CliOutputParser` couvre les formes courantes :

```php
use SuperAICore\Services\CliOutputParser;

$env = CliOutputParser::parseClaude($stdout);    // ou parseCodex / parseCopilot / parseGemini
// $env = ['text' => '…', 'model' => '…', 'input_tokens' => 12345, 'output_tokens' => 6789, …] ou null
```

`UsageRecorder` est enregistré en singleton ; no-op quand `AI_CORE_USAGE_TRACKING=false`.

## 9. Étendre les types de provider via `provider_types` (0.6.2+)

SuperAICore embarque 9 types de provider (`anthropic` / `anthropic-proxy` / `bedrock` / `vertex` / `google-ai` / `openai` / `openai-compatible` / `kiro-api` / `builtin`) — chacun décrit dans `Services\ProviderTypeRegistry::bundled()` avec son libellé, icône, champs de formulaire, nom de variable d'env, env de base-url, backends autorisés, et sa table `extra_config → env`. Les apps hôtes peuvent rebaptiser un type existant (par ex. pointer `label_key` vers un namespace lang local) ou déclarer un nouveau type complet via un seul bloc de config, sans fork :

```php
// config/super-ai-core.php
return [
    // …autres clés…

    'provider_types' => [
        // Rebaptiser un type existant — le reste du descripteur hérite.
        \SuperAICore\Models\AiProvider::TYPE_ANTHROPIC => [
            'label_key' => 'integrations.ai_provider_anthropic',
            'icon'      => 'bi-key',
        ],

        // Déclarer un type nouveau. La forme suit
        // ProviderTypeDescriptor::fromArray() — le registre alimente
        // automatiquement l'UI /providers, l'env builder,
        // AiProvider::requiresApiKey() et chaque backend buildEnv().
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

Quand SuperAICore ajoute plus tard un nouveau type en amont (par ex. `TYPE_ANTHROPIC_VERTEX_V2`), les hôtes le voient après un `composer update` — **aucun changement de code côté hôte**. Le registre est résolvable via `app(\SuperAICore\Services\ProviderTypeRegistry::class)` ; `get($type)` / `all()` / `forBackend($backend)` sont les trois points d'entrée courants.

Les apps hôtes qui dupliquaient avant la matrice des types de provider dans leurs propres contrôleurs/runners (le `IntegrationController::PROVIDER_TYPES` + `ClaudeRunner::providerEnvVars()` de SuperTeam avant 0.6.2) peuvent les **remplacer par une délégation d'une ligne vers `ProviderTypeRegistry` + `ProviderEnvBuilder`**. Voir la section « Host-app migration » de [CHANGELOG.md](CHANGELOG.md) pour les snippets avant/après.

## 10. Enregistrement automatique d'usage depuis les runners (0.6.5+)

Si votre hôte a une classe qui utilise le trait `Runner\Concerns\MonitoredProcess` pour lancer des sous-processus CLI (le `ClaudeRunner` de SuperTeam est l'exemple canonique), vous pouvez basculer n'importe quelle spawn-path vers l'enregistrement automatique dans `ai_usage_logs` en remplaçant `runMonitored()` par `runMonitoredAndRecord()`. La nouvelle variante bufférise stdout, le parse avec `CliOutputParser` à la sortie, et appelle `UsageRecorder::record()` avec les compteurs de tokens récupérés — un seul appel remplace les 20–40 lignes de glue parser + enregistreur que la plupart des runners hôtes finissent par écrire par backend.

```php
use Symfony\Component\Process\Process;

class MyRunner {
    use \SuperAICore\Runner\Concerns\MonitoredProcess;

    public function run(Task $task): int
    {
        $process = Process::fromShellCommandline(
            'claude -p "…" --output-format=stream-json --verbose'
        );

        // runMonitored() — spawn + enregistrement dans le moniteur. À utiliser
        //   pour les runs dont vous ne voulez pas toucher le format de sortie.
        // runMonitoredAndRecord() — idem, PLUS enregistrement d'usage à la sortie.
        return $this->runMonitoredAndRecord(
            process:         $process,
            backend:         'claude_cli',
            commandSummary:  'claude -p "review" --output-format=stream-json',
            externalLabel:   "task:{$task->id}",
            engine:          'claude',          // pilote la sélection CliOutputParser
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

Le code de sortie du CLI est toujours renvoyé tel quel. Si `CliOutputParser` ne reconnaît pas le format du flux (fréquent pour les sorties texte brut Codex / Copilot), aucune ligne n'est écrite et une note `debug` est émise — c'est opt-in précisément pour que l'adoption ne casse jamais silencieusement un runner dont le format n'est pas encore du stream-json.

## 11. Mise à jour

```bash
composer update forgeomni/superaicore
php artisan vendor:publish --tag=super-ai-core-migrations --force
php artisan migrate
```

Consultez [CHANGELOG.md](CHANGELOG.md) avant un `--force` sur la config.

**Migration 0.6.2** — ajoute deux colonnes nullable à `ai_usage_logs` : `shadow_cost_usd decimal(12,6)` et `billing_model varchar(20)`. Sûre, non destructive. Les lignes existantes reçoivent `NULL` (affiché `—` sur le tableau de bord) ; les nouvelles écritures sont remplies automatiquement par le Dispatcher. Pour nettoyer les lignes de test pré-0.6.1 avec `task_type=NULL` :

```sql
DELETE FROM ai_usage_logs WHERE task_type IS NULL AND input_tokens = 0 AND output_tokens = 0;
```

**Migration 0.6.6** — ajoute une colonne nullable + un index composite à `ai_usage_logs` : `idempotency_key varchar(80)` + index `(idempotency_key, created_at)`. Alimente la fenêtre de dédup 60 s du Dispatcher.

**0.6.7 — aucune migration.** Changement de comportement runtime pur. Deux points à revoir :

1. **Hôtes lançant claude depuis un processus lui-même démarré par un shell parent `claude`** (par ex. `php artisan serve` lancé depuis une session Claude Code) : claude se met soudain à s'authentifier correctement après la mise à jour. Si vous aviez masqué ça par un env-scrub manuel dans votre runner, il est maintenant redondant mais inoffensif.
2. **Hôtes avec leur propre `ProcessSource`** : ajoutez votre préfixe de label à la nouvelle clé de config pour qu'`AiProcessSource` ne rende pas une ligne nue en doublon à côté de votre ligne riche :

   ```php
   // config/super-ai-core.php
   'process_monitor' => [
       'enabled' => env('AI_CORE_PROCESS_MONITOR', false),
       'host_owned_label_prefixes' => ['task:'],   // convention SuperTeam
   ],
   ```

`AiProcessSource::list()` est désormais **live-only** par contrat — il ne renvoie QUE les processus OS actuellement en cours. Les hôtes qui se reposaient sur `list()` pour récupérer des lignes terminées doivent désormais interroger `ai_processes` directement (la table reste le journal d'audit complet de chaque spawn).

**0.6.8 — aucune migration.** Fonctionnalités purement additives. Trois points à revoir :

1. **Adopter le sync MCP piloté par catalogue est opt-in.** Déposez un catalogue à `.mcp-servers/mcp-catalog.json`, écrivez `.claude/mcp-host.json` avec les choix tier projet / tier agent, puis `php artisan claude:mcp-sync --dry-run` pour prévisualiser. Les hôtes qui n'exécutent pas la commande ne voient aucun changement — aucun fichier n'est touché tant que vous ne l'invoquez pas. Voir `docs/mcp-sync.md` pour la shape.

2. **Mettre à jour les appelants `SuperAgentBackend`.** Les utilisateurs one-shot existants continuent de marcher tels quels (`max_turns` vaut toujours 1 par défaut, les clés d'enveloppe sont additives). Le SDK est épinglé sur **`forgeomni/superagent` 0.8.9**. Pour tirer parti des nouvelles capacités in-process, passez :
   ```php
   $dispatcher->dispatch([
       'prompt'          => '…',
       'backend'         => 'superagent',
       'max_turns'       => 10,              // lance la vraie boucle agentique
       'max_cost_usd'    => 1.50,            // plafond dur via Agent::withMaxBudget()
       'mcp_config_file' => base_path('.mcp.json'),
       'provider_config' => ['provider' => 'kimi', 'region' => 'cn'],  // sensible à la région
       'load_tools'      => ['agent'],       // OPT-IN : dispatch sub-agent via AgentTool
   ]);

   // Quand load_tools: ['agent'] est défini ET que le run a dispatché des sub-agents,
   // l'enveloppe gagne une clé optionnelle `subagents` (productivity SDK 0.8.9) :
   //   [
   //     ['agentId' => 'research-jordan',
   //      'status' => 'completed',           // ou 'completed_empty' sur zéro tool-call
   //      'filesWritten' => ['/abs/path.md'],
   //      'toolCallsByName' => ['Read' => 3, 'Write' => 1],
   //      'productivityWarning' => null,     // advisory si outils mais aucune écriture
   //      'totalToolUseCount' => 4],
   //     …,
   //   ]
   // `status === 'completed_empty'` ou `productivityWarning` non-null = signal de
   // re-dispatch. Clé omise quand aucun sub-agent n'a tourné — zéro changement pour
   // les appelants qui n'utilisent pas AgentTool.
   ```

3. **Déboguer les providers API en une commande.** `bin/superaicore api:status` sonde chaque provider dont la variable d'env API-key est définie (5 s cURL chacun) ; `--all` élargit à tout DEFAULT_PROVIDERS, `--json` émet du JSON structuré pour les dashboards. Distingue auth rejetée (HTTP 401/403), timeout réseau et clé absente avec un `reason` distinct pour chaque.

4. **Le durcissement agent-spawn face aux modèles faibles est automatique.** Les hôtes qui utilisent `AgentSpawn\Pipeline` (y compris tous ceux sur `TaskRunner` avec `spawn_plan_dir`) héritent de cinq défenses supplémentaires à la mise à jour sans changement de code : clauses de guard injectées côté hôte dans chaque `task_prompt` (sensible à la langue via détection CJK), `output_subdir` ASCII canonique, nettoyage pré-fanout des fichiers réservés au consolidateur écrits prématurément, audit de contrat post-fanout, et prompt de consolidation sensible à la langue qui interdit les noms de fichier d'erreur inventés. Deux effets de bord à connaître :
   - Les `run.log` / prompt / script d'exécution par agent écrivent désormais dans `$TMPDIR/superaicore-spawn-<date>-<hex>/<agent>/` au lieu de `$outputRoot/<agent>/`. Le répertoire de sortie utilisateur ne contient plus que les vrais livrables (`.md` / `.csv` / `.png`). Mettez à jour l'outillage hôte qui globbait `$outputRoot/<agent>/run.log` — le chemin a changé.
   - `Orchestrator::run()` renvoie désormais `report[N].warnings[]` sur chaque entrée. Les appelants existants qui ne lisent que `exit` / `log` / `duration_ms` / `error` restent compatibles en source (la clé est optionnelle dans le PHPDoc).

**0.6.9 — aucune migration.** Surface additive + cinq correctifs de correction automatiques hérités de la montée de version SDK. La contrainte Composer est remontée `^0.8.0` → `^0.9.0`. Quatre points à revoir :

1. **Rebinding de la clé registry Qwen (côté SDK).** Le SDK 0.9.0 rebinde la clé `qwen` sur un provider OpenAI-compat (`<region>/compatible-mode/v1/chat/completions`). L'ancienne wire shape DashScope native reste disponible sous `qwen-native`. Si vous dépendiez des champs wire-level `parameters.thinking_budget` / `parameters.enable_code_interpreter` (champs DashScope natifs), changez votre provider config :
   ```php
   'provider_config' => ['provider' => 'qwen-native', 'region' => 'cn'],
   ```
   Les deux clés lisent la même env `QWEN_API_KEY` / `DASHSCOPE_API_KEY`. Le nouveau défaut `qwen` est ce que le CLI `qwen-code` d'Alibaba utilise en production — ne touchez à rien sauf si vous utilisiez ces champs DashScope natifs. `ApiHealthDetector::DEFAULT_PROVIDERS` inclut désormais les deux clés, donc `api:status` affiche les deux endpoints côte à côte.

2. **Trois nouvelles options `SuperAgentBackend`.** Toutes additives, toutes optionnelles :
   ```php
   $dispatcher->dispatch([
       'prompt'           => '…',
       'backend'          => 'superagent',
       'provider_config'  => ['provider' => 'kimi', 'region' => 'cn'],

       // NOUVEAU — champs wire spécifiques au vendor, deep-merge dans le body
       'extra_body'       => ['custom_vendor_field' => 'value'],

       // NOUVEAU — features routées par capability ; skip silencieux sur providers non-supportés
       'features'         => [
           'prompt_cache_key' => ['session_id' => $sessionId],  // cache prompt session Kimi
           'thinking'         => ['budget' => 4000],             // CoT avec fallback
       ],
       // Raccourci : 'prompt_cache_key' => $sessionId est mappé sur
       // features.prompt_cache_key.session_id automatiquement.

       // NOUVEAU — harness loop-detection par-dessus le streaming handler
       'loop_detection'   => true,    // ou : ['tool_loop_threshold' => 7, ...]
   ]);
   ```
   `loop_detection` attrape `TOOL_LOOP` (5× même tool+args), `STAGNATION` (8× même nom), `FILE_READ_LOOP` (8 des 15 derniers appels en read-like, avec exemption cold-start), `CONTENT_LOOP` (fenêtre 50 chars 10×) et `THOUGHT_LOOP` (3× même texte thinking). Les violations partent en wire events SDK — l'enveloppe AICore reste byte-exact pour les appelants qui n'optent pas.

3. **Rafraîchissement live du catalogue modèles.** Nouveau sous-commande :
   ```bash
   ./bin/superaicore super-ai-core:models refresh              # rafraîchir chaque provider avec creds env
   ./bin/superaicore super-ai-core:models refresh --provider=kimi
   php artisan super-ai-core:models refresh --provider=qwen
   ```
   Tire l'endpoint `GET /models` live de chaque provider dans `~/.superagent/models-cache/<provider>.json`. Overlay au-dessus de l'override utilisateur, en-dessous des `register()` runtime — les prix bundled sont préservés quand le `/models` vendor omet les tarifs. `CostCalculator` / `ModelResolver` les récupèrent automatiquement à l'appel suivant — pas de redémarrage, pas de republish de config.

4. **OAuth Kimi Code / Qwen Code.** Sans clé API, connectez-vous en interactif via le CLI SDK :
   ```bash
   ./vendor/bin/superagent auth login kimi-code     # device flow RFC 8628 contre auth.kimi.com
   ./vendor/bin/superagent auth login qwen-code     # device flow + PKCE S256 contre chat.qwen.ai
   ```
   Le token atterrit dans `~/.superagent/credentials/<kimi-code|qwen-code>.json`. `ApiHealthDetector::filterToConfigured()` traite désormais ces fichiers comme « configurés » pour `kimi` / `qwen`, donc `api:status` et `/providers` les reprennent sans `KIMI_API_KEY` / `QWEN_API_KEY` en env. Le path de refresh OAuth Anthropic est maintenant sérialisé par `flock` cross-process — des workers queue Laravel partageant les creds OAuth stockés ne se battent plus pour réécrire les refresh tokens.

**Pour les serveurs MCP déclarant un bloc `oauth:` dans `mcp.json`** vous pouvez désormais appeler `McpManager::oauthStatus($key)` / `oauthLogin($key)` / `oauthLogout($key)` dans votre UI. `oauthLogin()` bloque sur stdio pendant le poll device-flow — lancez-le en job queue, pas en ligne dans une requête. Les `startAuth()` / `clearAuth()` / `testConnection()` existants (serveurs browser-login / session-dir, ex. scraper LinkedIn) restent inchangés.

## Dépannage

- **`Class 'SuperAgent\Agent' not found`** — vous avez retiré `forgeomni/superagent` mais laissé `AI_CORE_SUPERAGENT_ENABLED=true`. Mettez-le à `false` ou réinstallez le SDK.
- **Backend CLI manquant** — exécutez `which claude` / `which codex`. Si vide, installez la CLI ou indiquez un chemin absolu dans `CLAUDE_CLI_BIN` / `CODEX_CLI_BIN`.
- **Rien dans `ai_usage_logs`** — vérifiez `AI_CORE_USAGE_TRACKING=true` et que les migrations sont passées.
- **Prompt `vendor:publish` ambigu** — passez explicitement un `--tag` de la liste ci-dessus.
