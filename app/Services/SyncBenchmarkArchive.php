<?php

namespace App\Services;

use App\Models\BenchmarkRun;
use App\Models\ModelRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncBenchmarkArchive
{
    public function __construct(
        private BenchmarkArchive $benchmarkArchive,
        private BenchmarkRunStorage $benchmarkRunStorage,
        private OpenCodeUsageTracker $openCodeUsageTracker,
    ) {}

    /**
     * Only one sync runs at a time: a status poll that finds another sync in progress skips instead of
     * queueing behind it on SQLite's single writer. Disk scans happen outside the database transaction.
     *
     * @return array{created: int, updated: int, unchanged: int, failed: int, errors: list<string>, locked: bool}
     */
    public function handle(?string $slug = null): array
    {
        $result = [
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'failed' => 0,
            'errors' => [],
            'locked' => false,
        ];

        $lock = Cache::lock('benchforge:archive-sync', 900);

        if (! $lock->get()) {
            return [...$result, 'locked' => true];
        }

        try {
            @set_time_limit(900);

            $archiveRuns = $slug === null
                ? $this->benchmarkArchive->all()
                : array_filter([$this->benchmarkArchive->find($slug)]);

            foreach ($archiveRuns as $archiveRun) {
                $this->syncArchiveRun($archiveRun, $result);
            }
        } finally {
            $lock->release();
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $archiveRun
     * @param  array{created: int, updated: int, unchanged: int, failed: int, errors: list<string>, locked: bool}  $result
     */
    private function syncArchiveRun(array $archiveRun, array &$result): void
    {
        try {
            $state = DB::transaction(fn (): string => $this->syncRun($archiveRun));
            $benchmarkRun = BenchmarkRun::query()->where('slug', $archiveRun['slug'])->first();

            if ($benchmarkRun !== null && ($state !== 'unchanged' || $benchmarkRun->storage_scanned_at === null)) {
                $this->benchmarkRunStorage->refresh($benchmarkRun);
            }

            if ($benchmarkRun !== null) {
                try {
                    $this->openCodeUsageTracker->syncForRun($benchmarkRun);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }

            $result[$state]++;
        } catch (Throwable $exception) {
            report($exception);
            $result['failed']++;
            $result['errors'][] = $archiveRun['slug'].': '.$exception->getMessage();
        }
    }

    /**
     * @param  array<string, mixed>  $archiveRun
     */
    private function syncRun(array $archiveRun): string
    {
        $benchmarkRun = BenchmarkRun::query()->where('slug', $archiveRun['slug'])->first();

        if ($benchmarkRun?->source_fingerprint === $archiveRun['fingerprint']) {
            $benchmarkRun->update(['synced_at' => now()]);

            return 'unchanged';
        }

        $state = $benchmarkRun === null ? 'created' : 'updated';
        $benchmarkRun ??= new BenchmarkRun;
        $benchmarkRun->fill([
            'slug' => $archiveRun['slug'],
            'name' => $archiveRun['name'],
            'source_path' => $archiveRun['sourcePath'],
            'source_fingerprint' => $archiveRun['fingerprint'],
            'schema' => $archiveRun['schema'],
            'started_at' => $archiveRun['date'],
            'metadata' => $archiveRun['metadata'],
            'routes' => $archiveRun['routes'],
            'stage_names' => $archiveRun['stageNames'],
            'model_count' => $archiveRun['counts']['total'],
            'passed_count' => $archiveRun['counts']['pass'],
            'failed_count' => $archiveRun['counts']['fail'],
            'skipped_count' => $archiveRun['counts']['skip'],
            'unknown_count' => $archiveRun['counts']['unknown'],
            'total_duration_seconds' => $archiveRun['totalDurationSeconds'],
            'synced_at' => now(),
        ])->save();

        $modelRuns = $this->syncModels($benchmarkRun, $archiveRun['models']);
        $this->syncArtifacts($benchmarkRun, $archiveRun, $modelRuns);

        return $state;
    }

    /**
     * @param  list<array<string, mixed>>  $archiveModels
     * @return array<string, ModelRun>
     */
    private function syncModels(BenchmarkRun $benchmarkRun, array $archiveModels): array
    {
        $modelRuns = [];

        foreach ($archiveModels as $archiveModel) {
            $modelRun = $benchmarkRun->modelRuns()->updateOrCreate(
                ['slug' => $archiveModel['slug']],
                [
                    'model' => $archiveModel['model'],
                    'status' => $archiveModel['overallStatus'],
                    'duration_seconds' => $archiveModel['durationSeconds'],
                    'failed_stages' => $archiveModel['failedStages'],
                ],
            );
            $stageNames = [];

            foreach ($archiveModel['stages'] as $stageName => $stage) {
                $stageNames[] = $stageName;
                $modelRun->stageResults()->updateOrCreate(
                    ['stage' => $stageName],
                    [
                        'status' => $stage['status'],
                        'exit_code' => $stage['code'],
                        'raw_value' => $stage['rawValue'],
                        'message' => $stage['message'],
                        'log_path' => $stage['logPath'] ?? null,
                        'log_size_bytes' => $stage['logSizeBytes'] ?? null,
                    ],
                );
            }

            $this->deleteMissing($modelRun->stageResults(), 'stage', $stageNames);
            $modelRuns[$archiveModel['slug']] = $modelRun;
        }

        $this->deleteMissing($benchmarkRun->modelRuns(), 'slug', array_keys($modelRuns));

        return $modelRuns;
    }

    /**
     * @param  array<string, mixed>  $archiveRun
     * @param  array<string, ModelRun>  $modelRuns
     */
    private function syncArtifacts(BenchmarkRun $benchmarkRun, array $archiveRun, array $modelRuns): void
    {
        $artifacts = $archiveRun['artifacts'];

        foreach ($archiveRun['models'] as $model) {
            array_push($artifacts, ...$model['artifacts']);
        }

        $artifactKeys = [];

        foreach ($artifacts as $artifact) {
            $artifactKeys[] = $artifact['key'];
            $benchmarkRun->artifacts()->updateOrCreate(
                ['artifact_key' => $artifact['key']],
                [
                    'model_run_id' => $artifact['modelSlug'] === null
                        ? null
                        : ($modelRuns[$artifact['modelSlug']]->id ?? null),
                    'kind' => $artifact['kind'],
                    'type' => $artifact['type'],
                    'label' => $artifact['label'],
                    'path' => $artifact['path'],
                    'relative_path' => $artifact['relativePath'],
                    'size_bytes' => $artifact['sizeBytes'],
                    'modified_at' => $artifact['modifiedAt'],
                ],
            );
        }

        $this->deleteMissing($benchmarkRun->artifacts(), 'artifact_key', $artifactKeys);
    }

    /**
     * @param  HasMany<Model, Model>  $relation
     * @param  list<string>  $presentValues
     */
    private function deleteMissing(HasMany $relation, string $column, array $presentValues): void
    {
        if ($presentValues === []) {
            $relation->delete();

            return;
        }

        $relation->whereNotIn($column, $presentValues)->delete();
    }
}
