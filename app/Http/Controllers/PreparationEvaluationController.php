<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePreparationEvaluationRequest;
use App\Models\PreparationComparison;
use App\Models\PreparationPackage;
use Illuminate\Http\RedirectResponse;

class PreparationEvaluationController extends Controller
{
    public function update(UpdatePreparationEvaluationRequest $request, PreparationComparison $preparationComparison, PreparationPackage $preparationPackage): RedirectResponse
    {
        abort_unless($preparationPackage->status === 'completed', 409, 'Yalnız tamamlanan hazırlık paketleri değerlendirilebilir.');

        $preparationPackage->update([
            'manual_evaluation' => array_map('intval', $request->safe()->only(array_keys(PreparationPackage::EVALUATION_LABELS))),
            'review_notes' => $request->validated('review_notes'),
            'issue_severity' => $request->validated('issue_severity'),
            'reviewed_at' => now(),
        ]);

        return redirect()->route('preparations.show', $preparationComparison)
            ->with('status', 'Hazırlık paketi için insan değerlendirmesi kaydedildi.');
    }
}
