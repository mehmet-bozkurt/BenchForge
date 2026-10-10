<?php

use App\Models\BenchmarkRun;
use App\Models\ModelRun;
use App\Models\ModelRunAttempt;
use App\Models\StageResult;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

test('it shows available model exports in the run report and comparison workspace', function () {
    $runsRoot = storage_path('framework/testing/preview-runs-'.Str::uuid());
    config()->set('benchforge.runs_path', $runsRoot);

    try {
        $run = BenchmarkRun::factory()->create([
            'slug' => 'nova-marketplace-v1',
            'name' => 'Nova Marketplace V1',
            'routes' => ['/', '/cart'],
            'model_count' => 3,
        ]);
        $leftModel = ModelRun::factory()->for($run)->create([
            'slug' => 'minimax-m3',
            'model' => 'opencode-go/minimax-m3',
        ]);
        $rightModel = ModelRun::factory()->for($run)->create([
            'slug' => 'longcat-2.0',
            'model' => 'opencode-go/longcat-2.0',
            'status' => 'fail',
        ]);
        ModelRunAttempt::factory()->for($rightModel)->create([
            'result' => [
                'overallStatus' => 'pass',
                'stages' => ['build' => ['status' => 'pass']],
            ],
        ]);
        ModelRun::factory()->for($run)->create([
            'slug' => 'no-static-export',
            'model' => 'opencode-go/no-static-export',
        ]);

        File::ensureDirectoryExists($runsRoot.'/'.$run->slug.'/_gallery/'.$leftModel->slug);
        File::put($runsRoot.'/'.$run->slug.'/_gallery/'.$leftModel->slug.'/index.html', '<h1>MiniMax</h1>');
        File::ensureDirectoryExists($runsRoot.'/'.$run->slug.'/'.$rightModel->slug.'/out');
        File::put($runsRoot.'/'.$run->slug.'/'.$rightModel->slug.'/out/index.html', '<h1>LongCat</h1>');

        $this->get(route('runs.show', $run))
            ->assertOk()
            ->assertSee('Siteleri karşılaştır')
            ->assertSee(route('runs.compare', ['benchmarkRun' => $run, 'left' => $leftModel->id]), escape: false)
            ->assertSee(route('runs.models.preview', [$run, $leftModel]), escape: false)
            ->assertSee('Çıktı yok');

        $this->get(route('runs.compare', [
            'benchmarkRun' => $run,
            'left' => $leftModel->id,
            'right' => $rightModel->id,
            'path' => '/cart',
            'viewport' => 'mobile',
        ]))
            ->assertOk()
            ->assertSee('Modelleri aynı çerçevede inceleyin')
            ->assertSee('minimax-m3')
            ->assertSee('longcat-2.0')
            ->assertSee('İlk teslim çıktısı · puanlanan sürüm')
            ->assertSee('Kurtarılmış çıktıyı incele (puanlanmaz)')
            ->assertSee(route('runs.models.preview', [
                'benchmarkRun' => $run,
                'modelRun' => $rightModel,
                'path' => '/cart',
                'version' => 'recovered',
            ]))
            ->assertDontSee(route('runs.previews.show', [$run, $rightModel], absolute: false).'/cart', escape: false)
            ->assertSee('data-viewport-width="390"', escape: false)
            ->assertSee(route('runs.previews.show', [$run, $leftModel], absolute: false).'/cart', escape: false)
            ->assertSee('sandbox="allow-scripts"', escape: false)
            ->assertSee('Yeni sekmede aç ↗')
            ->assertSee('target="_blank"', escape: false)
            ->assertSee(route('runs.models.preview', [
                'benchmarkRun' => $run,
                'modelRun' => $leftModel,
                'path' => '/cart',
            ]), escape: false);

        $this->get(route('runs.models.preview', [
            'benchmarkRun' => $run,
            'modelRun' => $leftModel,
            'path' => '/cart',
        ]))
            ->assertOk()
            ->assertSee('data-standalone-preview', escape: false)
            ->assertSee('minimax-m3')
            ->assertSee('1 / 2')
            ->assertSee('Önceki model: longcat-2.0')
            ->assertSee('Sonraki model: longcat-2.0')
            ->assertSee(route('runs.models.preview', [
                'benchmarkRun' => $run,
                'modelRun' => $rightModel,
                'path' => '/cart',
            ]), escape: false)
            ->assertSee(route('runs.previews.show', [$run, $leftModel], absolute: false).'/cart', escape: false)
            ->assertSee('sandbox="allow-scripts"', escape: false);
    } finally {
        File::deleteDirectory($runsRoot);
    }
});

