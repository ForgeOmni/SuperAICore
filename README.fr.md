# forgeomni/superaicore

[![tests](https://github.com/ForgeOmni/SuperAICore/actions/workflows/tests.yml/badge.svg)](https://github.com/ForgeOmni/SuperAICore/actions/workflows/tests.yml)
[![license](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![php](https://img.shields.io/badge/php-%E2%89%A58.1-blue.svg)](composer.json)
[![laravel](https://img.shields.io/badge/laravel-10%20%7C%2011%20%7C%2012-orange.svg)](composer.json)

[English](README.md) · [简体中文](README.zh-CN.md) · [Français](README.fr.md)

Package Laravel pour l'exécution unifiée d'IA sur plusieurs backends : **Claude CLI**, **Codex CLI**, **SuperAgent SDK**, **Anthropic API**, **OpenAI API**. Livré avec une CLI indépendante du framework, un dispatcher par capacité, la gestion des serveurs MCP, le suivi d'usage, l'analyse des coûts et une interface d'administration complète.

Fonctionne de façon autonome dans une installation Laravel neuve. L'UI est optionnelle et entièrement remplaçable : elle peut être intégrée dans une application hôte (par ex. SuperTeam) ou désactivée si seuls les services sont nécessaires.

## Relation avec SuperAgent

`forgeomni/superaicore` et `forgeomni/superagent` sont des **packages frères, pas une relation parent-enfant** :

- **SuperAgent** est un SDK PHP léger, en processus, qui pilote une seule boucle LLM avec tool-use (un agent, une conversation).
- **SuperAICore** est une couche d'orchestration à l'échelle de Laravel — elle choisit le backend, résout les identifiants du provider, route par capacité, suit l'usage, calcule les coûts, gère les serveurs MCP et fournit une UI d'administration.

**SuperAICore ne dépend pas de SuperAgent pour fonctionner.** SuperAgent n'est que l'un des cinq backends. Les quatre autres (Claude CLI, Codex CLI, Anthropic API, OpenAI API) fonctionnent sans lui, et `SuperAgentBackend` se déclare poliment indisponible via un contrôle `class_exists(Agent::class)` lorsque le SDK est absent. Si vous n'avez pas besoin de SuperAgent, définissez `AI_CORE_SUPERAGENT_ENABLED=false` dans votre `.env` et le Dispatcher se rabat sur les backends restants.

L'entrée `forgeomni/superagent` dans `composer.json` est présente pour que le backend SuperAgent compile tel quel. Si vous ne l'utilisez jamais, vous pouvez la retirer du `composer.json` de votre application hôte avant `composer install` — aucun autre code de SuperAICore n'importe l'espace de noms SuperAgent.

## Fonctionnalités

- **Cinq backends enfichables** — Claude CLI, Codex CLI, SuperAgent, Anthropic API, OpenAI API, unifiés derrière un même contrat `Dispatcher`.
- **Modèle Provider / Service / Routing** — associer des capacités abstraites (`summarize`, `translate`, `code_review`…) à des services concrets, puis les services à des identifiants provider.
- **Gestionnaire de serveurs MCP** — installer, activer et configurer les serveurs MCP depuis l'UI d'administration.
- **Suivi d'usage** — chaque appel persiste les tokens prompt/réponse, la durée et le coût dans `ai_usage_logs`.
- **Analyse des coûts** — table de tarification par modèle, cumuls en USD, tableau de bord avec graphiques.
- **Moniteur de processus** — inspecter les processus IA en cours, suivre les logs, terminer les processus orphelins.
- **UI trilingue** — anglais, chinois simplifié, français, commutable à l'exécution.
- **Compatible hôte** — désactiver routes/vues, changer le layout Blade ou réutiliser le lien de retour et le sélecteur de langue dans l'application parente.

## Prérequis

- PHP ≥ 8.1
- Laravel 10, 11 ou 12
- Guzzle 7, Symfony Process 6/7

Optionnel, uniquement quand le backend correspondant est activé :

- `claude` CLI dans `$PATH` pour le backend Claude CLI
- `codex` CLI dans `$PATH` pour le backend Codex CLI
- Clé API Anthropic ou OpenAI pour les backends HTTP

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
# Lister les backends et leur disponibilité
./vendor/bin/super-ai-core list-backends

# Appeler n'importe quel backend
./vendor/bin/super-ai-core call "Bonjour" --backend=anthropic_api --api-key=sk-ant-...
./vendor/bin/super-ai-core call "Bonjour" --backend=claude_cli
./vendor/bin/super-ai-core call "Bonjour" --backend=superagent
```

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
Dispatcher ← BackendRegistry  ← { ClaudeCli, CodexCli, SuperAgent, AnthropicApi, OpenAiApi }
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
