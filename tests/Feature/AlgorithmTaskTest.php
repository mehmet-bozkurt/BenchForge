<?php

use App\Exceptions\BenchmarkRunnerException;
use App\Models\BenchmarkExecution;
use App\Models\BenchmarkRun;
use App\Models\ModelEvaluation;
use App\Models\ModelRun;
use App\Models\StageResult;
use App\Services\BenchmarkPreflight;
use App\Services\BenchmarkRunner;
use App\Services\ModelLeaderboard;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

use function Pest\Laravel\mock;

uses(LazilyRefreshDatabase::class);

/**
 * @param  array<string, mixed>  $metrics
 */
function algorithmModelRun(BenchmarkRun $run, string $model, array $opencode, ?array $metrics, string $testsStatus = 'fail'): ModelRun
{
    $modelRun = ModelRun::factory()->for($run)->create(['slug' => Str::slug($model), 'model' => $model]);
    StageResult::factory()->for($modelRun)->create(['stage' => 'opencode', 'status' => $opencode['status'], 'exit_code' => $opencode['code']]);
    StageResult::factory()->for($modelRun)->create(['stage' => 'tests', 'status' => $testsStatus, 'exit_code' => $testsStatus === 'pass' ? 0 : 1, 'metrics' => $metrics]);

    return $modelRun;
}

function algorithmRun(array $attributes = []): BenchmarkRun
{
    return BenchmarkRun::factory()->isolated()->create([
        'metadata' => ['environment_profile' => 'isolated-v1', 'task_type' => 'algorithm'],
        'stage_names' => ['opencode', 'tests'],
        ...$attributes,
    ]);
}

test('a run without a recorded task type is a site run', function () {
    expect(BenchmarkRun::factory()->isolated()->create()->taskType())->toBe('site')
        ->and(algorithmRun()->taskType())->toBe('algorithm')
        ->and(BenchmarkRun::factory()->create(['metadata' => ['task_type' => 'nonsense']])->taskType())->toBe('site');
});

test('it scores an algorithm task by the share of unit tests that passed and ranks without a review', function () {
    $run = algorithmRun();
    algorithmModelRun($run, 'openrouter/good-model', ['status' => 'pass', 'code' => 0], ['passed' => 9, 'total' => 10, 'tampered' => []]);
    algorithmModelRun($run, 'openrouter/weak-model', ['status' => 'pass', 'code' => 0], ['passed' => 2, 'total' => 10, 'tampered' => []]);
    algorithmModelRun($run, 'openrouter/failed-model', ['status' => 'fail', 'code' => 124], ['passed' => 10, 'total' => 10, 'tampered' => []], 'pass');
    algorithmModelRun($run, 'openrouter/provider-error-model', ['status' => 'fail', 'code' => 75], null);

    $models = app(ModelLeaderboard::class)->all('isolated-v1', 'algorithm');

    expect($models->pluck('provider_model')->all())->toBe([
        'openrouter/good-model',
        'openrouter/weak-model',
        'openrouter/failed-model',
        'openrouter/provider-error-model',
    ])
        ->and($models->pluck('rank')->all())->toBe([1, 2, 3, 4])
        ->and($models->firstWhere('provider_model', 'openrouter/good-model')['technical']['score'])->toBe(90.0)
        ->and($models->firstWhere('provider_model', 'openrouter/good-model')['composite_score'])->toBe(90.0)
        ->and($models->firstWhere('provider_model', 'openrouter/weak-model')['technical']['score'])->toBe(20.0)
        ->and($models->firstWhere('provider_model', 'openrouter/failed-model')['technical']['score'])->toBe(0.0)
        // A provider-side failure says nothing about the model, so the run is not measured.
        ->and($models->firstWhere('provider_model', 'openrouter/provider-error-model')['measured_run_count'])->toBe(0);
});

