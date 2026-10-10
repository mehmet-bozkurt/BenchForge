<?php

namespace App\Http\Controllers;

use App\Exceptions\BenchmarkRunnerException;
use App\Http\Requests\StorePreparationComparisonRequest;
use App\Models\OpenCodeGoModel;
use App\Models\OpenRouterModel;
use App\Models\PreparationComparison;
use App\Services\CatalogModelDetails;
use App\Services\PreparationRunner;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class PreparationComparisonController extends Controller
{
    public function index(PreparationRunner $runner): View
    {
        $activeComparison = $this->activeComparison($runner);

        return view('preparations.index', [
            'comparisons' => PreparationComparison::query()
                ->withCount('packages')
                ->with(['packages' => fn ($query) => $query->select([
                    'id', 'preparation_comparison_id', 'model_id', 'model_name', 'status',
                    'duration_seconds', 'cost_usd', 'manual_evaluation', 'reviewed_at',
                ])])
                ->latest('id')->paginate(20),
            'activeComparison' => $activeComparison,
        ]);
    }

    public function create(PreparationRunner $runner, CatalogModelDetails $catalogDetails): View
    {
        $options = $runner->options();

        return view('preparations.create', [
            'options' => $options,
            'modelDetails' => [
                'openrouter' => ($options['openRouterModels'] ?? collect())
                    ->mapWithKeys(fn (OpenRouterModel $model): array => [$model->model_id => $catalogDetails->forModel($model)]),
                'opencode-go' => ($options['openCodeGoModels'] ?? collect())
                    ->mapWithKeys(fn (OpenCodeGoModel $model): array => [$model->model_id => $catalogDetails->forModel($model)]),
            ],
            'activeComparison' => $this->activeComparison($runner),
        ]);
    }

    public function store(StorePreparationComparisonRequest $request, PreparationRunner $runner): RedirectResponse
    {
        try {
            $comparison = $runner->start($request->preparationInput());
        } catch (BenchmarkRunnerException $exception) {
            return back()->withInput()->withErrors(['preparation' => $exception->getMessage()]);
        }

        return redirect()->route('preparations.show', $comparison)
            ->with('status', 'Hazırlık paketi üretimi başlatıldı. Proje geliştirme başlatılmadı.');
    }

    public function show(PreparationComparison $preparationComparison, PreparationRunner $runner): View
    {
        return view('preparations.show', [
            'comparison' => $runner->reconcile($preparationComparison)->load('packages'),
        ]);
    }

    private function activeComparison(PreparationRunner $runner): ?PreparationComparison
    {
        $comparison = PreparationComparison::query()
            ->whereIn('status', PreparationComparison::ACTIVE_STATUSES)->latest('id')->first();

        if ($comparison === null) {
            return null;
        }

        $comparison = $runner->reconcile($comparison);

        return $comparison->isActive() ? $comparison : null;
    }
}
