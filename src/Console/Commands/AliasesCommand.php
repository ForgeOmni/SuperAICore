<?php

namespace SuperAICore\Console\Commands;

use SuperAICore\Services\AliasRouter;
use SuperAICore\Services\BackendRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `superaicore aliases [target]` — show the alias → backend/model pool
 * that `send` routes through (built-ins merged with
 * `super-ai-core.dispatch.aliases`), or resolve one target the exact way
 * `send` would (ai-dispatch parity for `models resolve`).
 */
#[AsCommand(name: 'aliases', description: 'List dispatch aliases or resolve a single send target')]
class AliasesCommand extends Command
{
    public function __construct(
        protected ?BackendRegistry $backends = null,
        protected ?AliasRouter $router = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('target', InputArgument::OPTIONAL, 'Resolve this single target instead of listing everything')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output raw JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $backends = $this->backends ??= new BackendRegistry();
        $router = $this->router ??= new AliasRouter($backends);

        if ($target = $input->getArgument('target')) {
            $route = $router->resolve((string) $target);
            if ($input->getOption('json')) {
                $output->writeln(json_encode($route, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return Command::SUCCESS;
            }
            $output->writeln("target={$route['requested']} source={$route['source']}");
            foreach ($route['candidates'] as $i => $candidate) {
                $registered = $backends->get($candidate['backend']) !== null;
                $output->writeln(sprintf(
                    '  %d. %s%s%s',
                    $i + 1,
                    $candidate['backend'],
                    $candidate['model'] !== null ? ':' . $candidate['model'] : '',
                    $registered ? '' : '  <comment>(backend not registered)</comment>',
                ));
            }
            return Command::SUCCESS;
        }

        $all = $router->all();
        if ($input->getOption('json')) {
            $output->writeln(json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['alias', 'candidates (in order)', 'available']);
        foreach ($all as $alias => $candidates) {
            $chain = [];
            $available = true;
            foreach ($candidates as $candidate) {
                $chain[] = $candidate['backend'] . ($candidate['model'] !== null ? ':' . $candidate['model'] : '');
                $available = $available && $backends->get($candidate['backend']) !== null;
            }
            $table->addRow([$alias, implode(' → ', $chain), $available ? 'yes' : 'no']);
        }
        $table->render();
        return Command::SUCCESS;
    }
}
