<?php

namespace SuperAICore\Support;

/**
 * Single record in the provider-type registry.
 *
 * Bundles everything a host app ever needs to know about one provider type:
 *   - UI metadata (label / description / icon / form fields)
 *   - Which backends can route it (default + allowed set)
 *   - Env injection spec (api-key env name, base-url env name, extra_config
 *     → env map for bedrock / vertex)
 *   - Whether it needs an API key / base URL
 *
 * Before 0.6.2 this metadata was split three ways:
 *   - SuperAICore  → AiProvider::BACKEND_TYPES (backend/type matrix only)
 *   - SuperAICore  → *CliBackend::buildEnv() hardcoded env keys
 *   - SuperTeam    → IntegrationController::PROVIDER_TYPES (UI labels + fields)
 *
 * Now there's one source: ProviderTypeRegistry. Host apps query the registry
 * and never again have to mirror a table when SuperAICore adds a new type.
 *
 * Back-compat: toArray() produces exactly the shape SuperTeam's Blade templates
 * already iterate (`$type['label_key']`, `$type['fields']`, etc.), so the
 * migration is a single controller method swap — no view changes required.
 */
class ProviderTypeDescriptor
{
    public function __construct(
        /** Stable type id — matches AiProvider::TYPE_* constant. */
        public readonly string $type,

        /** Translator key for the human-readable label. */
        public readonly string $labelKey,

        /** Translator key for the longer description shown in the add-provider UI. */
        public readonly string $descKey,

        /** Bootstrap Icons class name (e.g. 'bi-key'). */
        public readonly string $icon,

        /**
         * Form fields the provider-creation UI should render for this type.
         * Members are stable machine names: 'api_key', 'base_url', 'region',
         * 'access_key_id', 'secret_access_key', 'project_id'.
         *
         * @var string[]
         */
        public readonly array $fields,

        /** Default dispatcher backend for new providers of this type. */
        public readonly string $defaultBackend,

        /**
         * Every backend that can legally route this provider type.
         *
         * @var string[]
         */
        public readonly array $allowedBackends,

        public readonly bool $needsApiKey,
        public readonly bool $needsBaseUrl,

        /**
         * Name of the env var the api_key should be exported as when the
         * runner spawns a CLI. Null when the type doesn't flow a key
         * (e.g. `builtin`, `bedrock` which uses AWS_* env via envExtras).
         */
        public readonly ?string $envKey,

        /**
         * Env var name the base_url should be exported as, when present.
         * `null` when base_url isn't part of this type's contract.
         */
        public readonly ?string $baseUrlEnv,

        /**
         * Map of `env var name → extra_config key`. Used for types that
         * carry structured credentials (bedrock's region / access-key-id /
         * secret-access-key, vertex's project-id / region).
         *
         * @var array<string,string>
         */
        public readonly array $envExtras,

        /**
         * Static env flags to set alongside credentials when this type is
         * routed through certain backends. Keys are backend ids; values are
         * flat key/value env maps. E.g. Vertex on Claude needs
         * `CLAUDE_CODE_USE_VERTEX=1`, Bedrock on Claude needs
         * `CLAUDE_CODE_USE_BEDROCK=1`.
         *
         * @var array<string,array<string,string>>
         */
        public readonly array $backendEnvFlags = [],
    ) {}

    public static function fromArray(string $type, array $data): self
    {
        return new self(
            type:            $type,
            labelKey:        (string) ($data['label_key'] ?? 'super-ai-core::messages.provider_type.' . $type),
            descKey:         (string) ($data['desc_key'] ?? 'super-ai-core::messages.provider_type_desc.' . $type),
            icon:            (string) ($data['icon'] ?? 'bi-plug'),
            fields:          array_values((array) ($data['fields'] ?? [])),
            defaultBackend:  (string) ($data['default_backend'] ?? $data['backend'] ?? ''),
            allowedBackends: array_values((array) ($data['allowed_backends'] ?? [$data['default_backend'] ?? $data['backend'] ?? ''])),
            needsApiKey:     (bool)   ($data['needs_api_key'] ?? in_array('api_key', (array) ($data['fields'] ?? []), true)),
            needsBaseUrl:    (bool)   ($data['needs_base_url'] ?? in_array('base_url', (array) ($data['fields'] ?? []), true)),
            envKey:          $data['env_key'] ?? null,
            baseUrlEnv:      $data['base_url_env'] ?? null,
            envExtras:       (array) ($data['env_extras'] ?? []),
            backendEnvFlags: (array) ($data['backend_env_flags'] ?? []),
        );
    }

    /**
     * Backward-compatible array projection — matches the shape SuperTeam's
     * Blade templates already iterate (`label_key`, `desc_key`, `icon`,
     * `fields`, `backend`, `allowed_backends`). Adding keys here is safe;
     * removing or renaming is a breaking change for template consumers.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'type'              => $this->type,
            'label_key'         => $this->labelKey,
            'desc_key'          => $this->descKey,
            'icon'              => $this->icon,
            'fields'            => $this->fields,
            'backend'           => $this->defaultBackend,
            'allowed_backends'  => $this->allowedBackends,
            'needs_api_key'     => $this->needsApiKey,
            'needs_base_url'    => $this->needsBaseUrl,
            'env_key'           => $this->envKey,
            'base_url_env'      => $this->baseUrlEnv,
            'env_extras'        => $this->envExtras,
            'backend_env_flags' => $this->backendEnvFlags,
        ];
    }

    /**
     * Overlay another descriptor's non-null fields on top of this one.
     * Used to apply host-config overrides on top of the bundled defaults:
     * the host can partially override (e.g. swap `label_key` to point at
     * its own lang namespace) without restating the full descriptor.
     */
    public function mergedWith(array $overrides): self
    {
        return self::fromArray($this->type, array_merge($this->toArray(), $overrides));
    }
}
