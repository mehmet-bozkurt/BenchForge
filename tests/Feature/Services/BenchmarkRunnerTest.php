<?php

use App\Exceptions\BenchmarkRunnerException;
use App\Models\BenchmarkExecution;
use App\Models\BenchmarkRun;
use App\Models\ModelRun;
use App\Models\ModelRunAttempt;
use App\Models\OpenCodeGoModel;
use App\Models\OpenRouterModel;
use App\Services\BenchForgeSettings;
use App\Services\BenchmarkPreflight;
use App\Services\BenchmarkRunner;
use App\Services\ModelRunContinuationRunner;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

use function Pest\Laravel\mock;

uses(LazilyRefreshDatabase::class);

test('it discovers safe prompt and model configuration options', function () {
    $root = storage_path('framework/testing/benchmark-root-'.Str::uuid());

    File::ensureDirectoryExists($root.'/prompts');
    File::ensureDirectoryExists($root.'/config');
    File::ensureDirectoryExists($root.'/runs');
    File::put($root.'/prompts/nova-marketplace-v2.txt', 'Build a marketplace.');
    File::put($root.'/config/models.conf', "# max_parallel=1\nminimax|openrouter/minimax/minimax-m3|3101\ndeepseek|opencode-go/deepseek|3102\n");
    config()->set('benchforge.root_path', $root);
    config()->set('benchforge.runs_path', $root.'/runs');
    config()->set('benchforge.runner_path', $root.'/runner');

    try {
        $options = app(BenchmarkRunner::class)->options();

        expect($options['prompts'])->toBe([[
            'value' => 'prompts/nova-marketplace-v2.txt',
            'label' => 'Nova Marketplace V2',
            'source' => 'file',
        ]])
            ->and($options['configs'])->toBe([[
                'value' => 'config/models.conf',
                'label' => 'Models',
                'modelCount' => 2,
                'ports' => [3101, 3102],
                'providers' => ['openrouter', 'opencode-go'],
                'maxParallel' => 1,
            ]])
            ->and($options['openRouterModels'])->toBeEmpty()
            ->and($options['openCodeGoModels'])->toBeEmpty()
            ->and($options['catalog'])->toBe([
                'count' => 0,
                'lastSyncedAt' => null,
            ])
            ->and($options['goCatalog'])->toBe([
                'count' => 0,
                'lastSyncedAt' => null,
            ])
            ->and($options['suggestedRoutes'])->toBe(['/']);
    } finally {
        File::deleteDirectory($root);
    }
});

test('it appends the static export contract to custom prompts', function () {
    $directory = storage_path('framework/testing/prompt-contract-'.Str::uuid());
    File::ensureDirectoryExists($directory);

    try {
        $method = new ReflectionMethod(BenchmarkRunner::class, 'materializePrompt');
        $path = $method->invoke(app(BenchmarkRunner::class), [
            'absolutePath' => null,
            'reference' => 'custom:incident-console',
            'content' => 'Build an incident response console.',
        ], $directory);
        $contents = File::get($path);

        expect($contents)
            ->toContain('Build an incident response console.')
            ->toContain('BenchForge teslim sözleşmesi:')
            ->toContain('NEXT_PUBLIC_BASE_PATH')
            ->toContain('static export');
    } finally {
        File::deleteDirectory($directory);
    }
});

test('it builds an OpenCode Go preflight from the selected catalog models', function () {
    $model = OpenCodeGoModel::factory()->create(['model_id' => 'glm-5.3-flash']);
    $result = [
        'ready' => true,
        'checkedAt' => '2026-09-21T10:00:00+03:00',
        'summary' => 'Kontroller geçti.',
        'checks' => [],
    ];
    $preflight = mock(BenchmarkPreflight::class);
    $preflight->shouldReceive('inspect')
        ->once()
        ->with([3251], ['opencode-go'])
        ->andReturn($result);

    expect(app(BenchmarkRunner::class)->preflight(null, [$model->model_id], 'opencode_go'))
        ->toBe($result);
});

