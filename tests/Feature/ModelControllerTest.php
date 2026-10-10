<?php

use App\Models\BenchmarkRun;
use App\Models\ModelEvaluation;
use App\Models\ModelRun;
use App\Models\StageResult;
use App\Services\ModelLeaderboard;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

test('it ranks reviewed models using technical and manual scores', function () {
    $run = BenchmarkRun::factory()->create(['name' => 'Marketplace V1']);
    $reviewed = ModelRun::factory()->for($run)->create([
        'slug' => 'reviewed-model',
        'model' => 'opencode-go/reviewed-model',
        'duration_seconds' => 120,
    ]);
    $unreviewed = ModelRun::factory()->for($run)->create([
        'slug' => 'unreviewed-model',
        'model' => 'opencode-go/unreviewed-model',
        'duration_seconds' => 60,
    ]);

    foreach (['opencode', 'lint', 'build'] as $stage) {
        StageResult::factory()->for($reviewed)->create(['stage' => $stage, 'status' => 'pass']);
    }
    StageResult::factory()->for($reviewed)->create(['stage' => 'runtime', 'status' => 'fail']);

    foreach (['opencode', 'lint', 'build', 'runtime'] as $stage) {
        StageResult::factory()->for($unreviewed)->create(['stage' => $stage, 'status' => 'pass']);
    }

    ModelEvaluation::factory()->for($reviewed)->create([
        'overall_score' => 8,
        'notes' => 'Dengeli bir sonuç.',
    ]);

    $this->get(route('models.index'))
        ->assertOk()
        ->assertSee('Liderlik Tablosu')
        ->assertSeeInOrder(['reviewed-model', 'unreviewed-model'])
        ->assertSee('77,0')
        ->assertSee('Değerlendirme bekliyor')
        ->assertSee(route('models.show', 'opencode-go~reviewed-model'), escape: false);
});

test('it groups runs by model identity instead of workspace folder', function () {
    $firstRun = BenchmarkRun::factory()->create();
    $secondRun = BenchmarkRun::factory()->create();
    ModelRun::factory()->for($firstRun)->create([
        'slug' => 'deepseek-v4.1-flash',
        'model' => 'opencode-go/deepseek-v4.1-flash',
    ])->stageResults()->create(['stage' => 'opencode', 'status' => 'pass']);
    ModelRun::factory()->for($secondRun)->create([
        'slug' => 'go-deepseek-v41-flash-9ab9da',
        'model' => 'opencode-go/deepseek-v4.1-flash',
    ])->stageResults()->create(['stage' => 'opencode', 'status' => 'pass']);

    $models = app(ModelLeaderboard::class)->all();

    expect($models)->toHaveCount(1)
        ->and($models->first()['slug'])->toBe('opencode-go~deepseek-v4.1-flash')
        ->and($models->first()['run_count'])->toBe(2);

    $this->get(route('models.show', 'opencode-go~deepseek-v4.1-flash'))
        ->assertOk()
        ->assertSee('opencode-go/deepseek-v4.1-flash');
    $this->get(route('models.show', 'go-deepseek-v41-flash-9ab9da'))
        ->assertOk()
        ->assertSee('opencode-go/deepseek-v4.1-flash');
});

test('it averages technical scores per run and gives no credit when the model step failed', function () {
    $legacyRun = BenchmarkRun::factory()->create();
    $extendedRun = BenchmarkRun::factory()->create();
    $model = 'opencode-go/minimax-m3';

    $legacy = ModelRun::factory()->for($legacyRun)->create(['slug' => 'minimax-m3', 'model' => $model]);
    foreach (['opencode', 'lint', 'build'] as $stage) {
        StageResult::factory()->for($legacy)->create(['stage' => $stage, 'status' => 'pass']);
    }

    $extended = ModelRun::factory()->for($extendedRun)->create(['slug' => 'or-minimax-m3', 'model' => $model]);
    StageResult::factory()->for($extended)->create(['stage' => 'opencode', 'status' => 'fail']);
    foreach (['lint', 'build'] as $stage) {
        StageResult::factory()->for($extended)->create(['stage' => $stage, 'status' => 'pass']);
    }
    foreach (['export', 'routes', 'runtime'] as $stage) {
        StageResult::factory()->for($extended)->create(['stage' => $stage, 'status' => 'skip']);
    }

    ModelRun::factory()->for(BenchmarkRun::factory())->create([
        'slug' => 'minimax-m3',
        'model' => $model,
        'duration_seconds' => 0,
    ]);

    $summary = app(ModelLeaderboard::class)->find('opencode-go~minimax-m3');

    expect($summary['technical']['score'])->toBe(50.0)
        ->and($summary['run_count'])->toBe(3)
        ->and($summary['measured_run_count'])->toBe(2)
        ->and($summary['mixed_stage_sets'])->toBeTrue();

    $this->get(route('models.index'))
        ->assertOk()
        ->assertSee('Farklı aşama setleri')
        ->assertSee('2 koşu ortalaması');
});

test('it shows a model scorecard with run history and safely escaped notes', function () {
    $run = BenchmarkRun::factory()->create([
        'slug' => 'scorecard-run',
        'name' => 'Scorecard Run',
        'started_at' => now(),
    ]);
    $modelRun = ModelRun::factory()->for($run)->create([
        'slug' => 'scorecard-model',
        'model' => 'opencode-go/scorecard-model',
        'status' => 'fail',
        'duration_seconds' => 90,
    ]);
    StageResult::factory()->for($modelRun)->create(['stage' => 'build', 'status' => 'pass']);
    StageResult::factory()->for($modelRun)->create(['stage' => 'runtime', 'status' => 'fail']);
    ModelEvaluation::factory()->for($modelRun)->create([
        'visual_design_score' => 9,
        'overall_score' => 7,
        'issue_severity' => 'major',
        'notes' => '<script>alert("xss")</script> Güçlü tasarım.',
    ]);

    $this->get(route('models.show', 'scorecard-model'))
        ->assertOk()
        ->assertSee('Model karnesi')
        ->assertSee('Görsel tasarım')
        ->assertSee('9,0/10')
        ->assertSee('Scorecard Run')
        ->assertSee('Önemli')
        ->assertSee('&lt;script&gt;alert', escape: false)
        ->assertDontSee('<script>alert', escape: false)
        ->assertSee(route('runs.show', $run), escape: false);
});

test('it returns not found for an unknown model', function () {
    $this->get(route('models.show', 'missing-model'))->assertNotFound();
});
