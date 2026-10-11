<?php

use App\Models\BenchmarkRun;
use App\Models\ModelRun;
use App\Models\StageResult;
use App\Services\BenchmarkHistory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

it('renders synchronized benchmark results on the dashboard', function () {
    $benchmarkRun = BenchmarkRun::factory()->create([
        'slug' => 'nova-marketplace-v1',
        'name' => 'Nova Marketplace V1',
        'stage_names' => ['opencode', 'lint', 'build'],
        'model_count' => 2,
        'passed_count' => 1,
        'failed_count' => 1,
        'total_duration_seconds' => 3195,
        'total_bytes' => 3_221_225_472,
        'workspace_bytes' => 3_000_000_000,
        'result_bytes' => 221_225_472,
        'workspace_count' => 2,
        'storage_scanned_at' => now(),
    ]);
    $failedModel = ModelRun::factory()->for($benchmarkRun)->create([
        'slug' => 'minimax-m3',
        'model' => 'opencode-go/minimax-m3',
        'status' => 'fail',
        'duration_seconds' => 859,
        'failed_stages' => ['runtime'],
    ]);
    ModelRun::factory()->for($benchmarkRun)->create([
        'slug' => 'qwen3.8-flash',
        'model' => 'opencode-go/qwen3.8-flash',
        'status' => 'pass',
        'duration_seconds' => 2336,
    ]);
    StageResult::factory()->for($failedModel)->create([
        'stage' => 'runtime',
        'status' => 'fail',
        'exit_code' => 1,
    ]);

    $response = $this->get(route('dashboard'));

    $response
        ->assertOk()
        ->assertSee('Nova Marketplace V1')
        ->assertSee('minimax-m3')
        ->assertSee('qwen3.8-flash')
        ->assertSee('1 / 2 geçti')
        ->assertSee('Eski koşul')
        ->assertSee('Disk kullanımı')
        ->assertSee('3.00 GB')
        ->assertSee('53 dk 15 sn')
        ->assertSee('Lint: Veri yok')
        ->assertDontSee('stage-abbreviation', escape: false);
});

it('shows the task summary, condition and recorded cost on a run card', function () {
    $runPath = storage_path('framework/testing/dashboard-card-'.Str::uuid());
    File::ensureDirectoryExists($runPath);
    File::put($runPath.'/_prompt.txt', "You are participating in a one-shot benchmark.\n\nYour task is to transform the existing Next.js project into a complete booking app named:\n\nHarbor Stay\n\n---\nBenchForge teslim sözleşmesi:\n- npm run lint\n");

    try {
        $run = BenchmarkRun::factory()->isolated()->create([
            'name' => 'Harbor Stay V1',
            'source_path' => $runPath,
            'stage_names' => ['opencode', 'lint'],
            'routes' => ['/', '/rooms'],
            'model_count' => 1,
            'passed_count' => 1,
        ]);
        ModelRun::factory()->for($run)->create([
            'model' => 'opencode-go/glm-5.3-flash',
            'status' => 'pass',
            'usage' => ['costUsd' => '0.6803241700'],
        ]);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Yalıtılmış koşul v1')
            ->assertSee('Complete booking app named: Harbor Stay')
            ->assertDontSee('teslim sözleşmesi')
            ->assertSee('Rotalar: /, /rooms')
            ->assertSee('$0,68')
            ->assertSee('1 / 1 geçti');
    } finally {
        File::deleteDirectory($runPath);
    }
});

it('summarises Turkish prompts with a dotted capital İ', function () {
    expect(app(BenchmarkHistory::class)->summarizePrompt('Bu klasördeki Next.js başlangıç şablonunun üzerinde, iki sayfalık bir site yap. Ek paket kurma.'))
        ->toBe('İki sayfalık bir site yap.');
});

it('renders a helpful empty state when no history has been synchronized', function () {
    $response = $this->get(route('dashboard'));

    $response
        ->assertOk()
        ->assertSee('Henüz benchmark bulunamadı');
});

it('marks provider failures and missing submissions in the run matrix', function () {
    $run = BenchmarkRun::factory()->isolated()->create(['stage_names' => ['opencode', 'lint'], 'model_count' => 2, 'failed_count' => 2]);
    $flaky = ModelRun::factory()->for($run)->create(['model' => 'opencode-go/flaky', 'status' => 'fail']);
    StageResult::factory()->for($flaky)->create(['stage' => 'opencode', 'status' => 'fail', 'exit_code' => StageResult::PROVIDER_ERROR_CODE]);
    $idle = ModelRun::factory()->for($run)->create(['model' => 'opencode-go/idle', 'status' => 'fail']);
    StageResult::factory()->for($idle)->create(['stage' => 'opencode', 'status' => 'fail', 'exit_code' => StageResult::NO_SUBMISSION_CODE]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Üretim: Sağlayıcı hatası, puanlanmaz')
        ->assertSee('stage-cell-provider', escape: false)
        ->assertSee('Üretim: Teslim yok');
});
