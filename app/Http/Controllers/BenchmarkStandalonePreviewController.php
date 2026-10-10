<?php

namespace App\Http\Controllers;

use App\Models\BenchmarkRun;
use App\Models\ModelRun;
use App\Services\BenchmarkPreview;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;

class BenchmarkStandalonePreviewController extends Controller
{
    private const EVALUATION_CRITERIA = [
        'visual_design_score' => ['label' => 'Görsel tasarım', 'description' => 'Hiyerarşi, estetik ve görsel kalite'],
        'user_experience_score' => ['label' => 'Kullanıcı deneyimi', 'description' => 'Anlaşılabilirlik, akış ve kullanım kolaylığı'],
        'task_completion_score' => ['label' => 'Görev tamamlama', 'description' => 'Prompt kapsamının karşılanma düzeyi'],
        'functionality_score' => ['label' => 'İşlevsellik', 'description' => 'Etkileşimlerin gerçek ve doğru çalışması'],
        'responsive_quality_score' => ['label' => 'Responsive kalite', 'description' => 'Mobil, tablet ve masaüstü uyumu'],
        'content_quality_score' => ['label' => 'İçerik kalitesi', 'description' => 'Metinlerin gerçekçiliği ve bağlama uygunluğu'],
        'consistency_score' => ['label' => 'Tutarlılık', 'description' => 'Sayfalar arası tasarım ve davranış bütünlüğü'],
    ];

    public function __invoke(
        Request $request,
        BenchmarkRun $benchmarkRun,
        ModelRun $modelRun,
        BenchmarkPreview $benchmarkPreview,
    ): View {
        abort_unless($benchmarkPreview->isAvailable($benchmarkRun, $modelRun), 404);

        $modelRun->load('evaluation');

        $benchmarkRun->load([
            'modelRuns' => fn (HasMany $relation): HasMany => $relation->oldest('id'),
        ]);
        $previewableModels = $benchmarkRun->modelRuns
            ->filter(fn (ModelRun $candidate): bool => $benchmarkPreview->isAvailable($benchmarkRun, $candidate))
            ->values();
        $currentIndex = $previewableModels->search(
            fn (ModelRun $candidate): bool => $candidate->is($modelRun),
        );

        abort_if($currentIndex === false, 404);

        $modelCount = $previewableModels->count();
        $previousModel = $modelCount > 1
            ? $previewableModels->get(($currentIndex - 1 + $modelCount) % $modelCount)
            : null;
        $nextModel = $modelCount > 1
            ? $previewableModels->get(($currentIndex + 1) % $modelCount)
            : null;

        $availableRoutes = collect($benchmarkRun->routes ?? ['/'])
            ->filter(fn (mixed $route): bool => is_string($route) && str_starts_with($route, '/'))
            ->values();
        $selectedRoute = $request->string('path')->toString();

        if (! $availableRoutes->contains($selectedRoute)) {
            $selectedRoute = $availableRoutes->first() ?? '/';
        }

        $previewBaseUrl = route('runs.previews.show', [$benchmarkRun, $modelRun], absolute: false);
        $relativePath = ltrim($selectedRoute, '/');

        return view('runs.preview', [
            'run' => $benchmarkRun,
            'modelRun' => $modelRun,
            'availableRoutes' => $availableRoutes,
            'selectedRoute' => $selectedRoute,
            'previewUrl' => $relativePath === '' ? $previewBaseUrl.'/' : $previewBaseUrl.'/'.$relativePath,
            'previousModel' => $previousModel,
            'nextModel' => $nextModel,
            'currentPosition' => $currentIndex + 1,
            'modelCount' => $modelCount,
            'evaluation' => $modelRun->evaluation,
            'evaluationCriteria' => self::EVALUATION_CRITERIA,
            'openEvaluationPanel' => $request->boolean('review') || session()->has('errors') || session()->has('status'),
        ]);
    }
}