test('a recovered export is shown for review only and the first submission stays the scored preview', function () {
    $runsRoot = storage_path('framework/testing/recovered-preview-'.Str::uuid());
    config()->set('benchforge.runs_path', $runsRoot);

    try {
        $run = BenchmarkRun::factory()->create(['slug' => 'recovery-run', 'routes' => ['/']]);
        $modelRun = ModelRun::factory()->for($run)->create(['slug' => 'glm-5.3', 'status' => 'fail']);
        ModelRunAttempt::factory()->for($modelRun)->create(['attempt_number' => 1]);
        $runPath = $runsRoot.'/'.$run->slug;

        File::ensureDirectoryExists($runPath.'/_attempts/glm-5.3/attempt-1/out-before');
        File::put($runPath.'/_attempts/glm-5.3/attempt-1/out-before/index.html', '<h1>First submission</h1>');
        File::ensureDirectoryExists($runPath.'/_gallery/glm-5.3');
        File::put($runPath.'/_gallery/glm-5.3/index.html', '<h1>Recovered export</h1>');

        $this->get(route('runs.previews.show', [$run, $modelRun], absolute: false))
            ->assertOk()
            ->assertSee('First submission')
            ->assertDontSee('Recovered export');
        $this->get(route('runs.previews.recovered', [$run, $modelRun], absolute: false))
            ->assertOk()
            ->assertHeader('Content-Security-Policy', 'sandbox allow-scripts')
            ->assertSee('Recovered export');

        $this->get(route('runs.models.preview', [$run, $modelRun]))
            ->assertOk()
            ->assertSee('İlk teslim · puanlanan')
            ->assertSee(route('runs.previews.show', [$run, $modelRun], absolute: false).'/', escape: false)
            ->assertSee('data-evaluation-panel', escape: false);

        $this->get(route('runs.models.preview', [$run, $modelRun, 'version' => 'recovered']))
            ->assertOk()
            ->assertSee('Deneme 1 ile kurtarılmış çıktıdır')
            ->assertSee(route('runs.previews.recovered', [$run, $modelRun], absolute: false).'/', escape: false)
            ->assertDontSee('data-evaluation-panel', escape: false);
    } finally {
        File::deleteDirectory($runsRoot);
    }
});

test('a model without a first submission export can only be reviewed and not scored', function () {
    $runsRoot = storage_path('framework/testing/recovered-only-'.Str::uuid());
    config()->set('benchforge.runs_path', $runsRoot);

    try {
        $run = BenchmarkRun::factory()->create(['slug' => 'recovered-only', 'routes' => ['/']]);
        $modelRun = ModelRun::factory()->for($run)->create(['slug' => 'kimi-k3', 'status' => 'fail']);
        ModelRunAttempt::factory()->for($modelRun)->create(['attempt_number' => 1]);

        File::ensureDirectoryExists($runsRoot.'/'.$run->slug.'/_gallery/kimi-k3');
        File::put($runsRoot.'/'.$run->slug.'/_gallery/kimi-k3/index.html', '<h1>Recovered export</h1>');

        $this->get(route('runs.previews.show', [$run, $modelRun], absolute: false))->assertNotFound();

        $this->get(route('runs.models.preview', [$run, $modelRun, 'review' => 1]))
            ->assertOk()
            ->assertSee('bu model için puanlama kapalıdır')
            ->assertSee(route('runs.previews.recovered', [$run, $modelRun], absolute: false).'/', escape: false)
            ->assertDontSee('data-evaluation-panel', escape: false);
    } finally {
        File::deleteDirectory($runsRoot);
    }
});

