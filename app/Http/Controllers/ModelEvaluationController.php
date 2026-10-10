<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateModelEvaluationRequest;
use App\Models\BenchmarkRun;
use App\Models\ModelRun;
use App\Services\BenchmarkPreview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class ModelEvaluationController extends Controller
{
    public function update(
        UpdateModelEvaluationRequest $request,
        BenchmarkRun $benchmarkRun,
        ModelRun $modelRun,
        BenchmarkPreview $benchmarkPreview,
    ): RedirectResponse {
        if (! $benchmarkPreview->isAvailable($benchmarkRun, $modelRun)) {
            throw ValidationException::withMessages([
                'evaluation' => 'Manuel puan yalnız ilk teslim çıktısına verilir; bu modelin ilk teslimine ait görüntülenebilir çıktı yok.',
            ]);
        }

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