test('it snapshots fixed OpenRouter routing in the generated run configuration', function () {
    $model = OpenRouterModel::factory()->create(['model_id' => 'deepseek/deepseek-v4.1-flash']);
    Http::preventStrayRequests();
    Http::fake([
        'https://openrouter.ai/api/v1/models/deepseek/deepseek-v4.1-flash/endpoints' => Http::response([
            'data' => ['id' => $model->model_id, 'endpoints' => [[
                'tag' => 'relace/fp4', 'provider_name' => 'Relace', 'supported_parameters' => ['tools'],
            ]]],
        ]),
    ]);
    $directory = storage_path('framework/testing/provider-snapshot-'.Str::uuid());
    File::ensureDirectoryExists($directory);

    try {
        $runner = app(BenchmarkRunner::class);
        $selection = (new ReflectionMethod(BenchmarkRunner::class, 'customModelSelection'))
            ->invoke($runner, [$model->model_id], 'fixed', [$model->model_id => 'relace/fp4']);
        $path = (new ReflectionMethod(BenchmarkRunner::class, 'materializeOpenCodeConfig'))
            ->invoke($runner, $selection, $directory);
        $config = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

        expect($selection['configContents'])->toContain('# openrouter_routing=fixed')
            ->toContain('# openrouter_requested_providers=deepseek/deepseek-v4.1-flash:relace/fp4')
            ->and($config['provider']['openrouter']['models'][$model->model_id]['options']['provider'])->toBe([
                'allow_fallbacks' => false, 'data_collection' => 'deny', 'require_parameters' => true,
                'order' => ['relace/fp4'], 'only' => ['relace/fp4'],
            ]);
        Http::assertSentCount(1);
    } finally {
        File::deleteDirectory($directory);
    }
});

test('it refuses a stale fixed provider before creating an execution or workspace', function () {
    $model = OpenRouterModel::factory()->create(['model_id' => 'deepseek/deepseek-v4.1-flash']);
    $root = storage_path('framework/testing/provider-start-'.Str::uuid());
    File::ensureDirectoryExists($root.'/runs');
    File::ensureDirectoryExists($root.'/prompts');
    File::ensureDirectoryExists($root.'/config');
    config()->set('benchforge.root_path', $root);
    config()->set('benchforge.runs_path', $root.'/runs');
    config()->set('benchforge.runner_path', $root.'/runner');
    Http::preventStrayRequests();
    Http::fake([
        'https://openrouter.ai/api/v1/models/deepseek/deepseek-v4.1-flash/endpoints' => Http::sequence()
            ->push(['data' => ['id' => $model->model_id, 'endpoints' => [['tag' => 'relace/fp4', 'supported_parameters' => ['tools']]]]])
            ->push(['data' => ['id' => $model->model_id, 'endpoints' => []]]),
    ]);
    mock(BenchmarkPreflight::class)->shouldReceive('inspect')->once()->with([3251], ['openrouter'])
        ->andReturn(['ready' => true, 'checks' => []]);
    mock(ModelRunContinuationRunner::class)->shouldReceive('reconcileActiveAttempts')->once();

    try {
        $runner = app(BenchmarkRunner::class);
        $runner->preflight(null, [$model->model_id], 'custom', 'fixed', [$model->model_id => 'relace/fp4']);

        expect(fn () => $runner->start([
            'run_id' => 'stale-provider-run', 'prompt_mode' => 'custom',
            'custom_prompt_content' => 'Build a dashboard for this provider test.',
            'model_mode' => 'custom', 'models' => [$model->model_id], 'routing_mode' => 'fixed',
            'fixed_providers' => [$model->model_id => 'relace/fp4'], 'max_parallel' => 1, 'routes' => ['/'],
        ]))->toThrow(BenchmarkRunnerException::class, 'seçilen sağlayıcı artık listede yok');
        $this->assertDatabaseMissing('benchmark_executions', ['run_id' => 'stale-provider-run']);
        expect(File::exists($root.'/runs/stale-provider-run'))->toBeFalse()
            ->and(File::exists($root.'/runner'))->toBeFalse();
        Http::assertSentCount(2);
    } finally {
        File::deleteDirectory($root);
    }
});

