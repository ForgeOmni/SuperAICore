<?php

namespace SuperAICore\Http\Controllers;

use SuperAICore\Models\AiProcess;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\Process\Process;

/**
 * Process Monitor — shows ONLY processes registered by SuperAICore
 * (or by host apps via Process::register). Arbitrary system processes
 * are ignored. Supports log tailing per process.
 */
class ProcessController extends Controller
{
    public function index()
    {
        // Refresh status of running rows (mark dead ones as finished)
        foreach (AiProcess::running()->get() as $p) {
            if (!$p->isAlive()) {
                $p->update(['status' => AiProcess::STATUS_FINISHED, 'ended_at' => now()]);
            }
        }

        $processes = AiProcess::orderByDesc('started_at')->limit(100)->get();
        return view('super-ai-core::processes.index', compact('processes'));
    }

    /**
     * Register a running process. Called by SuperAICore backends OR by
     * host app via JSON POST.
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

        $data['status'] = AiProcess::STATUS_RUNNING;
        $data['started_at'] = now();
        if ($request->user()) $data['user_id'] = $request->user()->id;

        $proc = AiProcess::create($data);
        return response()->json($proc, 201);
    }

    public function kill(Request $request)
    {
        $id = (int) $request->input('id');
        $process = AiProcess::find($id);
        if (!$process || !$process->pid) {
            return response()->json(['ok' => false, 'error' => 'process not found'], 404);
        }

        $p = new Process(['kill', '-TERM', (string) $process->pid]);
        $p->run();
        $success = $p->isSuccessful();

        if ($success) {
            $process->update(['status' => AiProcess::STATUS_KILLED, 'ended_at' => now()]);
        }

        return response()->json([
            'ok' => $success,
            'output' => trim($p->getErrorOutput() ?: $p->getOutput()),
        ]);
    }

    /**
     * Tail a process's log file — used by the right-pane log viewer.
     */
    public function log(Request $request, AiProcess $process)
    {
        if (!$process->log_file || !file_exists($process->log_file)) {
            return response()->json([
                'ok' => false, 'text' => '',
                'error' => 'log file not available',
                'is_alive' => $process->isAlive(),
            ]);
        }

        $bytes = min((int) $request->input('bytes', 65536), 1048576);
        $size = filesize($process->log_file);
        $offset = max(0, $size - $bytes);
        $fh = fopen($process->log_file, 'r');
        fseek($fh, $offset);
        $text = stream_get_contents($fh);
        fclose($fh);

        return response()->json([
            'ok' => true,
            'path' => $process->log_file,
            'size' => $size,
            'text' => $text,
            'is_alive' => $process->isAlive(),
        ]);
    }
}