test('it never mixes site and algorithm runs of the same model', function () {
    $siteRun = BenchmarkRun::factory()->isolated()->create();
    $site = ModelRun::factory()->for($siteRun)->create(['slug' => 'shared-site', 'model' => 'openrouter/shared']);
    StageResult::factory()->for($site)->create(['stage' => 'opencode', 'status' => 'pass']);
    algorithmModelRun(algorithmRun(), 'openrouter/shared', ['status' => 'pass', 'code' => 0], ['passed' => 5, 'total' => 10, 'tampered' => []]);

    $leaderboard = app(ModelLeaderboard::class);

    expect($leaderboard->all('isolated-v1', 'site')->firstWhere('provider_model', 'openrouter/shared')['run_count'])->toBe(1)
        ->and($leaderboard->all('isolated-v1', 'site')->firstWhere('provider_model', 'openrouter/shared')['technical']['score'])->toBe(100.0)
        ->and($leaderboard->all('isolated-v1', 'algorithm')->firstWhere('provider_model', 'openrouter/shared')['technical']['score'])->toBe(50.0)
        ->and($leaderboard->find('openrouter~shared', 'isolated-v1', 'algorithm')['run_count'])->toBe(1);
});

test('the leaderboard page switches between site and algorithm tasks', function () {
    algorithmModelRun(algorithmRun(), 'openrouter/algo-model', ['status' => 'pass', 'code' => 0], ['passed' => 3, 'total' => 4, 'tampered' => []]);

    $this->get(route('models.index', ['type' => 'algorithm']))
        ->assertOk()
        ->assertSee('Test başarısı')
        ->assertSee('algo-model')
        ->assertSee('75,0')
        ->assertDontSee('Değerlendirme bekliyor');

    $this->get(route('models.index'))
        ->assertOk()
        ->assertDontSee('algo-model');

    $this->get(route('models.show', ['openrouter~algo-model', 'type' => 'algorithm']))
        ->assertOk()
        ->assertSee('Birim testler')
        ->assertDontSee('Manuel puanlar');

    $this->get(route('models.index', ['type' => 'unknown']))->assertOk()->assertSee('Bu koşulda henüz model sonucu yok');
});

test('it does not accept a manual evaluation for an algorithm task', function () {
    $run = algorithmRun();
    $modelRun = algorithmModelRun($run, 'openrouter/algo-model', ['status' => 'pass', 'code' => 0], ['passed' => 1, 'total' => 1, 'tampered' => []], 'pass');

    $this->from(route('runs.show', $run))
        ->put(route('runs.models.evaluation.update', [$run, $modelRun]), ModelEvaluation::factory()->make()->only([
            'visual_design_score', 'user_experience_score', 'task_completion_score', 'functionality_score', 'responsive_quality_score', 'content_quality_score', 'consistency_score', 'overall_score', 'issue_severity',
        ]))
        ->assertSessionHasErrors('evaluation');

    expect(ModelEvaluation::count())->toBe(0);
});

test('it lists only well formed imported algorithm tasks', function () {
    $root = storage_path('framework/testing/algorithm-root-'.Str::uuid());

    foreach (['prompts', 'config', 'runs'] as $directory) {
        File::ensureDirectoryExists($root.'/'.$directory);
    }

    $write = function (string $slug, array $task, bool $complete = true) use ($root): void {
        File::ensureDirectoryExists($root.'/algorithm-tasks/'.$slug.'/workspace');
        File::put($root.'/algorithm-tasks/'.$slug.'/task.json', json_encode($task));

        if ($complete) {
            File::put($root.'/algorithm-tasks/'.$slug.'/prompt.txt', 'Solve it.');
        }
    };
    $write('two-fer', ['slug' => 'two-fer', 'title' => 'Two fer', 'solutionFile' => 'two-fer.js', 'testFile' => 'two-fer.spec.js', 'testCount' => 3]);
    $write('no-prompt', ['slug' => 'no-prompt', 'solutionFile' => 'a.js', 'testFile' => 'a.spec.js'], complete: false);
    $write('mismatch', ['slug' => 'another-slug', 'solutionFile' => 'a.js', 'testFile' => 'a.spec.js']);
    $write('Bad_Name', ['slug' => 'Bad_Name', 'solutionFile' => 'a.js', 'testFile' => 'a.spec.js']);
    config()->set('benchforge.root_path', $root);
    config()->set('benchforge.runs_path', $root.'/runs');
    config()->set('benchforge.runner_path', $root.'/runner');

    try {
        $tasks = app(BenchmarkRunner::class)->algorithmTasks();

        expect($tasks)->toHaveCount(1)
            ->and($tasks[0]['value'])->toBe('two-fer')
            ->and($tasks[0]['label'])->toBe('Two fer')
            ->and($tasks[0]['testCount'])->toBe(3)
            ->and(app(BenchmarkRunner::class)->options()['algorithmTasks'])->toHaveCount(1);
    } finally {
        File::deleteDirectory($root);
    }
});

