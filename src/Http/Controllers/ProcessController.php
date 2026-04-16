<?php

namespace ForgeOmni\AiCore\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\Process\Process;

/**
 * Process Monitor — lists running AI CLI processes (claude, codex, superagent)
 * and lets admins kill orphaned ones.
 *
 * Decoupled from SuperTeam's Task/TaskResult — just monitors system processes.
 * Host apps can extend to correlate with their own run/task models.
 */
class ProcessController extends Controller
{
    /**
     * Binary names that count as "AI CLI processes".
     */
    const BINARIES = ['claude', 'codex'];

    public function index()
    {
        $processes = $this->listProcesses();
        return view('ai-core::processes.index', compact('processes'));
    }

    public function kill(Request $request)
    {
        $pid = (int) $request->input('pid');
        if (!$pid) return response()->json(['ok' => false, 'error' => 'Missing pid'], 422);

        $process = new Process(['kill', '-TERM', (string) $pid]);
        $process->run();

        return response()->json([
            'ok' => $process->isSuccessful(),
            'output' => trim($process->getErrorOutput() ?: $process->getOutput()),
        ]);
    }

    protected function listProcesses(): array
    {
        $patterns = implode('|', array_map('preg_quote', self::BINARIES));
        $process = new Process(['ps', 'axo', 'pid,user,pcpu,pmem,etime,args']);
        $process->run();

        $out = [];
        if (!$process->isSuccessful()) return $out;

        foreach (explode("\n", trim($process->getOutput())) as $line) {
            if (!preg_match('/\b(' . $patterns . ')\b/', $line, $m)) continue;
            // Skip the "ps" command itself and grep
            if (str_contains($line, 'ps axo') || str_contains($line, 'grep')) continue;

            $parts = preg_split('/\s+/', trim($line), 6);
            if (count($parts) < 6) continue;
            [$pid, $user, $cpu, $mem, $etime, $args] = $parts;
            if (!ctype_digit($pid)) continue;

            $out[] = [
                'pid' => (int) $pid,
                'user' => $user,
                'cpu' => (float) $cpu,
                'mem' => (float) $mem,
                'elapsed' => $etime,
                'command' => $args,
                'binary' => $m[1],
            ];
        }

        return $out;
    }
}
