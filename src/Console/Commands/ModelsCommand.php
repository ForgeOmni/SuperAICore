<?php

namespace SuperAICore\Console\Commands;

use SuperAgent\Providers\ModelCatalog;
use SuperAgent\Providers\ModelCatalogRefresher;
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
 *   update [--url <u>]      fetch the remote bundled catalog to ~/.superagent/models.json
 *   refresh [--provider <p>] pull each provider's live GET /models endpoint into
 *                            the per-provider overlay cache at
 *                            ~/.superagent/models-cache/<provider>.json
 *                            (requires the provider's API key in env).
 *                            Omit --provider to refresh every configured provider.
 *   status                  show source provenance + override mtime
 *   reset                   delete the user override
 *
 * Pricing and family aliases flow automatically into CostCalculator and the
 * per-engine ModelResolvers once `models update` or `models refresh` has run —
 * no SuperAICore config publish required.
 */
#[AsCommand(
    name: 'super-ai-core:models',
    description: 'Manage the SuperAgent model catalog (list / update / refresh / status / reset)'
)]
final class ModelsCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::OPTIONAL, 'list | update | refresh | status | reset', 'list');
        $this->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Filter `list` / scope `refresh` by provider key (anthropic|openai|gemini|kimi|qwen|glm|minimax|openrouter)');
        $this->addOption('url', null, InputOption::VALUE_REQUIRED, 'Override SUPERAGENT_MODELS_URL for a one-shot `update`');
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip the confirmation prompt on `reset`');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!class_exists(ModelCatalog::class)) {
            $output->writeln('<error>SuperAgent\\Providers\\ModelCatalog not found. Is forgeomni/superagent ^0.9.0 installed?</error>');
            return Command::FAILURE;
        }

        $action = (string) $input->getArgument('action');
        return match ($action) {
            'list'    => $this->runList($input, $output),
            'update'  => $this->runUpdate($input, $output),
            'refresh' => $this->runRefresh($input, $output),
            'status'  => $this->runStatus($output),
            'reset'   => $this->runReset($input, $output),
            default   => $this->unknownAction($action, $output),
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

    /**
     * Pull each provider's live `GET /models` endpoint into the per-provider
     * overlay cache. This is the SDK 0.9.0 `ModelCatalogRefresher` path — it
     * overlays on top of the user-override catalog without replacing it, so
     * bundled pricing is preserved when the vendor's `/models` response omits
     * rates (which it usually does).
     */
    private function runRefresh(InputInterface $input, OutputInterface $output): int
    {
        if (!class_exists(ModelCatalogRefresher::class)) {
            $output->writeln('<error>ModelCatalogRefresher requires forgeomni/superagent ^0.9.0.</error>');
            return Command::FAILURE;
        }

        $provider = $input->getOption('provider');

        if ($provider !== null && $provider !== '') {
            try {
                $rows = ModelCatalogRefresher::refresh((string) $provider);
                $output->writeln("<info>Refreshed:</info> {$provider} — " . count($rows) . ' models cached at ' . ModelCatalogRefresher::cachePath((string) $provider));
                return Command::SUCCESS;
            } catch (\Throwable $e) {
                $output->writeln("<error>Refresh failed:</error> {$provider}: {$e->getMessage()}");
                $output->writeln('<comment>Hint:</comment> set the provider API key env var (e.g. KIMI_API_KEY) before running.');
                return Command::FAILURE;
            }
        }

        $results = ModelCatalogRefresher::refreshAll();
        $table = new Table($output);
        $table->setHeaders(['Provider', 'Status', 'Details']);
        $anyOk = false;
        foreach ($results as $p => $r) {
            $ok = (bool) ($r['ok'] ?? false);
            $anyOk = $anyOk || $ok;
            $table->addRow([
                $p,
                $ok ? '<info>ok</info>' : '<comment>skipped</comment>',
                $ok
                    ? ((int) ($r['count'] ?? 0)) . ' models'
                    : (string) ($r['error'] ?? 'unknown'),
            ]);
        }
        $table->render();
        $output->writeln('');
        $output->writeln($anyOk
            ? '<info>Cached overlays written under ' . ModelCatalogRefresher::cacheDir() . '</info>'
            : '<comment>Nothing refreshed — set provider API keys in env and retry.</comment>');

        return $anyOk ? Command::SUCCESS : Command::FAILURE;
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
        if (class_exists(ModelCatalogRefresher::class)) {
            $cacheDir = ModelCatalogRefresher::cacheDir();
            $cached = [];
            if (is_dir($cacheDir)) {
                foreach (glob(rtrim($cacheDir, '/') . '/*.json') ?: [] as $f) {
                    $cached[] = basename($f, '.json');
                }
            }
            $table->addRow([
                'refresh cache',
                $cacheDir,
                $cached === [] ? '<comment>empty</comment>' : '<info>' . implode(', ', $cached) . '</info>',
            ]);
        }
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
        $output->writeln('<comment>Valid actions:</comment> list, update, refresh, status, reset');
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
