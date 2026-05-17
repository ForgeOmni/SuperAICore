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
 * `superaicore squad "<task>"` — passthrough to SDK 1.0.0's
 * `superagent auto --squad` orchestration. Forces the Squad mode
 * (peer-collaboration, cross-model dispatch, per-step checkpointing)
 * over the legacy master-slave path even when the heuristic wouldn't
 * pick it on its own.
 *
 * Flags forwarded:
 *   --max-cost <usd>     budget cap with downshift at 80%
 *   --no-squad           revert to legacy path (passthrough only —
 *                        users can mix this in for A/B comparisons)
 *
 * Same rationale as `smart`: don't fork the SDK CLI inside the host.
 */
#[AsCommand(name: 'squad', description: 'Run SuperAgent squad multi-agent (passthrough to vendor superagent auto --squad)')]
final class SquadCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setHelp('Wraps `superagent auto --squad`. Pass arguments after `squad` as you would to `superagent auto`.')
            ->addArgument('args', InputArgument::IS_ARRAY, 'Arguments forwarded to `superagent auto`')
            ->addOption('binary', null, InputOption::VALUE_REQUIRED, 'Path to the `superagent` binary')
            ->addOption('no-squad', null, InputOption::VALUE_NONE, 'Forward `--no-squad` to the vendor CLI for A/B comparison');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $binary = (string) ($input->getOption('binary') ?? $this->locateSuperagentBinary());
        if ($binary === '') {
            $output->writeln('<error>Could not locate the `superagent` binary. Pass --binary=… or run `composer install`.</error>');
            return self::FAILURE;
        }

        $args = (array) $input->getArgument('args');
        $modeFlag = $input->getOption('no-squad') ? '--no-squad' : '--squad';
        $cmd = array_merge([(new PhpExecutableFinder())->find() ?: 'php', $binary, 'auto', $modeFlag], $args);

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
