<?php

namespace SuperAICore\Models;

use SuperAICore\Models\Concerns\HasConfigurablePrefix;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * AI Provider — execution engine configuration row.
 *
 * Ported from SuperTeam.
 *
 * @property int $id
 * @property string $scope         global | user
 * @property int|null $scope_id    user_id when scope=user
 * @property string $backend       claude | codex | superagent
 * @property string $name          Display name
 * @property string $type          builtin | anthropic | anthropic-proxy | bedrock | vertex | openai | openai-compatible
 * @property string|null $base_url
 * @property string|null $api_key  Encrypted at rest
 * @property array|null  $extra_config
 * @property bool $is_active
 * @property int  $sort_order
 */
class AiProvider extends Model
{
    use HasConfigurablePrefix;

    const BACKEND_CLAUDE     = 'claude';
    const BACKEND_CODEX      = 'codex';
    const BACKEND_SUPERAGENT = 'superagent';
    const BACKEND_GEMINI     = 'gemini';
    const BACKEND_COPILOT    = 'copilot';
    const BACKEND_KIRO       = 'kiro';
    const BACKEND_KIMI       = 'kimi';
    const BACKENDS = [self::BACKEND_CLAUDE, self::BACKEND_CODEX, self::BACKEND_SUPERAGENT, self::BACKEND_GEMINI, self::BACKEND_COPILOT, self::BACKEND_KIRO, self::BACKEND_KIMI];

    const TYPE_BUILTIN           = 'builtin';
    const TYPE_ANTHROPIC         = 'anthropic';
    const TYPE_ANTHROPIC_PROXY   = 'anthropic-proxy';
    const TYPE_BEDROCK           = 'bedrock';
    const TYPE_VERTEX            = 'vertex';
    const TYPE_OPENAI            = 'openai';
    const TYPE_OPENAI_COMPATIBLE = 'openai-compatible';
    const TYPE_GOOGLE_AI         = 'google-ai';
    // Kiro CLI's headless API-key channel. Stored key is injected as
    // KIRO_API_KEY at spawn time; presence of the env var makes kiro-cli
    // skip its browser-login flow. Requires a Kiro Pro / Pro+ / Power plan.
    const TYPE_KIRO_API          = 'kiro-api';
    // Kimi CLI's OAuth-subscription channel. `kimi login` (browser OAuth)
    // owns the token; SuperAICore only reads the state in ~/.kimi/. Distinct
    // from the BYO-API-key Moonshot path, which routes through the
    // superagent backend (SDK's KimiProvider) against api.moonshot.ai.
    const TYPE_MOONSHOT_BUILTIN  = 'moonshot-builtin';
    // OpenAI Responses API (SDK 0.9.1+). Accepts api_key for metered use,
    // or an `access_token` stored via host-app OAuth for ChatGPT Plus/Pro
    // subscribers — SDK flips base_url to chatgpt.com/backend-api/codex.
    // Azure OpenAI deployments are auto-detected from the base_url markers.
    const TYPE_OPENAI_RESPONSES  = 'openai-responses';
    // Local LM Studio server (SDK 0.9.1+). Defaults to http://localhost:1234.
    const TYPE_LMSTUDIO          = 'lmstudio';

    const TYPES = [
        self::TYPE_BUILTIN           => 'builtin',
        self::TYPE_ANTHROPIC         => 'anthropic',
        self::TYPE_ANTHROPIC_PROXY   => 'anthropic-proxy',
        self::TYPE_BEDROCK           => 'bedrock',
        self::TYPE_VERTEX            => 'vertex',
        self::TYPE_OPENAI            => 'openai',
        self::TYPE_OPENAI_COMPATIBLE => 'openai-compatible',
        self::TYPE_OPENAI_RESPONSES  => 'openai-responses',
        self::TYPE_LMSTUDIO          => 'lmstudio',
        self::TYPE_GOOGLE_AI         => 'google-ai',
        self::TYPE_KIRO_API          => 'kiro-api',
        self::TYPE_MOONSHOT_BUILTIN  => 'moonshot-builtin',
    ];

    /**
     * Valid backend → type matrix (inherited from SuperTeam, extended for Gemini).
     * Arbitrary backend/type combinations are rejected at the validation layer
     * and the "New provider" modal narrows the type dropdown based on backend.
     *
     *  claude     → Anthropic family (+ Bedrock / Vertex passthrough, builtin login)
     *  codex      → OpenAI family (+ builtin ChatGPT login)
     *  superagent → SDK can drive either provider family; no builtin
     *  gemini     → Google AI Studio (google-ai) or Vertex AI (vertex) + builtin OAuth login
     *  copilot    → builtin only (CLI owns OAuth/keychain/refresh; no API-key surface in v1)
     */
    const BACKEND_TYPES = [
        self::BACKEND_CLAUDE => [
            self::TYPE_BUILTIN,
            self::TYPE_ANTHROPIC,
            self::TYPE_ANTHROPIC_PROXY,
            self::TYPE_BEDROCK,
            self::TYPE_VERTEX,
        ],
        self::BACKEND_CODEX => [
            self::TYPE_BUILTIN,
            self::TYPE_OPENAI,
            self::TYPE_OPENAI_COMPATIBLE,
        ],
        self::BACKEND_SUPERAGENT => [
            self::TYPE_ANTHROPIC,
            self::TYPE_ANTHROPIC_PROXY,
            self::TYPE_OPENAI,
            self::TYPE_OPENAI_COMPATIBLE,
            self::TYPE_OPENAI_RESPONSES,
            self::TYPE_LMSTUDIO,
        ],
        self::BACKEND_GEMINI => [
            self::TYPE_BUILTIN,
            self::TYPE_GOOGLE_AI,
            self::TYPE_VERTEX,
        ],
        self::BACKEND_COPILOT => [
            self::TYPE_BUILTIN,
        ],
        self::BACKEND_KIRO => [
            self::TYPE_BUILTIN,    // kiro-cli login already completed on host
            self::TYPE_KIRO_API,   // DB-stored KIRO_API_KEY injected at spawn
        ],
        self::BACKEND_KIMI => [
            // `kimi login` completed on host; ~/.kimi/ holds OAuth state.
            // No API-key channel here — direct-HTTP BYO-key usage routes
            // through the superagent backend via SDK's KimiProvider, not
            // through this CLI.
            self::TYPE_MOONSHOT_BUILTIN,
        ],
    ];

