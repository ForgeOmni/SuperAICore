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
17. [Reranker sémantique de skills via SPI `EmbeddingProvider` (0.9.0)](#17-reranker-sémantique-de-skills-via-spi-embeddingprovider-090)
18. [Drapeaux d'outils `agent_grep` + `browser` (0.9.0)](#18-drapeaux-doutils-agent_grep--browser-090)
19. [Boucle de captures d'écran navigateur (0.9.0)](#19-boucle-de-captures-décran-navigateur-090)
20. [Découpage `usage_source` — `user` vs `ambient` (0.9.0)](#20-découpage-usage_source--user-vs-ambient-090)
21. [Reprise de session cross-harness (0.9.0)](#21-reprise-de-session-cross-harness-090)
22. [Goal store persistant (0.9.1)](#22-goal-store-persistant-091)
23. [Portail d'approbation à trois niveaux (0.9.1)](#23-portail-dapprobation-à-trois-niveaux-091)
24. [Manifeste de plugin workspace (0.9.1)](#24-manifeste-de-plugin-workspace-091)
25. [Endpoint JSON `/v1/usage` headless (0.9.1)](#25-endpoint-json-v1usage-headless-091)
26. [Agrégation `cache_hit_rate` (0.9.1)](#26-agrégation-cache_hit_rate-091)
27. [Vague de fiabilité TaskRunner (0.9.2)](#27-vague-de-fiabilité-taskrunner-092)
28. [Squad multi-agent + bindings compagnons SDK 1.0.0 (0.9.6)](#28-squad-multi-agent--bindings-compagnons-sdk-100-096)
29. [Bump SDK 1.0.5 + vague de fonctionnalités inspirées d'opencode (0.9.7)](#29-bump-sdk-105--vague-de-fonctionnalités-inspirées-dopencode-097)
30. [Opus 4.8 + Grok + Cursor (1.0.0 / SDK 1.0.9)](#30-opus-48--grok--cursor-100--sdk-109)
31. [Support bi-CLI kimi-cli + kimi-code (1.0.2 / SDK 1.0.10)](#31-support-bi-cli-kimi-cli--kimi-code-102--sdk-1010)
32. [SmartFlow — workflows dynamiques cross-CLI + fédération superagent (1.0.5 / SDK 1.1.0)](#32-smartflow--workflows-dynamiques-cross-cli--fédération-superagent-105--sdk-110)
33. [Pont de skills CLI — `superaicore:sync-cli` + le contrat `SkillLibrary` (1.0.6)](#33-pont-de-skills-cli--superaicoresync-cli--le-contrat-skilllibrary-106)

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

## 17. Reranker sémantique de skills via SPI `EmbeddingProvider` (0.9.0)

*Depuis 0.9.0 — `Memory\Embeddings\EmbeddingProvider` du SuperAgent SDK 0.9.7.*

La passe sémantique optionnelle au-dessus du top-N BM25 de `SkillRanker` (introduite en 0.9.0) embarquait avant un client HTTP Ollama fait main + un adaptateur callable. 0.9.0 remplace les deux par la SPI `EmbeddingProvider` du SDK, pour que le reranker, le `SemanticSkillRouter` du SDK et tout `OnnxEmbeddingProvider` fourni par l'hôte partagent un singleton conteneur unique + un cache unique.

### Chemin de moindre résistance — Ollama

```dotenv
AI_CORE_EMBEDDINGS_OLLAMA_URL=http://127.0.0.1:11434
AI_CORE_EMBEDDINGS_OLLAMA_MODEL=nomic-embed-text
```

```bash
ollama pull nomic-embed-text   # 768 dims, ~270 Mo
```

C'est tout. `EmbeddingProviderFactory` construit en lazy un `SuperAgent\Memory\Embeddings\OllamaEmbeddingProvider` au premier usage, et `SemanticSkillReranker` le consomme de manière transparente. Le ranking de skills commence à booster par sémantique d'intention au-dessus du match lexical BM25 — `php artisan skill:rank "重构认证模块"` préfère désormais aussi un skill dont la description dit « auth refactor » même quand aucun token chinois littéral n'apparaît.

### Apportez le vôtre — instance `EmbeddingProvider`

Pour ONNX, OpenAI, Cohere, cache pré-construit, ou tout chemin non-Ollama, enregistrez le provider typé directement :

```php
// app/Providers/AppServiceProvider.php
use SuperAgent\Memory\Embeddings\OnnxEmbeddingProvider;

$this->app->bind(
    \SuperAgent\Memory\Embeddings\EmbeddingProvider::class,
    fn () => new OnnxEmbeddingProvider('/abs/path/to/all-MiniLM-L6-v2.onnx', dimensions: 384)
);
```

Puis pointez la factory sur le binding (ou définissez `super-ai-core.embeddings.provider` dans la config publiée à une instance). L'`OnnxEmbeddingProvider` du SDK requiert soit `ext-onnxruntime`, soit le binding userland `ankane/onnxruntime` plus un fichier modèle — voir le docblock de son constructeur pour le chemin d'erreur d'installation.

### Apportez le vôtre — closure (forme legacy)

Si vous avez déjà une closure embedder de SuperAICore pré-0.9.0, passez-la comme `super-ai-core.embeddings.callback`. Le `CallableEmbeddingProvider` du SDK auto-détecte si la closure prend `array $texts` (forme batch préférée) ou `string $text` (forme single-shot legacy), pour que le code hôte existant continue de fonctionner :

```php
// config/super-ai-core.php — les deux formes marchent
return [
    'embeddings' => [
        // Forme batch — préférée
        'callback'    => fn (array $texts) => $myBatchEmbedder->embedAll($texts),
        // OU forme single-text (forme legacy VectorMemoryProvider)
        // 'callback' => fn (string $text) => $myEmbedder->embed($text),
        'fingerprint' => 'my-bge-large-v1.5',  // bumpez pour invalider le cache au changement de modèle
    ],
];
```

Le `fingerprint` est la clé d'invalidation du cache — changez-le quand vous swappez le modèle sous-jacent pour que les vecteurs cachés soient flushés proprement sans polluer les entrées non liées.

### L'échec par ligne reste gracieux

Quand l'embedder retourne `[]` pour un texte spécifique (flake du daemon Ollama, OOM ONNX sur une entrée), le reranker garde le score BM25 pour ce hit au lieu de planter tout l'appel. Les autres lignes obtiennent quand même le boost cosinus. Le vecteur de la query est mis en cache par `fingerprint() . sha1(query)` pour que les appels répétés avec la même query (typique en ranking batch / harnais de tests) ne ré-embeddent pas.

### Partage avec le `SemanticSkillRouter` du SDK

Les hôtes qui pilotent le SDK directement (sans passer par le Dispatcher de SuperAICore) peuvent récupérer la même instance depuis le conteneur pour que le reranker et le router SDK partagent un cache :

```php
use SuperAICore\Services\EmbeddingProviderFactory;
use SuperAgent\Skills\SemanticSkillRouter;

$embedder = app(EmbeddingProviderFactory::class)->make();
if ($embedder !== null) {
    $router = new SemanticSkillRouter(
        skillManager: $myManager,
        embedder: $embedder,           // même instance que le reranker
        threshold: 0.55,
        topK: 3,
    );
}
```

`SuperAgentBackend` forwarde aussi l'`EmbeddingProvider` résolu dans le bag d'options forwardées d'`Agent` (sous `embedding_provider`) pour que de futurs consommateurs SDK le récupèrent via `Agent::getOptions()` sans câblage par appel.

---

## 18. Drapeaux d'outils `agent_grep` + `browser` (0.9.0)

*Depuis 0.9.0 — `AgentGrepTool` + `FirefoxBridgeTool` du SuperAgent SDK 0.9.7.*

Deux drapeaux d'injection d'outils sur `super-ai-core.tools`. Tous deux ne se déclenchent **que** quand l'appelant n'a pas fourni de tableau `load_tools` explicite (la souveraineté de l'appelant gagne). Et tous deux ne se déclenchent **que** pour les dispatches backend SuperAgent qui pilotent réellement une boucle agentique avec outils — les appels one-shot (`max_turns=1`, sans `load_tools`) et les dispatches CLI (`claude_cli`, `codex_cli`, etc.) ne sont absolument pas affectés.

### `agent_grep` — activé par défaut

```dotenv
AI_CORE_TOOLS_AGENT_GREP=true   # défaut — mettez false pour opt-out
```

Quand activé, `SuperAgentBackend` ajoute `'agent_grep'` en tête de la liste `load_tools` implicite. L'outil siège dans le `BuiltinToolRegistry::classMap` du SDK, donc `ToolLoader` le résout en lazy quand l'agent dispatche son premier appel d'outil.

Ce qu'il apporte par rapport à l'outil `grep` simple :

1. **Injection du symbole englobant** — chaque ligne de match est annotée avec le `class::function` (ou `function` top-level) dans laquelle elle se trouve, pour les fichiers PHP / JS / TS / Python / Go. Extracteur par défaut : regex pure-PHP — `~95%` de précision sur du code typique, sans dépendance externe.
2. **Troncature des chunks vus dans la session** — les requêtes répétées sur le même tuple `(file, line range, sha)` au sein d'une session sont tronquées en marqueur `... (lines N–M previously shown to you in this session)`. L'état vit dans `ToolStateManager` indexé par `(file, lineRange, sha)` pour que l'isolation swarm fonctionne sans fuite du registre seen-chunk d'un agent vers un autre.

Pour la précision tree-sitter (intéressant sur des codebases Rust / Ruby / Java / C++ que l'extracteur regex couvre mal), sous-classez `AgentGrepTool` et passez un `CompositeSymbolExtractor([new TreeSitterSymbolExtractor(), new RegexSymbolExtractor()])` — voir le docblock de la classe SDK à `vendor/forgeomni/superagent/src/Tools/Builtin/AgentGrepTool.php` pour le chemin d'installation. Nécessite le binaire CLI `tree-sitter` sur `$PATH` et les grammaires correspondantes ; SuperAICore ne les auto-vendor pas.

Vous voulez la parité `grep` pure (par exemple pour un script qui munit la sortie ripgrep brute) ? Passez juste un `load_tools` explicite qui exclut `agent_grep` — les listes fournies par l'appelant gagnent :

```php
$dispatcher->dispatch([
    'backend'    => 'superagent',
    'load_tools' => ['grep', 'read_file', 'web_fetch'],   // explicite — pas d'agent_grep
    'max_turns'  => 5,
    // …
]);
```

### `browser` — installation manuelle requise

```dotenv
AI_CORE_TOOLS_BROWSER=true
SUPERAGENT_BROWSER_BRIDGE_PATH=/abs/path/to/forgeomni-bridge-launcher
```

L'outil `browser` n'est pas dans `BuiltinToolRegistry::classMap`, donc `load_tools` ne peut pas l'atteindre. `SuperAgentBackend::attachBrowserTool()` instancie `FirefoxBridgeTool` et le `Agent::addTool()` directement quand le drapeau est activé et que la classe SDK est disponible.

L'outil pilote un onglet Firefox ou Chromium réel via Native Messaging — actions : `navigate`, `screenshot`, `click`, `type`, `eval`, `wait`, `close`. Le côté PHP (`FirefoxBridgeTool` + `NativeMessagingTransport` + `FirefoxBridge`) est pleinement self-contained dans le SDK ; l'hôte installe trois choses :

1. **Firefox** (ou tout navigateur basé Chromium avec support WebExtension).
2. **Forgeomni Bridge WebExtension** — `manifest.json` minimal + script de fond ~150 LoC qui ouvre `runtime.connectNative('forgeomni_bridge')` et dispatche les messages entrants vers les API `tabs.*` / `webNavigation.*`. Walkthrough dans le docblock de `vendor/forgeomni/superagent/src/Tools/Browser/FirefoxBridge.php`.
3. **Binaire launcher Native Messaging** — n'importe quel exécutable qui pipe du JSON length-prefixé entre Firefox et le processus PHP. Le binaire Rust de jcode marche tel quel, ou écrivez un shim Node / Go de 50 lignes.

Tant que le launcher n'est pas installé et que `SUPERAGENT_BROWSER_BRIDGE_PATH` n'y pointe pas, chaque action retourne une erreur explicative pour que l'agent apprenne à demander de l'aide d'installation au lieu de boucler. Activer le drapeau avant d'installer le launcher est sûr.

Pour outrepasser la lookup d'env var pour un dispatch (rare), passez `launcherArgv` :

```php
$dispatcher->dispatch([
    'backend'              => 'superagent',
    'browser_launcher_argv' => ['/opt/bridge/launcher', '--profile=staging'],
    // …
]);
```

### Surface de capacités serrée

`FirefoxBridgeTool` n'expose délibérément que les sept actions ci-dessus. Pas de gestion d'onglets, cookies, history, downloads, ou API extension — celles-ci élargiraient significativement le rayon d'explosion d'abus et ne sont pas nécessaires pour le workload typique « utiliser la page comme un humain ». Les hôtes qui ont besoin de plus le câblent directement via `FirefoxBridge::evalJs()` depuis un outil custom.

---

## 19. Boucle de captures d'écran navigateur (0.9.0)

*Depuis 0.9.0.*

Quand l'outil `browser` exécute `action: 'screenshot'`, `FirefoxBridgeTool::execute()` retourne un `ToolResult::success(['format' => 'png', 'base64' => $data, 'bytes' => N])`. Le contenu est encodé en JSON et stocké sur le bloc de contenu `ToolResultMessage` dans le trail de messages d'`AgentResult`.

`SuperAgentBackend::persistLatestScreenshot()` parcourt ce trail post-dispatch :

1. Indexer chaque bloc `tool_use` dont `toolName === 'browser'` par `toolUseId`.
2. Pour chaque bloc `tool_result` ultérieur dont `toolUseId` matche et `isError !== true`, décoder le contenu JSON et lire `base64`.
3. Garder le DERNIER avec succès — un long agent run peut prendre plusieurs captures et seule la plus récente est opérationnellement intéressante.
4. L'écrire dans `BrowserScreenshotStore` indexé par le process_id du dispatch (priorité : `options['process_id']` → `external_label` → `metadata.session_id` → `session_id` → hex aléatoire).
5. Exposer l'URL résultante sur l'envelope dispatch comme `latest_screenshot_url`.

### Round-trip avec `AiProcessSource`

`AiProcessSource::list()` lit `BrowserScreenshotStore::latest()` contre l'`external_label` (puis la clé composite `aiprocess.<id>`) de la ligne `ai_processes` quand elle construit chaque `ProcessEntry`. La vue `/processes` rend un badge `📷 screenshot` jaune sur les lignes qui ont une capture ; cliquer ouvre l'image inline dans le panneau latéral (le offcanvas drawer B1).

Au reap (PID meurt, statut bascule à FINISHED), `AiProcessSource` appelle `BrowserScreenshotStore::purgeFor()` contre les mêmes clés pour que les captures n'accumulent pas au-delà de la durée utile du run.

### Configurer le backend de stockage

```dotenv
AI_CORE_BROWSER_SHOTS_DISK=local                                # n'importe quel disk Laravel filesystem
AI_CORE_BROWSER_SHOTS_DIR=super-ai-core/browser-screenshots     # relatif à la racine du disk
```

Conseil prod : utilisez un disk tmpfs par-pod (montez `tmpfs` à `/var/cache/super-ai-core/screenshots`, configurez un disk `local` qui pointe là), ou un disk S3 avec une lifecycle rule courte. Le défaut `local` marche sur une install Laravel mono-machine et pendant le développement.

### UI custom

Les hôtes qui veulent leur propre rendu de captures (carousel, history, pipeline OCR) lisent directement depuis le store :

```php
use SuperAICore\Services\BrowserScreenshotStore;

$store = app(BrowserScreenshotStore::class);
$url = $store->latest($externalLabel);   // null quand aucune frame sur disque
```

Pour des archives multi-frames (plutôt que juste la dernière), wrappez le call site pour écrire votre propre slot indexé via `store($key, $base64Png)` avec un suffixe de clé par frame (ex. `"task:42:frame:7"`).

---

## 20. Découpage `usage_source` — `user` vs `ambient` (0.9.0)

*Depuis 0.9.0.*

Le `Swarm\AmbientWorker` du SuperAgent SDK 0.9.7 fait tourner des scans memory-dedup et staleness en arrière-plan sur un tick. Son callback `tagUsage` se déclenche à chaque pass complétée avec `usage_source: 'ambient'`, mais avant 0.9.0 SuperAICore n'avait aucun moyen de bucketer ces lignes séparément sur `/usage` — elles se mélangeaient à la dépense user-facing.

`Dispatcher::resolveUsageSource()` extrait maintenant la source de `options['usage_source']` ou `options['metadata']['usage_source']` et l'écrit comme clé top-level `metadata.usage_source` (défaut `'user'`). Contraint à `[a-z0-9_-]{1,32}` contre les fuites typo-en-bucket-fantôme.

### Câbler l'AmbientWorker

Le worker lui-même vit dans le SDK ; SuperAICore câble un callback `tagUsage` qui dispatche un appel comptable no-op pour enregistrer la dépense :

```php
// app/Console/Commands/AmbientTickCommand.php
use SuperAgent\Memory\Palace\PalaceStorage;
use SuperAgent\Memory\Palace\MemoryDeduplicator;
use SuperAgent\Swarm\AmbientWorker;
use SuperAICore\Services\Dispatcher;

class AmbientTickCommand extends Command
{
    protected $signature = 'super-ai-core:ambient-tick';

    public function handle(PalaceStorage $palace, MemoryDeduplicator $dedup, Dispatcher $dispatcher): int
    {
        $worker = new AmbientWorker(
            storage: $palace,
            deduplicator: $dedup,
            config: [
                'dedup_interval_seconds'       => 600,   // 10m
                'stale_check_interval_seconds' => 3600,  // 1h
                'pass_budget_seconds'          => 5,
            ],
            tagUsage: function (string $passName, array $stats) use ($dispatcher) {
                // Enregistrer une ligne synthétique étiquetée 'ambient' pour
                // que /usage la groupe. Pas de prompt, pas d'appel modèle —
                // on veut juste la ligne metadata. La plupart des hôtes
                // écrivent déjà leurs propres lignes ambient directement
                // via UsageRecorder ; le chemin dispatch ici est illustratif.
            },
        );

        $report = $worker->tick();
        $this->table(['pass', 'ran', 'stats'], collect($report)->map(fn ($r, $p) => [
            $p,
            $r['ran'] ? 'yes' : '—',
            json_encode($r['stats'] ?? null),
        ])->all());
        return self::SUCCESS;
    }
}
```

```php
// app/Console/Kernel.php
$schedule->command('super-ai-core:ambient-tick')
         ->everyFiveMinutes()
         ->withoutOverlapping();
```

Les hôtes qui pilotent des dispatches mode-ambient via `Dispatcher` (par exemple re-résumé en arrière-plan de longs drawers de mémoire) passent juste la source à chaque appel :

```php
$dispatcher->dispatch([
    'prompt'   => 'Summarise drawers above 20K tokens.',
    'backend'  => 'superagent',
    'metadata' => ['usage_source' => 'ambient'],
    // …
]);
```

### Lire le découpage sur `/usage`

La carte « By Source » du tableau de bord siège à côté de By Task Type / By Model / By Backend. L'en-tête montre un badge « N ambient · $X » quand de l'activité ambient s'est produite, pour que les opérateurs voient d'un coup d'œil combien de dépense de fond porte la fenêtre courante. La mise en page se reflow à `col-lg-3` sur les viewports larges pour que les cartes existantes restent lisibles.

Le `whereJsonContains` / la lookup de chemin JSON ne sont pas nécessaires — l'écrivain Dispatcher tire `usage_source` à la clé metadata top-level sur chaque ligne, donc le contrôleur groupe en PHP via les méthodes Eloquent collection. Marche sur MySQL 5.7, PostgreSQL 9 et SQLite sans ops JSON spécifiques au driver.

### Buckets de source custom

L'allowlist accepte n'importe quelle chaîne `[a-z0-9_-]{1,32}`. Les sources définies par l'hôte (`'eval'`, `'audit'`, `'replay'`) marchent direct :

```php
$dispatcher->dispatch([
    'metadata' => ['usage_source' => 'eval'],   // apparaît comme son propre bucket sur /usage
    // …
]);
```

Tout ce qui sort de l'allowlist (majuscules, caractères spéciaux, > 32 chars) est normalisé — l'écrivain applique `mb_strtolower(preg_replace('/[^a-z0-9_-]+/i', '', $c))` puis tronque à 32 chars. Les lignes avec des valeurs non parsables retombent à `'user'`.

---

## 21. Reprise de session cross-harness (0.9.0)

*Depuis 0.9.0 — famille `Conversation\HarnessImporter` du SuperAgent SDK 0.9.7.*

La page /processes gagne une liste déroulante « Resume from… » quand `super-ai-core.resume.enabled` est activé. Les opérateurs choisissent une session Claude Code (`~/.claude/projects/<hash>/<uuid>.jsonl`) ou Codex (`~/.codex/sessions/**/*.jsonl`) depuis le picker et soit inspectent la transcription inline, soit laissent l'hôte la re-dispatcher dans un backend.

### Activer la fonctionnalité

Désactivé par défaut — les importeurs voient l'historique de session de chaque opérateur sur les machines partagées :

```dotenv
AI_CORE_RESUME_ENABLED=true
```

Cela démasque la liste déroulante sur `/processes` et ouvre trois endpoints sous `/super-ai-core/resume` :

- `GET /resume` — lister les harnesses disponibles sur cette machine
- `GET /resume/{harness}` — lister les sessions, plus récentes en premier (paramètre query `limit`, défaut 30, max 200)
- `POST /resume/{harness}/load` — charger une session, retourne transcription + payload hôte optionnel

### Le callback `on_load` — hook de re-dispatch hôte

Sans callback, l'endpoint `/load` retourne juste la transcription parsée en JSON. Les hôtes qui veulent un « resume into chat with provider X » en un clic câblent un callable qui retourne `{redirect: '<url>'}` :

```php
// config/super-ai-core.php
use SuperAgent\Messages\Message;

return [
    'resume' => [
        'enabled' => env('AI_CORE_RESUME_ENABLED', false),
        'on_load' => function (string $harness, string $sessionId, array $messages): array {
            // $messages est list<Message> — alimentez direct Agent::loadMessages($messages)
            // ou passez par Conversation\Transcoder::encode() pour une famille de wire différente.
            $session = ChatSession::createFromHarnessImport($harness, $sessionId, $messages);
            return [
                'redirect' => route('chat.show', $session),
                'session_id' => $session->id,
            ];
        },
    ],
];
```

Le modal frontend vérifie `host_payload.redirect` — quand présent, il navigue là plutôt que de rendre la transcription inline.

### Accès programmatique depuis un contrôleur

Les hôtes qui veulent leur propre UI « Resume » résolvent le resolver et construisent le flow qu'ils veulent :

```php
use SuperAICore\Services\HarnessSessionResolver;

class MyResumeController extends Controller
{
    public function __construct(protected HarnessSessionResolver $resolver) {}

    public function pickAndResume(Request $request)
    {
        $harness = $request->input('harness', 'claude');
        if (!in_array($harness, $this->resolver->availableHarnesses(), true)) {
            return back()->withErrors(['harness' => 'Unsupported harness']);
        }

        $sessions = $this->resolver->listSessions($harness, limit: 50);
        // → [['id' => '8e2c-…', 'project' => 'shopify-autopilot',
        //     'started_at' => '2026-04-30T…', 'message_count' => 47,
        //     'first_user_message' => 'Refactor the checkout flow…'], …]

        // Une fois que l'utilisateur en a choisi une :
        $payload = $this->resolver->loadTranscript($harness, $sessionId);
        // → ['harness' => 'claude', 'session' => '8e2c-…',
        //    'transcript' => [['role' => 'user', 'content' => '…'], …],
        //    'host_payload' => /* ce que votre on_load a retourné */]

        return view('my.resume.review', compact('payload'));
    }
}
```

### Continuation cross-wire via le Transcoder SDK

Les importeurs retournent du SuperAgent `Message[]` dans la représentation interne du SDK. Pour reprendre sur une famille de provider différente (commencer en Claude, continuer en Kimi), passez les messages par le `Conversation\Transcoder` 0.9.5 du SDK :

```php
use SuperAgent\Agent;
use SuperAgent\Conversation\Transcoder;

$messages = $this->resolver->loadTranscript('claude', $sessionId)['transcript'];
// Hydratez le tableau transcription en instances Message si vous l'avez sérialisé.
// (HarnessImporter::load() retourne directement des instances Message — utilisez
//  ce chemin quand vous re-dispatchez depuis un processus hôte qui n'a pas
//  traversé une frontière JSON.)

$agent = new Agent([
    'provider' => /* LLMProvider Kimi construit par l'hôte */,
    'max_turns' => 10,
]);
$agent->loadMessages($messages);   // Le Transcoder gère la conversion de wire-shape
$agent->run('Continuez là où la session précédente s\'est arrêtée — écrivez les tests unitaires.');
```

### Ce qui est lossy à travers les harnesses

Les importeurs sont délibérément tolérants — les lignes malformées / types d'event inconnus sont skipped silencieusement plutôt que de rejeter toute la session. Les vrais session logs des vrais harnesses sont sales (drift de schéma Claude Code 1.x vs 2.x, changements de format de rollout Codex CLI). Le Transcoder strip les artefacts que le wire shape cible ne peut pas porter — les blocs thinking signés Anthropic ne survivent pas un saut vers OpenAI, et les items reasoning chiffrés OpenAI Responses ne survivent pas un saut vers Anthropic. Les ids tool-call round-trip correctement à travers toutes les familles depuis 0.9.5.

### Ce qui n'est pas livré

- **Auto-découverte des fichiers de session jcode / pi / OpenCode** — le set d'importeurs 0.9.7 du SDK couvre Claude Code et Codex. Les hôtes qui ont besoin d'autres harnesses implémentent `HarnessImporter` directement et droppent un binding service-provider pour enregistrer l'implémentation.
- **UI de re-dispatch pour l'historique `ai_processes` propre à SuperAICore** — `/processes` est live-only par contrat depuis 0.6.7 (il montre les PIDs en cours, pas les lignes finies). La liste déroulante Resume sert au pickup de session cross-harness, pas à rejouer les runs SuperAICore antérieurs. Les hôtes qui veulent « refaire cette tâche finie avec un provider différent » construisent leur propre UI au-dessus de l'audit log `ai_processes`.

---

## 22. Goal store persistant (0.9.1)

*Depuis 0.9.1 — SPI `Goals\Contracts\GoalStore` du SuperAgent SDK 0.9.8.*

Le SDK 0.9.8 livre `Goals\GoalManager` — une primitive scoped-thread
pour « cette conversation travaille vers X ». Le manager a besoin de
persistance pour survivre aux redémarrages de processus (codex met les
goals en pause quand l'utilisateur tape `/pause`, et ils doivent rester
en pause après recyclage du processus hôte). SuperAICore 0.9.1 câble
le backend Eloquent par défaut.

### Câblage par défaut

`SuperAICoreServiceProvider::register()` lie :

```php
$this->app->bind(
    \SuperAgent\Goals\Contracts\GoalStore::class,
    \SuperAICore\Goals\EloquentGoalStore::class,
);
$this->app->singleton(\SuperAgent\Goals\GoalManager::class);
```

`app(GoalManager::class)` se résout avec le store persistant injecté
automatiquement. `php artisan migrate` crée la table `ai_goals`
(`thread_id`, `description`, `status`, `metadata`, timestamps). Honore
`super-ai-core.table_prefix` si votre hôte en utilise un.

```php
use SuperAgent\Goals\GoalManager;

$manager = app(GoalManager::class);
$manager->setActiveGoal($threadId, 'Refactorer le flux checkout pour respecter le nouveau moteur de taxes');

// Plus tard — l'agent lit le goal en milieu de conversation via l'outil
// agent_get_goal en lecture seule, ou l'hôte le met en pause sur
// dépassement budgétaire :
$manager->pause($threadId);
// …redémarrage du processus hôte…
$active = $manager->getActiveGoal($threadId);   // toujours null — en pause
$manager->resume($threadId);
```

### Contrainte : au plus une ligne non-terminale par thread

`EloquentGoalStore::setActiveGoal()` transitionne toute ligne `active` /
`paused` / `budget_limited` préexistante du thread vers `superseded`
avant d'insérer la nouvelle. Les statuts terminaux (`completed`,
`cancelled`, `superseded`) s'accumulent librement comme piste d'audit.

### Store personnalisé — l'hôte garde déjà les goals dans sa propre table

Les hôtes qui modélisent déjà des goals (ex. SuperTeam stocke
`objectives` par projet) substituent leur propre implémentation. Le
contrat est petit — cinq méthodes sur `GoalStore` :

```php
namespace App\Goals;

use SuperAgent\Goals\Contracts\GoalStore;
use SuperAgent\Goals\Goal;

final class MyGoalStore implements GoalStore
{
    public function setActiveGoal(string $threadId, string $description, array $metadata = []): Goal
    { /* upsert dans votre table `objectives`, marquer la ligne active précédente superseded */ }

    public function getActiveGoal(string $threadId): ?Goal
    { /* retourne Goal::active(...) ou null en pause / complétée / absente */ }

    public function pause(string $threadId): void           { /* … */ }
    public function resume(string $threadId): void          { /* … */ }
    public function complete(string $threadId, ?string $result = null): void { /* … */ }
}
```

Surchargez le binding dans le `register()` du service provider hôte —
**avant** que quoi que ce soit ne résolve `GoalManager` :

```php
$this->app->bind(
    \SuperAgent\Goals\Contracts\GoalStore::class,
    \App\Goals\MyGoalStore::class,
);
```

L'`EloquentGoalStore` de ce package devient du code mort du point de
vue de votre hôte — c'est une implémentation de référence, pas une
dépendance dure.

---

## 23. Portail d'approbation à trois niveaux (0.9.1)

*Depuis 0.9.1.*

`Runner\ApprovalGate` reflète la commande `/permissions` de codex
(renommée depuis `/approvals`). Trois modes — `Auto`, `Suggest`,
`Never` — avec un token override `/approve` à usage unique pour le flux
codex « laisse passer ce seul appel spécifique ».

### Différences entre les modes

```
                outils lecture seule  mutations ordinaires   shell destructif
   ──────────────────────────────────────────────────────────────────────────
   Auto         allow                 allow                  SUGGEST APPROVAL
   Suggest      allow                 SUGGEST APPROVAL       SUGGEST APPROVAL
   Never        allow                 hard deny              hard deny
```

Allowlist en lecture seule codée en dur sur l'enum :

```php
ApprovalMode::readOnlyAllowlist();
// → ['agent_grep', 'agent_glob', 'agent_read', 'agent_ls',
//    'agent_status', 'web_search', 'web_fetch', 'agent_get_goal']
```

La détection de shell destructif passe par le
`Guidance\Gates\DestructiveCommandScanner` existant — même set de regex
que le package utilise depuis pré-0.7. Le mode Auto utilise le scanner
comme plancher de sécurité même s'il laisse passer les mutations
ordinaires.

### Câblage à l'intérieur d'un runner hôte

Le portail est une fonction de décision pure — le code hôte l'appelle
avant de transférer l'appel d'outil au backend, et rend la suggestion
dans sa propre UI. Pas d'enforcement côté backend ; l'opt-in est à un
appel d'enveloppe près :

```php
use SuperAICore\Runner\ApprovalGate;
use SuperAICore\Runner\ApprovalMode;
use SuperAICore\Runner\ApprovalDecision;

$gate    = app(ApprovalGate::class);
$mode    = ApprovalMode::parse($thread->approval_mode ?? 'suggest');
$pending = $thread->pending_approval_tool_use_id;   // stocké côté hôte, voir ci-dessous

$decision = $gate->evaluate(
    toolName:           $call->name,
    arguments:          $call->arguments,
    mode:               $mode,
    toolUseId:          $call->id,
    approvedToolUseId:  $pending,
);

if ($decision->allow) {
    $thread->forget('pending_approval_tool_use_id');   // override à usage unique consommé
    return $backend->dispatchTool($call);
}

if ($decision->canRetry) {
    // Mode Suggest — remonter à l'utilisateur. Il tape /approve dans
    // l'UI, l'hôte estampille
    // $thread->pending_approval_tool_use_id = $call->id, puis ré-émet
    // le même appel.
    return [
        'error' => $decision->reason,
        'code'  => $decision->errorCode,    // 'mutation_pending_approval' ou
                                            // 'destructive_pending_approval'
        'tool_use_id' => $call->id,
    ];
}

// Hard deny — le mode Never rejette une mutation. Dites à l'utilisateur
// de changer de mode ; ne PAS auto-retry.
throw new RuntimeException($decision->reason);
```

### Le flux `/approve`

1. L'agent émet un block `tool_use` qui mute l'état.
2. Le portail retourne `canRetry: true`, code `mutation_pending_approval`, avec le `tool_use_id` de l'appel.
3. L'UI hôte affiche « Approuver cet appel ? » avec le diff / la commande shell.
4. L'utilisateur tape `/approve`. L'hôte stocke `tool_use_id` dans `pending_approval_tool_use_id`.
5. L'hôte ré-exécute le même tour d'agent. Le portail voit `approvedToolUseId === toolUseId`, retourne `allow`.
6. L'hôte efface `pending_approval_tool_use_id`. Usage unique — le prochain appel de l'agent passe par le portail à blanc.

L'override est `hash_equals($approvedToolUseId, $toolUseId)` — égalité
de chaînes, sans astuce d'encodage. L'hôte possède le stockage et la
discipline d'effacement ; le portail est sans état.

### Scanner destructif personnalisé

Le constructeur du portail prend un `DestructiveCommandScanner`
optionnel. Pour surcharger (par ex. ajouter la détection SQL DROP),
re-lier :

```php
$this->app->singleton(\SuperAICore\Runner\ApprovalGate::class, function ($app) {
    return new \SuperAICore\Runner\ApprovalGate(
        scanner: new \App\Guidance\StrictScanner(),
    );
});
```

---

## 24. Manifeste de plugin workspace (0.9.1)

*Depuis 0.9.1.*

Le pattern « workspace plugin sharing » de codex en PHP. Une équipe
commit `.superaicore/workspace-plugins.json` dans le repo ; les
nouveaux arrivants obtiennent l'ensemble complet de plugins de l'équipe
sur `git clone` au lieu d'une doc d'onboarding par machine.

### Format du manifeste

```json
{
    "plugins": [
        {
            "name":    "team-pr-review",
            "source":  "github.com/our-org/agent-skill-pr-review",
            "version": "1.4.0",
            "scope":   "workspace"
        },
        {
            "name":    "team-jira-helper",
            "source":  "github.com/our-org/agent-skill-jira",
            "version": "0.8.2",
            "scope":   "user"
        }
    ]
}
```

- `scope: "workspace"` → doit être installé pour tous ceux qui travaillent dans ce repo. Le registry le retourne en `missing_required`.
- `scope: "user"` → recommandation seulement. Retourné en `missing_recommended` ; l'UI hôte invite le développeur plutôt que d'auto-installer.

### Boucle de sync

```php
use SuperAICore\Plugins\WorkspacePluginRegistry;

$registry = app(WorkspacePluginRegistry::class);

// Rassembler les noms des plugins déjà installés localement par
// l'hôte — c'est spécifique à l'hôte (votre PluginInstaller sait où
// ils vivent).
$installedNames = collect(app(\App\Plugins\PluginInstaller::class)->list())
    ->pluck('name')
    ->all();

$pending = $registry->pendingInstalls($installedNames);
// → [
//     'missing_required'    => [['name' => 'team-pr-review',  …]],
//     'missing_recommended' => [['name' => 'team-jira-helper', …]],
//   ]

foreach ($pending['missing_required'] as $entry) {
    // Auto-install — pas de prompt ; c'est une exigence scope workspace.
    app(\App\Plugins\PluginInstaller::class)->install(
        $entry['name'], $entry['source'], $entry['version'],
    );
}

if ($pending['missing_recommended']) {
    // Inviter le développeur plutôt que d'auto-installer.
    $this->info(sprintf(
        "Plugins recommandés que ce workspace utilise : %s. Lancez `php artisan plugin:install --recommended` pour les ajouter.",
        collect($pending['missing_recommended'])->pluck('name')->implode(', '),
    ));
}
```

### Ajouter / retirer des entrées depuis PHP

```php
$registry->add(
    name:    'team-deploy-helper',
    source:  'github.com/our-org/agent-skill-deploy',
    version: '2.1.0',
    scope:   WorkspacePluginRegistry::SCOPE_WORKSPACE,
);

$registry->remove('team-jira-helper');   // retourne true / false
```

Le registry écrit du JSON pretty-print avec un ordre de clés stable,
donc le manifeste est review-friendly quand il atterrit dans une PR.

### Où vit le manifeste

Codé en dur à
`<workspace_root>/.superaicore/workspace-plugins.json`. La
`workspaceRoot` par défaut est `base_path()`. Surchargez le binding
singleton si la disposition de votre repo place la racine du workspace
ailleurs :

```php
$this->app->singleton(\SuperAICore\Plugins\WorkspacePluginRegistry::class, function () {
    return new \SuperAICore\Plugins\WorkspacePluginRegistry(
        workspaceRoot: '/var/www/myapp',
    );
});
```

---

## 25. Endpoint JSON `/v1/usage` headless (0.9.1)

*Depuis 0.9.1.*

`Http\Controllers\UsageApiController` reflète la forme `/v1/usage` de
l'app-server codex — un axe par requête, schéma de bucket identique.
Pour les pipelines de billing / Grafana / portails de coût CI qui ne
veulent pas scraper le tableau de bord HTML.

### Enregistrement de route + auth

La route est enregistrée sous le préfixe standard du package
(par défaut `super-ai-core`) :

```
GET /super-ai-core/v1/usage
```

L'auth est la responsabilité de l'hôte. Encapsulez le groupe de routes
externe ou le middleware par-route dans votre config :

```php
// config/super-ai-core.php
return [
    'route' => [
        'middleware' => ['web', 'auth:sanctum', 'can:view-billing'],
    ],
];
```

Le contrôleur ne suppose pas de session ; sans middleware, chaque
appelant qui atteint l'endpoint obtient les données agrégées de coût.

### Paramètres de requête

| clé         | type   | défaut  | notes                                              |
| ----------- | ------ | ------- | -------------------------------------------------- |
| `group_by`  | string | `day`   | un de `day`, `model`, `provider`, `thread`, `backend`, `task_type` |
| `days`      | int    | `30`    | clampé à ≥ 1                                       |
| `model`     | string | —       | filtre exact-match sur `ai_usage_logs.model`       |
| `task_type` | string | —       | filtre exact-match sur `ai_usage_logs.task_type`   |
| `user_id`   | string | —       | filtre exact-match sur `ai_usage_logs.user_id`     |
| `backend`   | string | —       | filtre exact-match sur `ai_usage_logs.backend`     |

`group_by` inconnu retourne 422 avec la liste autorisée.

### Forme de réponse

```json
{
    "group_by": "model",
    "from":     "2026-04-04T00:00:00+00:00",
    "to":       "2026-05-04T17:21:48+00:00",
    "buckets": [
        {
            "bucket":            "claude-opus-4-7",
            "runs":              412,
            "cost_usd":          12.847291,
            "shadow_cost_usd":   12.847291,
            "input_tokens":      1837421,
            "output_tokens":     291038,
            "cache_read_tokens": 4129873,
            "cache_hit_rate":    0.6921
        },
        …
    ]
}
```

`cache_hit_rate` est calculé à l'intérieur du bucket — `cache_read /
(input + cache_read)` — plutôt que moyenné depuis l'estampille
per-row, donc il reste correct quel que soit le sous-ensemble de
lignes ayant la clé metadata définie.

### Exemples curl

```bash
# Dépense quotidienne par modèle, 7 derniers jours
curl -H "Authorization: Bearer $TOKEN" \
    'https://app.example.com/super-ai-core/v1/usage?group_by=day&days=7'

# Coût par-thread sur le mois dernier, scope au backend SuperAgent
curl -H "Authorization: Bearer $TOKEN" \
    'https://app.example.com/super-ai-core/v1/usage?group_by=thread&backend=superagent&days=30'

# Ventilation niveau provider pour un seul task type
curl -H "Authorization: Bearer $TOKEN" \
    'https://app.example.com/super-ai-core/v1/usage?group_by=provider&task_type=email_summary'
```

### Datasource Grafana JSON

La forme est compatible avec le datasource basé JSON de Grafana —
pointez le panel sur
`/super-ai-core/v1/usage?group_by=day&days=$__range_days`, mappage de
champ `bucket → time` et choisissez `cost_usd` / `cache_hit_rate`
comme métriques. Le cap dur de 5000 lignes dans le contrôleur empêche
qu'une plage de dates qui s'emballe ne casse le fetch Grafana.

### Limites

- `limit(5000)` sur la requête sous-jacente — au-delà de cette fenêtre les totaux par bucket restent corrects mais le slice exact est les 5000 lignes les plus récentes. Resserrez la plage de dates ou filtrez par `backend` / `model` si vous avez besoin d'une fenêtre plus large.
- Les filtres sont exact-match seulement ; pas de `LIKE` / `IN` / regex. Pour des requêtes plus riches, le `UsageController` du tableau de bord HTML a la surface de filtre complète, ou construisez votre propre contrôleur au-dessus de `AiUsageLog`.

---

## 26. Agrégation `cache_hit_rate` (0.9.1)

*Depuis 0.9.1 — compagnon de l'affichage cache-rate par tour de DeepSeek-TUI.*

Chaque ligne `ai_usage_logs` dont le `metadata` porte une part de cache
non nulle porte maintenant aussi `metadata.cache_hit_rate ∈ [0, 1]`.

### Pourquoi le dénominateur BRUT

```
cache_hit_rate = cache_read_tokens / (input_tokens + cache_read_tokens)
                                       └── input non caché ──┘
```

Le dénominateur est le prompt **brut** — la taille totale du prompt
avant la décote cache, pas seulement la portion cachée. Group-and-
average à travers les lignes fonctionne correctement parce que chaque
ligne utilise la même forme de dénominateur : agrégez `cache_read` et
`input` séparément, puis divisez.

```php
// Groupement par modèle — lit correctement sans redériver :
$rows = AiUsageLog::where('model', 'claude-opus-4-7')
    ->where('created_at', '>=', now()->subDays(7))
    ->get();

$rates = $rows->avg(fn ($r) => $r->metadata['cache_hit_rate'] ?? null);
// vs. recalcul ground truth, qui donne le même nombre :
$cacheRead = $rows->sum(fn ($r) => $r->metadata['cache_read_tokens'] ?? 0);
$gross     = $rows->sum('input_tokens') + $cacheRead;
$truth     = $gross > 0 ? $cacheRead / $gross : 0;
```

### Absent vs zéro — différence sémantique

| état                                | valeur `cache_hit_rate` | signification                      |
| ----------------------------------- | ----------------------- | ---------------------------------- |
| pas de cache key envoyée, cache off | absent (clé non définie)| « pas de cache éligible »          |
| cache key envoyée, miss complet     | `0.0`                   | « 0% hit — cache froid ou churn »  |
| cache key envoyée, hit partiel      | `0.42`                  | « 42% du prompt payé était gratuit »|
| cache key envoyée, hit complet      | `1.0`                   | « 100% hit — session collante »    |

Les tableaux de bord qui filtrent sur `cache_hit_rate IS NOT NULL`
séparent proprement « feature en usage, juste froide » de « feature
pas du tout utilisée ».

### Alias DeepSeek V3 / R1

Les anciens wires DeepSeek (V3, R1) estampillaient `cache_hit_tokens`
au lieu de `cache_read_tokens`. `UsageRecorder` accepte les deux —
l'alias est lu à l'entrée, la clé canonique est écrite à la sortie.

```php
// Les deux produisent la même ligne :
$recorder->record(['cache_read_tokens' => 1500, …]);
$recorder->record(['cache_hit_tokens'  => 1500, …]);   // alias legacy
```

Le code hôte qui estampillait historiquement l'alias sur les
enregistrements d'usage est forward-compatible — pas de migration
nécessaire.

### Carte sommaire `total_cache_read_tokens`

Le bloc session-summary de la page `/usage` porte maintenant
`total_cache_read_tokens` aux côtés des slices existants de cache froid
et de coût ambient. C'est le compte absolu, pas le taux — le taux
apparaît par-modèle et par-ligne.

### Lecture depuis un queue worker

La colonne `cache_hit_rate` fait partie de `metadata` (JSON), pas une
colonne top-level, donc les installs MySQL 5.7 / SQLite sans indexation
JSON-path lisent ça inline :

```php
AiUsageLog::query()
    ->where('created_at', '>=', now()->subDay())
    ->whereNotNull('metadata')
    ->get()
    ->filter(fn ($r) => isset($r->metadata['cache_hit_rate']))
    ->groupBy('model')
    ->map(fn ($rows) => [
        'avg_rate' => round($rows->avg(fn ($r) => $r->metadata['cache_hit_rate']), 4),
        'runs'     => $rows->count(),
    ]);
```

L'endpoint `/v1/usage` (§25) fait le même calcul côté serveur sur les
six axes de group-by — habituellement plus simple que de rouler le
vôtre.

---

## 27. Vague de fiabilité TaskRunner (0.9.2)

*Depuis 0.9.2 — `Runner\TaskRunner` uniquement.*

TaskRunner peut remettre une tâche à un autre backend quand le primaire
échoue avec une sortie de type quota/rate-limit. Cible : les jobs
opérateur longs où un abonnement CLI ou une clé API peut se retrouver
limité au milieu de la tâche, alors que l'hôte veut continuer le même
prompt sur Codex, Gemini, Kimi ou un backend HTTP.

Le fallback est **par run**. Le backend demandé est toujours essayé en
premier, donc aucun état sticky n'est à réinitialiser quand le primaire
se rétablit.

### Chaîne par appel

```php
use SuperAICore\Runner\TaskRunner;

$envelope = app(TaskRunner::class)->run('claude_cli', $prompt, [
    'fallback_profile' => 'coding',
    'fallback_chain' => ['claude_cli', 'codex_cli', 'gemini_cli', 'kimi_cli'],
    'fallback_on' => ['rate limit', 'usage limit', 'quota', '429'],
    'inherit_failure_context' => true,
    'log_file' => storage_path('logs/tasks/123.log'),
]);
```

Si `claude_cli` échoue avec une sortie correspondante, TaskRunner retente
sur `codex_cli`. Le second prompt est le prompt original plus un bloc de
handoff compact contenant le backend précédent, l'exit code et la fin de
la sortie/log précédente. Mettez `inherit_failure_context=false` si le
backend suivant doit recevoir uniquement le prompt original.

### Chaîne automatique

```php
$envelope = app(TaskRunner::class)->run('claude_cli', $prompt, [
    'fallback_chain' => 'auto',
]);
```

`auto` utilise les backends enregistrés/activés, avec cet ordre par défaut :

```text
claude_cli -> codex_cli -> gemini_cli -> kimi_cli -> copilot_cli ->
kiro_cli -> superagent -> anthropic_api -> openai_api -> gemini_api
```

Définissez `AI_CORE_TASK_FALLBACK_CHECK_AVAILABILITY=true` pour demander à
chaque backend enregistré si son binaire ou ses credentials semblent
utilisables avant de l'ajouter à la chaîne auto.

### Defaults globaux

```dotenv
AI_CORE_TASK_FALLBACK_AUTO=false
AI_CORE_TASK_FALLBACK_CHAIN=claude_cli,codex_cli,gemini_cli
AI_CORE_TASK_FALLBACK_CHECK_AVAILABILITY=false
AI_CORE_TASK_FALLBACK_INHERIT_CONTEXT=true
```

Le bloc de config est `super-ai-core.task_fallback` :

```php
'task_fallback' => [
    'auto_enabled' => false,
    'check_availability' => false,
    'chain' => [],
    'auto_chain' => ['claude_cli', 'codex_cli', 'gemini_cli', /* ... */],
    'fallback_on' => ['rate limit', 'usage limit', 'quota', '429'],
    'inherit_failure_context' => true,
],
```

Les options par appel surchargent la config. `fallback_chain` accepte une
chaîne séparée par virgules, un tableau, ou `'auto'`.

Quand `fallback_chain` est omis, TaskRunner résout la politique workload
dans cet ordre :

```text
fallback_profile / chains_by_profile
-> task_type / chains_by_task_type
-> capability / chains_by_capability
-> task_fallback.chain
-> auto_enabled / auto_chain
```

Exemple de config :

```php
'task_fallback' => [
    'chains_by_profile' => [
        'coding' => ['claude_cli', 'codex_cli', 'gemini_cli'],
        'research' => ['claude_cli', 'kimi_cli', 'gemini_cli'],
    ],
    'chains_by_task_type' => [
        'tasks.run' => ['claude_cli', 'codex_cli'],
    ],
    'chains_by_capability' => [
        'summarise' => ['claude_cli', 'kimi_cli'],
    ],
],
```

### Sémantique de matching

Le fallback continue seulement si l'enveloppe échouée contient un fragment
configuré dans `error`, `output`, `summary`, la fin de `log_file`, ou la
chaîne de l'exit code. Les defaults incluent :

- `rate limit`, `rate_limit`, `usage limit`
- `quota`, `quota_exceeded`, `insufficient_quota`
- `too many requests`, `429`
- `billing`, `budget`, `limit reached`
- `usage_not_included`

Les erreurs de validation de prompt, fichiers manquants, échecs d'outil et
autres erreurs non-quota restent donc sur le backend d'origine sauf si
l'hôte les ajoute explicitement à `fallback_on`.

### Rapport de tentatives

Quand le fallback est actif, l'enveloppe retournée inclut :

```php
$envelope->fallbackReport === [
    [
        'attempt' => 1,
        'backend' => 'claude_cli',
        'success' => false,
        'retryable' => true,
        'next_backend' => 'codex_cli',
        'exit_code' => 1,
        'model' => null,
        'duration_ms' => 0,
        'usage_log_id' => null,
        'cost_usd' => null,
        'billing_model' => null,
        'log_file' => '/path/to/log',
        'error' => 'Claude usage limit reached. Try again later.',
    ],
    [
        'attempt' => 2,
        'backend' => 'codex_cli',
        'success' => true,
        'retryable' => false,
        'next_backend' => null,
        'exit_code' => 0,
        'model' => 'gpt-5.2',
        'duration_ms' => 1500,
        'usage_log_id' => 123,
        'cost_usd' => 0.01,
        'billing_model' => 'usage',
        'log_file' => '/path/to/log',
        'error' => null,
    ],
];
```

`TaskResultEnvelope::toArray()` expose les mêmes données sous
`fallback_report`, donc les hôtes qui persistent l'enveloppe peuvent les
stocker sans cas particulier.

Chaque tentative Dispatcher reçoit aussi des metadata adaptées aux
analytics de lignes d'usage :

```php
[
    'fallback_active' => true,
    'fallback_chain' => ['claude_cli', 'codex_cli'],
    'fallback_attempt' => 2,
    'fallback_primary_backend' => 'claude_cli',
    'fallback_backend' => 'codex_cli',
]
```

### Directions d'implémentation associées

Utilisez ces primitives comme couche de fiabilité, pas seulement comme
dernier retry :

- **Chaînes par type de tâche** — gardez des defaults différents pour le
  code, la recherche, la synthèse et la maintenance de fond. Les chaînes de
  code commencent souvent par `claude_cli` ou `codex_cli`; la synthèse peut
  inclure `kimi_cli`; les backends HTTP directs fonctionnent bien comme
  dernier stop headless.
- **Badges de statut UI** — persistez `fallback_report` sur la ligne de
  tâche hôte et rendez des états compacts comme « primary limited »,
  « continued on codex » ou « stopped on non-retryable error ». Liez chaque
  tentative à son `log_file` quand il existe.
- **Politique de retry de queue** — utilisez le fallback TaskRunner avant
  le retry de queue. Un retry de queue relance tout le job; le fallback
  garde le même run logique en mouvement et conserve le contexte du backend
  échoué.
- **Analytics de fiabilité** — groupez `fallback_report[*].backend` avec
  `ai_usage_logs.backend` pour repérer les primaires qui touchent souvent
  le quota et les secondaires qui terminent réellement le travail. Cela
  donne un signal propre pour réordonner `auto_chain`.
- **Revue sécurité** — gardez `fallback_on` étroit. Ajoutez seulement les
  fragments que votre hôte considère retryables; les échecs de validation
  et les refus tool-policy devraient généralement rester terminaux.
- **Déploiement progressif** — commencez par un `fallback_chain` par appel
  sur une classe de tâches, puis déplacez les chaînes stables dans
  `super-ai-core.task_fallback.chain`; activez
  `AI_CORE_TASK_FALLBACK_AUTO=true` seulement après revue de l'availability
  backend et de la facturation.

---

## 28. Squad multi-agent + bindings compagnons SDK 1.0.0 (0.9.6)

*Depuis 0.9.6 — la contrainte SDK passe à `^1.0`.*

0.9.6 livre le pipeline de peer-collaboration `Squad` du SDK 1.0.0
comme dixième adaptateur Dispatcher et enveloppe les primitives
compagnons SDK 0.9.8 (`AutoModelStrategy`, `CacheAwareCompressor`,
`UntrustedInput`, `TokenBucket`, `AdHocMemoryProvider`,
`Conversation\Fork`, `AgentDepthGuard`, DeepSeek FIM) derrière des
services hôtes first-class adressables depuis n'importe quel chemin
de dispatch. Chaque binding est additif et opt-in.

### Pipeline Squad — dispatch cross-modèle adaptatif

```php
use SuperAICore\Services\Dispatcher;

$result = app(Dispatcher::class)->dispatch([
    'backend' => 'squad',
    'prompt'  => 'Refactore AuthController pour utiliser Laravel Sanctum.',

    // Optionnel — décomposition heuristique par TaskDecomposer par défaut.
    // Chaque sous-tâche porte une classe de difficulté (trivial/easy/moderate/hard/expert)
    // que ModelTierMap mappe vers un provider tiers.
    'subtasks' => [
        ['role' => 'planner',  'description' => 'Propose les changements',  'difficulty' => 'moderate'],
        ['role' => 'editor',   'description' => 'Applique le diff',         'difficulty' => 'hard'],
        ['role' => 'reviewer', 'description' => 'Vérifie le résultat',      'difficulty' => 'easy'],
    ],

    // Optionnel — override le tier map global pour ce dispatch.
    'tier_map' => [
        'trivial'  => ['provider' => 'anthropic', 'model' => 'claude-haiku-4-5'],
        'easy'     => ['provider' => 'deepseek',  'model' => 'deepseek-v4-flash'],
        'moderate' => ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6'],
        'hard'     => ['provider' => 'anthropic', 'model' => 'claude-opus-4-7'],
    ],

    'max_cost_usd'   => 2.50,                // optionnel — downshift à 80%
    'checkpoint_dir' => storage_path('app/squad/auth-refactor'),
    'squad_id'       => 'auth-refactor-2026-05-16',  // redispatcher avec le même id pour reprendre
]);

// Surface de l'enveloppe
$result['text'];                       // sorties fusionnées sur toutes les étapes
$result['cost_usd'];                   // somme sur les dispatches d'étape
$result['turns'];                      // nombre d'étapes exécutées
$result['squad']['squad_id'];
$result['squad']['step_count'];
$result['squad']['completed'];         // liste des rôles de sous-tâche terminés
$result['squad']['roles'];             // list<{name, provider, model, tier}>
$result['squad']['checkpoint_path'];   // sur disque — réinjectez comme `checkpoint_dir` pour reprendre
$result['squad']['mailbox_log'];       // trace d'audit des messages peer
```

Le pipeline écrit un checkpoint après chaque étape. Si le processus
est tué en plein milieu, redispatcher avec le même `squad_id` et
`checkpoint_dir` reprend depuis la dernière étape réussie — les rôles
précédents ne sont pas re-exécutés.

Le plafond de coût (`max_cost_usd`) est appliqué par étape. Quand le
coût cumulé dépasse 80% du plafond, les étapes suivantes sont
descendues d'un tier (`hard → moderate`, `moderate → easy`, etc.)
jusqu'à la fin du pipeline ou au plafond dur. Le tableau
`squad.roles` de l'enveloppe reflète le tier final auquel chaque
étape a tourné, pour que l'UI hôte puisse rendre « étape 3 downshift
de `hard` vers `moderate` ».

Quand le `TaskDecomposer` heuristique suffit (la plupart des tâches),
omettez `subtasks` complètement. Le décomposeur lit le prompt, le
splitte en sous-tâches planner / editor / verifier / etc. et attribue
des classes de difficulté basées sur les mots-clés du prompt + des
heuristiques de longueur. `subtasks` pré-décomposés sont surtout
utiles quand l'hôte a une connaissance domaine spécifique sur la
manière de décomposer (e.g. workflow de code-review qui veut
toujours planner → diff → reviewer → doc-writer).

### Commandes console `smart` et `squad`

Les deux commandes sont des passthroughs vers le binaire vendor
`superagent` :

```bash
./vendor/bin/superaicore smart "audite ce diff"
./vendor/bin/superaicore smart show --last
./vendor/bin/superaicore smart replay <run-id> --max-cost=1.50

./vendor/bin/superaicore squad "refactore le module auth" --max-cost=2.0
./vendor/bin/superaicore squad --no-squad "compare avec le chemin legacy"
```

Passez `--binary=/abs/path/to/superagent` quand le SDK est installé
hors de `vendor/forgeomni/superagent/`.

### `AutoModelRouter` — heuristique `/model auto`

Résolvez le service depuis le container et donnez-lui le même triplet
`Message[]` / `systemPrompt` / `options` que l'Agent verrait :

```php
use SuperAgent\Messages\Message;
use SuperAICore\Services\AutoModelRouter;

$router = app(AutoModelRouter::class);

$messages = [
    Message::user('Revois le plan de migration pour la réécriture user_schema.'),
    Message::user('Vérifie spécifiquement si le backfill est concurrency-safe.'),
];

$pickedModel = $router->select($messages, systemPrompt: 'Tu es un reviewer senior.', options: [
    'reasoning_effort' => 'max',   // force le tier Pro
]);
// → 'claude-opus-4-7' (quand auto_model.pro_model est re-bindé) ou 'deepseek-v4-pro'

$depth = $router->trailingToolChainDepth($messages);  // 0 ici — pas de tool call
```

Les hôtes qui câblent ça dans leur propre dispatcher / planificateur
obtiennent une escalation sur :

- **Long contexte** — total tokens > `long_context_tokens` (défaut
  32 000).
- **Tool chains profondes** — run trainante de N+ blocs `tool_use`
  dépasse `tool_chain_threshold` (défaut 3).
- **`reasoning_effort=max` explicite** — l'appelant demande le max
  de raisonnement ; route vers Pro.
- **Mots-clés d'intention** — system prompt contient `review` /
  `audit` / `design` / `migration` / `architecture` / etc.

Override les defaults Pro/Flash via config :

```php
// config/super-ai-core.php
'auto_model' => [
    'enabled'              => true,
    'pro_model'            => 'claude-opus-4-7',
    'flash_model'          => 'claude-haiku-4-5',
    'long_context_tokens'  => 24_000,
    'tool_chain_threshold' => 4,
    'score_catalog_path'   => storage_path('app/eval-scores.json'),
],
```

Quand `score_catalog_path` pointe vers un fichier JSON
`ScoreCatalog` SuperAgent, le modèle top-scoré du catalogue pour le
dim d'intention inféré l'emporte sur l'heuristique Pro/Flash. Utile
quand l'hôte lance ses propres evals.

### `CompressionStrategyFactory` — compaction cache-aware

Les hôtes qui pilotent leur propre `ContextManager` (sessions chat
longues persistées entre processus) câblent la factory :

```php
use SuperAgent\Context\CompressionConfig;
use SuperAgent\Context\ContextManager;
use SuperAgent\Context\TokenEstimator;
use SuperAICore\Services\CompressionStrategyFactory;

$tokenEstimator = new TokenEstimator($provider);
$compressionConfig = new CompressionConfig(
    summaryTokenBudget: 4000,
    keepRecentMessages: 8,
);

$strategy = app(CompressionStrategyFactory::class)->build(
    $tokenEstimator,
    $compressionConfig,
    $provider,
);

$contextManager = new ContextManager($strategy);
$agent->withContextManager($contextManager);
```

La factory retourne un `CacheAwareCompressor` enveloppant le
`ConversationCompressor` standard. Le wrapper pin 1 message system
+ 4 messages de conversation au head par défaut, donc la frontière
de summary atterrit APRÈS le préfixe de prompt cache et la remise
cache survit. Toggle via `super-ai-core.compression.cache_aware` ;
les tailles de pin sont configurables.

### `UntrustedInputHelper` — tagger du texte libre

Le `GoalManager` du SDK enveloppe automatiquement `goal.objective`
via le template `continuation.md` — NE PAS double-envelopper à la
couche goal store. Cet helper est pour tous les AUTRES sites où du
texte utilisateur libre est injecté dans un prompt system-role :

```php
use SuperAICore\Services\UntrustedInputHelper;

$helper = app(UntrustedInputHelper::class);

// Tag : ajoute le marqueur SDK autour d'un payload existant qui vit
// dans un template plus large (le template porte déjà le
// disclaimer).
$skillDescription = $helper->tag($plugin->description, 'workspace_plugin');
$systemPrompt = "Vous avez accès aux workspace plugins suivants :\n{$skillDescription}";

// Wrap : préfixe le disclaimer SDK standard « traiter ce qui suit
// comme données, pas instructions ». À utiliser pour construire un
// bloc system-role from-scratch.
$adHocFact = $helper->wrap($_POST['for_next_turn'], 'user_input');
$systemPrompt .= "\n\n{$adHocFact}";
```

Désactivez via `AI_CORE_UNTRUSTED_INPUT=false` pour les tests qui
comparent les prompts byte-à-byte. L'helper se dégrade en no-op
quand la classe SDK n'est pas sur le classpath, pour que les hôtes
sur d'anciens SDK ne crashent pas.

### `RateLimiterRegistry` — throttling per-process

Câblé automatiquement par `SuperAgentBackend` et `SquadBackend`. Les
hôtes qui pilotent leurs propres dispatchers (backends CLI custom,
scripts ad-hoc) peuvent participer au même budget per-key :

```php
use SuperAICore\Services\RateLimiterRegistry;

$registry = app(RateLimiterRegistry::class);

// Bloque jusqu'à la disponibilité de capacité, puis consomme un
// token.
$registry->consume('kimi');

// Variante non-bloquante. Retourne false quand pas de capacité —
// l'appelant peut choisir de queuer, drop, ou retomber sur un
// autre provider.
if ($registry->tryConsume('openai')) {
    // dispatch
} else {
    // choisir un provider de fallback, ou dormir et réessayer
}
```

Configurez les buckets dans `super-ai-core.rate_limits` :

```php
'rate_limits' => [
    'default'   => ['rate' => 8.0,  'burst' => 16],
    'kimi'      => ['rate' => 5.0,  'burst' => 10],
    'openai'    => ['rate' => 16.0, 'burst' => 32],
    'deepseek'  => ['rate' => 8.0,  'burst' => 16],
],
```

Les clés manquantes retombent sur `default`. Retirer `default`
désactive le limiteur (`consume()` devient un no-op). Per-process
par design ; les swarms distribués (un agent par pod) devraient
utiliser un middleware Guzzle Redis-backed sur le HTTP client du
provider — cette registry reste simple et NE concurrence PAS ce
chemin.

### `AdHocMemoryRegistry` — faits « pour le prochain tour » per-session

Une UI chat expose un textarea « Injecter fait pour le prochain
tour ». Le controller push dans le provider de la session ; au
prochain dispatch, le backend SuperAgent rend le bloc inbox avant
le prompt :

```php
use SuperAICore\Services\AdHocMemoryRegistry;

$registry = app(AdHocMemoryRegistry::class);

// Dans le controller : l'utilisateur a tapé « ignore les endpoints
// /v1 dépréciés »
$noteId = $registry->push(
    sessionId: $chatSession->id,
    content:   $request->input('for_next_turn'),
    ttlSeconds: 600,           // TTL 10 minutes
    untrusted:  true,
    kind:       'note',
);

// Oublier tout le pool de la session à la fermeture du chat
$registry->forget($chatSession->id);
```

La mémoire est process-local — les entrées meurent au shutdown. Les
faits durables vont dans `MEMORY.md` / `BuiltinMemoryProvider`, pas
ici. La classe provider est l'`AdHocMemoryProvider` du SDK ; les
hôtes qui veulent rendre l'inbox directement peuvent résoudre
`forSession($id)` et inspecter la queue.

### `ConversationForkService` — sémantique codex `/side`

```php
use SuperAICore\Services\ConversationForkService;

$forks = app(ConversationForkService::class);

// Branche la conversation. Stockez le fork handle sous un UUID
// dans l'URL pour que l'utilisateur puisse revenir.
$fork = $forks->start($chatSession->messages);
session()->put("fork:{$forkId}", $fork);

// L'utilisateur joue quelques messages sur le côté, compare les
// modèles…

// Discard le côté : le parent est intact.
$newParent = $forks->finish($fork, 'discard');

// Promote des messages du côté spécifiques dans le parent.
$newParent = $forks->finish($fork, 'promote', [3, 5, 7]);

// Promote tout.
$newParent = $forks->finish($fork, 'promoteAll');

$chatSession->update(['messages' => $newParent]);
```

Le service est stateless — la lifetime du fork est de la
responsabilité de l'hôte. Utile pour les UI chat qui veulent
« brancher cette conversation, essayer un modèle différent à côté,
ne promouvoir que les messages utiles du côté ».

### `DeepSeekFimService` — complétion fill-in-the-middle

L'endpoint FIM de DeepSeek vit sur la région `beta`. L'abstraction
chat-shape `Backend` ne convient pas (pas de `messages`, juste
prefix + suffix), donc les hôtes qui bâtissent des features de
complétion IDE-style appellent ce service directement :

```php
use SuperAICore\Services\DeepSeekFimService;

$fim = app(DeepSeekFimService::class);

if ($fim->isAvailable()) {
    $body = $fim->complete(
        prefix: "function calculateTax(\$amount, \$rate) {\n    ",
        suffix: "\n    return \$amount * \$rate;\n}",
        options: [
            'max_tokens'  => 64,
            'temperature' => 0.1,
            'stop'        => ['}'],
        ],
    );
}
```

Posez `DEEPSEEK_API_KEY` (ou `super-ai-core.deepseek.api_key`)
pour activer. Le service construit un provider per-call contre la
région `beta` — le provider DeepSeek chat-region refuse
explicitement les appels FIM.

### Cadran trois niveaux `reasoning_effort`

Option per-call sur `Dispatcher::dispatch()` :

```php
$result = $dispatcher->dispatch([
    'backend'          => 'superagent',
    'prompt'           => 'Audite cette migration pour les race conditions.',
    'reasoning_effort' => 'max',   // off | high | max
]);
```

Route vers la bonne forme de body selon l'upstream :
- La plupart des providers : champ top-level `reasoning_effort`.
- NVIDIA NIM : `chat_template_kwargs.thinking`.
- Providers sans la capability : silencieusement ignoré.

Nourrit aussi l'heuristique d'escalation `AutoModelRouter` quand
mis à `max`.

### Handoff `Agent::switchProvider()`

```php
$result = $dispatcher->dispatch([
    'backend' => 'superagent',
    'prompt'  => '…continue cette conversation…',
    'handoff' => [
        'provider' => 'kimi',
        'config'   => [
            'api_key' => env('KIMI_API_KEY'),
            'region'  => 'cn',
        ],
        'policy'   => 'preserveAll',   // default | preserveAll | freshStart
    ],
]);

// L'enveloppe avertit quand la conversation historique ne tient
// pas dans la fenêtre de contexte du nouveau modèle — l'hôte peut
// afficher un prompt « compresser avant le prochain tour ».
$result['handoff_token_status'];
// → ['tokens' => 142_000, 'window' => 128_000, 'fits' => false, 'model' => 'moonshot-v1-128k']
```

`HandoffPolicy::default()` garde les tours récents et jette les
vieilles sorties tool. `preserveAll` garde tout (peut ne pas
rentrer dans la nouvelle fenêtre — voir `handoff_token_status`).
`freshStart` ne porte que le system prompt en avant.

### Cap de profondeur sous-agent

```php
// config/super-ai-core.php
'agents' => [
    'max_depth' => 3,   // défaut SDK 5
],
```

Transmis à `Swarm\AgentDepthGuard::setMax()` pendant le boot du
service provider. Override per-process via la variable env
`SUPERAGENT_MAX_AGENT_DEPTH`.

### Quel binding choisir

| Binding | Quand l'utiliser |
| --- | --- |
| `SquadBackend` | Tâche multi-étapes qui bénéficie de modèles différents par étape (planner → editor → reviewer). Le plafond de coût compte. Reprise crash via checkpoints. |
| `AutoModelRouter` | Vous construisez un dispatcher / planificateur custom et voulez l'heuristique Pro/Flash du SDK sans coupler à `SuperAgentBackend`. |
| `CompressionStrategyFactory` | Vous pilotez votre propre `ContextManager` pour de longues sessions multi-tour et voulez que le préfixe de cache survive à la summarisation. |
| `UntrustedInputHelper` | Vous concaténez du texte libre dans un system prompt à un site que le `GoalManager` du SDK ne couvre pas déjà. |
| `RateLimiterRegistry` | Le provider vous a déjà throttlé upstream et vous voulez ceinture-et-bretelles côté client. |
| `AdHocMemoryRegistry` | L'UI chat expose des faits « pour le prochain tour » et vous voulez l'isolement per-session. |
| `ConversationForkService` | L'UI chat offre du branching / « essayer un modèle différent à côté ». |
| `DeepSeekFimService` | Complétion préfixe / inline-fill style IDE. Le `Backend` chat-shape ne convient pas. |
| `reasoning_effort` | Vous voulez de la pensée supplémentaire sur un dispatch spécifique sans re-binder globalement le modèle. |
| Handoff `Agent::switchProvider` | Vous wrappez `SuperAgentBackend` directement et voulez le switching provider en milieu de conversation. |

---

## 29. Bump SDK 1.0.5 + vague de fonctionnalités inspirées d'opencode (0.9.7)

Dix patterns portés depuis [opencode](https://github.com/sst/opencode)
au-dessus du release de capacités SDK 1.0.5. La ligne directrice est un
envelope de dispatch visibility-first : chaque dispatch SuperAgent
enregistre désormais les diffs par fichier entre un snapshot shadow-git
pre/post, l'UI obtient un bandeau +/- et un bouton de revert, et l'agent
peut interrompre pour poser une question de clarification à l'opérateur
sans que l'hôte n'ait à construire un canal latéral. Le reste est de la
scafolderie opérationnelle : rulesets de permissions par agent,
héritage de permissions sous-agent, rétention des snapshots, mode plan
/ build, sessions shell PTY et partage de session.

### 29.1 Résumé de diff par fichier + bouton revert

**But** : chaque dispatch qui touche au worktree doit laisser une trace
machine-lisible de ce qui a changé, avec un chemin de revert en un clic
quand le modèle a écrit quelque chose qu'il n'aurait pas dû.

**Câblage** :

```bash
# SDK 1.0.5 (via le bump SuperAICore 0.9.7) — automatique, aucune config
php artisan migrate                       # récupère les trois nouvelles colonnes ai_usage_logs

# Vérifiez que le shadow store est joignable
php -r 'require "vendor/autoload.php"; var_dump((new SuperAgent\Checkpoint\GitShadowStore(getcwd()))->shadowDir());'
```

Le Dispatcher écrit trois nouvelles colonnes sur `ai_usage_logs` :

| Colonne | Type | Signification |
|---|---|---|
| `pre_snapshot` | varchar(64) | Commit shadow-git capturé AVANT le dispatch. Utilisé par `POST /usage/{id}/revert`. |
| `post_snapshot` | varchar(64) | Commit shadow-git capturé APRÈS le dispatch. Côté `to` du diff par fichier. |
| `file_diff_summary` | json | Envelope `{additions, deletions, files, diffs: [{file, additions, deletions, status, patch, truncated}], truncated}`. |

**Lire l'envelope diff depuis PHP** :

```php
use SuperAICore\Models\AiUsageLog;

$row = AiUsageLog::find($usageLogId);
$diff = $row->file_diff_summary;
echo "+ {$diff['additions']} − {$diff['deletions']} sur {$diff['files']} fichiers\n";

foreach ($diff['diffs'] as $f) {
    echo "  {$f['status']} {$f['file']}   +{$f['additions']} −{$f['deletions']}\n";
    if ($f['truncated']) {
        echo "    (patch tronqué à 256 KB)\n";
    }
}
```

**Revert** :

```bash
# UI : cliquez le bouton ↩ sur une ligne /usage qui a un pre_snapshot.
# Headless :
curl -X POST -H "X-CSRF-TOKEN: $TOKEN" "$BASE_URL/usage/$ID/revert"
# → {"ok":true,"message":"Worktree restored to snapshot ab1c2d3.","snapshot":"ab1c2d3…"}
```

Les fichiers non-trackés ajoutés depuis le snapshot sont LAISSÉS en
place — le restore matche le contrat
`GitShadowStore::restore()` du SuperAgent SDK. C'est volontaire : vous
gardez les nouveaux logs / artefacts tout en revertant les sources
trackées.

**Réglages** :

- `AI_CORE_SNAPSHOT_PROJECT_ROOT` — override du chemin que le shadow
  store mirroir. Résolution par défaut :
  `options['project_root']` → `super-ai-core.snapshot.project_root` →
  `base_path()` → `getcwd()`.
- `AI_CORE_SNAPSHOT_ENABLED=false` — désactive complètement
  l'enregistrement de diff pour un envelope pré-0.9.7 byte-identique.
- `AI_CORE_SNAPSHOT_REVERT_ENABLED=false` — continue d'enregistrer mais
  l'endpoint revert renvoie 403.
- Les patches par fichier tronquent à 256 KB ; le diff entier cap à 200
  fichiers.

**Prune quotidien** :

```bash
php artisan super-ai-core:snapshot-prune --days=7 --dry-run
php artisan super-ai-core:snapshot-prune --days=7
```

Ou programmez depuis `app/Console/Kernel.php` :

```php
protected function schedule(Schedule $schedule)
{
    $schedule->command('super-ai-core:snapshot-prune')->dailyAt('02:00');
}
```

### 29.2 Outil HITL `ask_user` mid-run

**But** : l'agent peut interrompre et demander une décision à
l'opérateur quand il découvre une bifurcation, au lieu de deviner ou
d'attendre le prochain prompt.

**Câblage** :

```dotenv
AI_CORE_TOOLS_ASK_USER=true
```

C'est tout. `SuperAgentBackend` attache `AskUserTool` à chaque dispatch
quand le flag est on. L'outil insère une ligne `ai_user_questions`,
poll toutes les 500ms, et renvoie la réponse de l'opérateur (ou une
erreur si le timeout fire).

**Ce que le modèle émet** (visible dans la trace tool-use) :

```json
{
  "name": "ask_user",
  "input": {
    "question": "La migration ajoute une colonne NOT NULL à une table de 50M lignes. Appliquer avec `IF NOT EXISTS` ou écrire un backfill par lot ?",
    "options": [
      {"label": "IF NOT EXISTS", "description": "One-shot ; lock table bref"},
      {"label": "Backfill par lot", "description": "Lent mais sans lock ; plus sûr en prod"}
    ],
    "timeout_seconds": 1200
  }
}
```

**Ce que l'opérateur voit** sur `/processes` : une carte d'avertissement
inline avec la question, des boutons optionnels pour les choix
prédéfinis, et un champ texte libre quand aucun choix n'est fourni. La
carte poll `/processes/questions` toutes les 4 secondes et disparaît
quand le statut de la ligne passe à `answered`.

**Réponse programmatique** (pour un client non-UI) :

```bash
curl -X POST -H "Content-Type: application/json" -H "X-CSRF-TOKEN: $TOKEN" \
  "$BASE_URL/processes/questions/$QUESTION_ID/answer" \
  -d '{"answer": "Backfill par lot"}'
```

**Quand NE PAS utiliser** :

- Workers de queue long-terme sans surveillance — la boucle de polling
  bloque l'agent jusqu'à `timeout_seconds` (défaut 600s, capé à
  3600s). Laissez `AI_CORE_TOOLS_ASK_USER` off pour les jobs qui
  tournent sans humain présent.
- Décisions sans bifurcation que le contexte de la conversation
  désambiguïse déjà. La description de l'outil dit au modèle de ne pas
  demander de devinettes que l'utilisateur pourrait inférer.

### 29.3 Rappels de session

**But** : préfixer le system prompt avec une guidance context-sensitive
sans que l'appelant ne le sache. Utile pour marqueurs mode plan, zones
sécurité-sensible, conventions projet, etc.

**Config** (`config/super-ai-core.php`) :

```php
'reminders' => [
    'rules' => [
        [
            'name' => 'plan-mode-active',
            'when' => ['agent' => 'plan'],
            'text' => "## Mode plan actif\nÉcrivez le plan dans `.superagent/plans/{session}.md`. N'appelez AUCUN outil edit/write contre le worktree projet.",
        ],
        [
            'name' => 'security-sensitive-area',
            'when' => ['metadata.path' => 'src/Auth/*'],
            'text' => "## Note sécurité\nCe répertoire contient le code auth + permissions. Préférez les changements additifs ; flaggez tout ce qui touche au stockage de token pour review humain.",
        ],
        [
            'name' => 'compliance-region-eu',
            'when' => ['metadata.region' => 'eu'],
            'text' => "## Conformité\nCe dispatch tourne dans la région EU — les règles GDPR s'appliquent. N'incluez aucune PII utilisateur dans le corps du prompt.",
        ],
    ],
],
```

**Sémantique de matching** :

- Les clés `when` sont des lookups en notation pointée dans `$options`
  passé à `Dispatcher::dispatch()`. `when` vide (ou omis) signifie
  « match toujours » — utile pour une bannière de conformité globale.
- Les valeurs supportent des globs shell-style (`fnmatch`), donc
  `'metadata.path' => 'src/Auth/*'` matche n'importe quoi sous
  `src/Auth/`.
- Les règles tirent dans l'ordre de déclaration ; les bodies qui
  matchent sont joints par une ligne blanche et préfixés au system
  prompt de l'appelant.

### 29.4 Ruleset de permissions par agent

**But** : gating d'outils déclaratif par agent. L'agent `plan` ne doit
écrire que des fichiers `.md` de plan ; l'agent `explore` doit être en
lecture seule ; l'agent `build` doit avoir toute la surface.

**Config** (`config/super-ai-core.php`) :

```php
'agents' => [
    'plan' => [
        'permission' => [
            '*'     => 'allow',
            'edit'  => ['*' => 'deny', '*.md' => 'allow'],
            'write' => ['*' => 'deny', '*.md' => 'allow'],
        ],
    ],
    'explore' => [
        'permission' => [
            '*'     => 'deny',
            'read'  => 'allow',
            'grep'  => 'allow',
            'glob'  => 'allow',
            'list'  => 'allow',
            'bash'  => 'allow',
        ],
    ],
    'build' => [
        'permission' => [
            '*' => 'allow',
        ],
    ],
],
```

**Sémantique d'évaluation** (opencode `permission/evaluate.ts`) :

- Forme de règle : `{permission, pattern, action}`. Les valeurs peuvent
  être string (action broadcast pour cet outil) ou map per-pattern.
- **La DERNIÈRE règle qui matche gagne**. Un large
  `'*' => 'allow'` suivi d'un spécifique `'edit' => 'deny'` aboutit à
  `edit` deny.
- Action par défaut si rien ne matche : `ask`. La méthode `project()`
  de l'évaluateur expose trois listes — `allowed_tools`,
  `denied_tools`, `ask_tools` — et SuperAgentBackend câble les deux
  premières sur l'agent. `ask_tools` laisse un hôte construire son
  propre hook HITL sur une surface plus étroite.

**Déclenchement** : passez `options['agent']` (ou `metadata.agent`)
dans le dispatch. SuperAgentBackend le lit, consulte l'évaluateur, et
attache les listes allowed/denied à l'`Agent` SDK — sauf si l'appelant
a passé `allowed_tools` / `denied_tools` explicites, qui gagnent
toujours.

### 29.5 Workflow mode plan

**But** : le modèle écrit un plan dans un fichier markdown ;
l'opérateur approuve ; l'agent build exécute le plan approuvé. Même
forme que le pattern plan_enter / plan_exit d'opencode.

**Dispatch** :

```php
use SuperAICore\Modes\CliModeRouter;
use SuperAgent\Modes\ModeContext;

$ctx = ModeContext::root('plan');
$result = app(CliModeRouter::class)->dispatch(
    'plan',
    "Refactorer le middleware auth pour drop le chemin de stockage legacy session-token.",
    $ctx,
);

echo $result->text;                // sortie phase build (ou texte plan si refusé)
echo $result->modeSpecific['plan_file'];   // .superagent/plans/{session}.md
echo $result->modeSpecific['phase'];       // completed | plan_rejected
```

**Ce qui se passe dans l'ordre** :

1. **Phase plan** : dispatché contre
   `super-ai-core.modes.plan.plan_backend` (défaut `cli:claude_cli`).
   Le system prompt synthétique + le ruleset
   `super-ai-core.agents.plan.permission` (quand déclaré) refusent les
   édits hors du fichier plan. Le modèle écrit
   `.superagent/plans/{session}.md`.
2. **Phase approbation** : une ligne `ai_user_questions` monte
   demandant à l'opérateur `[Approve, Reject]`. L'orchestrateur poll
   toutes les 500ms jusqu'à `approval_timeout` (défaut 600s). Quand
   HITL est désactivé (`tools.ask_user_enabled=false`), auto-approuve
   pour que l'orchestrateur reste utilisable en CI.
3. **Phase build** : dispatché contre
   `super-ai-core.modes.plan.build_backend` avec un prompt synthétique
   qui pointe vers le fichier plan approuvé + inclut son texte
   complet.

**Config** (`config/super-ai-core.php`) :

```php
'modes' => [
    'plan' => [
        'enabled'          => true,
        'plan_backend'     => 'cli:claude_cli',
        'build_backend'    => 'cli:claude_cli',
        'plan_dir'         => '.superagent/plans',
        'auto_approve'     => null,           // null = auto-détection
        'approval_timeout' => 600,
    ],
],
```

### 29.6 Dérivation de permissions sous-agent

**But** : quand un agent parent dispatche un sous-agent (via
l'`AgentTool` de SuperAgent ou n'importe quel dispatch imbriqué),
l'enfant doit hériter de la liste deny du parent. Un parent read-only
produit toujours des enfants read-only.

**Deux sources de signal** :

```php
// Option A : pass-through explicite (utilisez-le depuis votre propre
// dispatcher quand vous savez exactement ce que le parent a refusé)
$child = $dispatcher->dispatch([
    'prompt'              => $task,
    'agent'               => 'explore',
    'parent_denied_tools' => ['edit', 'write', 'bash'],
]);

// Option B : résolution par nom d'agent (laissez le PermissionEvaluator
// chercher le ruleset du parent dans la config). Plus propre quand le
// parent est un des agents déclarés dans
// super-ai-core.agents.{name}.permission.
$child = $dispatcher->dispatch([
    'prompt'   => $task,
    'agent'    => 'explore',
    'metadata' => ['parent_agent' => 'plan'],
]);
```

**Sémantique de merge** : set deny effectif de l'enfant =
`union(explicit_child_denied, agent_rule_denied, parent_denied)`.
Monotone — les enfants ne peuvent jamais élever.

### 29.7 Sessions shell PTY long-terme (Phase 1)

**But** : streamer les processus shell long-running (watchers de tests,
`tail -f`, `npm run dev`) dans l'UI sans bloquer la boucle agent.

**Câblage** :

```dotenv
AI_CORE_PTY_ENABLED=true
```

**Spawn** :

```bash
curl -X POST -H "Content-Type: application/json" -H "X-CSRF-TOKEN: $TOKEN" \
  "$BASE_URL/pty/sessions" \
  -d '{"command":"npm run dev","cwd":"/srv/app","title":"vite watcher"}'
# → {"ok":true,"session":{"id":42,"pid":12345,"status":"running","log_path":"..."}}
```

**Poll** :

```bash
curl "$BASE_URL/pty/sessions/42/poll?cursor=0"
# → {"ok":true,"id":42,"chunk":"vite v5.4.0 ready in 184ms\n  ➜  Local:   http://...","cursor":48,"status":"running","exit_code":null}

# Le poll suivant reprend depuis le cursor renvoyé
curl "$BASE_URL/pty/sessions/42/poll?cursor=48"
```

**Terminer** :

```bash
curl -X POST -H "X-CSRF-TOKEN: $TOKEN" "$BASE_URL/pty/sessions/42/kill"
```

**Limitations Phase 1** :

- Pas de stdin. L'endpoint `write` renvoie 501. PHP ne peut pas garder
  un pipe vivant entre requêtes HTTP sans worker persistant.
- Pas de vrai TTY. On spawn via `proc_open`, pas `openpty`. Les
  consommateurs qui ont besoin de sémantique terminal réelle (TUI mode
  curses, positionnement de curseur par séquence d'échappement) ne
  rendront pas correctement.

**Phase 2 (différée)** upgradera le transport à WebSocket via Laravel
Reverb / Soketi, le protocole keyed-cursor restant inchangé.

### 29.8 File d'attente hôte pour partage de session

**But** : générer une URL partageable pour une session afin qu'un
collègue puisse review la trace d'audit de l'agent sans accès DB.

**Mode REMOTE** (push vers un sharer externe) :

```dotenv
AI_CORE_SHARE_ENABLED=true
AI_CORE_SHARE_REMOTE_URL=https://share.acme.example.com
AI_CORE_SHARE_SECRET=opaque-bearer-token-the-sharer-accepts
```

```bash
curl -X POST -H "X-CSRF-TOKEN: $TOKEN" "$BASE_URL/share/sessions/$SESSION_ID/create"
# → {"ok":true,"share_id":"abc123…","share_url":"https://share.acme.example.com/shares/abc123…","status":"active","message":"Share ready."}
```

**Mode LOCAL** (intranet — le propre SuperAICore de l'hôte sert le
partage) :

```dotenv
AI_CORE_SHARE_ENABLED=true
AI_CORE_SHARE_LOCAL_URL_TEMPLATE=https://internal.acme.example.com/super-ai-core/shares/{share_id}
```

Le template d'URL local est rendu en substituant `{share_id}` dans le
placeholder. ShareSessionService écrit la ligne mais NE pousse nulle
part ; l'hôte est censé exposer une route qui lit la ligne et rend la
session par son `share_id`.

**Révocation** :

```bash
curl -X POST -H "X-CSRF-TOKEN: $TOKEN" "$BASE_URL/share/sessions/$SESSION_ID/destroy"
```

La ligne locale passe à `revoked`. En mode REMOTE, un DELETE
best-effort fire aussi contre `<remote_url>/api/shares/<share_id>` —
les échecs sont silencés parce que la révocation locale seule suffit
à arrêter d'exposer le lien.

### 29.9 Plomberie SDK 1.0.5 — LSP, compactor structuré, Gemini 3.5

- **Outil LSP** — réglez `AI_CORE_TOOLS_LSP=true` et
  SuperAgentBackend ajoute `lsp` à la liste `load_tools` implicite.
  L'agent obtient `lsp.diagnostics($file)` /
  `lsp.hover($file, $line, $col)` /
  `lsp.definition($file, $line, $col)` / `lsp.touch($file)` contre
  l'un des 9 serveurs LSP intégrés du SDK (phpactor, intelephense,
  gopls, rust-analyzer, pyright, typescript-language-server, clangd,
  bash-language-server, zls). Les root markers par serveur sont
  composer.json / go.mod / Cargo.toml / etc.
- **Résumé compacté structuré** — réglez
  `AI_CORE_COMPRESSION_SUMMARY_PROMPT=structured` pour opter chaque
  dispatch dans le template 7 sections du SDK 1.0.5 (Goal /
  Constraints / Progress / Decisions / Next Steps / Critical Context
  / Relevant Files). ~30-50% plus court que le défaut ; préserve
  l'état blocked entre compactions. `options['summary_prompt']`
  per-call gagne.
- **Fonctionnalités Gemini 3.5** — passez `thinking`, `grounding` /
  `google_search`, `url_context` comme options per-call sur
  `Dispatcher::dispatch()`. SuperAgentBackend les transmet à
  `Agent::run($prompt, $options)` ; le `GeminiProvider` du SDK gate
  sur `modelSupportsThinking()` pour la branche thinking et
  n'appende `{googleSearch: {}}` / `{urlContext: {}}` que sur ses
  propres `tools[]`. Ignoré silencieusement par les autres providers.
- **Correctifs transcoder handoff cross-provider** — le bug
  d'early-return de `ChatCompletionsProvider::convertMessage()` du SDK
  0.9.5 (qui corrompait les traces tool-use multi-tours contre Kimi /
  GLM / MiniMax / Qwen / OpenAI / OpenRouter / LMStudio) est fixé dans
  le pin SDK 1.0.5. Les hôtes utilisant `max_turns > 1` contre l'un de
  ces providers upgrade silencieusement — pas de changement de code.
- **`gemini-3.5-pro / -flash / -flash-lite` dans `EngineCatalog`** —
  les trois slugs Gemini 3.5 sont désormais des available_models pour
  le moteur gemini-cli et apparaissent dans le dropdown. La CLI gemini
  système peut ne pas accepter les slugs 3.5 encore ; les appelants
  SDK utilisant les tags `sdk:` les drivent aujourd'hui.

### 29.10 Quand utiliser quoi

| Vous voulez … | Utilisez … |
|---|---|
| « Montre-moi ce que ce dispatch a vraiment changé » | Résumé diff par fichier (§29.1) |
| « Annule les édits worktree de ce run » | Endpoint revert (§29.1) |
| « L'agent doit demander à l'utilisateur avant de faire X » | Outil `ask_user` (§29.2) |
| « Préfixe de system prompt context-sensitive » | Rappels de session (§29.3) |
| « Cet agent doit être read-only » | Ruleset par agent (§29.4) |
| « Plan d'abord, build seulement après approbation » | Mode plan (§29.5) |
| « Les sous-agents doivent hériter du set deny du parent » | Dérivation perm sous-agent (§29.6) |
| « Streamer un shell long-running dans l'UI » | Sessions PTY (§29.7) |
| « Partager cette session avec un collègue » | File de partage de session (§29.8) |
| « L'agent a besoin de diagnostics LSP mid-loop » | Outil LSP (§29.9) |
| « Résumé compacteur plus court pour sessions longues » | `summary_prompt: structured` (§29.9) |
| « Gemini 3.5 thinking + grounding » | Options per-call Gemini 3.5 (§29.9) |

---

## 30. Opus 4.8 + Grok + Cursor (1.0.0 / SDK 1.0.9)

La version stable 1.0.0 embarque le SDK `^1.0.9` et ajoute la génération
Claude Opus 4.8, xAI Grok sur deux canaux indépendants, et deux nouveaux
moteurs CLI sur abonnement (Cursor Composer + Grok Build). Tout ce qui
suit est additif — aucun changement de schéma, aucune publication de config.

### 30.1 Routage de Claude Opus 4.8

`claude-opus-4-8` est le nouveau fleuron d'Anthropic : il détient l'alias
`opus`, le contexte 1M natif, le thinking entrelacé, le mode rapide et le
contrôle de l'effort, au tier Opus (15 $ / 75 $ par 1M). L'alias se résout
automatiquement :

```php
use SuperAICore\Services\ClaudeModelResolver;

ClaudeModelResolver::resolve('opus');            // 'claude-opus-4-8'
ClaudeModelResolver::resolve('claude-opus-4-8'); // passthrough
```

Le catalogue de moteurs `claude`, `model_pricing` et les tiers **expert**
de `squad` / `cli_squad` pointent tous vers 4.8. Épinglez explicitement un
Opus plus ancien si vous en avez besoin — les anciens ids restent dans le
catalogue :

```php
app(\SuperAICore\Dispatcher::class)->dispatch([
    'prompt'  => $task,
    'backend' => 'anthropic_api',
    'model'   => 'claude-opus-4-8',   // ou 'claude-opus-4-7' pour épingler
]);
```

### 30.2 Deux canaux Grok — API vs CLI (ne les confondez pas)

« Grok » est joignable de deux façons, et elles sont délibérément séparées :

| | Type de provider `grok` (API) | Moteur `grok_cli` (CLI) |
|---|---|---|
| Backend | `superagent` → SDK `GrokProvider` | `grok_cli` (binaire `grok`) |
| Endpoint | `https://api.x.ai/v1` | grok.com (Grok Build) |
| Auth | `XAI_API_KEY` / `GROK_API_KEY` | `grok login` (`~/.grok`) |
| Modèle par défaut | `grok-4.3` (ctx 1M) | `grok-build` |
| Facturation | au compteur (usage) | abonnement (lignes à 0 $) |

```php
// (a) API xAI au compteur — ligne provider, type=grok, routée via superagent :
$provider = \SuperAICore\Models\AiProvider::create([
    'backend' => 'superagent',
    'type'    => 'grok',
    'name'    => 'xAI Grok',
    'api_key' => env('XAI_API_KEY'),   // GROK_API_KEY également accepté
]);

// (b) CLI sur abonnement — pas de clé ; `grok login` gère l'auth :
app(\SuperAICore\Dispatcher::class)->dispatch([
    'prompt'  => $task,
    'backend' => 'grok_cli',
    'model'   => 'grok-build',
    'effort'  => 'high',   // low | medium | high | xhigh | max
]);
```

`api:status` sonde le canal API (filtré sur les clés configurées) ; le
canal CLI apparaît dans `cli:status` et les cartes de moteur `/providers`.

### 30.3 Onboarding du CLI Cursor Composer

```bash
curl https://cursor.com/install -fsS | bash   # installe cursor-agent
cursor-agent login                             # OAuth navigateur → ~/.cursor
./vendor/bin/superaicore cli:status            # confirme « logged in »
```

Dispatchez à travers lui (facturé à l'abonnement ; `--force` auto-approuve
les outils pour que les exécutions headless ne bloquent pas sur la
confirmation par outil) :

```php
app(\SuperAICore\Dispatcher::class)->dispatch([
    'prompt'  => $task,
    'backend' => 'cursor_cli',
    'model'   => 'composer-2.5-fast',   // ou 'composer-2.5', 'auto', etc.
    'cwd'     => base_path(),            // mappé sur --workspace
]);
```

Les serveurs MCP se synchronisent vers `.cursor/mcp.json` via
`McpManager::syncAllBackends()`. Les runners headless sans navigateur
exportent `CURSOR_API_KEY` au lieu de `cursor-agent login`. Le sélecteur de
modèle est piloté par `CursorModelResolver` (avec `liveCatalog()` qui
re-sonde `cursor-agent models`).

### 30.4 CLI Grok Build + contrôle de l'effort

```bash
curl -fsSL https://grok.com/install.sh | bash  # installe grok
grok login                                      # OAuth grok.com → ~/.grok
```

```php
app(\SuperAICore\Dispatcher::class)->dispatch([
    'prompt'           => $task,
    'backend'          => 'grok_cli',
    'model'            => 'grok-build',
    'effort'           => 'max',          // → --effort
    // 'reasoning_effort' => '...',       // → --reasoning-effort
]);
```

Les spawns scriptés utilisent `--prompt-file` (pas de limite de longueur
d'argv) ; le backend émet l'enveloppe standard avec `usage.input_tokens` /
`output_tokens` parsés. Grok dispose de sous-agents natifs (`--agents` /
`create-subagent`) et gère MCP via `grok mcp add` (pas de fichier de config
inscriptible par l'hôte).

### 30.5 Où ils apparaissent

Comme `EngineCatalog`, `ProviderTypeRegistry` et les résolveurs de modèle
par moteur alimentent tout, les deux CLI apparaissent automatiquement dans :
l'UI `/providers` (cartes de moteur, lignes builtin, menus déroulants
d'ajout de provider, badges de version + login), les sélecteurs de modèle
(`modelOptions('cursor')` / `modelOptions('grok')`), `cli:status`, le
tableau de bord des coûts (sous « Subscription engines », lignes à 0 $), le
Process Monitor (lignes en direct + mots-clés de scan), et la sync
`McpManager`.

---

## 31. Support bi-CLI kimi-cli + kimi-code (1.0.2 / SDK 1.0.10)

Le nouveau `@moonshot-ai/kimi-code` (TypeScript) de Moonshot remplace l'ancien
`MoonshotAI/kimi-cli` (Python). Les deux publient le même binaire `kimi` mais
exposent une surface headless incompatible, donc le backend `kimi_cli` détecte
désormais lequel est installé et s'adapte. Le pin passe aussi de SDK `^1.0.9` à
`^1.0.10`. Additif — aucun changement de schéma, aucun config publish ; l'id de
backend Dispatcher `kimi_cli` est inchangé.

### 31.1 Détection de variante + override

`KimiCliBackend` résout le dialecte une fois par binaire (mis en cache) via une
sonde `kimi --help` unique — l'ancien CLI expose un flag `--print`, kimi-code
non. Épinglez-le pour éviter la sonde pendant la transition :

```php
// config/super-ai-core.php — backends.kimi_cli.variant
'variant' => env('AI_CORE_KIMI_CLI_VARIANT', 'auto'),  // auto | kimi-code | kimi-cli
```

```bash
AI_CORE_KIMI_CLI_VARIANT=kimi-code   # force le nouveau CLI (déjà mis à jour)
AI_CORE_KIMI_CLI_VARIANT=kimi-cli    # force l'ancien CLI (encore en Python)
AI_CORE_KIMI_CLI_VARIANT=auto        # défaut : sonde `kimi --help`
```

Le dispatch est identique dans les deux cas — l'id de backend ne change jamais :

```php
app(\SuperAICore\Dispatcher::class)->dispatch([
    'prompt'  => $task,
    'backend' => 'kimi_cli',
    'model'   => 'kimi-k2-turbo',   // optionnel ; --model sur les deux dialectes
]);
```

### 31.2 La matrice des flags (pourquoi la détection est nécessaire)

| | ancien `kimi-cli` (`--print`) | nouveau `kimi-code` (`--prompt`) |
|---|---|---|
| Déclencheur headless | `--print` (booléen, implique yolo) | `--prompt` (mode print) |
| Format de sortie | `--output-format=stream-json` | `--output-format stream-json` |
| Plafond d'étapes | `--max-steps-per-turn N` | — (config.toml) |
| MCP par exécution | `--mcp-config-file F` | — (config.toml) |
| Répertoire de travail | `-w <dir>` | — (cwd du process) |
| Options inconnues | tolérées | rejetées strictement |
| `content` assistant | tableau de blocs (`text` / `think`) | chaîne simple |
| indice de reprise | stderr | ligne NDJSON `{"role":"meta",…}` |

Le parseur tolère les deux formes de `content` et ignore la ligne `role:meta`
de reprise de kimi-code, donc il reste correct même si la détection se trompe.
La commande ancienne envoyée à kimi-code serait rejetée d'emblée (`--print`
inconnu) — d'où l'adaptation de l'argv par dialecte. kimi-code n'a pas de flag
`--mcp-config-file` par exécution, donc un `mcp_config_file` passé est
silencieusement ignoré (MCP y est piloté par config.toml).

### 31.3 SDK 1.0.10 — correctifs Kimi/OpenAI-compatible transparents

Le pin vers `^1.0.10` atteint le backend `superagent` sans changement de code
SuperAICore. Les types de provider HTTP direct `kimi` / `qwen` / `glm` /
`deepseek` / `grok` / `openrouter` / `openai` obtiennent désormais :

- **Comptage usage en streaming** — `stream_options.include_usage` est envoyé,
  donc les réponses streamées reportent à nouveau un bloc `usage`. Avant, les
  appels streamés via ces types enregistraient 0 $ de token/coût/cache sur les
  lignes `ai_usage` et le tableau de bord `/providers`.
- **Normalisation stricte des schémas d'outils** — les `$ref`/`$defs` locaux
  sont inlinés et les propriétés enum-only sans type reçoivent un `type`, donc
  les outils MCP / Skill / Agent passent le validateur de Moonshot.
- **`max_completion_tokens`** pour les modèles de raisonnement Kimi (plus de
  réponses vides quand le canal de raisonnement épuise le budget) +
  round-trip de `reasoning_content`.
- **Découverte de capacités par modèle** — les flags `thinking` / `vision` /
  `tools` / `structured_output` lus depuis la réponse `/models` du provider
  alimentent le routage par capacité.
- **`SUPERAGENT_KIMI_SWARM_ENABLED`** (nouveau, opt-in) — l'outil spéculatif
  Kimi Agent-Swarm REST est désactivé par défaut.

Les notes de conception du backend bi-CLI sont dans `docs/kimi-cli-backend.md`
§8.

---

## 32. SmartFlow — workflows dynamiques cross-CLI + fédération superagent (1.0.5 / SDK 1.1.0)

SmartFlow est le portage par SuperAICore du moteur `Workflow` intégré de Claude
Code, reciblé pour que l'unité de routage soit un **CLI/backend** plutôt qu'un
modèle API. Il suit le SmartFlow cross-*modèle* du SDK SuperAgent (SDK 1.1.0) mais
pilote les backends que SuperAICore gère déjà, et il peut **déléguer** un sous-flow
au moteur du SDK pour une véritable fédération cross-CLI → cross-modèle. Additif :
le Dispatcher, AgentSpawn et les orchestrateurs Squad/Team/Smart/Auto restent
intacts. Référence complète : [docs/smartflow.md](smartflow.md).

### 32.1 Les primitives

Un corps de flow est `callable(Flow $flow): mixed` (ou un fichier YAML compilé en
un tel callable). `$flow` expose : `agent($prompt, $opts)` (un appel cross-CLI →
tableau validé avec `schema`, chaîne brute, ou `$flow->SKIP`), `call()` (différé,
pour le fan-out), `parallel([...])` (barrière ; les appels différés s'exécutent en
parallèle via un pool de processus), `pipeline($items, ...$stages)` (par item /
par étape), `gate($name, $check, $opts)` (acceptation avec
`fallback`/`relay`/`required`), `council($claim, $lenses)` (vote multi-perspective,
chaque lentille épinglable à un CLI différent), `budget`, et `log()`/`phase()`.
Clés de `$opts` : `backend` (le CLI — `provider` est un alias accepté), `model`,
`role` (persona), `system`, `schema`, `temperature`, `max_tokens`, `label`,
`provider_config`.

```php
use SuperAICore\SmartFlow\{FlowEngine, FlowDefinition, FlowOptions};

$def = FlowDefinition::make('review', 'cross-CLI review', function ($flow) {
    $flow->phase('Summarize');
    $summary = $flow->agent("Summarize:\n{$flow->args['diff']}", ['backend' => 'claude_cli']);

    $flow->phase('Review');
    $reviews = $flow->parallel([
        $flow->call("Correctness:\n$summary", ['role' => 'reviewer', 'backend' => 'codex_cli']),
        $flow->call("Security:\n$summary",    ['role' => 'reviewer', 'backend' => 'gemini_cli']),
    ]);

    return $flow->agent("Decide:\n" . json_encode($flow->keep($reviews)), [
        'backend' => 'claude_cli',
        'schema'  => ['type' => 'object', 'required' => ['decision'],
            'properties' => ['decision' => ['type' => 'string', 'enum' => ['approve', 'request_changes']]]],
    ]);
});

$result = (new FlowEngine())->run($def, ['diff' => $diff]);   // ->value, ->costUsd(), ->ledger, ->runId
```

### 32.2 Sortie structurée — le filet de sécurité à 3 couches

Les CLIs renvoient de la prose, donc un `schema` demandé est intégré au prompt et
une valeur valide est récupérée via trois couches croissantes — JSON de la réponse
entière (`native`/`submitted`) → bloc balisé ```` ```json ```` (`submitted`) →
objet/tableau reniflé par regex (`extracted`) — validée par un `SchemaValidator`
sans dépendance. Si aucune ne valide, l'appel renvoie la sentinelle `SKIP` au lieu
de crasher, de sorte qu'un fan-out peut écarter les mauvaises réponses via
`$flow->keep(...)`.

### 32.3 Resume, journal, répétition

Chaque exécution ajoute un journal JSONL sous
`~/.superaicore/flows/<runId>.jsonl` (override : `SUPERAICORE_FLOW_DIR` ou
`super-ai-core.smartflow.ledger_dir`). Chaque appel reçoit une signature adressée
par contenu à partir de ce que vous avez *déclaré* ; `--resume <runId>` rejoue le
plus long préfixe inchangé à coût nul et n'exécute en réel qu'à partir du premier
appel modifié (les gates occupent un emplacement de journal pour que les appels
post-gate restent alignés). `--rehearse` / `--dry-run` exécutent un flow de bout en
bout **sans aucun CLI invoqué** — les appels à schéma reçoivent des stubs
déterministes conformes au schéma, le coût est `$0` — donc les flows sont testables
sur une machine vierge.

### 32.4 Fédération — déléguer un sous-flow à superagent

Un flow SuperAICore peut confier un sous-flow au SmartFlow (cross-modèle) propre à
superagent. C'est le découpage en couches voulu : SuperAICore fait du fan-out à
travers les CLIs ; le segment `superagent` fait du fan-out à travers les providers
de modèles. Deux modes :

```php
// named — superagent exécute l'un de SES PROPRES flows ; il se distribue lui-même à travers les providers
$findings = $flow->delegate('research-trio', [
    'flow_args'        => ['topic' => $flow->args['goal']],
    'delegate_provider' => 'openai',     // steer superagent's model tier
]);

// spec — superagent exécute un flow que SuperAICore A ÉCRIT (provider-based, cross-model)
$brief = $flow->delegate('', ['spec' => [
    'name'  => 'mini-brief',
    'steps' => [
        ['name' => 'gather', 'role' => 'researcher', 'provider' => 'openai',    'prompt' => 'research {{args.q}}'],
        ['name' => 'write',  'role' => 'writer',     'provider' => 'anthropic', 'prompt' => "summarize:\n{{steps.gather.output}}"],
    ],
    'return' => 'write',
], 'flow_args' => ['q' => $flow->args['goal']]]);
```

Un appel délégué utilise la même machinerie journal / budget / resume /
`parallel()`, donc son coût se fédère dans le budget parent et il répète avec le
parent. La **spec inline utilise le schéma du SDK** (les étapes routent à travers
les `provider`s de modèles, pas les CLIs) et est exécutée par le moteur de
superagent. Une délégation named nécessite que le flow existe dans le registre du
SDK (`superagent flow list`) ; un SDK manquant ou un flow inconnu échoue proprement
(vide / `SKIP`) sans crasher le parent. Sous le capot :
`SuperAICore\SmartFlow\Delegation` + `SuperAgentFlowBridge` (in-process via
`SuperAgent\SmartFlow\FlowEngine`).

### 32.5 Écriture YAML

Les flows statiques vivent dans `resources/flows/*.yaml` (compilés par
`YamlFlowLoader`). Déposez les vôtres sous `./flows`, `./.superaicore/flows`, ou
`super-ai-core.smartflow.flows_dir`. Templating : `{{args.x}}`,
`{{steps.<name>.output}}`, `{{item}}`, chemins pointés. Stratégies : `solo`
(défaut), `parallel`, `pipeline`, `gate`, `delegate`.

```yaml
- name: research            # hand the research leg to superagent
  strategy: delegate
  delegate: research-trio   # named SDK flow (or `spec: {...}` to author inline)
  provider: "{{args.research_provider}}"
  flow_args: {topic: "{{args.goal}}"}
```

Flows intégrés : `cross-cli-review`, `cross-cli-dev`, `cross-cli-council`,
`cross-cli-federated`. CLI : `superaicore flow list|show|plan|run` (et
`php artisan flow ...`).

### 32.6 Quand recourir à SmartFlow

Smart / Squad / Auto décomposent une tâche de façon heuristique et routent les
sous-tâches ; AgentSpawn est le protocole de plan de spawn en 3 phases pour les
CLIs sans outil Agent natif. Recourez à **SmartFlow** quand vous voulez un flux de
contrôle multi-étapes *explicitement écrit* (fan-out, pipelines, gates, councils),
un routage CLI par étape, une sortie structurée, des budgets, de la répétition, du
resume et la fédération superagent — la même forme que le `Workflow` de Claude
Code, rendue cross-CLI.

---

## 33. Pont de skills CLI — `superaicore:sync-cli` + le contrat `SkillLibrary` (1.0.6)

SuperAICore relie déjà le **MCP** à la config native de chaque CLI backend
(`McpManager::syncAllBackends()`, §13). 1.0.6 offre aux **skills + agents** le même
traitement avec un pont générique unique, de sorte qu'un hôte cesse de bricoler un
sync séparé par CLI (un installateur de wrapper Codex, un sync de commande custom
Gemini, un traducteur Kimi, …).

Le partage des responsabilités est tout l'enjeu :

- **SuperAICore sait OÙ / COMMENT / QUAND.** Où chaque CLI range ses skills,
  comment y installer un wrapper *en sécurité* (jamais à travers un symlink), et
  quand resynchroniser (uniquement quand l'empreinte a dérivé).
- **L'hôte sait QUOI.** Il implémente `SuperAICore\Contracts\SkillLibrary` et la
  bind. SuperAICore ne porte aucune hypothèse d'hôte — quand rien n'est bindé, le
  pont est un no-op silencieux, de sorte que le package reste agnostique de
  l'hôte.

### Le contrat

```php
namespace SuperAICore\Contracts;

interface SkillLibrary
{
    /** @return array<int,array{name:string,description:string}> */
    public function skills(): array;

    /** @return array<int,array{name:string,description?:string}> */
    public function agents(): array;

    /** Full SKILL.md for a backend's NATIVE skill dir (codex/gemini/…). */
    public function skillWrapper(string $backend, string $skillName): string;

    /** Markdown digest for backends with no skill dir (copilot/kimi/kiro). */
    public function instructionsDigest(string $backend): string;

    /** Stable hash of the whole library; drives the lazy re-sync. */
    public function fingerprint(): string;
}
```

Bindez-le dans un service provider :

```php
$this->app->singleton(
    \SuperAICore\Contracts\SkillLibrary::class,
    \App\Services\SuperTeamSkillLibrary::class,
);
```

### Les wrappers fins gardent la source autoritaire

Le corps `skillWrapper()` recommandé est un SKILL.md **fin** qui délègue au loader
de l'hôte au lieu de dupliquer le vrai corps du skill — ainsi les éditions du
`.claude/skills/<name>/SKILL.md` canonique ne nécessitent aucun resync, et le
wrapper ne peut jamais dériver de (ou écraser) la source :

```php
public function skillWrapper(string $backend, string $skill): string
{
    return <<<MD
    ---
    name: super-team-{$skill}
    description: Runtime wrapper for `{$skill}`; loads the latest definition from source.
    ---
    Load the canonical definition (do not duplicate it here):
    ```bash
    php /path/to/host/artisan super-team:skill {$skill} --format=markdown
    ```
    MD;
}
```

### Trois formes d'installation

`CliSkillBridge::BACKENDS` mappe chaque backend sur un mode — ajouter un CLI est un
changement d'une ligne :

| Mode | Backends | Ce qui est posé | Chemin $HOME |
|------|----------|-----------|-----------|
| `native_dir`  | codex, gemini, grok, cursor, qwen | un répertoire de wrapper préfixé par skill (`super-team-<name>/SKILL.md`) | `.codex/skills`, `.gemini/skills`, `.grok/skills`, `.cursor/skills-cursor`, `.qwen/skills` |
| `instructions`| copilot, kimi, kiro | un fichier digest (comment charger n'importe quel skill à la demande + la liste) | `.copilot/super-team-skills.md`, `.kimi/…`, `.kiro/…` |
| `source`      | claude | rien — lit le `.claude/skills` de l'hôte directement | — |
| `none`        | superagent | rien | — |

### Écritures sûres face aux symlinks (le correctif d'écrasement)

Le pont **n'écrit jamais à travers un symlink**. Avant d'écrire un répertoire de
wrapper, un `SKILL.md`, un digest ou un manifeste, il vérifie la cible via
`is_link()` et délie d'abord un lien périmé (en laissant la *cible* du lien
intacte). Cela ferme la faille d'écriture-à-travers-symlink où un lien résiduel
`~/.codex/skills/super-team-x -> …/.claude/skills/x` laissait une écriture de
wrapper écraser le vrai corps du skill source.

### Sync paresseux au dispatch

Chaque sync inscrit le `fingerprint()` dans un manifeste par backend
(`.superteam-skill-sync.json`) à côté de la liste des wrappers qu'il a installés.
`TaskRunner` appelle le pont avant chaque dispatch CLI :

```php
// TaskRunner::ensureCliSkillsSynced() — normalizes codex_cli → codex, best-effort
(new \SuperAICore\Services\CliSkillBridge())->ensureSynced($engine);
```

`ensureSynced()` est peu coûteux : `needsSync()` compare un seul hash et retourne
tôt quand la bibliothèque n'a pas changé, de sorte que le coût par dispatch est une
seule comparaison. Le pruning est **borné au manifeste** — seuls les wrappers que
ce pont a installés auparavant et qu'il ne veut plus sont retirés ; les skills
propres à l'utilisateur ne sont jamais touchés. Tout échec est avalé pour qu'un
raté de sync ne puisse jamais bloquer un dispatch.

### `superaicore:sync-cli` — le refresh complet manuel / cron

```bash
php artisan superaicore:sync-cli                       # skills + MCP → every installed CLI
php artisan superaicore:sync-cli --skills-only         # skip the MCP step
php artisan superaicore:sync-cli --mcp-only            # only MCP (= mcp:sync-backends)
php artisan superaicore:sync-cli --backends=codex,gemini
php artisan superaicore:sync-cli --project-root=/path  # override .mcp.json discovery
```

Les skills passent par `CliSkillBridge` ; le MCP réutilise
`McpManager::syncAllBackends()`. Le hook `TaskRunner` par dispatch garde les
backends à jour pendant l'usage normal — recourez à cette commande pour un refresh
ponctuel depuis un git hook, un cron, ou après avoir édité la bibliothèque.

### Usage programmatique

```php
$bridge = new \SuperAICore\Services\CliSkillBridge();   // resolves the bound library
if ($bridge->active()) {
    $report = $bridge->syncAll(['codex', 'gemini']);    // [['backend'=>…,'installed'=>189,'pruned'=>0,'path'=>…], …]
}
```

---

## Voir aussi

- [docs/smartflow.md](smartflow.md) — workflows cross-CLI SmartFlow + fédération superagent (1.0.5)
- [docs/idempotency.md](idempotency.md) — fenêtre de dédup 60s, contrat niveau repository
- [docs/streaming-backends.md](streaming-backends.md) — formats de stream par CLI
- [docs/task-runner-quickstart.md](task-runner-quickstart.md) — référence d'options `TaskRunner`
- [docs/spawn-plan-protocol.md](spawn-plan-protocol.md) — émulation agent codex/gemini
- [docs/mcp-sync.md](mcp-sync.md) — sync MCP piloté par catalogue
- [docs/api-stability.md](api-stability.md) — contrat SemVer
