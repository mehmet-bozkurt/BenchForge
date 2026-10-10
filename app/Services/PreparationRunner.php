<?php

namespace App\Services;

use App\Exceptions\BenchmarkRunnerException;
use App\Models\OpenCodeGoModel;
use App\Models\OpenRouterModel;
use App\Models\PreparationComparison;
use App\Models\PreparationPackage;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use stdClass;
use Symfony\Component\Process\Process;
use Throwable;

class PreparationRunner
{
    private const MODEL_LIMIT = 5;

    private const TITLE_MAX_CHARACTERS = 120;

    private const SPECIFICATION_MAX_CHARACTERS = 100_000;

    private const TECHNOLOGIES_MAX_CHARACTERS = 5_000;

    private const COMPARISON_STATUSES = ['starting', 'running', 'completed', 'partial', 'failed', 'stopped', 'stopping'];

    private const PACKAGE_STATUSES = ['pending', 'running', 'completed', 'failed', 'stopped'];

    private const TERMINAL_PACKAGE_STATUSES = ['completed', 'failed', 'stopped'];

    /**
     * Create a new service instance.
     */
    public function __construct(
        private PreparationContract $contract,
        private OpenRouterCatalog $openRouterCatalog,
        private OpenCodeGoCatalog $openCodeGoCatalog,
        private ProviderConnectionStatus $providerConnectionStatus,
        private OpenRouterProviderRouting $openRouterProviderRouting,
        private BenchmarkProcessEnvironment $processEnvironment,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return [
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
            'connections' => $this->providerConnectionStatus->inspect(),
        ];
    }

    /** @param array<string, mixed> $input */
    public function start(array $input): PreparationComparison
    {
        try {
            return Cache::lock('benchforge:preparation:lifecycle', 120)
                ->block(5, fn (): PreparationComparison => $this->startLocked($input));
        } catch (LockTimeoutException) {
            throw new BenchmarkRunnerException('Başka bir hazırlık karşılaştırması başlatılıyor.');
        }
    }

    public function reconcile(PreparationComparison $comparison): PreparationComparison
    {
        $comparison->refresh()->load('packages');

        if (! $comparison->isActive()) {
            return $comparison;
        }

        try {
            $workingDirectory = $this->verifiedWorkingDirectory($comparison);
        } catch (BenchmarkRunnerException) {
            $comparison->packages
                ->whereNotIn('status', self::TERMINAL_PACKAGE_STATUSES)
                ->each(fn (PreparationPackage $package) => $package->update([
                    'status' => 'failed',
                    'error_message' => 'Hazırlık çalışma alanı güvenli biçimde doğrulanamadı.',
                ]));
            $comparison->update([
                'status' => 'failed',
                'message' => 'Hazırlık çalışma alanı güvenli biçimde doğrulanamadı; hiçbir sürece sinyal gönderilmedi.',
                'finished_at' => now(),
            ]);
            $this->releaseActiveLockFromRoot($comparison);

            return $comparison->refresh()->load('packages');
        }

        $job = $this->readJob($comparison, $workingDirectory);
        $state = $this->readState($comparison, $workingDirectory);
        $runnerIsAlive = $this->isRunnerProcess($comparison->runner_pid, $comparison, $workingDirectory);

        if ($state !== null && $job !== null) {
            $this->syncPackages($comparison, $state, $job, $workingDirectory);
        }

        $comparison->refresh()->load('packages');
        $stateStatus = is_string($state['status'] ?? null) && in_array($state['status'], self::COMPARISON_STATUSES, true)
            ? $state['status']
            : $comparison->status;
        $stalled = $comparison->isActive() && $this->isStalled($comparison);

        if ($stalled && $runnerIsAlive) {
            $signalled = $this->terminateOwnedProcess($comparison, $workingDirectory);
            $comparison->update([
                'status' => $signalled ? 'stopping' : $comparison->status,
                'message' => $signalled
                    ? 'Hazırlık zaman sınırını aştı; doğrulanan işçi güvenli biçimde durduruluyor.'
                    : 'Hazırlık zaman sınırını aştı ancak işçiye güvenli durdurma sinyali gönderilemedi.',
            ]);

            return $comparison->refresh()->load('packages');
        }

        if ($comparison->isActive() && ! $runnerIsAlive) {

            $fallbackStatus = in_array($stateStatus, ['stopped', 'stopping'], true) ? 'stopped' : 'failed';
            $comparison->packages
                ->whereNotIn('status', self::TERMINAL_PACKAGE_STATUSES)
                ->each(fn (PreparationPackage $package) => $package->update([
                    'status' => $fallbackStatus,
                    'error_message' => $fallbackStatus === 'stopped'
                        ? 'Hazırlık paketi kullanıcı tarafından durduruldu.'
                        : ($stalled
                            ? 'Hazırlık paketi zaman sınırında tamamlanamadı.'
                            : 'Hazırlık işçisi sonuç üretmeden kapandı.'),
                ]));

            $comparison->refresh()->load('packages');
            $finalStatus = $this->finalStatus($comparison, $stateStatus);
            $comparison->update([
                'status' => $finalStatus,
                'message' => $this->statusMessage($finalStatus),
                'finished_at' => $state['finishedAt'] ?? now(),
            ]);
            $this->releaseActiveLock($comparison, $workingDirectory);
        } elseif ($comparison->isActive() && $state !== null) {
            $comparison->update([
                'status' => in_array($stateStatus, PreparationComparison::ACTIVE_STATUSES, true) ? $stateStatus : $comparison->status,
                'message' => is_string($state['message'] ?? null)
                    ? Str::limit(trim($state['message']), 1000, '')
                    : $comparison->message,
                'started_at' => $state['startedAt'] ?? $comparison->started_at,
            ]);
        }

        return $comparison->refresh()->load('packages');
    }

