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
  - `cursor-agent` dans `$PATH` (puis `cursor-agent login` ; ou `CURSOR_API_KEY` pour le mode headless) — pour le backend Cursor Composer CLI (1.0.0+)
  - `grok` dans `$PATH` (puis `grok login`) — pour le backend xAI Grok Build CLI (1.0.0+)
  - Clé API Anthropic — pour `anthropic_api`
  - Clé API OpenAI — pour `openai_api`
  - Clé Google AI Studio — pour `gemini_api`
  - Clé API xAI (`XAI_API_KEY` / `GROK_API_KEY`) — pour le type de provider facturé `grok` via `superagent` (1.0.0+)

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

La config se pose dans `config/super-ai-core.php`. Les migrations créent dix tables :

- `integration_configs`
- `ai_capabilities`
- `ai_services`
- `ai_service_routing`
- `ai_providers`
- `ai_model_settings`
- `ai_usage_logs`
- `ai_processes`
- `skill_executions` *(depuis 0.8.6)*
- `skill_evolution_candidates` *(depuis 0.8.6)*

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
CURSOR_CLI_BIN=cursor-agent
GROK_CLI_BIN=grok
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
# CLIs Cursor Composer + Grok Build (1.0.0+). Moteurs sur abonnement —
# ils gèrent leur propre login (~/.cursor, ~/.grok). `force`/`always_approve`
# auto-approuvent les outils en mode headless ; mettre à false pour revenir
# à la confirmation par outil.
AI_CORE_CURSOR_CLI_ENABLED=true
AI_CORE_CURSOR_FORCE=true
AI_CORE_GROK_CLI_ENABLED=true
AI_CORE_GROK_ALWAYS_APPROVE=true
# CURSOR_API_KEY=...   # Cursor headless (sinon `cursor-agent login`)
# Clé API xAI pour le type de provider facturé `grok` via superagent (1.0.0+).
# Distincte du moteur CLI `grok` (abonnement grok.com) ci-dessus.
# XAI_API_KEY=xai-...  # GROK_API_KEY est aussi accepté comme nom de repli
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

### Test de dispatch (1.1.0)

```bash
# Diagnostic agrégé : moteurs, auth, backends, alias, préférences, archive
./vendor/bin/superaicore doctor

# Envoi one-shot par alias avec le contrat de routage JSON complet
./vendor/bin/superaicore send sonnet "ping" --json-result

# Inspecter le pool de routage et l'archive des runs
./vendor/bin/superaicore aliases
./vendor/bin/superaicore runs list
```

Attendu : `doctor` se termine par un résumé du type `N ok, 0 warn, 0 fail`
(les warns sont bénins — ils signalent des moteurs non installés), et `send`
renvoie un contrat JSON dont le champ `ok` vaut `true`. Voir
[docs/ai-dispatch-parity.md](docs/ai-dispatch-parity.md).

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

Dans un hôte Laravel qui bind une `SkillLibrary` (1.0.6+), une seule commande
artisan diffuse toute votre bibliothèque de skills + agents vers la surface native
de chaque CLI installé — les répertoires de skills codex/gemini/grok/cursor/qwen et
les fichiers d'instructions copilot/kimi/kiro — et re-propage le MCP dans la même
passe. Elle est sûre face aux symlinks et paresseuse par empreinte, de sorte que la
relancer est peu coûteux et idempotent :

```bash
php artisan superaicore:sync-cli                         # skills + MCP → every installed CLI
php artisan superaicore:sync-cli --skills-only --backends=codex,gemini
```

