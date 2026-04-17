<?php

namespace SuperAICore\Runner;

use Closure;
use SuperAICore\Registry\Skill;
use SuperAICore\Services\CapabilityRegistry;
use SuperAICore\Translator\SkillBodyTranslator;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Walks an ordered chain of backends trying to run a skill, per DESIGN §5
 * D13–D16:
 *
 *   - Probe each hop; `incompatible` verdict plus "not last hop" = skip.
 *   - Run the current hop with a tee writer so we buffer the raw output
 *     while still streaming it to the user.
 *   - After the run, ask `SideEffectDetector` if the hop touched the cwd
 *     or emitted stream-json `tool_use` events for mutating tools. If
 *     yes, **lock** on this hop — return its exit code regardless, do
 *     not try the next one.
 *   - No side-effect + success (exit 0) → return 0.
 *   - No side-effect + failure → log and try the next hop; if this was
 *     the last hop, return the failure exit code.
 *
 * The `runnerFactory` callable is `fn(string $backend, Closure $writer): ?SkillRunner`.
 * Returning null means "no runner available for this backend"; the chain
 * skips it with a warning.
 */
final class FallbackChain
{
    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly Closure $runnerFactory,
        private readonly string $cwd,
    ) {}

    /**
     * @param string[] $chain         backend keys, in order
     * @param string   $renderedArgs  pre-rendered `<args>...</args>` XML block
     *                                (empty string when the caller passed
     *                                no args or no schema demanded any)
     */
    public function run(
        Skill $skill,
        array $chain,
        string $renderedArgs,
        bool $dryRun,
        OutputInterface $output,
    ): int {
        if (!$chain) {
            $output->writeln('<error>[fallback] empty chain — nothing to run</error>');
            return 1;
        }

        $last = count($chain) - 1;
        $lastExit = 1;
        $prev = null;

        foreach ($chain as $i => $backend) {
            $cap = $this->capabilities->for($backend);
            $verdict = (new CompatibilityProbe($cap))->probe($skill);

            if ($verdict['status'] === CompatibilityProbe::INCOMPATIBLE && $i !== $last) {
                $reason = $verdict['reasons'][0] ?? 'incompatible';
                $arrow = $prev === null ? $backend : "{$prev} → {$backend}";
                $output->writeln("<comment>[fallback] {$arrow}: incompatible ({$reason})</comment>");
                $prev = $backend;
                continue;
            }

            if ($verdict['status'] !== CompatibilityProbe::COMPATIBLE) {
                $output->writeln(sprintf('<comment>[fallback] %s: %s — proceeding (%s)</comment>',
                    $backend, $verdict['status'], $verdict['reasons'][0] ?? ''));
            }

            $translation = (new SkillBodyTranslator($cap))->translate($skill);
            $translated = new Skill(
                name: $skill->name,
                description: $skill->description,
                source: $skill->source,
                body: $translation['body'] . $renderedArgs,
                path: $skill->path,
                allowedTools: $skill->allowedTools,
                frontmatter: $skill->frontmatter,
            );

            $buffer = '';
            $writer = function (string $chunk) use (&$buffer, $output): void {
                $buffer .= $chunk;
                $output->write($chunk);
            };

            $runner = ($this->runnerFactory)($backend, $writer);
            if (!$runner) {
                $output->writeln("<comment>[fallback] no runner available for {$backend}, skipping</comment>");
                $prev = $backend;
                continue;
            }

            $detector = null;
            if (!$dryRun) {
                $detector = new SideEffectDetector($this->cwd);
                $detector->snapshotBefore();
            }

            $output->writeln("<comment>[fallback] running on {$backend}</comment>");
            $exit = $runner->runSkill($translated, [], $dryRun);
            $lastExit = $exit;

            if ($detector !== null) {
                $effects = $detector->detectAfter($buffer);
                if ($effects['detected']) {
                    $output->writeln("<comment>[fallback] locked on {$backend}: side-effect detected</comment>");
                    foreach ($effects['reasons'] as $r) {
                        $output->writeln('  - ' . $r);
                    }
                    return $exit;
                }
            }

            if ($exit === 0) {
                return 0;
            }

            if ($i !== $last) {
                $output->writeln("<comment>[fallback] {$backend} failed (exit {$exit}), no side-effects — trying next hop</comment>");
            }

            $prev = $backend;
        }

        return $lastExit;
    }
}
