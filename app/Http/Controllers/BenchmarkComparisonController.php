<?php

namespace App\Http\Controllers;

use App\Models\BenchmarkRun;
use App\Models\ModelRun;
use App\Models\ModelRunAttempt;
use App\Services\BenchmarkPreview;
use App\Services\ModelRunContinuationRunner;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class BenchmarkComparisonController extends Controller
{
    private const STAGE_LABELS = [
        'opencode' => 'OpenCode',
        'lint' => 'Lint',
        'build' => 'Build',
        'export' => 'Export',
        'routes' => 'Rotalar',
        'runtime' => 'Runtime',
    ];

    public function __invoke(
        Request $request,
        BenchmarkRun $benchmarkRun,
        BenchmarkPreview $benchmarkPreview,
        ModelRunContinuationRunner $continuationRunner,
    ): View {
        $continuationRunner->reconcileForRun($benchmarkRun);

        $benchmarkRun->load([
            'modelRuns' => fn (HasMany $relation): HasMany => $relation->oldest('id'),
            'modelRuns.evaluation',
            'modelRuns.stageResults',
            'modelRuns.modelRunAttempts',
        ]);

        $models = $benchmarkRun->modelRuns;
        $previewableModels = $benchmarkRun->modelRuns
            ->filter(fn (ModelRun $modelRun): bool => $benchmarkPreview->isAvailable($benchmarkRun, $modelRun))
            ->values();
        $previewableModelIds = $previewableModels->pluck('id');
        $recoveredPreviewModelIds = $models
            ->filter(fn (ModelRun $modelRun): bool => $benchmarkPreview->isAvailable($benchmarkRun, $modelRun, BenchmarkPreview::RECOVERED))
            ->pluck('id');

        $leftModel = $this->selectedModel($models, $request->integer('left'))
            ?? $previewableModels->first()
            ?? $models->first();
        $rightModel = $request->has('right')
            ? $this->selectedModel($models, $request->integer('right'))
            : ($previewableModels->first(fn (ModelRun $modelRun): bool => $modelRun->isNot($leftModel))
                ?? $models->first(fn (ModelRun $modelRun): bool => $modelRun->isNot($leftModel)));
        $resultComparisons = collect([$leftModel, $rightModel])
            ->filter()
            ->unique('id')
            ->mapWithKeys(fn (ModelRun $modelRun): array => [$modelRun->id => $this->resultComparison($modelRun)]);
        $hasRecoveryAttempts = collect([$leftModel, $rightModel])
            ->filter()
            ->contains(fn (ModelRun $modelRun): bool => $modelRun->modelRunAttempts->isNotEmpty());
        $availableRoutes = collect($benchmarkRun->routes ?? ['/'])
            ->filter(fn (mixed $route): bool => is_string($route) && str_starts_with($route, '/'))
            ->values();
        $selectedRoute = $request->string('path')->toString();
        $selectedViewport = $request->string('viewport')->toString();

        if (! $availableRoutes->contains($selectedRoute)) {
            $selectedRoute = $availableRoutes->first() ?? '/';
        }

        if (! in_array($selectedViewport, ['desktop', 'tablet', 'mobile'], true)) {
            $selectedViewport = 'desktop';
        }

        return view('runs.compare', [
            'run' => $benchmarkRun,
            'models' => $models,
            'previewableModels' => $previewableModels,
            'previewableModelIds' => $previewableModelIds,
            'recoveredPreviewModelIds' => $recoveredPreviewModelIds,
            'leftModel' => $leftModel,
            'rightModel' => $rightModel,
            'resultComparisons' => $resultComparisons,
            'hasRecoveryAttempts' => $hasRecoveryAttempts,
            'availableRoutes' => $availableRoutes,
            'selectedRoute' => $selectedRoute,
            'selectedViewport' => $selectedViewport,
        ]);
    }

    /**
     * @param  Collection<int, ModelRun>  $models
     */
    private function selectedModel(Collection $models, int $modelId): ?ModelRun
    {
        if ($modelId === 0) {
            return null;
        }

        return $models->first(fn (ModelRun $modelRun): bool => $modelRun->getKey() === $modelId);
    }

    /**
     * @return array{attempt: ?ModelRunAttempt, rows: list<array{stage: string, label: string, initial: string, latest: string, change: string}>}
     */
    private function resultComparison(ModelRun $modelRun): array
    {
        $attempt = $modelRun->modelRunAttempts
            ->sortByDesc('attempt_number')
            ->first(fn (ModelRunAttempt $attempt): bool => $attempt->status === 'completed' && is_array($attempt->result));

        if ($attempt === null) {
            return ['attempt' => null, 'rows' => []];
        }

        $initialStages = $modelRun->stageResults->keyBy('stage');
        $latestStages = $attempt->result['stages'] ?? [];
        $rows = [];

        foreach (self::STAGE_LABELS as $stage => $label) {
            $initial = $initialStages->get($stage)?->status ?? 'unknown';
            $latest = is_array($latestStages) && is_array($latestStages[$stage] ?? null)
                ? ($latestStages[$stage]['status'] ?? 'unknown')
                : 'unknown';
            $initial = in_array($initial, ['pass', 'fail', 'skip'], true) ? $initial : 'unknown';
            $latest = in_array($latest, ['pass', 'fail', 'skip'], true) ? $latest : 'unknown';

            $rows[] = [
                'stage' => $stage,
                'label' => $label,
                'initial' => $initial,
                'latest' => $latest,
                'change' => $this->stageChange($initial, $latest),
            ];
        }

        return ['attempt' => $attempt, 'rows' => $rows];
    }

    private function stageChange(string $initial, string $latest): string
    {
        if ($latest === 'skip') {
            return 'not_run';
        }

        if ($latest === 'unknown') {
            return 'unknown';
        }

        if ($initial === 'fail' && $latest === 'pass') {
            return 'improved';
        }

        if ($initial === 'pass' && $latest === 'fail') {
            return 'regressed';
        }

        if ($initial === 'unknown' || $initial === 'skip') {
            return 'new_result';
        }

        return 'unchanged';
    }
}
