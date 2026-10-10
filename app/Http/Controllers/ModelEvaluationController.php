<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateModelEvaluationRequest;
use App\Models\BenchmarkRun;
use App\Models\ModelRun;
use Illuminate\Http\RedirectResponse;

class ModelEvaluationController extends Controller
{
    public function update(
        UpdateModelEvaluationRequest $request,
        BenchmarkRun $benchmarkRun,
        ModelRun $modelRun,
    ): RedirectResponse {
        $modelRun->evaluation()->updateOrCreate(
            [],
            [
                ...$request->safe()->except('return_path'),
                'reviewed_at' => now(),
            ],
        );

        return redirect()
            ->route('runs.models.preview', [
                'benchmarkRun' => $benchmarkRun,
                'modelRun' => $modelRun,
                'path' => $request->string('return_path')->toString() ?: '/',
                'review' => 1,
            ])
            ->with('status', 'Manuel değerlendirme kaydedildi.');
    }
}
