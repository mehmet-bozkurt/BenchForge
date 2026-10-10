<?php

namespace App\Http\Controllers;

use App\Exceptions\BenchmarkStorageException;
use App\Http\Requests\DeleteBenchmarkRunRequest;
use App\Models\BenchmarkRun;
use App\Services\BenchmarkRunStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Number;

class BenchmarkResultController extends Controller
{
    public function destroy(
        DeleteBenchmarkRunRequest $request,
        BenchmarkRun $benchmarkRun,
        BenchmarkRunStorage $benchmarkRunStorage,
    ): RedirectResponse {
        try {
            $result = $benchmarkRunStorage->deleteResults($benchmarkRun);
        } catch (BenchmarkStorageException $exception) {
            return back()->withErrors(['storage' => $exception->getMessage()]);
        }

        return redirect()
            ->route('runs.show', $benchmarkRun)
            ->with('status', 'Sonuç arşivi silindi; çalışma alanları korundu. '.Number::fileSize($result['freedBytes'], 2).' alan boşaltıldı.');
    }
}
