<?php

namespace SuperAICore\Support;

/**
 * Declarative command-shape metadata for a CLI engine.
 *
 * Describes WHAT args the binary accepts, not HOW a given host launches it.
 * Runtime concerns (cwd, env resolution, timeouts as policy, stdin wiring,
 * retry, logging) stay in the caller — this type is meant to be safe to
 * expose on EngineDescriptor and drive a default builder in
 * CliProcessBuilderRegistry.
 *
 * Seeded per engine in EngineCatalog; overridable from host config via
 * `super-ai-core.engines.<key>.process_spec`.
 */
final class ProcessSpec
{
    /**
     * @param string   $binary             short command name (e.g. 'claude')
     * @param string[] $versionArgs        args that print the version (e.g. ['--version'])
     * @param ?string[] $authStatusArgs    args that print auth status, or null when the CLI has none
     * @param ?string  $promptFlag         flag preceding the prompt string (e.g. '-p' or '--print'); null if positional
     * @param ?string  $outputFormatFlag  full "--output-format=json" style flag that asks for structured output
     * @param ?string  $modelFlag          flag preceding a model id (e.g. '--model')
     * @param string[] $defaultFlags       extra flags always appended (e.g. ['--allow-all-tools'])
     * @param int      $defaultTimeoutSec  suggested spawn timeout for generate() calls
     */
    public function __construct(
        public readonly string  $binary,
        public readonly array   $versionArgs = ['--version'],
        public readonly ?array  $authStatusArgs = null,
        public readonly ?string $promptFlag = null,
        public readonly ?string $outputFormatFlag = null,
        public readonly ?string $modelFlag = null,
        public readonly array   $defaultFlags = [],
        public readonly int     $defaultTimeoutSec = 300,
    ) {}

    public function toArray(): array
    {
        return [
            'binary'               => $this->binary,
            'version_args'         => $this->versionArgs,
            'auth_status_args'     => $this->authStatusArgs,
            'prompt_flag'          => $this->promptFlag,
            'output_format_flag'   => $this->outputFormatFlag,
            'model_flag'           => $this->modelFlag,
            'default_flags'        => $this->defaultFlags,
            'default_timeout_sec'  => $this->defaultTimeoutSec,
        ];
    }

    /**
     * Hydrate from an array (host config / test fixture).
     */
    public static function fromArray(array $a): self
    {
        return new self(
            binary:            (string) ($a['binary'] ?? ''),
            versionArgs:       (array)  ($a['version_args'] ?? ['--version']),
            authStatusArgs:    isset($a['auth_status_args']) ? (array) $a['auth_status_args'] : null,
            promptFlag:        $a['prompt_flag'] ?? null,
            outputFormatFlag:  $a['output_format_flag'] ?? null,
            modelFlag:         $a['model_flag'] ?? null,
            defaultFlags:      (array)  ($a['default_flags'] ?? []),
            defaultTimeoutSec: (int)    ($a['default_timeout_sec'] ?? 300),
        );
    }
}
