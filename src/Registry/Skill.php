<?php

namespace SuperAICore\Registry;

/**
 * A Claude Code skill: directory with SKILL.md + frontmatter + body.
 */
final class Skill
{
    /**
     * @param string[]            $allowedTools
     * @param array<string,mixed> $frontmatter
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $description,
        public readonly string $source,
        public readonly string $body,
        public readonly string $path,
        public readonly array $allowedTools = [],
        public readonly array $frontmatter = [],
    ) {}

    public static function SOURCE_PROJECT(): string { return 'project'; }
    public static function SOURCE_PLUGIN(): string { return 'plugin'; }
    public static function SOURCE_USER(): string { return 'user'; }
}
