<?php

namespace App\Services;

use App\Exceptions\BenchmarkRunnerException;
use App\Models\OpenCodeGoModel;
use App\Models\OpenRouterModel;
use App\Models\WorkflowRun;
use App\Models\WorkflowRunModel;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use stdClass;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Starts, follows and stops workflow runs.
 *
 * A run is one detached `bin/workflow-worker.mjs` process group, started through `bin/benchmark-launcher.mjs` like the
 * preparation worker. PHP writes `job.json`, the template snapshot, the inputs and a restricted OpenCode configuration
 * per model; the worker owns `state.json`. Reconciliation copies the worker's state into the database and finishes runs
 * whose worker has exited. Only one workflow runs at a time (lock `benchforge:workflow:lifecycle`).
 */
class WorkflowRunner
{
    public const JOB_SCHEMA = 'benchforge-workflow-job/1';

    private const AGENT = 'benchforge-workflow';

    private const RUN_STATUSES = ['starting', 'running', 'stopping', 'completed', 'partial', 'failed', 'stopped'];

    private const MODEL_STATUSES = ['pending', 'running', 'completed', 'partial', 'failed', 'stopped'];

    private const FINAL_MODEL_STATUSES = ['completed', 'partial', 'failed', 'stopped'];

    private const STEP_STATUSES = ['pending', 'running', 'completed', 'partial', 'failed', 'stopped', 'skipped'];

    private const COUNT_KEYS = ['total', 'completed', 'failed', 'running', 'skipped', 'stopped'];

    private const MAX_PARALLEL_MODELS = 5;

    private const MAX_CONCURRENT_CALLS = 10;

    private const MIN_TIMEOUT_SECONDS = 30;

    private const MAX_TIMEOUT_SECONDS = 7200;

    private const READY_ATTEMPTS = 60;

    private const READY_INTERVAL_MICROSECONDS = 50_000;

    /**
     * How long a 'starting' run without a worker pid belongs to start(), which writes its folder and launches the worker
     * while holding the lifecycle lock. The launcher itself times out after 10 s.
     */
    private const START_GRACE_SECONDS = 30;

    private const LAST_OUTPUT_STATUSES = ['completed', 'failed'];

    private const TAIL_CHARACTERS = 4096;

