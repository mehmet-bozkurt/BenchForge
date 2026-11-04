<?php

use App\Exceptions\BenchmarkRunnerException;
use App\Models\OpenCodeGoModel;
use App\Models\OpenRouterModel;
use App\Models\WorkflowRun;
use App\Models\WorkflowRunModel;
use App\Services\BenchForgeSettings;
use App\Services\BenchmarkProcessEnvironment;
use App\Services\ModelSelection;
use App\Services\OpenRouterProviderRouting;
use App\Services\ProviderConnectionStatus;
use App\Services\WorkflowRunFiles;
use App\Services\WorkflowRunner;
use App\Services\WorkflowTemplateStore;
use App\Services\WorkflowTemplateValidator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

use function Pest\Laravel\mock;

uses(LazilyRefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function workflowRunnerTemplate(): array
{
    return [
        'schema' => 'benchforge-workflow/1',
        'id' => 'runner-flow',
        'name' => 'Koşucu akışı',
        'version' => 1,
        'inputs' => [
            ['id' => 'ideaCount', 'type' => 'integer', 'label' => 'Fikir sayısı', 'default' => 2, 'min' => 1, 'max' => 3],
            ['id' => 'tone', 'type' => 'string', 'label' => 'Ton', 'default' => 'neşeli', 'maxLength' => 20],
        ],
        'steps' => [
            [
                'id' => 'ideas',
                'type' => 'llm_json',
                'title' => 'Fikirler',
                'prompt' => 'Write {{inputs.ideaCount}} game ideas in a {{inputs.tone}} tone.',
                'schema' => [
                    'type' => 'object',
                    'required' => ['ideas'],
                    'properties' => ['ideas' => ['type' => 'array', 'items' => [
                        'type' => 'object',
                        'required' => ['title'],
                        'properties' => ['title' => ['type' => 'string']],
                    ]]],
                ],
            ],
            [
                'id' => 'demos',
                'type' => 'foreach',
                'title' => 'Demolar',
                'over' => 'steps.ideas.output.ideas',
                'itemLabel' => '{{item.title}}',
                'steps' => [
                    ['id' => 'build', 'type' => 'code_text', 'title' => 'Demo yaz', 'prompt' => 'Build a demo of {{item.title}}.'],
                ],
            ],
        ],
        'gallery' => ['from' => 'demos', 'title' => '{{item.title}}', 'file' => 'build'],
    ];
}

/**
 * Saves the test template as a user template and returns its exact bytes.
 */
function writeWorkflowRunnerTemplate(): string
{
    $path = config('benchforge.root_path').'/workflows/runner-flow/v001.json';
    $raw = json_encode(workflowRunnerTemplate(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
    File::ensureDirectoryExists(dirname($path));
    File::put($path, $raw);

    return $raw;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function workflowRunnerInput(array $overrides = []): array
{
    return [
        'template_id' => 'runner-flow',
        'template_version' => 1,
        'inputs' => ['ideaCount' => '2', 'tone' => 'neşeli'],
        'model_mode' => 'group',
        'config' => 'config/pilot.conf',
        'max_parallel' => 5,
        'max_concurrent_calls' => 3,
        'timeouts' => ['llm' => 300, 'code' => null],
        ...$overrides,
    ];
}

/**
 * The runner with a fake worker script in place of `bin/workflow-worker.mjs`.
 */
function workflowRunnerWithWorker(string $workerScript): WorkflowRunner
{
    return new class($workerScript, app(WorkflowTemplateStore::class), app(WorkflowTemplateValidator::class), app(ModelSelection::class), app(ProviderConnectionStatus::class), app(BenchmarkProcessEnvironment::class), app(WorkflowRunFiles::class)) extends WorkflowRunner
    {
        public function __construct(
            private string $testWorkerScript,
            WorkflowTemplateStore $templateStore,
            WorkflowTemplateValidator $templateValidator,
            ModelSelection $modelSelection,
            ProviderConnectionStatus $providerConnectionStatus,
            BenchmarkProcessEnvironment $processEnvironment,
            WorkflowRunFiles $files,
        ) {
            parent::__construct($templateStore, $templateValidator, $modelSelection, $providerConnectionStatus, $processEnvironment, $files);
        }

        protected function workerScript(): string
        {
            return $this->testWorkerScript;
        }
    };
}

/**
 * A fake worker: writes a running state with one active call per model, keeps a child in its process group and,
 * on SIGTERM, writes the final stopped state and a marker file. It refuses to run with leaked secrets.
 */
function writeWorkflowFakeWorker(string $path): void
{
    $source = <<<'JS'
import { readFileSync, renameSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { spawn } from "node:child_process";

if (process.env.BENCHFORGE_RUNNER !== "1" || process.env.APP_KEY !== undefined || process.env.BENCHFORGE_TEST_WORKFLOW_SECRET !== undefined) {
  process.exit(91);
}

const jobPath = process.argv[2];
const root = dirname(jobPath);
const job = JSON.parse(readFileSync(jobPath, "utf8"));
const keeper = spawn(process.execPath, ["-e", "setInterval(() => {}, 1000)"], { stdio: "ignore" });
writeFileSync(join(root, "child.pid"), String(keeper.pid));
const now = () => new Date().toISOString();
const models = Object.fromEntries(job.models.map((model) => [model.slug, {
  model: model.model,
  name: model.name,
  status: "running",
  message: null,
  startedAt: now(),
  finishedAt: null,
  durationSeconds: null,
  usage: { inputTokens: null, outputTokens: null, costUsd: null, calls: 0 },
  items: { total: 0, completed: 0, failed: 0 },
  steps: { ideas: { status: "running", message: null, attempts: 1, usage: { inputTokens: null, outputTokens: null, costUsd: null, calls: 0 } } },
  active: [{ step: "ideas", child: null, item: null, label: null, attempt: 1, startedAt: now(), tail: "ğ".repeat(5000) }],
}]));
let written = 0;
const write = (status, message) => {
  const temporary = join(root, `.state-${process.pid}-${++written}.tmp`);
  writeFileSync(temporary, JSON.stringify({
    schema: "benchforge-workflow-state/1",
    runId: job.runId,
    pid: process.pid,
    status,
    message,
    startedAt: now(),
    updatedAt: now(),
    finishedAt: status === "stopped" ? now() : null,
    models,
  }));
  renameSync(temporary, join(root, "state.json"));
};

write("running", null);
process.on("SIGTERM", () => {
  for (const model of Object.values(models)) {
    model.status = "stopped";
    model.message = "Çalıştırma durduruldu.";
    model.active = [];
  }
  write("stopped", "Çalıştırma durduruldu.");
  writeFileSync(join(root, "terminated.txt"), "SIGTERM");
  process.exit(0);
});
setInterval(() => {}, 1000);
JS;

    File::ensureDirectoryExists(dirname($path));
    File::put($path, $source);
}

/**
 * Starts the fake worker as its own process group through the benchmark launcher and returns its pid.
 */
function launchWorkflowFakeWorker(string $workerScript, string $jobPath): int
{
    $launcher = new Process([
        (string) config('benchforge.node_binary'),
        base_path('bin/benchmark-launcher.mjs'),
        $workerScript,
        $jobPath,
    ], base_path(), app(BenchmarkProcessEnvironment::class)->make());
    $launcher->setTimeout(10);
    $launcher->mustRun();

    return (int) trim($launcher->getOutput());
}

function workflowProcessIsAlive(int $pid): bool
{
    return $pid > 1 && posix_kill($pid, 0);
}

function waitForWorkflowCondition(Closure $condition, int $attempts = 80): bool
{
    for ($attempt = 0; $attempt < $attempts; $attempt++) {
        if ($condition()) {
            return true;
        }

        usleep(50_000);
    }

    return $condition();
}

/**
 * A hand-written run directory with a job, a template snapshot and optionally a state and events.
 *
 * @param  array<string, mixed>|null  $state
 * @return array{run: WorkflowRun, directory: string}
 */
function workflowRunnerFixture(string $status, ?array $state, int $pid = 999_999): array
{
    $slug = 'runner-flow-20261022-120000';
    $directory = app(WorkflowRunFiles::class)->runsRoot().'/'.$slug;
    File::ensureDirectoryExists($directory, 0700, true);
    File::put($directory.'/template.json', json_encode(workflowRunnerTemplate(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    File::put($directory.'/job.json', json_encode([
        'schema' => 'benchforge-workflow-job/1',
        'runId' => $slug,
        'runDirectory' => $directory,
        'models' => [
            ['slug' => ModelSelection::folderFor('openrouter/fixture/alpha'), 'model' => 'openrouter/fixture/alpha', 'name' => 'Alfa'],
            ['slug' => ModelSelection::folderFor('opencode-go/beta'), 'model' => 'opencode-go/beta', 'name' => 'Beta'],
        ],
    ], JSON_THROW_ON_ERROR));

    if ($state !== null) {
        File::put($directory.'/state.json', json_encode([
            'schema' => 'benchforge-workflow-state/1',
            'runId' => $slug,
            'pid' => $pid,
            ...$state,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    $run = WorkflowRun::factory()->create([
        'slug' => $slug,
        'template_id' => 'runner-flow',
        'title' => 'Koşucu akışı',
        'status' => $status,
        'working_directory' => $directory,
        'runner_pid' => $pid,
        'message' => 'İş akışı için güvenli çalışma alanı hazırlanıyor.',
        'started_at' => now()->subSeconds(30),
        'finished_at' => null,
    ]);

    foreach ([['openrouter/fixture/alpha', 'Alfa'], ['opencode-go/beta', 'Beta']] as [$model, $name]) {
        WorkflowRunModel::factory()->for($run)->create([
            'model' => $model,
            'model_name' => $name,
            'status' => 'running',
            'duration_seconds' => null,
            'input_tokens' => null,
            'output_tokens' => null,
            'cost_usd' => null,
            'items_total' => 0,
            'items_completed' => 0,
            'items_failed' => 0,
        ]);
    }

    return ['run' => $run, 'directory' => $directory];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function workflowModelState(string $model, string $status, array $overrides = []): array
{
    return [
        'model' => $model,
        'name' => 'Model',
        'status' => $status,
        'message' => null,
        'durationSeconds' => null,
        'usage' => ['inputTokens' => null, 'outputTokens' => null, 'costUsd' => null, 'calls' => 0],
        'items' => ['total' => 0, 'completed' => 0, 'failed' => 0],
        'steps' => [],
        'active' => [],
        ...$overrides,
    ];
}

test('it rejects invalid starts with a Turkish message before inspecting connections', function (array $input, string $message) {
    Http::preventStrayRequests();
    mock(BenchForgeSettings::class)->shouldReceive('apply')->once();
    mock(ProviderConnectionStatus::class)->shouldNotReceive('inspect');
    writeWorkflowRunnerTemplate();
    File::put(config('benchforge.root_path').'/config/mixed.conf', "glm|opencode-go/glm-5.3-flash|3101\nqwen|ollama/qwen3|3102\n");
    File::put(config('benchforge.root_path').'/config/large.conf', implode("\n", array_map(
        fn (int $number): string => "go-{$number}|opencode-go/model-{$number}|".(3100 + $number),
        range(1, 6),
    ))."\n");

    expect(fn () => app(WorkflowRunner::class)->start(workflowRunnerInput($input)))
        ->toThrow(BenchmarkRunnerException::class, $message);
    expect(WorkflowRun::query()->count())->toBe(0)
        ->and(File::exists(config('benchforge.root_path').'/workflow-runs'))->toBeFalse();
    Http::assertNothingSent();
})->with([
    'unknown template' => [['template_id' => 'missing-flow'], 'Şablon bulunamadı ya da geçersiz.'],
    'unknown template version' => [['template_version' => 7], 'Şablon bulunamadı ya da geçersiz.'],
    'malformed template id' => [['template_id' => '../runner-flow'], 'Şablon bulunamadı.'],
    'input above its maximum' => [['inputs' => ['ideaCount' => '4', 'tone' => 'neşeli']], 'Fikir sayısı en fazla 3 olabilir.'],
    'missing required input' => [['inputs' => ['ideaCount' => '', 'tone' => 'neşeli']], 'Fikir sayısı zorunludur.'],
    'too many catalog models' => [[
        'model_mode' => 'custom',
        'models' => ['a/one', 'a/two', 'a/three', 'a/four', 'a/five', 'a/six'],
    ], 'OpenRouter kataloğundan 1–5 model seçin.'],
    'too many group models' => [['config' => 'config/large.conf'], 'Bu grupta 6 model var; iş akışında en fazla 5 model seçilebilir.'],
    'unsupported provider in a group' => [
        ['config' => 'config/mixed.conf'],
        'Bu gruptaki ollama/qwen3 modeli iş akışlarında desteklenmiyor (yalnız OpenRouter ve OpenCode Go).',
    ],
    'too many parallel models' => [['max_parallel' => 6], 'Aynı anda çalışacak model sayısı 1 ile 5 arasında olmalıdır.'],
    'too many concurrent calls' => [['max_concurrent_calls' => 11], 'Eşzamanlı model çağrısı sayısı 1 ile 10 arasında olmalıdır.'],
    'too short a timeout' => [['timeouts' => ['llm' => 10, 'code' => null]], 'Süre sınırı 30 ile 7200 saniye arasında olmalıdır.'],
]);

test('it reports every invalid input field in the exception context', function () {
    mock(BenchForgeSettings::class)->shouldReceive('apply')->once();
    writeWorkflowRunnerTemplate();

    try {
        app(WorkflowRunner::class)->start(workflowRunnerInput(['inputs' => ['ideaCount' => 'çok', 'tone' => str_repeat('a', 21)]]));
        $this->fail('The start should have been rejected.');
    } catch (BenchmarkRunnerException $exception) {
        expect($exception->context())->toBe(['errors' => [
            'inputs.ideaCount' => 'Fikir sayısı bir tam sayı olmalıdır.',
            'inputs.tone' => 'Ton en fazla 20 karakter olabilir.',
        ]]);
    }
});

test('it does not start when a selected provider is not connected', function () {
    Http::preventStrayRequests();
    writeWorkflowRunnerTemplate();
    $model = OpenCodeGoModel::factory()->create(['available' => true, 'agent_compatible' => true]);
    mock(ProviderConnectionStatus::class)->shouldReceive('inspect')->once()->andReturn([
        'cli' => ['status' => 'pass'],
        'providers' => ['openrouter' => ['status' => 'pass'], 'opencode-go' => ['status' => 'fail']],
    ]);

    expect(fn () => app(WorkflowRunner::class)->start(workflowRunnerInput([
        'model_mode' => 'opencode_go',
        'go_models' => [$model->model_id],
        'max_parallel' => 1,
    ])))->toThrow(BenchmarkRunnerException::class, 'OpenCode Go için OpenCode bağlantısı hazır değil. Bağlantı durumunu yenileyip tekrar deneyin.');

    expect(WorkflowRun::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('it launches the worker with a private job, template snapshot and restricted model configs, then stops it', function () {
    Http::preventStrayRequests();
    $root = storage_path('framework/testing/workflow-runner-'.Str::uuid());
    $binPath = $root.'/bin';
    $workerScript = $root.'/worker/fake-workflow-worker.mjs';
    File::ensureDirectoryExists($binPath, 0700, true);
    File::put($binPath.'/opencode', '#!/bin/sh');
    chmod($binPath.'/opencode', 0755);
    writeWorkflowFakeWorker($workerScript);
    config()->set('benchforge.command_path', $binPath);
    $raw = writeWorkflowRunnerTemplate();
    File::put(config('benchforge.root_path').'/config/pilot.conf', "alpha|openrouter/fixture/workflow-alpha|3101\nbeta|opencode-go/glm-workflow|3102\n");
    OpenRouterModel::factory()->create(['model_id' => 'fixture/workflow-alpha', 'name' => 'Alfa İş Akışı']);
    mock(ProviderConnectionStatus::class)->shouldReceive('inspect')->andReturn([
        'cli' => ['status' => 'pass'],
        'providers' => ['openrouter' => ['status' => 'pass'], 'opencode-go' => ['status' => 'pass']],
    ]);
    $routing = ['allow_fallbacks' => true, 'data_collection' => 'deny', 'require_parameters' => true];
    mock(OpenRouterProviderRouting::class)->shouldReceive('routingForModels')->once()
        ->with(['fixture/workflow-alpha'], 'auto')
        ->andReturn(['fixture/workflow-alpha' => $routing]);
    $runner = workflowRunnerWithWorker($workerScript);
    $alphaSlug = ModelSelection::folderFor('openrouter/fixture/workflow-alpha');
    $betaSlug = ModelSelection::folderFor('opencode-go/glm-workflow');
    $run = null;
    $originalEnvironment = $_ENV;
    $_ENV['APP_KEY'] = 'workflow-launch-test-only-secret';
    $_ENV['BENCHFORGE_TEST_WORKFLOW_SECRET'] = 'workflow-unknown-test-only-secret';

    try {
        $run = $runner->start(workflowRunnerInput());
        $directory = $run->working_directory;
        $job = json_decode(File::get($directory.'/job.json'), true, flags: JSON_THROW_ON_ERROR);
        $alphaConfigRaw = File::get($directory.'/models/'.$alphaSlug.'/opencode.json');
        $alphaConfig = json_decode($alphaConfigRaw, true, flags: JSON_THROW_ON_ERROR);
        $betaConfig = json_decode(File::get($directory.'/models/'.$betaSlug.'/opencode.json'), true, flags: JSON_THROW_ON_ERROR);
        $snapshot = $runner->snapshot($run);

        expect($run->status)->toBe('running')
            ->and($run->slug)->toMatch('/^runner-flow-\d{8}-\d{6}$/')
            ->and($directory)->toBe(realpath((string) config('benchforge.root_path')).'/workflow-runs/'.$run->slug)
            ->and(fileperms($directory) & 0777)->toBe(0700)
            ->and(fileperms($directory.'/job.json') & 0777)->toBe(0600)
            ->and(fileperms($directory.'/models/'.$alphaSlug.'/opencode.json') & 0777)->toBe(0600)
            ->and($run->template_version)->toBe(1)
            ->and($run->template_sha256)->toBe(hash('sha256', $raw))
            ->and($run->inputs)->toBe(['ideaCount' => 2, 'tone' => 'neşeli'])
            ->and($run->models->map->only(['slug', 'model', 'model_name', 'provider', 'status'])->all())->toBe([
                ['slug' => $alphaSlug, 'model' => 'openrouter/fixture/workflow-alpha', 'model_name' => 'Alfa İş Akışı', 'provider' => 'openrouter', 'status' => 'running'],
                ['slug' => $betaSlug, 'model' => 'opencode-go/glm-workflow', 'model_name' => 'glm-workflow', 'provider' => 'opencode-go', 'status' => 'running'],
            ])
            ->and(File::get($directory.'/template.json'))->toBe($raw)
            ->and(json_decode(File::get($directory.'/inputs.json'), true))->toBe(['ideaCount' => 2, 'tone' => 'neşeli'])
            ->and($job)->toMatchArray([
                'schema' => 'benchforge-workflow-job/1',
                'runId' => $run->slug,
                'runDirectory' => $directory,
                'templateFile' => 'template.json',
                'inputs' => ['ideaCount' => 2, 'tone' => 'neşeli'],
                'models' => [
                    ['slug' => $alphaSlug, 'model' => 'openrouter/fixture/workflow-alpha', 'name' => 'Alfa İş Akışı', 'provider' => 'openrouter', 'configFile' => 'models/'.$alphaSlug.'/opencode.json'],
                    ['slug' => $betaSlug, 'model' => 'opencode-go/glm-workflow', 'name' => 'glm-workflow', 'provider' => 'opencode-go', 'configFile' => 'models/'.$betaSlug.'/opencode.json'],
                ],
                'settings' => [
                    'maxParallelModels' => 2,
                    'maxConcurrentCalls' => 3,
                    'stepModes' => ['build' => 'code_text'],
                    'timeouts' => ['llm' => 300, 'code' => null],
                ],
                'opencode' => ['binary' => $binPath.'/opencode'],
            ])
            ->and($job['createdAt'])->toBe($run->started_at->toIso8601String())
            ->and($alphaConfigRaw)->toContain('"mcp": {}')
            ->and($alphaConfig['permission'])->toBe(['*' => 'deny'])
            ->and($alphaConfig['tools'])->toBe(['*' => false])
            ->and($alphaConfig['plugin'])->toBe([])
            ->and($alphaConfig['instructions'])->toBe([])
            ->and($alphaConfig['autoupdate'])->toBeFalse()
            ->and($alphaConfig['share'])->toBe('disabled')
            ->and($alphaConfig['agent'])->toBe(['benchforge-workflow' => [
                'description' => 'BenchForge iş akışı adımı',
                'mode' => 'primary',
                'prompt' => "Follow the user's instructions exactly. Do not use tools, do not ask questions, answer only with the requested output.",
                'tools' => ['*' => false],
                'permission' => ['*' => 'deny'],
            ]])
            ->and($alphaConfig['provider'])->toBe(['openrouter' => ['models' => ['fixture/workflow-alpha' => ['options' => ['provider' => $routing]]]]])
            ->and($betaConfig)->not->toHaveKey('provider')
            ->and($betaConfig['agent'])->toHaveKey('benchforge-workflow')
            ->and($snapshot['active'])->toBeTrue()
            ->and($snapshot['models'][0]['active'][0])->toMatchArray(['step' => 'ideas', 'attempt' => 1])
            ->and(mb_strlen($snapshot['models'][0]['active'][0]['tail']))->toBe(4096)
            ->and(json_encode($snapshot, JSON_THROW_ON_ERROR))->not->toContain($directory);

        $childPid = (int) File::get($directory.'/child.pid');
        $stopping = $runner->stop($run);

        expect($stopping->status)->toBe('stopping')
            ->and(waitForWorkflowCondition(fn (): bool => ! workflowProcessIsAlive($run->runner_pid) && ! workflowProcessIsAlive($childPid)))->toBeTrue()
            ->and(File::get($directory.'/terminated.txt'))->toBe('SIGTERM');

        $stopped = $runner->reconcile($run);

        expect($stopped->status)->toBe('stopped')
            ->and($stopped->message)->toBe('Çalıştırma durduruldu.')
            ->and($stopped->finished_at)->not->toBeNull()
            ->and($stopped->models->pluck('status')->all())->toBe(['stopped', 'stopped']);
        Http::assertNothingSent();
    } finally {
        $_ENV = $originalEnvironment;

        if ($run instanceof WorkflowRun && is_int($run->refresh()->runner_pid) && workflowProcessIsAlive($run->runner_pid)) {
            posix_kill(-$run->runner_pid, SIGKILL);
        }

        File::deleteDirectory($root);
    }
});

test('it runs the real worker to completion with a fake OpenCode CLI and imports its results', function () {
    Http::preventStrayRequests();
    $root = storage_path('framework/testing/workflow-runner-real-'.Str::uuid());
    $binPath = $root.'/bin';
    File::ensureDirectoryExists($binPath, 0700, true);
    $fakeCli = <<<'JS'
if (process.env.APP_KEY !== undefined) process.exit(91);
let prompt = "";
process.stdin.setEncoding("utf8");
process.stdin.on("data", (chunk) => { prompt += chunk; });
process.stdin.on("end", () => {
  const text = prompt.includes("OUTPUT FORMAT")
    ? "```json\n" + JSON.stringify({ ideas: [{ title: "Kule" }] }) + "\n```"
    : "```html\n<!doctype html><html><head><title>Kule</title></head><body><script>addEventListener('pointerdown', () => {}); addEventListener('resize', () => {}); requestAnimationFrame(() => {}); document.addEventListener('visibilitychange', () => {});</script></body></html>\n```";
  process.stdout.write(`${JSON.stringify({ type: "step_finish", sessionID: "ses_fake", part: { cost: 0.001, tokens: { input: 10, output: 20 } } })}\n`);
  process.stdout.write(`${JSON.stringify({ type: "text", sessionID: "ses_fake", part: { id: "answer", text } })}\n`);
});
JS;
    $nodeBinary = (string) config('benchforge.node_binary');
    File::put($binPath.'/opencode', '#!'.$nodeBinary.PHP_EOL.$fakeCli);
    chmod($binPath.'/opencode', 0755);
    // apply() puts the Node.js directory first in the command path: a node wrapper beside the fake keeps a real opencode next to node out.
    File::put($binPath.'/node', "#!/bin/sh\nexec ".escapeshellarg($nodeBinary)." \"\$@\"\n");
    chmod($binPath.'/node', 0700);
    config()->set('benchforge.node_binary', $binPath.'/node');
    config()->set('benchforge.command_path', $binPath);
    writeWorkflowRunnerTemplate();
    $model = OpenCodeGoModel::factory()->create(['model_id' => 'glm-real-worker', 'name' => 'GLM Gerçek İşçi']);
    mock(ProviderConnectionStatus::class)->shouldReceive('inspect')->andReturn([
        'cli' => ['status' => 'pass'],
        'providers' => ['opencode-go' => ['status' => 'pass']],
    ]);
    $runner = app(WorkflowRunner::class);
    $run = null;
    $originalEnvironment = $_ENV;
    $_ENV['APP_KEY'] = 'workflow-real-worker-test-only-secret';

    try {
        $run = $runner->start(workflowRunnerInput([
            'model_mode' => 'opencode_go',
            'go_models' => [$model->model_id],
            'max_parallel' => 1,
        ]));

        expect($nodeBinary)->toStartWith('/')
            ->and(json_decode(File::get($run->working_directory.'/job.json'), true, flags: JSON_THROW_ON_ERROR)['opencode']['binary'])
            ->toBe($binPath.'/opencode');

        for ($attempt = 0; $attempt < 200 && $run->isActive(); $attempt++) {
            usleep(50_000);
            $run = $runner->reconcile($run);
        }

        $runModel = $run->models->sole();
        $snapshot = $runner->snapshot($run);
        $gallery = app(WorkflowRunFiles::class)->galleryItems($run);

        expect($run->status)->toBe('completed')
            ->and($runModel->status)->toBe('completed')
            ->and($runModel->input_tokens)->toBe(20)
            ->and($runModel->output_tokens)->toBe(40)
            ->and($runModel->items_total)->toBe(1)
            ->and($runModel->items_completed)->toBe(1)
            ->and(array_column($snapshot['models'][0]['steps'], 'status'))->toBe(['completed', 'completed'])
            ->and($snapshot['totals']['calls'])->toBe(2)
            ->and($gallery)->toHaveCount(1)
            ->and($gallery[0])->toMatchArray(['item' => '001', 'title' => 'Kule', 'status' => 'completed'])
            ->and($gallery[0]['demoUrl'])->not->toBeNull();
        Http::assertNothingSent();
    } finally {
        $_ENV = $originalEnvironment;

        if ($run instanceof WorkflowRun && $run->refresh()->isActive()) {
            try {
                $runner->stop($run);
            } catch (BenchmarkRunnerException) {
                // The worker may already be exiting between reconciliation and cleanup.
            }
        }

        File::deleteDirectory($root);
    }
});

test('it refuses to start while another workflow is running', function () {
    $root = storage_path('framework/testing/workflow-runner-active-'.Str::uuid());
    $workerScript = $root.'/fake-workflow-worker.mjs';
    writeWorkflowFakeWorker($workerScript);
    writeWorkflowRunnerTemplate();
    $fixture = workflowRunnerFixture('running', null, 0);
    $pid = launchWorkflowFakeWorker($workerScript, $fixture['directory'].'/job.json');
    $fixture['run']->update(['runner_pid' => $pid]);
    mock(ProviderConnectionStatus::class)->shouldNotReceive('inspect');

    try {
        expect(waitForWorkflowCondition(fn (): bool => File::exists($fixture['directory'].'/state.json')))->toBeTrue();
        expect(fn () => workflowRunnerWithWorker($workerScript)->start(workflowRunnerInput()))
            ->toThrow(BenchmarkRunnerException::class, '“Koşucu akışı” iş akışı halen çalışıyor. Aynı anda tek iş akışı çalışabilir.');
        expect(WorkflowRun::query()->count())->toBe(1)
            ->and($fixture['run']->refresh()->status)->toBe('running');
    } finally {
        if (workflowProcessIsAlive($pid)) {
            posix_kill(-$pid, SIGKILL);
        }

        File::deleteDirectory($root);
    }
});

test('it keeps a live worker active, never undoes stopping and signals the whole process group on stop', function () {
    $root = storage_path('framework/testing/workflow-runner-stop-'.Str::uuid());
    $workerScript = $root.'/fake-workflow-worker.mjs';
    writeWorkflowFakeWorker($workerScript);
    $fixture = workflowRunnerFixture('starting', null, 0);
    $pid = launchWorkflowFakeWorker($workerScript, $fixture['directory'].'/job.json');
    $fixture['run']->update(['runner_pid' => $pid]);
    $runner = workflowRunnerWithWorker($workerScript);

    try {
        expect(waitForWorkflowCondition(fn (): bool => File::exists($fixture['directory'].'/child.pid')
            && File::exists($fixture['directory'].'/state.json')))->toBeTrue();
        $childPid = (int) File::get($fixture['directory'].'/child.pid');

        $running = $runner->reconcile($fixture['run']);

        expect($running->status)->toBe('running')
            ->and($running->message)->toBeNull()
            ->and($running->models->pluck('status')->all())->toBe(['running', 'running']);

        $running->update(['status' => 'stopping', 'message' => 'Çalıştırma durduruluyor.']);

        expect($runner->reconcile($running)->status)->toBe('stopping');

        $running->update(['status' => 'running']);
        $stopping = $runner->stop($running);

        expect($stopping->status)->toBe('stopping')
            ->and($stopping->message)->toBe('Çalıştırma durduruluyor.')
            ->and(waitForWorkflowCondition(fn (): bool => ! workflowProcessIsAlive($pid) && ! workflowProcessIsAlive($childPid)))->toBeTrue()
            ->and(File::get($fixture['directory'].'/terminated.txt'))->toBe('SIGTERM')
            ->and($runner->reconcile($stopping)->status)->toBe('stopped');
        expect(fn () => $runner->stop($stopping))->toThrow(BenchmarkRunnerException::class, 'Bu iş akışı artık çalışmıyor.');
    } finally {
        if (workflowProcessIsAlive($pid)) {
            posix_kill(-$pid, SIGKILL);
        }

        File::deleteDirectory($root);
    }
});

test('it finishes a run with the final state of a worker that has exited and keeps unknown usage empty', function () {
    $alpha = ModelSelection::folderFor('openrouter/fixture/alpha');
    $beta = ModelSelection::folderFor('opencode-go/beta');
    $fixture = workflowRunnerFixture('running', [
        'status' => 'partial',
        'message' => 'Bazı modeller başarısız oldu.',
        'finishedAt' => '2026-10-22T12:10:00+03:00',
        'models' => [
            $alpha => workflowModelState('openrouter/fixture/alpha', 'completed', [
                'durationSeconds' => 61.237,
                'usage' => ['inputTokens' => 1200, 'outputTokens' => 300, 'costUsd' => 0.0125, 'calls' => 3],
                'items' => ['total' => 2, 'completed' => 2, 'failed' => 0],
            ]),
            $beta => workflowModelState('opencode-go/beta', 'failed', [
                'message' => 'Model sağlayıcıda bulunamadı veya kaldırıldı.',
                'durationSeconds' => 4,
                'usage' => ['inputTokens' => null, 'outputTokens' => null, 'costUsd' => null, 'calls' => 1],
                'items' => ['total' => 2, 'completed' => 0, 'failed' => 2],
            ]),
        ],
    ]);

    $run = app(WorkflowRunner::class)->reconcile($fixture['run']);
    $models = $run->models->keyBy('slug');

    expect($run->status)->toBe('partial')
        ->and($run->message)->toBe('Bazı modeller başarısız oldu.')
        ->and($run->finished_at->getTimestamp())->toBe(CarbonImmutable::parse('2026-10-22T12:10:00+03:00')->getTimestamp())
        ->and($models[$alpha]->only(['status', 'error_message', 'duration_seconds', 'input_tokens', 'output_tokens', 'cost_usd', 'items_total', 'items_completed', 'items_failed']))->toBe([
            'status' => 'completed',
            'error_message' => null,
            'duration_seconds' => '61.24',
            'input_tokens' => 1200,
            'output_tokens' => 300,
            'cost_usd' => '0.012500',
            'items_total' => 2,
            'items_completed' => 2,
            'items_failed' => 0,
        ])
        ->and($models[$beta]->only(['status', 'error_message', 'input_tokens', 'output_tokens', 'cost_usd', 'items_failed']))->toBe([
            'status' => 'failed',
            'error_message' => 'Model sağlayıcıda bulunamadı veya kaldırıldı.',
            'input_tokens' => null,
            'output_tokens' => null,
            'cost_usd' => null,
            'items_failed' => 2,
        ]);
});

test('it fails unfinished models when the worker vanished without a final state', function (string $runStatus, ?string $stateStatus, string $expectedRun, string $expectedModel, string $message) {
    $alpha = ModelSelection::folderFor('openrouter/fixture/alpha');
    $beta = ModelSelection::folderFor('opencode-go/beta');
    $fixture = workflowRunnerFixture($runStatus, $stateStatus === null ? null : [
        'status' => $stateStatus,
        'message' => null,
        'models' => [
            $alpha => workflowModelState('openrouter/fixture/alpha', 'completed'),
            $beta => workflowModelState('opencode-go/beta', 'running'),
        ],
    ]);

    $run = app(WorkflowRunner::class)->reconcile($fixture['run']);
    $models = $run->models->keyBy('slug');

    expect($run->status)->toBe($expectedRun)
        ->and($run->message)->toBe($message)
        ->and($run->finished_at)->not->toBeNull()
        ->and($models[$beta]->status)->toBe($expectedModel)
        ->and($models[$beta]->error_message)->toBe($message);
})->with([
    'running worker crashed' => ['running', 'running', 'partial', 'failed', 'İş akışı süreci beklenmedik şekilde kapandı.'],
    'stop pressed before exit' => ['stopping', 'running', 'stopped', 'stopped', 'Çalıştırma durduruldu.'],
    'worker wrote stopping' => ['running', 'stopping', 'stopped', 'stopped', 'Çalıştırma durduruldu.'],
]);

test('it fails a run that never wrote a state and touches no process for an unsafe directory', function () {
    $fixture = workflowRunnerFixture('starting', null);
    $crashed = app(WorkflowRunner::class)->reconcile($fixture['run']);

    expect($crashed->status)->toBe('failed')
        ->and($crashed->models->pluck('status')->unique()->all())->toBe(['failed']);

    $unsafe = WorkflowRun::factory()->create([
        'status' => 'running',
        'working_directory' => $fixture['directory'].'/../elsewhere',
        'runner_pid' => getmypid(),
        'finished_at' => null,
    ]);
    WorkflowRunModel::factory()->for($unsafe)->create(['status' => 'running']);

    $result = app(WorkflowRunner::class)->reconcile($unsafe);

    expect($result->status)->toBe('failed')
        ->and($result->message)->toBe('İş akışı çalışma alanı güvenli biçimde doğrulanamadı; hiçbir sürece sinyal gönderilmedi.')
        ->and($result->models->sole()->status)->toBe('failed')
        ->and(workflowProcessIsAlive(getmypid()))->toBeTrue();
});

test('it leaves a run that is still being started to start() and fails one that never got a worker', function () {
    $runsRoot = app(WorkflowRunFiles::class)->runsRoot();
    $runner = app(WorkflowRunner::class);
    $starting = fn (int $secondsAgo, string $slug): WorkflowRun => tap(WorkflowRun::factory()->create([
        'slug' => $slug,
        'status' => 'starting',
        'runner_pid' => null,
        'working_directory' => $runsRoot.'/'.$slug,
        'message' => 'İş akışı için güvenli çalışma alanı hazırlanıyor.',
        'started_at' => now()->subSeconds($secondsAgo),
        'finished_at' => null,
    ]), fn (WorkflowRun $run) => WorkflowRunModel::factory()->for($run)->create(['status' => 'pending']));

    $fresh = $starting(5, 'runner-flow-20261022-120005');
    $attributes = $fresh->refresh()->getAttributes();

    expect($runner->reconcile($fresh)->getAttributes())->toBe($attributes)
        ->and($runner->active()?->is($fresh))->toBeTrue()
        ->and($fresh->refresh()->getAttributes())->toBe($attributes)
        ->and($fresh->models->sole()->status)->toBe('pending');

    $fresh->delete();
    $abandoned = $starting(60, 'runner-flow-20261022-120100');

    expect($runner->active())->toBeNull();

    $failed = $abandoned->refresh()->load('models');

    expect($failed->status)->toBe('failed')
        ->and($failed->message)->toBe('İş akışı çalışma alanı güvenli biçimde doğrulanamadı; hiçbir sürece sinyal gönderilmedi.')
        ->and($failed->finished_at)->not->toBeNull()
        ->and($failed->models->sole()->status)->toBe('failed')
        ->and($runner->reconcile($failed)->status)->toBe('failed');
});

test('it preserves a finished run when its directory no longer exists', function () {
    $run = WorkflowRun::factory()->create(['status' => 'completed']);
    $model = WorkflowRunModel::factory()->for($run)->create(['status' => 'completed', 'input_tokens' => 10]);

    $result = app(WorkflowRunner::class)->reconcile($run);

    expect($result->status)->toBe('completed')
        ->and($model->refresh()->input_tokens)->toBe(10)
        ->and(app(WorkflowRunner::class)->active())->toBeNull();
});

test('it builds a snapshot in template order with null totals for unreported usage', function () {
    $alpha = ModelSelection::folderFor('openrouter/fixture/alpha');
    $beta = ModelSelection::folderFor('opencode-go/beta');
    $fixture = workflowRunnerFixture('running', [
        'status' => 'completed',
        'message' => null,
        'finishedAt' => '2026-10-22T12:10:00+03:00',
        'models' => [
            $alpha => workflowModelState('openrouter/fixture/alpha', 'completed', [
                'durationSeconds' => 20,
                'usage' => ['inputTokens' => 100, 'outputTokens' => 50, 'costUsd' => 0.25, 'calls' => 3],
                'items' => ['total' => 2, 'completed' => 2, 'failed' => 0],
                'steps' => [
                    'demos' => [
                        'status' => 'completed',
                        'message' => null,
                        'durationSeconds' => 12.5,
                        'attempts' => 0,
                        'usage' => ['inputTokens' => 60, 'outputTokens' => 30, 'costUsd' => 0.2, 'calls' => 2],
                        'items' => [
                            ['key' => '001', 'label' => 'Kule', 'status' => 'completed', 'steps' => ['build' => 'completed', 'unknown' => 'completed']],
                            ['key' => '../002', 'label' => 'Kaçış', 'status' => 'completed', 'steps' => []],
                        ],
                        'counts' => ['total' => 1, 'completed' => 1, 'failed' => 0, 'running' => 0, 'skipped' => 0, 'stopped' => 0],
                    ],
                    'ideas' => [
                        'status' => 'completed',
                        'message' => null,
                        'durationSeconds' => 7.5,
                        'attempts' => 1,
                        'usage' => ['inputTokens' => 40, 'outputTokens' => 20, 'costUsd' => 0.05, 'calls' => 1],
                    ],
                ],
            ]),
            $beta => workflowModelState('opencode-go/beta', 'completed', [
                'durationSeconds' => 25,
                'usage' => ['inputTokens' => null, 'outputTokens' => 20, 'costUsd' => 0, 'calls' => 2],
                'items' => ['total' => 2, 'completed' => 1, 'failed' => 1],
            ]),
        ],
    ]);
    File::put($fixture['directory'].'/events.jsonl', json_encode([
        'time' => '2026-10-22T12:00:00+03:00', 'level' => 'info', 'type' => 'run_started',
        'model' => null, 'step' => null, 'item' => null, 'message' => 'Çalıştırma başladı.',
    ], JSON_UNESCAPED_UNICODE)."\n");

    $snapshot = app(WorkflowRunner::class)->snapshot($fixture['run']);
    $alphaSnapshot = $snapshot['models'][0];

    expect(array_keys($snapshot))->toBe(['status', 'active', 'message', 'startedAt', 'finishedAt', 'durationSeconds', 'totals', 'models', 'events'])
        ->and($snapshot['status'])->toBe('completed')
        ->and($snapshot['active'])->toBeFalse()
        ->and($snapshot['message'])->toBe('İş akışı tamamlandı.')
        ->and($snapshot['totals'])->toBe([
            'inputTokens' => null,
            'outputTokens' => 70,
            'costUsd' => 0.25,
            'calls' => 5,
            'itemsTotal' => 4,
            'itemsCompleted' => 3,
            'itemsFailed' => 1,
        ])
        ->and(array_keys($alphaSnapshot))->toBe(['slug', 'model', 'name', 'status', 'message', 'durationSeconds', 'usage', 'items', 'steps', 'active', 'lastOutput'])
        ->and($alphaSnapshot['lastOutput'])->toBeNull()
        ->and($alphaSnapshot['usage'])->toBe(['inputTokens' => 100, 'outputTokens' => 50, 'costUsd' => 0.25, 'calls' => 3])
        ->and($alphaSnapshot['durationSeconds'])->toBe(20.0)
        ->and(array_column($alphaSnapshot['steps'], 'id'))->toBe(['ideas', 'demos'])
        ->and($alphaSnapshot['steps'][0])->toBe([
            'id' => 'ideas',
            'type' => 'llm_json',
            'title' => 'Fikirler',
            'status' => 'completed',
            'message' => null,
            'durationSeconds' => 7.5,
            'attempts' => 1,
            'usage' => ['inputTokens' => 40, 'outputTokens' => 20, 'costUsd' => 0.05, 'calls' => 1],
            'counts' => null,
            'children' => null,
            'items' => null,
        ])
        ->and($alphaSnapshot['steps'][1]['counts'])->toBe(['total' => 1, 'completed' => 1, 'failed' => 0, 'running' => 0, 'skipped' => 0, 'stopped' => 0])
        ->and($alphaSnapshot['steps'][1]['children'])->toBe([['id' => 'build', 'type' => 'code_text', 'title' => 'Demo yaz']])
        ->and($alphaSnapshot['steps'][1]['items'])->toBe([['key' => '001', 'label' => 'Kule', 'status' => 'completed', 'steps' => ['build' => 'completed']]])
        ->and($alphaSnapshot['active'])->toBe([])
        ->and($snapshot['models'][1]['steps'][0]['status'])->toBe('pending')
        ->and($snapshot['events'])->toHaveCount(1)
        ->and(json_encode($snapshot, JSON_THROW_ON_ERROR))->not->toContain($fixture['directory']);
});

test('it shows every snapshot time in the app timezone and passes each model last output through', function () {
    $root = storage_path('framework/testing/workflow-runner-times-'.Str::uuid());
    $workerScript = $root.'/state-worker.mjs';
    File::ensureDirectoryExists($root);
    File::put($workerScript, <<<'JS'
import { readFileSync, renameSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";

const root = dirname(process.argv[2]);
const state = JSON.parse(readFileSync(join(root, "state-fixture.json"), "utf8"));
writeFileSync(join(root, ".state.tmp"), JSON.stringify({ ...state, pid: process.pid }));
renameSync(join(root, ".state.tmp"), join(root, "state.json"));
setInterval(() => {}, 1000);
JS);
    $alpha = ModelSelection::folderFor('openrouter/fixture/alpha');
    $beta = ModelSelection::folderFor('opencode-go/beta');
    $fixture = workflowRunnerFixture('running', null, 0);
    File::put($fixture['directory'].'/state-fixture.json', json_encode([
        'schema' => 'benchforge-workflow-state/1',
        'runId' => $fixture['run']->slug,
        'status' => 'running',
        'message' => null,
        'startedAt' => '2026-10-22T20:37:37+03:00',
        'models' => [
            $alpha => workflowModelState('openrouter/fixture/alpha', 'running', [
                'active' => [
                    ['step' => 'demos', 'child' => 'build', 'item' => '002', 'label' => 'Labirent', 'attempt' => 1, 'startedAt' => '2026-10-22T20:38:00+03:00', 'tail' => 'yazıyor'],
                    ['step' => 'ideas', 'child' => null, 'item' => null, 'label' => null, 'attempt' => 2, 'startedAt' => 'yesterday', 'tail' => 5],
                ],
                'lastOutput' => [
                    'step' => 'demos', 'child' => 'build', 'item' => '001', 'label' => 'Kule', 'status' => 'failed',
                    'finishedAt' => '2026-10-22T20:39:30.250+03:00', 'tail' => 'a'.str_repeat('ğ', 5000),
                ],
            ]),
            $beta => workflowModelState('opencode-go/beta', 'running', [
                'lastOutput' => ['step' => '../ideas', 'child' => null, 'item' => null, 'status' => 'completed', 'finishedAt' => null, 'tail' => 'gizli'],
            ]),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    File::put($fixture['directory'].'/events.jsonl', implode("\n", array_map(
        fn (string $time): string => json_encode(['time' => $time, 'level' => 'info', 'type' => 'step_started', 'message' => 'Adım başladı.'], JSON_UNESCAPED_UNICODE),
        ['2026-10-22T20:37:37+03:00', '2026-10-22T17:38:01.5Z', '2026-02-31T10:00:00+03:00', 'tomorrow', '2026-10-22 20:37:37'],
    ))."\n");
    $pid = launchWorkflowFakeWorker($workerScript, $fixture['directory'].'/job.json');
    $fixture['run']->update(['runner_pid' => $pid]);
    $runner = workflowRunnerWithWorker($workerScript);

    try {
        expect(waitForWorkflowCondition(fn (): bool => File::exists($fixture['directory'].'/state.json')))->toBeTrue();

        $snapshot = $runner->snapshot($fixture['run']);
        [$alphaSnapshot, $betaSnapshot] = $snapshot['models'];

        expect($snapshot['active'])->toBeTrue()
            ->and($snapshot['startedAt'])->toBe($fixture['run']->refresh()->started_at->setTimezone('UTC')->toIso8601String())
            ->and($snapshot['startedAt'])->toEndWith('+00:00')
            ->and($snapshot['finishedAt'])->toBeNull()
            ->and(array_column($snapshot['events'], 'time'))->toBe(['2026-10-22T17:37:37+00:00', '2026-10-22T17:38:01+00:00', null, null, null])
            ->and(array_keys($snapshot['events'][0]))->toBe(['time', 'level', 'type', 'model', 'step', 'item', 'message'])
            ->and(array_keys($alphaSnapshot))->toBe(['slug', 'model', 'name', 'status', 'message', 'durationSeconds', 'usage', 'items', 'steps', 'active', 'lastOutput'])
            ->and($alphaSnapshot['active'])->toBe([
                ['step' => 'demos', 'child' => 'build', 'item' => '002', 'label' => 'Labirent', 'attempt' => 1, 'startedAt' => '2026-10-22T17:38:00+00:00', 'tail' => 'yazıyor'],
                ['step' => 'ideas', 'child' => null, 'item' => null, 'label' => null, 'attempt' => 2, 'startedAt' => null, 'tail' => ''],
            ])
            ->and($alphaSnapshot['lastOutput'])->toBe([
                'step' => 'demos',
                'child' => 'build',
                'item' => '001',
                'label' => 'Kule',
                'status' => 'failed',
                'finishedAt' => '2026-10-22T17:39:30+00:00',
                'tail' => str_repeat('ğ', 4096),
            ])
            ->and($betaSnapshot['lastOutput'])->toBeNull()
            ->and(json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))->not->toContain('gizli');
    } finally {
        if (workflowProcessIsAlive($pid)) {
            posix_kill(-$pid, SIGKILL);
        }

        File::deleteDirectory($root);
    }
});

test('it keeps the last output of a finished run and moves its finish time to the app timezone', function () {
    $alpha = ModelSelection::folderFor('openrouter/fixture/alpha');
    $beta = ModelSelection::folderFor('opencode-go/beta');
    $fixture = workflowRunnerFixture('running', [
        'status' => 'completed',
        'message' => null,
        'finishedAt' => '2026-10-22T12:10:00+03:00',
        'models' => [
            $alpha => workflowModelState('openrouter/fixture/alpha', 'completed', [
                'lastOutput' => ['step' => 'ideas', 'child' => null, 'item' => null, 'label' => null, 'status' => 'completed', 'finishedAt' => '2026-10-22T12:09:59+03:00', 'tail' => '```json'],
            ]),
            $beta => workflowModelState('opencode-go/beta', 'completed', [
                'lastOutput' => ['step' => 'ideas', 'status' => 'running', 'finishedAt' => '2026-10-22T12:09:59+03:00', 'tail' => 'yarım'],
            ]),
        ],
    ]);

    $snapshot = app(WorkflowRunner::class)->snapshot($fixture['run']);

    expect($snapshot['active'])->toBeFalse()
        ->and($snapshot['finishedAt'])->toBe('2026-10-22T09:10:00+00:00')
        ->and($snapshot['models'][0]['active'])->toBe([])
        ->and($snapshot['models'][0]['lastOutput'])->toBe([
            'step' => 'ideas', 'child' => null, 'item' => null, 'label' => null, 'status' => 'completed',
            'finishedAt' => '2026-10-22T09:09:59+00:00', 'tail' => '```json',
        ])
        ->and($snapshot['models'][1]['lastOutput'])->toBeNull();
});

test('it leaves models without calls out of the usage totals but not finished models without usage', function () {
    $alpha = ModelSelection::folderFor('openrouter/fixture/alpha');
    $beta = ModelSelection::folderFor('opencode-go/beta');
    $fixture = workflowRunnerFixture('running', [
        'status' => 'running',
        'message' => null,
        'models' => [
            $alpha => workflowModelState('openrouter/fixture/alpha', 'running', [
                'usage' => ['inputTokens' => 100, 'outputTokens' => 50, 'costUsd' => 0.25, 'calls' => 3],
            ]),
            $beta => workflowModelState('opencode-go/beta', 'pending'),
        ],
    ]);
    $runner = app(WorkflowRunner::class);

    $withoutCalls = $runner->snapshot($fixture['run']);

    expect($withoutCalls['totals'])->toMatchArray([
        'inputTokens' => 100,
        'outputTokens' => 50,
        'costUsd' => 0.25,
        'calls' => 3,
    ]);

    $finished = WorkflowRun::factory()->create(['status' => 'completed']);
    WorkflowRunModel::factory()->for($finished)->create(['input_tokens' => 10, 'output_tokens' => 5, 'cost_usd' => 0.5]);
    WorkflowRunModel::factory()->for($finished)->create(['input_tokens' => null, 'output_tokens' => 7, 'cost_usd' => null]);

    expect($runner->snapshot($finished)['totals'])->toMatchArray([
        'inputTokens' => null,
        'outputTokens' => 12,
        'costUsd' => null,
        'calls' => 0,
    ]);
});
