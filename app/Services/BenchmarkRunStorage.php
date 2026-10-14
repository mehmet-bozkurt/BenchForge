<?php

namespace App\Services;

use App\Exceptions\BenchmarkStorageException;
use App\Models\BenchmarkExecution;
use App\Models\BenchmarkRun;
use App\Models\ModelRunAttempt;
use Closure;
use FilesystemIterator;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

class BenchmarkRunStorage
{
    /**
     * @return array{totalBytes: int, workspaceBytes: int, resultBytes: int, workspaceCount: int}
     */
    public function refresh(BenchmarkRun $benchmarkRun): array
    {
        $runPath = $this->runPath($benchmarkRun);
        $runnerDirectories = $this->runnerDirectories($benchmarkRun);
        $workspaceDirectories = $runPath === null ? [] : $this->workspaceDirectories($runPath);
        $workspaceBytes = array_sum(array_map($this->directoryBytes(...), $workspaceDirectories));
        $runBytes = $runPath === null ? 0 : $this->directoryBytes($runPath);
        $runnerBytes = array_sum(array_map($this->directoryBytes(...), $runnerDirectories));
        $resultBytes = max(0, $runBytes - $workspaceBytes) + $runnerBytes;
        $metrics = [
            'totalBytes' => $workspaceBytes + $resultBytes,
            'workspaceBytes' => $workspaceBytes,
            'resultBytes' => $resultBytes,
            'workspaceCount' => count($workspaceDirectories),
        ];

        $benchmarkRun->forceFill([
            'total_bytes' => $metrics['totalBytes'],
            'workspace_bytes' => $metrics['workspaceBytes'],
            'result_bytes' => $metrics['resultBytes'],
            'workspace_count' => $metrics['workspaceCount'],
            'storage_scanned_at' => now(),
        ])->save();

        return $metrics;
    }

    /**
     * @return array{freedBytes: int, deletedCount: int}
     */
    public function deleteWorkspaces(BenchmarkRun $benchmarkRun): array
    {
        return $this->deleteWithLock($benchmarkRun, 'workspaces', function () use ($benchmarkRun): array {
            $runPath = $this->runPath($benchmarkRun);

            if ($runPath === null) {
                throw new BenchmarkStorageException('Koşu klasörü bulunamadı. Hiçbir dosya silinmedi.');
            }

            $directories = $this->workspaceDirectories($runPath);
            // Previews of models that did not pass every stage live only in their workspace; keep a copy first.
            $this->archivePreviews($runPath, $directories);
            $freedBytes = array_sum(array_map($this->directoryBytes(...), $directories));
            $failed = [];

            foreach ($directories as $directory) {
                if (! $this->deleteDirectory($directory)) {
                    $failed[] = basename($directory);
                }
            }

            if ($failed !== []) {
                $this->refresh($benchmarkRun);

                throw new BenchmarkStorageException('Bazı çalışma alanları silinemedi: '.implode(', ', $failed));
            }

            DB::transaction(function () use ($benchmarkRun): void {
                $benchmarkRun->artifacts()->where('type', 'source')->delete();
                $benchmarkRun->forceFill(['workspace_deleted_at' => now()])->save();
            });
            $this->refresh($benchmarkRun);

            return ['freedBytes' => $freedBytes, 'deletedCount' => count($directories)];
        });
    }

    /**
     * Removes what can be reinstalled (node_modules, .next) from every model workspace and keeps the model's
     * source and export. Previews that exist only in a workspace are archived to _gallery first.
     *
     * @return array{freedBytes: int, slimmedCount: int, archivedPreviews: int}
     */
    public function slimWorkspaces(BenchmarkRun $benchmarkRun, bool $dryRun = false): array
    {
        $slim = function () use ($benchmarkRun, $dryRun): array {
            $runPath = $this->runPath($benchmarkRun);

            if ($runPath === null) {
                return ['freedBytes' => 0, 'slimmedCount' => 0, 'archivedPreviews' => 0];
            }

            $directories = $this->workspaceDirectories($runPath);
            $targets = [];

            foreach ($directories as $directory) {
                foreach (['node_modules', '.next'] as $name) {
                    $path = $directory.DIRECTORY_SEPARATOR.$name;

                    if (is_dir($path) && ! is_link($path) && realpath($path) === $path) {
                        $targets[] = $path;
                    }
                }
            }

            $freedBytes = array_sum(array_map($this->directoryBytes(...), $targets));
            $slimmed = count(array_unique(array_map('dirname', $targets)));

            if ($dryRun) {
                return ['freedBytes' => $freedBytes, 'slimmedCount' => $slimmed, 'archivedPreviews' => $this->archivePreviews($runPath, $directories, true)];
            }

            $archived = $this->archivePreviews($runPath, $directories);

            foreach ($targets as $target) {
                if (! $this->deleteDirectory($target)) {
                    throw new BenchmarkStorageException('Bağımlılık klasörü silinemedi: '.basename(dirname($target)).'/'.basename($target));
                }
            }

            $this->refresh($benchmarkRun);

            return ['freedBytes' => $freedBytes, 'slimmedCount' => $slimmed, 'archivedPreviews' => $archived];
        };

        return $this->deleteWithLock($benchmarkRun, $dryRun ? 'slim-dry-run' : 'slim', $slim);
    }