test('a model preview cannot be opened through another benchmark run', function () {
    $firstRun = BenchmarkRun::factory()->create();
    $secondRun = BenchmarkRun::factory()->create();
    $modelRun = ModelRun::factory()->for($secondRun)->create();

    $this->get(route('runs.previews.show', [$firstRun, $modelRun]))->assertNotFound();
    $this->get(route('runs.models.preview', [$firstRun, $modelRun]))->assertNotFound();
});

test('it compares the first result with the latest finished recovery even without a preview', function () {
    $run = BenchmarkRun::factory()->create([
        'slug' => 'incident-ops-v1',
        'stage_names' => ['opencode', 'lint', 'build', 'export', 'routes', 'runtime'],
    ]);
    $modelRun = ModelRun::factory()->for($run)->create([
        'slug' => 'failed-export',
        'status' => 'fail',
    ]);
    StageResult::factory()->for($modelRun)->create(['stage' => 'opencode', 'status' => 'pass']);
    StageResult::factory()->for($modelRun)->create(['stage' => 'build', 'status' => 'fail']);
    StageResult::factory()->for($modelRun)->create(['stage' => 'export', 'status' => 'skip']);
    ModelRunAttempt::factory()->for($modelRun)->create([
        'attempt_number' => 1,
        'mode' => 'validate',
        'result' => [
            'overallStatus' => 'pass',
            'stages' => [
                'opencode' => ['status' => 'skip'],
                'lint' => ['status' => 'pass'],
                'build' => ['status' => 'pass'],
                'export' => ['status' => 'pass'],
                'routes' => ['status' => 'pass'],
                'runtime' => ['status' => 'pass'],
            ],
        ],
    ]);

    $this->get(route('runs.compare', [
        'benchmarkRun' => $run,
        'left' => $modelRun->id,
        'right' => 0,
    ]))
        ->assertOk()
        ->assertSee('İlk teslim ve son tamamlanan deneme')
        ->assertSee('Görüntülenebilir çıktı yok')
        ->assertSee('Yalnız doğrulama')
        ->assertSee('data-stage-change="improved"', escape: false)
        ->assertSee('data-stage-change="not_run"', escape: false)
        ->assertSee('Düzeldi')
        ->assertSee('Bu denemede çalışmadı')
        ->assertDontSee('data-preview-frame', escape: false);

    expect($modelRun->fresh()->status)->toBe('fail');
});

test('it compares with the latest completed attempt when a newer attempt has no result', function () {
    $run = BenchmarkRun::factory()->create();
    $modelRun = ModelRun::factory()->for($run)->create(['status' => 'fail']);
    StageResult::factory()->for($modelRun)->create(['stage' => 'build', 'status' => 'fail']);
    ModelRunAttempt::factory()->for($modelRun)->create([
        'attempt_number' => 1,
        'result' => [
            'overallStatus' => 'fail',
            'stages' => ['build' => ['status' => 'fail']],
        ],
    ]);
    ModelRunAttempt::factory()->for($modelRun)->create([
        'attempt_number' => 2,
        'result' => [
            'overallStatus' => 'pass',
            'stages' => ['build' => ['status' => 'pass']],
        ],
    ]);
    ModelRunAttempt::factory()->for($modelRun)->create([
        'attempt_number' => 3,
        'status' => 'failed',
        'result' => null,
    ]);

    $this->get(route('runs.compare', ['benchmarkRun' => $run, 'left' => $modelRun->id, 'right' => 0]))
        ->assertOk()
        ->assertSee('Deneme 2')
        ->assertSee('data-stage-change="improved"', escape: false)
        ->assertDontSee('Deneme 3');
});

test('it selects a second model by default when no previews exist', function () {
    $run = BenchmarkRun::factory()->create();
    ModelRun::factory()->for($run)->create(['slug' => 'first-model', 'model' => 'openrouter/demo/first-model']);
    ModelRun::factory()->for($run)->create(['slug' => 'second-model', 'model' => 'openrouter/demo/second-model']);

    $response = $this->get(route('runs.compare', $run));

    $response
        ->assertOk()
        ->assertSee('data-preview-comparison', escape: false)
        ->assertSee('first-model')
        ->assertSee('second-model');

    expect(substr_count($response->getContent(), 'Görüntülenebilir çıktı yok'))->toBe(2);
});
