<?php

namespace App\Services;

use App\Models\ModelRun;
use App\Models\StageResult;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Model ids contain "/", which cannot appear in a route segment.
     */
    private const KEY_SEPARATOR = '~';

    public function __construct(private readonly BenchmarkPreview $benchmarkPreview) {}

    /** @return Collection<int, array<string, mixed>> */
    public function all(): Collection
    {
        return ModelRun::query()
            ->with(['benchmarkRun', 'stageResults', 'evaluation', 'modelRunAttempts'])
            ->get()
            ->groupBy(fn (ModelRun $run): string => $this->identity($run))
            ->map(fn (Collection $runs, string $identity): array => $this->summarize($identity, $runs))
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

    /**
     * Find a model by its URL key; a legacy workspace folder slug resolves to the model it ran.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $key): ?array
    {
        if ($key === '' || $key === '.' || $key === '..' || basename($key) !== $key) {
            return null;
        }

        $identity = str_replace(self::KEY_SEPARATOR, '/', $key);
        $runs = $this->runsFor($identity);

        if ($runs->isEmpty()) {
            $legacyRun = ModelRun::query()->where('slug', $key)->first();

            if ($legacyRun === null) {
                return null;
            }

            $identity = $this->identity($legacyRun);
            $runs = $this->runsFor($identity);
        }

        return $runs->isEmpty() ? null : $this->summarize($identity, $runs);
    }

    /**
     * Leaderboard rows are keyed by the provider/model id, not by the workspace folder,
     * so the same model run under different folder names is summarised once.
     */
    public function identity(ModelRun $run): string
    {
        return filled($run->model) ? (string) $run->model : (string) $run->slug;
    }

    public function key(string $identity): string
    {
        return str_replace('/', self::KEY_SEPARATOR, $identity);
    }

    /**
     * A run's technical score is its own stage pass ratio; a run whose model step failed
     * produced no submission of its own, so untouched template checks earn nothing.
     */
    public function runTechnicalScore(ModelRun $run): ?float
    {
        if ($run->stageResults->isEmpty()) {
            return null;
        }

        $modelStage = $run->stageResults->firstWhere('stage', 'opencode');

        if ($modelStage !== null && $modelStage->status !== 'pass') {
            return 0.0;
        }

        return round(($run->stageResults->where('status', 'pass')->count() / $run->stageResults->count()) * 100, 1);
    }

    /** @return Collection<int, ModelRun> */
    private function runsFor(string $identity): Collection
    {
        return ModelRun::query()
            ->where(fn (Builder $query): Builder => $query
                ->where('model', $identity)
                ->orWhere(fn (Builder $query): Builder => $query->where('model', '')->where('slug', $identity)))
            ->with(['benchmarkRun', 'stageResults', 'evaluation', 'modelRunAttempts'])
            ->get();
    }

    /**
     * @param  Collection<int, ModelRun>  $runs
     * @return array<string, mixed>
     */
    private function summarize(string $identity, Collection $runs): array
    {
        $measuredRuns = $runs->filter(fn (ModelRun $run): bool => $run->stageResults->isNotEmpty())->values();
        $stageResults = $measuredRuns->flatMap(fn (ModelRun $run): Collection => $run->stageResults);
        $evaluations = $runs->pluck('evaluation')->filter()->values();
        $passedStages = $stageResults->where('status', 'pass')->count();
        $technicalScore = $measuredRuns->isEmpty()
            ? 0.0
            : round((float) $measuredRuns->avg(fn (ModelRun $run): float => (float) $this->runTechnicalScore($run)), 1);
        $manualScore = $evaluations->isEmpty()
            ? null
            : round((float) $evaluations->avg('overall_score'), 1);
        $stageSets = $measuredRuns
            ->map(fn (ModelRun $run): string => $run->stageResults->pluck('stage')->sort()->implode(','))
            ->unique()
            ->values();

        return [
            'slug' => $this->key($identity),
            'display_name' => Str::after($identity, '/'),
            'provider_model' => $identity,
            'workspace_slugs' => $runs->pluck('slug')->unique()->sort()->values(),
            'run_count' => $runs->count(),
            'measured_run_count' => $measuredRuns->count(),
            'mixed_stage_sets' => $stageSets->count() > 1,
            'passed_run_count' => $measuredRuns->where('status', 'pass')->count(),
            'passed_run_rate' => $measuredRuns->isEmpty()
                ? 0.0
                : round(($measuredRuns->where('status', 'pass')->count() / $measuredRuns->count()) * 100, 1),
            'average_duration_seconds' => (int) round((float) $measuredRuns->avg('duration_seconds')),
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
                'technical_score' => $this->runTechnicalScore($run),
                'manual_score' => $run->evaluation?->overall_score,
                'preview_available' => $this->benchmarkPreview->isAvailable($run->benchmarkRun, $run),
            ])
            ->values();
    }
}
