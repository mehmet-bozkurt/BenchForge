<?php

namespace App\Services;

use App\Exceptions\BenchmarkRunnerException;
use App\Models\BenchmarkExecution;
use App\Models\BenchmarkPrompt;
use App\Models\BenchmarkRun;
use App\Models\ModelRunAttempt;
use App\Models\OpenCodeGoModel;
use App\Models\OpenRouterModel;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class BenchmarkRunner
{
    private const MAX_LOG_TAIL_BYTES = 32768;

    private const RUN_ID_PATTERN = '/^[a-z0-9][a-z0-9-]{2,63}$/';

    private const ROUTE_PATTERN = "/^\/[A-Za-z0-9\-._~!$&'()*+,;=:@%\/]*$/";

    public const CUSTOM_MODEL_LIMIT = ModelSelection::BENCHMARK_MODEL_LIMIT;

    private const STATIC_EXPORT_CONTRACT = <<<'PROMPT'

---
BenchForge teslim sözleşmesi:
- `npm run lint` ve `npm run build` başarılı olmalıdır.
- Next.js static export üretilmeli; `out/index.html` ve `out/_next` bulunmalıdır.
- `NEXT_PUBLIC_BASE_PATH` ile verilen model klasörü için `basePath` ve asset yolları doğru çalışmalıdır.
- İstenen tüm rotalar static export içinde doğrudan açılabilmelidir.
- Kalıcı bir geliştirme sunucusu başlatmayın; teslimi proje dosyalarında bırakın.
PROMPT;

    public function __construct(
        private SyncBenchmarkArchive $syncBenchmarkArchive,
        private BenchmarkPreflight $benchmarkPreflight,
        private ModelSelection $modelSelection,
        private ModelRunContinuationRunner $modelRunContinuationRunner,
        private BenchmarkProcessEnvironment $processEnvironment,
    ) {}

    /**
     * @return array{
     *     prompts: list<array{value: string, label: string, source: string}>,
     *     configs: list<array{value: string, label: string, modelCount: int, ports: list<int>, providers: list<string>, maxParallel: int}>,
     *     openRouterModels: Collection<int, OpenRouterModel>,
     *     openCodeGoModels: Collection<int, OpenCodeGoModel>,
     *     catalog: array{count: int, lastSyncedAt: ?CarbonImmutable},
     *     goCatalog: array{count: int, lastSyncedAt: ?CarbonImmutable},
     *     suggestedRoutes: list<string>
     * }
     */
    public function options(): array
    {
        $root = $this->projectRoot();
        $prompts = collect($this->listFiles($root.'/prompts', '.txt'))
            ->map(fn (string $filename): array => [
                'value' => 'prompts/'.$filename,
                'label' => $this->titleFromFilename($filename),
                'source' => 'file',
            ])
            ->concat(
                BenchmarkPrompt::query()
                    ->where('active', true)
                    ->orderBy('title')
                    ->orderBy('id')
                    ->get()
                    ->map(fn (BenchmarkPrompt $prompt): array => [
                        'value' => 'library:'.$prompt->id,
                        'label' => $prompt->title,
                        'source' => 'library',
                    ]),
            )
            ->values()
            ->all();

        return [
            'prompts' => $prompts,
            'configs' => $this->modelSelection->groupOptions(),
            ...$this->modelSelection->catalogOptions(),
            'suggestedRoutes' => ['/'],
            'algorithmTasks' => $this->algorithmTasks(),
        ];
    }

    /**
     * Imported algorithm tasks (see engine/tools/import-exercism.mjs) under the data root's algorithm-tasks folder.
     *
     * @return list<array{value: string, label: string, testCount: int, directory: string}>
     */
    public function algorithmTasks(): array
    {
        $directory = $this->projectRoot().'/algorithm-tasks';
        $realDirectory = realpath($directory);

        if ($realDirectory === false || ! is_dir($realDirectory)) {
            return [];
        }

        $tasks = [];

        foreach (File::directories($realDirectory) as $taskDirectory) {
            $slug = basename($taskDirectory);
            $realTask = realpath($taskDirectory);

            if (is_link($taskDirectory) || $realTask === false || ! $this->isWithin($realDirectory, $realTask)
                || preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $slug) !== 1
                || ! is_file($realTask.'/prompt.txt') || ! is_dir($realTask.'/workspace') || ! is_file($realTask.'/task.json')) {
                continue;
            }

            $task = json_decode((string) file_get_contents($realTask.'/task.json'), true);

            if (! is_array($task) || ($task['slug'] ?? null) !== $slug || ! is_string($task['solutionFile'] ?? null) || ! is_string($task['testFile'] ?? null)) {
                continue;
            }

            $tasks[] = [
                'value' => $slug,
                'label' => is_string($task['title'] ?? null) && $task['title'] !== '' ? $task['title'] : $this->titleFromFilename($slug),
                'testCount' => max(0, (int) ($task['testCount'] ?? 0)),
                'directory' => $realTask,
            ];
        }

        usort($tasks, fn (array $left, array $right): int => strcoll($left['label'], $right['label']));

        return $tasks;
    }

    /**
     * @return array{
     *     ready: bool,
     *     checkedAt: string,
     *     summary: string,
     *     checks: list<array{key: string, label: string, status: 'pass'|'fail', message: string}>
     * }
     */
    public function preflight(?string $selectedConfig, array $selectedModels = [], string $modelMode = 'group', string $routingMode = 'auto', array $fixedProviders = [], string $taskType = BenchmarkRun::DEFAULT_TASK_TYPE): array
    {
        $parsedConfig = match ($modelMode) {
            'custom' => $this->customModelSelection($selectedModels, $routingMode, $fixedProviders),
            'opencode_go' => $this->modelSelection->openCodeGo($selectedModels),
            default => $this->modelSelection->group((string) $selectedConfig, $this->options()['configs']),
        };

        return $this->benchmarkPreflight->inspect(
            $parsedConfig['option']['ports'],
            $parsedConfig['option']['providers'],
            ...($taskType === 'algorithm' ? [$taskType] : []),
        );
    }

    /** @return array<string, mixed> */
    public function connectionStatus(): array
    {
        return $this->benchmarkPreflight->connections();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function start(array $input): BenchmarkExecution
    {
        try {
            return Cache::lock('benchforge:runner:start', 120)
                ->block(5, fn (): BenchmarkExecution => $this->startLocked($input));
        } catch (LockTimeoutException) {
            throw new BenchmarkRunnerException('Başka bir benchmark başlatma işlemi devam ediyor.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function startLocked(array $input): BenchmarkExecution
    {
        app(BenchForgeSettings::class)->apply();

        $root = $this->projectRoot();
        $activeExecution = BenchmarkExecution::query()
            ->whereIn('status', BenchmarkExecution::ACTIVE_STATUSES)
            ->latest('id')
            ->first();

        if ($activeExecution !== null) {
            $this->reconcile($activeExecution);
            $activeExecution->refresh();

            if ($activeExecution->isActive()) {
                throw new BenchmarkRunnerException("{$activeExecution->run_id} koşusu halen devam ediyor.");
            }
        }

        $this->modelRunContinuationRunner->reconcileActiveAttempts();
        $activeAttempt = ModelRunAttempt::query()
            ->whereIn('status', ModelRunAttempt::ACTIVE_STATUSES)
            ->latest('id')
            ->first();

        if ($activeAttempt !== null) {
            throw new BenchmarkRunnerException('Bir model kurtarma denemesi halen devam ediyor.');
        }

        $runId = trim($input['run_id']);

        if (preg_match(self::RUN_ID_PATTERN, $runId) !== 1) {
            throw new BenchmarkRunnerException('Koşu adı 3–64 karakter olmalı; yalnızca küçük harf, sayı ve tire kullanın.');
        }

        $runPath = $root.'/runs/'.$runId;

        if (! $this->isWithin($root.'/runs', $runPath) || File::exists($runPath)) {
            throw new BenchmarkRunnerException('Bu koşu adı zaten kullanılıyor. Mevcut sonuçların üzerine yazılmaz.');
        }

        $options = $this->options();
        $taskType = ($input['task_type'] ?? BenchmarkRun::DEFAULT_TASK_TYPE) === 'algorithm' ? 'algorithm' : BenchmarkRun::DEFAULT_TASK_TYPE;
        $algorithmTask = null;

        if ($taskType === 'algorithm') {
            $algorithmTask = collect($options['algorithmTasks'] ?? [])->firstWhere('value', (string) ($input['algorithm_task'] ?? ''));

            if ($algorithmTask === null) {
                throw new BenchmarkRunnerException('Listeden geçerli bir algoritma görevi seçin.');
            }

            $promptSelection = [
                'absolutePath' => $algorithmTask['directory'].'/prompt.txt',
                'reference' => 'algorithm:'.$algorithmTask['value'],
                'content' => null,
            ];
            $routes = [];
        } else {
            $promptSelection = $this->resolvePromptSelection($input, $options['prompts']);
            $routes = $this->validateRoutes($input['routes']);
        }

        $parsedConfig = $this->modelSelection->resolve($input, refreshProviders: true, groupOptions: $options['configs']);
        $parsedConfig = $this->modelSelection->withMaxParallel($parsedConfig, $input['max_parallel'] ?? 1);
        $preflight = $this->benchmarkPreflight->inspect(
            $parsedConfig['option']['ports'],
            $parsedConfig['option']['providers'],
            ...($taskType === 'algorithm' ? [$taskType] : []),
        );

        if (! $preflight['ready']) {
            $failedChecks = collect($preflight['checks'])
                ->where('status', 'fail')
                ->pluck('label')
                ->implode(', ');

            throw new BenchmarkRunnerException("Sistem ön kontrolü başarısız: {$failedChecks}.");
        }

        // Algoritma görevleri sunucu başlatmaz; model portlarının boş olması gerekmez.
        foreach ($taskType === 'algorithm' ? [] : $parsedConfig['option']['ports'] as $port) {
            $this->ensurePortAvailable($port);
        }

        $runnerDirectory = $this->runnerDirectory();
        File::ensureDirectoryExists($runnerDirectory, 0700, true);
        $lockPath = $runnerDirectory.'/active.lock';

        if (File::exists($lockPath)) {
            File::delete($lockPath);
        }

        $lockHandle = @fopen($lockPath, 'x');

        if ($lockHandle === false) {
            throw new BenchmarkRunnerException('Başka bir benchmark başlatma işlemi devam ediyor.');
        }

        fwrite($lockHandle, (string) getmypid());
        fclose($lockHandle);

        $executionDirectory = $runnerDirectory.'/'.$runId.'-'.Str::lower(Str::random(8));
        File::ensureDirectoryExists($executionDirectory, 0700, true);
        $statePath = $executionDirectory.'/state.json';
        $logPath = $executionDirectory.'/runner.log';
        $jobPath = $executionDirectory.'/job.json';
        $execution = null;

        try {
            $promptPath = $this->materializePrompt($promptSelection, $executionDirectory);
            $configPath = $this->materializeModelConfig($parsedConfig, $executionDirectory);
            $openCodeConfigPath = $this->materializeOpenCodeConfig($parsedConfig, $executionDirectory);
            $execution = BenchmarkExecution::query()->create([
                'run_id' => $runId,
                'status' => 'starting',
                'prompt_path' => $promptSelection['reference'],
                'config_path' => $parsedConfig['reference'],
                'routes' => $routes,
                'model_count' => $parsedConfig['option']['modelCount'],
                'phase' => 'Koşucu başlatılıyor',
                'message' => 'Benchmark arka plan süreci hazırlanıyor.',
                'state_path' => $statePath,
                'log_path' => $logPath,
                'started_at' => now(),
            ]);

            $job = [
                'statePath' => $statePath,
                'lockPath' => $lockPath,
                'logPath' => $logPath,
                'projectRoot' => $root,
                'scriptPath' => $this->configuredPath('benchmark_script'),
                'shellBinary' => $this->shellBinary(),
                'runId' => $runId,
                'promptPath' => $promptPath,
                'promptRelative' => $promptSelection['reference'],
                'configPath' => $configPath,
                'configRelative' => $parsedConfig['reference'],
                'openCodeConfigPath' => $openCodeConfigPath,
                'routes' => $routes,
                'taskType' => $taskType,
                'algorithmTaskDirectory' => $algorithmTask['directory'] ?? null,
                'modelCount' => $parsedConfig['option']['modelCount'],
                'startedAt' => now()->toIso8601String(),
            ];
            $written = file_put_contents(
                $jobPath,
                json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL,
                LOCK_EX,
            );

            if ($written === false) {
                throw new BenchmarkRunnerException('Koşucu görev dosyası oluşturulamadı.');
            }

            $launcher = new Process([
                $this->nodeBinary(),
                base_path('bin/benchmark-launcher.mjs'),
                base_path('bin/benchmark-worker.mjs'),
                $jobPath,
            ], base_path());
            $launcher->setEnv($this->runnerEnvironment());
            $launcher->setTimeout(10);
            $launcher->mustRun();

            $runnerPid = (int) trim($launcher->getOutput());

            if ($runnerPid <= 1) {
                throw new BenchmarkRunnerException('Koşucu işlem kimliği alınamadı.');
            }

            $execution->update(['runner_pid' => $runnerPid]);

            for ($attempt = 0; $attempt < 30; $attempt++) {
                usleep(50_000);
                $state = $this->readState($statePath);

                if (($state['runnerPid'] ?? null) === $runnerPid && ($state['runId'] ?? null) === $runId) {
                    return $this->reconcile($execution);
                }
            }

            throw new BenchmarkRunnerException('Koşucu zamanında hazır duruma gelemedi.');
        } catch (Throwable $exception) {
            if ($execution?->runner_pid && $this->isRunnerProcess($execution->runner_pid) && function_exists('posix_kill')) {
                posix_kill(-$execution->runner_pid, SIGTERM);
            }

            File::delete([$lockPath, $jobPath]);

            if ($execution !== null) {
                $execution->update([
                    'status' => 'failed',
                    'phase' => 'Başlatma hatası',
                    'message' => $exception->getMessage(),
                    'finished_at' => now(),
                ]);
            }

            if ($exception instanceof BenchmarkRunnerException) {
                throw $exception;
            }

            report($exception);

            throw new BenchmarkRunnerException('Benchmark başlatılamadı. Uygulama günlüğünü kontrol edin.');
        }
    }

    public function stop(): BenchmarkExecution
    {
        $execution = BenchmarkExecution::query()
            ->whereIn('status', BenchmarkExecution::ACTIVE_STATUSES)
            ->latest('id')
            ->first();

        if ($execution === null) {
            throw new BenchmarkRunnerException('Durdurulabilecek aktif bir benchmark yok.');
        }

        $execution = $this->reconcile($execution);

        if (! $execution->isActive() || ! $this->isRunnerProcess($execution->runner_pid)) {
            throw new BenchmarkRunnerException('Koşucu kimliği doğrulanamadı; hiçbir sürece sinyal gönderilmedi.');
        }

        $state = $this->readState($execution->state_path) ?? [];
        $state['status'] = 'stopping';
        $state['message'] = 'Benchmark ve ona bağlı süreçler durduruluyor.';
        $this->writeState($execution->state_path, $state);
        $execution->update([
            'status' => 'stopping',
            'phase' => 'Güvenli biçimde durduruluyor',
            'message' => $state['message'],
        ]);

        if (! function_exists('posix_kill') || ! posix_kill(-$execution->runner_pid, SIGTERM)) {
            throw new BenchmarkRunnerException('Koşucuya durdurma sinyali gönderilemedi.');
        }

        return $execution->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $execution = BenchmarkExecution::query()->latest('id')->first();

        if ($execution === null) {
            return $this->idleSnapshot();
        }

        $execution = $this->reconcile($execution);
        $outcome = $this->benchmarkOutcome($execution);

        return [
            'status' => $execution->status,
            'active' => $execution->isActive(),
            'runId' => $execution->run_id,
            'prompt' => $execution->prompt_path,
            'config' => $execution->config_path,
            'routes' => $execution->routes,
            'startedAt' => $execution->started_at?->toIso8601String(),
            'finishedAt' => $execution->finished_at?->toIso8601String(),
            'exitCode' => $execution->exit_code,
            'message' => $this->snapshotMessage($execution, $outcome),
            'logTail' => $this->readLogTail($execution->log_path),
            'outcome' => $outcome,
            'progress' => [
                'completedModels' => $execution->completed_model_count,
                'totalModels' => $execution->model_count,
                'percent' => $execution->progress_percent,
                'phase' => $execution->phase,
            ],
        ];
    }

    /**
     * @return array{
     *     status: 'pass'|'fail'|'pending'|'unknown'|'unavailable',
     *     label: string,
     *     summary: string,
     *     available: bool,
     *     totalModels: int,
     *     passedModels: int,
     *     failedModels: int,
     *     otherModels: int
     * }
     */
    private function benchmarkOutcome(BenchmarkExecution $execution): array
    {
        if ($execution->isActive()) {
            return $this->emptyOutcome(
                'pending',
                'Değerlendirme sürüyor',
                'Model sonuçları koşu tamamlandığında kesinleşecek.',
            );
        }

        if ($execution->status !== 'completed') {
            return $this->emptyOutcome(
                'unavailable',
                'Sonuç üretilemedi',
                'Koşucu tamamlanmadığı için benchmark sonucu hesaplanamadı.',
            );
        }

        $benchmarkRun = BenchmarkRun::query()->where('slug', $execution->run_id)->first();

        if ($benchmarkRun === null) {
            return $this->emptyOutcome(
                'unknown',
                'Sonuç bekleniyor',
                'Koşu tamamlandı ancak sonuç raporu henüz arşivde bulunamadı.',
            );
        }

        $otherModels = $benchmarkRun->skipped_count + $benchmarkRun->unknown_count;
        $allPassed = $benchmarkRun->model_count > 0
            && $benchmarkRun->passed_count === $benchmarkRun->model_count;
        $status = match (true) {
            $benchmarkRun->failed_count > 0 => 'fail',
            $allPassed => 'pass',
            default => 'unknown',
        };

        return [
            'status' => $status,
            'label' => match ($status) {
                'pass' => 'Tüm kontroller geçti',
                'fail' => 'Kontrollerde başarısızlık var',
                default => 'İnceleme gerekiyor',
            },
            'summary' => "{$benchmarkRun->passed_count}/{$benchmarkRun->model_count} model tüm aşamalardan geçti.",
            'available' => true,
            'totalModels' => $benchmarkRun->model_count,
            'passedModels' => $benchmarkRun->passed_count,
            'failedModels' => $benchmarkRun->failed_count,
            'otherModels' => $otherModels,
        ];
    }

    /**
     * @param  array{status: string, failedModels: int}  $outcome
     */
    private function snapshotMessage(BenchmarkExecution $execution, array $outcome): ?string
    {
        if ($execution->status !== 'completed') {
            return $execution->message;
        }

        return match ($outcome['status']) {
            'pass' => 'Koşucu sorunsuz tamamlandı ve bütün benchmark kontrolleri geçti.',
            'fail' => "Koşucu sorunsuz tamamlandı; {$outcome['failedModels']} modelde başarısız doğrulama var.",
            'unknown' => 'Koşucu tamamlandı; sonuç raporunda incelenmesi gereken belirsiz aşamalar var.',
            default => $execution->message,
        };
    }

    /**
     * @return array{
     *     status: 'pending'|'unknown'|'unavailable',
     *     label: string,
     *     summary: string,
     *     available: false,
     *     totalModels: 0,
     *     passedModels: 0,
     *     failedModels: 0,
     *     otherModels: 0
     * }
     */
    private function emptyOutcome(string $status, string $label, string $summary): array
    {
        return [
            'status' => $status,
            'label' => $label,
            'summary' => $summary,
            'available' => false,
            'totalModels' => 0,
            'passedModels' => 0,
            'failedModels' => 0,
            'otherModels' => 0,
        ];
    }

    private function reconcile(BenchmarkExecution $execution): BenchmarkExecution
    {
        $state = $this->readState($execution->state_path);
        $status = is_string($state['status'] ?? null) ? $state['status'] : $execution->status;
        $runnerPid = (int) ($state['runnerPid'] ?? $execution->runner_pid);

        if ($execution->isActive() && ! $this->isRunnerProcess($runnerPid)) {
            // The worker may have written its final state between the first read and the liveness check.
            $state = $this->readState($execution->state_path) ?? $state;
            $status = is_string($state['status'] ?? null) ? $state['status'] : $execution->status;
            $summaryExists = File::exists($this->projectRoot().'/runs/'.$execution->run_id.'/_summary.tsv');
            $stopRequested = in_array($status, ['stopping', 'stopped'], true) || $execution->status === 'stopping';
            $workerStatus = $status;
            $status = match (true) {
                $summaryExists => 'completed',
                $stopRequested => 'stopped',
                default => 'failed',
            };
            $workerReportedOutcome = $workerStatus === $status
                && filled($state['finishedAt'] ?? null)
                && filled($state['message'] ?? null);
            $state = array_merge($state ?? [], [
                'status' => $status,
                'finishedAt' => $state['finishedAt'] ?? now()->toIso8601String(),
                'message' => match (true) {
                    $workerReportedOutcome => $state['message'],
                    $status === 'completed' => 'Benchmark tamamlandı ve özet dosyası oluşturuldu.',
                    $status === 'stopped' => 'Benchmark kullanıcı tarafından durduruldu.',
                    default => 'Koşucu beklenmedik biçimde kapandı; ayrıntılar çalışma günlüğünde.',
                },
            ]);
            $this->writeState($execution->state_path, $state);
            File::delete($this->runnerDirectory().'/active.lock');
        }

        $completedModels = $this->completedModelCount($execution->run_id);
        $percent = $execution->model_count > 0
            ? min(100, (int) round(($completedModels / $execution->model_count) * 100))
            : 0;
        $logTail = $this->readLogTail($execution->log_path);

        $execution->update([
            'status' => $status,
            'completed_model_count' => $completedModels,
            'progress_percent' => $status === 'completed' ? 100 : $percent,
            'phase' => $this->progressPhase($status, $logTail, $completedModels),
            'message' => $state['message'] ?? $execution->message,
            'runner_pid' => $state['runnerPid'] ?? $execution->runner_pid,
            'benchmark_pid' => $state['benchmarkPid'] ?? $execution->benchmark_pid,
            'exit_code' => $state['exitCode'] ?? $execution->exit_code,
            'started_at' => $state['startedAt'] ?? $execution->started_at,
            'finished_at' => $state['finishedAt'] ?? $execution->finished_at,
        ]);

        if ($status === 'completed' && $execution->synced_at === null) {
            $syncResult = $this->syncBenchmarkArchive->handle($execution->run_id);

            if (! $syncResult['locked'] && $syncResult['failed'] === 0) {
                $execution->update(['synced_at' => now()]);
            }
        }

        return $execution->refresh();
    }

    /**
     * @param  list<array{value: string}>  $options
     */
    private function resolveSelectedFile(string $selected, array $options, string $directory): string
    {
        if (! collect($options)->contains('value', $selected)) {
            throw new BenchmarkRunnerException('Listeden geçerli bir görev promptu seçin.');
        }

        $root = realpath($this->projectRoot().'/'.$directory);
        $candidate = realpath($this->projectRoot().'/'.$selected);

        if ($root === false || $candidate === false || ! $this->isWithin($root, $candidate)) {
            throw new BenchmarkRunnerException('Seçilen dosya güvenli dizinin dışında.');
        }

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<array{value: string, label: string, source: string}>  $options
     * @return array{absolutePath: ?string, reference: string, content: ?string}
     */
    private function resolvePromptSelection(array $input, array $options): array
    {
        if (($input['prompt_mode'] ?? 'saved') === 'custom') {
            $content = trim((string) ($input['custom_prompt_content'] ?? ''));

            if ($content === '') {
                throw new BenchmarkRunnerException('Özel görev promptu boş olamaz.');
            }

            $title = trim((string) ($input['custom_prompt_title'] ?? ''));
            $slug = Str::slug($title !== '' ? $title : 'tek-kullanim');

            return [
                'absolutePath' => null,
                'reference' => 'custom:'.($slug !== '' ? $slug : 'tek-kullanim'),
                'content' => $content,
            ];
        }

        $selected = trim((string) ($input['prompt'] ?? ''));

        if (str_starts_with($selected, 'library:')) {
            $promptId = filter_var(Str::after($selected, 'library:'), FILTER_VALIDATE_INT);
            $prompt = $promptId === false
                ? null
                : BenchmarkPrompt::query()->whereKey($promptId)->where('active', true)->first();

            if ($prompt === null || ! collect($options)->contains('value', $selected)) {
                throw new BenchmarkRunnerException('Listeden geçerli bir görev promptu seçin.');
            }

            return [
                'absolutePath' => null,
                'reference' => "library:{$prompt->id}:{$prompt->slug}",
                'content' => $prompt->content,
            ];
        }

        return [
            'absolutePath' => $this->resolveSelectedFile($selected, $options, 'prompts'),
            'reference' => $selected,
            'content' => null,
        ];
    }

    /**
     * @param  array{absolutePath: ?string, reference: string, content: ?string}  $prompt
     */
    private function materializePrompt(array $prompt, string $executionDirectory): string
    {
        if ($prompt['absolutePath'] !== null) {
            return $prompt['absolutePath'];
        }

        $path = $executionDirectory.'/prompt.txt';
        File::put(
            $path,
            rtrim((string) $prompt['content']).PHP_EOL.self::STATIC_EXPORT_CONTRACT.PHP_EOL,
            true,
        );
        @chmod($path, 0600);

        return $path;
    }

    /**
     * @param  list<string>  $selectedModels
     * @param  array<string, mixed>  $fixedProviders
     * @return array{absolutePath: null, reference: string, configContents: string, openCodeConfigContents: string, option: array{value: string, label: string, modelCount: int, ports: list<int>, providers: list<string>, maxParallel: int}, models: list<array{provider: string, modelId: string, model: string, folder: string, port: int, routing: array<string, mixed>}>}
     */
    private function customModelSelection(array $selectedModels, string $routingMode = 'auto', array $fixedProviders = [], bool $refreshProviders = false): array
    {
        return $this->modelSelection->openRouter($selectedModels, $routingMode, $fixedProviders, $refreshProviders);
    }

    /** @param array<string, mixed> $config */
    private function materializeModelConfig(array $config, string $executionDirectory): string
    {
        if (is_string($config['absolutePath'] ?? null)) {
            return $config['absolutePath'];
        }

        $path = $executionDirectory.'/models.conf';
        File::put($path, (string) ($config['configContents'] ?? ''), true);
        @chmod($path, 0600);

        return $path;
    }

    /** @param array<string, mixed> $config */
    private function materializeOpenCodeConfig(array $config, string $executionDirectory): string
    {
        if (is_string($config['openCodeConfigContents'] ?? null)) {
            $path = $executionDirectory.'/opencode.json';
            File::put($path, $config['openCodeConfigContents'], true);
            @chmod($path, 0600);

            return $path;
        }

        $path = realpath($this->projectRoot().'/config/opencode-benchmark.json');

        if ($path === false || ! $this->isWithin($this->projectRoot().'/config', $path)) {
            throw new BenchmarkRunnerException('OpenCode benchmark yapılandırması bulunamadı.');
        }

        return $path;
    }

    /**
     * @param  list<string>  $routes
     * @return list<string>
     */
    private function validateRoutes(array $routes): array
    {
        $routes = array_values(array_unique(array_filter(array_map('trim', $routes))));

        if ($routes === [] || count($routes) > 20) {
            throw new BenchmarkRunnerException('1–20 arasında rota girin.');
        }

        foreach ($routes as $route) {
            if (mb_strlen($route) > 180 || preg_match(self::ROUTE_PATTERN, $route) !== 1 || str_contains($route, '//')) {
                throw new BenchmarkRunnerException("Geçersiz rota: {$route}");
            }
        }

        return $routes;
    }

    private function ensurePortAvailable(int $port): void
    {
        $socket = @stream_socket_server("tcp://127.0.0.1:{$port}");

        if ($socket === false) {
            throw new BenchmarkRunnerException("{$port} portu kullanımda. Önce çalışan süreci kapatın.");
        }

        fclose($socket);
    }

    /**
     * @return list<string>
     */
    private function listFiles(string $directory, string $extension): array
    {
        $realDirectory = realpath($directory);

        if ($realDirectory === false) {
            throw new BenchmarkRunnerException('Benchmark seçenek dizini bulunamadı.', ['directory' => $directory]);
        }

        return collect(File::files($realDirectory))
            ->filter(function (\SplFileInfo $file) use ($extension, $realDirectory): bool {
                $realPath = $file->getRealPath();

                return $file->isFile()
                    && str_ends_with($file->getFilename(), $extension)
                    && $realPath !== false
                    && $this->isWithin($realDirectory, $realPath);
            })
            ->map(fn (\SplFileInfo $file): string => $file->getFilename())
            ->sort(fn (string $left, string $right): int => strcoll($left, $right))
            ->values()
            ->all();
    }

    private function titleFromFilename(string $filename): string
    {
        return Str::of($filename)
            ->beforeLast('.')
            ->replace(['-', '_'], ' ')
            ->title()
            ->toString();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readState(string $statePath): ?array
    {
        if (! File::isFile($statePath) || ! $this->isWithin($this->runnerDirectory(), $statePath)) {
            return null;
        }

        try {
            $state = json_decode(File::get($statePath), true, flags: JSON_THROW_ON_ERROR);

            return is_array($state) && ($state['version'] ?? null) === 1 ? $state : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function writeState(string $statePath, array $state): void
    {
        if (! $this->isWithin($this->runnerDirectory(), $statePath)) {
            throw new BenchmarkRunnerException('Koşucu durum dosyası güvenli dizinin dışında.');
        }

        File::ensureDirectoryExists(dirname($statePath), 0700, true);
        $temporaryPath = $statePath.'.php-'.getmypid().'.tmp';
        File::put(
            $temporaryPath,
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL,
            true,
        );
        File::move($temporaryPath, $statePath);
    }

    private function isRunnerProcess(?int $pid): bool
    {
        if ($pid === null || $pid <= 1) {
            return false;
        }

        $process = new Process(['ps', '-p', (string) $pid, '-o', 'command='], null, $this->processEnvironment->make(commandPath: '/usr/bin:/bin'));
        $process->setTimeout(3);
        $process->run();

        return $process->isSuccessful()
            && str_contains(trim($process->getOutput()), base_path('bin/benchmark-worker.mjs'));
    }

    private function completedModelCount(string $runId): int
    {
        $resultsDirectory = $this->projectRoot().'/runs/'.$runId.'/_results';

        if (! File::isDirectory($resultsDirectory)) {
            return 0;
        }

        return collect(File::files($resultsDirectory))
            ->filter(fn (\SplFileInfo $file): bool => $file->isFile() && $file->getExtension() === 'result')
            ->count();
    }

    private function readLogTail(string $logPath): string
    {
        if (! File::isFile($logPath) || ! $this->isWithin($this->runnerDirectory(), $logPath)) {
            return '';
        }

        $handle = @fopen($logPath, 'rb');

        if ($handle === false) {
            return '';
        }

        try {
            $size = File::size($logPath);

            if ($size === 0) {
                return '';
            }

            $length = min($size, self::MAX_LOG_TAIL_BYTES);
            fseek($handle, max(0, $size - $length));
            $content = fread($handle, $length) ?: '';

            return trim((string) preg_replace('/\x1B(?:[@-Z\\-_]|\[[0-?]*[ -\/]*[@-~])/', '', $content));
        } finally {
            fclose($handle);
        }
    }

    private function progressPhase(string $status, string $logTail, int $completedModels): string
    {
        return match (true) {
            $status === 'completed' => 'Tamamlandı',
            $status === 'failed' => 'Koşucu hatası',
            $status === 'stopped' => 'Durduruldu',
            $status === 'stopping' => 'Güvenli biçimde durduruluyor',
            str_contains($logTail, 'MODEL PARALEL BASLATILIYOR'), $completedModels > 0 => 'Modeller üretiliyor ve doğrulanıyor',
            str_contains($logTail, 'Projeler hazirlaniyor') => 'Model çalışma alanları hazırlanıyor',
            default => 'Koşucu başlatılıyor',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function idleSnapshot(): array
    {
        return [
            'status' => 'idle',
            'active' => false,
            'runId' => null,
            'prompt' => null,
            'config' => null,
            'routes' => [],
            'startedAt' => null,
            'finishedAt' => null,
            'exitCode' => null,
            'message' => 'Yeni bir benchmark başlatmaya hazır.',
            'logTail' => '',
            'outcome' => $this->emptyOutcome(
                'unavailable',
                'Henüz sonuç yok',
                'Yeni bir koşu tamamlandığında teknik sonuç burada gösterilecek.',
            ),
            'progress' => [
                'completedModels' => 0,
                'totalModels' => 0,
                'percent' => 0,
                'phase' => 'Hazır',
            ],
        ];
    }

    private function projectRoot(): string
    {
        return $this->configuredPath('root_path');
    }

    private function runnerDirectory(): string
    {
        return rtrim((string) config('benchforge.runner_path'), DIRECTORY_SEPARATOR);
    }

    private function configuredPath(string $key): string
    {
        $path = realpath((string) config("benchforge.{$key}"));

        if ($path === false) {
            throw new BenchmarkRunnerException('BenchForge dosya yolu bulunamadı.', ['config' => $key]);
        }

        return rtrim($path, DIRECTORY_SEPARATOR);
    }

    private function nodeBinary(): string
    {
        $nodeBinary = (string) config('benchforge.node_binary');

        if (! is_file($nodeBinary) || ! is_executable($nodeBinary)) {
            throw new BenchmarkRunnerException('Node.js çalıştırıcısı bulunamadı.', ['path' => $nodeBinary]);
        }

        return $nodeBinary;
    }

    private function shellBinary(): string
    {
        $shellBinary = (string) config('benchforge.shell_binary', '/bin/zsh');

        if (! is_file($shellBinary) || ! is_executable($shellBinary)) {
            throw new BenchmarkRunnerException('zsh çalıştırıcısı bulunamadı. Sistem ayarlarını kontrol edin.');
        }

        try {
            $process = new Process([$shellBinary, '--version'], null, $this->runnerEnvironment());
            $process->setTimeout(3);
            $process->run();

            if (! $process->isSuccessful() || preg_match('/^zsh\s+\d+\.\d+/', trim($process->getOutput())) !== 1) {
                throw new BenchmarkRunnerException('Yapılandırılan shell çalıştırıcısı zsh olarak doğrulanamadı.');
            }
        } catch (BenchmarkRunnerException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BenchmarkRunnerException('zsh çalıştırılamadı. Shell yolunu ve erişim izinlerini kontrol edin.');
        }

        return $shellBinary;
    }

    /**
     * @return array<string, string|false>
     */
    private function runnerEnvironment(): array
    {
        $homePath = realpath((string) config('benchforge.home_path'));
        $commandPath = trim((string) config('benchforge.command_path'));

        if ($homePath === false || ! File::isDirectory($homePath)) {
            throw new BenchmarkRunnerException('Koşucu kullanıcı dizini bulunamadı.', ['path' => config('benchforge.home_path')]);
        }

        if ($commandPath === '') {
            throw new BenchmarkRunnerException('Koşucu komut yolu yapılandırılmadı.');
        }

        return $this->processEnvironment->make($homePath, $commandPath);
    }

    private function isWithin(string $parent, string $candidate): bool
    {
        $normalizedParent = rtrim($parent, DIRECTORY_SEPARATOR);

        return $candidate === $normalizedParent
            || str_starts_with($candidate, $normalizedParent.DIRECTORY_SEPARATOR);
    }
}
