<?php

namespace App\Services;

use App\Exceptions\BenchmarkRunnerException;
use App\Models\BenchmarkExecution;
use App\Models\BenchmarkPrompt;
use App\Models\BenchmarkRun;
use App\Models\ModelRunAttempt;
use App\Models\OpenCodeGoModel;
use App\Models\OpenRouterModel;
use Illuminate\Contracts\Cache\LockTimeoutException;
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

    private const CUSTOM_MODEL_LIMIT = 5;

    private const CUSTOM_MODEL_PORT_START = 3251;

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
        private OpenRouterCatalog $openRouterCatalog,
        private OpenRouterProviderRouting $openRouterProviderRouting,
        private OpenCodeGoCatalog $openCodeGoCatalog,
        private ModelRunContinuationRunner $modelRunContinuationRunner,
        private BenchmarkProcessEnvironment $processEnvironment,
    ) {}

    /**
     * @return array{
     *     prompts: list<array{value: string, label: string, source: string}>,
     *     configs: list<array{value: string, label: string, modelCount: int, ports: list<int>, providers: list<string>, maxParallel: int}>,
     *     openRouterModels: Collection<int, OpenRouterModel>,
     *     catalog: array{count: int, lastSyncedAt: mixed},
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
        $configs = collect($this->listFiles($root.'/config', '.conf'))
            ->map(fn (string $filename): array => $this->parseConfig($filename)['option'])
            ->values()
            ->all();

        return [
            'prompts' => $prompts,
            'configs' => $configs,
            'openRouterModels' => $this->openRouterCatalog->selectableModels(),
            'openCodeGoModels' => $this->openCodeGoCatalog->selectableModels(),
            'catalog' => [
                'count' => OpenRouterModel::query()->where('available', true)->count(),
                'lastSyncedAt' => $this->openRouterCatalog->lastSyncedAt(),
            ],
            'goCatalog' => [
                'count' => OpenCodeGoModel::query()->where('available', true)->count(),
                'lastSyncedAt' => $this->openCodeGoCatalog->lastSyncedAt(),
            ],
            'suggestedRoutes' => ['/'],
        ];
    }

    /**
     * @return array{
     *     ready: bool,
     *     checkedAt: string,
     *     summary: string,
     *     checks: list<array{key: string, label: string, status: 'pass'|'fail', message: string}>
     * }
     */
    public function preflight(?string $selectedConfig, array $selectedModels = [], string $modelMode = 'group', string $routingMode = 'auto', array $fixedProviders = []): array
    {
        $parsedConfig = match ($modelMode) {
            'custom' => $this->customModelSelection($selectedModels, $routingMode, $fixedProviders),
            'opencode_go' => $this->openCodeGoModelSelection($selectedModels),
            default => $this->resolveConfig((string) $selectedConfig, $this->options()['configs']),
        };

        return $this->benchmarkPreflight->inspect(
            $parsedConfig['option']['ports'],
            $parsedConfig['option']['providers'],
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
        $promptSelection = $this->resolvePromptSelection($input, $options['prompts']);
        $parsedConfig = match ($input['model_mode'] ?? 'group') {
            'custom' => $this->customModelSelection($input['models'] ?? [], $input['routing_mode'] ?? 'auto', $input['fixed_providers'] ?? [], refreshProviders: true),
            'opencode_go' => $this->openCodeGoModelSelection($input['go_models'] ?? []),
            default => $this->resolveConfig((string) ($input['config'] ?? ''), $options['configs']),
        };
        $parsedConfig = $this->withMaxParallel($parsedConfig, $input['max_parallel'] ?? 1);
        $routes = $this->validateRoutes($input['routes']);
        $preflight = $this->benchmarkPreflight->inspect(
            $parsedConfig['option']['ports'],
            $parsedConfig['option']['providers'],
        );

        if (! $preflight['ready']) {
            $failedChecks = collect($preflight['checks'])
                ->where('status', 'fail')
                ->pluck('label')
                ->implode(', ');

            throw new BenchmarkRunnerException("Sistem ön kontrolü başarısız: {$failedChecks}.");
        }

        foreach ($parsedConfig['option']['ports'] as $port) {
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
     * @return array{absolutePath: null, reference: string, configContents: string, openCodeConfigContents: string, option: array{value: string, label: string, modelCount: int, ports: list<int>, providers: list<string>, maxParallel: int}}
     */
    private function customModelSelection(array $selectedModels, string $routingMode = 'auto', array $fixedProviders = [], bool $refreshProviders = false): array
    {
        $selectedModels = array_values(array_unique(array_filter(
            array_map(fn (mixed $model): string => trim((string) $model), $selectedModels),
        )));

        if ($selectedModels === [] || count($selectedModels) > self::CUSTOM_MODEL_LIMIT) {
            throw new BenchmarkRunnerException('OpenRouter kataloğundan 1–5 model seçin.');
        }

        $modelsById = OpenRouterModel::query()
            ->whereIn('model_id', $selectedModels)
            ->where('available', true)
            ->where('agent_compatible', true)
            ->get()
            ->keyBy('model_id');

        if ($modelsById->count() !== count($selectedModels)) {
            throw new BenchmarkRunnerException('Seçilen modellerden biri artık kullanılamıyor veya araç desteği sunmuyor. Kataloğu yenileyin.');
        }

        $routing = $this->openRouterProviderRouting->routingForModels($selectedModels, $routingMode, $fixedProviders, $refreshProviders);

        $ports = [];
        $configLines = [
            '# BenchForge tarafından OpenRouter kataloğundan oluşturuldu.',
            '# max_parallel=1',
            '# openrouter_routing='.$routingMode,
        ];

        if ($routingMode === 'fixed') {
            $configLines[] = '# openrouter_requested_providers='.implode(', ', array_map(fn (string $modelId): string => $modelId.':'.$fixedProviders[$modelId], $selectedModels));
        }
        $openCodeModels = [];

        foreach ($selectedModels as $index => $modelId) {
            $port = self::CUSTOM_MODEL_PORT_START + $index;
            $ports[] = $port;
            $modelSlug = Str::slug(Str::afterLast($modelId, '/')) ?: 'model';
            $folder = 'or-'.Str::limit($modelSlug, 36, '').'-'.substr(sha1($modelId), 0, 6);
            $configLines[] = "{$folder}|openrouter/{$modelId}|{$port}";
            $openCodeModels[$modelId] = [
                'options' => [
                    'provider' => $routing[$modelId],
                ],
            ];
        }

        $openCodeConfig = [
            '$schema' => 'https://opencode.ai/config.json',
            'provider' => [
                'openrouter' => [
                    'models' => $openCodeModels,
                ],
            ],
        ];

        return [
            'absolutePath' => null,
            'reference' => 'custom:openrouter',
            'configContents' => implode(PHP_EOL, $configLines).PHP_EOL,
            'openCodeConfigContents' => json_encode(
                $openCodeConfig,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ).PHP_EOL,
            'option' => [
                'value' => 'custom:openrouter',
                'label' => 'Özel OpenRouter seçimi',
                'modelCount' => count($selectedModels),
                'ports' => $ports,
                'providers' => ['openrouter'],
                'maxParallel' => 1,
            ],
        ];
    }

    /**
     * @param  list<string>  $selectedModels
     * @return array{absolutePath: null, reference: string, configContents: string, openCodeConfigContents: null, option: array{value: string, label: string, modelCount: int, ports: list<int>, providers: list<string>, maxParallel: int}}
     */
    private function openCodeGoModelSelection(array $selectedModels): array
    {
        $selectedModels = array_values(array_unique(array_filter(
            array_map(fn (mixed $model): string => trim((string) $model), $selectedModels),
        )));

        if ($selectedModels === [] || count($selectedModels) > self::CUSTOM_MODEL_LIMIT) {
            throw new BenchmarkRunnerException('OpenCode Go kataloğundan 1–5 model seçin.');
        }

        $modelsById = OpenCodeGoModel::query()
            ->whereIn('model_id', $selectedModels)
            ->where('available', true)
            ->where('agent_compatible', true)
            ->get()
            ->keyBy('model_id');

        if ($modelsById->count() !== count($selectedModels)) {
            throw new BenchmarkRunnerException('Seçilen OpenCode Go modellerinden biri artık kullanılamıyor veya araç desteği sunmuyor. Kataloğu yenileyin.');
        }

        $ports = [];
        $configLines = [
            '# BenchForge tarafından OpenCode Go kataloğundan oluşturuldu.',
            '# max_parallel=1',
        ];

        foreach ($selectedModels as $index => $modelId) {
            $port = self::CUSTOM_MODEL_PORT_START + $index;
            $ports[] = $port;
            $modelSlug = Str::slug($modelId) ?: 'model';
            $folder = 'go-'.Str::limit($modelSlug, 36, '').'-'.substr(sha1($modelId), 0, 6);
            $configLines[] = "{$folder}|opencode-go/{$modelId}|{$port}";
        }

        return [
            'absolutePath' => null,
            'reference' => 'custom:opencode-go',
            'configContents' => implode(PHP_EOL, $configLines).PHP_EOL,
            'openCodeConfigContents' => null,
            'option' => [
                'value' => 'custom:opencode-go',
                'label' => 'Özel OpenCode Go seçimi',
                'modelCount' => count($selectedModels),
                'ports' => $ports,
                'providers' => ['opencode-go'],
                'maxParallel' => 1,
            ],
        ];
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

    /**
     * @param  array{absolutePath: ?string, reference: string, configContents: ?string, openCodeConfigContents: ?string, option: array{value: string, label: string, modelCount: int, ports: list<int>, providers: list<string>, maxParallel: int}}  $config
     * @return array{absolutePath: null, reference: string, configContents: string, openCodeConfigContents: ?string, option: array{value: string, label: string, modelCount: int, ports: list<int>, providers: list<string>, maxParallel: int}}
     */
    private function withMaxParallel(array $config, mixed $requestedMaxParallel): array
    {
        $maxParallel = filter_var($requestedMaxParallel, FILTER_VALIDATE_INT);
        $modelCount = $config['option']['modelCount'];

        if ($maxParallel === false || $maxParallel < 1 || $maxParallel > $modelCount) {
            throw new BenchmarkRunnerException("Eşzamanlılık 1 ile {$modelCount} arasında olmalıdır.");
        }

        $contents = is_string($config['configContents'])
            ? $config['configContents']
            : File::get((string) $config['absolutePath']);
        $parallelDirective = "# max_parallel={$maxParallel}";

        if (preg_match('/^#\s*max_parallel=\d+$/m', $contents) === 1) {
            $contents = (string) preg_replace('/^#\s*max_parallel=\d+$/m', $parallelDirective, $contents, 1);
        } else {
            $contents = $parallelDirective.PHP_EOL.$contents;
        }

        $config['absolutePath'] = null;
        $config['configContents'] = $contents;
        $config['option']['maxParallel'] = $maxParallel;

        return $config;
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
     * @param  list<array{value: string}>  $options
     * @return array{absolutePath: string, reference: string, configContents: null, openCodeConfigContents: null, option: array{value: string, label: string, modelCount: int, ports: list<int>, providers: list<string>, maxParallel: int}}
     */
    private function resolveConfig(string $selected, array $options): array
    {
        if (! collect($options)->contains('value', $selected)) {
            throw new BenchmarkRunnerException('Listeden geçerli bir model grubu seçin.');
        }

        return $this->parseConfig(basename($selected));
    }

    /**
     * @return array{absolutePath: string, reference: string, configContents: null, openCodeConfigContents: null, option: array{value: string, label: string, modelCount: int, ports: list<int>, providers: list<string>, maxParallel: int}}
     */
    private function parseConfig(string $filename): array
    {
        $configRoot = realpath($this->projectRoot().'/config');
        $absolutePath = realpath($this->projectRoot().'/config/'.$filename);

        if ($configRoot === false || $absolutePath === false || ! $this->isWithin($configRoot, $absolutePath)) {
            throw new BenchmarkRunnerException('Model yapılandırması güvenli dizinin dışında.');
        }

        $ports = [];
        $providers = [];
        $modelCount = 0;
        $maxParallel = null;

        foreach (preg_split('/\R/', File::get($absolutePath)) ?: [] as $index => $rawLine) {
            $line = trim($rawLine);

            if (preg_match('/^#\s*max_parallel=(\d+)$/', $line, $matches) === 1) {
                if ($maxParallel !== null) {
                    throw new BenchmarkRunnerException("{$filename}:".($index + 1).' max_parallel birden fazla tanımlanamaz.');
                }

                $maxParallel = (int) $matches[1];

                continue;
            }

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));

            if (count($parts) !== 3) {
                throw new BenchmarkRunnerException("{$filename}:".($index + 1).' geçerli bir model kaydı değil.');
            }

            [$folder, $model, $rawPort] = $parts;
            $port = filter_var($rawPort, FILTER_VALIDATE_INT);

            if (
                preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $folder) !== 1
                || $model === ''
                || preg_match('/\s/', $model) === 1
                || $port === false
                || $port < 1024
                || $port > 65535
            ) {
                throw new BenchmarkRunnerException("{$filename}:".($index + 1).' geçerli bir model kaydı değil.');
            }

            if (in_array($port, $ports, true)) {
                throw new BenchmarkRunnerException("{$filename} içinde {$port} portu birden fazla kullanılıyor.");
            }

            $ports[] = $port;
            $providers[] = Str::before($model, '/');
            $modelCount++;
        }

        if ($modelCount === 0) {
            throw new BenchmarkRunnerException("{$filename} içinde çalıştırılabilir model bulunamadı.");
        }

        $maxParallel ??= $modelCount;

        if ($maxParallel < 1 || $maxParallel > $modelCount) {
            throw new BenchmarkRunnerException("{$filename} içinde max_parallel 1 ile {$modelCount} arasında olmalıdır.");
        }

        return [
            'absolutePath' => $absolutePath,
            'reference' => 'config/'.$filename,
            'configContents' => null,
            'openCodeConfigContents' => null,
            'option' => [
                'value' => 'config/'.$filename,
                'label' => $this->titleFromFilename($filename),
                'modelCount' => $modelCount,
                'ports' => $ports,
                'providers' => array_values(array_unique($providers)),
                'maxParallel' => $maxParallel,
            ],
        ];
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
