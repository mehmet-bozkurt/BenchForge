<?php

namespace App\Services;

use App\Models\ModelRun;
use App\Models\StageResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ModelLeaderboard
{
    /** @var array<string, string> */
    public const CRITERIA = [
        'visual_design_score' => 'Görsel tasarım',
        'user_experience_score' => 'Kullanıcı deneyimi',
        'task_completion_score' => 'Görev tamamlama',
        'functionality_score' => 'İşlevsellik',
        'responsive_quality_score' => 'Responsive kalite',
        'content_quality_score' => 'İçerik kalitesi',
        'consistency_score' => 'Tutarlılık',
        'overall_score' => 'Genel puan',
    ];

    public function __construct(private readonly BenchmarkPreview $benchmarkPreview) {}

    /** @return Collection<int, array<string, mixed>> */
    public function all(): Collection
    {
        return ModelRun::query()
            ->with(['benchmarkRun', 'stageResults', 'evaluation'])
            ->get()
            ->groupBy('slug')
            ->map(fn (Collection $runs, string $slug): array => $this->summarize($slug, $runs))
            ->sort(function (array $left, array $right): int {
                $reviewComparison = ($right['manual']['count'] > 0) <=> ($left['manual']['count'] > 0);

                if ($reviewComparison !== 0) {
                    return $reviewComparison;
                }

                $scoreComparison = ($right['composite_score'] ?? -1) <=> ($left['composite_score'] ?? -1);

                if ($scoreComparison !== 0) {
                    return $scoreComparison;
                }

                $technicalComparison = $right['technical']['score'] <=> $left['technical']['score'];

                return $technicalComparison !== 0
                    ? $technicalComparison
                    : strcmp($left['display_name'], $right['display_name']);
            })
            ->values()
            ->map(function (array $model, int $index): array {
                $model['rank'] = $model['manual']['count'] > 0 ? $index + 1 : null;

                return $model;
            });
    }

    /** @return array<string, mixed>|null */
    public function find(string $slug): ?array
    {
        if ($slug === '' || $slug === '.' || $slug === '..' || basename($slug) !== $slug) {
            return null;
        }

        $runs = ModelRun::query()
            ->where('slug', $slug)
            ->with(['benchmarkRun', 'stageResults', 'evaluation'])
            ->get();

        return $runs->isEmpty() ? null : $this->summarize($slug, $runs);
    }

    /**
     * @param  Collection<int, ModelRun>  $runs
     * @return array<string, mixed>
     */
    private function summarize(string $slug, Collection $runs): array
    {
        $stageResults = $runs->flatMap(fn (ModelRun $run): Collection => $run->stageResults);
        $evaluations = $runs->pluck('evaluation')->filter()->values();
        $passedStages = $stageResults->where('status', 'pass')->count();
        $technicalScore = $stageResults->isEmpty()
            ? 0.0
            : round(($passedStages / $stageResults->count()) * 100, 1);
        $manualScore = $evaluations->isEmpty()
            ? null
            : round((float) $evaluations->avg('overall_score'), 1);

        return [
            'slug' => $slug,
            'display_name' => Str::after((string) $runs->first()->model, '/'),
            'provider_model' => (string) $runs->first()->model,
            'run_count' => $runs->count(),
            'passed_run_count' => $runs->where('status', 'pass')->count(),
            'passed_run_rate' => round(($runs->where('status', 'pass')->count() / $runs->count()) * 100, 1),
            'average_duration_seconds' => (int) round((float) $runs->avg('duration_seconds')),
            'latest_run_at' => $runs->pluck('benchmarkRun.started_at')->filter()->max(),
            'composite_score' => $manualScore === null
                ? null
                : round(($technicalScore * 0.6) + (($manualScore * 10) * 0.4), 1),
            'technical' => [
                'score' => $technicalScore,
                'passed' => $passedStages,
                'total' => $stageResults->count(),
                'failed' => $stageResults->where('status', 'fail')->count(),
                'stages' => $this->stageBreakdown($stageResults),
            ],
            'manual' => [
                'count' => $evaluations->count(),
                'coverage' => round(($evaluations->count() / $runs->count()) * 100, 1),
                'average' => $manualScore,
                'criteria' => collect(self::CRITERIA)->mapWithKeys(fn (string $label, string $criterion): array => [
                    $criterion => [
                        'label' => $label,
                        'average' => $evaluations->isEmpty() ? null : round((float) $evaluations->avg($criterion), 1),
                    ],
                ])->all(),
                'severity' => collect(['none', 'minor', 'major', 'critical'])->mapWithKeys(fn (string $severity): array => [
                    $severity => $evaluations->where('issue_severity', $severity)->count(),
                ])->all(),
                'notes' => $this->notes($runs),
            ],
            'runs' => $this->runHistory($runs),
        ];
    }

    /**
     * @param  Collection<int, StageResult>  $stageResults
     * @return Collection<int, array<string, mixed>>
     */
    private function stageBreakdown(Collection $stageResults): Collection
    {
        return $stageResults
            ->groupBy('stage')
            ->map(function (Collection $results, string $stage): array {
                $passed = $results->where('status', 'pass')->count();

                return [
                    'stage' => $stage,
                    'passed' => $passed,
                    'failed' => $results->where('status', 'fail')->count(),
                    'skipped' => $results->whereIn('status', ['skip', 'unknown'])->count(),
                    'total' => $results->count(),
                    'pass_rate' => round(($passed / $results->count()) * 100, 1),
                ];
            })
            ->sortByDesc('pass_rate')
            ->values();
    }

    /**
     * @param  Collection<int, ModelRun>  $runs
     * @return Collection<int, array<string, mixed>>
     */
    private function notes(Collection $runs): Collection
    {
        return $runs
            ->filter(fn (ModelRun $run): bool => filled($run->evaluation?->notes))
            ->sortByDesc(fn (ModelRun $run): mixed => $run->evaluation?->reviewed_at)
            ->map(fn (ModelRun $run): array => [
                'text' => $run->evaluation->notes,
                'severity' => $run->evaluation->issue_severity,
                'reviewed_at' => $run->evaluation->reviewed_at,
                'benchmark_name' => $run->benchmarkRun->name,
                'benchmark_run' => $run->benchmarkRun,
            ])
            ->values();
    }

    /**
     * @param  Collection<int, ModelRun>  $runs
     * @return Collection<int, array<string, mixed>>
     */
    private function runHistory(Collection $runs): Collection
    {
        return $runs
            ->sortByDesc(fn (ModelRun $run): mixed => $run->benchmarkRun->started_at)
            ->map(fn (ModelRun $run): array => [
                'model_run' => $run,
                'benchmark_run' => $run->benchmarkRun,
                'status' => $run->status,
                'duration_seconds' => $run->duration_seconds,
                'failed_stages' => $run->stageResults->where('status', 'fail')->pluck('stage')->values(),
                'technical_score' => $run->stageResults->isEmpty()
                    ? 0.0
                    : round(($run->stageResults->where('status', 'pass')->count() / $run->stageResults->count()) * 100, 1),
                'manual_score' => $run->evaluation?->overall_score,
                'preview_available' => $this->benchmarkPreview->isAvailable($run->benchmarkRun, $run),
            ])
            ->values();
    }
}
