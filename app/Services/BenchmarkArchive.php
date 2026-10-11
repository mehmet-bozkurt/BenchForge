<?php

namespace App\Services;

use App\Models\StageResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

class BenchmarkArchive
{
    private const NON_STAGE_COLUMNS = [
        'klasor',
        'folder',
        'slug',
        'model',
        'sure_sn',
        'duration',
        'duration_seconds',
    ];

    private const EXTENDED_STAGES = ['export', 'routes', 'runtime'];

    /**
     * @return list<array{
     *     slug: string,
     *     name: string,
     *     date: CarbonImmutable,
     *     schema: 'legacy'|'extended'|'incomplete',
     *     stageNames: list<string>,
     *     models: list<array<string, mixed>>,
     *     counts: array{pass: int, fail: int, skip: int, unknown: int, total: int},
     *     totalDurationSeconds: int
     * }>
     */
    public function all(): array
    {
        $runsPath = (string) config('benchforge.runs_path');

        if (! File::isDirectory($runsPath)) {
            return [];
        }

        return collect(File::directories($runsPath))
            ->filter(fn (string $directory): bool => $this->isSafeSegment(basename($directory)))
            ->map(fn (string $directory): ?array => $this->readRun($directory))
            ->filter()
            ->sortByDesc(fn (array $run): int => $run['date']->getTimestamp())
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $slug): ?array
    {
        $directory = rtrim((string) config('benchforge.runs_path'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$slug;

        if (! $this->isSafeSegment($slug) || ! File::isDirectory($directory) || is_link($directory)) {
            return null;
        }

        return $this->readRun($directory);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readRun(string $directory): ?array
    {
        $summaryPath = $directory.'/_summary.tsv';
        $metaPath = $directory.'/_meta.txt';

        $hasSummary = File::isFile($summaryPath);

        try {
            $summaryContents = $hasSummary ? File::get($summaryPath) : '';
            $metaContents = File::isFile($metaPath) ? File::get($metaPath) : '';
            $summary = $this->parseSummary($summaryContents);
            $meta = $this->parseMeta($metaContents);
            $date = $this->parseDate(
                $meta['date'] ?? $meta['tarih'] ?? null,
                File::lastModified($directory),
            );
        } catch (Throwable) {
            return null;
        }

        $archiveModels = $summary['models'] !== []
            ? $summary['models']
            : $this->inferredModels($directory);
        $models = array_map(
            fn (array $model): array => $this->enrichModel($model, $directory),
            $archiveModels,
        );
        $artifacts = $this->runArtifacts($directory);
        $statuses = array_column($models, 'overallStatus');
        $counts = [
            'pass' => count(array_filter($statuses, fn (string $status): bool => $status === 'pass')),
            'fail' => count(array_filter($statuses, fn (string $status): bool => $status === 'fail')),
            'skip' => count(array_filter($statuses, fn (string $status): bool => $status === 'skip')),
            'unknown' => count(array_filter($statuses, fn (string $status): bool => $status === 'unknown')),
            'total' => count($statuses),
        ];

        return [
            'slug' => basename($directory),
            'name' => $this->humanizeSlug(basename($directory)),
            'date' => $date,
            'sourcePath' => $directory,
            'fingerprint' => $this->fingerprint($summaryContents, $metaContents, $artifacts, $models),
            'metadata' => $meta,
            'routes' => array_values(array_filter(array_map('trim', explode(',', $meta['routes'] ?? '')))),
            'schema' => match (true) {
                ! $hasSummary => 'incomplete',
                count(array_intersect($summary['stageNames'], self::EXTENDED_STAGES)) > 0 => 'extended',
                default => 'legacy',
            },
            'stageNames' => $summary['stageNames'],
            'models' => $models,
            'counts' => $counts,
            'totalDurationSeconds' => array_sum(array_column($models, 'durationSeconds')),
            'artifacts' => $artifacts,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function inferredModels(string $runPath): array
    {
        return collect(File::directories($runPath))
            ->map(fn (string $directory): string => basename($directory))
            ->filter(fn (string $slug): bool => ! str_starts_with($slug, '_') && $this->isSafeSegment($slug))
            ->map(fn (string $slug): array => [
                'slug' => $slug,
                'model' => $slug,
                'displayName' => $slug,
                'durationSeconds' => 0,
                'stages' => [],
                'overallStatus' => 'unknown',
                'failedStages' => [],
            ])
            ->sortBy('slug')
            ->values()
            ->all();
    }

    /**
     * @return array{stageNames: list<string>, models: list<array<string, mixed>>}
     */
    private function parseSummary(string $contents): array
    {
        $lines = array_values(array_filter(
            preg_split('/\R/u', trim($contents)) ?: [],
            fn (string $line): bool => trim($line) !== '',
        ));

        if ($lines === []) {
            return ['stageNames' => [], 'models' => []];
        }

        $columns = array_map(
            fn (string $column): string => $this->normalizeColumn($column),
            explode('|', array_shift($lines)),
        );
        $stageNames = array_values(array_filter(
            $columns,
            fn (string $column): bool => $column !== '' && ! in_array($column, self::NON_STAGE_COLUMNS, true),
        ));
        $models = [];

        foreach ($lines as $line) {
            $values = array_map('trim', explode('|', $line));
            $values = array_pad($values, count($columns), '');
            $row = array_combine($columns, array_slice($values, 0, count($columns)));

            if ($row === false) {
                continue;
            }

            $slug = $row['klasor'] ?? $row['folder'] ?? $row['slug'] ?? '';
            $model = $row['model'] ?? $slug;

            if ($slug === '' && $model === '') {
                continue;
            }

            $rawStatuses = [];

            foreach ($stageNames as $stageName) {
                $rawStatuses[$stageName] = $this->statusFromRawValue($row[$stageName] ?? null);
            }

            $stages = [];

            foreach ($stageNames as $stageName) {
                $status = $rawStatuses[$stageName];

                if ($status !== 'pass' && in_array($stageName, ['export', 'routes', 'runtime'], true) && ($rawStatuses['build'] ?? null) === 'fail') {
                    $status = 'skip';
                } elseif ($status !== 'pass' && in_array($stageName, ['routes', 'runtime'], true) && ($rawStatuses['export'] ?? null) === 'fail') {
                    $status = 'skip';
                }

                $code = is_numeric($row[$stageName] ?? null) ? (int) $row[$stageName] : null;
                $stages[$stageName] = [
                    'status' => $status,
                    'code' => $code,
                    'rawValue' => $row[$stageName] ?? null,
                    'message' => match (true) {
                        $status === 'skip' => 'Önceki aşama başarısız olduğu için çalıştırılmadı.',
                        $stageName === 'opencode' && $code === StageResult::PROVIDER_ERROR_CODE => 'Sağlayıcı hatası: model çağrısı sağlayıcı tarafında başarısız oldu; bu koşu puanlanmaz.',
                        $stageName === 'opencode' && $code === StageResult::NO_SUBMISSION_CODE => 'Teslim yok: model başlangıç şablonunda hiçbir dosyayı değiştirmedi.',
                        default => null,
                    },
                ];
            }

            $duration = (int) ($row['sure_sn'] ?? $row['duration_seconds'] ?? $row['duration'] ?? 0);
            $models[] = [
                'slug' => $slug,
                'model' => $model,
                'displayName' => Str::after($model, '/'),
                'durationSeconds' => max(0, $duration),
                'stages' => $stages,
                'overallStatus' => $this->overallStatus(array_column($stages, 'status')),
                'failedStages' => array_keys(array_filter(
                    $stages,
                    fn (array $stage): bool => $stage['status'] === 'fail',
                )),
            ];
        }

        return ['stageNames' => $stageNames, 'models' => $models];
    }

    /**
     * @return array<string, string>
     */
    private function parseMeta(string $contents): array
    {
        $meta = [];

        foreach (preg_split('/\R/u', $contents) ?: [] as $line) {
            $separator = strpos($line, '=');

            if ($separator === false || $separator === 0) {
                continue;
            }

            $meta[trim(substr($line, 0, $separator))] = trim(substr($line, $separator + 1));
        }

        return $meta;
    }

    /**
     * @param  array<string, mixed>  $model
     * @return array<string, mixed>
     */
    private function enrichModel(array $model, string $runPath): array
    {
        $artifacts = [];
        $sourcePath = $runPath.'/'.$model['slug'];

        if (File::isDirectory($sourcePath)) {
            $artifacts[] = $this->artifact(
                'model:'.$model['slug'].':source',
                'source',
                'Kaynak proje',
                $sourcePath,
                $model['slug'],
            );
        }

        foreach ($model['stages'] as $stageName => &$stage) {
            $logPath = $runPath.'/_logs/'.$model['slug'].'.'.$stageName.'.log';

            if (! File::isFile($logPath)) {
                $stage['logPath'] = null;
                $stage['logSizeBytes'] = null;

                continue;
            }

            $stage['logPath'] = $logPath;
            $stage['logSizeBytes'] = File::size($logPath);
            $artifacts[] = $this->artifact(
                'model:'.$model['slug'].':log:'.$stageName,
                'stage_log',
                $stageName.' logu',
                $logPath,
                $model['slug'],
            );
        }
        unset($stage);

        $model['artifacts'] = $artifacts;

        return $model;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function runArtifacts(string $runPath): array
    {
        $candidates = [
            ['run:summary', 'summary', 'Sonuç özeti', $runPath.'/_summary.tsv'],
            ['run:meta', 'meta', 'Koşu meta verisi', $runPath.'/_meta.txt'],
            ['run:prompt', 'prompt', 'Prompt', $runPath.'/_prompt.txt'],
            ['run:models', 'models', 'Model yapılandırması', $runPath.'/_modeller.conf'],
            ['run:opencode-config', 'config', 'OpenCode yönlendirme ayarları', $runPath.'/_opencode.json'],
            ['run:gallery', 'gallery', 'Gallery', $runPath.'/_gallery'],
            ['run:logs', 'logs', 'Log klasörü', $runPath.'/_logs'],
            ['run:results', 'results', 'Ham sonuçlar', $runPath.'/_results'],
            ['run:runtime', 'runtime', 'Runtime çıktıları', $runPath.'/_runtime'],
            ['run:serve-logs', 'serve_logs', 'Sunucu logları', $runPath.'/_serve_logs'],
        ];
        $artifacts = [];

        foreach ($candidates as [$key, $type, $label, $path]) {
            if (! File::exists($path)) {
                continue;
            }

            $artifacts[] = $this->artifact($key, $type, $label, $path);
        }

        return $artifacts;
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(string $key, string $type, string $label, string $path, ?string $modelSlug = null): array
    {
        $isDirectory = File::isDirectory($path);
        $runsPath = rtrim((string) config('benchforge.runs_path'), DIRECTORY_SEPARATOR);

        return [
            'key' => $key,
            'modelSlug' => $modelSlug,
            'kind' => $isDirectory ? 'directory' : 'file',
            'type' => $type,
            'label' => $label,
            'path' => $path,
            'relativePath' => 'runs/'.ltrim(Str::after($path, $runsPath), DIRECTORY_SEPARATOR),
            'sizeBytes' => $isDirectory ? null : File::size($path),
            'modifiedAt' => CarbonImmutable::createFromTimestamp(File::lastModified($path)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $artifacts
     * @param  list<array<string, mixed>>  $models
     */
    private function fingerprint(string $summary, string $meta, array $artifacts, array $models): string
    {
        $modelArtifacts = collect($models)->flatMap(fn (array $model): array => $model['artifacts'])->all();
        $signatures = collect([...$artifacts, ...$modelArtifacts])
            ->map(fn (array $artifact): array => [
                $artifact['key'],
                $artifact['sizeBytes'],
                $artifact['modifiedAt']->getTimestamp(),
            ])
            ->sortBy(fn (array $signature): string => $signature[0])
            ->values()
            ->all();

        return hash('sha256', $summary."\0".$meta."\0".json_encode($signatures, JSON_THROW_ON_ERROR));
    }

    private function parseDate(?string $value, int $fallbackTimestamp): CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return CarbonImmutable::createFromTimestamp($fallbackTimestamp);
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})$/', $value, $matches) === 1) {
            return CarbonImmutable::create(
                (int) $matches[1],
                (int) $matches[2],
                (int) $matches[3],
                (int) $matches[4],
                (int) $matches[5],
                (int) $matches[6],
                'Europe/Istanbul',
            );
        }

        $months = [
            'oca' => 1,
            'sub' => 2,
            'şub' => 2,
            'mar' => 3,
            'nis' => 4,
            'may' => 5,
            'haz' => 6,
            'tem' => 7,
            'agu' => 8,
            'ağu' => 8,
            'eyl' => 9,
            'eki' => 10,
            'kas' => 11,
            'ara' => 12,
        ];

        if (preg_match('/^(\d{1,2})\s+([^\s]+)\s+(\d{4}).*?([+-]\d{2})\s+(\d{2}):(\d{2}):(\d{2})$/u', $value, $matches) === 1) {
            $month = $months[mb_strtolower($matches[2], 'UTF-8')] ?? null;

            if ($month !== null) {
                return CarbonImmutable::create(
                    (int) $matches[3],
                    $month,
                    (int) $matches[1],
                    (int) $matches[5],
                    (int) $matches[6],
                    (int) $matches[7],
                    $matches[4].':00',
                );
            }
        }

        try {
            return CarbonImmutable::parse($value, 'Europe/Istanbul');
        } catch (Throwable) {
            return CarbonImmutable::createFromTimestamp($fallbackTimestamp);
        }
    }

    private function normalizeColumn(string $column): string
    {
        return Str::of($column)
            ->replaceMatches('/^\x{FEFF}/u', '')
            ->replace(['İ', 'I', 'ı'], 'i')
            ->ascii()
            ->trim()
            ->lower()
            ->replaceMatches('/\s+/', '_')
            ->toString();
    }

    private function statusFromRawValue(?string $rawValue): string
    {
        $value = mb_strtolower(trim((string) $rawValue), 'UTF-8');

        if ($value === '' || in_array($value, ['skip', 'skipped', 'n/a'], true)) {
            return 'skip';
        }

        if (in_array($value, ['0', 'pass', 'passed', 'ok'], true)) {
            return 'pass';
        }

        if (in_array($value, ['fail', 'failed'], true) || preg_match('/^-?\d+$/', $value) === 1) {
            return 'fail';
        }

        return 'unknown';
    }

    /**
     * @param  list<string>  $statuses
     */
    private function overallStatus(array $statuses): string
    {
        if ($statuses === [] || in_array('unknown', $statuses, true)) {
            return 'unknown';
        }

        if (in_array('fail', $statuses, true)) {
            return 'fail';
        }

        if (in_array('skip', $statuses, true)) {
            return 'skip';
        }

        return 'pass';
    }

    private function humanizeSlug(string $slug): string
    {
        return Str::of($slug)
            ->replaceMatches('/[-_]+/', ' ')
            ->title()
            ->toString();
    }

    private function isSafeSegment(string $segment): bool
    {
        return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $segment) === 1;
    }
}