test('it rejects an invalid model group concurrency limit', function () {
    $root = storage_path('framework/testing/benchmark-root-'.Str::uuid());

    File::ensureDirectoryExists($root.'/prompts');
    File::ensureDirectoryExists($root.'/config');
    File::ensureDirectoryExists($root.'/runs');
    File::put($root.'/prompts/nova.txt', 'Build a marketplace.');
    File::put($root.'/config/models.conf', "# max_parallel=2\nminimax|openrouter/minimax/minimax-m3|3101\n");
    config()->set('benchforge.root_path', $root);
    config()->set('benchforge.runs_path', $root.'/runs');

    try {
        expect(fn () => app(BenchmarkRunner::class)->options())
            ->toThrow(BenchmarkRunnerException::class, 'max_parallel 1 ile 1 arasında olmalıdır.');
    } finally {
        File::deleteDirectory($root);
    }
});

test('it rejects requested concurrency above the selected model count', function () {
    $root = storage_path('framework/testing/benchmark-root-'.Str::uuid());

    File::ensureDirectoryExists($root.'/prompts');
    File::ensureDirectoryExists($root.'/config');
    File::ensureDirectoryExists($root.'/runs');
    File::put($root.'/prompts/nova.txt', 'Build a marketplace.');
    File::put($root.'/config/models.conf', "# max_parallel=1\nminimax|openrouter/minimax/minimax-m3|3101\n");
    config()->set('benchforge.root_path', $root);
    config()->set('benchforge.runs_path', $root.'/runs');

    try {
        expect(fn () => app(BenchmarkRunner::class)->start([
            'run_id' => 'too-much-concurrency',
            'prompt' => 'prompts/nova.txt',
            'config' => 'config/models.conf',
            'max_parallel' => 2,
            'routes' => ['/'],
        ]))->toThrow(BenchmarkRunnerException::class, 'Eşzamanlılık 1 ile 1 arasında olmalıdır.');
    } finally {
        File::deleteDirectory($root);
    }
});

test('it rejects a new benchmark while a model recovery attempt is active', function () {
    $root = storage_path('framework/testing/benchmark-recovery-lock-'.Str::uuid());
    File::ensureDirectoryExists($root);
    config()->set('benchforge.root_path', $root);
    $run = BenchmarkRun::factory()->create();
    $modelRun = ModelRun::factory()->for($run)->create(['status' => 'fail']);
    ModelRunAttempt::factory()->for($modelRun)->create([
        'status' => 'running',
        'result' => null,
        'finished_at' => null,
    ]);
    mock(ModelRunContinuationRunner::class)
        ->shouldReceive('reconcileActiveAttempts')
        ->once();

    try {
        expect(fn () => app(BenchmarkRunner::class)->start([
            'run_id' => 'blocked-by-recovery',
        ]))->toThrow(BenchmarkRunnerException::class);
        expect(BenchmarkExecution::query()->where('run_id', 'blocked-by-recovery')->exists())
            ->toBeFalse();
    } finally {
        File::deleteDirectory($root);
    }
});

test('it returns an idle snapshot before any benchmark has been started', function () {
    $snapshot = app(BenchmarkRunner::class)->snapshot();

    expect($snapshot['status'])->toBe('idle')
        ->and($snapshot['active'])->toBeFalse()
        ->and($snapshot['progress'])->toBe([
            'completedModels' => 0,
            'totalModels' => 0,
            'percent' => 0,
            'phase' => 'Hazır',
        ]);
});

