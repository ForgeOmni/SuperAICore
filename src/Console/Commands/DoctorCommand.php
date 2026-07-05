<?php

namespace SuperAICore\Console\Commands;

use SuperAICore\Services\AliasRouter;
use SuperAICore\Services\BackendRegistry;
use SuperAICore\Services\CliStatusDetector;
use SuperAICore\Services\RunStore;
use SuperAICore\Support\DispatchPreferences;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `superaicore doctor` — one-stop environment diagnostic (ai-dispatch
 * parity). Aggregates what `cli:status` + `api:status` show separately,
 * plus the dispatch-layer checks the send/resume/preferences wave added:
 * registered backends, alias resolvability, preferences file, run store.
 *
 * Severity: fail = no backend can take a dispatch at all;
 * warn = an engine/alias/file is missing but something else still works.
 */
#[AsCommand(name: 'doctor', description: 'Diagnose CLI engines, backends, aliases, preferences, and the run store')]
class DoctorCommand extends Command
{
    public function __construct(
        protected ?BackendRegistry $backends = null,
        protected ?AliasRouter $router = null,
        protected ?RunStore $runs = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output raw JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $backends = $this->backends ??= new BackendRegistry();
        $router = $this->router ??= new AliasRouter($backends);
        $runs = $this->runs ??= new RunStore();

        $checks = [];

        // 1. Registered dispatcher backends.
        $names = $backends->names();
        $checks[] = [
            'check' => 'backends.registered',
            'status' => $names === [] ? 'fail' : 'ok',
            'detail' => $names === [] ? 'no backends registered' : implode(', ', $names),
        ];

        // 2. CLI engine binaries + auth (same probes as cli:status).
        $cliStatuses = [];
        try {
            $cliStatuses = CliStatusDetector::all();
        } catch (\Throwable $e) {
            $checks[] = ['check' => 'cli.detect', 'status' => 'warn', 'detail' => 'probe failed: ' . $e->getMessage()];
        }
        foreach ($cliStatuses as $engine => $status) {
            $installed = (bool) ($status['installed'] ?? false);
            $checks[] = [
                'check' => "cli.{$engine}",
                'status' => $installed ? 'ok' : 'warn',
                'detail' => $installed
                    ? trim(trim(strtok((string) ($status['version'] ?? ''), "\n")) . (isset($status['path']) ? " ({$status['path']})" : ''))
                        ?: 'installed'
                    : 'not installed',
            ];
        }

        // 3. Aliases — first candidate of every alias should hit a
        //    registered backend, otherwise `send <alias>` starts degraded.
        $broken = [];
        foreach ($router->all() as $alias => $candidates) {
            $first = $candidates[0]['backend'] ?? null;
            if ($first !== null && $backends->get($first) === null) {
                $broken[] = "{$alias}→{$first}";
            }
        }
        $checks[] = [
            'check' => 'dispatch.aliases',
            'status' => $broken === [] ? 'ok' : 'warn',
            'detail' => $broken === []
                ? count($router->all()) . ' aliases resolvable'
                : 'primary candidate unregistered: ' . implode(', ', $broken),
        ];

        // 4. Preferences file.
        $checks[] = [
            'check' => 'dispatch.preferences',
            'status' => DispatchPreferences::exists() ? 'ok' : 'warn',
            'detail' => DispatchPreferences::exists()
                ? DispatchPreferences::path()
                : 'missing — run `superaicore preferences init`',
        ];

        // 5. Run store writable.
        $probeId = $runs->record(['status' => 'doctor_probe']);
        if ($probeId !== null) {
            @unlink($runs->path() . '/' . $probeId . '.json');
        }
        $checks[] = [
            'check' => 'dispatch.runs_store',
            'status' => $probeId !== null ? 'ok' : 'warn',
            'detail' => $probeId !== null ? $runs->path() : 'not writable: ' . $runs->path(),
        ];

        $summary = ['ok' => 0, 'warn' => 0, 'fail' => 0];
        foreach ($checks as $check) {
            $summary[$check['status']]++;
        }

        if ($input->getOption('json')) {
            $output->writeln(json_encode(
                ['checks' => $checks, 'summary' => $summary],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));
        } else {
            foreach ($checks as $check) {
                $tag = match ($check['status']) {
                    'ok' => '<info>ok  </info>',
                    'warn' => '<comment>warn</comment>',
                    default => '<error>FAIL</error>',
                };
                $output->writeln(sprintf('%s  %-24s %s', $tag, $check['check'], $check['detail']));
            }
            $output->writeln('');
            $output->writeln(sprintf('%d ok, %d warn, %d fail', $summary['ok'], $summary['warn'], $summary['fail']));
        }

        return $summary['fail'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