    private const ISO_TIME_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-](?:0\d|1[0-4]):[0-5]\d)$/';

    private const MAX_COST_USD = 999_999;

    private const UNSIGNED_INTEGER_MAX = 4_294_967_295;

    private const WORKER_MODEL_PATTERN = '/^(?:openrouter\/[A-Za-z0-9._:-]+\/[A-Za-z0-9._:\/-]+|opencode-go\/[A-Za-z0-9._:\/-]+)$/';

    private const UNEXPECTED_EXIT_MESSAGE = 'İş akışı süreci beklenmedik şekilde kapandı.';

    private const STOPPED_MESSAGE = 'Çalıştırma durduruldu.';

    /**
     * Create a new service instance.
     */
    public function __construct(
        private WorkflowTemplateStore $templateStore,
        private WorkflowTemplateValidator $templateValidator,
        private ModelSelection $modelSelection,
        private ProviderConnectionStatus $providerConnectionStatus,
        private BenchmarkProcessEnvironment $processEnvironment,
        private WorkflowRunFiles $files,
    ) {}

    /**
     * @param  array<string, mixed>  $input  StartWorkflowRunRequest::workflowInput()
     */
    public function start(array $input): WorkflowRun
    {
        try {
            return Cache::lock('benchforge:workflow:lifecycle', 120)
                ->block(5, fn (): WorkflowRun => $this->startLocked($input));
        } catch (LockTimeoutException) {
            throw new BenchmarkRunnerException('Başka bir iş akışı başlatılıyor ya da durduruluyor. Biraz sonra yeniden deneyin.');
        }
    }

    public function reconcile(WorkflowRun $run): WorkflowRun
    {
        $run->refresh()->load('models');

        if (! $run->isActive() || $this->isBeingStarted($run)) {
            return $run;
        }

        $workingDirectory = $this->files->runDirectory($run);

        if ($workingDirectory === null) {
            $this->finishModels($run, 'failed', 'İş akışı çalışma alanı güvenli biçimde doğrulanamadı.');
            $run->update([
                'status' => 'failed',
                'message' => 'İş akışı çalışma alanı güvenli biçimde doğrulanamadı; hiçbir sürece sinyal gönderilmedi.',
                'finished_at' => now(),
            ]);

            return $run->refresh()->load('models');
        }

        // Check liveness before reading the state: a worker that writes its final state and exits in
        // between would otherwise look dead with unfinished models and be marked as failed.
        $runnerIsAlive = $this->isRunnerProcess($run->runner_pid, $workingDirectory);
        $state = $this->readState($run);

        if ($state !== null) {
            $this->syncModels($run, $state);
        }

        $run->refresh()->load('models');
        $stateStatus = $this->allowed($state['status'] ?? null, self::RUN_STATUSES);

        if (! $runnerIsAlive) {
            if ($stateStatus !== null && in_array($stateStatus, WorkflowRun::FINAL_STATUSES, true)) {
                $stopped = $stateStatus === 'stopped';
                $this->finishModels($run, $stopped ? 'stopped' : 'failed', $stopped ? self::STOPPED_MESSAGE : self::UNEXPECTED_EXIT_MESSAGE);
                $status = $stateStatus;
                $message = $this->message($state['message'] ?? null, 1000) ?? $this->statusMessage($status);
            } else {
                $stopping = $run->status === 'stopping' || $stateStatus === 'stopping';
                $this->finishModels($run, $stopping ? 'stopped' : 'failed', $stopping ? self::STOPPED_MESSAGE : self::UNEXPECTED_EXIT_MESSAGE);
                $run->refresh()->load('models');
                $status = $stopping ? 'stopped' : $this->derivedStatus($run);
                $message = $stopping ? self::STOPPED_MESSAGE : self::UNEXPECTED_EXIT_MESSAGE;
            }

            $run->update([
                'status' => $status,
                'message' => $message,
                'finished_at' => $this->timestamp($state['finishedAt'] ?? null) ?? now(),
            ]);
        } elseif ($state !== null) {
            $stateMessage = $this->message($state['message'] ?? null, 1000);
            $stopping = $run->status === 'stopping';

            $run->update([
                'status' => $stopping
                    ? 'stopping'
                    : ($stateStatus !== null && in_array($stateStatus, WorkflowRun::ACTIVE_STATUSES, true) ? $stateStatus : $run->status),
                'message' => $stateMessage ?? ($stopping ? $run->message : null),
            ]);
        }

        return $run->refresh()->load('models');
    }

    public function stop(WorkflowRun $run): WorkflowRun
    {
        try {
            return Cache::lock('benchforge:workflow:lifecycle', 30)
                ->block(5, function () use ($run): WorkflowRun {
                    $run = $this->reconcile($run);

                    if (! $run->isActive()) {
                        throw new BenchmarkRunnerException('Bu iş akışı artık çalışmıyor.');
                    }

                    $workingDirectory = $this->files->runDirectory($run);

                    if ($workingDirectory === null
                        || ! function_exists('posix_kill')
                        || ! $this->isRunnerProcess($run->runner_pid, $workingDirectory)) {
                        throw new BenchmarkRunnerException('İş akışı durdurulamadı.');
                    }

                    $previousStatus = $run->status;
                    $previousMessage = $run->message;
                    $run->update(['status' => 'stopping', 'message' => 'Çalıştırma durduruluyor.']);

                    if (! posix_kill(-$run->runner_pid, SIGTERM)) {
                        $run->update(['status' => $previousStatus, 'message' => $previousMessage]);

                        throw new BenchmarkRunnerException('İş akışı durdurulamadı.');
                    }

                    return $run->refresh()->load('models');
                });
        } catch (LockTimeoutException) {
            throw new BenchmarkRunnerException('Bu iş akışı için başka bir işlem sürüyor. Biraz sonra yeniden deneyin.');
        }
    }

    /**
     * The live view of a run (§12.1). Steps follow the template order; usage totals are null unless every contributing
     * model reported the value. Every time is ISO-8601 in the app timezone (null when the worker wrote no valid time),
     * so the header and the event log show one clock. No file paths are included.
     *
     * @return array<string, mixed>
     */
    public function snapshot(WorkflowRun $run): array
    {
        $run = $this->reconcile($run);
        $state = $this->readState($run);
        $template = $this->files->template($run);
        $stateModels = is_array($state['models'] ?? null) ? $state['models'] : [];
        $models = [];
        $callsKnown = [];

        foreach ($run->models as $model) {
            $modelState = is_array($stateModels[$model->slug] ?? null)
                && ($stateModels[$model->slug]['model'] ?? $model->model) === $model->model
                    ? $stateModels[$model->slug]
                    : null;
            $models[] = $this->modelSnapshot($run, $model, $modelState ?? [], $template);
            $callsKnown[] = is_int($modelState['usage']['calls'] ?? null);
        }

        return [
            'status' => $run->status,
            'active' => $run->isActive(),
            'message' => $run->message,
            'startedAt' => $this->isoTime($run->started_at),
            'finishedAt' => $this->isoTime($run->finished_at),
            'durationSeconds' => $this->elapsedSeconds($run),
            'totals' => [
                'inputTokens' => $this->usageTotal($models, $callsKnown, 'inputTokens'),
                'outputTokens' => $this->usageTotal($models, $callsKnown, 'outputTokens'),
                'costUsd' => $this->usageTotal($models, $callsKnown, 'costUsd'),
                'calls' => array_sum(array_map(fn (array $model): int => $model['usage']['calls'], $models)),
                'itemsTotal' => array_sum(array_map(fn (array $model): int => $model['items']['total'], $models)),
                'itemsCompleted' => array_sum(array_map(fn (array $model): int => $model['items']['completed'], $models)),
                'itemsFailed' => array_sum(array_map(fn (array $model): int => $model['items']['failed'], $models)),
            ],
            'models' => $models,
            'events' => array_map(
                fn (array $event): array => [...$event, 'time' => $this->isoTime($event['time'])],
                $this->files->eventsTail($run, 80),
            ),
        ];
    }

    /**
     * The latest run that is still active after reconciliation.
     */
    public function active(): ?WorkflowRun
    {
        $run = WorkflowRun::query()
            ->whereIn('status', WorkflowRun::ACTIVE_STATUSES)
            ->latest('id')
            ->first();

        if ($run === null) {
            return null;
        }

        $run = $this->reconcile($run);

        return $run->isActive() ? $run : null;
    }

    /**
     * The worker entry point. Tests replace it with a fake worker script.
     */
    protected function workerScript(): string
    {
        return base_path('bin/workflow-worker.mjs');
    }

    /** @param array<string, mixed> $input */
    private function startLocked(array $input): WorkflowRun
    {
        app(BenchForgeSettings::class)->apply();

        $activeRun = WorkflowRun::query()
            ->whereIn('status', WorkflowRun::ACTIVE_STATUSES)
            ->latest('id')
            ->first();

        if ($activeRun !== null) {
            $activeRun = $this->reconcile($activeRun);

            if ($activeRun->isActive()) {
                throw new BenchmarkRunnerException("“{$activeRun->title}” iş akışı halen çalışıyor. Aynı anda tek iş akışı çalışabilir.");
            }
        }

        $template = $this->validatedTemplate($input);
        $inputs = $this->validatedInputs($template['template'], $input['inputs'] ?? []);
        $settings = $this->validatedSettings($input);
        $models = $this->selectedModels($this->modelSelection->resolveForWorkflow($input, refreshProviders: true));
        $settings['maxParallelModels'] = min($settings['maxParallelModels'], count($models));
        $settings['stepModes'] = $this->stepModes($template['template']);

        foreach (array_unique(array_column($models, 'provider')) as $provider) {
            $this->ensureConnectionReady($provider);
        }

        $openCodeBinary = $this->findOpenCodeBinary();
        $runsRoot = $this->files->runsRoot();
        $slug = $this->runSlug((string) $template['template']['id'], $runsRoot);
        $workingDirectory = $runsRoot.'/'.$slug;

        $run = WorkflowRun::query()->create([
            'slug' => $slug,
            'template_id' => $template['template']['id'],
            'template_version' => $template['version'],
            'template_sha256' => $template['sha256'],
            'title' => Str::limit(trim((string) $template['template']['name']), 120, ''),
            'status' => 'starting',
            'inputs' => $inputs,
            'settings' => $settings,
            'working_directory' => $workingDirectory,
            'started_at' => now(),
            'message' => 'İş akışı için güvenli çalışma alanı hazırlanıyor.',
        ]);

        foreach ($models as $model) {
            $run->models()->create([
                'model' => $model['model'],
                'model_name' => $model['name'],
                'slug' => $model['slug'],
                'provider' => $model['provider'],
                'status' => 'pending',
            ]);
        }

        $runnerPid = null;

        try {
            if (file_exists($workingDirectory) || is_link($workingDirectory)) {
                throw new BenchmarkRunnerException('İş akışı çalışma klasörü zaten var; mevcut dosyalara dokunulmadı.');
            }

            File::ensureDirectoryExists($workingDirectory, 0700, false);
            @chmod($workingDirectory, 0700);

            if (realpath($workingDirectory) !== $workingDirectory || is_link($workingDirectory)) {
                throw new BenchmarkRunnerException('İş akışı çalışma klasörü doğrulanamadı.');
            }

            $this->writePrivateFile($workingDirectory.'/template.json', $template['raw'], $workingDirectory);
            $this->writeJson($workingDirectory.'/inputs.json', $this->jsonObject($inputs), $workingDirectory);
            $jobModels = [];

            foreach ($models as $model) {
                $modelDirectory = $workingDirectory.'/models/'.$model['slug'];
                File::ensureDirectoryExists($modelDirectory, 0700, true);

                if (realpath($modelDirectory) !== $modelDirectory || is_link($modelDirectory)) {
                    throw new BenchmarkRunnerException('Model klasörü güvenli biçimde oluşturulamadı.');
                }

                $this->writeJson($modelDirectory.'/opencode.json', $this->openCodeConfig($model), $workingDirectory);
                $jobModels[] = [
                    'slug' => $model['slug'],
                    'model' => $model['model'],
                    'name' => $model['name'],
                    'provider' => $model['provider'],
                    'configFile' => 'models/'.$model['slug'].'/opencode.json',
                ];
            }

            $jobPath = $workingDirectory.'/job.json';
            $this->writeJson($jobPath, [
                'schema' => self::JOB_SCHEMA,
                'runId' => $slug,
                'runDirectory' => $workingDirectory,
                'templateFile' => 'template.json',
                'inputs' => $this->jsonObject($inputs),
                'models' => $jobModels,
                'settings' => [
                    'maxParallelModels' => $settings['maxParallelModels'],
                    'maxConcurrentCalls' => $settings['maxConcurrentCalls'],
                    'stepModes' => $this->jsonObject($settings['stepModes']),
                    'timeouts' => $settings['timeouts'],
                ],
                'opencode' => ['binary' => $openCodeBinary],
                'createdAt' => $run->started_at->toIso8601String(),
            ], $workingDirectory);

            $launcher = new Process([
                $this->nodeBinary(),
                base_path('bin/benchmark-launcher.mjs'),
                $this->workerScript(),
                $jobPath,
            ], base_path());
            $launcher->setEnv($this->runnerEnvironment());
            $launcher->setTimeout(10);
            $launcher->mustRun();
            $runnerPid = filter_var(trim($launcher->getOutput()), FILTER_VALIDATE_INT);

            if ($runnerPid === false || $runnerPid <= 1) {
                throw new BenchmarkRunnerException('İş akışı işçisinin işlem kimliği alınamadı.');
            }

            $run->update(['runner_pid' => $runnerPid]);

            for ($attempt = 0; $attempt < self::READY_ATTEMPTS; $attempt++) {
                usleep(self::READY_INTERVAL_MICROSECONDS);

                if ($this->readState($run->refresh()) !== null) {
                    return $this->reconcile($run);
                }
            }

            throw new BenchmarkRunnerException('İş akışı işçisi zamanında hazır duruma gelemedi.');
        } catch (Throwable $exception) {
            if (is_int($runnerPid) && $runnerPid > 1) {
                $this->terminateOwnedProcess($run->refresh(), $workingDirectory);
            }

            $run->models()
                ->whereNotIn('status', self::FINAL_MODEL_STATUSES)
                ->update(['status' => 'failed', 'error_message' => 'İş akışı başlatılamadı.']);
            $run->update([
                'status' => 'failed',
                'message' => $exception instanceof BenchmarkRunnerException
                    ? $exception->getMessage()
                    : 'İş akışı başlatılamadı. Uygulama günlüğünü kontrol edin.',
                'finished_at' => now(),
            ]);

            if ($exception instanceof BenchmarkRunnerException) {
                throw $exception;
            }

            report($exception);

            throw new BenchmarkRunnerException('İş akışı başlatılamadı. Uygulama günlüğünü kontrol edin.');
        }
    }

    /**
     * The requested template version, validated again from its exact bytes, which become the run's snapshot.
     *
     * @param  array<string, mixed>  $input
     * @return array{template: array<string, mixed>, raw: string, sha256: string, version: int}
     */
    private function validatedTemplate(array $input): array
    {
        $templateId = $input['template_id'] ?? null;
        $version = $input['template_version'] ?? null;
        $version = $version === null ? null : filter_var($version, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (! is_string($templateId) || preg_match(WorkflowTemplateStore::ID_PATTERN, $templateId) !== 1 || $version === false) {
            throw new BenchmarkRunnerException('Şablon bulunamadı.');
        }

        $found = $this->templateStore->find($templateId, $version);

        if ($found === null) {
            throw new BenchmarkRunnerException('Şablon bulunamadı ya da geçersiz.');
        }

        try {
            $template = json_decode($found['raw'], true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $template = null;
        }

        $errors = $this->templateValidator->validate($template);

        if ($errors !== []
            || ! is_array($template)
            || ($template['id'] ?? null) !== $templateId
            || hash('sha256', $found['raw']) !== $found['sha256']) {
            throw new BenchmarkRunnerException('Şablon doğrulanamadı: '.($errors[0] ?? 'dosya içeriği beklenen şablonla eşleşmiyor.'));
        }

        return [
            'template' => $template,
            'raw' => $found['raw'],
            'sha256' => $found['sha256'],
            'version' => $found['version'],
        ];
    }

    /**
     * @param  array<string, mixed>  $template
     * @return array<string, mixed>
     */
    private function validatedInputs(array $template, mixed $inputs): array
    {
        $result = $this->templateValidator->validateInputs($template, is_array($inputs) ? $inputs : []);

        if ($result['errors'] !== []) {
            throw new BenchmarkRunnerException((string) reset($result['errors']), ['errors' => $result['errors']]);
        }

        return $result['values'];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{maxParallelModels: int, maxConcurrentCalls: int, stepModes: array<string, string>, timeouts: array{llm: ?int, code: ?int}}
     */
    private function validatedSettings(array $input): array
    {
        $maxParallel = filter_var($input['max_parallel'] ?? 1, FILTER_VALIDATE_INT);

        if ($maxParallel === false || $maxParallel < 1 || $maxParallel > self::MAX_PARALLEL_MODELS) {
            throw new BenchmarkRunnerException('Aynı anda çalışacak model sayısı 1 ile 5 arasında olmalıdır.');
        }

        $maxConcurrentCalls = filter_var($input['max_concurrent_calls'] ?? 2, FILTER_VALIDATE_INT);

        if ($maxConcurrentCalls === false || $maxConcurrentCalls < 1 || $maxConcurrentCalls > self::MAX_CONCURRENT_CALLS) {
            throw new BenchmarkRunnerException('Eşzamanlı model çağrısı sayısı 1 ile 10 arasında olmalıdır.');
        }

        $requestedTimeouts = is_array($input['timeouts'] ?? null) ? $input['timeouts'] : [];
        $timeouts = [];

        foreach (['llm', 'code'] as $kind) {
            $value = $requestedTimeouts[$kind] ?? null;

            if ($value === null || $value === '') {
                $timeouts[$kind] = null;

                continue;
            }

            $seconds = filter_var($value, FILTER_VALIDATE_INT);

            if ($seconds === false || $seconds < self::MIN_TIMEOUT_SECONDS || $seconds > self::MAX_TIMEOUT_SECONDS) {
                throw new BenchmarkRunnerException('Süre sınırı 30 ile 7200 saniye arasında olmalıdır.');
            }

            $timeouts[$kind] = $seconds;
        }

        return [
            'maxParallelModels' => $maxParallel,
            'maxConcurrentCalls' => $maxConcurrentCalls,
            'stepModes' => [],
            'timeouts' => ['llm' => $timeouts['llm'], 'code' => $timeouts['code']],
        ];
    }

    /**
     * Every `code_text` step, top level or inside a foreach, with its Phase 1 mode.
     *
     * @param  array<string, mixed>  $template
     * @return array<string, string>
     */
    private function stepModes(array $template): array
    {
        $modes = [];

        foreach ($template['steps'] as $step) {
            foreach ([$step, ...(($step['type'] ?? null) === 'foreach' ? $step['steps'] : [])] as $candidate) {
                if (($candidate['type'] ?? null) === 'code_text') {
                    $modes[(string) $candidate['id']] = 'code_text';
                }
            }
        }

        return $modes;
    }

    /**
     * @param  array{models: list<array{provider: string, modelId: string, model: string, folder: string, port: int, routing: ?array<string, mixed>}>}  $selection
     * @return list<array{slug: string, model: string, modelId: string, provider: string, name: string, routing: ?array<string, mixed>}>
     */
    private function selectedModels(array $selection): array
    {
        $entries = $selection['models'];

        if ($entries === [] || count($entries) > ModelSelection::WORKFLOW_MODEL_LIMIT) {
            throw new BenchmarkRunnerException('İş akışı için 1–'.ModelSelection::WORKFLOW_MODEL_LIMIT.' model seçin.');
        }

        $names = $this->catalogNames($entries);
        $models = [];
        $slugs = [];

        foreach ($entries as $entry) {
            $model = $entry['model'];

            if (! in_array($entry['provider'], ModelSelection::WORKFLOW_PROVIDERS, true)
                || preg_match(self::WORKER_MODEL_PATTERN, $model) !== 1
                || strlen($model) > 255) {
                throw new BenchmarkRunnerException("{$model} modeli iş akışlarında kullanılamıyor.");
            }

            $slug = ModelSelection::folderFor($model);

            if (preg_match(WorkflowRunFiles::MODEL_SLUG_PATTERN, $slug) !== 1 || isset($slugs[$slug])) {
                throw new BenchmarkRunnerException('Model klasör adı güvenli biçimde oluşturulamadı.');
            }

            $slugs[$slug] = true;
            $name = Str::limit(trim((string) ($names[$model] ?? '')), 255, '');
            $models[] = [
                'slug' => $slug,
                'model' => $model,
                'modelId' => $entry['modelId'],
                'provider' => $entry['provider'],
                'name' => $name !== '' ? $name : Str::limit($entry['modelId'], 255, ''),
                'routing' => $entry['routing'],
            ];
        }

        return $models;
    }

    /**
     * Display names from the catalogs, keyed by provider-prefixed model.
     *
     * @param  list<array{provider: string, modelId: string, model: string}>  $entries
     * @return array<string, string>
     */
    private function catalogNames(array $entries): array
    {
        $names = [];

        foreach (['openrouter' => OpenRouterModel::class, 'opencode-go' => OpenCodeGoModel::class] as $provider => $modelClass) {
            $modelIds = array_values(array_map(
                fn (array $entry): string => $entry['modelId'],
                array_filter($entries, fn (array $entry): bool => $entry['provider'] === $provider),
            ));

            if ($modelIds === []) {
                continue;
            }

            foreach ($modelClass::query()->whereIn('model_id', $modelIds)->pluck('name', 'model_id') as $modelId => $name) {
                $names[$provider.'/'.$modelId] = (string) $name;
            }
        }

        return $names;
    }

    private function ensureConnectionReady(string $provider): void
    {
        $connections = $this->providerConnectionStatus->inspect();

        if (($connections['cli']['status'] ?? null) !== 'pass') {
            throw new BenchmarkRunnerException('OpenCode CLI hazır değil. Bağlantı durumunu yenileyip tekrar deneyin.');
        }

        if (($connections['providers'][$provider]['status'] ?? null) !== 'pass') {
            $label = $provider === 'openrouter' ? 'OpenRouter' : 'OpenCode Go';

            throw new BenchmarkRunnerException("{$label} için OpenCode bağlantısı hazır değil. Bağlantı durumunu yenileyip tekrar deneyin.");
        }
    }

    /**
     * A tool-less OpenCode configuration for one model: no tools, no permissions, no plugins, MCP servers or instructions.
     *
     * @param  array{modelId: string, provider: string, routing: ?array<string, mixed>}  $model
     * @return array<string, mixed>
     */
    private function openCodeConfig(array $model): array
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
                self::AGENT => [
                    'description' => 'BenchForge iş akışı adımı',
                    'mode' => 'primary',
                    'prompt' => "Follow the user's instructions exactly. Do not use tools, do not ask questions, answer only with the requested output.",
                    'tools' => ['*' => false],
                    'permission' => ['*' => 'deny'],
                ],
            ],
        ];

        if ($model['provider'] === 'openrouter') {
            $config['provider'] = [
                'openrouter' => [
                    'models' => [
                        $model['modelId'] => [
                            'options' => [
                                'provider' => $model['routing'],
                            ],
                        ],
                    ],
                ],
            ];
        }

        return $config;
    }

    /**
     * `<templateId>-<Ymd-His>`, with `-2`, `-3`, … when that name is already used by a run row or folder.
     */
    private function runSlug(string $templateId, string $runsRoot): string
    {
        $base = rtrim(Str::limit($templateId, 62, ''), '-').'-'.now()->format('Ymd-His');

        for ($suffix = 1; $suffix <= 99; $suffix++) {
            $slug = $suffix === 1 ? $base : $base.'-'.$suffix;

            if (preg_match(WorkflowRunFiles::RUN_SLUG_PATTERN, $slug) === 1
                && ! file_exists($runsRoot.'/'.$slug)
                && ! is_link($runsRoot.'/'.$slug)
                && ! WorkflowRun::query()->where('slug', $slug)->exists()) {
                return $slug;
            }
        }

        throw new BenchmarkRunnerException('Çalıştırma için benzersiz bir ad bulunamadı.');
    }

    /**
     * Copies each unfinished model's status, message, duration, usage and item counts from the worker state.
     *
     * @param  array<string, mixed>  $state
     */
    private function syncModels(WorkflowRun $run, array $state): void
    {
        $stateModels = $state['models'] ?? null;

        if (! is_array($stateModels) || ($stateModels !== [] && array_is_list($stateModels))) {
            return;
        }

        foreach ($run->models as $model) {
            $modelState = $stateModels[$model->slug] ?? null;

            if (in_array($model->status, self::FINAL_MODEL_STATUSES, true)
                || ! is_array($modelState)
                || ($modelState['model'] ?? $model->model) !== $model->model) {
                continue;
            }

            $status = $this->allowed($modelState['status'] ?? null, self::MODEL_STATUSES);

            if ($status === null) {
                continue;
            }

            $usage = is_array($modelState['usage'] ?? null) ? $modelState['usage'] : [];
            $items = is_array($modelState['items'] ?? null) ? $modelState['items'] : [];
            $cost = $this->number($usage['costUsd'] ?? null, self::MAX_COST_USD);

            $model->update([
                'status' => $status,
                'error_message' => $this->message($modelState['message'] ?? null, 2000),
                'duration_seconds' => $this->duration($modelState['durationSeconds'] ?? null),
                'input_tokens' => $this->nonNegativeInteger($usage['inputTokens'] ?? null),
                'output_tokens' => $this->nonNegativeInteger($usage['outputTokens'] ?? null),
                'cost_usd' => $cost === null ? null : number_format($cost, 6, '.', ''),
                'items_total' => $this->boundedCount($items['total'] ?? null),
                'items_completed' => $this->boundedCount($items['completed'] ?? null),
                'items_failed' => $this->boundedCount($items['failed'] ?? null),
            ]);
        }
    }

    /**
     * start() creates the run row before it writes the folder and launches the worker, and it stores the worker pid only
     * after the launcher returns. Until then the run is still start()'s, which holds the lifecycle lock, so an unlocked
     * reconciliation (a page load) must not mistake it for a dead worker. A start that never stored a pid is failed once
     * the grace period has passed.
     */
    private function isBeingStarted(WorkflowRun $run): bool
    {
        $startedAt = $run->started_at ?? $run->created_at;

        return $run->status === 'starting'
            && $run->runner_pid === null
            && $startedAt !== null
            && $startedAt->greaterThan(now()->subSeconds(self::START_GRACE_SECONDS));
    }

    private function finishModels(WorkflowRun $run, string $status, string $message): void
    {
        $run->models()
            ->whereNotIn('status', self::FINAL_MODEL_STATUSES)
            ->update(['status' => $status, 'error_message' => $message]);
    }

    /**
     * completed when every model completed, partial when at least one model produced something, otherwise failed.
     */
    private function derivedStatus(WorkflowRun $run): string
    {
        $statuses = $run->models->pluck('status');
        $completed = $statuses->filter(fn (string $status): bool => $status === 'completed')->count();

        if ($completed > 0 && $completed === $statuses->count()) {
            return 'completed';
        }

        if ($statuses->contains(fn (string $status): bool => in_array($status, ['completed', 'partial'], true))) {
            return 'partial';
        }

        return 'failed';
    }

    private function statusMessage(string $status): string
    {
        return match ($status) {
            'completed' => 'İş akışı tamamlandı.',
            'partial' => 'İş akışı kısmen tamamlandı; bazı adımlar başarısız oldu.',
            'stopped' => self::STOPPED_MESSAGE,
            default => 'İş akışı başarısız oldu.',
        };
    }

    /**
     * @param  array<string, mixed>  $modelState
     * @param  array<string, mixed>|null  $template
     * @return array<string, mixed>
     */
    private function modelSnapshot(WorkflowRun $run, WorkflowRunModel $model, array $modelState, ?array $template): array
    {
        $stateUsage = is_array($modelState['usage'] ?? null) ? $modelState['usage'] : [];

        return [
            'slug' => $model->slug,
            'model' => $model->model,
            'name' => $model->model_name,
            'status' => $model->status,
            'message' => $model->error_message,
            'durationSeconds' => $model->duration_seconds === null ? null : (float) $model->duration_seconds,
            'usage' => [
                'inputTokens' => $model->input_tokens,
                'outputTokens' => $model->output_tokens,
                'costUsd' => $model->cost_usd === null ? null : (float) $model->cost_usd,
                'calls' => $this->nonNegativeInteger($stateUsage['calls'] ?? null) ?? 0,
            ],
            'items' => [
                'total' => (int) $model->items_total,
                'completed' => (int) $model->items_completed,
                'failed' => (int) $model->items_failed,
            ],
            'steps' => $this->stepSnapshots($template, is_array($modelState['steps'] ?? null) ? $modelState['steps'] : []),
            'active' => $run->isActive() ? $this->activeCalls($modelState['active'] ?? null) : [],
            'lastOutput' => $this->lastOutput($modelState['lastOutput'] ?? null),
        ];
    }

    /**
     * Top-level steps in template order with their state; foreach steps also list their children, counts and items.
     *
     * @param  array<string, mixed>|null  $template
     * @param  array<string, mixed>  $stateSteps
     * @return list<array<string, mixed>>
     */
    private function stepSnapshots(?array $template, array $stateSteps): array
    {
        if ($template === null) {
            return [];
        }

        $steps = [];

        foreach ($template['steps'] as $step) {
            if (! is_array($step) || ! is_string($step['id'] ?? null)) {
                continue;
            }

            $stepState = is_array($stateSteps[$step['id']] ?? null) ? $stateSteps[$step['id']] : [];
            $isForeach = ($step['type'] ?? null) === 'foreach';
            $children = $isForeach && is_array($step['steps'] ?? null)
                ? array_values(array_map(fn (mixed $child): array => [
                    'id' => (string) ($child['id'] ?? ''),
                    'type' => (string) ($child['type'] ?? ''),
                    'title' => (string) ($child['title'] ?? ($child['id'] ?? '')),
                ], array_filter($step['steps'], 'is_array')))
                : null;

            $steps[] = [
                'id' => $step['id'],
                'type' => (string) ($step['type'] ?? ''),
                'title' => (string) ($step['title'] ?? $step['id']),
                'status' => $this->allowed($stepState['status'] ?? null, self::STEP_STATUSES) ?? 'pending',
                'message' => $this->message($stepState['message'] ?? null, 1000),
                'durationSeconds' => $this->duration($stepState['durationSeconds'] ?? null),
                'attempts' => $this->nonNegativeInteger($stepState['attempts'] ?? null) ?? 0,
                'usage' => $this->usage($stepState['usage'] ?? null),
                'counts' => $isForeach ? $this->counts($stepState['counts'] ?? null) : null,
                'children' => $children,
                'items' => $isForeach ? $this->items($stepState['items'] ?? null, array_column($children ?? [], 'id')) : null,
            ];
        }

        return $steps;
    }

    /**
     * @return array<string, int>
     */
    private function counts(mixed $counts): array
    {
        $counts = is_array($counts) ? $counts : [];
        $normalized = [];

        foreach (self::COUNT_KEYS as $key) {
            $normalized[$key] = $this->boundedCount($counts[$key] ?? null);
        }

        return $normalized;
    }

    /**
     * @param  list<string>  $childIds
     * @return list<array{key: string, label: ?string, status: string, steps: array<string, string>}>
     */
    private function items(mixed $items, array $childIds): array
    {
        if (! is_array($items) || ! array_is_list($items)) {
            return [];
        }

        $normalized = [];

        foreach (array_slice($items, 0, 2000) as $item) {
            $key = is_array($item) ? ($item['key'] ?? null) : null;

            if (! is_string($key) || preg_match(WorkflowRunFiles::ITEM_KEY_PATTERN, $key) !== 1) {
                continue;
            }

            $childStatuses = [];

            foreach ($childIds as $childId) {
                $childStatus = $this->allowed($item['steps'][$childId] ?? null, self::STEP_STATUSES);

                if ($childStatus !== null) {
                    $childStatuses[$childId] = $childStatus;
                }
            }

            $normalized[] = [
                'key' => $key,
                'label' => $this->message($item['label'] ?? null, 120),
                'status' => $this->allowed($item['status'] ?? null, self::STEP_STATUSES) ?? 'pending',
                'steps' => $childStatuses,
            ];
        }

        return $normalized;
    }

    /**
     * @return list<array{step: string, child: ?string, item: ?string, label: ?string, attempt: ?int, startedAt: ?string, tail: string}>
     */
    private function activeCalls(mixed $calls): array
    {
        if (! is_array($calls) || ! array_is_list($calls)) {
            return [];
        }

        $active = [];

        foreach (array_slice($calls, 0, 20) as $call) {
            $step = is_array($call) ? $this->stepId($call['step'] ?? null) : null;

            if ($step === null) {
                continue;
            }

            $active[] = [
                'step' => $step,
                'child' => $this->stepId($call['child'] ?? null),
                'item' => $this->itemKey($call['item'] ?? null),
                'label' => $this->message($call['label'] ?? null, 120),
                'attempt' => $this->nonNegativeInteger($call['attempt'] ?? null),
                'startedAt' => $this->isoTime($call['startedAt'] ?? null),
                'tail' => $this->tail($call['tail'] ?? null),
            ];
        }

        return $active;
    }

    /**
     * The text of the model's last finished tool-less call, as the worker recorded it; null when absent or malformed.
     *
     * @return array{step: string, child: ?string, item: ?string, label: ?string, status: string, finishedAt: ?string, tail: string}|null
     */
    private function lastOutput(mixed $lastOutput): ?array
    {
        if (! is_array($lastOutput)) {
            return null;
        }

        $step = $this->stepId($lastOutput['step'] ?? null);
        $status = $this->allowed($lastOutput['status'] ?? null, self::LAST_OUTPUT_STATUSES);

        if ($step === null || $status === null || ! is_string($lastOutput['tail'] ?? null)) {
            return null;
        }

        return [
            'step' => $step,
            'child' => $this->stepId($lastOutput['child'] ?? null),
            'item' => $this->itemKey($lastOutput['item'] ?? null),
            'label' => $this->message($lastOutput['label'] ?? null, 120),
            'status' => $status,
            'finishedAt' => $this->isoTime($lastOutput['finishedAt'] ?? null),
            'tail' => $this->tail($lastOutput['tail']),
        ];
    }

    /**
     * The last 4096 characters of a model text, as valid UTF-8.
     */
    private function tail(mixed $text): string
    {
        $text = is_string($text) ? mb_scrub($text, 'UTF-8') : '';

        return mb_strlen($text) > self::TAIL_CHARACTERS ? mb_substr($text, -self::TAIL_CHARACTERS) : $text;
    }

    private function stepId(mixed $value): ?string
    {
        return is_string($value) && preg_match(WorkflowRunFiles::STEP_ID_PATTERN, $value) === 1 ? $value : null;
    }

    private function itemKey(mixed $value): ?string
    {
        return is_string($value) && preg_match(WorkflowRunFiles::ITEM_KEY_PATTERN, $value) === 1 ? $value : null;
    }

    /**
     * @return array{inputTokens: ?int, outputTokens: ?int, costUsd: int|float|null, calls: int}
     */
    private function usage(mixed $usage): array
    {
        $usage = is_array($usage) ? $usage : [];

        return [
            'inputTokens' => $this->nonNegativeInteger($usage['inputTokens'] ?? null),
            'outputTokens' => $this->nonNegativeInteger($usage['outputTokens'] ?? null),
            'costUsd' => $this->number($usage['costUsd'] ?? null, self::MAX_COST_USD),
            'calls' => $this->nonNegativeInteger($usage['calls'] ?? null) ?? 0,
        ];
    }

    /**
     * The sum of one usage value over the models, or null when any model that made calls (or whose call count is
     * unknown) did not report it. A model that is known to have made no calls does not contribute.
     *
     * @param  list<array{usage: array{inputTokens: ?int, outputTokens: ?int, costUsd: int|float|null, calls: int}}>  $models
     * @param  list<bool>  $callsKnown
     */
    private function usageTotal(array $models, array $callsKnown, string $key): int|float|null
    {
        $total = 0;
        $contributors = 0;

        foreach ($models as $index => $model) {
            $value = $model['usage'][$key];

            if ($value === null) {
                if (! ($callsKnown[$index] ?? false) || $model['usage']['calls'] > 0) {
                    return null;
                }

                continue;
            }

            $total += $value;
            $contributors++;
        }

        return $contributors === 0 ? null : $total;
    }

    private function elapsedSeconds(WorkflowRun $run): ?float
    {
        if ($run->started_at === null) {
            return null;
        }

        $end = $run->finished_at ?? CarbonImmutable::now();

        return round(max(0, $end->getTimestampMs() - $run->started_at->getTimestampMs()) / 1000, 1);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readState(WorkflowRun $run): ?array
    {
        $state = $this->files->readState($run);

        if ($state === null
            || $run->runner_pid === null
            || ! is_int($state['pid'] ?? null)
            || $state['pid'] !== $run->runner_pid) {
            return null;
        }

        return $state;
    }

    private function isRunnerProcess(?int $pid, string $workingDirectory): bool
    {
        if ($pid === null || $pid <= 1) {
            return false;
        }

        try {
            $process = new Process(['ps', '-p', (string) $pid, '-o', 'command='], null, $this->processEnvironment->make(commandPath: '/usr/bin:/bin'));
            $process->setTimeout(3);
            $process->run();
            $command = trim($process->getOutput());
            $jobPath = $workingDirectory.'/job.json';

            return $process->isSuccessful()
                && preg_match('/(?:^|\s)'.preg_quote($this->workerScript(), '/').'(?=\s|$)/', $command) === 1
                && preg_match('/(?:^|\s)'.preg_quote($jobPath, '/').'(?=\s|$)/', $command) === 1;
        } catch (Throwable) {
            return false;
        }
    }

    private function terminateOwnedProcess(WorkflowRun $run, string $workingDirectory): bool
    {
        if (! function_exists('posix_kill')
            || $run->working_directory !== $workingDirectory
            || ! $this->isRunnerProcess($run->runner_pid, $workingDirectory)) {
            return false;
        }

        return posix_kill(-$run->runner_pid, SIGTERM);
    }

    /** @param array<string, mixed>|stdClass $data */
    private function writeJson(string $path, array|stdClass $data, string $workingDirectory): void
    {
        $this->writePrivateFile(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL,
            $workingDirectory,
        );
    }

    private function writePrivateFile(string $path, string $content, string $workingDirectory): void
    {
        if (! $this->isWithin($workingDirectory, $path) || $path === $workingDirectory || is_link($path)) {
            throw new BenchmarkRunnerException('İş akışı dosyası güvenli çalışma klasörünün dışında.');
        }

        File::ensureDirectoryExists(dirname($path), 0700, true);
        $temporaryPath = $path.'.php-'.getmypid().'-'.Str::lower(Str::random(6)).'.tmp';
        $written = File::put($temporaryPath, $content, true);

        if ($written === false) {
            throw new BenchmarkRunnerException('İş akışı dosyası yazılamadı.');
        }

        @chmod($temporaryPath, 0600);
        File::move($temporaryPath, $path);
        @chmod($path, 0600);
    }

    /**
     * An empty PHP array would be written as a JSON list; job and input values are JSON objects.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>|stdClass
     */
    private function jsonObject(array $values): array|stdClass
    {
        return $values === [] ? new stdClass : $values;
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
            throw new BenchmarkRunnerException('İş akışı işçisi kullanıcı ve komut yolu yapılandırması geçersiz.');
        }

        return $this->processEnvironment->make($homePath, $commandPath);
    }

    /**
     * @param  list<string>  $allowed
     */
    private function allowed(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    private function message(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(mb_scrub($value, 'UTF-8'));

        return $value === '' ? null : Str::limit($value, $limit, '');
    }

    /**
     * A worker timestamp (ISO-8601 with an offset or `Z`) moved to the app timezone; null for anything else, including
     * impossible dates such as February 31. Eloquent stores the wall-clock time without converting it, and the page
     * shows database times in the app timezone, so every time is converted before it is stored or returned.
     */
    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || strlen($value) > 40 || preg_match(self::ISO_TIME_PATTERN, $value) !== 1) {
            return null;
        }

        try {
            $time = CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }

        return $time->format('Y-m-d\TH:i:s') === substr($value, 0, 19)
            ? $time->setTimezone((string) config('app.timezone'))
            : null;
    }

    /**
     * ISO-8601 with the offset of the app timezone, so the event log, the live calls and the header use one clock.
     */
    private function isoTime(mixed $value): ?string
    {
        $time = $value instanceof CarbonInterface
            ? $value->toImmutable()->setTimezone((string) config('app.timezone'))
            : $this->timestamp($value);

        return $time?->toIso8601String();
    }

    private function duration(mixed $value): ?float
    {
        return $this->number($value, 31_536_000) === null ? null : round((float) $value, 2);
    }

    private function number(mixed $value, int|float $maximum): int|float|null
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 && $value <= $maximum
            ? $value
            : null;
    }

    private function nonNegativeInteger(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    private function boundedCount(mixed $value): int
    {
        return min(self::UNSIGNED_INTEGER_MAX, $this->nonNegativeInteger($value) ?? 0);
    }

    private function isWithin(string $parent, string $candidate): bool
    {
        $normalizedParent = rtrim($parent, DIRECTORY_SEPARATOR);

        return $candidate === $normalizedParent
            || str_starts_with($candidate, $normalizedParent.DIRECTORY_SEPARATOR);
    }
}
