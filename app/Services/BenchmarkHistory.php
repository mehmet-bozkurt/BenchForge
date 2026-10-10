<?php

namespace App\Services;

use App\Models\BenchmarkRun;
use App\Models\ModelRun;
use App\Models\StageResult;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class BenchmarkHistory
{
    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return BenchmarkRun::query()
            ->with([
                'modelRuns' => fn (HasMany $relation): HasMany => $relation->oldest('id'),
                'modelRuns.stageResults' => fn (HasMany $relation): HasMany => $relation->oldest('id'),
            ])
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (BenchmarkRun $run): array => [
                'slug' => $run->slug,
                'name' => $run->name,
                'date' => $run->started_at ?? $run->created_at,
                'schema' => $run->schema,
                'stageNames' => $run->stage_names,
                'models' => $run->modelRuns->map(fn (ModelRun $modelRun): array => [
                    'slug' => $modelRun->slug,
                    'model' => $modelRun->model,
                    'displayName' => Str::after($modelRun->model, '/'),
                    'durationSeconds' => $modelRun->duration_seconds,
                    'overallStatus' => $modelRun->status,
                    'failedStages' => $modelRun->failed_stages ?? [],
                    'stages' => $modelRun->stageResults
                        ->mapWithKeys(fn (StageResult $stageResult): array => [
                            $stageResult->stage => [
                                'status' => $stageResult->status,
                                'code' => $stageResult->exit_code,
                                'rawValue' => $stageResult->raw_value,
                                'message' => $stageResult->message,
                                'logPath' => $stageResult->log_path,
                                'logSizeBytes' => $stageResult->log_size_bytes,
                            ],
                        ])
                        ->all(),
                ])->all(),
                'counts' => [
                    'pass' => $run->passed_count,
                    'fail' => $run->failed_count,
                    'skip' => $run->skipped_count,
                    'unknown' => $run->unknown_count,
                    'total' => $run->model_count,
                ],
                'totalDurationSeconds' => $run->total_duration_seconds,
                'storage' => [
                    'totalBytes' => $run->total_bytes,
                    'workspaceBytes' => $run->workspace_bytes,
                    'resultBytes' => $run->result_bytes,
                    'workspaceCount' => $run->workspace_count,
                    'scannedAt' => $run->storage_scanned_at,
                ],
            ])
            ->all();
    }
}
