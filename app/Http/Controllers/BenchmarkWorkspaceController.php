<?php

namespace App\Http\Controllers;

use App\Exceptions\BenchmarkStorageException;
use App\Http\Requests\DeleteBenchmarkWorkspaceRequest;
use App\Models\BenchmarkRun;
use App\Services\BenchmarkRunStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Number;

class BenchmarkWorkspaceController extends Controller
{
    public function destroy(
        DeleteBenchmarkWorkspaceRequest $request,
        BenchmarkRun $benchmarkRun,
        BenchmarkRunStorage $benchmarkRunStorage,
    ): RedirectResponse {
        try {
            $result = $benchmarkRunStorage->deleteWorkspaces($benchmarkRun);
        } catch (BenchmarkStorageException $exception) {
            return back()->withErrors(['storage' => $exception->getMessage()]);
        }

        return back()->with(
            'status',
            $result['deletedCount'] === 0
                ? 'Silinecek çalışma alanı bulunamadı.'
                : $result['deletedCount'].' çalışma alanı silindi. '.Number::fileSize($result['freedBytes'], 2).' alan boşaltıldı.',
        );
    }
}