    public function stop(PreparationComparison $comparison): PreparationComparison
    {
        try {
            return Cache::lock('benchforge:preparation:lifecycle', 30)
                ->block(5, function () use ($comparison): PreparationComparison {
                    $comparison = $this->reconcile($comparison);

                    if (! $comparison->isActive()) {
                        throw new BenchmarkRunnerException('Bu hazırlık karşılaştırması artık çalışmıyor.');
                    }

                    $workingDirectory = $this->verifiedWorkingDirectory($comparison);
                    $state = $this->readState($comparison, $workingDirectory);

                    if ($state === null || ! $this->isRunnerProcess($comparison->runner_pid, $comparison, $workingDirectory)) {
                        throw new BenchmarkRunnerException('Hazırlık işçisi kimliği doğrulanamadı; hiçbir sürece sinyal gönderilmedi.');
                    }

                    if (! function_exists('posix_kill')) {
                        throw new BenchmarkRunnerException('Bu sistemde güvenli süreç durdurma desteği bulunamadı.');
                    }

                    $previousStatus = $comparison->status;
                    $previousMessage = $comparison->message;
                    $previousState = $state;
                    $state['status'] = 'stopping';
                    $state['message'] = 'Hazırlık paketleri güvenli biçimde durduruluyor.';
                    $this->writeState($workingDirectory.'/state.json', $state, $workingDirectory);
                    $comparison->update(['status' => 'stopping', 'message' => $state['message']]);

                    if (! posix_kill(-$comparison->runner_pid, SIGTERM)) {
                        $this->writeState($workingDirectory.'/state.json', $previousState, $workingDirectory);
                        $comparison->update(['status' => $previousStatus, 'message' => $previousMessage]);
                        throw new BenchmarkRunnerException('Hazırlık işçisine durdurma sinyali gönderilemedi.');
                    }

                    return $comparison->refresh()->load('packages');
                });
        } catch (LockTimeoutException) {
            throw new BenchmarkRunnerException('Hazırlık karşılaştırması için başka bir işlem sürüyor.');
        }
    }

