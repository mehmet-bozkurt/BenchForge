<?php

namespace App\Services;

use App\Exceptions\BenchmarkRunnerException;
use App\Models\BenchmarkExecution;
use App\Models\BenchmarkRun;
use App\Models\ModelRun;
use App\Models\ModelRunAttempt;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Throwable;

class ModelRunContinuationRunner
{
    public const MAX_PARALLEL_ATTEMPTS = 2;

    private const MAX_LOG_TAIL_BYTES = 32768;

    private const SAFE_SEGMENT_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    private const SESSION_PATTERN = '/^ses_[A-Za-z0-9_-]+$/';

    public function __construct(
        private OpenCodeSessionLocator $sessionLocator,
        private BenchmarkRunStorage $benchmarkRunStorage,
        private OpenCodeUsageTracker $openCodeUsageTracker,
        private ModelRunDiagnosticContext $diagnosticContext,
        private BenchmarkProcessEnvironment $processEnvironment,
    ) {}

    public function start(BenchmarkRun $benchmarkRun, ModelRun $modelRun, string $mode): ModelRunAttempt
    {
        if (! in_array($mode, ['continue', 'validate'], true)) {
            throw new BenchmarkRunnerException('Geçerli bir kurtarma denemesi seçin.');
        }

        try {
            return Cache::lock('benchforge:runner:start', 20)
                ->block(5, fn (): ModelRunAttempt => $this->startLocked($benchmarkRun, $modelRun, $mode));
        } catch (LockTimeoutException) {
            throw new BenchmarkRunnerException('Başka bir benchmark başlatma işlemi devam ediyor.');
        }
    }

    /**
     * @return array{canContinue: bool, canValidate: bool, reason: ?string, sessionId: ?string}
     */
    public function availability(BenchmarkRun $benchmarkRun, ModelRun $modelRun): array
    {
        $unavailable = fn (string $reason): array => [
            'canContinue' => false,
            'canValidate' => false,
            'reason' => $reason,
            'sessionId' => null,
        ];

        if ($modelRun->benchmark_run_id !== $benchmarkRun->id || $modelRun->status !== 'fail') {
            return $unavailable('Yalnızca ilk teslimi sorunlu modeller kurtarma denemesine açılır.');
        }

        $this->reconcileActiveAttempts();

        if ($this->hasRecoveredResult($modelRun)) {
            return $unavailable('Bu modelin son kurtarma denemesi bütün kontrolleri geçti. İlk teslim kaydı korunuyor.');
        }

        if (BenchmarkExecution::query()->whereIn('status', BenchmarkExecution::ACTIVE_STATUSES)->exists()) {
            return $unavailable('Bir benchmark koşusu çalışıyor. Tamamlandığında yeniden deneyin.');
        }

        try {
            $definition = $this->modelDefinition($benchmarkRun, $modelRun);
        } catch (BenchmarkRunnerException $exception) {
            return $unavailable($exception->getMessage());
        }

        if ($reason = $this->activeAttemptBlockReason($modelRun, $definition['port'])) {
            return $unavailable($reason);
        }

        $sessionId = $definition['openCodeConfigPath'] === null
            ? null
            : $this->sourceSession($benchmarkRun, $modelRun, $definition['workspace']);

        return [
            'canContinue' => $sessionId !== null && $definition['openCodeConfigPath'] !== null,
            'canValidate' => true,
            'reason' => null,
            'sessionId' => $sessionId,
        ];
    }

    public function reconcileForRun(BenchmarkRun $benchmarkRun): void
    {
        ModelRunAttempt::query()
            ->whereHas('modelRun', fn ($query) => $query->where('benchmark_run_id', $benchmarkRun->id))
            ->whereIn('status', ModelRunAttempt::ACTIVE_STATUSES)
            ->with('modelRun.benchmarkRun')
            ->get()
            ->each(fn (ModelRunAttempt $attempt) => $this->reconcile($attempt));
    }

