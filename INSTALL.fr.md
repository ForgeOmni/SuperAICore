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
  - Clé API Anthropic — pour `anthropic_api`
  - Clé API OpenAI — pour `openai_api`

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
AI_CORE_SUPERAGENT_ENABLED=true
AI_CORE_ANTHROPIC_API_ENABLED=true
AI_CORE_OPENAI_API_ENABLED=true
CLAUDE_CLI_BIN=claude
CODEX_CLI_BIN=codex
ANTHROPIC_BASE_URL=https://api.anthropic.com
OPENAI_BASE_URL=https://api.openai.com

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
./vendor/bin/super-ai-core list-backends

# Aller-retour via l'API Anthropic
./vendor/bin/super-ai-core call "ping" --backend=anthropic_api --api-key="$ANTHROPIC_API_KEY"
```

Attendu : une courte réponse texte et un bloc d'usage.

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

## 8. Mise à jour

```bash
composer update forgeomni/superaicore
php artisan vendor:publish --tag=super-ai-core-migrations --force
php artisan migrate
```

Consultez [CHANGELOG.md](CHANGELOG.md) avant un `--force` sur la config.

## Dépannage

- **`Class 'SuperAgent\Agent' not found`** — vous avez retiré `forgeomni/superagent` mais laissé `AI_CORE_SUPERAGENT_ENABLED=true`. Mettez-le à `false` ou réinstallez le SDK.
- **Backend CLI manquant** — exécutez `which claude` / `which codex`. Si vide, installez la CLI ou indiquez un chemin absolu dans `CLAUDE_CLI_BIN` / `CODEX_CLI_BIN`.
- **Rien dans `ai_usage_logs`** — vérifiez `AI_CORE_USAGE_TRACKING=true` et que les migrations sont passées.
- **Prompt `vendor:publish` ambigu** — passez explicitement un `--tag` de la liste ci-dessus.