test('it refuses to start an algorithm benchmark for a task that was not imported', function () {
    $root = storage_path('framework/testing/algorithm-root-'.Str::uuid());

    foreach (['prompts', 'config', 'runs'] as $directory) {
        File::ensureDirectoryExists($root.'/'.$directory);
    }

    config()->set('benchforge.root_path', $root);
    config()->set('benchforge.runs_path', $root.'/runs');
    config()->set('benchforge.runner_path', $root.'/runner');

    try {
        expect(fn () => app(BenchmarkRunner::class)->start([
            'run_id' => 'algo-run',
            'task_type' => 'algorithm',
            'algorithm_task' => 'missing-task',
            'model_mode' => 'group',
            'config' => 'config/models.conf',
            'max_parallel' => 1,
        ]))->toThrow(BenchmarkRunnerException::class, 'geçerli bir algoritma görevi');
    } finally {
        File::deleteDirectory($root);
    }
});

test('it starts an algorithm benchmark without routes and passes the task type on', function () {
    $execution = BenchmarkExecution::factory()->make(['run_id' => 'algo-run']);
    $runner = mock(BenchmarkRunner::class);
    $runner->shouldReceive('options')->andReturn([
        'prompts' => [],
        'configs' => [['value' => 'config/models.conf', 'label' => 'Models', 'modelCount' => 2, 'ports' => [3101, 3102]]],
        'algorithmTasks' => [['value' => 'two-fer', 'label' => 'Two fer', 'testCount' => 3, 'directory' => '/tmp/two-fer']],
        'suggestedRoutes' => ['/'],
    ]);
    $runner->shouldReceive('start')
        ->once()
        ->with([
            'run_id' => 'algo-run',
            'task_type' => 'algorithm',
            'algorithm_task' => 'two-fer',
            'prompt_mode' => 'saved',
            'prompt' => '',
            'custom_prompt_title' => '',
            'custom_prompt_content' => '',
            'save_prompt' => false,
            'model_mode' => 'group',
            'config' => 'config/models.conf',
            'models' => [],
            'go_models' => [],
            'max_parallel' => 2,
            'routes' => [],
        ])
        ->andReturn($execution);

    $this->post(route('runner.store'), [
        'run_id' => 'algo-run',
        'task_type' => 'algorithm',
        'algorithm_task' => 'two-fer',
        'model_mode' => 'group',
        'config' => 'config/models.conf',
        'max_parallel' => 2,
    ])->assertRedirect(route('runner.create'));
});