    /** @param array<string, mixed> $input */
    private function startLocked(array $input): PreparationComparison
    {
        app(BenchForgeSettings::class)->apply();

        $activeComparison = PreparationComparison::query()
            ->whereIn('status', PreparationComparison::ACTIVE_STATUSES)
            ->latest('id')
            ->first();

        if ($activeComparison !== null) {
            $activeComparison = $this->reconcile($activeComparison);

            if ($activeComparison->isActive()) {
                throw new BenchmarkRunnerException("{$activeComparison->title} hazırlık karşılaştırması halen devam ediyor.");
            }
        }

        $validated = $this->validatedInput($input);
        $models = $this->selectedModels($validated['provider'], $validated['models']);
        $this->ensureConnectionReady($validated['provider']);
        $openCodeBinary = $this->findOpenCodeBinary();
        $preparationRoot = $this->preparationRoot();
        $directoryName = 'comparison-'.Str::lower(Str::random(16));
        $workingDirectory = $preparationRoot.'/'.$directoryName;

        if (File::exists($workingDirectory) || ! $this->isWithin($preparationRoot, $workingDirectory)) {
            throw new BenchmarkRunnerException('Hazırlık çalışma dizini güvenli biçimde oluşturulamadı.');
        }

        $comparison = PreparationComparison::query()->create([
            'title' => $validated['title'],
            'specification' => $validated['specification'],
            'technologies' => $validated['technologies'],
            'provider' => $validated['provider'],
            'selected_models' => $validated['models'],
            'source_hash' => hash('sha256', $validated['specification']),
            'contract_version' => PreparationContract::VERSION,
            'status' => 'starting',
            'working_directory' => $workingDirectory,
            'started_at' => now(),
            'message' => 'Hazırlık paketleri için güvenli çalışma alanı oluşturuluyor.',
        ]);
        $runnerPid = null;

        try {
            File::ensureDirectoryExists($workingDirectory, 0700, true);

            if (realpath($workingDirectory) !== $workingDirectory || is_link($workingDirectory)) {
                throw new BenchmarkRunnerException('Hazırlık çalışma dizini doğrulanamadı.');
            }

            $lockPath = $preparationRoot.'/active.lock';
            $this->acquireActiveLock($lockPath, $comparison);
            $prompt = $this->contract->prompt(
                $validated['title'],
                $validated['specification'],
                $validated['technologies'],
            );
            $promptContents = rtrim($prompt).PHP_EOL;

            if (strlen($promptContents) > 1_048_576) {
                throw new BenchmarkRunnerException('Hazırlık promptu güvenli 1 MiB sınırını aşıyor. Şartnameyi kısaltın.');
            }

            $promptPath = $workingDirectory.'/prompt.txt';
            $this->writePrivateFile($promptPath, $promptContents, $workingDirectory);
            $routing = $validated['provider'] === 'openrouter'
                ? $this->openRouterProviderRouting->routingForModels($validated['models'], 'auto')
                : [];
            $jobModels = [];

            foreach ($validated['models'] as $modelId) {
                $model = $models->get($modelId);
                $modelName = Str::limit(trim((string) $model->name), 255, '');

                if ($modelName === '') {
                    $modelName = Str::limit($modelId, 255, '');
                }

                $package = $comparison->packages()->create([
                    'model_id' => $modelId,
                    'model_name' => $modelName,
                    'status' => 'pending',
                ]);
                $modelSlug = Str::slug(Str::afterLast($modelId, '/')) ?: 'model';
                $modelDirectory = $workingDirectory.'/models/'.$package->id.'-'.Str::limit($modelSlug, 48, '');
                File::ensureDirectoryExists($modelDirectory, 0700, true);

                if (realpath($modelDirectory) !== $modelDirectory || is_link($modelDirectory)) {
                    throw new BenchmarkRunnerException('Model hazırlık dizini güvenli biçimde oluşturulamadı.');
                }

                $configPath = $modelDirectory.'/opencode.json';
                $resultPath = $modelDirectory.'/result.txt';
                $this->writeJson(
                    $configPath,
                    $this->openCodeConfig($validated['provider'], $modelId, $routing[$modelId] ?? null),
                    $workingDirectory,
                );
                $jobModels[] = [
                    'packageId' => $package->id,
                    'modelId' => $modelId,
                    'modelName' => $modelName,
                    'model' => $validated['provider'].'/'.$modelId,
                    'directory' => $modelDirectory,
                    'configPath' => $configPath,
                    'resultPath' => $resultPath,
                ];
            }

            $statePath = $workingDirectory.'/state.json';
            $jobPath = $workingDirectory.'/job.json';
            $this->writeJson($jobPath, [
                'version' => 1,
                'comparisonId' => $comparison->id,
                'statePath' => $statePath,
                'promptPath' => $promptPath,
                'opencodeBinary' => $openCodeBinary,
                'timeoutSeconds' => $this->timeoutSeconds(),
                'models' => $jobModels,
                'startedAt' => $comparison->started_at?->toIso8601String() ?? now()->toIso8601String(),
            ], $workingDirectory);

            $launcher = new Process([
                $this->nodeBinary(),
                base_path('bin/benchmark-launcher.mjs'),
                base_path('bin/preparation-worker.mjs'),
                $jobPath,
            ], base_path());
            $launcher->setEnv($this->runnerEnvironment());
            $launcher->setTimeout(10);
            $launcher->mustRun();
            $runnerPid = filter_var(trim($launcher->getOutput()), FILTER_VALIDATE_INT);

            if ($runnerPid === false || $runnerPid <= 1) {
                throw new BenchmarkRunnerException('Hazırlık işçisi işlem kimliği alınamadı.');
            }

            $comparison->update(['runner_pid' => $runnerPid]);

            for ($attempt = 0; $attempt < 30; $attempt++) {
                usleep(50_000);
                $state = $this->readState($comparison->refresh(), $workingDirectory);

                if ($state !== null) {
                    return $this->reconcile($comparison);
                }
            }

            throw new BenchmarkRunnerException('Hazırlık işçisi zamanında hazır duruma gelemedi.');
        } catch (Throwable $exception) {
            if (is_int($runnerPid) && $runnerPid > 1 && $this->isRunnerProcess($runnerPid, $comparison, $workingDirectory)) {
                $this->terminateOwnedProcess($comparison, $workingDirectory);
            }

            $comparison->packages()
                ->whereNotIn('status', self::TERMINAL_PACKAGE_STATUSES)
                ->update(['status' => 'failed', 'error_message' => 'Hazırlık karşılaştırması başlatılamadı.']);
            $comparison->update([
                'status' => 'failed',
                'message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);
            $this->releaseActiveLock($comparison, $workingDirectory);

            if ($exception instanceof BenchmarkRunnerException) {
                throw $exception;
            }

            report($exception);

            throw new BenchmarkRunnerException('Hazırlık karşılaştırması başlatılamadı. Uygulama günlüğünü kontrol edin.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{title: string, specification: string, technologies: string, provider: string, models: list<string>}
     */
    private function validatedInput(array $input): array
    {
        $title = $this->normalizeText((string) ($input['title'] ?? ''));
        $specification = $this->normalizeText((string) ($input['specification'] ?? ''));
        $technologies = $this->normalizeText((string) ($input['technologies'] ?? ''));
        $provider = trim((string) ($input['provider'] ?? ''));
        $models = $input['models'] ?? [];

        foreach ([$title, $specification, $technologies] as $value) {
            if (! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
                throw new BenchmarkRunnerException('Hazırlık metinleri geçerli UTF-8 düz metin olmalıdır.');
            }
        }

        if ($title === '' || mb_strlen($title, 'UTF-8') > self::TITLE_MAX_CHARACTERS) {
            throw new BenchmarkRunnerException('Başlık boş olamaz ve 120 karakteri aşamaz.');
        }

        if (mb_strlen($specification, 'UTF-8') < 50 || mb_strlen($specification, 'UTF-8') > self::SPECIFICATION_MAX_CHARACTERS) {
            throw new BenchmarkRunnerException('Şartname 50 ile 100000 karakter arasında olmalıdır.');
        }

        if (mb_strlen($technologies, 'UTF-8') < 5 || mb_strlen($technologies, 'UTF-8') > self::TECHNOLOGIES_MAX_CHARACTERS) {
            throw new BenchmarkRunnerException('Teknoloji kısıtları 5 ile 5000 karakter arasında olmalıdır.');
        }

        if (! in_array($provider, ['openrouter', 'opencode-go'], true)) {
            throw new BenchmarkRunnerException('Geçerli bir model sağlayıcısı seçin.');
        }

        if (! is_array($models) || ! array_is_list($models) || count($models) < 1 || count($models) > self::MODEL_LIMIT) {
            throw new BenchmarkRunnerException('Aynı sağlayıcıdan 1–5 model seçin.');
        }

        $normalizedModels = array_map(fn (mixed $model): string => is_string($model) ? trim($model) : '', $models);

        if (in_array('', $normalizedModels, true)
            || count(array_unique($normalizedModels)) !== count($normalizedModels)
            || collect($normalizedModels)->contains(fn (string $model): bool => strlen($model) > 200
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/', $model) !== 1)) {
            throw new BenchmarkRunnerException('Aynı sağlayıcıdan 1–5 farklı model seçin.');
        }

        return compact('title', 'specification', 'technologies', 'provider') + ['models' => $normalizedModels];
    }

    private function normalizeText(string $value): string
    {
        if (str_starts_with($value, "\xEF\xBB\xBF")) {
            $value = substr($value, 3);
        }

        return trim(str_replace(["\r\n", "\r"], "\n", $value), " \t\n\r\v");
    }

    /** @param list<string> $modelIds @return Collection<string, OpenRouterModel|OpenCodeGoModel> */
    private function selectedModels(string $provider, array $modelIds): Collection
    {
        $modelClass = $provider === 'openrouter' ? OpenRouterModel::class : OpenCodeGoModel::class;
        $models = $modelClass::query()
            ->whereIn('model_id', $modelIds)
            ->where('available', true)
            ->where('agent_compatible', true)
            ->get()
            ->keyBy('model_id');

        if ($models->count() !== count($modelIds)) {
            throw new BenchmarkRunnerException('Seçilen modellerden biri artık kullanılamıyor veya araç desteği sunmuyor. Kataloğu yenileyin.');
        }

        return $models;
    }

    private function ensureConnectionReady(string $provider): void
    {
        $connections = $this->providerConnectionStatus->inspect();

        if (($connections['cli']['status'] ?? null) !== 'pass') {
            throw new BenchmarkRunnerException('OpenCode CLI hazır değil. Bağlantı durumunu yenileyip tekrar deneyin.');
        }

        if (($connections['providers'][$provider]['status'] ?? null) !== 'pass') {
            throw new BenchmarkRunnerException('Seçilen sağlayıcının OpenCode bağlantısı hazır değil.');
        }
    }

    /** @param array<string, mixed>|null $providerRouting @return array<string, mixed> */
    private function openCodeConfig(string $provider, string $modelId, ?array $providerRouting): array
    {
        $config = [
            '$schema' => 'https://opencode.ai/config.json',
            'autoupdate' => false,
            'share' => 'disabled',
            'plugin' => [],
            'instructions' => [],
            'mcp' => new stdClass,
            'tools' => ['*' => false],
            'permission' => ['*' => 'deny'],
            'agent' => [
                'benchforge-preparation' => [
                    'description' => 'BenchForge hazırlık paketi üreticisi',
                    'mode' => 'primary',
                    'prompt' => 'Yalnızca verilen şartname verisini analiz edip beş bölümlü JSON hazırlık paketi üret. Kod yazma veya çalıştırma, araç kullanma, soru sorma ve kaynakta olmayan bilgiyi kesin gereksinim gibi sunma. Kaynakta açık olanları, çıkarımları ve opsiyonel önerileri ayır. memory_bank başlangıç durumunda açıkça “Geliştirme başlamadı” ifadesini koru.',
                    'tools' => ['*' => false],
                    'permission' => ['*' => 'deny'],
                ],
            ],
        ];

        if ($provider === 'openrouter') {
            $config['provider'] = [
                'openrouter' => [
                    'models' => [
                        $modelId => [
                            'options' => [
                                'provider' => $providerRouting,
                            ],
                        ],
                    ],
                ],
            ];
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $job
     */
    private function syncPackages(PreparationComparison $comparison, array $state, array $job, string $workingDirectory): void
    {
        $comparison->load('packages');
        $packages = $comparison->packages->keyBy('id');
        $jobModels = collect($job['models'] ?? [])->keyBy('packageId');
        $stateModels = $state['models'] ?? null;

        if (! is_array($stateModels) || ! array_is_list($stateModels) || count($stateModels) !== $packages->count()) {
            return;
        }

        $statePackageIds = collect($stateModels)
            ->map(fn (mixed $model): ?int => is_array($model) && is_int($model['packageId'] ?? null)
                ? $model['packageId']
                : null);
        $expectedPackageIds = $packages->keys()->map(fn (mixed $id): int => (int) $id)->sort()->values();

        if ($statePackageIds->contains(null)
            || $statePackageIds->unique()->count() !== $statePackageIds->count()
            || $statePackageIds->sort()->values()->all() !== $expectedPackageIds->all()) {
            return;
        }

        foreach ($stateModels as $modelState) {
            $packageId = $modelState['packageId'];
            $package = $packages->get($packageId);
            $jobModel = $jobModels->get($packageId);

            if (! $package instanceof PreparationPackage || ! is_array($jobModel)) {
                continue;
            }

            if ($package->reviewed_at !== null || in_array($package->status, self::TERMINAL_PACKAGE_STATUSES, true)) {
                continue;
            }

            $status = is_string($modelState['status'] ?? null) && in_array($modelState['status'], self::PACKAGE_STATUSES, true)
                ? $modelState['status']
                : null;

            if ($status === null) {
                continue;
            }

            $metrics = $this->packageMetrics($modelState);

            if ($status === 'completed') {
                $expectedResultPath = is_string($jobModel['resultPath'] ?? null) ? $jobModel['resultPath'] : '';
                $stateResultPath = is_string($modelState['resultPath'] ?? null) ? $modelState['resultPath'] : '';

                if ($stateResultPath !== $expectedResultPath) {
                    $package->update([
                        ...$metrics,
                        'status' => 'failed',
                        'error_message' => 'Hazırlık sonucu güvenli çalışma yoluyla eşleşmiyor.',
                    ]);

                    continue;
                }

                $raw = $this->readPrivateFile($expectedResultPath, $workingDirectory, 1_048_576);
                $inspection = $raw === null
                    ? $this->contract->inspect('')
                    : $this->contract->inspect($raw);

                if (! $inspection['valid']) {
                    $package->update([
                        ...$metrics,
                        'status' => 'failed',
                        'structural_checks' => $inspection['checks'],
                        'error_message' => $raw === null
                            ? 'Hazırlık sonucu bulunamadı veya güvenli biçimde okunamadı.'
                            : $inspection['error'],
                    ]);

                    continue;
                }

                $package->update([
                    ...$metrics,
                    ...$inspection['sections'],
                    'status' => 'completed',
                    'structural_checks' => $inspection['checks'],
                    'error_message' => null,
                ]);

                continue;
            }

            $package->update([
                ...$metrics,
                'status' => $status,
                'error_message' => in_array($status, ['failed', 'stopped'], true)
                    ? Str::limit(trim((string) ($modelState['errorMessage'] ?? 'Hazırlık paketi üretilemedi.')), 2000, '')
                    : null,
            ]);
        }
    }

    /** @param array<string, mixed> $state @return array<string, int|float|string|null> */
    private function packageMetrics(array $state): array
    {
        $duration = is_numeric($state['durationSeconds'] ?? null) ? (float) $state['durationSeconds'] : null;
        $cost = is_numeric($state['costUsd'] ?? null) ? (float) $state['costUsd'] : null;

        return [
            'duration_seconds' => $duration !== null && is_finite($duration) && $duration >= 0 && $duration <= 31_536_000
                ? round($duration, 2)
                : null,
            'cost_usd' => $cost !== null && is_finite($cost) && $cost >= 0 && $cost <= 10_000_000
                ? number_format($cost, 8, '.', '')
                : null,
            'input_tokens' => $this->nonNegativeInteger($state['inputTokens'] ?? null),
            'output_tokens' => $this->nonNegativeInteger($state['outputTokens'] ?? null),
        ];
    }

    private function nonNegativeInteger(mixed $value): ?int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX]]);

        return $integer === false ? null : $integer;
    }

    private function finalStatus(PreparationComparison $comparison, string $stateStatus): string
    {
        $statuses = $comparison->packages->pluck('status');
        $completed = $statuses->filter(fn (string $status): bool => $status === 'completed')->count();

        if ($completed === $statuses->count() && $completed > 0) {
            return 'completed';
        }

        if ($completed > 0) {
            return 'partial';
        }

        if (in_array($stateStatus, ['stopped', 'stopping'], true) || $statuses->every(fn (string $status): bool => $status === 'stopped')) {
            return 'stopped';
        }

        return 'failed';
    }

    private function statusMessage(string $status): string
    {
        return match ($status) {
            'completed' => 'Tüm hazırlık paketleri üretildi ve yapısal sözleşmeyi geçti.',
            'partial' => 'Bazı hazırlık paketleri üretildi; bazı modeller başarısız oldu.',
            'stopped' => 'Hazırlık karşılaştırması kullanıcı tarafından durduruldu.',
            default => 'Hazırlık paketleri üretilemedi. Model hatalarını inceleyin.',
        };
    }

    /** @return array<string, mixed>|null */
    private function readJob(PreparationComparison $comparison, string $workingDirectory): ?array
    {
        $job = $this->readJson($workingDirectory.'/job.json', $workingDirectory);

        if (($job['version'] ?? null) !== 1
            || ($job['comparisonId'] ?? null) !== $comparison->id
            || ($job['statePath'] ?? null) !== $workingDirectory.'/state.json'
            || ($job['promptPath'] ?? null) !== $workingDirectory.'/prompt.txt'
            || ! is_array($job['models'] ?? null)
            || ! array_is_list($job['models'])) {
            return null;
        }

        $comparison->loadMissing('packages');
        $packages = $comparison->packages->keyBy('id');
        $packageIds = [];

        foreach ($job['models'] as $model) {
            if (! is_array($model)
                || ! is_int($model['packageId'] ?? null)
                || isset($packageIds[$model['packageId']])) {
                return null;
            }

            $packageIds[$model['packageId']] = true;
            $package = $packages->get($model['packageId']);
            $directory = $model['directory'] ?? null;
            $configPath = $model['configPath'] ?? null;
            $resultPath = $model['resultPath'] ?? null;

            if (! $package instanceof PreparationPackage
                || ($model['modelId'] ?? null) !== $package->model_id
                || ! is_string($directory)
                || ! is_string($configPath)
                || ! is_string($resultPath)
                || dirname($directory) !== $workingDirectory.'/models'
                || $configPath !== $directory.'/opencode.json'
                || $resultPath !== $directory.'/result.txt'
                || is_link($directory)
                || realpath($directory) !== $directory
                || ! $this->isWithin($workingDirectory, $directory)
                || $this->readPrivateFile($configPath, $workingDirectory, 131_072) === null) {
                return null;
            }
        }

        $expectedPackageIds = $packages->keys()->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
        $actualPackageIds = collect(array_keys($packageIds))->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();

        if ($actualPackageIds !== $expectedPackageIds
            || $this->readPrivateFile($workingDirectory.'/prompt.txt', $workingDirectory, 1_048_576) === null) {
            return null;
        }

        return $job;
    }

    /** @return array<string, mixed>|null */
    private function readState(PreparationComparison $comparison, string $workingDirectory): ?array
    {
        $state = $this->readJson($workingDirectory.'/state.json', $workingDirectory);

        if (($state['version'] ?? null) !== 1
            || (int) ($state['comparisonId'] ?? 0) !== $comparison->id
            || (int) ($state['runnerPid'] ?? 0) !== (int) $comparison->runner_pid) {
            return null;
        }

        return $state;
    }

    /** @return array<string, mixed>|null */
    private function readJson(string $path, string $workingDirectory): ?array
    {
        $content = $this->readPrivateFile($path, $workingDirectory, 2_097_152);

        if ($content === null) {
            return null;
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function readPrivateFile(string $path, string $workingDirectory, int $maximumBytes): ?string
    {
        if ($path === '' || ! $this->isWithin($workingDirectory, $path) || is_link($path) || ! File::isFile($path)) {
            return null;
        }

        $realPath = realpath($path);

        if ($realPath === false || $realPath !== $path || ! $this->isWithin($workingDirectory, $realPath)) {
            return null;
        }

        try {
            if (File::size($realPath) > $maximumBytes) {
                return null;
            }

            return File::get($realPath);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $data */
    private function writeJson(string $path, array $data, string $workingDirectory): void
    {
        $this->writePrivateFile(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL,
            $workingDirectory,
        );
    }

    /** @param array<string, mixed> $state */
    private function writeState(string $path, array $state, string $workingDirectory): void
    {
        $this->writeJson($path, $state, $workingDirectory);
    }

    private function writePrivateFile(string $path, string $content, string $workingDirectory): void
    {
        if (! $this->isWithin($workingDirectory, $path) || $path === $workingDirectory || is_link($path)) {
            throw new BenchmarkRunnerException('Hazırlık dosyası güvenli çalışma dizininin dışında.');
        }

        File::ensureDirectoryExists(dirname($path), 0700, true);
        $temporaryPath = $path.'.php-'.getmypid().'-'.Str::lower(Str::random(6)).'.tmp';
        $written = File::put($temporaryPath, $content, true);

        if ($written === false) {
            throw new BenchmarkRunnerException('Hazırlık dosyası yazılamadı.');
        }

        @chmod($temporaryPath, 0600);
        File::move($temporaryPath, $path);
        @chmod($path, 0600);
    }

    private function acquireActiveLock(string $lockPath, PreparationComparison $comparison): void
    {
        if (is_link($lockPath)) {
            throw new BenchmarkRunnerException('Hazırlık kilit dosyası güvenli değil.');
        }

        if (File::exists($lockPath)) {
            File::delete($lockPath);
        }

        $handle = @fopen($lockPath, 'x');

        if ($handle === false) {
            throw new BenchmarkRunnerException('Başka bir hazırlık karşılaştırması devam ediyor.');
        }

        try {
            fwrite($handle, (string) $comparison->id);
        } finally {
            fclose($handle);
        }

        @chmod($lockPath, 0600);
    }

    private function releaseActiveLock(PreparationComparison $comparison, string $workingDirectory): void
    {
        $root = dirname($workingDirectory);
        $lockPath = $root.'/active.lock';

        if (is_link($lockPath) || ! File::isFile($lockPath)) {
            return;
        }

        try {
            if (trim(File::get($lockPath)) === (string) $comparison->id) {
                File::delete($lockPath);
            }
        } catch (Throwable) {
            return;
        }
    }

    private function releaseActiveLockFromRoot(PreparationComparison $comparison): void
    {
        try {
            $root = $this->preparationRoot();
            $lockPath = $root.'/active.lock';

            if (is_link($lockPath) || ! File::isFile($lockPath)) {
                return;
            }

            if (trim(File::get($lockPath)) === (string) $comparison->id) {
                File::delete($lockPath);
            }
        } catch (Throwable) {
            return;
        }
    }

    private function verifiedWorkingDirectory(PreparationComparison $comparison): string
    {
        $root = $this->preparationRoot();
        $storedPath = $comparison->working_directory;

        if (! is_string($storedPath) || $storedPath === '' || is_link($storedPath)) {
            throw new BenchmarkRunnerException('Hazırlık çalışma dizini doğrulanamadı.');
        }

        $realPath = realpath($storedPath);

        if ($realPath === false
            || $realPath !== $storedPath
            || dirname($realPath) !== $root
            || ! $this->isWithin($root, $realPath)) {
            throw new BenchmarkRunnerException('Hazırlık çalışma dizini güvenli kökle eşleşmiyor.');
        }

        return $realPath;
    }

    private function isRunnerProcess(?int $pid, PreparationComparison $comparison, string $workingDirectory): bool
    {
        if ($pid === null || $pid <= 1) {
            return false;
        }

        try {
            $process = new Process(['ps', '-p', (string) $pid, '-o', 'command='], null, $this->processEnvironment->make(commandPath: '/usr/bin:/bin'));
            $process->setTimeout(3);
            $process->run();
            $command = trim($process->getOutput());
            $workerPath = base_path('bin/preparation-worker.mjs');
            $jobPath = $workingDirectory.'/job.json';

            return $process->isSuccessful()
                && preg_match('/(?:^|\s)'.preg_quote($workerPath, '/').'(?=\s|$)/', $command) === 1
                && preg_match('/(?:^|\s)'.preg_quote($jobPath, '/').'(?=\s|$)/', $command) === 1
                && $comparison->working_directory === $workingDirectory;
        } catch (Throwable) {
            return false;
        }
    }

    private function terminateOwnedProcess(PreparationComparison $comparison, string $workingDirectory): bool
    {
        if (! function_exists('posix_kill')
            || ! $this->isRunnerProcess($comparison->runner_pid, $comparison, $workingDirectory)) {
            return false;
        }

        return posix_kill(-$comparison->runner_pid, SIGTERM);
    }

    private function isStalled(PreparationComparison $comparison): bool
    {
        $maximumDuration = ($this->timeoutSeconds() * max(1, $comparison->packages()->count())) + 120;

        return $comparison->started_at !== null
            && $comparison->started_at->addSeconds($maximumDuration)->isPast();
    }

    private function preparationRoot(): string
    {
        $configured = rtrim((string) config('benchforge.preparation_path'), DIRECTORY_SEPARATOR);

        if ($configured === '') {
            throw new BenchmarkRunnerException('Hazırlık depolama yolu yapılandırılmadı.');
        }

        File::ensureDirectoryExists($configured, 0700, true);
        $realPath = realpath($configured);

        if ($realPath === false || ! File::isDirectory($realPath)) {
            throw new BenchmarkRunnerException('Hazırlık depolama yolu oluşturulamadı.');
        }

        return rtrim($realPath, DIRECTORY_SEPARATOR);
    }

    private function findOpenCodeBinary(): string
    {
        foreach (explode(PATH_SEPARATOR, (string) config('benchforge.command_path')) as $directory) {
            if ($directory === '') {
                continue;
            }

            $candidate = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'opencode';

            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        throw new BenchmarkRunnerException('OpenCode komutu yapılandırılan PATH içinde bulunamadı.');
    }

    private function nodeBinary(): string
    {
        $binary = (string) config('benchforge.node_binary');

        if (! is_file($binary) || ! is_executable($binary)) {
            throw new BenchmarkRunnerException('Node.js çalıştırıcısı bulunamadı.');
        }

        return $binary;
    }

    /** @return array<string, string|false> */
    private function runnerEnvironment(): array
    {
        $homePath = realpath((string) config('benchforge.home_path'));
        $commandPath = trim((string) config('benchforge.command_path'));

        if ($homePath === false || ! File::isDirectory($homePath) || $commandPath === '') {
            throw new BenchmarkRunnerException('Hazırlık işçisi kullanıcı ve komut yolu yapılandırması geçersiz.');
        }

        return $this->processEnvironment->make($homePath, $commandPath);
    }

    private function timeoutSeconds(): int
    {
        return max(30, min(1800, (int) config('benchforge.preparation_timeout_seconds', 900)));
    }

    private function isWithin(string $parent, string $candidate): bool
    {
        $normalizedParent = rtrim($parent, DIRECTORY_SEPARATOR);

        return $candidate === $normalizedParent
            || str_starts_with($candidate, $normalizedParent.DIRECTORY_SEPARATOR);
    }
}
