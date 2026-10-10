<?php

namespace App\Http\Controllers;

use App\Exceptions\BenchmarkStorageException;
use App\Http\Requests\DeleteBenchmarkRunRequest;
use App\Models\BenchmarkRun;
use App\Models\ModelRun;
use App\Models\ModelRunAttempt;
use App\Services\BenchmarkPreview;
use App\Services\BenchmarkRunStorage;
use App\Services\ModelRunBoundaryReport;
use App\Services\ModelRunContinuationRunner;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Number;

class BenchmarkRunController extends Controller
{
    public function show(
        BenchmarkRun $benchmarkRun,
        BenchmarkPreview $benchmarkPreview,
        ModelRunContinuationRunner $continuationRunner,
        ModelRunBoundaryReport $boundaryReport,
    ): View {
        $continuationRunner->reconcileForRun($benchmarkRun);

        $benchmarkRun->load([
            'modelRuns' => fn (HasMany $relation): HasMany => $relation->oldest('id'),
            'modelRuns.stageResults' => fn (HasMany $relation): HasMany => $relation->oldest('id'),
            'modelRuns.modelRunAttempts' => fn (HasMany $relation): HasMany => $relation->oldest('attempt_number'),
            'modelRuns.evaluation',
            'artifacts' => fn (HasMany $relation): HasMany => $relation->orderBy('artifact_key'),
        ]);

        $continuationAvailability = $benchmarkRun->modelRuns
            ->mapWithKeys(fn (ModelRun $modelRun): array => [
                $modelRun->id => $continuationRunner->availability($benchmarkRun, $modelRun),
            ]);
        $activeAttemptCount = ModelRunAttempt::query()
            ->whereIn('status', ModelRunAttempt::ACTIVE_STATUSES)
            ->count();
        $attemptLogTails = $benchmarkRun->modelRuns
            ->flatMap(fn (ModelRun $modelRun) => $modelRun->modelRunAttempts)
            ->filter(fn ($attempt): bool => $attempt->isActive())
            ->mapWithKeys(fn ($attempt): array => [
                $attempt->id => $continuationRunner->readLogTail($attempt),
            ]);
        $modelBoundaryReports = $benchmarkRun->modelRuns->mapWithKeys(fn (ModelRun $model): array => [
            $model->id => $boundaryReport->forModel($benchmarkRun, $model),
        ]);
        $attemptBoundaryReports = $benchmarkRun->modelRuns->flatMap(fn (ModelRun $model) => $model->modelRunAttempts)
            ->mapWithKeys(fn (ModelRunAttempt $attempt): array => [
                $attempt->id => $boundaryReport->forAttempt($benchmarkRun, $benchmarkRun->modelRuns->firstWhere('id', $attempt->model_run_id), $attempt),
            ]);

        return view('runs.show', [
            'run' => $benchmarkRun,
            'artifactsByKey' => $benchmarkRun->artifacts->keyBy('artifact_key'),
            'previewableModelIds' => $benchmarkRun->modelRuns
                ->filter(fn (ModelRun $modelRun): bool => $benchmarkPreview->isAvailable($benchmarkRun, $modelRun))
                ->pluck('id'),
            'recoveredPreviewModelIds' => $benchmarkRun->modelRuns
                ->filter(fn (ModelRun $modelRun): bool => $benchmarkPreview->isAvailable($benchmarkRun, $modelRun, BenchmarkPreview::RECOVERED))
                ->pluck('id'),
            'continuationAvailability' => $continuationAvailability,
            'activeAttemptCount' => $activeAttemptCount,
            'maxParallelAttempts' => ModelRunContinuationRunner::MAX_PARALLEL_ATTEMPTS,
            'attemptLogTails' => $attemptLogTails,
            'modelBoundaryReports' => $modelBoundaryReports,
            'attemptBoundaryReports' => $attemptBoundaryReports,
        ]);
    }

    public function destroy(
        DeleteBenchmarkRunRequest $request,
        BenchmarkRun $benchmarkRun,
        BenchmarkRunStorage $benchmarkRunStorage,
    ): RedirectResponse {
        try {
            $result = $benchmarkRunStorage->deleteRun($benchmarkRun);
        } catch (BenchmarkStorageException $exception) {
            return back()->withErrors(['storage' => $exception->getMessage()]);
        }

        return redirect()
            ->route('dashboard')
            ->with('status', 'Benchmark sonucu ve dosyaları silindi. '.Number::fileSize($result['freedBytes'], 2).' alan boşaltıldı.');
    }
}
