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

## Voir aussi

- [docs/idempotency.md](idempotency.md) — fenêtre de dédup 60s, contrat niveau repository
- [docs/streaming-backends.md](streaming-backends.md) — formats de stream par CLI
- [docs/task-runner-quickstart.md](task-runner-quickstart.md) — référence d'options `TaskRunner`
- [docs/spawn-plan-protocol.md](spawn-plan-protocol.md) — émulation agent codex/gemini
- [docs/mcp-sync.md](mcp-sync.md) — sync MCP piloté par catalogue
- [docs/api-stability.md](api-stability.md) — contrat SemVer