    /**
     * Types that can be routed through $backend.
     *
     * Since 0.6.2 this delegates to `ProviderTypeRegistry` when the
     * container is booted — so host-config-added types surface here too.
     * Falls back to the static BACKEND_TYPES matrix when the registry
     * isn't available (early boot, CLI without a fully-hydrated app).
     *
     * @return string[]
     */
    public static function typesForBackend(string $backend): array
    {
        if (function_exists('app')) {
            try {
                $registry = app(\SuperAICore\Services\ProviderTypeRegistry::class);
                $types = array_keys($registry->forBackend($backend));
                if ($types !== []) return $types;
            } catch (\Throwable) {
                // fall through to static
            }
        }
        return self::BACKEND_TYPES[$backend] ?? [];
    }

    protected $table = 'ai_providers';

    protected $fillable = [
        'scope', 'scope_id', 'backend', 'name', 'type', 'base_url',
        'api_key', 'extra_config', 'is_active', 'sort_order',
    ];

    protected $hidden = ['api_key'];

    protected $casts = [
        'extra_config' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    // ─── API Key encryption ───

    public function setApiKeyAttribute(?string $value): void
    {
        $this->attributes['api_key'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getDecryptedApiKeyAttribute(): ?string
    {
        return $this->attributes['api_key']
            ? Crypt::decryptString($this->attributes['api_key'])
            : null;
    }

    public function hasApiKey(): bool
    {
        return !empty($this->attributes['api_key']);
    }

    public function getMaskedApiKeyAttribute(): ?string
    {
        $key = $this->decrypted_api_key;
        if (!$key) return null;
        if (strlen($key) <= 8) return str_repeat('*', strlen($key));
        return substr($key, 0, 7) . '...' . substr($key, -4);
    }

    // ─── Query helpers ───

    public static function getForScope(string $scope = 'global', ?int $scopeId = null, ?string $backend = null): Collection
    {
        $query = static::where('scope', $scope)
            ->where('scope_id', $scopeId)
            ->orderBy('sort_order')
            ->orderBy('id');
        if ($backend !== null) $query->where('backend', $backend);
        return $query->get();
    }

    public static function getActiveForScope(string $scope = 'global', ?int $scopeId = null, ?string $backend = null): ?self
    {
        $query = static::where('scope', $scope)
            ->where('scope_id', $scopeId)
            ->where('is_active', true);
        if ($backend !== null) $query->where('backend', $backend);
        return $query->first();
    }

    public static function resolveForUser(?int $userId, string $backend = self::BACKEND_CLAUDE): ?self
    {
        if ($userId) {
            $userProvider = static::getActiveForScope('user', $userId, $backend);
            if ($userProvider) return $userProvider;
        }
        return static::getActiveForScope('global', null, $backend);
    }

    public function activate(): void
    {
        static::where('scope', $this->scope)
            ->where('scope_id', $this->scope_id)
            ->where('backend', $this->backend)
            ->where('id', '!=', $this->id)
            ->update(['is_active' => false]);
        $this->update(['is_active' => true]);
    }

    public function deactivate(): void
    {
        $this->update(['is_active' => false]);
    }

    /**
     * 0.6.2+ delegates to ProviderTypeRegistry so host-added types work too.
     * The hardcoded fallback stays accurate for the 9 bundled types.
     */
    public function requiresApiKey(): bool
    {
        if (function_exists('app')) {
            try {
                return app(\SuperAICore\Services\ProviderTypeRegistry::class)
                    ->requiresApiKey($this->type);
            } catch (\Throwable) {
                // fall through
            }
        }
        return in_array($this->type, [
            self::TYPE_ANTHROPIC,
            self::TYPE_ANTHROPIC_PROXY,
            self::TYPE_OPENAI,
            self::TYPE_OPENAI_COMPATIBLE,
            self::TYPE_KIRO_API,
        ]);
    }

    public function requiresBaseUrl(): bool
    {
        if (function_exists('app')) {
            try {
                return app(\SuperAICore\Services\ProviderTypeRegistry::class)
                    ->requiresBaseUrl($this->type);
            } catch (\Throwable) {
                // fall through
            }
        }
        return in_array($this->type, [self::TYPE_ANTHROPIC_PROXY, self::TYPE_OPENAI_COMPATIBLE]);
    }

    public function getApiBaseUrl(): string
    {
        if ($this->type === self::TYPE_ANTHROPIC_PROXY && $this->base_url) {
            return rtrim($this->base_url, '/');
        }
        if ($this->type === self::TYPE_OPENAI_COMPATIBLE && $this->base_url) {
            return rtrim($this->base_url, '/');
        }
        return 'https://api.anthropic.com';
    }

    /**
     * Export row as array suitable for Dispatcher::dispatch(provider_config).
     */
    public function toProviderConfig(): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'backend'      => $this->backend,
            'type'         => $this->type,
            'api_key'      => $this->decrypted_api_key,
            'base_url'     => $this->base_url,
            'extra_config' => $this->extra_config,
        ];
    }
}