test('it rejects an algorithm benchmark without a task and still requires routes for a site benchmark', function () {
    $runner = mock(BenchmarkRunner::class);
    $runner->shouldReceive('options')->andReturn([
        'prompts' => [['value' => 'prompts/nova.txt', 'label' => 'Nova']],
        'configs' => [['value' => 'config/models.conf', 'label' => 'Models', 'modelCount' => 2, 'ports' => [3101, 3102]]],
        'algorithmTasks' => [['value' => 'two-fer', 'label' => 'Two fer', 'testCount' => 3, 'directory' => '/tmp/two-fer']],
        'suggestedRoutes' => ['/'],
    ]);
    $runner->shouldNotReceive('start');

    $this->post(route('runner.store'), ['run_id' => 'algo-run', 'task_type' => 'algorithm', 'model_mode' => 'group', 'config' => 'config/models.conf', 'max_parallel' => 2])
        ->assertSessionHasErrors('algorithm_task');
    $this->post(route('runner.store'), ['run_id' => 'algo-run', 'task_type' => 'algorithm', 'algorithm_task' => 'unknown', 'model_mode' => 'group', 'config' => 'config/models.conf', 'max_parallel' => 2])
        ->assertSessionHasErrors('algorithm_task');
    $this->post(route('runner.store'), ['run_id' => 'site-run', 'task_type' => 'site', 'prompt_mode' => 'saved', 'prompt' => 'prompts/nova.txt', 'model_mode' => 'group', 'config' => 'config/models.conf', 'max_parallel' => 2])
        ->assertSessionHasErrors('routes');
    $this->post(route('runner.store'), ['run_id' => 'odd-run', 'task_type' => 'unknown', 'model_mode' => 'group', 'config' => 'config/models.conf', 'max_parallel' => 2])
        ->assertSessionHasErrors('task_type');
});

test('an algorithm preflight does not check model ports or site tooling', function () {
    $preflight = app(BenchmarkPreflight::class);
    $site = $preflight->inspect([], []);
    $algorithm = $preflight->inspect([], [], 'algorithm');

    expect(collect($site['checks'])->pluck('key')->all())->toContain('ports')
        ->and(collect($algorithm['checks'])->pluck('key')->all())->not->toContain('ports')
        ->and(collect($algorithm['checks'])->firstWhere('key', 'commands')['message'])->toBe('rsync ve shasum hazır.');
});

test('it syncs an algorithm run with its unit test counts', function () {
    $runsPath = storage_path('framework/testing/algorithm-sync-'.Str::uuid());
    $runPath = $runsPath.'/algo-run';
    File::ensureDirectoryExists($runPath.'/_results');
    File::ensureDirectoryExists($runPath.'/_logs');
    File::put($runPath.'/_meta.txt', "test=algo-run\ndate=2026-11-11 10:00:00\ntask_type=algorithm\nalgorithm_task=two-fer\nenvironment_profile=isolated-v1\nroutes=\n");
    File::put($runPath.'/_summary.tsv', "klasor|model|opencode|tests|sure_sn\nmodel-a|openrouter/model-a|0|1|42\nmodel-b|openrouter/model-b|0|0|30\n");
    File::put($runPath.'/_results/model-a.tests.json', json_encode(['passed' => 7, 'total' => 10, 'tampered' => ['two-fer.spec.js']]));
    File::put($runPath.'/_results/model-b.tests.json', json_encode(['passed' => 10, 'total' => 10, 'tampered' => []]));
    File::put($runPath.'/_logs/model-a.tests.log', 'BASARISIZ: x');
    config()->set('benchforge.runs_path', $runsPath);

    try {
        $this->artisan('benchforge:sync')->assertSuccessful();

        $benchmarkRun = BenchmarkRun::query()->where('slug', 'algo-run')->firstOrFail();
        $tests = StageResult::query()->where('stage', 'tests')->get()->keyBy(fn (StageResult $stage): string => $stage->modelRun->slug);

        expect($benchmarkRun->taskType())->toBe('algorithm')
            ->and($benchmarkRun->schema)->toBe('algorithm')
            ->and($benchmarkRun->stage_names)->toBe(['opencode', 'tests'])
            ->and($tests['model-a']->status)->toBe('fail')
            ->and($tests['model-a']->metrics)->toBe(['passed' => 7, 'total' => 10, 'tampered' => ['two-fer.spec.js']])
            ->and($tests['model-a']->message)->toBe('7/10 test geçti.')
            ->and($tests['model-a']->testPassRate())->toBe(70.0)
            ->and($tests['model-b']->status)->toBe('pass')
            ->and($tests['model-b']->testPassRate())->toBe(100.0)
            ->and(StageResult::query()->where('stage', 'opencode')->first()->testPassRate())->toBeNull();
    } finally {
        File::deleteDirectory($runsPath);
    }
});
