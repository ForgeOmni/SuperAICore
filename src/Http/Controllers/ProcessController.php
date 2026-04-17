<?php

namespace SuperAICore\Http\Controllers;

use SuperAICore\Services\ProcessMonitor;
use SuperAICore\Services\ProcessSourceRegistry;
use SuperAICore\Sources\AiProcessSource;
use SuperAICore\Support\ProcessEntry;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Process Monitor — aggregates rows from every registered ProcessSource
 * (built-in ai_processes + host-app contributors like TaskResult/Workflow)
 * and renders the two-pane list + log viewer.
 *
 * IDs in the URL follow the "{sourceKey}.{localId}" composite format —
 * see ProcessEntry::compose(). Legacy numeric IDs are treated as
 * aiprocess.<id> for backward compatibility.
 */
class ProcessController extends Controller
{
    public function __construct(
        protected ProcessSourceRegistry $sources,
    ) {}

    public function index()
    {
        $system = ProcessMonitor::getSystemProcesses();
        $processes = $this->sources->collect($system);

        usort($processes, function (ProcessEntry $a, ProcessEntry $b) {
            return ($b->started_at?->timestamp ?? 0) <=> ($a->started_at?->timestamp ?? 0);
        });

        return view('super-ai-core::processes.index', compact('processes'));
    }

    /**
     * Back-compat helper: host apps that called
     * POST /super-ai-core/processes/register still need a straight DB
     * insert into ai_processes.
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'pid' => 'required|integer',
            'backend' => 'required|string|max:30',
            'command' => 'nullable|string|max:500',
            'external_id' => 'nullable|string|max:120',
            'external_label' => 'nullable|string|max:255',
            'output_dir' => 'nullable|string|max:500',
            'log_file' => 'nullable|string|max:500',
            'metadata' => 'nullable|array',
        ]);
        $data['status'] = \SuperAICore\Models\AiProcess::STATUS_RUNNING;
        $data['started_at'] = now();
        if ($request->user()) $data['user_id'] = $request->user()->id;

        $proc = \SuperAICore\Models\AiProcess::create($data);
        return response()->json($proc, 201);
    }

    public function kill(Request $request)
    {
        [$sourceKey, $localId] = $this->splitId((string) $request->input('id'));
        $source = $this->sources->find($sourceKey);
        if (!$source) {
            return response()->json(['ok' => false, 'error' => 'unknown source'], 404);
        }

        $ok = $source->kill($localId);
        return response()->json(['ok' => $ok]);
    }

    /**
     * Tail a process's log. Route binds {process} as a plain string so that
     * composite IDs like "task_result.13" round-trip cleanly.
     */
    public function log(Request $request, string $process)
    {
        [$sourceKey, $localId] = $this->splitId($process);
        $source = $this->sources->find($sourceKey);
        if (!$source) {
            return response()->json(['ok' => false, 'text' => '', 'error' => 'unknown source']);
        }

        $path = $source->logFile($localId);
        $bytes = (int) $request->input('bytes', 65536);

        // Also try to derive the live pid from the entry list so the
        // "alive" badge works even when the source doesn't track it
        // separately on disk.
        $pid = null;
        foreach ($source->list(ProcessMonitor::getSystemProcesses()) as $entry) {
            if ($entry->id === ProcessEntry::compose($sourceKey, $localId)) {
                $pid = $entry->pid;
                break;
            }
        }

        return response()->json(ProcessMonitor::tailLog($path, $pid, $bytes));
    }

    protected function splitId(string $id): array
    {
        [$source, $local] = ProcessEntry::parseId($id);
        // Numeric-only = legacy ai_processes row
        if ($source === null && ctype_digit($id)) {
            return [AiProcessSource::KEY, $id];
        }
        return [$source, $local];
    }
}