    /** @return array{freedBytes: int} */
    public function deleteResults(BenchmarkRun $benchmarkRun): array
    {
        return $this->deleteWithLock($benchmarkRun, 'results', function () use ($benchmarkRun): array {
            $runPath = $this->runPath($benchmarkRun);

            if ($runPath === null) {
                throw new BenchmarkStorageException('Koşu klasörü bulunamadı. Hiçbir dosya silinmedi.');
            }

            $workspaceDirectories = $this->workspaceDirectories($runPath);
            $workspacePaths = array_fill_keys($workspaceDirectories, true);
            $runnerDirectories = $this->runnerDirectories($benchmarkRun, forDeletion: true);
            $freedBytes = max(0, $this->directoryBytes($runPath) - array_sum(array_map($this->directoryBytes(...), $workspaceDirectories)))
                + array_sum(array_map($this->directoryBytes(...), $runnerDirectories));
            $failed = [];
            $items = new FilesystemIterator($runPath, FilesystemIterator::SKIP_DOTS);

            foreach ($items as $item) {
                if (isset($workspacePaths[$item->getPathname()])) {
                    continue;
                }

                if ($item->isDir() && ! $item->isLink()) {
                    $this->deleteDirectory($item->getPathname());
                } else {
                    File::delete($item->getPathname());
                }

                clearstatcache(true, $item->getPathname());

                if (File::exists($item->getPathname()) || is_link($item->getPathname())) {
                    $failed[] = $item->getBasename();
                }
            }

            foreach ($runnerDirectories as $runnerPath) {
                if (! $this->deleteDirectory($runnerPath)) {
                    $failed[] = basename($runnerPath);
                }
            }

            unset($items);

            if ($failed !== []) {
                throw new BenchmarkStorageException('Bazı sonuç dosyaları silinemedi: '.implode(', ', $failed));
            }

            DB::transaction(function () use ($benchmarkRun, $workspaceDirectories): void {
                BenchmarkExecution::query()->where('run_id', $benchmarkRun->slug)->delete();
                $benchmarkRun->artifacts()->delete();
                $benchmarkRun->modelRuns()->delete();

                foreach ($workspaceDirectories as $directory) {
                    $slug = basename($directory);
                    $modelRun = $benchmarkRun->modelRuns()->create([
                        'slug' => $slug,
                        'model' => $slug,
                        'status' => 'unknown',
                        'duration_seconds' => 0,
                        'failed_stages' => [],
                    ]);
                    $benchmarkRun->artifacts()->create([
                        'model_run_id' => $modelRun->id,
                        'artifact_key' => 'model:'.$slug.':source',
                        'kind' => 'directory',
                        'type' => 'source',
                        'label' => 'Kaynak proje',
                        'path' => $directory,
                        'relative_path' => 'runs/'.$benchmarkRun->slug.'/'.$slug,
                        'size_bytes' => null,
                        'modified_at' => now(),
                    ]);
                }

                $benchmarkRun->forceFill([
                    'source_fingerprint' => hash('sha256', 'results-deleted\0'.implode('\0', $workspaceDirectories)),
                    'schema' => 'incomplete',
                    'metadata' => null,
                    'routes' => [],
                    'stage_names' => [],
                    'model_count' => count($workspaceDirectories),
                    'passed_count' => 0,
                    'failed_count' => 0,
                    'skipped_count' => 0,
                    'unknown_count' => count($workspaceDirectories),
                    'total_duration_seconds' => 0,
                    'synced_at' => now(),
                ])->save();
            });

            $this->refresh($benchmarkRun);

            return ['freedBytes' => $freedBytes];
        });
    }

