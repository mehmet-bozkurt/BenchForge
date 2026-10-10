<?php

use App\Models\Artifact;
use App\Models\BenchmarkRun;
use App\Models\ModelRun;
use App\Models\StageResult;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

it('synchronizes benchmark history without duplicating unchanged runs', function () {
    $runsPath = storage_path('framework/testing/sync-runs-'.Str::uuid());
    $runPath = $runsPath.'/nova-marketplace-v1';
    File::ensureDirectoryExists($runPath.'/_logs');
    File::ensureDirectoryExists($runPath.'/minimax-m3');
    File::put($runPath.'/_meta.txt', <<<'META'
test=nova-marketplace-v1
tarih=2026-09-18 00:08:26
routes=/,/search
META);
    File::put($runPath.'/_summary.tsv', <<<'SUMMARY'
klasor|model|opencode|lint|build|export|routes|runtime|sure_sn
minimax-m3|opencode-go/minimax-m3|0|0|0|0|0|1|859
qwen3.8-flash|opencode-go/qwen3.8-flash|0|0|0|0|0|0|2336
SUMMARY);
    File::put($runPath.'/_logs/minimax-m3.runtime.log', 'CONSOLE ERROR: invalid SVG height');
    config()->set('benchforge.runs_path', $runsPath);

    try {
        $this->artisan('benchforge:sync')->assertSuccessful();

        expect(BenchmarkRun::count())->toBe(1)
            ->and(ModelRun::count())->toBe(2)
            ->and(StageResult::count())->toBe(12)
            ->and(Artifact::count())->toBe(5);
        $this->assertDatabaseHas('benchmark_runs', [
            'slug' => 'nova-marketplace-v1',
            'model_count' => 2,
            'passed_count' => 1,
            'failed_count' => 1,
            'workspace_count' => 1,
            'workspace_bytes' => 0,
        ]);
        $this->assertDatabaseHas('stage_results', [
            'stage' => 'runtime',
            'status' => 'fail',
            'exit_code' => 1,
        ]);
        $this->assertDatabaseHas('artifacts', [
            'artifact_key' => 'model:minimax-m3:log:runtime',
            'type' => 'stage_log',
        ]);

        $this->artisan('benchforge:sync')->assertSuccessful();

        expect(BenchmarkRun::count())->toBe(1)
            ->and(ModelRun::count())->toBe(2)
            ->and(StageResult::count())->toBe(12)
            ->and(Artifact::count())->toBe(5);

        File::put($runPath.'/_summary.tsv', <<<'SUMMARY'
klasor|model|opencode|lint|build|export|routes|runtime|sure_sn
minimax-m3|opencode-go/minimax-m3|0|0|0|0|0|0|900
qwen3.8-flash|opencode-go/qwen3.8-flash|0|0|0|0|0|0|2336
SUMMARY);

        $this->artisan('benchforge:sync')->assertSuccessful();

        $this->assertDatabaseHas('model_runs', [
            'slug' => 'minimax-m3',
            'status' => 'pass',
            'duration_seconds' => 900,
        ]);
        $this->assertDatabaseHas('benchmark_runs', [
            'slug' => 'nova-marketplace-v1',
            'passed_count' => 2,
            'failed_count' => 0,
        ]);
    } finally {
        File::deleteDirectory($runsPath);
    }
});

it('completes successfully when no archive directory exists', function () {
    config()->set('benchforge.runs_path', storage_path('framework/testing/missing-sync-runs'));

    $this->artisan('benchforge:sync')->assertSuccessful();

    expect(BenchmarkRun::count())->toBe(0);
});

it('indexes incomplete run folders so their storage can be managed', function () {
    $runsPath = storage_path('framework/testing/incomplete-runs-'.Str::uuid());
    $runPath = $runsPath.'/interrupted-run';
    File::ensureDirectoryExists($runPath.'/model-a');
    File::ensureDirectoryExists($runPath.'/_logs');
    File::put($runPath.'/model-a/app.js', str_repeat('a', 100));
    File::put($runPath.'/_logs/model-a.log', str_repeat('b', 25));
    config()->set('benchforge.runs_path', $runsPath);

    try {
        $this->artisan('benchforge:sync')->assertSuccessful();

        $this->assertDatabaseHas('benchmark_runs', [
            'slug' => 'interrupted-run',
            'schema' => 'incomplete',
            'model_count' => 1,
            'unknown_count' => 1,
            'total_bytes' => 125,
            'workspace_bytes' => 100,
            'result_bytes' => 25,
        ]);
        $this->assertDatabaseHas('model_runs', [
            'slug' => 'model-a',
            'status' => 'unknown',
        ]);
    } finally {
        File::deleteDirectory($runsPath);
    }
});
