<?php

namespace App\Http\Controllers;

use App\Services\BenchmarkHistory;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function __invoke(BenchmarkHistory $benchmarkHistory): View
    {
        $runs = $benchmarkHistory->all();
        $latestRun = $runs[0] ?? null;
        $modelRuns = collect($runs)->sum(fn (array $run): int => count($run['models']));
        $passedModels = collect($runs)->sum(fn (array $run): int => $run['counts']['pass']);
        $failedModels = collect($runs)->sum(fn (array $run): int => $run['counts']['fail']);

        return view('dashboard', [
            'runs' => $runs,
            'latestRun' => $latestRun,
            'metrics' => [
                'runs' => count($runs),
                'modelRuns' => $modelRuns,
                'passedModels' => $passedModels,
                'failedModels' => $failedModels,
                'passRate' => $modelRuns > 0 ? (int) round(($passedModels / $modelRuns) * 100) : 0,
                'storageBytes' => collect($runs)->sum(fn (array $run): int => (int) ($run['storage']['totalBytes'] ?? 0)),
            ],
        ]);
    }
}
