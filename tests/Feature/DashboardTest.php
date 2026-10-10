<?php

use App\Models\BenchmarkRun;
use App\Models\ModelRun;
use App\Models\StageResult;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

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
        ->assertSee('1 sorunlu')
        ->assertSee('Disk kullanımı')
        ->assertSee('3.00 GB')
        ->assertSee('2.79 GB')
        ->assertSee('210.98 MB')
        ->assertSee('53 dk 15 sn');
});

it('renders a helpful empty state when no history has been synchronized', function () {
    $response = $this->get(route('dashboard'));

    $response
        ->assertOk()
        ->assertSee('Henüz benchmark bulunamadı');
});