    public function reconcileActiveAttempts(): void
    {
        ModelRunAttempt::query()
            ->whereIn('status', ModelRunAttempt::ACTIVE_STATUSES)
            ->with('modelRun.benchmarkRun')
            ->get()
            ->each(fn (ModelRunAttempt $attempt) => $this->reconcile($attempt));
    }

    public function reconcile(ModelRunAttempt $attempt): ModelRunAttempt
    {
        if (! $attempt->isActive()) {
            return $attempt;
        }

        $state = $this->readState($attempt);
        $status = is_string($state['status'] ?? null) ? $state['status'] : $attempt->status;
        $runnerPid = (int) ($state['runnerPid'] ?? $attempt->runner_pid);

        if (! in_array($status, [...ModelRunAttempt::ACTIVE_STATUSES, 'completed', 'failed', 'stopped'], true)) {
            $status = $attempt->status;
        }

        if (! $this->isRunnerProcess($runnerPid, $attempt)) {
            $hasResult = File::isFile($attempt->attempt_path.'/result.txt');
            $status = match (true) {
                in_array($status, ['stopping', 'stopped'], true) => 'stopped',
                $status === 'failed' => 'failed',
                $hasResult => 'completed',
                default => 'failed',
            };
            $state = array_merge($state ?? [], [
                'status' => $status,
                'finishedAt' => $state['finishedAt'] ?? now()->toIso8601String(),
                'message' => match ($status) {
                    'completed' => 'Deneme tamamlandı ve doğrulama sonuçları kaydedildi.',
                    'stopped' => 'Deneme kullanıcı tarafından durduruldu.',
                    default => $state['message'] ?? 'Kurtarma koşucusu beklenmedik biçimde kapandı; çalışma günlüğünü inceleyin.',
                },
            ]);
            $this->writeState($attempt, $state);
        }

        $terminal = ! in_array($status, ModelRunAttempt::ACTIVE_STATUSES, true);
        $result = $terminal ? $this->parseResult($attempt) : $attempt->result;

        if ($status === 'completed' && $result === null) {
            $status = 'failed';
            $state['message'] = 'Deneme kapandı ancak okunabilir bir sonuç dosyası üretilemedi.';
        }

        $attempt->update([
            'status' => $status,
            'runner_pid' => $state['runnerPid'] ?? $attempt->runner_pid,
            'process_pid' => $state['processPid'] ?? $attempt->process_pid,
            'exit_code' => $state['exitCode'] ?? $attempt->exit_code,
            'message' => $state['message'] ?? $attempt->message,
            'started_at' => $state['startedAt'] ?? $attempt->started_at,
            'finished_at' => $terminal ? ($state['finishedAt'] ?? $attempt->finished_at ?? now()) : null,
            'session_id' => $result['sessionId'] ?? $attempt->session_id,
            'result' => $result,
        ]);

        if ($terminal && $attempt->usage === null) {
            try {
                $this->openCodeUsageTracker->syncAttempt($attempt);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        if ($terminal && $attempt->synced_at === null) {
            try {
                $benchmarkRun = $attempt->modelRun->benchmarkRun;
                $this->benchmarkRunStorage->refresh($benchmarkRun);
                $attempt->update(['synced_at' => now()]);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $attempt->refresh();
    }

    public function stop(
        BenchmarkRun $benchmarkRun,
        ModelRun $modelRun,
        ModelRunAttempt $attempt,
    ): ModelRunAttempt {
        if ($modelRun->benchmark_run_id !== $benchmarkRun->id || $attempt->model_run_id !== $modelRun->id) {
            throw new BenchmarkRunnerException('Kurtarma denemesi bu modele ait değil.');
        }

        $attempt = $this->reconcile($attempt);

        if (! $attempt->isActive() || ! $this->isRunnerProcess($attempt->runner_pid, $attempt)) {
            throw new BenchmarkRunnerException('Koşucu kimliği doğrulanamadı; hiçbir sürece sinyal gönderilmedi.');
        }

        $state = $this->readState($attempt) ?? [];
        $state['status'] = 'stopping';
        $state['message'] = 'Kurtarma denemesi ve ona bağlı süreçler durduruluyor.';
        $this->writeState($attempt, $state);
        $attempt->update(['status' => 'stopping', 'message' => $state['message']]);

        if (! function_exists('posix_kill') || ! posix_kill(-$attempt->runner_pid, SIGTERM)) {
            throw new BenchmarkRunnerException('Koşucuya durdurma sinyali gönderilemedi.');
        }

        return $attempt->refresh();
    }

    public function readLogTail(ModelRunAttempt $attempt): string
    {
        $attemptPath = realpath($attempt->attempt_path);
        $logPath = realpath($attempt->log_path);

        if ($attemptPath === false || $logPath === false || ! File::isFile($logPath) || ! $this->isWithin($attemptPath, $logPath)) {
            return '';
        }

        $handle = @fopen($logPath, 'rb');

        if ($handle === false) {
            return '';
        }

        try {
            $size = File::size($logPath);
            $length = min($size, self::MAX_LOG_TAIL_BYTES);

            if ($length === 0) {
                return '';
            }

            fseek($handle, max(0, $size - $length));
            $content = fread($handle, $length) ?: '';

            return trim((string) preg_replace('/\x1B(?:[@-Z\\-_]|\[[0-?]*[ -\/]*[@-~])/', '', $content));
        } finally {
            fclose($handle);
        }
    }

    private function startLocked(BenchmarkRun $benchmarkRun, ModelRun $modelRun, string $mode): ModelRunAttempt
    {
        app(BenchForgeSettings::class)->apply();

        if ($modelRun->benchmark_run_id !== $benchmarkRun->id) {
            throw new BenchmarkRunnerException('Seçilen model bu benchmark koşusuna ait değil.');
        }

        $this->reconcileActiveAttempts();

        $activeExecution = BenchmarkExecution::query()
            ->whereIn('status', BenchmarkExecution::ACTIVE_STATUSES)
            ->latest('id')
            ->first();

        if ($activeExecution !== null) {
            throw new BenchmarkRunnerException("{$activeExecution->run_id} benchmark koşusu halen devam ediyor.");
        }

        if ($modelRun->status !== 'fail') {
            throw new BenchmarkRunnerException('Yalnızca ilk teslimi sorunlu modeller yeniden çalıştırılabilir.');
        }

        if ($this->hasRecoveredResult($modelRun)) {
            throw new BenchmarkRunnerException('Bu modelin son kurtarma denemesi zaten bütün kontrolleri geçti.');
        }

        $definition = $this->modelDefinition($benchmarkRun, $modelRun);

        if ($reason = $this->activeAttemptBlockReason($modelRun, $definition['port'])) {
            throw new BenchmarkRunnerException($reason);
        }

        $this->ensurePortAvailable($definition['port']);
        $sourceSessionId = null;

        if ($mode === 'continue') {
            if ($definition['openCodeConfigPath'] === null) {
                throw new BenchmarkRunnerException('Bu koşunun OpenCode yapılandırması bulunamadığı için model devam ettirilemiyor.');
            }

            $sourceSessionId = $this->sourceSession($benchmarkRun, $modelRun, $definition['workspace']);

            if ($sourceSessionId === null) {
                throw new BenchmarkRunnerException('Bu model için çalışma alanıyla eşleşen bir OpenCode oturumu bulunamadı.');
            }
        }

        $attemptNumber = ((int) $modelRun->modelRunAttempts()->max('attempt_number')) + 1;
        $attemptsRoot = $definition['runPath'].'/_attempts';

        if (! File::exists($attemptsRoot)) {
            File::makeDirectory($attemptsRoot, 0700);
        }

        $resolvedAttemptsRoot = realpath($attemptsRoot);

        if ($resolvedAttemptsRoot === false
            || ! File::isDirectory($resolvedAttemptsRoot)
            || $resolvedAttemptsRoot !== $attemptsRoot
            || dirname($resolvedAttemptsRoot) !== $definition['runPath']) {
            throw new BenchmarkRunnerException('Kurtarma sonuç klasörü izin verilen koşu alanının dışında.');
        }

        $attemptRoot = $resolvedAttemptsRoot.'/'.$modelRun->slug;

        if (! File::exists($attemptRoot)) {
            File::makeDirectory($attemptRoot, 0700);
        }

        $resolvedAttemptRoot = realpath($attemptRoot);

        if ($resolvedAttemptRoot === false
            || ! File::isDirectory($resolvedAttemptRoot)
            || $resolvedAttemptRoot !== $attemptRoot
            || dirname($resolvedAttemptRoot) !== $resolvedAttemptsRoot) {
            throw new BenchmarkRunnerException('Kurtarma denemesi klasörü izin verilen koşu alanının dışında.');
        }

        $attemptPath = $resolvedAttemptRoot.'/attempt-'.$attemptNumber;

        if (! $this->isWithin($definition['runPath'], $attemptPath) || File::exists($attemptPath)) {
            throw new BenchmarkRunnerException('Kurtarma denemesi klasörü güvenli biçimde oluşturulamadı.');
        }

        File::ensureDirectoryExists($attemptPath, 0700, true);
        $lockPath = $attemptPath.'/active.lock';

        $lockHandle = @fopen($lockPath, 'x');

        if ($lockHandle === false) {
            throw new BenchmarkRunnerException('Kurtarma denemesi kilidi oluşturulamadı.');
        }

        fwrite($lockHandle, (string) getmypid());
        fclose($lockHandle);

        $statePath = $attemptPath.'/state.json';
        $logPath = $attemptPath.'/runner.log';
        $jobPath = $attemptPath.'/job.json';
        $promptPath = $attemptPath.'/continuation-prompt.txt';
        $prompt = $mode === 'continue'
            ? $this->diagnosticContext->prompt($benchmarkRun, $modelRun, $definition)
            : null;
        $attempt = null;

        try {
            if ($prompt !== null) {
                File::put($promptPath, $prompt.PHP_EOL, true);
                @chmod($promptPath, 0600);
            }

            $attempt = $modelRun->modelRunAttempts()->create([
                'attempt_number' => $attemptNumber,
                'mode' => $mode,
                'status' => 'starting',
                'source_session_id' => $sourceSessionId,
                'message' => $mode === 'continue'
                    ? 'OpenCode oturumunun ayrılmış kopyası hazırlanıyor.'
                    : 'Model çağrılmadan doğrulamalar hazırlanıyor.',
                'prompt' => $prompt,
                'state_path' => $statePath,
                'log_path' => $logPath,
                'attempt_path' => $attemptPath,
                'started_at' => now(),
            ]);

            $job = [
                'statePath' => $statePath,
                'lockPath' => $lockPath,
                'logPath' => $logPath,
                'projectRoot' => $this->projectRoot(),
                'scriptPath' => $this->configuredPath('resume_script'),
                'shellBinary' => $this->shellBinary(),
                'runPath' => $definition['runPath'],
                'modelSlug' => $modelRun->slug,
                'model' => $definition['model'],
                'port' => $definition['port'],
                'routes' => $definition['routes'],
                'openCodeConfigPath' => $definition['openCodeConfigPath'] ?? '-',
                'attemptPath' => $attemptPath,
                'mode' => $mode,
                'sourceSessionId' => $sourceSessionId ?? '-',
                'promptPath' => $prompt !== null ? $promptPath : '-',
                'attemptId' => $attempt->id,
                'startedAt' => now()->toIso8601String(),
                'boundaryPolicyVersion' => ModelRunDiagnosticContext::POLICY_VERSION,
            ];
            File::put(
                $jobPath,
                json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL,
                true,
            );
            @chmod($jobPath, 0600);

            $launcher = new Process([
                $this->nodeBinary(),
                base_path('bin/benchmark-launcher.mjs'),
                base_path('bin/model-run-attempt-worker.mjs'),
                $jobPath,
            ], base_path(), $this->runnerEnvironment());
            $launcher->setTimeout(10);
            $launcher->mustRun();

            $runnerPid = (int) trim($launcher->getOutput());

            if ($runnerPid <= 1) {
                throw new BenchmarkRunnerException('Kurtarma koşucusunun işlem kimliği alınamadı.');
            }

            $attempt->update(['runner_pid' => $runnerPid]);

            for ($poll = 0; $poll < 30; $poll++) {
                usleep(50_000);
                $state = $this->readState($attempt);

                if (($state['runnerPid'] ?? null) === $runnerPid && ($state['attemptId'] ?? null) === $attempt->id) {
                    return $this->reconcile($attempt);
                }
            }

            throw new BenchmarkRunnerException('Kurtarma koşucusu zamanında hazır duruma gelemedi.');
        } catch (Throwable $exception) {
            if ($attempt?->runner_pid && $this->isRunnerProcess($attempt->runner_pid, $attempt) && function_exists('posix_kill')) {
                posix_kill(-$attempt->runner_pid, SIGTERM);
            }

            File::delete([$lockPath, $jobPath]);

            if ($attempt !== null) {
                $attempt->update([
                    'status' => 'failed',
                    'message' => $exception->getMessage(),
                    'finished_at' => now(),
                ]);
            }

            if ($exception instanceof BenchmarkRunnerException) {
                throw $exception;
            }

            report($exception);

            throw new BenchmarkRunnerException('Kurtarma denemesi başlatılamadı. Uygulama günlüğünü kontrol edin.');
        }
    }

    /**
     * @return array{runPath: string, workspace: string, model: string, port: int, routes: list<string>, openCodeConfigPath: ?string}
     */
    private function modelDefinition(BenchmarkRun $benchmarkRun, ModelRun $modelRun): array
    {
        if (preg_match(self::SAFE_SEGMENT_PATTERN, $benchmarkRun->slug) !== 1
            || preg_match(self::SAFE_SEGMENT_PATTERN, $modelRun->slug) !== 1) {
            throw new BenchmarkRunnerException('Koşu veya model klasörü güvenli bir ada sahip değil.');
        }

        $runsRoot = realpath((string) config('benchforge.runs_path'));
        $runPath = $runsRoot === false ? false : realpath($runsRoot.'/'.$benchmarkRun->slug);

        if ($runsRoot === false || $runPath === false || ! File::isDirectory($runPath) || ! $this->isWithin($runsRoot, $runPath)) {
            throw new BenchmarkRunnerException('Benchmark koşusunun dosyaları bulunamadı.');
        }

        $workspace = realpath($runPath.'/'.$modelRun->slug);

        if ($workspace === false || ! File::isDirectory($workspace) || dirname($workspace) !== $runPath) {
            throw new BenchmarkRunnerException('Model çalışma alanı silinmiş veya güvenli runs alanının dışında.');
        }

        $configPath = realpath($runPath.'/_modeller.conf');

        if ($configPath === false || ! File::isFile($configPath) || dirname($configPath) !== $runPath) {
            throw new BenchmarkRunnerException('Koşunun model ve port yapılandırması bulunamadı.');
        }

        $definition = null;

        foreach (preg_split('/\R/', File::get($configPath)) ?: [] as $rawLine) {
            $line = trim($rawLine);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));

            if (count($parts) === 3 && $parts[0] === $modelRun->slug) {
                $definition = $parts;
                break;
            }
        }

        if ($definition === null) {
            throw new BenchmarkRunnerException('Modelin özgün yapılandırma kaydı bulunamadı.');
        }

        [, $model, $rawPort] = $definition;
        $port = filter_var($rawPort, FILTER_VALIDATE_INT);

        if ($model !== $modelRun->model || $port === false || $port < 1024 || $port > 65535) {
            throw new BenchmarkRunnerException('Modelin özgün kimliği veya port kaydı doğrulanamadı.');
        }

        $routes = collect($benchmarkRun->routes ?? ['/'])
            ->filter(fn (mixed $route): bool => is_string($route) && preg_match("/^\/[A-Za-z0-9\-._~!$&'()*+,;=:@%\/]*$/", $route) === 1)
            ->values()
            ->all();
        $routes = $routes !== [] ? $routes : ['/'];
        $openCodeConfigPath = realpath($runPath.'/_opencode.json');

        if ($openCodeConfigPath !== false && (! File::isFile($openCodeConfigPath) || dirname($openCodeConfigPath) !== $runPath)) {
            $openCodeConfigPath = false;
        }

        return [
            'runPath' => $runPath,
            'workspace' => $workspace,
            'model' => $model,
            'port' => $port,
            'routes' => $routes,
            'openCodeConfigPath' => $openCodeConfigPath === false ? null : $openCodeConfigPath,
        ];
    }

    private function sourceSession(BenchmarkRun $benchmarkRun, ModelRun $modelRun, string $workspace): ?string
    {
        $attempts = $modelRun->modelRunAttempts()
            ->where('mode', 'continue')
            ->orderByDesc('attempt_number')
            ->orderByDesc('id')
            ->get();

        foreach ($attempts as $attempt) {
            $session = $this->verifiedSourceSession($workspace, $attempt->session_id);

            if ($session !== null) {
                return $session;
            }

            $usage = $attempt->usage;

            if (is_array($usage) && ($usage['source'] ?? null) === 'opencode_session') {
                $session = $this->verifiedSourceSession($workspace, $usage['sessionId'] ?? null);

                if ($session !== null) {
                    return $session;
                }
            }
        }

        $sessionsRoot = realpath(dirname($workspace).'/_sessions');
        $sessionPath = $sessionsRoot === false
            ? false
            : realpath($sessionsRoot.'/'.$modelRun->slug.'.session');
        $candidate = $sessionPath !== false
            && dirname($sessionPath) === $sessionsRoot
            && $this->isWithin(dirname($workspace), $sessionPath)
            && File::isFile($sessionPath)
                ? trim(File::get($sessionPath))
                : null;

        if (is_string($candidate) && preg_match(self::SESSION_PATTERN, $candidate) === 1) {
            return $this->verifiedSourceSession($workspace, $candidate);
        }

        return $this->sessionLocator->locate(
            $workspace,
            title: "benchforge:{$benchmarkRun->slug}:{$modelRun->slug}:initial",
        );
    }

    private function verifiedSourceSession(string $workspace, mixed $candidate): ?string
    {
        if (! is_string($candidate) || preg_match(self::SESSION_PATTERN, $candidate) !== 1) {
            return null;
        }

        $located = $this->sessionLocator->locate($workspace, $candidate);

        return $located === $candidate ? $located : null;
    }

    private function hasRecoveredResult(ModelRun $modelRun): bool
    {
        $latest = $modelRun->modelRunAttempts()->latest('attempt_number')->first();

        return $latest?->status === 'completed'
            && ($latest->result['overallStatus'] ?? null) === 'pass';
    }

    private function activeAttemptBlockReason(ModelRun $modelRun, int $port): ?string
    {
        $activeAttempts = ModelRunAttempt::query()
            ->whereIn('status', ModelRunAttempt::ACTIVE_STATUSES)
            ->with('modelRun.benchmarkRun')
            ->get();

        foreach ($activeAttempts as $activeAttempt) {
            if ($activeAttempt->modelRun?->id === $modelRun->id
                || $activeAttempt->modelRun?->model === $modelRun->model) {
                return 'Bu model için başka bir kurtarma denemesi çalışıyor.';
            }
        }

        if ($activeAttempts->count() >= self::MAX_PARALLEL_ATTEMPTS) {
            return 'Aynı anda en fazla iki farklı model kurtarma denemesi çalışabilir.';
        }

        foreach ($activeAttempts as $activeAttempt) {
            $activeModel = $activeAttempt->modelRun;

            if ($activeModel === null || $activeModel->benchmarkRun === null) {
                return 'Aktif denemenin portu doğrulanamadığı için yeni deneme başlatılamıyor.';
            }

            try {
                $activePort = $this->modelDefinition($activeModel->benchmarkRun, $activeModel)['port'];
            } catch (BenchmarkRunnerException) {
                return 'Aktif denemenin portu doğrulanamadığı için yeni deneme başlatılamıyor.';
            }

            if ($activePort === $port) {
                return "{$port} portu başka bir kurtarma denemesine ayrılmış.";
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function parseResult(ModelRunAttempt $attempt): ?array
    {
        $path = $attempt->attempt_path.'/result.txt';

        if (! File::isFile($path) || ! $this->isWithin($attempt->attempt_path, $path)) {
            return null;
        }

        $parts = explode('|', trim(File::get($path)), 11);

        if (count($parts) !== 11) {
            return null;
        }

        [$mode, $sourceSession, $sessionId, $opencode, $lint, $build, $export, $routes, $runtime, $duration, $model] = $parts;

        if (! in_array($mode, ['continue', 'validate'], true) || $mode !== $attempt->mode) {
            return null;
        }

        $stages = [
            'opencode' => $this->stageResult($opencode),
            'lint' => $this->stageResult($lint),
            'build' => $this->stageResult($build),
            'export' => $this->stageResult($export),
            'routes' => $this->stageResult($routes),
            'runtime' => $this->stageResult($runtime),
        ];

        if ($stages['build']['status'] !== 'pass') {
            foreach (['export', 'routes', 'runtime'] as $stage) {
                $stages[$stage] = $this->skippedStage();
            }
        } elseif ($stages['export']['status'] !== 'pass') {
            foreach (['routes', 'runtime'] as $stage) {
                $stages[$stage] = $this->skippedStage();
            }
        }

        $requiredStages = $mode === 'continue'
            ? ['opencode', 'lint', 'build', 'export', 'routes', 'runtime']
            : ['lint', 'build', 'export', 'routes', 'runtime'];
        $passed = collect($requiredStages)->every(fn (string $stage): bool => $stages[$stage]['status'] === 'pass');
        $validSession = preg_match(self::SESSION_PATTERN, $sessionId) === 1 ? $sessionId : null;

        return [
            'mode' => $mode,
            'model' => $model,
            'sourceSessionId' => preg_match(self::SESSION_PATTERN, $sourceSession) === 1 ? $sourceSession : null,
            'sessionId' => $validSession,
            'durationSeconds' => max(0, (int) $duration),
            'overallStatus' => $passed ? 'pass' : 'fail',
            'failedStages' => array_keys(array_filter(
                $stages,
                fn (array $stage): bool => $stage['status'] === 'fail',
            )),
            'stages' => $stages,
        ];
    }

    /** @return array{status: string, code: ?int, rawValue: string, message: ?string} */
    private function stageResult(string $rawValue): array
    {
        if ($rawValue === 'skip') {
            return $this->skippedStage();
        }

        $code = filter_var($rawValue, FILTER_VALIDATE_INT);

        return [
            'status' => $code === 0 ? 'pass' : ($code === false ? 'unknown' : 'fail'),
            'code' => $code === false ? null : $code,
            'rawValue' => $rawValue,
            'message' => null,
        ];
    }

    /** @return array{status: 'skip', code: null, rawValue: 'skip', message: string} */
    private function skippedStage(): array
    {
        return [
            'status' => 'skip',
            'code' => null,
            'rawValue' => 'skip',
            'message' => 'Önceki aşama başarısız olduğu için çalıştırılmadı.',
        ];
    }

    /** @return array<string, mixed>|null */
    private function readState(ModelRunAttempt $attempt): ?array
    {
        if (! File::isFile($attempt->state_path) || ! $this->isWithin($attempt->attempt_path, $attempt->state_path)) {
            return null;
        }

        try {
            $state = json_decode(File::get($attempt->state_path), true, flags: JSON_THROW_ON_ERROR);

            return is_array($state) && ($state['version'] ?? null) === 1 ? $state : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $state */
    private function writeState(ModelRunAttempt $attempt, array $state): void
    {
        if (! $this->isWithin($attempt->attempt_path, $attempt->state_path)) {
            throw new BenchmarkRunnerException('Kurtarma durum dosyası güvenli deneme klasörünün dışında.');
        }

        File::ensureDirectoryExists(dirname($attempt->state_path), 0700, true);
        $temporaryPath = $attempt->state_path.'.php-'.getmypid().'.tmp';
        File::put(
            $temporaryPath,
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL,
            true,
        );
        File::move($temporaryPath, $attempt->state_path);
    }

    private function isRunnerProcess(?int $pid, ModelRunAttempt $attempt): bool
    {
        if ($pid === null || $pid <= 1) {
            return false;
        }

        $process = new Process(['ps', '-p', (string) $pid, '-o', 'command='], null, $this->processEnvironment->make(commandPath: '/usr/bin:/bin'));
        $process->setTimeout(3);
        $process->run();

        $command = trim($process->getOutput());

        return $process->isSuccessful()
            && str_contains($command, base_path('bin/model-run-attempt-worker.mjs'))
            && str_contains($command, $attempt->attempt_path.'/job.json');
    }

    private function ensurePortAvailable(int $port): void
    {
        $socket = @stream_socket_server("tcp://127.0.0.1:{$port}");

        if ($socket === false) {
            throw new BenchmarkRunnerException("{$port} portu kullanımda. Önce çalışan süreci kapatın.");
        }

        fclose($socket);
    }

    private function projectRoot(): string
    {
        return $this->configuredPath('root_path');
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

    /** @return array<string, string|false> */
    private function runnerEnvironment(): array
    {
        $homePath = realpath((string) config('benchforge.home_path'));
        $commandPath = trim((string) config('benchforge.command_path'));

        if ($homePath === false || ! File::isDirectory($homePath) || $commandPath === '') {
            throw new BenchmarkRunnerException('Kurtarma koşucusunun kullanıcı veya komut yolu yapılandırılmadı.');
        }

        $environment = $this->processEnvironment->make($homePath, $commandPath);

        if (getenv('BENCHFORGE_SKIP_BROWSER_SMOKE') === '1') {
            $environment['BENCHFORGE_SKIP_BROWSER_SMOKE'] = '1';
        }

        $timeoutSeconds = filter_var(getenv('BENCHFORGE_ATTEMPT_TIMEOUT_SECONDS'), FILTER_VALIDATE_INT);

        if (is_int($timeoutSeconds) && $timeoutSeconds >= 60 && $timeoutSeconds <= 7200) {
            $environment['BENCHFORGE_ATTEMPT_TIMEOUT_SECONDS'] = (string) $timeoutSeconds;
        }

        return $environment;
    }

    private function isWithin(string $parent, string $candidate): bool
    {
        $normalizedParent = rtrim($parent, DIRECTORY_SEPARATOR);

        return $candidate === $normalizedParent
            || str_starts_with($candidate, $normalizedParent.DIRECTORY_SEPARATOR);
    }
}
