<?php

use App\Exceptions\BenchmarkRunnerException;
use App\Models\BenchmarkExecution;
use App\Models\BenchmarkRun;
use App\Models\ModelRun;
use App\Models\ModelRunAttempt;
use App\Models\StageResult;
use App\Services\BenchForgeSettings;
use App\Services\BenchmarkProcessEnvironment;
use App\Services\BenchmarkRunStorage;
use App\Services\ModelRunContinuationRunner;
use App\Services\ModelRunDiagnosticContext;
use App\Services\OpenCodeSessionLocator;
use App\Services\OpenCodeUsageTracker;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

use function Pest\Laravel\mock;

uses(LazilyRefreshDatabase::class);

/** @return array{root: string, run: BenchmarkRun, models: list<ModelRun>} */
function continuationConcurrencyFixture(array $ports = [49161, 49162, 49163]): array
{
    $root = storage_path('framework/testing/continuation-concurrency-'.Str::uuid());
    $runPath = $root.'/runs/concurrent-recovery';
    File::ensureDirectoryExists($runPath);

    $models = [];
    $definitions = [];
    $run = BenchmarkRun::factory()->create(['slug' => 'concurrent-recovery']);

    foreach (['model-a', 'model-b', 'model-c'] as $index => $slug) {
        File::ensureDirectoryExists($runPath.'/'.$slug);
        $model = 'openrouter/vendor/'.$slug;
        $definitions[] = $slug.'|'.$model.'|'.$ports[$index];
        $models[] = ModelRun::factory()->for($run)->create([
            'slug' => $slug,
            'model' => $model,
            'status' => 'fail',
        ]);
    }

    File::put($runPath.'/_modeller.conf', implode("\n", $definitions)."\n");
    config()->set('benchforge.root_path', $root);
    config()->set('benchforge.runs_path', $root.'/runs');
    config()->set('benchforge.runner_path', $root.'/runner');

    return compact('root', 'run', 'models');
}

function continuationRunnerWithActiveFixtures(): ModelRunContinuationRunner
{
    return new class(app(OpenCodeSessionLocator::class), app(BenchmarkRunStorage::class), app(OpenCodeUsageTracker::class), app(ModelRunDiagnosticContext::class), app(BenchmarkProcessEnvironment::class)) extends ModelRunContinuationRunner
    {
        public function reconcileActiveAttempts(): void {}
    };
}