`TaskRunner` exécute aussi ce sync de skills de façon paresseuse avant chaque
dispatch CLI (une seule comparaison d'empreinte), donc la commande sert aux
refreshes manuels / cron / git-hook. Quand aucune `SkillLibrary` n'est bindée, elle
affiche une ligne de skip et ne fait rien.

Aucune configuration requise. Sans `--dry-run`, la commande passe la main aux CLI backends (`claude`, `codex`, `gemini`, `copilot`, `kiro-cli`, `cursor-agent`, `grok`) — installez ceux que vous comptez utiliser :

```bash
npm i -g @anthropic-ai/claude-code
brew install codex        # ou : cargo install codex
npm i -g @google/gemini-cli
npm i -g @github/copilot   # puis `copilot login` (OAuth device flow)
# kiro-cli — télécharger depuis https://kiro.dev/cli/ puis `kiro-cli login`
# (ou export KIRO_API_KEY=ksk_... pour le mode headless Pro / Pro+ / Power)
curl https://cursor.com/install -fsS | bash   # puis `cursor-agent login` (1.0.0+)
curl -fsSL https://grok.com/install.sh | bash  # puis `grok login` (1.0.0+)
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

SuperAICore embarque 15 types de provider (`builtin`, `moonshot-builtin`, `anthropic`, `anthropic-proxy`, `bedrock`, `vertex`, `google-ai`, `openai`, `openai-compatible`, `openai-responses`, `lmstudio`, `deepseek`, `qwen-anthropic`, `grok`, `kiro-api`) — chacun décrit dans `Services\ProviderTypeRegistry::bundled()` avec son libellé, icône, champs de formulaire, nom de variable d'env, env de base-url, backends autorisés, et sa table `extra_config → env`. Les apps hôtes peuvent rebaptiser un type existant (par ex. pointer `label_key` vers un namespace lang local) ou déclarer un nouveau type complet via un seul bloc de config, sans fork :

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

**0.7.0 — aucune migration.** Surface additive + un correctif de mapping de longue date. Contrainte Composer remontée `^0.9.0` → `^0.9.1`. Cinq points à revoir :

1. **Deux nouveaux types de provider : `openai-responses` et `lmstudio`.** Les deux routent via le backend `superagent` (clés SDK `openai-responses` / `lmstudio`).
   - **API OpenAI Responses** — mode facturé : ajoutez une ligne provider avec `type = openai-responses` et une clé API. Mode abonnement ChatGPT : laissez `api_key` vide et stockez `access_token` dans `extra_config.access_token` (depuis le flux OAuth ChatGPT de votre hôte) — le SDK bascule automatiquement la base URL sur `chatgpt.com/backend-api/codex`. Azure OpenAI : définissez `base_url` à `https://<nom>.openai.azure.com/openai/deployments/<deployment>` — le SDK ajoute automatiquement la query `api-version=2025-04-01-preview` (surchargez via `extra_config.azure_api_version`).
   - **LM Studio** — pointez `base_url` vers votre serveur LM Studio local (défaut `http://localhost:1234/v1`). Aucune clé API requise ; le SDK synthétise un header `Authorization` de substitution. Utile pour les charges déconnectées / on-prem.

2. **Round-trip de la clé d'idempotence via le SDK.** Si vous passiez déjà `idempotency_key` dans les options de `Dispatcher::dispatch()`, aucun changement de code — mais la valeur voyage maintenant avec l'`AgentResult` du SDK au lieu de passer latéralement via UsageRecorder. Les hôtes dont le Dispatcher tourne sur un autre process que l'écriture n'ont plus besoin de recalculer la clé côté écriture. Même principe pour la clé auto dérivée d'`external_label` : `Dispatcher::dispatch()` pré-calcule le même `"{backend}:{external_label}"`, le transmet à `Agent::run()`, et préfère la valeur echoée lors de l'écriture dans `ai_usage_logs`.

3. **Passthrough de trace context W3C.** Si votre hôte a un middleware qui capture le header `traceparent` entrant, transmettez-le au Dispatcher :
   ```php
   $dispatcher->dispatch([
       'prompt'       => '…',
       'backend'      => 'superagent',
       'provider_config' => ['type' => 'openai-responses', 'api_key' => env('OPENAI_API_KEY')],
       'traceparent'  => $request->header('traceparent'),  // no-op silencieux si null
       'tracestate'   => $request->header('tracestate'),
   ]);
   ```
   Le SDK les projette sur l'enveloppe `client_metadata` de l'API Responses, donc les logs OpenAI corrèlent avec la trace distribuée de l'hôte. Drop silencieux sur chaînes invalides — passage inconditionnel sûr.

4. **Sous-classes `ProviderException` classifiées.** L'échelle de catch de `SuperAgentBackend` se scinde maintenant en six sous-classes SDK spécifiques (`ContextWindowExceeded`, `QuotaExceeded`, `UsageNotIncluded`, `CyberPolicy`, `ServerOverloaded`, `InvalidPrompt`) avant le `\Throwable` générique, chacune loggée avec un tag `error_class` stable + flag `retryable`. Contrat inchangé — toujours `null` en échec — donc aucun site d'appel ne casse. Les hôtes voulant un routage plus intelligent sous-classent `SuperAgentBackend` et surchargent la seam `logProviderError(\Throwable $e, string $code)`.

5. **Headers HTTP déclaratifs par type de provider.** Deux nouveaux champs sur le descripteur — `http_headers` (header → valeur littérale) et `env_http_headers` (header → nom de variable d'env, lue à la requête) — permettent d'injecter `OpenAI-Project`, `LangSmith-Project`, `OpenRouter-App` etc. sur chaque appel SDK d'un type de provider, sans toucher au code du package. Exemple :
   ```php
   // config/super-ai-core.php
   'provider_types' => [
       // Tag chaque appel OpenAI avec votre propre app id + reprend la
       // variable OPENAI_PROJECT (le SDK omet silencieusement le header
       // quand la variable n'est pas définie, donc c'est sûr sur les hôtes
       // qui n'ont pas configuré de clés project-scoped).
       \SuperAICore\Models\AiProvider::TYPE_OPENAI => [
           'http_headers'     => ['X-App' => 'my-host-app'],
           'env_http_headers' => ['OpenAI-Project' => 'OPENAI_PROJECT'],
       ],

       // Idem pour le nouveau type Responses API — injecte un header
       // LangSmith project, tracing cross-provider sans wrapper.
       \SuperAICore\Models\AiProvider::TYPE_OPENAI_RESPONSES => [
           'env_http_headers' => ['Langsmith-Project' => 'LANGSMITH_PROJECT'],
       ],
   ],
   ```

**Lignes `openai-compatible` / `anthropic-proxy` préexistantes.** Avant 0.7.0 ces lignes routaient silencieusement via le provider `anthropic` du SDK quand `provider_config.provider` n'était pas défini à la main — `anthropic-proxy` fonctionnait par coïncidence, `openai-compatible` échouait. Après 0.7.0 le `sdk_provider` du descripteur fait le mapping correct (`anthropic` / `openai`). Si votre hôte définissait explicitement `provider_config.provider`, rien ne change. Si vous dépendiez de ce fallback cassé, les lignes `openai-compatible` commencent enfin à fonctionner comme prévu.

Voir `docs/advanced-usage.fr.md` pour les recettes approfondies — Responses multi-tours, tracing LangSmith, LM Studio sur LAN, routage d'exception niveau hôte, surcharges HTTP-header par provider.

**0.7.1 — aucune migration.** Contrat purement additif — `Contracts\ScriptedSpawnBackend` arrive en sibling (pas en remplacement) de `StreamingBackend`. Les six backends CLI (`Claude` / `Codex` / `Gemini` / `Copilot` / `Kiro` / `Kimi`) l'implémentent dans la même release. Les hôtes qui portent aujourd'hui un `match ($backend) { 'claude' => buildClaudeProcess(…), 'codex' => buildCodexProcess(…), … }` par backend (une copie pour le spawn de tâche, une autre pour le chat one-shot) peuvent collapser les deux en un seul appel polymorphe :

```php
use SuperAICore\Services\BackendRegistry;

$backend = app(BackendRegistry::class)->forEngine($engineKey);  // nullable — null quand l'engine est désactivé
$process = $backend->prepareScriptedProcess([
    'prompt_file'  => $promptFile,
    'log_file'     => $logFile,
    'project_root' => $projectRoot,
    'model'        => $model,
    'env'          => $env,                     // construit côté hôte (lit IntegrationConfig)
    'disable_mcp'  => $disableMcp,              // surtout Claude
    'codex_extra_config_args' => $codexArgs,    // surtout Codex
]);
$process->start();

// Le sibling one-shot chat — le backend possède argv, parsing de sortie, strip ANSI :
$response = $backend->streamChat($prompt, function (string $chunk) {
    echo $chunk;
});

// 1.0.8+ — exposer un sous-ensemble délimité de serveurs MCP au tour de chat
// (Claude uniquement ; le défaut reste une surface MCP vide verrouillée).
// Voir docs/advanced-usage.fr.md §12.
$response = $backend->streamChat($prompt, $onChunk, [
    'mcp_mode'        => 'file',
    'mcp_config_file' => $subsetJsonPath,   // {"mcpServers": {...}}
]);
```

Après la migration, les futurs engines qui ship une implémentation `ScriptedSpawnBackend` s'allument automatiquement dans chaque code path hôte — aucune branche `match` à ajouter. `Support\CliBinaryLocator` est enregistré en singleton par le service provider pour que la résolution des binaires CLI côté hôte utilise les mêmes sondes (`~/.npm-global/bin` / `/opt/homebrew/bin` / chemins nvm / `%APPDATA%/npm` Windows) que les backends du package. `ClaudeCliBackend::CLAUDE_SESSION_ENV_MARKERS` est exposé en constante publique pour que les hôtes qui composent encore leurs propres processus `claude` partagent la liste canonique des 5 marqueurs à scrub.

Voir `docs/advanced-usage.fr.md` §12 pour le pattern de migration complet avant/après et `docs/host-spawn-uplift-roadmap.md` pour le contexte.

**0.8.1 — aucune migration.** Deux changements opt-in ; sûrs à laisser éteints à la mise à jour.

1. **Écritures `.mcp.json` portables via `mcp.portable_root_var`.** Default reste `null` — le legacy "chemins absolus partout" est préservé. Opt-in quand vous voulez qu'un `.mcp.json` généré survive à un copie / sync entre machines, utilisateurs ou couches de container (cas typique : votre dossier d'install `mcp` vit dans l'arbre projet et bouge avec lui) :

   ```dotenv
   # .env — n'importe quel nom de variable d'env exportée par votre runtime MCP convient
   AI_CORE_MCP_PORTABLE_ROOT_VAR=SUPERTEAM_ROOT
   ```

   ```jsonc
   // .claude/settings.local.json — Claude Code expand ${SUPERTEAM_ROOT} au spawn MCP
   { "env": { "SUPERTEAM_ROOT": "${PWD}" } }
   ```

   Après ça, chaque writer `McpManager::install*()` émet des commandes nues (`node`, `php`, `uvx`, `uv`, `python`) et réécrit les chemins sous `projectRoot()` en `${SUPERTEAM_ROOT}/<rel>`. Les trois helpers backend-sync (`superfeedMcpConfig`, `codexOcrMcpConfig`, `codexPdfExtractMcpConfig`) honorent le même knob. À l'égress vers les cibles per-machine (Codex `~/.codex/config.toml`, configs MCP user-scope Gemini / Claude / Copilot / Kiro / Kimi, flags runtime `codex exec -c`), `materializePortablePath()` retourne les placeholders en chemins absolus, donc les backends à manipulation littérale stricte spawnent correctement. Nouveaux helpers sur `McpManager` : `portablePath()`, `portableCommand()`, `portableRootVar()`, `materializePortablePath()`, `materializeServerSpec()`. Voir `docs/advanced-usage.fr.md` §13 pour les recettes (hôtes containerisés, montages multi-user, quoi faire quand la variable d'env n'est pas exportée au runtime).

2. **`/providers` gate maintenant l'UI sur la disponibilité du CLI.** Pur fix UI — pas de changement controller / route / DB. Les engines CLI (`claude` / `codex` / `gemini` / `copilot` / `kiro` / `kimi`) dont le binaire n'est pas sur `$PATH` rendent le toggle engine en `disabled` (avec tooltip + champ caché clampé), et la ligne synthétique "built-in (local CLI login)" dans la table per-backend est masquée quand l'engine est éteint ou son CLI manquant. Quand ni built-in ni aucun provider externe ne s'applique, la table affiche maintenant une ligne d'état vide pointant la vraie raison. Les hôtes qui voyaient des utilisateurs activer "Engine on" pour ensuite voir les spawns échouer silencieusement au runtime peuvent arrêter de traiter ces tickets.

**0.8.5 — aucune migration.** Uptake du SDK + un fix de correctness ; pas de changement DB / config. La contrainte Composer passe de `^0.9.0` à `^0.9.5`. Trois choses à savoir :

1. **Les replays multi-tour de tool-use contre Kimi / GLM / MiniMax / Qwen / OpenAI / OpenRouter / LMStudio fonctionnent enfin correctement.** Avant 0.9.5, le `ChatCompletionsProvider::convertMessage()` du SDK faisait un early-return sur le premier bloc `tool_use` (perdant le texte voisin et les tool calls parallèles) et lisait des propriétés `ContentBlock` inexistantes — chaque tool call rejoué partait en `{id: null, name: null, arguments: "null"}`. Les hôtes lançant `Dispatcher::dispatch(['backend' => 'superagent', 'max_turns' => 10, …])` contre n'importe lequel de ces providers étaient silencieusement cassés avant l'upgrade. Aucun changement de code d'appel requis ; le nouveau `Conversation\Transcoder` du SDK met chaque wire family derrière un seul converter canonique donc le fix atterrit sur tous les providers d'un coup.

2. **`SuperAgentBackend::buildAgent()` passe désormais toujours une instance `LLMProvider` construite au SDK** (pas une chaîne nom-de-provider + clés `llmConfig` étalées). Les chemins de production passent par `Dispatcher`, qui n'inspecte jamais `$agentConfig['provider']` — donc le changement est invisible. Les hôtes qui sous-classent `SuperAgentBackend` et override `makeAgent()` doivent mettre à jour les assertions de test qui vérifiaient `$agentConfig['provider'] === 'sa-test'` en `instanceof \SuperAgent\Contracts\LLMProvider` — voir `tests/Unit/SuperAgentBackendTest.php::test_no_region_still_hands_llmprovider_instance_to_agent` pour le pattern canonique. La nouvelle seam `makeProvider()` dans `SuperAgentBackend` est le point de substitution pour les tests qui veulent un faux LLMProvider sans devoir l'enregistrer dans `ProviderRegistry`.

3. **`Agent::switchProvider($name, $config, $policy)` est désormais disponible** si vous wrappez `SuperAgentBackend` directement et voulez un handoff in-process en milieu de conversation entre familles de providers. La `FallbackChain` propre à SuperAICore travaille au niveau des sous-processus CLI (un autre concern) donc elle ne l'utilise pas. Voir l'entrée `[0.9.5]` du CHANGELOG du SDK pour les presets `HandoffPolicy::default() / preserveAll() / freshStart()` et les règles d'encodage cross-family.

Le fix de la typo de namespace introduite en 0.8.1 (`makeProvider()` retournait un `\SuperAgent\Providers\LLMProvider` inexistant et cassait silencieusement tout le backend SuperAgent in-process sur 0.8.1 → 0.8.2) fait également partie de cette release. Les hôtes qui voyaient `Dispatcher::dispatch(['backend' => 'superagent', …])` retourner `null` à chaque appel devraient maintenant voir de vraies enveloppes — vérifiez avec `bin/superaicore api:status` contre vos providers routés via SuperAgent, ou lancez la suite du package : 480 tests, 1380 assertions.

**0.8.6 — ajoute deux tables.** Première release depuis 0.6.6 où `php artisan migrate` apporte du nouveau schema. Le moteur de skills est **opt-in via le câblage des hooks** — installez le package, lancez la migration, et zéro changement de comportement tant que vous ne pointez pas les hooks `PreToolUse(Skill)` et `Stop` de Claude Code vers les nouvelles commandes artisan. Trois choses à savoir :

1. **Lancer la migration.** Deux nouvelles tables : `skill_executions` (une ligne par invocation Skill de Claude Code — télémétrie) et `skill_evolution_candidates` (patches FIX-mode review-only proposés par l'evolver). Les deux honorent `super-ai-core.table_prefix` via `HasConfigurablePrefix`. Les deux `up()` sont gardés par `Schema::hasTable()` — la migration est idempotente. `down()` drop les deux — sûr pour re-bootstrapper en dev.

   ```bash
   composer update forgeomni/superaicore
   php artisan vendor:publish --tag=super-ai-core-migrations --force
   php artisan migrate
   ```

2. **Câbler les hooks (côté hôte, optionnel).** Le package n'expédie que les endpoints artisan — `.claude/settings.local.json` de Claude Code fait le binding hook → commande :

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

   Les deux commandes lisent le payload JSON du hook Claude Code sur stdin (deadline soft 1.0s + cap 200KB, lecture non-bloquante, ne fait jamais échouer le hook sur erreur de télémétrie) et auto-détectent `host_app` en remontant à la recherche d'un `.claude/` voisin (basename du parent). Le commit jumeau de SuperTeam démontre le câblage complet.

3. **Optionnel : cron `skill:evolve --sweep` quotidien.** Une fois la télémétrie en flux, l'evolver peut scanner les skills aux métriques dégradées et mettre en queue des candidats review-only sans brûler de tokens (par défaut, pas de dispatch LLM). Queue de review via `php artisan skill:candidates`.

   ```php
   // app/Console/Kernel.php
   $schedule->command('skill:evolve --sweep --threshold=0.30 --min-applied=5')
            ->daily()
            ->withoutOverlapping();
   ```

   `--sweep` déduplique contre les lignes `pending` existantes — idempotent à travers les runs. Ajoutez `--dispatch` pour aussi invoquer le LLM via `Dispatcher` avec `capability: 'reasoning'` — coûte des tokens, mais donne aux reviewers un vrai diff à appliquer. L'evolver **ne modifie jamais** SKILL.md directement. Les modes DERIVED / CAPTURED (auto-dérivation de nouveaux skills depuis les runs réussis / capture de workflows démontrés par l'utilisateur) sont volontairement non-shippés — Day 0 : les humains curatent les nouveaux skills.

Les six commandes artisan (`skill:track-start`, `skill:track-stop`, `skill:stats`, `skill:rank`, `skill:evolve`, `skill:candidates`) sont toutes enregistrées via `SuperAICoreServiceProvider::boot()`. Elles **ne sont pas** montées sur la console standalone `bin/superaicore` — appelez-les via `php artisan` depuis votre hôte Laravel. Voir `docs/advanced-usage.fr.md` §16 pour les patterns d'intégration de `SkillRanker` (UI de skill-picker côté hôte, retrieval pondéré, chaînes de fallback conscientes de la télémétrie).

**0.9.0 — pas de migration ; contrainte SDK remontée à `^0.9.7`.** Six intégrations additives de primitives jcode livrées dans SuperAgent SDK 0.9.7. Toutes opt-in via env flag et dégradent en no-op quand leur câblage hôte est absent — comportement pré-0.9.7 strictement préservé sauf si vous activez le drapeau correspondant. Seul `agent_grep` est **activé par défaut** (lecture seule, sans dépendance externe).

```bash
composer update forgeomni/superaicore forgeomni/superagent
# pas de `php artisan migrate` nécessaire — pas de changement de schéma en 0.9.0
```

Nouveaux drapeaux env (tous optionnels sauf indication contraire) :

```dotenv
# ─── Outils SuperAgent intégrés (0.9.7) ───
# `agent_grep` style jcode — contexte du symbole englobant + troncature
# des chunks vus dans la session. Activé par défaut car en lecture seule
# et ne se déclenche que sur les dispatches qui pilotent réellement une
# boucle agentique avec outils. Mettez à false pour un comportement
# octet-à-octet identique à 0.9.7.
AI_CORE_TOOLS_AGENT_GREP=true

# FirefoxBridgeTool du SDK 0.9.7 (`browser`) — pilote un onglet Firefox /
# Chromium réel via Native Messaging. Désactivé par défaut ; passez à
# true quand le launcher est installé.
AI_CORE_TOOLS_BROWSER=false
# Chemin du binaire launcher attendu par la WebExtension. L'outil renvoie
# une erreur explicative quand il n'est pas défini, donc vous pouvez
# laisser AI_CORE_TOOLS_BROWSER=true sans casser la boucle.
SUPERAGENT_BROWSER_BRIDGE_PATH=/abs/path/to/forgeomni-bridge-launcher

# ─── Stockage de captures d'écran (0.9.7) ───
# Alimente ProcessEntry::$latest_screenshot_url. SuperAgentBackend écrit
# chaque PNG base64 retourné par l'outil `browser` ; AiProcessSource
# purge au reap. Les défauts conviennent.
AI_CORE_BROWSER_SHOTS_DISK=local
AI_CORE_BROWSER_SHOTS_DIR=super-ai-core/browser-screenshots

# ─── Embeddings (utilisés par SemanticSkillReranker + SemanticSkillRouter SDK) ───
# Daemon Ollama optionnel pour le reranker sémantique. Quand non défini,
# le reranker dégrade vers l'ordre BM25 — pas de changement de
# comportement pour les hôtes qui n'ont pas opt-in.
AI_CORE_EMBEDDINGS_OLLAMA_URL=http://127.0.0.1:11434
AI_CORE_EMBEDDINGS_OLLAMA_MODEL=nomic-embed-text
AI_CORE_EMBEDDINGS_TIMEOUT_MS=10000

# ─── Reprise de session cross-harness (0.9.7) ───
# Désactivé par défaut — les importeurs voient l'historique de chaque
# opérateur sur les machines partagées (~/.claude, ~/.codex).
AI_CORE_RESUME_ENABLED=false
```

Six choses à revoir lors de la mise à jour :

1. **`agent_grep` enrichit silencieusement chaque dispatch SuperAgent en boucle d'outils.** L'outil est dans le `BuiltinToolRegistry::classMap` du SDK, donc `load_tools` le résout via `ToolLoader` — ne se déclenche que quand le dispatch fait réellement tourner des outils (`max_turns > 1` ou tableau `load_tools` explicite). Les appels one-shot et les dispatches CLI ne sont pas affectés. Mettez `AI_CORE_TOOLS_AGENT_GREP=false` si vos tests vérifient la liste exacte des outils.

2. **L'outil `browser` est une installation manuelle.** Le SDK livre `FirefoxBridgeTool` mais pas la WebExtension ni le binaire launcher. Le walkthrough d'installation est dans le docblock de la classe SDK : `vendor/forgeomni/superagent/src/Tools/Browser/FirefoxBridge.php`. Tant que le launcher n'est pas installé et que `SUPERAGENT_BROWSER_BRIDGE_PATH` n'y pointe pas, l'outil renvoie des erreurs explicatives pour que l'agent arrête de boucler — il est sûr d'activer le drapeau avant l'installation du launcher.

3. **Les captures d'écran font le round-trip via `external_label`.** `SuperAgentBackend::resolveScreenshotKey()` et `AiProcessSource::screenshotKeys()` préfèrent tous deux `external_label` en premier, puis la clé composite `aiprocess.<id>`. Les hôtes qui passent déjà `external_label` sur `Dispatcher::dispatch()` (convention standard depuis 0.6.6) obtiennent le round-trip gratuitement. Ceux qui ne le passent pas verront les captures stockées sous des clés aléatoires — définissez `external_label` sur le dispatch pour les aligner avec la ligne Process Monitor.

4. **`SemanticSkillReranker` se résout maintenant via le SDK.** Le client HTTP Ollama et l'adaptateur callback fait main pré-0.9.0 ont disparu — le reranker récupère un `EmbeddingProvider` du SuperAgent SDK 0.9.7 depuis le singleton conteneur construit par `EmbeddingProviderFactory`. Trois chemins de résolution : `super-ai-core.embeddings.provider` explicite (l'hôte câble le sien), `super-ai-core.embeddings.callback` (auto-emballé en `CallableEmbeddingProvider`), ou `super-ai-core.embeddings.ollama_url` (`OllamaEmbeddingProvider`). Quand aucun n'est défini, le reranker est un no-op propre — même contrat qu'avant.

5. **`/usage` gagne une carte « By Source ».** `Dispatcher::resolveUsageSource()` écrit `metadata.usage_source` (défaut `'user'`). L'`AmbientWorker` de SuperAgent étiquette les ticks de fond avec `'ambient'` via son callback `tagUsage` — quand vous câblez le worker, ces lignes apparaissent dans la nouvelle carte du tableau de bord sans réinstrumenter le code de coûts hôte. La mise en page se reflow à `col-lg-3` sur les viewports larges pour que les cartes existantes By Task Type / By Model / By Backend restent lisibles.

6. **La liste déroulante Resume de `/processes` est opt-in.** `AI_CORE_RESUME_ENABLED=false` cache la liste et fait que les endpoints du contrôleur renvoient 403. Mettez à `true` uniquement sur les machines où exposer l'historique `~/.claude` / `~/.codex` de chaque opérateur au tableau de bord est acceptable. Pour câbler le re-dispatch côté hôte (plutôt que l'affichage inline de transcription), définissez `super-ai-core.resume.on_load` sur un callable retournant `{redirect: '<url>'}` :

    ```php
    // config/super-ai-core.php
    'resume' => [
        'enabled' => env('AI_CORE_RESUME_ENABLED', true),
        'on_load' => function (string $harness, string $sessionId, array $messages) {
            // $messages est list<\SuperAgent\Messages\Message> — alimentez votre runner.
            $task = MyChatSession::createFromHarnessImport($harness, $sessionId, $messages);
            return ['redirect' => route('chat.show', $task)];
        },
    ],
    ```

