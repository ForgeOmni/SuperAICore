<?php

declare(strict_types=1);

namespace SuperAICore\Console\Commands;

use SuperAICore\SmartFlow\FlowEngine;
use SuperAICore\SmartFlow\FlowOptions;
use SuperAICore\SmartFlow\FlowRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `superaicore flow` — SmartFlow: cross-CLI dynamic workflows.
 *
 * The multi-CLI port of Claude Code's built-in `Workflow`. One set of primitives
 * (agent / parallel / pipeline / gate / council / budget / schema) drives any
 * registered backend, so a single flow can route its planner to Claude CLI and
 * its reviewers to Codex / Gemini CLI. Static flows ship as YAML under
 * resources/flows; `--rehearse` runs any flow end-to-end at zero cost without a
 * CLI installed.
 *
 *   superaicore flow list
 *   superaicore flow show cross-cli-review
 *   superaicore flow run cross-cli-review --args diff=@diff.txt --rehearse
 *   superaicore flow run cross-cli-dev --args goal="add caching" --concurrency 4
 *   superaicore flow run cross-cli-dev --resume <runId>
 */
#[AsCommand(name: 'flow', description: 'SmartFlow — run cross-CLI dynamic workflows (collaborate across Claude/Codex/Gemini/… CLIs)')]
final class FlowCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setHelp(
                "Run cross-CLI SmartFlow workflows.\n\n"
                . "Actions:\n"
                . "  list                list available flows (default)\n"
                . "  show <name>         show a flow's metadata\n"
                . "  plan <name>         show the plan without running any CLI\n"
                . "  run <name>          execute a flow\n\n"
                . "Rehearse any flow at zero cost with --rehearse."
            )
            ->addArgument('action', InputArgument::OPTIONAL, 'list | show | plan | run', 'list')
            ->addArgument('name', InputArgument::OPTIONAL, 'Flow name (for show/plan/run)')
            ->addOption('args', 'a', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Flow arg key=value (repeatable; value @file reads a file)')
            ->addOption('json', null, InputOption::VALUE_REQUIRED, 'Flow args as a JSON object')
            ->addOption('rehearse', null, InputOption::VALUE_NONE, 'Deterministic zero-cost run (no CLI invoked)')
            ->addOption('fake', null, InputOption::VALUE_NONE, 'Alias for --rehearse')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Rehearse without writing a ledger file')
            ->addOption('resume', null, InputOption::VALUE_REQUIRED, 'Replay the unchanged prefix of a prior run id')
            ->addOption('concurrency', null, InputOption::VALUE_REQUIRED, 'Max parallel CLI workers')
            ->addOption('budget-usd', null, InputOption::VALUE_REQUIRED, 'Hard USD ceiling for the run')
            ->addOption('backend', 'b', InputOption::VALUE_REQUIRED, 'Default backend (CLI) for calls without one')
            ->addOption('model', 'm', InputOption::VALUE_REQUIRED, 'Default model for calls without one')
            ->addOption('out-json', null, InputOption::VALUE_NONE, 'Print the full result as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = strtolower((string) $input->getArgument('action'));
        $name = (string) ($input->getArgument('name') ?? '');

        // Allow `flow <name>` shorthand when the first token is a known flow.
        if (!in_array($action, ['list', 'ls', 'show', 'plan', 'run', ''], true)) {
            $name = $action;
            $action = 'show';
        }

        return match ($action) {
            '', 'list', 'ls' => $this->list($output),
            'show' => $this->show($output, $name),
            'plan' => $this->show($output, $name),
            'run' => $this->runFlow($input, $output, $name),
            default => $this->list($output),
        };
    }

    private function registry(): FlowRegistry
    {
        if (function_exists('app')) {
            try {
                return app(FlowRegistry::class);
            } catch (\Throwable) {
                // fall through
            }
        }
        return new FlowRegistry();
    }

    private function engine(): FlowEngine
    {
        if (function_exists('app')) {
            try {
                return app(FlowEngine::class);
            } catch (\Throwable) {
                // fall through
            }
        }
        return new FlowEngine();
    }

    private function list(OutputInterface $output): int
    {
        $flows = $this->registry()->list();
        if ($flows === []) {
            $output->writeln('<comment>No flows found.</comment>');
            $output->writeln('Built-in flows live in resources/flows/*.yaml; add your own under ./flows or ./.superaicore/flows.');
            return self::SUCCESS;
        }

        $output->writeln(sprintf('<info>SmartFlow — %d flow%s available</info>', count($flows), count($flows) === 1 ? '' : 's'));
        $output->writeln('');
        $width = max(array_map('strlen', array_keys($flows)));
        foreach ($flows as $fname => $meta) {
            $output->writeln(sprintf('  <comment>%-' . $width . 's</comment>  %s', $fname, $this->firstLine($meta['description'])));
        }
        $output->writeln('');
        $output->writeln('<info>Run:</info>      superaicore flow run <name> --args key=value');
        $output->writeln('<info>Rehearse:</info> superaicore flow run <name> --rehearse');
        return self::SUCCESS;
    }

    private function show(OutputInterface $output, string $name): int
    {
        if ($name === '') {
            $output->writeln('<error>Usage: superaicore flow show <name></error>');
            return self::INVALID;
        }
        $registry = $this->registry();
        if (!$registry->has($name)) {
            $output->writeln("<error>Flow '{$name}' not found.</error> Try: superaicore flow list");
            return self::INVALID;
        }
        $def = $registry->get($name);
        if ($def === null) {
            $output->writeln("<error>Flow '{$name}' could not be loaded.</error>");
            return self::FAILURE;
        }

        $output->writeln("<info>Flow:</info> {$def->name}");
        $output->writeln(trim($def->description));
        if ($def->phases !== []) {
            $output->writeln('');
            $output->writeln('<info>Phases:</info>');
            foreach ($def->phases as $p) {
                $output->writeln('  • ' . ($p['title'] ?? ''));
            }
        }
        if ($def->defaults !== []) {
            $output->writeln('');
            $output->writeln('<info>Defaults:</info> ' . json_encode($def->defaults, JSON_UNESCAPED_SLASHES));
        }
        if ($def->source !== null && $def->source !== 'php') {
            $output->writeln('');
            $output->writeln('<comment>Source: ' . $def->source . '</comment>');
        }
        return self::SUCCESS;
    }

    private function runFlow(InputInterface $input, OutputInterface $output, string $name): int
    {
        if ($name === '') {
            $output->writeln('<error>Usage: superaicore flow run <name> [--args k=v] [--rehearse]</error>');
            return self::INVALID;
        }

        $registry = $this->registry();
        $def = $registry->get($name);
        if ($def === null) {
            $output->writeln("<error>Flow '{$name}' not found.</error> Try: superaicore flow list");
            return self::INVALID;
        }

        $flowArgs = $this->collectArgs($input);

        $opts = new FlowOptions();
        $opts->rehearse = (bool) $input->getOption('rehearse') || (bool) $input->getOption('fake');
        $opts->dryRun = (bool) $input->getOption('dry-run');
        if ($r = $input->getOption('resume')) {
            $opts->resumeRunId = (string) $r;
        }
        if ($c = $input->getOption('concurrency')) {
            $opts->concurrency = (int) $c;
        }
        if (($b = $input->getOption('budget-usd')) !== null) {
            $opts->budgetUsd = (float) $b;
        }
        if ($backend = $input->getOption('backend')) {
            $opts->defaultBackend = (string) $backend;
        }
        if ($model = $input->getOption('model')) {
            $opts->defaultModel = (string) $model;
        }

        $result = $this->engine()->run($def, $flowArgs, $opts);

        if ($input->getOption('out-json')) {
            $output->writeln(json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return $result->isSuccessful() ? self::SUCCESS : self::FAILURE;
        }

        $tag = $result->fake ? ' [rehearsal]' : '';
        if ($result->isSuccessful()) {
            $output->writeln("<info>Flow '{$name}' completed{$tag}</info>");
        } else {
            $output->writeln("<error>Flow '{$name}' failed: " . ($result->error ?? 'unknown') . '</error>');
        }

        $l = $result->ledger;
        $output->writeln('');
        $output->writeln(sprintf(
            '  calls: %d (cached %d, skips %d, gates %d)   cost: $%.4f   tokens: %d in / %d out',
            $l['calls'] ?? 0,
            $l['cached_calls'] ?? 0,
            $l['skips'] ?? 0,
            $l['gates'] ?? 0,
            $l['cost_usd'] ?? 0,
            $l['input_tokens'] ?? 0,
            $l['output_tokens'] ?? 0,
        ));
        if (!empty($l['layers'])) {
            $output->writeln('  layers: ' . json_encode($l['layers'], JSON_UNESCAPED_SLASHES));
        }
        $output->writeln('  run id: ' . $result->runId);
        if ($result->ledgerPath !== null) {
            $output->writeln('  <comment>ledger: ' . $result->ledgerPath . '   (resume with --resume ' . $result->runId . ')</comment>');
        }

        return $result->isSuccessful() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function collectArgs(InputInterface $input): array
    {
        $flowArgs = [];

        if ($json = $input->getOption('json')) {
            $decoded = json_decode((string) $json, true);
            if (is_array($decoded)) {
                $flowArgs = $decoded;
            }
        }

        foreach ((array) $input->getOption('args') as $pair) {
            $eq = strpos((string) $pair, '=');
            if ($eq === false) {
                continue;
            }
            $key = substr((string) $pair, 0, $eq);
            $value = substr((string) $pair, $eq + 1);
            // value of the form @path reads the file contents.
            if (str_starts_with($value, '@') && is_file(substr($value, 1))) {
                $value = (string) file_get_contents(substr($value, 1));
            }
            $flowArgs[$key] = $value;
        }

        return $flowArgs;
    }

    private function firstLine(string $text): string
    {
        $line = trim(strtok($text, "\n") ?: '');
        return mb_strlen($line) > 80 ? mb_substr($line, 0, 77) . '...' : $line;
    }
}
