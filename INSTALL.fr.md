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

## 9. Mise à jour

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

## Dépannage

- **`Class 'SuperAgent\Agent' not found`** — vous avez retiré `forgeomni/superagent` mais laissé `AI_CORE_SUPERAGENT_ENABLED=true`. Mettez-le à `false` ou réinstallez le SDK.
- **Backend CLI manquant** — exécutez `which claude` / `which codex`. Si vide, installez la CLI ou indiquez un chemin absolu dans `CLAUDE_CLI_BIN` / `CODEX_CLI_BIN`.
- **Rien dans `ai_usage_logs`** — vérifiez `AI_CORE_USAGE_TRACKING=true` et que les migrations sont passées.
- **Prompt `vendor:publish` ambigu** — passez explicitement un `--tag` de la liste ci-dessus.
