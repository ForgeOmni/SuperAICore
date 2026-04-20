<?php

namespace SuperAICore\Console\Commands;

use SuperAgent\Providers\ModelCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Host-side wrapper around SuperAgent's ModelCatalog.
 *
 * Subcommands:
 *   list [--provider <p>]   show the merged (bundled + user override) catalog
 *   update [--url <u>]      fetch the remote catalog to ~/.superagent/models.json
 *   status                  show source provenance + override mtime
 *   reset                   delete the user override
 *
 * Pricing and family aliases flow automatically into CostCalculator and the
 * per-engine ModelResolvers once `models update` has run — no SuperAICore
 * config publish required.
 */
#[AsCommand(
    name: 'super-ai-core:models',
    description: 'Manage the SuperAgent model catalog (list / update / status / reset)'
)]
final class ModelsCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::OPTIONAL, 'list | update | status | reset', 'list');
        $this->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Filter `list` by provider key (anthropic|openai|gemini|…)');
        $this->addOption('url', null, InputOption::VALUE_REQUIRED, 'Override SUPERAGENT_MODELS_URL for a one-shot `update`');
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip the confirmation prompt on `reset`');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!class_exists(ModelCatalog::class)) {
            $output->writeln('<error>SuperAgent\\Providers\\ModelCatalog not found. Is forgeomni/superagent ^0.8.7 installed?</error>');
            return Command::FAILURE;
        }

        $action = (string) $input->getArgument('action');
        return match ($action) {
            'list'   => $this->runList($input, $output),
            'update' => $this->runUpdate($input, $output),
            'status' => $this->runStatus($output),
            'reset'  => $this->runReset($input, $output),
            default  => $this->unknownAction($action, $output),
        };
    }

    private function runList(InputInterface $input, OutputInterface $output): int
    {
        $filter = $input->getOption('provider');
        $providers = $filter ? [(string) $filter] : ModelCatalog::providers();

        foreach ($providers as $provider) {
            $rows = ModelCatalog::modelsFor($provider);
            if (!$rows) {
                continue;
            }
            $output->writeln("<info>{$provider}</info>");
            $table = new Table($output);
            $table->setHeaders(['Model', 'Family', 'Input /1M', 'Output /1M', 'Aliases']);
            foreach ($rows as $m) {
                $table->addRow([
                    (string) ($m['id'] ?? '?'),
                    (string) ($m['family'] ?? '-'),
                    isset($m['input'])  ? '$' . number_format((float) $m['input'], 4)  : '-',
                    isset($m['output']) ? '$' . number_format((float) $m['output'], 4) : '-',
                    isset($m['aliases']) ? implode(', ', (array) $m['aliases']) : '',
                ]);
            }
            $table->render();
            $output->writeln('');
        }
        return Command::SUCCESS;
    }

    private function runUpdate(InputInterface $input, OutputInterface $output): int
    {
        $url = $input->getOption('url');
        try {
            $count = ModelCatalog::refreshFromRemote($url ? (string) $url : null);
            $output->writeln("<info>Updated:</info> {$count} models written to " . ModelCatalog::userOverridePath());
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln("<error>Update failed:</error> {$e->getMessage()}");
            $output->writeln('<comment>Hint:</comment> set SUPERAGENT_MODELS_URL or pass --url');
            return Command::FAILURE;
        }
    }

    private function runStatus(OutputInterface $output): int
    {
        $bundled  = ModelCatalog::bundledPath();
        $override = ModelCatalog::userOverridePath();
        $mtime    = ModelCatalog::userOverrideMtime();
        $remote   = ModelCatalog::remoteUrl();
        $stale    = ModelCatalog::isStale();

        $table = new Table($output);
        $table->setHeaders(['Source', 'Path / URL', 'Status']);
        $table->addRow(['bundled',  $bundled,  is_readable($bundled) ? '<info>loaded</info>' : '<error>missing</error>']);
        $table->addRow([
            'user override',
            $override,
            $mtime
                ? '<info>loaded</info> — updated ' . $this->formatAge(time() - $mtime) . ' ago' . ($stale ? ' <comment>(stale)</comment>' : '')
                : '<comment>not present</comment>',
        ]);
        $table->addRow(['remote URL', $remote ?? '(unset)', $remote ? 'configured' : '-']);
        $table->render();

        $output->writeln('');
        $output->writeln("<comment>Total models loaded:</comment> " . count(ModelCatalog::all()));
        return Command::SUCCESS;
    }

    private function runReset(InputInterface $input, OutputInterface $output): int
    {
        $path = ModelCatalog::userOverridePath();
        if (!file_exists($path)) {
            $output->writeln('<comment>Nothing to reset — user override does not exist.</comment>');
            return Command::SUCCESS;
        }
        if (!$input->getOption('yes')) {
            $helper = $this->getHelper('question');
            $question = new \Symfony\Component\Console\Question\ConfirmationQuestion(
                "Delete {$path}? [y/N] ", false
            );
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('Aborted.');
                return Command::SUCCESS;
            }
        }
        $ok = ModelCatalog::resetUserOverride();
        $output->writeln($ok ? '<info>User override removed.</info>' : '<error>Failed to remove user override.</error>');
        return $ok ? Command::SUCCESS : Command::FAILURE;
    }

    private function unknownAction(string $action, OutputInterface $output): int
    {
        $output->writeln("<error>Unknown action:</error> {$action}");
        $output->writeln('<comment>Valid actions:</comment> list, update, status, reset');
        return Command::FAILURE;
    }

    private function formatAge(int $seconds): string
    {
        if ($seconds < 60)     return "{$seconds}s";
        if ($seconds < 3600)   return (int) floor($seconds / 60) . 'm';
        if ($seconds < 86400)  return (int) floor($seconds / 3600) . 'h';
        return (int) floor($seconds / 86400) . 'd';
    }
}
