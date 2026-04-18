<?php

namespace SuperAICore\Console\Commands;

use SuperAICore\Registry\Skill;
use SuperAICore\Registry\SkillArguments;
use SuperAICore\Registry\SkillRegistry;
use SuperAICore\Runner\ClaudeSkillRunner;
use SuperAICore\Runner\CodexSkillRunner;
use SuperAICore\Runner\CompatibilityProbe;
use SuperAICore\Runner\CopilotSkillRunner;
use SuperAICore\Runner\FallbackChain;
use SuperAICore\Runner\GeminiSkillRunner;
use SuperAICore\Runner\SkillRunner;
use SuperAICore\Services\CapabilityRegistry;
use SuperAICore\Translator\SkillBodyTranslator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'skill:run',
    description: 'Run a named Claude skill against a backend CLI'
)]
final class SkillRunCommand extends Command
{
    /** @param array<string,SkillRunner>|null $runners keyed by backend key */
    public function __construct(
        private readonly ?SkillRegistry $registry = null,
        private readonly ?CapabilityRegistry $capabilities = null,
        private readonly ?array $runners = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Skill name')
            ->addArgument('args', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Free-form args appended to the skill prompt')
            ->addOption('backend', 'b', InputOption::VALUE_REQUIRED, 'Target backend: claude|codex|gemini|copilot|superagent', 'claude')
            ->addOption('exec', null, InputOption::VALUE_REQUIRED, 'Execution mode: claude|native|fallback', 'claude')
            ->addOption('fallback-chain', null, InputOption::VALUE_REQUIRED, 'Comma-separated backend chain for --exec=fallback (default: <backend>,claude)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the resolved command without executing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name    = (string) $input->getArgument('name');
        $args    = (array)  $input->getArgument('args');
        $exec    = (string) $input->getOption('exec');
        $backend = (string) $input->getOption('backend');
        $dryRun  = (bool)   $input->getOption('dry-run');

        $registry = $this->registry ?? new SkillRegistry();
        $skill = $registry->get($name);
        if (!$skill) {
            $output->writeln('<error>Skill not found: ' . $name . '</error>');
            return Command::FAILURE;
        }

        $schema = SkillArguments::fromFrontmatter($skill->frontmatter);
        if ($schema !== null) {
            if ($err = $schema->validate($args)) {
                $output->writeln('<error>[args] ' . $err . '</error>');
                return Command::FAILURE;
            }
            $renderedArgs = $schema->render($args);
        } else {
            $renderedArgs = SkillArguments::renderFreeform($args);
        }

        return match ($exec) {
            'claude'   => $this->runClaude($skill, $renderedArgs, $dryRun, $output),
            'native'   => $this->runNative($skill, $backend, $renderedArgs, $dryRun, $output),
            'fallback' => $this->runFallback($skill, $backend, (string) $input->getOption('fallback-chain'), $renderedArgs, $dryRun, $output),
            default    => $this->rejectUnknown($exec, $output),
        };
    }

    private function runFallback(Skill $skill, string $backend, string $chainSpec, string $renderedArgs, bool $dryRun, OutputInterface $output): int
    {
        $chain = $this->resolveChain($chainSpec, $backend);
        if (!$chain) {
            $output->writeln('<error>[fallback] no backends in chain</error>');
            return Command::FAILURE;
        }

        $caps = $this->capabilities ?? new CapabilityRegistry();
        $runnerFactory = function (string $b, \Closure $writer): ?SkillRunner {
            if ($this->runners !== null && array_key_exists($b, $this->runners)) {
                return $this->runners[$b];
            }
            return match ($b) {
                'claude'  => new ClaudeSkillRunner(writer: $writer),
                'codex'   => new CodexSkillRunner(writer: $writer),
                'gemini'  => new GeminiSkillRunner(writer: $writer),
                'copilot' => new CopilotSkillRunner(writer: $writer),
                default   => null,
            };
        };

        $fc = new FallbackChain($caps, $runnerFactory, getcwd() ?: '.');
        return $fc->run($skill, $chain, $renderedArgs, $dryRun, $output);
    }

    /** @return string[] */
    private function resolveChain(string $raw, string $backend): array
    {
        if ($raw !== '') {
            $parts = array_map('trim', explode(',', $raw));
            $parts = array_values(array_filter($parts, fn($p) => $p !== ''));
            return array_values(array_unique($parts));
        }
        return $backend === 'claude' ? ['claude'] : [$backend, 'claude'];
    }

    private function runClaude(Skill $skill, string $renderedArgs, bool $dryRun, OutputInterface $output): int
    {
        $runner = $this->runners['claude'] ?? new ClaudeSkillRunner(
            writer: fn(string $chunk) => $output->write($chunk)
        );
        $final = $renderedArgs === '' ? $skill : $this->withBody($skill, $skill->body . $renderedArgs);
        return $runner->runSkill($final, [], $dryRun);
    }

    private function runNative(Skill $skill, string $backend, string $renderedArgs, bool $dryRun, OutputInterface $output): int
    {
        $cap = ($this->capabilities ?? new CapabilityRegistry())->for($backend);

        $verdict = (new CompatibilityProbe($cap))->probe($skill);
        if ($verdict['status'] !== CompatibilityProbe::COMPATIBLE) {
            $output->writeln(sprintf('<comment>[probe] %s on %s:</comment>', $verdict['status'], $backend));
            foreach ($verdict['reasons'] as $r) {
                $output->writeln('  - ' . $r);
            }
            if ($verdict['status'] === CompatibilityProbe::INCOMPATIBLE) {
                $output->writeln('<comment>[probe] proceeding anyway; native mode is best-effort.</comment>');
            }
        }

        $translation = (new SkillBodyTranslator($cap))->translate($skill);
        if ($translation['translated']) {
            $pairs = [];
            foreach ($translation['translated'] as $from => $to) {
                $pairs[] = "{$from}→{$to}";
            }
            $output->writeln('<comment>[translate] mapped ' . count($pairs) . ' tool(s): ' . implode(', ', $pairs) . '</comment>');
        }
        if ($translation['untranslated']) {
            $output->writeln('<comment>[translate] ' . count($translation['untranslated']) . ' tool(s) without mapping: ' . implode(', ', $translation['untranslated']) . '</comment>');
        }

        $rewritten = $this->withBody($skill, $translation['body'] . $renderedArgs);

        $runner = $this->runners[$backend] ?? $this->defaultRunnerFor($backend, $output);
        if (!$runner) {
            $output->writeln('<error>No native runner available for backend: ' . $backend . '</error>');
            return Command::FAILURE;
        }

        return $runner->runSkill($rewritten, [], $dryRun);
    }

    private function withBody(Skill $skill, string $body): Skill
    {
        return new Skill(
            name: $skill->name,
            description: $skill->description,
            source: $skill->source,
            body: $body,
            path: $skill->path,
            allowedTools: $skill->allowedTools,
            frontmatter: $skill->frontmatter,
        );
    }

    private function defaultRunnerFor(string $backend, OutputInterface $output): ?SkillRunner
    {
        $writer = fn(string $chunk) => $output->write($chunk);
        return match ($backend) {
            'claude'  => new ClaudeSkillRunner(writer: $writer),
            'codex'   => new CodexSkillRunner(writer: $writer),
            'gemini'  => new GeminiSkillRunner(writer: $writer),
            'copilot' => new CopilotSkillRunner(writer: $writer),
            default   => null,
        };
    }

    private function rejectUnknown(string $exec, OutputInterface $output): int
    {
        $output->writeln('<error>Unknown --exec value: ' . $exec . '</error>');
        return Command::FAILURE;
    }
}
