<?php

use App\Models\BenchmarkExecution;
use App\Services\BenchForgeSystemStatus;
use App\Services\BenchmarkPreflight;
use App\Services\BenchmarkRunner;
use App\Services\PendingMigrations;
use App\Services\SyncBenchmarkArchive;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

use function Pest\Laravel\mock;

uses(LazilyRefreshDatabase::class);

test('it reports database updates that have not been applied', function () {
    $pending = app(PendingMigrations::class);

    expect($pending->names())->toBe([])
        ->and($pending->warning())->toBeNull();

    $latest = DB::table('migrations')->orderByDesc('id')->value('migration');
    DB::table('migrations')->where('migration', $latest)->delete();

    try {
        expect($pending->names())->toBe([$latest])
            ->and($pending->warning())->toContain('1 veritabanı güncellemesi uygulanmamış')
            ->and($pending->warning())->toContain('php artisan migrate');

        $status = collect(app(BenchForgeSystemStatus::class)->inspect()['checks'])->firstWhere('key', 'database');
        $preflight = collect(app(BenchmarkPreflight::class)->inspect([], [], 'algorithm')['checks'])->firstWhere('key', 'database');

        expect($status['status'])->toBe('fail')
            ->and($status['message'])->toContain('php artisan migrate')
            ->and($status['detail'])->toBe($latest)
            ->and($preflight['status'])->toBe('fail')
            ->and($preflight['message'])->toContain('php artisan migrate');
    } finally {
        DB::table('migrations')->insert(['migration' => $latest, 'batch' => 1]);
    }
});

test('a finished run whose results could not be saved says so instead of reporting success', function () {
    $root = storage_path('framework/testing/benchmark-root-'.Str::uuid());
    File::ensureDirectoryExists($root.'/runs/unsaved-run');
    File::ensureDirectoryExists($root.'/runner');
    config()->set('benchforge.root_path', $root);
    config()->set('benchforge.runs_path', $root.'/runs');
    config()->set('benchforge.runner_path', $root.'/runner');
    BenchmarkExecution::factory()->create([
        'run_id' => 'unsaved-run',
        'status' => 'completed',
        'message' => 'Benchmark tamamlandı ve sonuçlar kaydedildi.',
        'state_path' => $root.'/runner/missing-state.json',
        'log_path' => $root.'/runner/missing.log',
        'finished_at' => now(),
    ]);
    mock(SyncBenchmarkArchive::class)
        ->shouldReceive('handle')
        ->with('unsaved-run')
        ->andReturn(['created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 1, 'errors' => ['unsaved-run: SQLSTATE[HY000]: General error: 1 no such column: metrics'], 'locked' => false]);

    try {
        $snapshot = app(BenchmarkRunner::class)->snapshot();

        expect($snapshot['status'])->toBe('completed')
            ->and($snapshot['outcome']['status'])->toBe('unavailable')
            ->and($snapshot['outcome']['label'])->toBe('Sonuç kaydedilemedi')
            ->and($snapshot['message'])->toContain('sonuçlar veritabanına kaydedilemedi')
            ->and($snapshot['message'])->toContain('no such column: metrics')
            ->and(BenchmarkExecution::query()->where('run_id', 'unsaved-run')->value('synced_at'))->toBeNull();
    } finally {
        File::deleteDirectory($root);
    }
});
