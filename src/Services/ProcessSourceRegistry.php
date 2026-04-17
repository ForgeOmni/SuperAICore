<?php

namespace SuperAICore\Services;

use SuperAICore\Contracts\ProcessSource;
use SuperAICore\Support\ProcessEntry;

/**
 * Collects ProcessSource instances contributed by the host app and any
 * registered modules. Resolved from the container as a singleton, so
 * ServiceProviders can do:
 *
 *     $this->app->make(ProcessSourceRegistry::class)->register(new MySource);
 */
class ProcessSourceRegistry
{
    /** @var ProcessSource[] */
    protected array $sources = [];

    public function register(ProcessSource $source): void
    {
        $this->sources[$source->key()] = $source;
    }

    /** @return ProcessSource[] */
    public function all(): array
    {
        return array_values($this->sources);
    }

    public function find(string $key): ?ProcessSource
    {
        return $this->sources[$key] ?? null;
    }

    /**
     * Aggregate ProcessEntry rows from every registered source, passing a
     * shared OS-process scan so sources don't re-shell each time.
     *
     * @return ProcessEntry[]
     */
    public function collect(array $systemProcesses): array
    {
        $entries = [];
        foreach ($this->sources as $source) {
            foreach ($source->list($systemProcesses) as $entry) {
                if ($entry instanceof ProcessEntry) $entries[] = $entry;
            }
        }
        return $entries;
    }
}
