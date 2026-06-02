<?php

declare(strict_types=1);

namespace SuperAICore\SmartFlow;

/**
 * A runnable flow: a name + description + optional phase metadata + a body.
 *
 * The body is `callable(Flow $flow): mixed` — the PHP fluent DSL. Static flows
 * authored in YAML are compiled by {@see YamlFlowLoader} into exactly this shape,
 * so the engine has a single execution path for both authoring styles.
 */
final class FlowDefinition
{
    /**
     * @param callable(Flow): mixed $body
     * @param list<array{title: string, detail?: string}> $phases
     * @param array<string, mixed> $defaults  per-flow overrides (backend/model/budget)
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly mixed $body,
        public readonly array $phases = [],
        public readonly array $defaults = [],
        public readonly ?string $source = null,
    ) {}

    /**
     * @param callable(Flow): mixed $body
     */
    public static function make(string $name, string $description, callable $body, array $phases = [], array $defaults = []): self
    {
        return new self($name, $description, $body, $phases, $defaults);
    }

    public function run(Flow $flow): mixed
    {
        return ($this->body)($flow);
    }
}
