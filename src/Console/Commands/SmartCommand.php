<?php

declare(strict_types=1);

namespace SuperAICore\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * `superaicore smart "<task>"` — passthrough to SDK 0.9.9's
 * `superagent smart` orchestration. Reuses the operator's existing
 * SuperAgent credentials / config without forking the SDK's own
 * argv parser into our Symfony Console.
 *
 * Subcommands recognised (forwarded verbatim):
 *   - `smart "<task>"`            run the orchestrator
 *   - `smart show <id|--last>`    print a persisted run summary
 *   - `smart replay <id>` …       re-execute a persisted plan
 *
 * Flags forwarded to the SDK:
 *   --brain X            brain model id
 *   --max-cost N         abort if cost crosses cap
 *   --max-parallel N     sliding-window fan-out cap
 *   --json               machine-readable stdout
 *
 * We deliberately don't re-implement the orchestrator in PHP — the SDK
 * already drives it directly and the binding is just `exec`. This
 * keeps host CLIs from drifting out of sync with SDK CLI behaviour.
 */
#[AsCommand(name: 'smart', description: 'Run SuperAgent smart orchestration (passthrough to vendor superagent CLI)')]
final class SmartCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setHelp('Wraps `superagent smart`. Pass arguments after `smart` exactly as you would to `vendor/bin/superagent smart`.')
            ->addArgument('args', InputArgument::IS_ARRAY, 'Arguments forwarded to `superagent smart`')
            ->addOption('binary', null, InputOption::VALUE_REQUIRED, 'Path to the `superagent` binary (auto-discovered when omitted)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $binary = (string) ($input->getOption('binary') ?? $this->locateSuperagentBinary());
        if ($binary === '') {
            $output->writeln('<error>Could not locate the `superagent` binary. Pass --binary=… or run `composer install`.</error>');
            return self::FAILURE;
        }

        $args = (array) $input->getArgument('args');
        $cmd = array_merge([(new PhpExecutableFinder())->find() ?: 'php', $binary, 'smart'], $args);

        $proc = new Process($cmd);
        $proc->setTimeout(null);
        $proc->setTty(Process::isTtySupported());
        $proc->run(function ($type, $buffer) use ($output) {
            $output->write($buffer);
        });
        return $proc->getExitCode() ?? self::FAILURE;
    }

    private function locateSuperagentBinary(): string
    {
        $candidates = [
            __DIR__ . '/../../../vendor/forgeomni/superagent/bin/superagent',
            __DIR__ . '/../../../../../forgeomni/superagent/bin/superagent',
            __DIR__ . '/../../../../forgeomni/superagent/bin/superagent',
        ];
        foreach ($candidates as $c) {
            if (is_file($c)) return $c;
        }
        return '';
    }
}