    /** @return array{freedBytes: int} */
    public function deleteRun(BenchmarkRun $benchmarkRun): array
    {
        return $this->deleteWithLock($benchmarkRun, 'run', function () use ($benchmarkRun): array {
            $runPath = $this->runPath($benchmarkRun);
            $runnerDirectories = $this->runnerDirectories($benchmarkRun, forDeletion: true);
            $freedBytes = ($runPath === null ? 0 : $this->directoryBytes($runPath))
                + array_sum(array_map($this->directoryBytes(...), $runnerDirectories));

            foreach (array_filter([$runPath, ...$runnerDirectories]) as $directory) {
                if (! $this->deleteDirectory($directory)) {
                    throw new BenchmarkStorageException('Koşu dosyalarının tamamı silinemedi ('.basename($directory).'). Veritabanı kaydı korundu; bazı dosyalar kaldırılmış olabilir.');
                }
            }

            DB::transaction(function () use ($benchmarkRun): void {
                BenchmarkExecution::query()->where('run_id', $benchmarkRun->slug)->delete();
                $benchmarkRun->delete();
            });

            return ['freedBytes' => $freedBytes];
        });
    }

    /**
     * @param  Closure(): array<string, int>  $delete
     * @return array<string, int>
     */
    private function deleteWithLock(BenchmarkRun $benchmarkRun, string $operation, Closure $delete): array
    {
        $startedAt = microtime(true);
        $context = ['run_id' => $benchmarkRun->id, 'run_slug' => $benchmarkRun->slug, 'operation' => $operation];
        Log::info('benchmark.storage.deletion.started', $context);

        try {
            $result = Cache::lock('benchforge:storage:'.$benchmarkRun->id, 300)
                ->block(5, function () use ($benchmarkRun, $delete): array {
                    $this->ensureInactive($benchmarkRun);

                    return $delete();
                });

            Log::info('benchmark.storage.deletion.completed', $context + $result + ['duration_ms' => (int) ((microtime(true) - $startedAt) * 1000)]);

            return $result;
        } catch (Throwable $exception) {
            $failure = match (true) {
                $exception instanceof BenchmarkStorageException => $exception,
                $exception instanceof LockTimeoutException => new BenchmarkStorageException('Bu koşu için başka bir depolama işlemi devam ediyor.', previous: $exception),
                default => new BenchmarkStorageException('Silme işlemi tamamlanamadı. Bazı dosyalar kaldırılmış olabilir; hata uygulama günlüğüne kaydedildi.', previous: $exception),
            };

            Log::warning('benchmark.storage.deletion.failed', $context + [
                'reason' => $failure->getMessage(),
                'exception_type' => $exception::class,
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);

            throw $failure;
        }
    }

    /**
     * Copies a workspace's static export to _gallery/<model> when no archived preview exists yet.
     *
     * @param  list<string>  $workspaces
     */
    private function archivePreviews(string $runPath, array $workspaces, bool $dryRun = false): int
    {
        $archived = 0;
        $gallery = $runPath.DIRECTORY_SEPARATOR.'_gallery';

        foreach ($workspaces as $workspace) {
            $export = $workspace.DIRECTORY_SEPARATOR.'out';
            $target = $gallery.DIRECTORY_SEPARATOR.basename($workspace);

            if (! is_file($export.DIRECTORY_SEPARATOR.'index.html') || is_link($export) || file_exists($target) || is_link($target)) {
                continue;
            }

            if (! $dryRun) {
                if (is_link($gallery)) {
                    throw new BenchmarkStorageException('Önizleme arşivi klasörü güvenli değil.');
                }

                File::ensureDirectoryExists($gallery);

                if (! File::copyDirectory($export, $target)) {
                    throw new BenchmarkStorageException('Önizleme arşive kopyalanamadı: '.basename($workspace).'. Hiçbir klasör silinmedi.');
                }
            }

            $archived++;
        }

        return $archived;
    }

    private function deleteDirectory(string $directory): bool
    {
        clearstatcache(true, $directory);

        if (is_link($directory) || (File::exists($directory) && realpath($directory) !== $directory)) {
            throw new BenchmarkStorageException('Silme sırasında klasör yolu değişti. Güvenli olmayan hedef reddedildi.');
        }

        Log::info('benchmark.storage.deletion.directory', ['directory' => basename($directory)]);
        File::deleteDirectory($directory);
        clearstatcache(true, $directory);

        return ! File::exists($directory) && ! is_link($directory);
    }

    private function ensureInactive(BenchmarkRun $benchmarkRun): void
    {
        $active = BenchmarkExecution::query()
            ->where('run_id', $benchmarkRun->slug)
            ->whereIn('status', BenchmarkExecution::ACTIVE_STATUSES)
            ->exists();

        if ($active) {
            throw new BenchmarkStorageException('Aktif bir benchmarkın dosyaları silinemez. Önce koşunun tamamlanmasını veya durdurulmasını bekleyin.');
        }

        $activeAttempt = ModelRunAttempt::query()
            ->whereHas('modelRun', fn ($query) => $query->where('benchmark_run_id', $benchmarkRun->id))
            ->whereIn('status', ModelRunAttempt::ACTIVE_STATUSES)
            ->exists();

        if ($activeAttempt) {
            throw new BenchmarkStorageException('Aktif bir kurtarma denemesinin dosyaları silinemez. Önce denemenin tamamlanmasını veya durdurulmasını bekleyin.');
        }
    }

    private function runPath(BenchmarkRun $benchmarkRun): ?string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $benchmarkRun->slug) !== 1) {
            throw new BenchmarkStorageException('Güvenli olmayan koşu yolu reddedildi.');
        }

        $runsRoot = realpath((string) config('benchforge.runs_path'));

        if ($runsRoot === false || ! File::isDirectory($runsRoot)) {
            throw new BenchmarkStorageException('Benchmark runs klasörü bulunamadı.');
        }

        $candidate = $runsRoot.DIRECTORY_SEPARATOR.$benchmarkRun->slug;

        if (is_link($candidate)) {
            throw new BenchmarkStorageException('Bağlantı olarak tanımlanmış koşu klasörü silinemez.');
        }

        $resolved = realpath($candidate);

        if ($resolved === false) {
            return null;
        }

        if (! File::isDirectory($resolved) || dirname($resolved) !== $runsRoot) {
            throw new BenchmarkStorageException('Koşu klasörü izin verilen runs alanının dışında.');
        }

        return $resolved;
    }

    /** @return list<string> */
    private function runnerDirectories(BenchmarkRun $benchmarkRun, bool $forDeletion = false): array
    {
        $execution = BenchmarkExecution::query()->where('run_id', $benchmarkRun->slug)->first();
        $runnerRoot = realpath((string) config('benchforge.runner_path'));

        if ($runnerRoot === false || ! File::isDirectory($runnerRoot)) {
            if ($forDeletion && $execution !== null) {
                throw new BenchmarkStorageException('Koşucu depolama klasörü bulunamadı. Koşu dosyaları ve kayıtları silinmedi.');
            }

            return [];
        }

        $directories = [];

        if ($execution !== null) {
            $path = dirname($execution->state_path);
            $candidate = realpath($path);

            if (is_link($path) || ($candidate !== false && (dirname($candidate) !== $runnerRoot
                || ! File::isDirectory($candidate)
                || preg_match('/^'.preg_quote($benchmarkRun->slug, '/').'-[a-z0-9]+$/i', basename($candidate)) !== 1))) {
                throw new BenchmarkStorageException('Koşucu günlük yolu güvenli depolama alanının dışında.');
            }

            if ($candidate !== false) {
                $directories[] = $candidate;
            }
        }

        $items = new FilesystemIterator($runnerRoot, FilesystemIterator::SKIP_DOTS);

        foreach ($items as $item) {
            if ($item->isDir() && ! $item->isLink()
                && preg_match('/^'.preg_quote($benchmarkRun->slug, '/').'-[a-z0-9]{8}$/', $item->getBasename()) === 1) {
                $directories[] = $item->getPathname();
            }
        }

        $directories = array_values(array_unique($directories));
        sort($directories, SORT_STRING);

        return $directories;
    }

    /** @return list<string> */
    private function workspaceDirectories(string $runPath): array
    {
        $directories = [];
        $items = new FilesystemIterator($runPath, FilesystemIterator::SKIP_DOTS);

        foreach ($items as $item) {
            if ($item->isDir() && ! $item->isLink() && ! str_starts_with($item->getBasename(), '_')) {
                $directories[] = $item->getPathname();
            }
        }

        sort($directories, SORT_STRING);

        return $directories;
    }

    private function directoryBytes(string $path): int
    {
        $bytes = 0;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD,
            );

            /** @var SplFileInfo $item */
            foreach ($iterator as $item) {
                if ($item->isLink()) {
                    $stat = @lstat($item->getPathname());
                    $bytes += is_array($stat) ? (int) ($stat['size'] ?? 0) : 0;
                } elseif ($item->isFile()) {
                    $bytes += $item->getSize();
                }
            }
        } catch (Throwable) {
            throw new BenchmarkStorageException('Dosya boyutları güvenilir biçimde okunamadı.');
        }

        return $bytes;
    }
}