Recettes complètes (câblage Ollama, mise en place launcher browser, boucle tick AmbientWorker, callback de reprise harness) : voir [docs/advanced-usage.fr.md §17–§21](docs/advanced-usage.fr.md).

**0.9.1 — une nouvelle migration ; contrainte SDK remontée à `^0.9.8`.**
Cinq bindings compagnons SuperAgent SDK 0.9.8 additifs (goal store
persistant, portail d'approbation à trois niveaux, manifeste de plugin
workspace, JSON `/v1/usage` headless, agrégation `cache_hit_rate`) plus
un correctif de durcissement du backend
(`SuperAgentBackend::resolveEmbeddingProvider()` ne lève plus quand le
ServiceProvider du package n'a pas booté).

```bash
composer update forgeomni/superaicore forgeomni/superagent
php artisan migrate    # crée `ai_goals` (la seule nouvelle table)
```

Aucun nouveau drapeau env n'est obligatoire — chaque binding est un
singleton résolu via le conteneur avec des défauts sains. Les hôtes qui
veulent surcharger le goal store, verrouiller les approbations ou
préseeder un manifeste de plugins workspace le font en code :

```php
// config/super-ai-core.php  (optionnel)
return [
    // …clés existantes…

    // Mode par défaut du portail d'approbation. Suggest = mutations
    // requièrent /approve ; Auto = tout passe sauf shell destructif ;
    // Never = lecture seule pure. Surcharges par-thread sur
    // AiProcess.approval_mode (migration côté hôte si vous voulez les
    // persister).
    'runner' => [
        'approval_mode' => env('AI_CORE_APPROVAL_MODE', 'suggest'),
    ],
];
```

Six points à revoir lors de la mise à niveau :

1. **La table `ai_goals` est opt-in.** `php artisan migrate` la crée mais le binding n'écrit que quand quelque chose résout `app(\SuperAgent\Goals\GoalManager::class)` et appelle `setActiveGoal()` / `pause()` etc. Les hôtes qui n'utilisent pas la primitive goal peuvent laisser la table vide — pas d'estampillage automatique depuis `Dispatcher::dispatch()`.

2. **Un `GoalStore` personnalisé se substitue par rebind du conteneur.** Si vous gardez déjà les goals dans votre propre table, surchargez le binding avant la première résolution de `app(GoalManager::class)` :

    ```php
    // app/Providers/AppServiceProvider.php::register()
    $this->app->bind(
        \SuperAgent\Goals\Contracts\GoalStore::class,
        \App\Goals\MyGoalStore::class,
    );
    ```

    L'`EloquentGoalStore` livré ici est une implémentation de référence, pas une dépendance dure.

3. **`ApprovalGate` est câblé mais l'hôte possède la boucle.** Le portail est une fonction de décision pure — `evaluate($toolName, $arguments, $mode, $toolUseId, $approvedToolUseId)` retourne `ApprovalDecision::allow()` / `suggestApproval()` / `hardDeny()`. Les hôtes l'appellent à l'intérieur de leur wrapper de tool-dispatch avant de transférer au backend, rendent la suggestion dans leur UI et repassent le token `/approve` de l'utilisateur en `$approvedToolUseId` au retry. Pas d'enforcement côté backend pour l'instant — l'opt-in est à un appel d'enveloppe près dans votre runner.

4. **`/v1/usage` est non authentifiée par défaut.** La route est enregistrée dans `routes/web.php` sous le préfixe standard du package. Encapsulez le groupe de routes externe (ou le middleware par-route) avec ce que votre hôte utilise pour l'authentification API — `auth:sanctum`, URLs signées, allowlist d'IP interne. Le contrôleur ne suppose pas de session présente et servira volontiers les données agrégées de coût à tout appelant qui l'atteint. Voir `routes/web.php` pour le site d'enregistrement.

5. **`cache_hit_rate` atterrit sur chaque ligne avec une part de cache non nulle.** Les tableaux de bord existants continuent à fonctionner ; le nouveau code peut lire `metadata.cache_hit_rate` directement sans redériver le dénominateur. Distingue « pas de cache éligible » (clé absente) de « 0% de hit rate » (clé présente, valeur `0.0`). Accepte aussi l'alias legacy `cache_hit_tokens` des wires DeepSeek V3 / R1 — l'ancien code hôte qui estampillait l'alias sur les enregistrements d'usage est forward-compatible.

6. **Le correctif de durcissement du backend supprime un crash latent pour les hôtes non-Laravel.** `SuperAgentBackend::resolveEmbeddingProvider()` et `configBool()` enveloppent maintenant les lookups conteneur dans un try/catch. Les hôtes qui faisaient tourner le backend avant le boot du ServiceProvider du package (tests PHPUnit purs, points d'entrée CLI personnalisés) heurtaient une `BindingResolutionException` ; ils dégradent maintenant silencieusement vers « pas d'embedder » / défauts de config. Aucun changement requis côté hôte — ça arrête simplement de planter.

Recettes complètes (override de GoalStore persistant, câblage du
portail d'approbation à l'intérieur d'un runner hôte, format de
manifeste de plugin workspace + boucle de diff, recette `/v1/usage`
(exemples curl + datasource Grafana JSON), recettes de tableau de bord
`cache_hit_rate`) : voir [docs/advanced-usage.fr.md
§22–§26](docs/advanced-usage.fr.md).

**0.9.2 — aucune migration ; la vague de fiabilité TaskRunner est opt-in.**
Cette version ajoute un fallback backend par run pour `Runner\TaskRunner`
quand le backend primaire échoue avec une sortie de type quota/rate-limit,
plus les patterns hôte de politique, continuation, observabilité et rollout
qui le rendent exploitable pour les longs jobs opérateur. Les appels
existants gardent le comportement single-backend tant que vous ne passez pas
`fallback_chain`, ne configurez pas `super-ai-core.task_fallback.chain`, ou
n'activez pas le fallback automatique.

```bash
composer update forgeomni/superaicore
php artisan vendor:publish --tag=super-ai-core-config --force   # optionnel, pour récupérer task_fallback
```

Knobs env optionnels :

```dotenv
AI_CORE_TASK_FALLBACK_AUTO=false
AI_CORE_TASK_FALLBACK_CHAIN=claude_cli,codex_cli,gemini_cli
AI_CORE_TASK_FALLBACK_CHECK_AVAILABILITY=false
AI_CORE_TASK_FALLBACK_INHERIT_CONTEXT=true
```

Six points à revoir lors de la mise à niveau :

1. **Le fallback est par run, pas sticky.** Le backend demandé est toujours
   essayé en premier, donc un primaire rétabli reprend naturellement le
   trafic à la tâche suivante.

2. **Gardez les chaînes spécifiques au workload.** Les tâches de code
   peuvent préférer `claude_cli → codex_cli → gemini_cli`; recherche/synthèse
   peut inclure `kimi_cli`; les backends HTTP directs conviennent souvent
   comme dernier stop headless. Commencez par un `fallback_chain` par appel
   avant de promouvoir une chaîne en config globale.

3. **Le handoff continue seulement sur échec correspondant.** Les defaults
   couvrent les formulations quota/rate-limit courantes (`rate limit`,
   `usage limit`, `quota`, `429`, `too many requests`,
   `usage_not_included`). Les erreurs de validation de prompt, de tool et
   autres échecs non correspondants restent sur le backend d'origine sauf
   si vous étendez `fallback_on`.

4. **Utilisez le fallback TaskRunner avant le retry de queue.** Un retry de
   queue relance tout le job; le fallback garde le même run logique en
   mouvement et peut passer un court extrait sortie/log au backend suivant.
   C'est généralement la meilleure première récupération pour les longues
   tâches.

5. **Les hôtes peuvent persister le rapport de tentatives.**
   `TaskResultEnvelope` expose maintenant `fallbackReport`, et `toArray()`
   inclut `fallback_report`. Si votre hôte stocke les metadata de
   l'enveloppe, autorisez cette nouvelle clé nullable. L'UI peut rendre
   « primary limited, continued on codex » et lier chaque tentative à son
   `log_file`.

6. **Utilisez le rapport pour l'analytics de fiabilité.** Corrélez
   `fallback_report[*].backend` avec `ai_usage_logs.backend` pour identifier
   les primaires qui touchent souvent le quota et les secondaires qui
   terminent réellement le travail. Réordonnez `auto_chain` depuis cette
   évidence, pas à l'instinct.

Voir [docs/advanced-usage.fr.md §27](docs/advanced-usage.fr.md) et
[docs/task-runner-quickstart.md](docs/task-runner-quickstart.md) pour la
recette complète du fallback TaskRunner.

**0.9.5 — aucune migration ; fix de rendu de vue.** Deux fixes
d'encodage d'attribut Blade sur les pages index `/processes` et
`/usage`. Aucun backend, config ou surface API n'a bougé. Les hôtes
qui ont customisé `resources/views/processes/index.blade.php` ou
`resources/views/usage/index.blade.php` doivent reprendre le nouveau
pattern `@php($var = …)` + `@json($var)` lorsqu'ils réintroduisent
leurs overrides — construire le payload du side-panel inline dans
un attribut HTML à guillemets simples produit du markup malformé sur
les lignes dont l'URL de screenshot ou le blob de metadata contient
des guillemets / des `&`. Changement purement runtime.

**0.9.6 — aucune migration ; pin SDK passe à `^1.0`.** Backend Squad
multi-agent + six bindings compagnons SDK 0.9.8 / 1.0.0. Chaque
binding est additif et opt-in — le comportement pré-0.9.6 est
préservé tant que vous n'activez pas un flag, ne passez pas une
nouvelle option, ou ne résolvez pas un nouveau service depuis le
container.

```bash
composer update forgeomni/superaicore forgeomni/superagent
php artisan vendor:publish --tag=super-ai-core-config --force   # optionnel ; récupère les nouveaux blocs de config
# pas de `php artisan migrate` — aucun changement de schéma en 0.9.6
```

Knobs env optionnels (toutes les valeurs par défaut sont sûres ; le
package fonctionne sans qu'aucune ne soit définie) :

```dotenv
# ─── Squad multi-agent (SDK 1.0.0) ───
AI_CORE_SQUAD_ENABLED=true
AI_CORE_SQUAD_BACKEND_ENABLED=true
AI_CORE_SQUAD_MAX_COST=0              # 0 désactive le plafond
AI_CORE_SQUAD_CHECKPOINT_DIR=         # défaut : storage/app/squad/

# ─── Routage /model auto (SDK 0.9.8) ───
AI_CORE_AUTO_MODEL=true
AI_CORE_AUTO_MODEL_PRO=               # null → défaut SDK (deepseek-v4-pro)
AI_CORE_AUTO_MODEL_FLASH=             # null → défaut SDK (deepseek-v4-flash)
AI_CORE_AUTO_MODEL_LONG_CTX=32000
AI_CORE_AUTO_MODEL_TOOL_DEPTH=3
AI_CORE_AUTO_MODEL_SCORE_CATALOG=     # chemin optionnel d'un ScoreCatalog JSON

# ─── Compaction cache-aware (SDK 0.9.8) ───
AI_CORE_COMPRESSION_CACHE_AWARE=true
AI_CORE_COMPRESSION_PIN_HEAD=4
AI_CORE_COMPRESSION_PIN_SYSTEM=true

# ─── Limiteur token-bucket par provider (SDK 0.9.8) ───
AI_CORE_RL_DEFAULT_RATE=8.0
AI_CORE_RL_DEFAULT_BURST=16

# ─── Wrapping d'input non-fiable (SDK 0.9.8) ───
AI_CORE_UNTRUSTED_INPUT=true

# ─── Cap de profondeur sous-agent (SDK 0.9.8) ───
AI_CORE_AGENT_MAX_DEPTH=0             # 0 → défaut SDK (5)

# ─── DeepSeek FIM (SDK 0.9.8) ───
DEEPSEEK_API_KEY=
```

Huit choses à revoir lors de la montée :

1. **Squad est gardé par la disponibilité du SDK.** `BackendRegistry`
   n'enregistre `SquadBackend` que si
   `super-ai-core.backends.squad.enabled` est actif ET que les classes
   SDK 1.0.0 sont présentes (`PeerOrchestrator`, `TaskDecomposer`,
   `ModelTierMap`, `SquadCheckpointStore`). Les hôtes n'ayant pas
   monté SDK 1.0.0 ne voient aucun changement — Squad se déclare
   indisponible et le Dispatcher retombe sur les neuf autres
   adaptateurs.

2. **Les pipelines Squad persistent des checkpoints par étape.**
   Les échecs en cours laissent le checkpoint sur disque ;
   redispatchez avec le même `squad_id` et `checkpoint_dir` pour
   reprendre. Le `checkpoint_dir` par défaut atterrit dans
   `storage/app/squad/` donc les permissions storage de Laravel
   couvrent déjà le chemin. Override par appel via
   `options.checkpoint_dir`, ou globalement via
   `AI_CORE_SQUAD_CHECKPOINT_DIR`.

3. **`AutoModelRouter` est un service hôte, pas une dépendance
   backend.** Résoudre
   `app(\SuperAICore\Services\AutoModelRouter::class)` et appeler
   `select($messages, $systemPrompt, $options)` retourne le model
   id que ce dispatch doit cibler. Câblez-le dans votre dispatcher /
   planificateur custom quand vous voulez l'heuristique du SDK sans
   coupler au backend SuperAgent. Les hôtes qui ne résolvent pas le
   service ne voient rien changer.

4. **`CompressionStrategyFactory` est opt-in pour les hôtes qui
   pilotent leur propre `ContextManager`.** Le flux par défaut de
   `SuperAgentBackend` est one-shot (`max_turns=1`) et ne construit
   pas de `ContextManager`. Les hôtes qui font tourner de longs
   boucles sous-agent ou des sessions browser-tool appellent
   `app(\SuperAICore\Services\CompressionStrategyFactory::class)->build(…)`
   en montant leur propre context manager ; la factory retourne un
   `CacheAwareCompressor` autour du `ConversationCompressor` standard
   pour que la frontière de summary atterrisse APRÈS le préfixe de
   cache.

5. **`UntrustedInputHelper` couvre le texte libre que le SDK
   n'enveloppe pas déjà.** Le `Goals\GoalManager` du SDK 0.9.8
   enveloppe automatiquement `goal.objective` via le template
   `continuation.md` — NE PAS double-envelopper là. Cet helper est
   pour les entrées de mémoire ad-hoc, les descriptions de
   workspace plugin, la doc d'outil MCP importée de serveurs tiers,
   et tout input de formulaire UI hôte que vous concaténez dans un
   system prompt. Désactivable via `AI_CORE_UNTRUSTED_INPUT=false`
   quand vous avez besoin de prompts byte-identical (tests,
   comparaisons de dispatch).

6. **Le limiteur de taux est per-process.** Les swarms distribués
   (un agent par pod) ont besoin d'un limiteur partagé — le chemin
   le plus propre est un middleware Guzzle Redis-backed sur le HTTP
   client du provider ; cette registry reste simple et NE
   concurrence PAS ce pattern. Les defaults correspondent au budget
   de retry 429 par appel du SDK (8 RPS / 16 burst) ; les overrides
   par provider vont dans `super-ai-core.rate_limits.<provider>`.

7. **`reasoning_effort` est une option par appel sur
   `Dispatcher::dispatch()`.** Trois niveaux (`off` / `high` /
   `max`). Route vers la bonne forme de body selon l'upstream
   (`reasoning_effort` top-level pour la plupart des providers,
   `chat_template_kwargs` pour NVIDIA NIM, etc.). Silencieusement
   ignoré par les providers qui n'implémentent pas
   `SupportsReasoningEffort`. Nourrit aussi l'heuristique
   d'escalation d'`AutoModelRouter` quand mis à `max`.

8. **Commandes console `smart` et `squad`.** Les deux passent
   directement à la CLI vendor `superagent`
   (`vendor/forgeomni/superagent/bin/superagent`). Réutilise les
   credentials SuperAgent existantes de l'opérateur et le
   comportement de la CLI SDK plutôt que de réimplémenter
   l'orchestrateur en PHP :
   ```bash
   ./vendor/bin/superaicore smart "audite ce diff"
   ./vendor/bin/superaicore smart show --last
   ./vendor/bin/superaicore squad "refactore le module auth" --max-cost=2.0
   ./vendor/bin/superaicore squad --no-squad "compare avec le chemin legacy"
   ```
   Passez `--binary=/abs/path/to/superagent` quand le SDK est
   installé hors de `vendor/`.

Voir [docs/advanced-usage.fr.md §28](docs/advanced-usage.fr.md) pour
les recettes complètes — pipelines Squad, intégration
AutoModelRouter, câblage CacheAwareCompressor, overrides
RateLimiterRegistry, intégration UI chat AdHocMemoryRegistry,
side-panels ConversationForkService, endpoints de complétion DeepSeek
FIM.

**0.9.7 — quatre nouvelles migrations ; pin SDK passe à `^1.0.5`.**
Bump de capacité SDK 1.0.5 (correctifs transcoder handoff
cross-provider, matching de permissions opencode `BashArity`, résumé
compacté à 7 sections (opencode), vrai client LSP + `LSPTool`,
détection sémantique de boucle `LlmLoopChecker`, serveur ACP v1 stdio,
Gemini 3.5 / 3.x avec thinking + grounding + thought-parts) plus dix
fonctionnalités inspirées d'opencode (résumé de diff par fichier avec
revert, outil HITL `ask_user` mid-run, rétention de snapshots, rappels
de session, ruleset de permissions par agent, dérivation de permissions
sous-agent, mode plan, sessions shell PTY long-terme, file de partage
de session). Tous les bindings sont additifs et opt-in — le
comportement pré-0.9.7 est préservé tant que vous n'activez pas le
flag correspondant.

```bash
composer update forgeomni/superaicore forgeomni/superagent
php artisan vendor:publish --tag=super-ai-core-migrations
php artisan migrate
php artisan vendor:publish --tag=super-ai-core-config --force   # optionnel ; récupère les nouveaux blocs de config
```

Les quatre migrations sont additives + réversibles :

- `2026_05_20_000001_add_diff_summary_and_snapshots_to_ai_usage_logs.php`
  — `ai_usage_logs` reçoit `pre_snapshot` (varchar 64, nullable),
  `post_snapshot` (varchar 64, nullable), `file_diff_summary` (json,
  nullable). Les lignes pré-existantes sont à NULL ; les nouveaux
  dispatches via `SuperAgentBackend` les remplissent automatiquement.
- `2026_05_20_000002_create_ai_user_questions_table.php` — nouvelle
  table supportant l'outil HITL `ask_user`.
- `2026_05_20_000003_create_ai_pty_sessions_table.php` — nouvelle
  table supportant les endpoints PTY long-poll.
- `2026_05_20_000004_create_ai_session_shares_table.php` — nouvelle
  table supportant la file de partage de session.

Variables d'environnement optionnelles (chaque flag a un défaut sûr /
désactivé) :

```dotenv
# ─── Snapshots shadow-git + résumé de diff par fichier ───
AI_CORE_SNAPSHOT_ENABLED=true
AI_CORE_SNAPSHOT_PROJECT_ROOT=          # null → base_path() → getcwd()
AI_CORE_SNAPSHOT_RETENTION_DAYS=7
AI_CORE_SNAPSHOT_REVERT_ENABLED=true    # POST /usage/{id}/revert

# ─── Outil HITL `ask_user` mid-run ───
AI_CORE_TOOLS_ASK_USER=false            # off par défaut ; activez pour exposer l'outil

# ─── Outil LSP SDK 1.0.5 ───
AI_CORE_TOOLS_LSP=false                 # off par défaut ; activez pour l'outil lsp

# ─── Résumé compacté structuré (opencode) ───
AI_CORE_COMPRESSION_SUMMARY_PROMPT=     # mettez "structured" pour activer globalement

# ─── Mode plan CLI (Modes\CliPlanOrchestrator) ───
AI_CORE_PLAN_ENABLED=true
AI_CORE_PLAN_BACKEND=cli:claude_cli
AI_CORE_PLAN_BUILD_BACKEND=cli:claude_cli
AI_CORE_PLAN_DIR=.superagent/plans
AI_CORE_PLAN_AUTO_APPROVE=              # null → auto (HITL on = attendre, off = approuver)
AI_CORE_PLAN_APPROVAL_TIMEOUT=600

# ─── Sessions shell PTY long-terme ───
AI_CORE_PTY_ENABLED=false               # off par défaut ; opt-in selon le déploiement

# ─── File de partage de session ───
AI_CORE_SHARE_ENABLED=false
AI_CORE_SHARE_REMOTE_URL=               # URL de base du sharer distant (POST /api/shares/{id})
AI_CORE_SHARE_SECRET=                   # Bearer token envoyé au sharer distant
AI_CORE_SHARE_LOCAL_URL_TEMPLATE=       # fallback local sans remote ; placeholder {share_id}
```

Le ruleset de permissions par agent, les rappels de session et la
programmation du prune des snapshots vivent dans `super-ai-core.php` :

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
            'text' => "## Mode plan actif\nÉcrivez le plan dans `.superagent/plans/{session}.md`. N'appelez AUCUN outil edit/write contre le worktree projet.",
        ],
    ],
],
```

Programmez le prune de snapshots depuis `app/Console/Kernel.php` :

```php
protected function schedule(Schedule $schedule)
{
    $schedule->command('super-ai-core:snapshot-prune')->dailyAt('02:00');
}
```

Onze choses à revoir lors de la mise à jour :

1. **Le résumé de diff par fichier s'active automatiquement.** Quand
   le `GitShadowStore` SDK 1.0.5 peut snapshot le worktree, chaque
   dispatch `SuperAgentBackend::generate()` pose `pre_snapshot` /
   `post_snapshot` / `file_diff_summary` sur la ligne UsageLog. La page
   `/usage` rend un badge `+N −M` par ligne. Pour un comportement
   pré-0.9.7 byte-identique, posez `AI_CORE_SNAPSHOT_ENABLED=false`.
2. **L'outil HITL `ask_user` est OFF par défaut.** La boucle de polling
   de `AskUserTool::execute()` bloque l'agent jusqu'à `timeout_seconds`
   (défaut 600), ce qui est volontaire pour un usage interactif mais
   inadapté à un worker de queue qui doit se recycler. Activez
   `AI_CORE_TOOLS_ASK_USER=true` seulement quand un humain répondra
   devant `/processes`.
3. **Cadence de polling `/processes/questions`.** L'UI poll toutes les
   4s ; le serveur (dans `AskUserTool`) poll la DB toutes les 500ms.
   En concurrence élevée le coût est presque tout en polling
   serveur-side, pas en fanout navigateur.
4. **Revert est une écriture — sécurisez-la comme telle.** La route
   est protégée par `AI_CORE_SNAPSHOT_REVERT_ENABLED` (défaut on) ET
   hérite de la chaîne `super-ai-core.route.middleware`. En
   multi-tenant, ajoutez un middleware d'autorisation devant
   `/usage/{id}/revert`.
5. **`super-ai-core:snapshot-prune` est par hôte.** Il parcourt
   `~/.superagent/history/` de l'utilisateur qui lance la commande.
   Sur une machine multi-utilisateurs, programmez-le par utilisateur
   (ou normalisez le dossier shadow via
   `SUPERAGENT_HISTORY_DIR=/var/lib/superagent/history`).
6. **Le ruleset par agent ne se consulte QUE quand l'appelant n'a pas
   passé `allowed_tools` / `denied_tools`.** Les listes per-call
   explicites overrident le ruleset config-driven. C'est volontaire —
   les couches au-dessus de SuperAICore (PPT, SuperTeam, codex) calculent
   déjà leurs propres listes deny et ne doivent pas être overridées
   silencieusement.
7. **Le mode plan est enregistré dans `CliModeRouter` sous le nom
   `plan`.** Dispatch via `app(CliModeRouter::class)->dispatch('plan',
   $task, $ctx)`. Quand HITL est désactivé, l'orchestrateur auto-
   approuve (volontaire — pour garder l'orchestrateur utilisable en CI).
   Posez `AI_CORE_PLAN_AUTO_APPROVE=false` pour overrider.
8. **La dérivation de permissions sous-agent lit deux signaux.** Soit
   passez `parent_denied_tools` explicitement dans les options de
   dispatch enfant, soit passez `metadata.parent_agent` et laissez
   `PermissionEvaluator` résoudre le ruleset du parent. Le set deny
   est monotone parent → enfant — les enfants ne peuvent jamais
   élever.
9. **PTY est Phase 1 (long-poll, pas de stdin).** L'endpoint
   `/pty/sessions/{id}/write` renvoie 501 parce que PHP ne peut pas
   maintenir un pipe entre requêtes HTTP sans worker persistant.
   Utilisez une commande client-side type `expect` quand vous avez
   besoin d'entrée, ou attendez la Phase 2 (WebSocket via Laravel
   Reverb / Soketi).
10. **Le partage de session a deux modes.** REMOTE
    (`AI_CORE_SHARE_REMOTE_URL` posé) POSTe les lignes UsageLog +
    `file_diff_summary` vers un sharer externe avec un Bearer token ;
    LOCAL (`AI_CORE_SHARE_LOCAL_URL_TEMPLATE` posé) rend l'URL contre
    le propre SuperAICore de l'hôte — utile en intranet où "partager
    avec un collègue" veut dire "donner un lien vers la même instance
    Laravel".
11. **Les fonctionnalités Gemini 3.5 SDK 1.0.5 passent telles
    quelles.** Les options per-call `thinking` / `grounding` /
    `google_search` / `url_context` sont transmises à
    `Agent::run($prompt, $options)` et silencieusement ignorées par les
    providers non-Gemini. `EngineCatalog` liste `gemini-3.5-pro /
    -flash / -flash-lite` pour le moteur gemini-cli.

Voir [docs/advanced-usage.fr.md §29](docs/advanced-usage.fr.md) pour
les recettes complètes — tableau de bord diff par fichier, intégration
AskUserTool, bouton de revert, workflow mode plan, ruleset de
permissions par agent, héritage de permissions sous-agent, intégration
PTY long-poll, file de partage de session, programmation de la
rétention de snapshots.

**1.0.0 — première version stable ; aucune migration ; pin SDK passe à `^1.0.9`.**
Additif partout — aucun changement de schéma, aucun publish de config requis.
L'API publique est désormais stable selon SemVer (voir `docs/api-stability.md`).
Quatre choses à savoir :

1. **Claude Opus 4.8 est le nouveau modèle phare.** Le SDK 1.0.9 promeut
   `claude-opus-4-8` (prend l'alias `opus` ; contexte natif 1M, thinking
   entrelacé, mode rapide, contrôle d'effort). `ClaudeModelResolver`, le
   catalogue du moteur `claude`, `model_pricing` et les tiers **expert** de
   `squad` / `cli_squad` pointent désormais sur 4.8. Les hôtes épinglant un id
   Opus plus ancien continuent de fonctionner — les anciens ids restent au
   catalogue.
2. **xAI Grok arrive sur deux canaux.** (a) Le type de provider **API**
   facturé `grok` route via le backend `superagent` (`XAI_API_KEY` /
   `GROK_API_KEY`, défaut `grok-4.3`). (b) Le moteur **CLI sur abonnement**
   `grok_cli` (binaire `grok`, login grok.com) est un canal distinct. Ils
   partagent la marque, rien d'autre.
3. **Deux nouveaux moteurs CLI sur abonnement.** `cursor_cli` (Cursor Composer,
   `cursor-agent`) et `grok_cli` (Grok Build). Les deux en login `builtin`,
   facturés à l'abonnement (lignes d'usage à $0, coût fantôme depuis le
   catalogue). Activés par défaut ; désactivez via
   `AI_CORE_CURSOR_CLI_ENABLED=false` / `AI_CORE_GROK_CLI_ENABLED=false`. Ils
   apparaissent automatiquement dans `/providers`, `cli:status`, les sélecteurs
   de modèle, le tableau de bord des coûts et le moniteur de processus.
4. **Rien à défaire.** Les appelants pré-1.0.0 voient un comportement
   byte-identique ; redescendre le SDK à 1.0.7 fonctionne toujours pour les
   hôtes épinglés.

Voir [docs/advanced-usage.fr.md §30](docs/advanced-usage.fr.md) pour la recette
d'onboarding des CLI Cursor / Grok, le routage Opus 4.8, et la séparation des
canaux API-vs-CLI de Grok.

**1.0.2 — transition kimi-cli → kimi-code ; aucune migration ; pin SDK passe à
`^1.0.10`.** Additif partout — aucun changement de schéma, aucun publish de
config requis. Deux choses à savoir :

1. **Le backend `kimi_cli` supporte désormais les deux CLI kimi.** Le nouveau
   `@moonshot-ai/kimi-code` (TypeScript) de Moonshot remplace l'ancien
   `MoonshotAI/kimi-cli` (Python) ; les deux publient le même binaire `kimi`
   avec une surface headless + forme stream-json incompatibles. `KimiCliBackend`
   détecte automatiquement lequel est installé (sonde `kimi --help` mise en
   cache — l'ancien a un flag `--print`, kimi-code non) et adapte l'argv + le
   parsing sur les quatre chemins de spawn. Épinglez le dialecte avec
   `AI_CORE_KIMI_CLI_VARIANT` (`auto` par défaut / `kimi-code` / `kimi-cli`)
   pour éviter la sonde pendant la transition. L'id de backend Dispatcher
   `kimi_cli`, la carte `/providers` et les sélecteurs de modèle sont inchangés.
   (La parité agent-sync pour le modèle `.agents/` de kimi-code est un suivi
   tracé ; `KimiAgentSync` écrit encore l'agencement legacy `~/.kimi/agents/`.)
2. **Le SDK 1.0.10 durcit le chemin HTTP Kimi — de façon transparente.** Le pin
   passe `^1.0.9` → `^1.0.10`. Les types de provider HTTP direct `kimi` /
   `qwen` / `glm` / `deepseek` / `grok` / `openrouter` / `openai` (routés via le
   backend `superagent`) retrouvent le comptage `usage` en streaming
   (`stream_options.include_usage` — les appels streamés n'enregistrent plus
   $0), la normalisation stricte des schémas d'outils, `max_completion_tokens`
   pour les modèles de raisonnement Kimi, et la découverte de capacités par
   modèle. Nouveau garde opt-in `SUPERAGENT_KIMI_SWARM_ENABLED`. Rien à défaire
   — les appelants pré-1.0.2 voient un comportement identique.

Voir [docs/advanced-usage.fr.md §31](docs/advanced-usage.fr.md) et
`docs/kimi-cli-backend.md` §8 pour la recette de détection de variante et la
matrice des flags kimi-cli/kimi-code.

**1.0.5 — workflows cross-CLI SmartFlow ; aucune migration ; le pin SDK passe à
`^1.1.0`.** Additif partout — aucun changement de schéma ; ne publiez la config
que si vous la personnalisez (`php artisan vendor:publish --tag=super-ai-core-config`).
Trois choses à connaître :

1. **Nouvelle commande `flow` — workflows dynamiques cross-CLI.** SuperAICore porte
   le `Workflow` intégré de Claude Code sous le nom **SmartFlow** (`src/SmartFlow/`) :
   un jeu de primitives (`agent` / `parallel` / `pipeline` / `gate` / `council` /
   `budget` / `schema`) pilote n'importe quel backend enregistré, donc un flow peut
   planifier sur `claude_cli` et faire relire par `codex_cli` + `gemini_cli` en
   parallèle. Quatre flows intégrés sont livrés sous `resources/flows/*.yaml` ;
   répétez n'importe lequel à coût nul sans aucun CLI installé :
   ```bash
   ./vendor/bin/superaicore flow list
   ./vendor/bin/superaicore flow run cross-cli-review --args diff=@my.diff --rehearse
   ./vendor/bin/superaicore flow run cross-cli-dev --args goal="add caching" --concurrency 4
   ```
   Également monté sur artisan via `php artisan flow ...`. Les journaux par
   exécution vivent sous `~/.superaicore/flows` (override avec `SUPERAICORE_FLOW_DIR`) ;
   `--resume <id>` rejoue le préfixe inchangé à coût nul. Nouveau bloc de config
   `super-ai-core.smartflow.*` (`default_backend`, `concurrency`, `ledger_dir`,
   `flows_dir`, `budget`, `personas`) + env `AI_CORE_SMARTFLOW_*`.

2. **Fédération avec superagent.** Un flow peut déléguer un sous-flow au SmartFlow
   (cross-modèle) propre à superagent — `Flow::delegate()` ou `strategy: delegate`
   en YAML. Le mode **named** exécute l'un des flows propres à superagent (il se
   distribue lui-même à travers les providers de modèles) ; le mode **spec**
   exécute un flow dont SuperAICore a écrit la structure (superagent exécute selon
   l'instruction). Nécessite le SDK sur le classpath (c'est désormais le cas, pin
   `^1.1.0`) ; un SDK manquant ou un flow inconnu échoue proprement sans crasher le
   flow parent.
   ```bash
   ./vendor/bin/superaicore flow run cross-cli-federated \
       --args goal="add caching" --args research_provider=openai --rehearse
   ```

3. **Le SDK 1.1.0 apporte son propre SmartFlow (cross-modèle) — de façon
   transparente.** Le pin passe de `^1.0.10` à `^1.1.0` ; le backend `superagent`
   récupère le SmartFlow du SDK plus le durcissement wire-level 1.0.10→1.1.0. Aucun
   code SuperAICore ne dépend des classes SmartFlow du SDK hormis le pont de
   fédération optionnel. Rien à défaire — les appelants pré-1.0.5 voient un
   comportement identique.

Voir [docs/advanced-usage.fr.md §32](docs/advanced-usage.fr.md) et
[docs/smartflow.md](docs/smartflow.md) pour le guide SmartFlow complet —
primitives, écriture YAML, l'échelle de sortie structurée, resume et la recette de
fédération superagent.

**1.0.10 — GLM-5.2 vaisseau amiral natif ; aucune migration ; le pin SDK passe à
`^1.1.2`.** Additif partout — aucun changement de schéma ; publiez la config
seulement si vous voulez la table `model_pricing` rafraîchie (`php artisan
vendor:publish --tag=super-ai-core-config`). Deux points à connaître :

1. **GLM-5.2 est le nouveau défaut `glm`.** Le SDK 1.1.2 promeut `glm-5.2`
   (vaisseau amiral agentique orienté code de Z.ai : contexte 1M, sortie max
   128K, texte seul) au rang de vaisseau amiral natif `glm` et ajoute `glm-5.1`
   (contexte 200K). SuperAICore reflète les tarifs officiels Z.ai dans sa table
   `model_pricing` — `glm-5.2` / `glm-5.1` à **$1.40 entrée / $4.40 sortie** par
   1M avec un palier **$0.26 cache-hit**, `glm-5` à $1.00 / $3.20 — et sème
   `glm-5.2` dans les `available_models` du moteur `superagent` pour qu'il
   apparaisse dans les sélecteurs même hors ligne. Le raccourci nu `glm` et le
   défaut zéro-config résolvent désormais vers GLM-5.2 ; `glm-5` / `glm-4.x`
   restent joignables par id.

2. **`GlmProvider` gagne un cadran `reasoning_effort` — de façon transparente.**
   Le SDK 1.1.2 fait implémenter `SupportsReasoningEffort` à `GlmProvider`
   (rejoignant MiniMax M3) ; l'option par appel existante y route déjà. Aucun
   changement de site d'appel requis — `SuperAgentBackend` transférait déjà
   `reasoning_effort` / `thinking` génériquement.

**1.0.11 — Fable 5 & Sonnet 5 ; aucune migration ; le pin SDK passe à
`^1.1.5`.** Additif partout — aucun changement de schéma ; publiez la config
seulement si vous voulez la table `model_pricing` rafraîchie (`php artisan
vendor:publish --tag=super-ai-core-config`). Trois points à connaître :

1. **Fable 5 et Sonnet 5 sont désormais des modèles `anthropic` natifs.** Le
   SDK 1.1.5 ajoute `claude-fable-5` (le modèle le plus capable d'Anthropic :
   contexte 1M, sortie max 128K, pensée adaptative permanente, cadran d'effort)
   et `claude-sonnet-5` (le nouveau vaisseau amiral `sonnet`, sur la même
   surface adaptative de génération Claude 5). SuperAICore reflète les tarifs
   officiels dans `model_pricing` — Fable 5 **$10 entrée / $50 sortie** par 1M,
   Sonnet 5 **$3 / $15** (tarif de lancement $2/$10 jusqu'au 2026-08-31 ; la
   table porte le tarif officiel) — et sème les deux ids dans les
   `available_models` du moteur `superagent` pour les sélecteurs hors ligne.
   L'alias `sonnet` résout désormais vers Sonnet 5 côté SDK ; chaque id Claude
   antérieur reste joignable.

2. **La gamme Opus devient 3× moins chère — les tableaux de bord ont besoin de
   la nouvelle table.** Le SDK 1.1.5 corrige des tarifs périmés : l'Opus
   courant (`claude-opus-4-5`→`4-8`) est à **$5/$25** par 1M (au lieu de
   $15/$75) ; Haiku 4.5 à $1/$5. Le `model_pricing` de SuperAICore suit (seul
   l'instantané daté `claude-opus-4-20250514` garde l'historique $15/$75). Si
   votre hôte a publié une copie de config plus ancienne, republiez-la ou
   éditez-la — sinon `CostCalculator` continue de facturer Opus à l'ancien
   tarif. Le `anthropic` zéro-config résout désormais vers `claude-opus-4-8`
   côté SDK ; le palier EXPERT du Squad SDK route vers `claude-fable-5`, tandis
   que la config `squad.tiers` propre à SuperAICore reste inchangée (pointez
   `expert` vers `claude-fable-5` vous-même si vous voulez le palier du SDK).

3. **Le cadran `reasoning_effort` atteint désormais les modèles Anthropic — de
   façon transparente.** Le SDK 1.1.5 fait implémenter
   `SupportsReasoningEffort` à `AnthropicProvider`, mappant l'option par appel
   existante vers le `output_config.effort` GA d'Anthropic (Fable 5 /
   Sonnet 5 / Opus 4.5+ / Sonnet 4.6 ; les modèles non pris en charge ne
   renvoient jamais de 400) :

   ```php
   $dispatcher->dispatch([
       'backend'          => 'superagent',
       'prompt'           => 'Audite cette migration pour les race conditions.',
       'provider_config'  => ['provider' => 'anthropic', 'model' => 'claude-fable-5'],
       'reasoning_effort' => 'max',   // off | low…high | max
   ]);
   ```

   Aucun changement de site d'appel requis — `SuperAgentBackend` transférait
   déjà `reasoning_effort` / `thinking` génériquement. Le SDK gère aussi pour
   vous la surface de requête adaptative-seule de la génération Claude 5 (pas
   de `budget_tokens`, pas de paramètres d'échantillonnage, pas de prefill
   final), ce qui corrige au passage des 400 latents sur Opus 4.7/4.8.

Voir [docs/advanced-usage.fr.md §34](docs/advanced-usage.fr.md) (Fable 5 &
Sonnet 5 — la surface adaptative et le cadran d'effort Anthropic).

**1.1.0 — vague parité ai-dispatch ; aucune migration ; pin SDK inchangé.**
Additif : nouvelles commandes standalone + artisan `send`, `resume`, `runs`,
`aliases`, `preferences`, `doctor` et `skill:install-dispatch`, plus un
nouveau bloc de config `dispatch` (`aliases` / `retry_on_classes` /
`runs_path` / `preferences_path` — republiez la config pour le voir, ou
pilotez-le via `AI_CORE_RUNS_PATH` / `AI_CORE_PREFERENCES_PATH`). Les tables
de modèles Claude rattrapent la génération Claude 5 (famille `fable` ;
`sonnet` cible désormais `claude-sonnet-5`). Le durcissement conteneur-safe
de la console standalone (`Support\ConfigValue`) corrige `bin/superaicore`
dans les checkouts de dev. Voir
[docs/ai-dispatch-parity.md](docs/ai-dispatch-parity.md) et
[docs/advanced-usage.fr.md §35](docs/advanced-usage.fr.md).

**1.1.5 — la SKILL de délégation partout ; aucune migration ; pin SDK
inchangé.** Additif : `skill:install-dispatch` cible désormais aussi Grok /
Cursor / Qwen (`~/.grok/skills`, `~/.cursor/skills-cursor`,
`~/.qwen/skills`) ; `--agent all` couvre les six agents d'un coup, le
défaut reste claude seul. Le nouveau drapeau `--uninstall` annule une
installation antérieure sans toucher aux skills que vous avez écrites
vous-même. Rien à configurer ; pas de republication de config.

## Dépannage

- **`Class 'SuperAgent\Agent' not found`** — vous avez retiré `forgeomni/superagent` mais laissé `AI_CORE_SUPERAGENT_ENABLED=true`. Mettez-le à `false` ou réinstallez le SDK.
- **Backend CLI manquant** — exécutez `which claude` / `which codex`. Si vide, installez la CLI ou indiquez un chemin absolu dans `CLAUDE_CLI_BIN` / `CODEX_CLI_BIN`.
- **Rien dans `ai_usage_logs`** — vérifiez `AI_CORE_USAGE_TRACKING=true` et que les migrations sont passées.
- **Prompt `vendor:publish` ambigu** — passez explicitement un `--tag` de la liste ci-dessus.
