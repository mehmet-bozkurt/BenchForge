<?php

use App\Models\BenchmarkRun;
use App\Models\ModelRun;
use App\Models\ModelRunAttempt;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

test('it creates and updates a manual evaluation for a model result', function () {
    $runsRoot = storage_path('framework/testing/evaluation-write-'.Str::uuid());
    config()->set('benchforge.runs_path', $runsRoot);
    $run = BenchmarkRun::factory()->create([
        'slug' => 'nova-manual-review',
        'routes' => ['/', '/cart'],
    ]);
    $modelRun = ModelRun::factory()->for($run)->create([
        'slug' => 'minimax-m3',
        'model' => 'opencode-go/minimax-m3',
    ]);
    File::ensureDirectoryExists($runsRoot.'/'.$run->slug.'/_gallery/'.$modelRun->slug);
    File::put($runsRoot.'/'.$run->slug.'/_gallery/'.$modelRun->slug.'/index.html', '<h1>Preview</h1>');
    $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($runsRoot));
    $payload = [
        'visual_design_score' => 8,
        'user_experience_score' => 7,
        'task_completion_score' => 9,
        'functionality_score' => 8,
        'responsive_quality_score' => 7,
        'content_quality_score' => 9,
        'consistency_score' => 8,
        'overall_score' => 8,
        'issue_severity' => 'minor',
        'notes' => 'Sepet akışı güçlü, mobil menü daha iyi olabilir.',
        'return_path' => '/cart',
    ];

    $response = $this->put(route('runs.models.evaluation.update', [$run, $modelRun]), $payload);

    $response
        ->assertRedirect(route('runs.models.preview', [
            'benchmarkRun' => $run,
            'modelRun' => $modelRun,
            'path' => '/cart',
            'review' => 1,
        ]))
        ->assertSessionHas('status', 'Manuel değerlendirme kaydedildi.');
    $this->assertDatabaseHas('model_evaluations', [
        'model_run_id' => $modelRun->id,
        'visual_design_score' => 8,
        'overall_score' => 8,
        'issue_severity' => 'minor',
        'notes' => 'Sepet akışı güçlü, mobil menü daha iyi olabilir.',
    ]);

    $this->put(route('runs.models.evaluation.update', [$run, $modelRun]), [
        ...$payload,
        'overall_score' => 9,
        'notes' => 'İkinci inceleme.',
    ])->assertRedirect();

    expect($modelRun->evaluation()->count())->toBe(1);
    $this->assertDatabaseHas('model_evaluations', [
        'model_run_id' => $modelRun->id,
        'overall_score' => 9,
        'notes' => 'İkinci inceleme.',
    ]);
});

test('it refuses to score a model whose first submission has no viewable export', function () {
    $runsRoot = storage_path('framework/testing/evaluation-recovered-'.Str::uuid());
    config()->set('benchforge.runs_path', $runsRoot);

    try {
        $run = BenchmarkRun::factory()->create(['slug' => 'recovered-scoring', 'routes' => ['/']]);
        $modelRun = ModelRun::factory()->for($run)->create(['slug' => 'kimi-k3', 'status' => 'fail']);
        ModelRunAttempt::factory()->for($modelRun)->create(['attempt_number' => 1]);
        File::ensureDirectoryExists($runsRoot.'/'.$run->slug.'/_gallery/kimi-k3');
        File::put($runsRoot.'/'.$run->slug.'/_gallery/kimi-k3/index.html', '<h1>Recovered</h1>');

        $this
            ->from(route('runs.models.preview', [$run, $modelRun]))
            ->put(route('runs.models.evaluation.update', [$run, $modelRun]), [
                'visual_design_score' => 9,
                'user_experience_score' => 9,
                'task_completion_score' => 9,
                'functionality_score' => 9,
                'responsive_quality_score' => 9,
                'content_quality_score' => 9,
                'consistency_score' => 9,
                'overall_score' => 9,
                'issue_severity' => 'none',
                'return_path' => '/',
            ])
            ->assertSessionHasErrors('evaluation');

        $this->assertDatabaseMissing('model_evaluations', ['model_run_id' => $modelRun->id]);
    } finally {
        File::deleteDirectory($runsRoot);
    }
});

test('it validates scores severity and the return route', function () {
    $run = BenchmarkRun::factory()->create(['routes' => ['/']]);
    $modelRun = ModelRun::factory()->for($run)->create();

    $response = $this
        ->from(route('runs.models.preview', [$run, $modelRun]))
        ->put(route('runs.models.evaluation.update', [$run, $modelRun]), [
            'visual_design_score' => 11,
            'user_experience_score' => 7,
            'task_completion_score' => 7,
            'functionality_score' => 7,
            'responsive_quality_score' => 7,
            'content_quality_score' => 7,
            'consistency_score' => 7,
            'overall_score' => 7,
            'issue_severity' => 'unknown',
            'return_path' => '/admin',
        ]);

    $response
        ->assertRedirect(route('runs.models.preview', [$run, $modelRun]))
        ->assertSessionHasErrors([
            'visual_design_score' => 'Görsel tasarım puanı 1 ile 10 arasında olmalıdır.',
            'issue_severity' => 'Geçerli bir hata seviyesi seçin.',
            'return_path' => 'Geçerli bir benchmark rotası seçin.',
        ]);
    $this->assertDatabaseMissing('model_evaluations', ['model_run_id' => $modelRun->id]);
});

test('it renders saved notes safely in the evaluation panel', function () {
    $runsRoot = storage_path('framework/testing/evaluation-runs-'.Str::uuid());
    config()->set('benchforge.runs_path', $runsRoot);

    try {
        $run = BenchmarkRun::factory()->create(['slug' => 'safe-review']);
        $modelRun = ModelRun::factory()->for($run)->create(['slug' => 'reviewed-model']);
        $previewPath = $runsRoot.'/'.$run->slug.'/_gallery/'.$modelRun->slug;
        File::ensureDirectoryExists($previewPath);
        File::put($previewPath.'/index.html', '<h1>Preview</h1>');
        $modelRun->evaluation()->create([
            'visual_design_score' => 8,
            'user_experience_score' => 8,
            'task_completion_score' => 8,
            'functionality_score' => 8,
            'responsive_quality_score' => 8,
            'content_quality_score' => 8,
            'consistency_score' => 8,
            'overall_score' => 8,
            'issue_severity' => 'none',
            'notes' => "Güçlü <script>alert('xss')</script>",
            'reviewed_at' => now(),
        ]);

        $response = $this->get(route('runs.models.preview', [
            'benchmarkRun' => $run,
            'modelRun' => $modelRun,
            'review' => 1,
        ]));

        $response
            ->assertOk()
            ->assertSee('data-evaluation-open="true"', escape: false)
            ->assertSee('&lt;script&gt;alert', escape: false)
            ->assertDontSee("<script>alert('xss')</script>", escape: false);
    } finally {
        File::deleteDirectory($runsRoot);
    }
});

test('it forbids non local evaluation writes and cross run model access', function () {
    $firstRun = BenchmarkRun::factory()->create();
    $secondRun = BenchmarkRun::factory()->create();
    $modelRun = ModelRun::factory()->for($secondRun)->create();

    $this
        ->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
        ->put(route('runs.models.evaluation.update', [$secondRun, $modelRun]), [])
        ->assertForbidden();

    $this->put(route('runs.models.evaluation.update', [$firstRun, $modelRun]), [])->assertNotFound();
});
