<?php

namespace App\Http\Controllers;

use App\Exceptions\BenchmarkRunnerException;
use App\Models\PreparationComparison;
use App\Services\PreparationRunner;
use Illuminate\Http\RedirectResponse;

class PreparationExecutionController extends Controller
{
    public function destroy(PreparationComparison $preparationComparison, PreparationRunner $runner): RedirectResponse
    {
        try {
            $runner->stop($preparationComparison);
        } catch (BenchmarkRunnerException $exception) {
            return back()->withErrors(['preparation' => $exception->getMessage()]);
        }

        return redirect()->route('preparations.show', $preparationComparison)
            ->with('status', 'Hazırlık paketi üretimi durduruluyor; tamamlanan paketler korunacak.');
    }
}
