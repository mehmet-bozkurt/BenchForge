<?php

namespace App\Http\Controllers;

use App\Exceptions\BenchmarkRunnerException;
use App\Services\BenchmarkRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StopBenchmarkExecutionController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, BenchmarkRunner $benchmarkRunner): RedirectResponse
    {
        if (! in_array($request->ip(), ['127.0.0.1', '::1'], true)) {
            abort(403);
        }

        try {
            $execution = $benchmarkRunner->stop();
        } catch (BenchmarkRunnerException $exception) {
            return back()->withErrors(['runner' => $exception->getMessage()]);
        }

        return redirect()
            ->route('runner.create')
            ->with('status', "{$execution->run_id} için durdurma isteği gönderildi.");
    }
}
