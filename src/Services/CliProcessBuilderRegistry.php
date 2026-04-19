<?php

namespace SuperAICore\Services;

use SuperAICore\Support\EngineDescriptor;
use SuperAICore\Support\ProcessSpec;

/**
 * Assembles CLI argv arrays from an engine's declarative ProcessSpec.
 *
 * Purpose:
 *   Host apps have historically maintained their own "how do I spawn the
 *   claude/codex/gemini/copilot CLI" tables. With process_spec now living
 *   on EngineDescriptor, this registry produces the default argv from that
 *   spec — hosts only keep an override when they need non-standard args.
 *
 * Not a Process launcher:
 *   This returns argv (string[]) only. Env vars, cwd, timeout policy,
 *   stdin/tee wiring, and Symfony\Component\Process\Process instantiation
 *   stay in the caller. Runtime policy != command shape.
 *
 * Usage:
 *   $registry = app(CliProcessBuilderRegistry::class);
 *   $cmd = $registry->build('copilot', prompt: 'hello', model: 'gpt-5.1');
 *   // → ['copilot', '-p', 'hello', '--output-format=json', '--allow-all-tools', '--model', 'gpt-5.1']
 *
 *   $registry->register('copilot', function (EngineDescriptor $e, array $opts): array {
 *       // Host-custom shape.
 *   });
 */
class CliProcessBuilderRegistry
{
    /** @var array<string, callable(EngineDescriptor, array): string[]> */
    protected array $overrides = [];

    public function __construct(protected EngineCatalog $catalog) {}

    /**
     * Register a host-side override for a single engine key. Takes precedence
     * over the default spec-driven builder. Returning an empty array is a
     * signal that the engine can't be launched with the given options.
     *
     * @param callable(EngineDescriptor, array): string[] $builder
     */
    public function register(string $engineKey, callable $builder): void
    {
        $this->overrides[$engineKey] = $builder;
    }

    /**
     * Build an argv for `$engineKey`, using the override if registered or
     * defaultBuilder() otherwise. Throws when the engine is unknown or has
     * no ProcessSpec (e.g. superagent).
     *
     * @param array{prompt?:string, model?:?string, extra_args?:string[]} $options
     * @return string[]
     */
    public function build(string $engineKey, array $options = []): array
    {
        $engine = $this->catalog->get($engineKey);
        if (!$engine) {
            throw new \InvalidArgumentException("Unknown engine: {$engineKey}");
        }
        if (!$engine->isCli || $engine->processSpec === null) {
            throw new \InvalidArgumentException("Engine '{$engineKey}' has no ProcessSpec — not a CLI engine.");
        }

        $builder = $this->overrides[$engineKey] ?? $this->defaultBuilder();
        return $builder($engine, $options);
    }

    /**
     * Assemble argv from a ProcessSpec:
     *
     *   [binary, ...defaultFlags, (prompt), (--output-format=json), (--model X), ...extra_args]
     *
     * When promptFlag is null and a prompt is supplied, it's passed positionally
     * at the end (matches codex-style CLIs).
     *
     * @return callable(EngineDescriptor, array): string[]
     */
    public function defaultBuilder(): callable
    {
        return function (EngineDescriptor $engine, array $options): array {
            /** @var ProcessSpec $spec */
            $spec = $engine->processSpec;

            $cmd = [$spec->binary];
            foreach ($spec->defaultFlags as $flag) {
                $cmd[] = $flag;
            }

            $prompt = $options['prompt'] ?? null;
            $positionalPrompt = null;
            if ($prompt !== null && $prompt !== '') {
                if ($spec->promptFlag !== null) {
                    $cmd[] = $spec->promptFlag;
                    $cmd[] = $prompt;
                } else {
                    $positionalPrompt = $prompt;
                }
            }

            if ($spec->outputFormatFlag !== null) {
                $cmd[] = $spec->outputFormatFlag;
            }

            $model = $options['model'] ?? null;
            if ($model !== null && $model !== '' && $spec->modelFlag !== null) {
                // Copilot CLI uses dot-separated model IDs (claude-sonnet-4.6,
                // gpt-5.1) and rejects Claude-CLI's dash format outright — the
                // resolver translates dashes → dots and falls back to the
                // family default when a requested version isn't routable yet.
                if ($engine->key === 'copilot' && class_exists(CopilotModelResolver::class)) {
                    $model = CopilotModelResolver::resolve($model) ?? $model;
                }
                $cmd[] = $spec->modelFlag;
                $cmd[] = $model;
            }

            foreach ($options['extra_args'] ?? [] as $arg) {
                $cmd[] = (string) $arg;
            }

            if ($positionalPrompt !== null) {
                $cmd[] = $positionalPrompt;
            }

            return $cmd;
        };
    }

    /**
     * Argv for `<binary> <versionArgs>` — useful for CliStatusDetector.
     *
     * @return string[]
     */
    public function versionCommand(string $engineKey): array
    {
        $engine = $this->catalog->get($engineKey);
        if (!$engine || !$engine->processSpec) return [];
        return array_merge([$engine->processSpec->binary], $engine->processSpec->versionArgs);
    }

    /**
     * Argv for the engine's auth-status probe, or [] when the CLI has none.
     *
     * @return string[]
     */
    public function authStatusCommand(string $engineKey): array
    {
        $engine = $this->catalog->get($engineKey);
        if (!$engine || !$engine->processSpec || $engine->processSpec->authStatusArgs === null) {
            return [];
        }
        return array_merge([$engine->processSpec->binary], $engine->processSpec->authStatusArgs);
    }
}
