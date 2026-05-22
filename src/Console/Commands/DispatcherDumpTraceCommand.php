<?php

declare(strict_types=1);

namespace SuperAICore\Console\Commands;

use SuperAICore\Tracing\TraceCollector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Flush the Dispatcher trace ring to a Chrome Trace Event JSON file.
 *
 * Use cases:
 *   - Long-running queue worker stuck on a slow LLM call — dump from a
 *     second shell to see what the dispatcher has been up to.
 *   - Post-incident: dispatcher ran fine but the operator wants a record
 *     of the last 1024 events for archival.
 *   - CI: dump before tearing down the test harness so failures carry the
 *     timeline as an artifact.
 *
 * The ring is NOT cleared after dump (multiple snapshots are useful when
 * tracking a moving target). Pass --clear if you want a fresh ring.
 */
#[AsCommand(
    name: 'dispatcher:dump-trace',
    description: 'Dump the Dispatcher trace ring to a Chrome Trace Event JSON file (open in chrome://tracing, ui.perfetto.dev, or the bundled trace-viewer.html).'
)]
final class DispatcherDumpTraceCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('reason', 'r', InputOption::VALUE_REQUIRED,
                'Free-text reason recorded on the dump metadata', 'manual flush')
            ->addOption('trigger', null, InputOption::VALUE_REQUIRED,
                'Trigger label stamped on the dump (manual|timeout|debug|…)', 'manual')
            ->addOption('clear', null, InputOption::VALUE_NONE,
                'Clear the ring buffer after writing (default: keep events so subsequent dumps still see them)')
            ->addOption('json', null, InputOption::VALUE_NONE,
                'Emit { path, event_count } as JSON instead of a human line');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tracer = TraceCollector::getInstance();
        if (!$tracer->isEnabled()) {
            $output->writeln('<comment>Tracing is disabled (super-ai-core.tracing.enabled = false). Nothing to dump.</comment>');
            return Command::SUCCESS;
        }

        $eventCount = $tracer->getRing()->count();
        if ($eventCount === 0) {
            $output->writeln('<comment>Ring is empty. Run something through the Dispatcher first.</comment>');
            return Command::SUCCESS;
        }

        $path = $tracer->dump(
            trigger: (string) $input->getOption('trigger'),
            reason: (string) $input->getOption('reason'),
        );

        if ($input->getOption('clear')) {
            $tracer->getRing()->clear();
        }

        if ($path === null) {
            $output->writeln('<error>Trace writer not configured — set super-ai-core.tracing.storage_path or wire a TraceWriter on the collector.</error>');
            return Command::FAILURE;
        }

        if ($input->getOption('json')) {
            $output->writeln(json_encode([
                'ok'          => true,
                'path'        => $path,
                'event_count' => $eventCount,
                'cleared'     => (bool) $input->getOption('clear'),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $output->writeln(sprintf(
                '<info>Trace dump written</info>: %s  (%d events%s)',
                $path,
                $eventCount,
                $input->getOption('clear') ? ', ring cleared' : '',
            ));
            $output->writeln('<comment>Open with</comment>: chrome://tracing, https://ui.perfetto.dev, or .claude/design-system/templates/trace-viewer.html');
        }

        return Command::SUCCESS;
    }
}
