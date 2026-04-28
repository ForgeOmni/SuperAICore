# Usage avancé

[English](advanced-usage.md) · [简体中文](advanced-usage.zh-CN.md) · [Français](advanced-usage.fr.md)

Recettes pratiques pour les fonctionnalités SuperAICore qui ne tiennent pas dans le README. Ce guide couvre spécifiquement le chemin **superagent** — les six backends CLI sont documentés séparément ([streaming-backends](streaming-backends.md), [spawn-plan-protocol](spawn-plan-protocol.md), [task-runner-quickstart](task-runner-quickstart.md)).

Les exemples visent 0.7.0+ sauf indication contraire. Les fonctionnalités arrivées plus tôt portent une étiquette `(depuis X.Y.Z)`.

## Sommaire

1. [Round-trip de la clé d'idempotence](#1-round-trip-de-la-clé-didempotence)
2. [Passthrough W3C trace context](#2-passthrough-w3c-trace-context)
3. [Exceptions provider classifiées](#3-exceptions-provider-classifiées)
4. [API OpenAI Responses](#4-api-openai-responses)
5. [Routage vers l'abonnement ChatGPT via OAuth](#5-routage-vers-labonnement-chatgpt-via-oauth)
6. [Auto-détection Azure OpenAI](#6-auto-détection-azure-openai)
7. [LM Studio — serveur OpenAI-compat local](#7-lm-studio--serveur-openai-compat-local)
8. [Headers HTTP déclaratifs par type de provider](#8-headers-http-déclaratifs-par-type-de-provider)
9. [Feature dispatcher SDK — `extra_body` / `features` / `loop_detection`](#9-feature-dispatcher-sdk)
10. [Prompt-cache keys (Kimi)](#10-prompt-cache-keys-kimi)
11. [Étendre le registre de types de provider](#11-étendre-le-registre-de-types-de-provider)
12. [Spawn CLI hôte via `ScriptedSpawnBackend`](#12-spawn-cli-hôte-via-scriptedspawnbackend)
13. [Écritures `.mcp.json` portables](#13-écritures-mcpjson-portables)
14. [Adaptateur host-config SuperAgent — `createForHost`](#14-adaptateur-host-config-superagent--createforhost)
15. [Handoff de provider en milieu de conversation (`Agent::switchProvider`)](#15-handoff-de-provider-en-milieu-de-conversation-agentswitchprovider)
16. [Moteur de skills — télémétrie, ranking, évolution mode FIX](#16-moteur-de-skills--télémétrie-ranking-évolution-mode-fix)

---

## 1. Round-trip de la clé d'idempotence

*Depuis 0.6.6 (fenêtre), 0.7.0 (round-trip SDK).*

SuperAICore avait depuis 0.6.6 une fenêtre de dédup 60s sur `ai_usage_logs` via `idempotency_key` — passez la clé dans les options `Dispatcher::dispatch()` et les appels répétés se réduisent à une ligne. 0.7.0 boucle la boucle à travers le SDK : la clé voyage maintenant avec `AgentResult::$idempotencyKey` (SDK 0.9.1), donc l'écriture du Dispatcher lit la clé que le SDK a effectivement observée au lieu de la recalculer.

```php
use SuperAICore\Services\Dispatcher;

$dispatcher = app(Dispatcher::class);

// Clé explicite — l'appelant sait.
$r1 = $dispatcher->dispatch([
    'prompt'          => $prompt,
    'backend'         => 'superagent',
    'provider_config' => ['type' => 'anthropic', 'api_key' => env('ANTHROPIC_API_KEY')],
    'idempotency_key' => "checkout:{$order->id}:line:{$line->id}",
]);

// Même appel dans les 60s → même id de ligne ai_usage_logs.
$r2 = $dispatcher->dispatch([
    'prompt'          => $prompt,
    'backend'         => 'superagent',
    'provider_config' => ['type' => 'anthropic', 'api_key' => env('ANTHROPIC_API_KEY')],
    'idempotency_key' => "checkout:{$order->id}:line:{$line->id}",
]);

assert($r1['usage_log_id'] === $r2['usage_log_id']);
assert($r1['idempotency_key'] === 'checkout:…');  // echoée depuis l'enveloppe
```

L'auto-dérivation depuis `external_label` fonctionne toujours — si vous ne passez pas `idempotency_key` mais `external_label`, le Dispatcher utilise `"{backend}:{external_label}"`. Passez `idempotency_key => false` pour désactiver complètement l'auto-dédup (rare — chaque appel légitimement distinct).

**Pourquoi le round-trip compte :** les hôtes dont le Dispatcher tourne dans un worker web mais dont UsageRecorder écrit depuis un worker queue (typique avec Laravel Horizon) n'ont plus besoin de threader la clé à travers les payloads de job — elle voyage avec l'enveloppe. Voir [docs/idempotency.md](idempotency.md) pour le contrat complet.

---

## 2. Passthrough W3C trace context

*Depuis 0.7.0.*

Transmettez un header `traceparent` entrant sur chaque appel LLM et le SDK le projette dans l'enveloppe `client_metadata` de l'API OpenAI Responses. Résultat : les logs côté OpenAI + votre trace distribuée hôte se rejoignent sans couche wrapper.

```php
// app/Http/Middleware/AttachTraceContext.php
public function handle($request, Closure $next)
{
    // Ce middleware tourne habituellement avant vos contrôleurs
    // pour propager traceparent / tracestate dans le cycle de vie
    // de la requête. SuperAICore lit les headers depuis la Request
    // au dispatch.
    return $next($request);
}

// n'importe où un appel request-scoped démarre :
$result = app(Dispatcher::class)->dispatch([
    'prompt'          => $prompt,
    'backend'         => 'superagent',
    'provider_config' => ['type' => 'openai-responses', 'api_key' => env('OPENAI_API_KEY')],
    'traceparent'     => $request->header('traceparent'),  // sûr quand null
    'tracestate'      => $request->header('tracestate'),
]);
```

Les providers non-`openai-responses` ignorent silencieusement le header — passage inconditionnel sûr depuis un helper de dispatcher partagé. Les traceparent invalides (qui ne matchent pas la forme W3C `00-<32hex>-<16hex>-<2hex>`) sont éliminés sans erreur.

Si vous avez déjà créé un `SuperAgent\Support\TraceContext` ailleurs (par ex. un job en arrière-plan qui veut démarrer une trace racine), passez-le directement :

```php
use SuperAgent\Support\TraceContext;

$trace = TraceContext::fresh();   // aléatoire, échantillonné
$dispatcher->dispatch([
    ...,
    'trace_context' => $trace,
]);
```

---

## 3. Exceptions provider classifiées

*Depuis 0.7.0 (côté hôte), SDK 0.9.1 (classification).*

Avant 0.7.0 chaque échec backend SuperAgent atterrissait dans un seul bucket de logs : « SuperAgentBackend error: <message> ». Maintenant le SDK lève six sous-classes typées (`ContextWindowExceeded`, `QuotaExceeded`, `UsageNotIncluded`, `CyberPolicy`, `ServerOverloaded`, `InvalidPrompt`) et `SuperAgentBackend::generate()` les attrape individuellement, émettant un tag `error_class` stable + verdict `retryable`.

Lire la classification dans la télémétrie opérateur nécessite juste un drain de logs qui indexe le champ `error_class` :

```
[warning] SuperAgentBackend error [context_window_exceeded]: context too long
    error_class=context_window_exceeded retryable=false
```

### Routage plus intelligent sur échec

Le contrat par défaut retourne `null` sur échec donc les appelants du Dispatcher voient « aucun backend n'a donné de réponse » et tombent. Si vous voulez réagir à des modes d'échec spécifiques (compacter-puis-retry sur context overflow, cycler les providers sur quota épuisé, backoff sur overload), sous-classez `SuperAgentBackend` et surchargez la seam `logProviderError` pour faire remonter la classification sur l'enveloppe :

```php
use SuperAICore\Backends\SuperAgentBackend;

class RoutingSuperAgentBackend extends SuperAgentBackend
{
    public ?string $lastErrorClass = null;

    protected function logProviderError(\Throwable $e, string $code): void
    {
        $this->lastErrorClass = $code;
        parent::logProviderError($e, $code);
    }
}
```

Liez-le dans votre `AppServiceProvider` :

```php
$this->app->extend(\SuperAICore\Services\BackendRegistry::class, function ($registry) {
    $registry->register(new RoutingSuperAgentBackend(logger()));
    return $registry;
});
```

Puis dans votre propre wrapper de dispatcher :

```php
$result = $dispatcher->dispatch($opts);
if ($result === null) {
    $backend = app(\SuperAICore\Backends\SuperAgentBackend::class);
    if ($backend->lastErrorClass === 'context_window_exceeded') {
        // compacter l'historique et retenter
    } elseif ($backend->lastErrorClass === 'quota_exceeded') {
        // basculer sur une autre ligne provider et retenter
    }
}
```

---

## 4. API OpenAI Responses

*Depuis 0.7.0.*

Le type de provider `openai-responses` route via l'`OpenAIResponsesProvider` du SDK contre `/v1/responses`. Avantages clés sur Chat Completions :

- **Multi-tours avec état via `previous_response_id`.** Le SDK thread un response id assigné par le serveur à travers les tours suivants donc vous ne renvoyez pas le contexte de conversation.
- **`reasoning.effort` et `text.verbosity` fins.** Le SDK traduit le `features.thinking.*` de SuperAICore sur la shape Responses-API.
- **`prompt_cache_key` au niveau wire.** Le même knob `features.prompt_cache_key.session_id` fonctionne.

```php
$r = $dispatcher->dispatch([
    'prompt'  => $prompt,
    'backend' => 'superagent',
    'provider_config' => [
        'type'    => 'openai-responses',
        'api_key' => env('OPENAI_API_KEY'),
    ],
    'model' => 'gpt-5',
    'features' => [
        'thinking'         => ['effort' => 'medium'],
        'prompt_cache_key' => ['session_id' => $conversationId],
    ],
]);
```

### Multi-tours sans renvoyer le contexte

Le SDK stocke les responses côté serveur par défaut (`store: true`). Pour le prochain tour, passez l'id du response précédent dans `extra_body` :

```php
$turn2 = $dispatcher->dispatch([
    'prompt'  => 'Et X, alors ?',
    'backend' => 'superagent',
    'provider_config' => ['type' => 'openai-responses', 'api_key' => env('OPENAI_API_KEY')],
    'extra_body' => [
        'previous_response_id' => $turn1['response_id'] ?? null,  // SDK l'echoe sur succès
    ],
]);
```

Pour des tours sans état (raisons réglementaires, prompts secrets), définissez `extra_body.store = false` sur le premier appel.

---

## 5. Routage vers l'abonnement ChatGPT via OAuth

*Depuis 0.7.0 — s'appuie sur la détection de backend ChatGPT du SDK 0.9.1.*

Vous payez ChatGPT Plus / Pro / Business ? Votre quota d'abonnement est utilisable pour des appels type API via `chatgpt.com/backend-api/codex`. Le type `openai-responses` de SuperAICore y route automatiquement quand la ligne provider stocke un `access_token` dans `extra_config` au lieu d'un `api_key` sur le champ de premier niveau.

**Flux OAuth côté hôte.** L'OAuth vous appartient — le CLI `codex` d'Anthropic le fait, ainsi que le client OSS `codex`. Une fois un access_token frais :

```php
use SuperAICore\Models\AiProvider;

$provider = AiProvider::create([
    'scope'        => 'global',
    'backend'      => AiProvider::BACKEND_SUPERAGENT,
    'type'         => AiProvider::TYPE_OPENAI_RESPONSES,
    'name'         => 'ChatGPT Plus (OAuth)',
    'api_key'      => null,   // laisser vide
    'extra_config' => [
        'access_token'   => $tokens['access_token'],
        'refresh_token'  => $tokens['refresh_token'],
        'expires_at'     => $tokens['expires_at']->toIso8601String(),
    ],
    'is_active'    => true,
]);
```

Le SDK bascule `base_url` sur `https://chatgpt.com/backend-api/codex` (retire le préfixe `/v1/`), envoie l'access_token en `Authorization: Bearer …`, et vos requêtes sont facturées contre l'abonnement. Les limites de taux et la disponibilité des modèles reflètent le plan ChatGPT, pas le tier API.

Rafraîchissez le token avec votre propre job — le SDK ne rafraîchit pas un `access_token` sur le provider `openai-responses` (c'est une préoccupation de l'hôte).

---

## 6. Auto-détection Azure OpenAI

*Depuis 0.7.0.*

Pointez `base_url` vers votre déploiement Azure et le SDK auto-détecte via six marqueurs d'URL (`openai.azure.`, `cognitiveservices.azure.`, `aoai.azure.`, `azure-api.`, `azurefd.`, `windows.net/openai`). Aucun flag de config nécessaire.

```php
AiProvider::create([
    'scope'        => 'global',
    'backend'      => AiProvider::BACKEND_SUPERAGENT,
    'type'         => AiProvider::TYPE_OPENAI_RESPONSES,
    'name'         => 'Azure OpenAI — eastus2',
    'api_key'      => env('AZURE_OPENAI_KEY'),
    'base_url'     => 'https://mycompany.openai.azure.com/openai/deployments/gpt-5',
    'extra_config' => [
        // Optionnel — surcharge l'api-version par défaut si votre déploiement est en retard :
        'azure_api_version' => '2024-10-21',
    ],
]);
```

Comportement en mode Azure :

- Les requêtes deviennent `/openai/responses?api-version=2025-04-01-preview` (défaut, surchargeable).
- Les headers `Authorization: Bearer` et `api-key: <key>` sortent tous les deux donc les deux chemins auth Azure fonctionnent.
- L'id de modèle doit matcher le nom de déploiement, pas l'id modèle OpenAI — Azure expose le sien.

---

## 7. LM Studio — serveur OpenAI-compat local

*Depuis 0.7.0.*

LM Studio fait tourner un serveur OpenAI-compat local (typiquement sur `http://localhost:1234/v1`). Le type `lmstudio` cible ça sans cérémonie d'auth — le SDK synthétise un header `Authorization` de substitution donc Guzzle ne râle pas.

```php
AiProvider::create([
    'scope'     => 'global',
    'backend'   => AiProvider::BACKEND_SUPERAGENT,
    'type'      => AiProvider::TYPE_LMSTUDIO,
    'name'      => 'LM Studio — local',
    'base_url'  => 'http://localhost:1234/v1',
    'is_active' => true,
]);
```

Cas d'usage :

- **Complètement hors ligne / on-prem** — pas de clés cloud, pas d'egress.
- **Ingénierie de prompts** — itérer contre un modèle local avant de cramer la dépense API.
- **Tests CI bloquants** — faites tourner LM Studio dans un container, pointez `base_url` dessus, exécutez votre suite de tests Dispatcher contre une vraie sortie de modèle.

Pour LM Studio sur un autre hôte du même LAN, pointez juste `base_url` sur son IP. Rien d'autre à changer.

---

## 8. Headers HTTP déclaratifs par type de provider

*Depuis 0.7.0.*

Deux nouveaux champs sur `ProviderTypeDescriptor` permettent aux apps hôtes d'injecter des headers HTTP sur chaque appel SDK d'un type de provider spécifique :

- `http_headers` — headers littéraux. Pour une identification statique : `X-App: my-host`.
- `env_http_headers` — map nom de header → nom de variable d'env. Le SDK lit la variable d'env à la requête ; omet silencieusement le header quand la variable n'est pas définie. Pour les headers project-scoped : `OpenAI-Project: <depuis env>`.

```php
// config/super-ai-core.php
return [
    // …
    'provider_types' => [
        // Les clés OpenAI project-scoped reçoivent un header OpenAI-Project sur chaque appel.
        \SuperAICore\Models\AiProvider::TYPE_OPENAI => [
            'env_http_headers' => ['OpenAI-Project' => 'OPENAI_PROJECT'],
        ],

        // Idem pour le type Responses API. Tague aussi chaque appel pour LangSmith.
        \SuperAICore\Models\AiProvider::TYPE_OPENAI_RESPONSES => [
            'http_headers'     => ['X-Service' => 'my-host-app'],
            'env_http_headers' => [
                'OpenAI-Project'     => 'OPENAI_PROJECT',
                'LangSmith-Project'  => 'LANGSMITH_PROJECT',
            ],
        ],

        // Identification OpenRouter (le tier rate-limit l'utilise).
        'openrouter' => [
            'http_headers' => [
                'HTTP-Referer' => 'https://myapp.example.com',
                'X-Title'      => 'My Host App',
            ],
        ],
    ],
];
```

Les headers sont appliqués dans le `ChatCompletionsProvider` du SDK — ils voyagent sur chaque requête (chat + responses + listing de modèles + sonde de santé), donc votre télémétrie les voit uniformément.

---

## 9. Feature dispatcher SDK

*Depuis 0.6.9.*

Trois clés de plumbing sur `Dispatcher::dispatch()` quand `backend=superagent` :

### `extra_body` — échappatoire wire spécifique au vendor

Deep-merge au niveau top du body de chaque requête `ChatCompletionsProvider`. À utiliser quand vous avez besoin d'un champ que le SDK n'a pas encore exposé :

```php
$dispatcher->dispatch([
    ...,
    'backend' => 'superagent',
    'extra_body' => [
        'response_format' => ['type' => 'json_object'],  // mode JSON OpenAI
        'seed'            => 42,                         // sorties quasi-déterministes
    ],
]);
```

### `features` — features SDK routées par capacité

Routées via le `FeatureDispatcher` du SDK. Skip silencieux sur providers non supportés — passage inconditionnel sûr.

```php
$dispatcher->dispatch([
    ...,
    'features' => [
        // CoT avec fallback gracieux sur chaque provider
        'thinking'                  => ['effort' => 'high', 'budget' => 8000],
        // Cache prompt session-level Kimi (skip silencieux ailleurs)
        'prompt_cache_key'          => ['session_id' => $conversationId],
        // Marqueurs cache style Anthropic pour Qwen (shape DashScope native uniquement)
        'dashscope_cache_control'   => true,
    ],
]);
```

### `loop_detection` — attraper les agents qui s'emballent

Enveloppe le streaming handler dans `LoopDetectionHarness`. `true` utilise les défauts SDK ; un array surcharge les seuils :

```php
$dispatcher->dispatch([
    ...,
    'loop_detection' => [
        'tool_loop_threshold'     => 7,   // défaut 5 même outil+args d'affilée
        'stagnation_threshold'    => 10,  // défaut 8 même nom
        'file_read_loop_recent'   => 20,
        'file_read_loop_triggered' => 12,
        'content_loop_window'     => 100,
        'content_loop_repeats'    => 15,
        'thought_loop_repeats'    => 4,
    ],
]);
```

Les violations se déclenchent comme des wire events SDK — l'enveloppe AICore reste byte-exact pour les appelants qui n'opt-in pas.

---

## 10. Prompt-cache keys (Kimi)

*Depuis 0.6.9.*

Kimi supporte un cache de prompt au niveau session via un session id fourni par l'appelant (distinct des marqueurs par-bloc d'Anthropic). Un session id stable laisse Kimi réutiliser le cache de préfixe de prompt entre les tours d'une même conversation, réduisant drastiquement le coût en tokens d'entrée sur les runs multi-tours.

Deux formes équivalentes :

```php
// shorthand (préféré pour les appels à session unique)
$dispatcher->dispatch([
    ...,
    'backend'          => 'superagent',
    'provider_config'  => ['type' => 'openai-compatible', 'api_key' => env('KIMI_API_KEY'),
                           'base_url' => 'https://api.moonshot.ai/v1', 'provider' => 'kimi'],
    'prompt_cache_key' => $sessionId,
]);

// explicite (pour la symétrie avec les autres `features.*`)
$dispatcher->dispatch([
    ...,
    'features' => [
        'prompt_cache_key' => ['session_id' => $sessionId],
    ],
]);
```

Les providers non-Kimi sautent silencieusement la feature — passage sûr depuis un dispatcher partagé.

---

## 11. Étendre le registre de types de provider

*Depuis 0.6.2.*

Les hôtes ajoutent des types de provider entièrement nouveaux via `super-ai-core.provider_types` sans forker le package. Exemple — enregistrer l'API Grok de xAI :

```php
// config/super-ai-core.php
return [
    // …
    'provider_types' => [
        'xai-api' => [
            'label_key'        => 'integrations.ai_provider_xai',
            'desc_key'         => 'integrations.ai_provider_xai_desc',
            'icon'             => 'bi-x-lg',
            'fields'           => ['api_key'],
            'default_backend'  => \SuperAICore\Models\AiProvider::BACKEND_SUPERAGENT,
            'allowed_backends' => [\SuperAICore\Models\AiProvider::BACKEND_SUPERAGENT],
            'env_key'          => 'XAI_API_KEY',
            'sdk_provider'     => 'xai',                    // (0.7.0+) clé registry SDK
            'http_headers'     => ['X-App' => 'my-host'],   // (0.7.0+) optionnel
            'env_http_headers' => ['X-App-Version' => 'APP_VERSION'],
        ],
    ],
];
```

Le registre est adressable à `app(ProviderTypeRegistry::class)`. L'UI `/providers`, `ProviderEnvBuilder`, `AiProvider::requiresApiKey()`, et chaque backend qui s'intéresse aux types de provider reprennent automatiquement la nouvelle entrée.

Pour la shape complète du descripteur, voir `src/Support/ProviderTypeDescriptor.php`.

---

## 12. Spawn CLI hôte via `ScriptedSpawnBackend`

*Depuis 0.7.1.*

Les hôtes qui intègrent AICore (SuperTeam, SuperPilot, shopify-autopilot, …) portaient jusqu'ici un `match ($backend) { 'claude' => buildClaudeProcess(…), 'codex' => buildCodexProcess(…), 'gemini' => buildGeminiProcess(…) }` sur chaque spawn de tâche, plus une deuxième copie identique pour le chat one-shot. Chaque nouvel engine CLI (kiro, copilot, kimi, futurs) forçait un patch côté hôte. 0.7.1 introduit le contrat `Contracts\ScriptedSpawnBackend` — sibling de `StreamingBackend` — qui collapse les deux switches en un appel polymorphe. Les six backends CLI (`Claude` / `Codex` / `Gemini` / `Copilot` / `Kiro` / `Kimi`) l'implémentent dans la même release.

### Avant (match par backend, ~150 lignes dans le runner hôte)

```php
// Glu task-runner pré-0.7.1 côté hôte
$process = match ($engineKey) {
    'claude'  => $this->buildClaudeProcess($promptFile, $logFile, $projectRoot, $model, $env),
    'codex'   => $this->buildCodexProcess($promptFile, $logFile, $projectRoot, $model, $env),
    'gemini'  => $this->buildGeminiProcess($promptFile, $logFile, $projectRoot, $model, $env),
    'copilot' => $this->buildCopilotProcess($promptFile, $logFile, $projectRoot, $model, $env),
    'kiro'    => $this->buildKiroProcess($promptFile, $logFile, $projectRoot, $model, $env),
    'kimi'    => $this->buildKimiProcess($promptFile, $logFile, $projectRoot, $model, $env),
    default   => throw new \InvalidArgumentException("unknown engine: {$engineKey}"),
};
$process->start();
```

Chaque `buildXxxProcess()` traite sa propre composition d'argv, les flags `--output-format stream-json` et similaires, l'injection de config MCP, le scrub d'env (les 5 marqueurs `CLAUDE_CODE_*` de Claude, le passthrough `--config` de Codex), les capability transforms (renommage d'outils Gemini), le pipage wrapper-script et le cwd.

### Après (appel polymorphe unique)

```php
use SuperAICore\Services\BackendRegistry;

$backend = app(BackendRegistry::class)->forEngine($engineKey);  // nullable — null quand l'engine est désactivé en config
if ($backend === null) {
    throw new \RuntimeException("engine {$engineKey} has no scripted-spawn backend registered");
}

$process = $backend->prepareScriptedProcess([
    'prompt_file'             => $promptFile,
    'log_file'                => $logFile,
    'project_root'            => $projectRoot,
    'model'                   => $model,
    'env'                     => $env,          // construit côté hôte — lit IntegrationConfig
    'disable_mcp'             => $disableMcp,   // surtout Claude
    'codex_extra_config_args' => $codexArgs,    // surtout Codex
    'timeout'                 => 7200,
    'idle_timeout'            => 1800,
]);
$process->start();
```

`BackendRegistry::forEngine($engineKey)` itère les `dispatcher_backends` de l'engine dans l'ordre (le CLI d'abord par construction, ex. `claude → ['claude_cli', 'anthropic_api']`) et retourne le premier qui implémente `ScriptedSpawnBackend`. Retourne `null` quand l'engine n'a pas de backend CLI enregistré — soit désactivé via `AI_CORE_CLAUDE_CLI_ENABLED=false`, soit un engine superagent-only qui n'implémente pas le scripted spawn.

### Sibling one-shot chat — `streamChat()`

Tour de chat bloquant one-shot (texte prêt à afficher via `onChunk`, réponse accumulée renvoyée à la sortie). Le backend possède argv, passage prompt-vs-argv, parsing de sortie et strip ANSI (Kiro / Copilot émettent des codes couleur qui fuiraient sinon dans les UI hôtes) :

```php
$response = $backend->streamChat(
    $prompt,
    function (string $chunk) use ($ui) {
        $ui->append($chunk);
    },
    [
        'cwd'           => $projectRoot,
        'model'         => $model,
        'env'           => $env,
        'timeout'       => 0,            // 0 = pas de cap dur ; idle_timeout s'applique toujours
        'idle_timeout'  => 300,
        'allowed_tools' => ['Read', 'Bash'],  // Claude uniquement ; les autres CLI l'ignorent
    ]
);
```

### Helpers wrapper-script pour les implémenteurs

Si vous implémentez `ScriptedSpawnBackend` pour un nouveau CLI engine, le trait `Backends\Concerns\BuildsScriptedProcess` fournit la plomberie partagée :

- `buildWrappedProcess(…)` — écrit un script wrapper sh ou .bat qui pipe `prompt_file` via stdin, tee stdout+stderr vers `log_file`, applique cwd + env et retourne un `Symfony\Component\Process\Process` pré-configuré.
- `applyCapabilityTransform()` — réécrit le prompt file en place via `BackendCapabilities::transformPrompt()` (pour les backends qui ont besoin de renommer des outils ou d'injecter un préambule).
- `escapeFlags([…])` — enveloppe `escapeshellarg` sur une liste argv.

### Localisation du binaire CLI

`Support\CliBinaryLocator` est enregistré en singleton dans le service provider. Il centralise la sonde filesystem des binaires CLI dans les emplacements typiques macOS / Linux / Windows :

- `~/.npm-global/bin` (préfixe global npm)
- `/opt/homebrew/bin` et `/usr/local/bin` (Homebrew arm64 / x86_64)
- `~/.nvm/versions/node/<v>/bin` (chaque version nvm installée)
- `%APPDATA%/npm` Windows

Le nom du binaire vient de `EngineCatalog->cliBinary` — pas de `match`. Les hôtes qui veulent réutiliser les mêmes sondes pour leurs propres chemins CLI côté hôte peuvent injecter le locator :

```php
$locator = app(\SuperAICore\Support\CliBinaryLocator::class);
$claudePath = $locator->locate('claude');   // chemin absolu ou null
```

### Liste d'env-scrub Claude (pour les hôtes composant encore leurs propres processus `claude`)

`ClaudeCliBackend::CLAUDE_SESSION_ENV_MARKERS` est exposée en constante publique listant les 5 marqueurs d'env (`CLAUDECODE`, `CLAUDE_CODE_ENTRYPOINT`, `CLAUDE_CODE_SSE_PORT`, `CLAUDE_CODE_EXECPATH`, `CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS`) à scrub de l'env enfant pour que le garde de récursion parent-session de Claude ne refuse pas de démarrer. Les hôtes qui gardent leur propre composition de processus lisent la constante plutôt que de re-dériver la liste :

```php
use SuperAICore\Backends\ClaudeCliBackend;

$env = array_diff_key($parentEnv, array_flip(ClaudeCliBackend::CLAUDE_SESSION_ENV_MARKERS));
```

### Pourquoi le contrat compte à long terme

Après adoption de `ScriptedSpawnBackend`, un nouveau CLI engine landé en amont apparaît automatiquement dans les runners hôtes — pas de patch hôte, pas de branche `match` à ajouter. C'est tout l'intérêt du contrat : chaque nouvel engine depuis 0.7.1 (Kimi de Moonshot, futur Qwen d'Alibaba, futur Mistral `le-chat`, …) devient visible dans les code paths hôtes dès que SuperAICore enregistre son backend. Voir [docs/host-spawn-uplift-roadmap.md](host-spawn-uplift-roadmap.md) pour le contexte complet — les 700 lignes de glu par backend qu'il remplace, le plan de migration par phases et le pre-soak caveat.

---

## 13. Écritures `.mcp.json` portables

*Depuis 0.8.1.*

Les fichiers `.mcp.json` générés ont toujours été déplaçables *si* vous les écriviez à la main avec des noms de commande nus (`node`, `uvx`, …) et des chemins placeholders `${SUPERTEAM_ROOT}/<rel>`. Mais chaque chemin d'écriture déclenché par UI sur `McpManager::install*()` résolvait les binaires via `which()` / `PHP_BINARY` et joignait les chemins relatifs au projet en absolus avant d'écrire — donc dès qu'un utilisateur cliquait "Install" ou "Install All", le fichier se faisait re-polluer par `C:\Program Files\nodejs\node.exe`, `/Users/jane/projects/foo/.mcp-servers/bar/dist/index.js`, des chemins venv-bin, etc. Le fichier cassait alors dès qu'il était synchronisé dans le checkout d'un coéquipier, monté dans un container ou copié vers un `${HOME}` différent.

0.8.1 ajoute un **mode portable** opt-in piloté par un seul knob de config :

```dotenv
# .env — n'importe quel nom de variable d'env exportée par votre runtime MCP convient
AI_CORE_MCP_PORTABLE_ROOT_VAR=SUPERTEAM_ROOT
```

Quand le knob est posé (default `null` = legacy "chemins absolus partout"), chaque writer bascule deux choses :

1. **Commandes nues** — `which('node')` / `PHP_BINARY` / `which('uvx')` etc. sont remplacés par `node` / `php` / `uvx`. Le PATH du moteur CLI au moment du spawn décide quel binaire tourne réellement — pas d'épinglage per-machine.
2. **Placeholders de chemin** — chaque chemin absolu sous `projectRoot()` est réécrit en `${SUPERTEAM_ROOT}/<rel>`. Les chemins hors de l'arbre (`/usr/share/...`, `/var/lib/...`) restent absolus. Le runtime MCP de l'hôte expand le placeholder au spawn time.

### Faire expander `${SUPERTEAM_ROOT}` par le runtime MCP

La plupart des runtimes MCP (Claude Code, Codex, Gemini, …) lisent l'env project-scope depuis `.claude/settings.local.json` ou équivalent, puis l'injectent dans les processus MCP-server spawnés. Câblez `SUPERTEAM_ROOT` à la racine projet une fois :

```jsonc
// .claude/settings.local.json
{
  "env": {
    "SUPERTEAM_ROOT": "${PWD}"
  }
}
```

La valeur exacte dépend de comment l'hôte tourne — pour une app Laravel servie par `php artisan serve`, `${PWD}` fonctionne. Pour les déploiements en container, posez `SUPERTEAM_ROOT=/srv/app` (ou ce qu'est votre racine projet in-container) dans le fichier env du container. Pour un queue worker qui boote depuis un cwd différent, exportez-la depuis l'unit systemd / la config supervisord.

### Ce que les writers produisent — avant et après

```jsonc
// Avant — chemins absolus legacy
{
  "mcpServers": {
    "ocr": {
      "command": "C:\\Users\\jane\\AppData\\Local\\Programs\\Python\\Python312\\python.exe",
      "args": ["C:\\Users\\jane\\projects\\acme\\.mcp-servers\\ocr\\main.py"]
    },
    "pdf-extract": {
      "command": "/opt/homebrew/Cellar/php/8.3.0/bin/php",
      "args": ["/Users/jane/projects/acme/artisan", "pdf:extract"]
    }
  }
}
```

```jsonc
// Après — portable, avec AI_CORE_MCP_PORTABLE_ROOT_VAR=SUPERTEAM_ROOT
{
  "mcpServers": {
    "ocr": {
      "command": "python",
      "args": ["${SUPERTEAM_ROOT}/.mcp-servers/ocr/main.py"]
    },
    "pdf-extract": {
      "command": "php",
      "args": ["${SUPERTEAM_ROOT}/artisan", "pdf:extract"]
    }
  }
}
```

### Règle d'égress — les placeholders se matérialisent dans les cibles per-machine

Le `.mcp.json` project-scope garde les placeholders pour la portabilité. Mais trois catégories de cibles *ne peuvent pas* être portables :

- **Configs backend user-scope** — `~/.codex/config.toml` (TOML, aucune expansion `${VAR}`), `~/.gemini/settings.json`, `~/.claude/settings.json`, `~/.copilot/...`, `~/.kiro/...`, `~/.kimi/...` sont intrinsèquement per-machine.
- **Flags runtime `codex exec -c`** — passés à `codex exec` en ligne de commande ; pas d'expansion env.
- **Helpers qui synthétisent des entrées MCP par-dessus `.mcp.json`** au moment du sync — `superfeedMcpConfig`, `codexOcrMcpConfig`, `codexPdfExtractMcpConfig` produisent des specs consommées par `syncAllBackends()` et `codexMcpConfigArgs()`.

Pour ces cas, `codexMcpServers()` fait passer chaque spec par `materializeServerSpec()` immédiatement avant de retourner. Le matérialisateur :

1. Remplace `${SUPERTEAM_ROOT}` par la valeur runtime de la variable (`getenv('SUPERTEAM_ROOT')`).
2. Retombe sur `projectRoot()` quand la variable n'est pas exportée dans le processus courant — fréquent pour les queue workers qui n'héritent pas de l'env de la requête web.
3. No-op quand la portabilité est désactivée (la spec est déjà absolue).

Effet net : un seul `.mcp.json` project-scope ship des commandes nues + placeholders ; chaque backend writer qui le consomme reçoit des commandes nues + des chemins absolus réels exactement quand il en a besoin.

### Helpers programmatiques

Si votre hôte a son propre writer de spec MCP qui a besoin du même traitement, les cinq helpers sur `McpManager` sont publics :

```php
use SuperAICore\Services\McpManager;

$mcp = app(McpManager::class);

// Direction forward — utilisée par les writers
$cmd  = $mcp->portableCommand('uvx', $resolvedUvxPath);   // 'uvx' ou absolute
$path = $mcp->portablePath('/Users/jane/proj/.mcp/foo');  // '${SUPERTEAM_ROOT}/.mcp/foo'

// Inverse — utilisée à l'égress vers les cibles non-portables
$abs  = $mcp->materializePortablePath('${SUPERTEAM_ROOT}/foo'); // '/srv/app/foo'
$spec = $mcp->materializeServerSpec([                            // walk command + args + env
    'command' => 'python',
    'args'    => ['${SUPERTEAM_ROOT}/script.py'],
    'env'     => ['DATA_DIR' => '${SUPERTEAM_ROOT}/data'],
]);

// Accesseur du knob — null si portabilité désactivée
$varName = $mcp->portableRootVar(); // 'SUPERTEAM_ROOT' ou null
```

### Pièges

- **Substitution `uv run` pour les serveurs Python pyproject.** Portabilité + `pyproject.toml` + `entrypoint_script` déclenche un changement de routage — au lieu d'épingler `command` à `<projectRoot>/.venv/bin/<script>` (chemin per-machine), le writer émet `command: "uv"`, `args: ["run", "<script>"]`. `uv` résout le venv au spawn time. Si votre hôte n'a pas `uv` sur le PATH au moment du spawn MCP, laissez la portabilité éteinte pour ce serveur (ou installez `uv`).
- **Entrées de registre `PHP_BINARY`.** L'entrée registre `pdf-extract` garde `'command' => PHP_BINARY` directement (pour que la forme du registre reste identique). `installArtisan` la normalise en `'php'` au write time quand la portabilité est on. Les entrées custom du registre qui hardcodent un binaire absolu dans `command` ont besoin du même traitement — passez-le par `portableCommand($bare, $resolved)` depuis votre writer.
- **Les chemins hors-arbre restent absolus.** `portablePath('/usr/share/foo')` retourne `/usr/share/foo` inchangé parce que `/usr/share` n'est pas sous `projectRoot()`. C'est intentionnel — le placeholder n'a de sens que pour les chemins relatifs à l'arbre.
- **Helpers Codex (`codexOcrMcpConfig` / `codexPdfExtractMcpConfig` / `superfeedMcpConfig`) écrivent dans `.mcp.json` project-scope.** Ils émettent des placeholders quand la portabilité est on. Le matérialisateur d'égress les ré-absolutise avant qu'ils n'atteignent `~/.codex/config.toml`. Le comportement pre-0.8.1 (toujours-absolu) est préservé verbatim quand la portabilité est off.

---

## 14. Adaptateur host-config SuperAgent — `createForHost`

*Depuis 0.8.5.*

`SuperAgentBackend::buildAgent()` ne bricole plus la construction des providers SDK à la main. La double branche region / non-region + l'injection HTTP-header manuelle se réduisent à un seul appel :

```php
// src/Backends/SuperAgentBackend.php (extrait — ce que le backend fait en interne)
$hostConfig = [
    'api_key'  => $providerConfig['api_key']  ?? null,
    'base_url' => $providerConfig['base_url'] ?? null,
    'model'    => $options['model']           ?? $providerConfig['model']    ?? null,
    'region'   => $providerConfig['region']   ?? null,
    'extra'    => [
        'http_headers'     => $descriptor->httpHeaders,
        'env_http_headers' => $descriptor->envHttpHeaders,
    ],
];
$agentConfig['provider'] = ProviderRegistry::createForHost($providerName, $hostConfig);
```

L'adaptateur per-key côté SDK possède la map de constructeur :

- **Adaptateur par défaut** — passe `api_key` / `base_url` / `model` / `max_tokens` / `region` directement, puis fait un deep-merge de `extra` après eux (`extra` ne peut donc pas écraser accidentellement un champ top-level). Couvre Anthropic / OpenAI / OpenAI-Responses / OpenRouter / Ollama / LMStudio / Gemini / Kimi / Qwen / Qwen-native / GLM / MiniMax.
- **Adaptateur `bedrock`** (intégré au SDK) — éclate `credentials.aws_access_key_id` / `aws_secret_access_key` / `aws_region` dans les slots `access_key` / `secret_key` / `region` du constructeur BedrockProvider. Retombe sur `host['api_key']` pour `access_key` si le bloc credentials structuré est absent.
- **Futures clés provider** — chacune embarque son propre adaptateur (ou utilise celui par défaut). Les nouveaux types provider du SDK arrivent ici sans aucun changement de code backend.

### Ce que les hôtes qui contournent `Dispatcher` doivent faire

La plupart des hôtes passent par `Dispatcher::dispatch(['backend' => 'superagent', …])` et ne touchent jamais cette couche. Mais les hôtes qui construisent un `Agent` directement — typiquement parce qu'ils veulent piloter `withSystemPrompt()` / `addTool()` / les hooks de streaming du SDK sans l'enveloppe Dispatcher — peuvent utiliser la même forme :

```php
use SuperAgent\Agent;
use SuperAgent\Providers\ProviderRegistry;
use SuperAICore\Services\ProviderTypeRegistry;
use SuperAICore\Models\AiProvider;

$row = AiProvider::find(42);
$descriptor = app(ProviderTypeRegistry::class)->get($row->type);
$sdkKey = $descriptor?->sdkProvider ?: $row->type;

$provider = ProviderRegistry::createForHost($sdkKey, [
    'api_key'  => $row->decrypted_api_key,
    'base_url' => $row->base_url,
    'model'    => $row->extra_config['default_model'] ?? null,
    'region'   => $row->extra_config['region']        ?? null,
    'extra'    => [
        // Les headers HTTP statiques + env-driven déclarés sur le
        // descriptor passent par `extra` sur l'adaptateur par défaut.
        // Les hôtes peuvent aussi y mettre n'importe quel knob
        // provider-spécifique que le SDK accepte : `organization`,
        // `reasoning`, `verbosity`, `store`, `extra_body`,
        // `prompt_cache_key`, `azure_api_version`, etc.
        'http_headers'     => $descriptor?->httpHeaders     ?? [],
        'env_http_headers' => $descriptor?->envHttpHeaders  ?? [],
        'organization'     => $row->extra_config['organization'] ?? null,
    ],
]);

$agent = new Agent([
    'provider'   => $provider,
    'max_turns'  => 5,
    'max_tokens' => 4000,
]);
```

### Pourquoi ça compte

- **Un appel factory par provider**, peu importe la clé. Les hôtes qui portaient un `match ($providerType) { 'bedrock' => new BedrockProvider([...]), 'openai' => new OpenAIProvider([...]), … }` se ramènent à une ligne. Les nouvelles clés provider SDK (`openai-responses` et `lmstudio` ont atterri en 0.7.0 ; les futures atterrissent transparently) marchent sans bras `match` à ajouter.
- **Les providers region-aware restent region-aware** sans que les appelants aient à connaître la map de region. Passez `'region' => 'cn'` sur Kimi / Qwen / GLM / MiniMax et le `regionToBaseUrl()` per-provider du SDK résout le bon endpoint. `'region' => 'code'` sur Kimi / Qwen route via le store de credentials OAuth (`KimiCodeCredentials` / `QwenCodeCredentials`) et retombe sur `api_key` si aucun token n'est en cache.
- **Les adaptateurs custom sont un point d'extension.** Les hôtes qui maintiennent leur propre classe provider (ex. un proxy interne avec auth non-standard) enregistrent un adaptateur une fois et le reste de l'hôte traite la clé comme n'importe quelle autre :

  ```php
  use SuperAgent\Providers\ProviderRegistry;

  ProviderRegistry::registerHostConfigAdapter('my-internal-proxy', static function (array $host): array {
      return [
          'api_key'  => $host['credentials']['internal_token'] ?? null,
          'base_url' => 'https://llm-proxy.internal/v1',
          'model'    => $host['model'] ?? 'gpt-4o',
          // …tout ce dont la classe provider concrète a besoin
      ];
  });

  // Puis partout ailleurs dans l'hôte :
  $provider = ProviderRegistry::createForHost('my-internal-proxy', $hostConfig);
  ```

### Substitution de test — seam `makeProvider()`

Les sous-classes backend qui ont besoin d'injecter un faux `LLMProvider` sans toucher au `ProviderRegistry` global peuvent overrider `makeProvider()` directement :

```php
class FakeSuperAgentBackend extends \SuperAICore\Backends\SuperAgentBackend
{
    protected function makeProvider(string $providerName, array $hostConfig): \SuperAgent\Contracts\LLMProvider
    {
        return new MyFakeProvider($hostConfig);
    }
}
```

`SuperAgentBackend::makeAgent()` reçoit toujours un `LLMProvider` construit (jamais une chaîne + clés llmConfig étalées) post-0.8.5 — les assertions de test devraient vérifier `$agentConfig['provider'] instanceof \SuperAgent\Contracts\LLMProvider` plutôt que de comparer à une chaîne nom-de-provider.

---

## 15. Handoff de provider en milieu de conversation (`Agent::switchProvider`)

*Depuis 0.8.5 (via SDK 0.9.5).*

Cette feature **n'est pas utilisée par SuperAICore lui-même** — `FallbackChain` traverse des sous-processus CLI, pas des providers SDK in-process, et `Dispatcher` ne porte pas d'état conversationnel entre les appels. Mais les hôtes qui wrappent `SuperAgentBackend` et pilotent l'`Agent` directement peuvent passer une conversation vivante à un autre provider en plein vol sans perdre l'historique. Utile pour des patterns "commencer pas cher, escalader sous pression de context-window" ou "rebuild ça avec un autre modèle quand le premier dérape".

```php
use SuperAgent\Agent;
use SuperAgent\Conversation\HandoffPolicy;

$agent = new Agent(['provider' => 'anthropic', 'api_key' => $key, 'model' => 'claude-opus-4-7']);
$agent->run('analyse this codebase');

// Passe à un modèle moins cher / plus rapide pour la phase suivante :
$agent->switchProvider('kimi', ['api_key' => $kimiKey, 'model' => 'kimi-k2-6'])
      ->run('write the unit tests');

// Vérifie si l'historique tient dans la context window du nouveau modèle —
// les tokenizers différents comptent le même historique avec 20–30% de drift :
$status = $agent->lastHandoffTokenStatus();
if ($status !== null && ! $status['fits']) {
    // Déclenche votre compression IncrementalContext existante avant le prochain appel
}

// Vous voulez garder les blocks signed-thinking d'Anthropic au cas où vous reveniez ?
$agent->switchProvider('kimi', [...], HandoffPolicy::preserveAll());

// La conversation déraille — réessayez avec un autre modèle sur ardoise propre :
$agent->switchProvider('openai', [...], HandoffPolicy::freshStart());
```

Les trois presets de policy :

- **`HandoffPolicy::default()`** — garde l'historique tool, drop le signed thinking, append un marker system de handoff, reset les ids de continuation. Défaut sensé pour "switch sur un autre modèle et continue".
- **`HandoffPolicy::preserveAll()`** — garde tout dans la représentation interne. L'encoder drop quand même ce que la wire shape cible ne peut pas porter (signed thinking Anthropic, `prompt_cache_key` Kimi, items `reasoning` chiffrés Responses-API, refs `cachedContent` Gemini), mais ces artefacts atterrissent sous `AssistantMessage::$metadata['provider_artifacts'][$providerKey]` pour qu'un swap ultérieur de retour à la family d'origine puisse les ré-stitcher.
- **`HandoffPolicy::freshStart()`** — collapse l'historique au dernier user turn pour qu'un autre modèle prenne un tir propre.

### Ce qui est lossy

L'encoding cross-family strip toujours les artefacts que la wire shape cible ne peut pas porter. Le handoff est atomique — une construction de provider ratée (`api_key` manquante, region inconnue, network probe rejetée) laisse l'agent sur l'ancien provider avec sa liste de messages intacte. Gemini est la seule family qui n'expose pas d'ids de tool-call sur le wire ; l'encoder du SDK reconstruit l'index `toolUseId → toolName` depuis l'historique assistant à chaque appel, donc les conversations originées Gemini round-trip à travers d'autres providers et reviennent sans table de mapping externe. Les règles d'encoding complètes vivent dans l'entrée `[0.9.5]` du CHANGELOG du SDK — cherchez "Notes" dans cette section.

### Quand reach pour ça depuis un hôte SuperAICore

Presque jamais via le package directement. Le Dispatcher est one-shot par appel. Mais les runners host-side qui construisent leur propre `Agent` (ex. la pipeline PPT de SuperTeam qui veut planifier avec Claude + exécuter avec Kimi sans payer deux replays de contexte séparés) peuvent l'utiliser. Si vous vous trouvez à le vouloir depuis l'intérieur du `SuperAgentBackend` de SuperAICore, ouvrez une issue d'abord — il n'y a aucune surface produit actuelle pour le handoff multi-tour in-process et en ajouter une toucherait le contrat Dispatcher.

---

## 16. Moteur de skills — télémétrie, ranking, évolution mode FIX

*Depuis 0.8.6.*

Trois services orthogonaux qui transforment le catalogue statique `.claude/skills/` en boucle de feedback :

- **`SkillTelemetry`** — une ligne par invocation Skill de Claude Code dans `sac_skill_executions`.
- **`SkillRanker`** — BM25 pur PHP sur le registry, boosté par le taux de succès récent.
- **`SkillEvolver`** — patches mode FIX pour les skills défaillants, mis en queue comme candidats review-only. **N'auto-applique jamais.**

Modes d'évolution DERIVED / CAPTURED (auto-dériver de nouveaux skills depuis les runs réussis, capturer les workflows démontrés par l'utilisateur) volontairement non-shippés — Day 0 : les humains curatent les nouveaux skills. Pas de registry cloud (pas de besoin de partage inter-projets pour l'instant). Tout le moteur est inspiré du `skill_engine` de HKUDS/OpenSpace, taillé au sous-ensemble safe pour la prod.

### Câbler la télémétrie via les hooks Claude Code

Le package n'expédie que les endpoints artisan. Le contrat de hook appartient à Claude Code :

```jsonc
// .claude/settings.local.json
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

Les deux commandes lisent le payload JSON du hook sur stdin — `session_id`, `transcript_path`, `cwd`, `tool_name`, `tool_input.skill` pour `PreToolUse` ; `session_id`, `stop_hook_active`, `user_interrupted` pour `Stop`. Les lectures de payload utilisent une deadline soft de 1.0s + cap 200KB en lecture non-bloquante donc un pipe pathologique ne peut pas bloquer la session. Les erreurs de télémétrie sont avalées silencieusement — le hook n'échoue jamais. Les fallbacks par flags CLI (`--skill`, `--session`, `--host-app`, `--status`, `--error`) marchent pour invocation manuelle hors de Claude Code.

`host_app` est auto-détecté en remontant à la recherche d'un `.claude/` voisin et en utilisant le basename du parent — utile quand le même package est monté dans SuperTeam, SuperFeed, etc. et que vous voulez les métriques partitionnées par hôte.

### Agrégation : `SkillTelemetry::metrics()`

```php
use SuperAICore\Services\SkillTelemetry;
use Carbon\Carbon;

// Tout le temps, tous les skills
$metrics = SkillTelemetry::metrics();

// Les 7 derniers jours
$metrics = SkillTelemetry::metrics(Carbon::now()->subDays(7));

// Un skill spécifique
$metrics = SkillTelemetry::metrics(null, 'research');

// Retourne :
// [
//   'research' => [
//     'applied' => 42, 'completed' => 38, 'failed' => 3,
//     'orphaned' => 1, 'interrupted' => 0, 'in_progress' => 0,
//     'completion_rate' => 0.9048, 'failure_rate' => 0.0714,
//     'last_used_at' => '2026-04-26 14:33:12',
//   ],
//   ...
// ]
```

Une requête, un seul GROUP BY round-trip. `recentFailures($skillName, $limit = 5)` alimente le constructeur de prompt mode FIX.

### Ranking : `SkillRanker`

BM25 pur PHP (Robertson-Walker `K1=1.5`, `B=0.75`, IDF BM25-Plus). Le nom du skill est répété dans le doc bag pour upweight le signal d'intention ; description plus les 600 premiers caractères du body SKILL.md fournissent le reste de la surface lexicale. Tokeniseur CJK-aware qui émet chaque caractère Han comme un token (les descriptions de skill chinoises sont courtes — char-grams suffisent). Boost télémétrie pondéré par confiance : `final = bm25 * (1 + 0.4 * (success_rate - 0.5) * applied_signal)`, où `applied_signal = min(1, applied / 10)` sature vers 10 runs.

```php
use SuperAICore\Registry\SkillRegistry;
use SuperAICore\Services\SkillRanker;

$ranker = new SkillRanker(new SkillRegistry(base_path()));

$results = $ranker->rank('estimer effort projet outsourcé', limit: 5);
foreach ($results as $r) {
    echo "{$r['skill']->name}  score={$r['score']}  boost={$r['breakdown']['tel_boost']}\n";
    // breakdown contient aussi : bm25, matched (per-term IDF×TF), metrics (ligne télémétrie brute)
}

// Désactiver le boost pour ranking pure-lexical (ex. quand vous venez juste de seed la télémétrie) :
$ranker = new SkillRanker(new SkillRegistry(base_path()), useTelemetry: false);

// Restreindre à un sous-ensemble par nom (UI skill-picker côté hôte) :
$results = $ranker->rank($query, limit: 10, skillNames: ['research', 'plan', 'init']);
```

Sibling CLI : `php artisan skill:rank "votre tâche" --no-telemetry --format=json --cwd=/abs/path`. L'override `--cwd` compte pour les hôtes qui tournent depuis `web/public` dont la racine projet vit quelques niveaux plus haut.

### Évolution mode FIX : `SkillEvolver`

L'evolver construit un prompt LLM contraint contre le SKILL.md vivant (tronqué à 8K caractères) plus les 5 derniers échecs depuis la télémétrie, persiste un `SkillEvolutionCandidate` en statut `pending`, et **ne modifie jamais SKILL.md directement**. Les humains review la queue via `php artisan skill:candidates`.

```php
use SuperAICore\Services\Dispatcher;
use SuperAICore\Services\SkillEvolver;
use SuperAICore\Registry\SkillRegistry;
use SuperAICore\Models\SkillEvolutionCandidate;

$evolver = new SkillEvolver(
    new SkillRegistry(base_path()),
    app(Dispatcher::class),   // optionnel — seulement nécessaire quand dispatch=true
);

// Trigger manuel — pas d'appel LLM, juste stage un candidat avec le prompt
$candidate = $evolver->proposeFix('research');

// Ancrer le candidat à un run échoué spécifique
$candidate = $evolver->proposeFix(
    skillName: 'research',
    triggerType: SkillEvolutionCandidate::TRIGGER_FAILURE,
    executionId: 1234,
    dispatch: false,
);

// Brûler des tokens — invoque le LLM et stocke à la fois la réponse complète + le diff extrait
$candidate = $evolver->proposeFix('research', dispatch: true);
echo $candidate->proposed_diff;   // null si le LLM a dit NO_FIX_RECOMMENDED

// Sweep — met en queue des candidats pour chaque skill avec failure_rate > seuil
// après au moins N runs. Déduplique contre les lignes pending existantes.
$ids = $evolver->sweepDegraded(failureRateThreshold: 0.30, minApplied: 5);
```

Les contraintes hardcodées dans le prompt :

- « Produire le **plus petit patch possible**, pas une réécriture. »
- « Si vous ne pouvez pas identifier de root cause concrète depuis les preuves ci-dessous, répondre `NO_FIX_RECOMMENDED`. »
- « Ne pas inventer d'échecs que les preuves ne supportent pas. »
- « Ne pas restructurer les sections, renommer le skill, changer le `name` du frontmatter, ou ajouter de nouveaux outils à `allowed-tools` sauf si les preuves d'échec l'exigent explicitement. »
- Format de sortie pinnê à deux sections : `Diagnosis` (2-4 phrases) + `Patch` (un seul bloc \`\`\`diff fenced, OU la chaîne littérale `NO_FIX_RECOMMENDED`).

Le mode `--dispatch` route le prompt via `Dispatcher::dispatch()` avec `capability: 'reasoning'`, `task_type: 'skill_evolution_fix'` — le provider qui répond `reasoning` dans votre `RoutingRepository` s'en charge. Pas de nouvelles env vars, pas de nouvelles clés de config.

Cadence recommandée : cron nocturne, sans dispatch LLM. Les reviewers voient une queue de skills flaggés par la télémétrie avec les prompts pré-construits ; ils décident lesquels valent de brûler des tokens en relançant avec `--dispatch` depuis `php artisan skill:evolve --skill=<name> --dispatch`.

```php
// app/Console/Kernel.php
$schedule->command('skill:evolve --sweep --threshold=0.30 --min-applied=5')
         ->daily()
         ->withoutOverlapping();
```

### Reviewer les candidats

```bash
# Lister les pending
php artisan skill:candidates

# Filtrer
php artisan skill:candidates --skill=research --status=pending

# Inspecter un
php artisan skill:candidates --id=42 --show-prompt --show-diff

# JSON pour le tooling
php artisan skill:candidates --id=42 --format=json
```

Statuts : `pending` (juste mis en queue) → `reviewing` → `applied | rejected | superseded`. Workflow humain branché direct sur `git apply` :

```bash
php artisan skill:candidates --id=42 --show-diff --format=text \
  | sed -n '/^=== Proposed Diff ===$/,$p' \
  | tail -n +2 \
  | git apply --check                  # validation dry-run
```

Après application, marquer le candidat done :

```php
SkillEvolutionCandidate::find(42)->update([
    'status' => SkillEvolutionCandidate::STATUS_APPLIED,
    'reviewed_at' => now(),
    'reviewed_by' => auth()->user()->email,
]);
```

### Ce qui n'est volontairement pas shippé

- **Mode DERIVED** (auto-dériver de nouveaux skills depuis les runs réussis) — nécessiterait un juge LLM pour décider si un run multi-tour mérite d'être promu en skill, plus une queue de curation. Hors scope pour 0.8.6.
- **Mode CAPTURED** (capturer les workflows démontrés par l'utilisateur comme nouveaux skills) — même blocker plus une surface UX pour labeller la démonstration. Hors scope pour 0.8.6.
- **Registry cloud / partage de skills inter-projets** — pas de besoin actuel ; nécessiterait un service de registry et signature de skills.
- **Auto-apply** — l'evolver stage toujours, n'applique jamais. Par design — un mauvais patch dans SKILL.md empoisonne chaque exécution future de ce skill.
- **Montage sur `bin/superaicore`** — les six commandes artisan sont enregistrées via `SuperAICoreServiceProvider::boot()` uniquement. La console standalone ne les auto-monte pas car la télémétrie skill est un concern hôte, pas Composer-CLI. Si vous en avez besoin hors Laravel, enregistrez-les sur votre propre Symfony Console manuellement.

---

## Voir aussi

- [docs/idempotency.md](idempotency.md) — fenêtre de dédup 60s, contrat niveau repository
- [docs/streaming-backends.md](streaming-backends.md) — formats de stream par CLI
- [docs/task-runner-quickstart.md](task-runner-quickstart.md) — référence d'options `TaskRunner`
- [docs/spawn-plan-protocol.md](spawn-plan-protocol.md) — émulation agent codex/gemini
- [docs/mcp-sync.md](mcp-sync.md) — sync MCP piloté par catalogue
- [docs/api-stability.md](api-stability.md) — contrat SemVer