test('it rejects a run before launch when the configured node binary is not executable', function () {
    $root = storage_path('framework/testing/benchmark-root-'.Str::uuid());

    File::ensureDirectoryExists($root.'/prompts');
    File::ensureDirectoryExists($root.'/config');
    File::ensureDirectoryExists($root.'/runs');
    File::ensureDirectoryExists($root.'/tools');
    File::put($root.'/prompts/nova.txt', 'Build a marketplace.');
    File::put($root.'/config/models.conf', "hy3|opencode-go/hy3|49151\n");
    File::put($root.'/tools/benchmark.zsh', "#!/bin/zsh\n");
    config()->set('benchforge.root_path', $root);
    config()->set('benchforge.runs_path', $root.'/runs');
    config()->set('benchforge.benchmark_script', $root.'/tools/benchmark.zsh');
    config()->set('benchforge.runner_path', $root.'/runner');
    config()->set('benchforge.node_binary', $root.'/missing-node');
    config()->set('benchforge.command_path', '/usr/bin:/bin');
    config()->set('benchforge.home_path', $root);

    try {
        expect(fn () => app(BenchmarkRunner::class)->start([
            'run_id' => 'invalid-node-run',
            'prompt' => 'prompts/nova.txt',
            'config' => 'config/models.conf',
            'routes' => ['/'],
        ]))->toThrow(BenchmarkRunnerException::class, 'Sistem ön kontrolü başarısız:');

        expect(BenchmarkExecution::query()->where('run_id', 'invalid-node-run')->exists())
            ->toBeFalse();
    } finally {
        File::deleteDirectory($root);
    }
});