test('it snapshots the current shell after refreshing setup before a validation attempt', function () {
    $root = storage_path('framework/testing/continuation-shell-manifest-'.Str::uuid());
    $runPath = $root.'/runs/configured-shell-recovery';
    File::ensureDirectoryExists($runPath.'/model-a');
    File::ensureDirectoryExists($root.'/tools');
    File::ensureDirectoryExists($root.'/bin');
    File::put($runPath.'/_modeller.conf', "model-a|fixture/model-a|49156\n");
    File::put($root.'/tools/resume-model.zsh', '#!/bin/zsh');
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
    $run = BenchmarkRun::factory()->create(['slug' => 'configured-shell-recovery', 'routes' => ['/']]);
    $model = ModelRun::factory()->for($run)->create([
        'slug' => 'model-a', 'model' => 'fixture/model-a', 'status' => 'fail',
    ]);
    config()->set('benchforge.root_path', $root.'/outdated-root');
    config()->set('benchforge.runs_path', $root.'/outdated-root/runs');
    mock(BenchForgeSettings::class)->shouldReceive('apply')->once()->andReturnUsing(function () use ($root): void {
        config()->set('benchforge.root_path', $root);
        config()->set('benchforge.runs_path', $root.'/runs');
        config()->set('benchforge.resume_script', $root.'/tools/resume-model.zsh');
        config()->set('benchforge.node_binary', $root.'/bin/fake-node');
        config()->set('benchforge.shell_binary', $root.'/bin/zsh');
        config()->set('benchforge.command_path', '/usr/bin:/bin');
        config()->set('benchforge.home_path', $root);
    });
    $originalEnvironment = $_ENV;
    $_ENV['APP_KEY'] = 'continuation-launch-test-only-secret';
    $_ENV['BENCHFORGE_TEST_LAUNCH_SECRET'] = 'continuation-launch-unknown-test-only-secret';

    try {
        expect(fn () => app(ModelRunContinuationRunner::class)->start($run, $model, 'validate'))
            ->toThrow(BenchmarkRunnerException::class, 'Kurtarma koşucusunun işlem kimliği alınamadı.');

        $job = json_decode(File::get($root.'/captured-job.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($job['shellBinary'])->toBe($root.'/bin/zsh')
            ->and($job['projectRoot'])->toBe($root)
            ->and(File::get($root.'/captured-environment.txt'))->toBe('blocked')
            ->and($job['mode'])->toBe('validate');
        $this->assertDatabaseHas('model_run_attempts', [
            'model_run_id' => $model->id, 'status' => 'failed', 'mode' => 'validate',
        ]);
        expect(File::exists($runPath.'/_attempts/model-a/attempt-1/active.lock'))->toBeFalse();
    } finally {
        $_ENV = $originalEnvironment;
        File::deleteDirectory($root);
    }
});

test('it rejects a non-zsh shell during a direct validation start before launching a worker', function () {
    ['root' => $root, 'run' => $run, 'models' => $models] = continuationConcurrencyFixture();
    File::ensureDirectoryExists($root.'/tools');
    File::put($root.'/tools/resume-model.zsh', '#!/bin/zsh');
    config()->set('benchforge.resume_script', $root.'/tools/resume-model.zsh');
    config()->set('benchforge.shell_binary', '/bin/sh');
    config()->set('benchforge.node_binary', $root.'/missing-node');
    config()->set('benchforge.command_path', '/usr/bin:/bin');
    config()->set('benchforge.home_path', $root);
    mock(BenchForgeSettings::class)->shouldReceive('apply')->once();

    try {
        expect(fn () => app(ModelRunContinuationRunner::class)->start($run, $models[0], 'validate'))
            ->toThrow(BenchmarkRunnerException::class, 'Yapılandırılan shell çalıştırıcısı zsh olarak doğrulanamadı.');

        $this->assertDatabaseHas('model_run_attempts', [
            'model_run_id' => $models[0]->id, 'status' => 'failed', 'mode' => 'validate',
        ]);
        expect(File::exists($root.'/runs/concurrent-recovery/_attempts/model-a/attempt-1/active.lock'))->toBeFalse();
    } finally {
        File::deleteDirectory($root);
    }
});

test('it allows another model to use the second recovery slot', function () {
    ['root' => $root, 'run' => $run, 'models' => $models] = continuationConcurrencyFixture();
    ModelRunAttempt::factory()->for($models[0])->create([
        'status' => 'running',
        'result' => null,
        'finished_at' => null,
    ]);

    try {
        $availability = continuationRunnerWithActiveFixtures()->availability($run, $models[1]);

        expect($availability['canValidate'])->toBeTrue()
            ->and($availability['reason'])->toBeNull();
    } finally {
        File::deleteDirectory($root);
    }
});

test('it offers continuation for another model with a verified session while one recovery is active', function () {
    ['root' => $root, 'run' => $run, 'models' => $models] = continuationConcurrencyFixture();
    $runPath = $root.'/runs/concurrent-recovery';
    File::ensureDirectoryExists($runPath.'/_sessions');
    File::put($runPath.'/_opencode.json', '{}');
    File::put($runPath.'/_sessions/model-b.session', 'ses_model_b');
    ModelRunAttempt::factory()->for($models[0])->create([
        'status' => 'running',
        'result' => null,
        'finished_at' => null,
    ]);
    mock(OpenCodeSessionLocator::class)
        ->shouldReceive('locate')
        ->once()
        ->with($runPath.'/model-b', 'ses_model_b')
        ->andReturn('ses_model_b');

    try {
        $availability = continuationRunnerWithActiveFixtures()->availability($run, $models[1]);

        expect($availability['canContinue'])->toBeTrue()
            ->and($availability['canValidate'])->toBeTrue()
            ->and($availability['sessionId'])->toBe('ses_model_b');
    } finally {
        File::deleteDirectory($root);
    }
});

test('it rejects a third recovery attempt when two different models are active', function () {
    ['root' => $root, 'run' => $run, 'models' => $models] = continuationConcurrencyFixture();

    foreach ([$models[0], $models[1]] as $model) {
        ModelRunAttempt::factory()->for($model)->create([
            'status' => 'running',
            'result' => null,
            'finished_at' => null,
        ]);
    }

    try {
        $runner = continuationRunnerWithActiveFixtures();
        $availability = $runner->availability($run, $models[2]);

        expect($availability['canValidate'])->toBeFalse()
            ->and($availability['canContinue'])->toBeFalse()
            ->and($availability['reason'])->not->toBeNull();
        expect(fn () => $runner->start($run, $models[2], 'validate'))
            ->toThrow(BenchmarkRunnerException::class);
        expect($models[2]->modelRunAttempts()->count())->toBe(0);
    } finally {
        File::deleteDirectory($root);
    }
});

test('it rejects another attempt for a model already running', function () {
    ['root' => $root, 'run' => $run, 'models' => $models] = continuationConcurrencyFixture();
    ModelRunAttempt::factory()->for($models[0])->create([
        'status' => 'running',
        'result' => null,
        'finished_at' => null,
    ]);

    try {
        $runner = continuationRunnerWithActiveFixtures();
        $availability = $runner->availability($run, $models[0]);

        expect($availability['canValidate'])->toBeFalse()
            ->and($availability['canContinue'])->toBeFalse();
        expect(fn () => $runner->start($run, $models[0], 'validate'))
            ->toThrow(BenchmarkRunnerException::class);
        expect($models[0]->modelRunAttempts()->count())->toBe(1);
    } finally {
        File::deleteDirectory($root);
    }
});

test('it blocks recovery while a new benchmark is active', function () {
    ['root' => $root, 'run' => $run, 'models' => $models] = continuationConcurrencyFixture();
    BenchmarkExecution::factory()->create([
        'run_id' => 'different-running-benchmark',
        'status' => 'running',
    ]);

    try {
        $runner = continuationRunnerWithActiveFixtures();
        $availability = $runner->availability($run, $models[0]);

        expect($availability['canValidate'])->toBeFalse()
            ->and($availability['canContinue'])->toBeFalse();
        expect(fn () => $runner->start($run, $models[0], 'validate'))
            ->toThrow(BenchmarkRunnerException::class);
        expect($models[0]->modelRunAttempts()->count())->toBe(0);
    } finally {
        File::deleteDirectory($root);
    }
});

test('it rejects a second recovery when historical runs share the same port', function () {
    ['root' => $root, 'models' => $models] = continuationConcurrencyFixture();
    $otherRunPath = $root.'/runs/another-recovery';
    File::ensureDirectoryExists($otherRunPath.'/another-model');
    File::put($otherRunPath.'/_modeller.conf', "another-model|openrouter/vendor/another-model|49161\n");
    $otherRun = BenchmarkRun::factory()->create(['slug' => 'another-recovery']);
    $otherModel = ModelRun::factory()->for($otherRun)->create([
        'slug' => 'another-model',
        'model' => 'openrouter/vendor/another-model',
        'status' => 'fail',
    ]);
    ModelRunAttempt::factory()->for($models[0])->create([
        'status' => 'running',
        'result' => null,
        'finished_at' => null,
    ]);

    try {
        $runner = continuationRunnerWithActiveFixtures();
        $availability = $runner->availability($otherRun, $otherModel);

        expect($availability['canValidate'])->toBeFalse()
            ->and($availability['reason'])->not->toBeNull();
        expect(fn () => $runner->start($otherRun, $otherModel, 'validate'))
            ->toThrow(BenchmarkRunnerException::class);
        expect($otherModel->modelRunAttempts()->count())->toBe(0);
    } finally {
        File::deleteDirectory($root);
    }
});

test('it records a successful validation attempt without changing the original benchmark result', function () {
    $root = storage_path('framework/testing/continuation-'.Str::uuid());
    $runPath = $root.'/runs/recovery-test';
    $workspace = $runPath.'/model-a';
    $attemptPath = $runPath.'/_attempts/model-a/attempt-1';
    File::ensureDirectoryExists($workspace);
    File::ensureDirectoryExists($attemptPath);
    File::put($workspace.'/package.json', '{}');
    File::put($attemptPath.'/result.txt', 'validate|-||skip|0|0|0|0|0|12|openrouter/vendor/model-a');
    File::put($attemptPath.'/state.json', json_encode([
        'version' => 1,
        'status' => 'completed',
        'attemptId' => 1,
        'runnerPid' => 999999,
        'processPid' => 999998,
        'startedAt' => now()->subSeconds(12)->toIso8601String(),
        'finishedAt' => now()->toIso8601String(),
        'exitCode' => 0,
        'message' => 'Doğrulamalar tamamlandı.',
    ], JSON_THROW_ON_ERROR));
    config()->set('benchforge.root_path', $root);
    config()->set('benchforge.runs_path', $root.'/runs');
    config()->set('benchforge.runner_path', $root.'/runner');
    $run = BenchmarkRun::factory()->create([
        'slug' => 'recovery-test',
        'failed_count' => 1,
        'passed_count' => 0,
        'model_count' => 1,
    ]);
    $modelRun = ModelRun::factory()->for($run)->create([
        'slug' => 'model-a',
        'model' => 'openrouter/vendor/model-a',
        'status' => 'fail',
        'failed_stages' => ['build'],
    ]);
    StageResult::factory()->for($modelRun)->create(['stage' => 'build', 'status' => 'fail']);
    $attempt = ModelRunAttempt::factory()->for($modelRun)->create([
        'id' => 1,
        'attempt_number' => 1,
        'mode' => 'validate',
        'status' => 'running',
        'source_session_id' => null,
        'session_id' => null,
        'result' => null,
        'state_path' => $attemptPath.'/state.json',
        'log_path' => $attemptPath.'/runner.log',
        'attempt_path' => $attemptPath,
        'finished_at' => null,
        'synced_at' => null,
    ]);

    try {
        $attempt = app(ModelRunContinuationRunner::class)->reconcile($attempt);

        expect($attempt->status)->toBe('completed')
            ->and($attempt->result['overallStatus'])->toBe('pass')
            ->and($attempt->result['durationSeconds'])->toBe(12)
            ->and($attempt->result['stages']['opencode']['status'])->toBe('skip')
            ->and($attempt->usage['source'])->toBe('validation_only')
            ->and($attempt->usage['costUsd'])->toBe('0.0000000000')
            ->and($attempt->synced_at)->not->toBeNull()
            ->and($modelRun->fresh()->status)->toBe('fail')
            ->and($modelRun->fresh()->failed_stages)->toBe(['build'])
            ->and($run->fresh()->failed_count)->toBe(1)
            ->and($modelRun->stageResults()->where('stage', 'build')->sole()->status)->toBe('fail');
    } finally {
        File::deleteDirectory($root);
    }
});

test('it offers continuation only when the exact workspace session can be verified', function () {
    $root = storage_path('framework/testing/continuation-'.Str::uuid());
    $runPath = $root.'/runs/session-test';
    $workspace = $runPath.'/model-a';
    File::ensureDirectoryExists($workspace);
    File::ensureDirectoryExists($runPath.'/_sessions');
    File::put($runPath.'/_modeller.conf', "model-a|openrouter/vendor/model-a|49151\n");
    File::put($runPath.'/_opencode.json', '{}');
    File::put($runPath.'/_sessions/model-a.session', 'ses_exact123');
    config()->set('benchforge.root_path', $root);
    config()->set('benchforge.runs_path', $root.'/runs');
    config()->set('benchforge.runner_path', $root.'/runner');
    $run = BenchmarkRun::factory()->create([
        'slug' => 'session-test',
        'routes' => ['/'],
        'failed_count' => 1,
        'model_count' => 1,
    ]);
    $modelRun = ModelRun::factory()->for($run)->create([
        'slug' => 'model-a',
        'model' => 'openrouter/vendor/model-a',
        'status' => 'fail',
    ]);
    $locator = mock(OpenCodeSessionLocator::class);
    $locator->shouldReceive('locate')
        ->once()
        ->with($workspace, 'ses_exact123')
        ->andReturn('ses_exact123');

    try {
        $availability = app(ModelRunContinuationRunner::class)->availability($run, $modelRun);

        expect($availability)->toBe([
            'canContinue' => true,
            'canValidate' => true,
            'reason' => null,
            'sessionId' => 'ses_exact123',
        ]);
    } finally {
        File::deleteDirectory($root);
    }
});

test('it resumes from a stopped attempt whose verified usage has the session missing from its result', function () {
    $root = storage_path('framework/testing/continuation-'.Str::uuid());
    $runPath = $root.'/runs/stopped-session-test';
    $workspace = $runPath.'/model-a';
    File::ensureDirectoryExists($workspace);
    File::ensureDirectoryExists($runPath.'/_sessions');
    File::put($runPath.'/_modeller.conf', "model-a|openrouter/vendor/model-a|49154\n");
    File::put($runPath.'/_opencode.json', '{}');
    File::put($runPath.'/_sessions/model-a.session', 'ses_initial');
    config()->set('benchforge.root_path', $root);
    config()->set('benchforge.runs_path', $root.'/runs');
    config()->set('benchforge.runner_path', $root.'/runner');
    $run = BenchmarkRun::factory()->create(['slug' => 'stopped-session-test']);
    $modelRun = ModelRun::factory()->for($run)->create([
        'slug' => 'model-a',
        'model' => 'openrouter/vendor/model-a',
        'status' => 'fail',
    ]);
    ModelRunAttempt::factory()->for($modelRun)->create([
        'attempt_number' => 1,
        'mode' => 'continue',
        'status' => 'stopped',
        'session_id' => null,
        'result' => null,
        'usage' => [
            'source' => 'opencode_session',
            'sessionId' => 'ses_stopped_attempt',
        ],
    ]);
    $locator = mock(OpenCodeSessionLocator::class);
    $locator->shouldReceive('locate')
        ->once()
        ->with($workspace, 'ses_stopped_attempt')
        ->andReturn('ses_stopped_attempt');

    try {
        $availability = app(ModelRunContinuationRunner::class)->availability($run, $modelRun);

        expect($availability['canContinue'])->toBeTrue()
            ->and($availability['sessionId'])->toBe('ses_stopped_attempt');
    } finally {
        File::deleteDirectory($root);
    }
});

test('it skips a latest attempt session not confirmed by the locator and tries an older attempt', function () {
    $root = storage_path('framework/testing/continuation-'.Str::uuid());
    $runPath = $root.'/runs/older-session-test';
    $workspace = $runPath.'/model-a';
    File::ensureDirectoryExists($workspace);
    File::ensureDirectoryExists($runPath.'/_sessions');
    File::put($runPath.'/_modeller.conf', "model-a|openrouter/vendor/model-a|49155\n");
    File::put($runPath.'/_opencode.json', '{}');
    File::put($runPath.'/_sessions/model-a.session', 'ses_initial');
    config()->set('benchforge.root_path', $root);
    config()->set('benchforge.runs_path', $root.'/runs');
    config()->set('benchforge.runner_path', $root.'/runner');
    $run = BenchmarkRun::factory()->create(['slug' => 'older-session-test']);
    $modelRun = ModelRun::factory()->for($run)->create([
        'slug' => 'model-a',
        'model' => 'openrouter/vendor/model-a',
        'status' => 'fail',
    ]);
    ModelRunAttempt::factory()->for($modelRun)->create([
        'attempt_number' => 1,
        'status' => 'failed',
        'session_id' => 'ses_older',
        'result' => null,
    ]);
    ModelRunAttempt::factory()->for($modelRun)->create([
        'attempt_number' => 2,
        'status' => 'failed',
        'session_id' => 'ses_latest',
        'result' => null,
        'usage' => [
            'source' => 'opencode_session',
            'sessionId' => 'ses_latest_usage',
        ],
    ]);
    $locator = mock(OpenCodeSessionLocator::class);
    $locator->shouldReceive('locate')->once()->with($workspace, 'ses_latest')->andReturn('ses_other')->ordered();
    $locator->shouldReceive('locate')->once()->with($workspace, 'ses_latest_usage')->andReturnNull()->ordered();
    $locator->shouldReceive('locate')->once()->with($workspace, 'ses_older')->andReturn('ses_older')->ordered();

    try {
        $availability = app(ModelRunContinuationRunner::class)->availability($run, $modelRun);

        expect($availability['canContinue'])->toBeTrue()
            ->and($availability['sessionId'])->toBe('ses_older');
    } finally {
        File::deleteDirectory($root);
    }
});

test('it falls back to the exact original session when later attempts are not verified', function () {
    $root = storage_path('framework/testing/continuation-'.Str::uuid());
    $runPath = $root.'/runs/original-session-test';
    $workspace = $runPath.'/model-a';
    File::ensureDirectoryExists($workspace);
    File::ensureDirectoryExists($runPath.'/_sessions');
    File::put($runPath.'/_modeller.conf', "model-a|openrouter/vendor/model-a|49156\n");
    File::put($runPath.'/_opencode.json', '{}');
    File::put($runPath.'/_sessions/model-a.session', 'ses_initial');
    config()->set('benchforge.root_path', $root);
    config()->set('benchforge.runs_path', $root.'/runs');
    config()->set('benchforge.runner_path', $root.'/runner');
    $run = BenchmarkRun::factory()->create(['slug' => 'original-session-test']);
    $modelRun = ModelRun::factory()->for($run)->create([
        'slug' => 'model-a',
        'model' => 'openrouter/vendor/model-a',
        'status' => 'fail',
    ]);
    ModelRunAttempt::factory()->for($modelRun)->create([
        'status' => 'failed',
        'session_id' => 'ses_wrong_workspace',
        'result' => null,
        'usage' => [
            'source' => 'validation_only',
            'sessionId' => 'ses_unverified',
        ],
    ]);
    $locator = mock(OpenCodeSessionLocator::class);
    $locator->shouldReceive('locate')->once()->with($workspace, 'ses_wrong_workspace')->andReturnNull()->ordered();
    $locator->shouldReceive('locate')->once()->with($workspace, 'ses_initial')->andReturn('ses_initial')->ordered();

    try {
        $availability = app(ModelRunContinuationRunner::class)->availability($run, $modelRun);

        expect($availability['canContinue'])->toBeTrue()
            ->and($availability['sessionId'])->toBe('ses_initial');
    } finally {
        File::deleteDirectory($root);
    }
});

test('it keeps a stopped attempt stopped even when a result file was written', function () {
    $root = storage_path('framework/testing/continuation-'.Str::uuid());
    $runPath = $root.'/runs/stopped-attempt-test';
    $attemptPath = $runPath.'/_attempts/model-a/attempt-1';
    File::ensureDirectoryExists($runPath.'/model-a');
    File::ensureDirectoryExists($attemptPath);
    File::put($runPath.'/_modeller.conf', "model-a|openrouter/vendor/model-a|49153\n");
    File::put($attemptPath.'/result.txt', 'validate|-||skip|0|0|0|0|0|12|openrouter/vendor/model-a');
    File::put($attemptPath.'/state.json', json_encode([
        'version' => 1,
        'status' => 'stopped',
        'runnerPid' => 999999,
        'finishedAt' => now()->toIso8601String(),
    ], JSON_THROW_ON_ERROR));
    config()->set('benchforge.root_path', $root);
    config()->set('benchforge.runs_path', $root.'/runs');
    config()->set('benchforge.runner_path', $root.'/runner');
    $run = BenchmarkRun::factory()->create(['slug' => 'stopped-attempt-test']);
    $modelRun = ModelRun::factory()->for($run)->create([
        'slug' => 'model-a',
        'model' => 'openrouter/vendor/model-a',
        'status' => 'fail',
    ]);
    $attempt = ModelRunAttempt::factory()->for($modelRun)->create([
        'mode' => 'validate',
        'status' => 'running',
        'result' => null,
        'state_path' => $attemptPath.'/state.json',
        'log_path' => $attemptPath.'/runner.log',
        'attempt_path' => $attemptPath,
        'synced_at' => now(),
    ]);

    try {
        $runner = app(ModelRunContinuationRunner::class);
        $reconciled = $runner->reconcile($attempt);

        expect($reconciled->status)->toBe('stopped')
            ->and($reconciled->usage['source'])->toBe('validation_only')
            ->and($runner->availability($run, $modelRun)['canValidate'])->toBeTrue();
    } finally {
        File::deleteDirectory($root);
    }
});

test('it refuses an attempts symlink before writing outside the run directory', function () {
    $root = storage_path('framework/testing/continuation-'.Str::uuid());
    $outside = storage_path('framework/testing/continuation-outside-'.Str::uuid());
    $runPath = $root.'/runs/symlink-attempt-test';
    File::ensureDirectoryExists($runPath.'/model-a');
    File::ensureDirectoryExists($outside);
    File::put($runPath.'/_modeller.conf', "model-a|openrouter/vendor/model-a|49152\n");
    symlink($outside, $runPath.'/_attempts');
    config()->set('benchforge.root_path', $root);
    config()->set('benchforge.runs_path', $root.'/runs');
    config()->set('benchforge.runner_path', $root.'/runner');
    $run = BenchmarkRun::factory()->create([
        'slug' => 'symlink-attempt-test',
        'routes' => ['/'],
        'failed_count' => 1,
        'model_count' => 1,
    ]);
    $modelRun = ModelRun::factory()->for($run)->create([
        'slug' => 'model-a',
        'model' => 'openrouter/vendor/model-a',
        'status' => 'fail',
    ]);

    try {
        expect(fn () => app(ModelRunContinuationRunner::class)->start($run, $modelRun, 'validate'))
            ->toThrow(BenchmarkRunnerException::class, 'Kurtarma sonuç klasörü izin verilen koşu alanının dışında.')
            ->and(File::exists($outside.'/model-a'))->toBeFalse();
    } finally {
        @unlink($runPath.'/_attempts');
        File::deleteDirectory($root);
        File::deleteDirectory($outside);
    }
});
