<?php

namespace SuperAICore\Registry;

/**
 * A Claude Code sub-agent: a single `.md` file under `.claude/agents/`
 * with frontmatter + body. The body is the agent's system prompt; the
 * caller supplies the task prompt at run time.
 */
final class Agent
{
    /**
     * @param string[] $allowedTools
     * @param array<string,mixed> $frontmatter
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $description,
        public readonly string $source,
        public readonly string $body,
        public readonly string $path,
        public readonly ?string $model = null,
        public readonly array $allowedTools = [],
        public readonly array $frontmatter = [],
    ) {}

    public static function SOURCE_PROJECT(): string { return 'project'; }
    public static function SOURCE_USER(): string    { return 'user'; }
}