test('it snapshots the current configured shell after refreshing setup before launch', function () {
    $root = storage_path('framework/testing/benchmark-shell-manifest-'.Str::uuid());

    foreach (['prompts', 'config', 'runs', 'tools', 'bin'] as $directory) {
        File::ensureDirectoryExists($root.'/'.$directory);
    }

    File::put($root.'/prompts/fixture.txt', 'Build a fixture dashboard.');
    File::put($root.'/config/models.conf', "fixture|opencode-go/fixture|49155\n");
    File::put($root.'/config/opencode-benchmark.json', '{}');
    File::put($root.'/tools/benchmark.zsh', '#!/bin/zsh');
    File::put($root.'/bin/fake-node', <<<'SH'
#!/bin/sh
if [ -n "${APP_KEY+x}${BENCHFORGE_TEST_LAUNCH_SECRET+x}" ]; then exit 91; fi
printf blocked > "$HOME/captured-environment.txt"
cp "$3" "$HOME/captured-job.json"
echo 0
SH);
    chmod($root.'/bin/fake-node', 0755);
    File::put($root.'/bin/zsh', "#!/bin/sh\necho 'zsh 5.9'\n");
    chmod($root.'/bin/zsh', 0755);
    config()->set('benchforge.root_path', $root.'/outdated-root');
    mock(BenchForgeSettings::class)->shouldReceive('apply')->once()->andReturnUsing(function () use ($root): void {
        config()->set('benchforge.root_path', $root);
        config()->set('benchforge.runs_path', $root.'/runs');
        config()->set('benchforge.benchmark_script', $root.'/tools/benchmark.zsh');
        config()->set('benchforge.runner_path', $root.'/runner');
        config()->set('benchforge.node_binary', $root.'/bin/fake-node');
        config()->set('benchforge.shell_binary', $root.'/bin/zsh');
        config()->set('benchforge.command_path', '/usr/bin:/bin');
        config()->set('benchforge.home_path', $root);
    });
    mock(BenchmarkPreflight::class)->shouldReceive('inspect')->once()->with([49155], ['opencode-go'])
        ->andReturn(['ready' => true, 'checks' => []]);
    mock(ModelRunContinuationRunner::class)->shouldReceive('reconcileActiveAttempts')->once();
    $originalEnvironment = $_ENV;
    $_ENV['APP_KEY'] = 'benchmark-launch-test-only-secret';
    $_ENV['BENCHFORGE_TEST_LAUNCH_SECRET'] = 'benchmark-launch-unknown-test-only-secret';

    try {
        expect(fn () => app(BenchmarkRunner::class)->start([
            'run_id' => 'configured-shell-run',
            'prompt' => 'prompts/fixture.txt',
            'config' => 'config/models.conf',
            'routes' => ['/'],
        ]))->toThrow(BenchmarkRunnerException::class, 'Koşucu işlem kimliği alınamadı.');

        $job = json_decode(File::get($root.'/captured-job.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($job['shellBinary'])->toBe($root.'/bin/zsh')
            ->and($job['projectRoot'])->toBe($root)
            ->and(File::get($root.'/captured-environment.txt'))->toBe('blocked')
            ->and(File::exists($root.'/runs/configured-shell-run'))->toBeFalse();
        $this->assertDatabaseHas('benchmark_executions', [
            'run_id' => 'configured-shell-run', 'status' => 'failed',
        ]);
    } finally {
        $_ENV = $originalEnvironment;
        File::deleteDirectory($root);
    }
});

test('it reports an empty log while a completed run has not written output', function () {
    $root = storage_path('framework/testing/benchmark-root-'.Str::uuid());
    $runnerPath = $root.'/runner';
    $logPath = $runnerPath.'/empty.log';

    File::ensureDirectoryExists($root.'/runs');
    File::ensureDirectoryExists($runnerPath);
    File::put($logPath, '');
    config()->set('benchforge.root_path', $root);
    config()->set('benchforge.runs_path', $root.'/runs');
    config()->set('benchforge.runner_path', $runnerPath);
    BenchmarkExecution::factory()->create([
        'status' => 'completed',
        'model_count' => 1,
        'completed_model_count' => 0,
        'progress_percent' => 100,
        'log_path' => $logPath,
        'synced_at' => now(),
        'finished_at' => now(),
    ]);

    try {
        $snapshot = app(BenchmarkRunner::class)->snapshot();

        expect($snapshot['logTail'])->toBe('');
    } finally {
        File::deleteDirectory($root);
    }
});

test('it distinguishes a completed runner from a benchmark with failed model checks', function () {
    $root = storage_path('framework/testing/benchmark-root-'.Str::uuid());
    $runId = 'completed-with-failures';

    File::ensureDirectoryExists($root.'/runs');
    File::ensureDirectoryExists($root.'/runner');
    config()->set('benchforge.root_path', $root);
    config()->set('benchforge.runs_path', $root.'/runs');
    config()->set('benchforge.runner_path', $root.'/runner');
    BenchmarkRun::factory()->create([
        'slug' => $runId,
        'model_count' => 2,
        'passed_count' => 1,
        'failed_count' => 1,
        'skipped_count' => 0,
        'unknown_count' => 0,
    ]);
    BenchmarkExecution::factory()->create([
        'run_id' => $runId,
        'status' => 'completed',
        'model_count' => 2,
        'completed_model_count' => 2,
        'progress_percent' => 100,
        'log_path' => $root.'/runner/missing.log',
        'synced_at' => now(),
        'finished_at' => now(),
    ]);

    try {
        $snapshot = app(BenchmarkRunner::class)->snapshot();

        expect($snapshot['status'])->toBe('completed')
            ->and($snapshot['message'])->toBe('Koşucu sorunsuz tamamlandı; 1 modelde başarısız doğrulama var.')
            ->and($snapshot['outcome'])->toBe([
                'status' => 'fail',
                'label' => 'Kontrollerde başarısızlık var',
                'summary' => '1/2 model tüm aşamalardan geçti.',
                'available' => true,
                'totalModels' => 2,
                'passedModels' => 1,
                'failedModels' => 1,
                'otherModels' => 0,
            ]);
    } finally {
        File::deleteDirectory($root);
    }
});
