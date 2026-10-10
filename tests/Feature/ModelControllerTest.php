<?php

use App\Models\BenchmarkRun;
use App\Models\ModelEvaluation;
use App\Models\ModelRun;
use App\Models\StageResult;
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
        ->assertSee(route('models.show', 'reviewed-model'), escape: false);
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
