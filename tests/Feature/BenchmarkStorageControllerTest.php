<?php

use App\Exceptions\BenchmarkStorageException;
use App\Models\Artifact;
use App\Models\BenchmarkExecution;
use App\Models\BenchmarkRun;
use App\Models\ModelEvaluation;
use App\Models\ModelRun;
use App\Models\ModelRunAttempt;
use App\Models\StageResult;
use App\Services\BenchmarkRunStorage;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

test('it deletes only model workspaces and preserves benchmark results', function () {
    $runsPath = storage_path('framework/testing/storage-runs-'.Str::uuid());
    $runPath = $runsPath.'/storage-test-run';
    File::ensureDirectoryExists($runPath.'/model-a/src');
    File::ensureDirectoryExists($runPath.'/model-b');
    File::ensureDirectoryExists($runPath.'/_logs');
    File::put($runPath.'/model-a/src/app.js', str_repeat('a', 100));
    File::put($runPath.'/model-b/package.json', str_repeat('b', 50));
    File::put($runPath.'/_logs/model-a.log', str_repeat('c', 25));
    File::put($runPath.'/_summary.tsv', str_repeat('d', 10));
    config()->set('benchforge.runs_path', $runsPath);
    config()->set('benchforge.runner_path', storage_path('framework/testing/storage-runner'));
    $run = BenchmarkRun::factory()->create(['slug' => 'storage-test-run']);
    Artifact::factory()->for($run)->create([
        'artifact_key' => 'model:model-a:source',
        'type' => 'source',
        'path' => $runPath.'/model-a',
    ]);
    Artifact::factory()->for($run)->create([
        'artifact_key' => 'run:logs',
        'type' => 'logs',
        'path' => $runPath.'/_logs',
    ]);

    try {
        app(BenchmarkRunStorage::class)->refresh($run);

        $this->from(route('runs.show', $run))
            ->delete(route('runs.workspace.destroy', $run), ['confirmation' => '1'])
            ->assertRedirect(route('runs.show', $run))
            ->assertSessionHas('status');

        expect(File::exists($runPath.'/model-a'))->toBeFalse()
            ->and(File::exists($runPath.'/model-b'))->toBeFalse()
            ->and(File::isFile($runPath.'/_logs/model-a.log'))->toBeTrue()
            ->and(BenchmarkRun::query()->whereKey($run->id)->exists())->toBeTrue()
            ->and(Artifact::query()->where('type', 'source')->exists())->toBeFalse()
            ->and(Artifact::query()->where('type', 'logs')->exists())->toBeTrue();

        $run->refresh();

        expect($run->workspace_bytes)->toBe(0)
            ->and($run->result_bytes)->toBe(35)
            ->and($run->total_bytes)->toBe(35)
            ->and($run->workspace_count)->toBe(0)
            ->and($run->workspace_deleted_at)->not->toBeNull();
    } finally {
        File::deleteDirectory($runsPath);
    }
});

test('it permanently deletes a completed benchmark and all related records', function () {
    $runsPath = storage_path('framework/testing/delete-runs-'.Str::uuid());
    $runnerRoot = storage_path('framework/testing/delete-runner-'.Str::uuid());
    $runPath = $runsPath.'/delete-test-run';
    $runnerPath = $runnerRoot.'/delete-test-run-abc123';
    File::ensureDirectoryExists($runPath.'/model-a');
    File::ensureDirectoryExists($runPath.'/_logs');
    File::ensureDirectoryExists($runnerPath);
    File::put($runPath.'/model-a/app.js', str_repeat('a', 100));
    File::put($runPath.'/_logs/model-a.log', str_repeat('b', 25));
    File::put($runnerPath.'/state.json', str_repeat('c', 10));
    config()->set('benchforge.runs_path', $runsPath);
    config()->set('benchforge.runner_path', $runnerRoot);
    $run = BenchmarkRun::factory()->create(['slug' => 'delete-test-run']);
    $modelRun = ModelRun::factory()->for($run)->create();
    StageResult::factory()->for($modelRun)->create();
    ModelEvaluation::factory()->for($modelRun)->create();
    Artifact::factory()->for($run)->for($modelRun)->create();
    BenchmarkExecution::factory()->create([
        'run_id' => $run->slug,
        'status' => 'completed',
        'state_path' => $runnerPath.'/state.json',
        'log_path' => $runnerPath.'/runner.log',
    ]);

    try {
        app(BenchmarkRunStorage::class)->refresh($run);

        $this->delete(route('runs.destroy', $run), ['confirmation' => '1'])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status');

        expect(File::exists($runPath))->toBeFalse()
            ->and(File::exists($runnerPath))->toBeFalse()
            ->and(BenchmarkRun::query()->whereKey($run->id)->exists())->toBeFalse()
            ->and(BenchmarkExecution::query()->where('run_id', $run->slug)->exists())->toBeFalse()
            ->and(ModelRun::query()->whereKey($modelRun->id)->exists())->toBeFalse()
            ->and(StageResult::query()->exists())->toBeFalse()
            ->and(ModelEvaluation::query()->exists())->toBeFalse()
            ->and(Artifact::query()->exists())->toBeFalse();
    } finally {
        File::deleteDirectory($runsPath);
        File::deleteDirectory($runnerRoot);
    }
});

test('it permanently deletes an incomplete benchmark with nested workspaces and recovery artifacts', function () {
    $runsPath = storage_path('framework/testing/incomplete-delete-runs-'.Str::uuid());
    $runnerRoot = storage_path('framework/testing/incomplete-delete-runner-'.Str::uuid());
    $runPath = $runsPath.'/aeron-commerce-v1-repair';
    File::ensureDirectoryExists($runPath.'/glm-5.3-flash/node_modules/dependency/cache');
    File::ensureDirectoryExists($runPath.'/qwen3.8-flash/.next/cache');
    File::ensureDirectoryExists($runPath.'/_logs');
    File::ensureDirectoryExists($runPath.'/_attempts/glm-5.3-flash/attempt-1/workspace-before/node_modules/package');
    File::ensureDirectoryExists($runPath.'/_backups/original');
    File::ensureDirectoryExists($runnerRoot);
    File::put($runPath.'/glm-5.3-flash/node_modules/dependency/cache/.hidden-cache', 'cache');
    File::put($runPath.'/qwen3.8-flash/.next/cache/build.bin', 'build');
    File::put($runPath.'/qwen3.8-flash/.env.local', 'SECRET=fixture-only');
    File::put($runPath.'/_logs/repair.log', 'repair log');
    File::put($runPath.'/_attempts/glm-5.3-flash/attempt-1/workspace-before/node_modules/package/.state', 'state');
    File::put($runPath.'/_backups/original/.snapshot', 'snapshot');
    File::put($runPath.'/.DS_Store', 'fixture');
    config()->set('benchforge.runs_path', $runsPath);
    config()->set('benchforge.runner_path', $runnerRoot);
    $run = BenchmarkRun::factory()->create([
        'slug' => 'aeron-commerce-v1-repair',
        'schema' => 'incomplete',
        'metadata' => null,
        'model_count' => 2,
        'unknown_count' => 2,
    ]);
    $glm = ModelRun::factory()->for($run)->create([
        'slug' => 'glm-5.3-flash',
        'status' => 'unknown',
    ]);
    $qwen = ModelRun::factory()->for($run)->create([
        'slug' => 'qwen3.8-flash',
        'status' => 'unknown',
    ]);
    Artifact::factory()->for($run)->for($glm)->create([
        'artifact_key' => 'model:glm-5.3-flash:source',
        'type' => 'source',
        'path' => $runPath.'/glm-5.3-flash',
    ]);
    Artifact::factory()->for($run)->for($qwen)->create([
        'artifact_key' => 'model:qwen3.8-flash:source',
        'type' => 'source',
        'path' => $runPath.'/qwen3.8-flash',
    ]);

    try {
        expect(File::isFile($runPath.'/_summary.tsv'))->toBeFalse()
            ->and(File::isFile($runPath.'/_meta.txt'))->toBeFalse()
            ->and(BenchmarkExecution::query()->where('run_id', $run->slug)->exists())->toBeFalse();

        $this->delete(route('runs.destroy', $run), ['confirmation' => '1'])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status')
            ->assertSessionHasNoErrors();

        expect(File::exists($runPath))->toBeFalse()
            ->and(BenchmarkRun::query()->whereKey($run->id)->exists())->toBeFalse()
            ->and(ModelRun::query()->whereIn('id', [$glm->id, $qwen->id])->exists())->toBeFalse()
            ->and(Artifact::query()->where('benchmark_run_id', $run->id)->exists())->toBeFalse();
    } finally {
        File::deleteDirectory($runsPath);
        File::deleteDirectory($runnerRoot);
    }
});

test('it removes referenced and orphan worker directories without touching protected runner paths', function (string $routeName, bool $deletesRun, bool $hasExecution) {
    $runsPath = storage_path('framework/testing/worker-cleanup-runs-'.Str::uuid());
    $runnerRoot = storage_path('framework/testing/worker-cleanup-runner-'.Str::uuid());
    $outsidePath = storage_path('framework/testing/worker-cleanup-outside-'.Str::uuid());
    $slug = 'worker-cleanup-run';
    $runPath = $runsPath.'/'.$slug;
    $referencedRunner = $runnerRoot.'/'.$slug.'-abcd1234';
    $orphanRunner = $runnerRoot.'/'.$slug.'-wxyz5678';
    $otherRunner = $runnerRoot.'/another-run-abcd1234';
    $similarRunner = $runnerRoot.'/'.$slug.'-v2-abcd1234';
    $runnerSymlink = $runnerRoot.'/'.$slug.'-link1234';
    File::ensureDirectoryExists($runPath.'/model-a');
    File::ensureDirectoryExists($runPath.'/_logs');
    File::ensureDirectoryExists($referencedRunner);
    File::ensureDirectoryExists($orphanRunner);
    File::ensureDirectoryExists($otherRunner);
    File::ensureDirectoryExists($similarRunner);
    File::ensureDirectoryExists($outsidePath);
    File::put($runPath.'/model-a/app.js', 'workspace');
    File::put($runPath.'/_logs/model-a.log', 'result');
    File::put($referencedRunner.'/state.json', 'referenced');
    File::put($orphanRunner.'/state.json', 'orphan');
    File::put($otherRunner.'/state.json', 'other');
    File::put($similarRunner.'/state.json', 'similar');
    File::put($outsidePath.'/keep.txt', 'outside');
    File::put($runnerRoot.'/active.lock', 'root lock');
    symlink($outsidePath, $runnerSymlink);
    config()->set('benchforge.runs_path', $runsPath);
    config()->set('benchforge.runner_path', $runnerRoot);
    $run = BenchmarkRun::factory()->create(['slug' => $slug]);
    ModelRun::factory()->for($run)->create(['slug' => 'model-a']);
    if ($hasExecution) {
        BenchmarkExecution::factory()->create([
            'run_id' => $slug,
            'status' => 'completed',
            'state_path' => $referencedRunner.'/state.json',
            'log_path' => $referencedRunner.'/runner.log',
        ]);
    }

    try {
        $response = $this->delete(route($routeName, $run), ['confirmation' => '1']);

        $response
            ->assertSessionHas('status')
            ->assertSessionHasNoErrors();

        expect(File::exists($referencedRunner))->toBeFalse()
            ->and(File::exists($orphanRunner))->toBeFalse()
            ->and(File::isDirectory($otherRunner))->toBeTrue()
            ->and(File::isDirectory($similarRunner))->toBeTrue()
            ->and(File::isFile($runnerRoot.'/active.lock'))->toBeTrue()
            ->and(is_link($runnerSymlink))->toBeTrue()
            ->and(File::isFile($outsidePath.'/keep.txt'))->toBeTrue()
            ->and(BenchmarkExecution::query()->where('run_id', $slug)->exists())->toBeFalse();

        if ($deletesRun) {
            $response->assertRedirect(route('dashboard'));
            expect(File::exists($runPath))->toBeFalse()
                ->and(BenchmarkRun::query()->whereKey($run->id)->exists())->toBeFalse();
        } else {
            $response->assertRedirect(route('runs.show', $run));
            expect(File::isFile($runPath.'/model-a/app.js'))->toBeTrue()
                ->and(File::exists($runPath.'/_logs'))->toBeFalse()
                ->and(BenchmarkRun::query()->whereKey($run->id)->exists())->toBeTrue();
        }
    } finally {
        if (is_link($runnerSymlink)) {
            unlink($runnerSymlink);
        }

        File::deleteDirectory($runsPath);
        File::deleteDirectory($runnerRoot);
        File::deleteDirectory($outsidePath);
    }
})->with([
    'full benchmark deletion' => ['runs.destroy', true, true],
    'result archive deletion' => ['runs.results.destroy', false, true],
    'full deletion with orphan directories only' => ['runs.destroy', true, false],
]);

test('it keeps storage records unchanged when a workspace cannot be deleted', function () {
    $runsPath = storage_path('framework/testing/partial-workspace-runs-'.Str::uuid());
    $runnerRoot = storage_path('framework/testing/partial-workspace-runner-'.Str::uuid());
    $runPath = $runsPath.'/partial-workspace-run';
    $deletedWorkspace = $runPath.'/model-a';
    $blockedWorkspace = $runPath.'/model-b';
    File::ensureDirectoryExists($deletedWorkspace);
    File::ensureDirectoryExists($blockedWorkspace);
    File::ensureDirectoryExists($runPath.'/_logs');
    File::ensureDirectoryExists($runnerRoot);
    File::put($deletedWorkspace.'/app.js', 'first workspace');
    File::put($blockedWorkspace.'/app.js', 'blocked workspace');
    File::put($runPath.'/_logs/model.log', 'result');
    config()->set('benchforge.runs_path', $runsPath);
    config()->set('benchforge.runner_path', $runnerRoot);
    $run = BenchmarkRun::factory()->create(['slug' => 'partial-workspace-run']);
    $firstArtifact = Artifact::factory()->for($run)->create([
        'artifact_key' => 'model:model-a:source',
        'type' => 'source',
        'path' => $deletedWorkspace,
    ]);
    $blockedArtifact = Artifact::factory()->for($run)->create([
        'artifact_key' => 'model:model-b:source',
        'type' => 'source',
        'path' => $blockedWorkspace,
    ]);

    File::partialMock()
        ->shouldReceive('deleteDirectory')
        ->once()
        ->with($blockedWorkspace)
        ->andReturn(false);
    Log::spy();

    try {
        $this->from(route('runs.show', $run))
            ->delete(route('runs.workspace.destroy', $run), ['confirmation' => '1'])
            ->assertRedirect(route('runs.show', $run))
            ->assertSessionHasErrors(['storage'])
            ->assertSessionMissing('status');

        $run->refresh();

        expect(File::isFile($blockedWorkspace.'/app.js'))->toBeTrue()
            ->and($run->workspace_deleted_at)->toBeNull()
            ->and(Artifact::query()->whereKey($firstArtifact->id)->exists())->toBeTrue()
            ->and(Artifact::query()->whereKey($blockedArtifact->id)->exists())->toBeTrue()
            ->and($run->artifacts()->where('type', 'source')->count())->toBe(2);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'benchmark.storage.deletion.failed'
                && ($context['run_slug'] ?? null) === $run->slug
                && ($context['operation'] ?? null) === 'workspaces');
        Log::shouldNotHaveReceived(
            'info',
            fn (string $message): bool => $message === 'benchmark.storage.deletion.completed',
        );
    } finally {
        File::swap(new Filesystem);
        File::deleteDirectory($runsPath);
        File::deleteDirectory($runnerRoot);
    }
});

test('it deletes result artifacts while preserving model workspaces', function () {
    $runsPath = storage_path('framework/testing/result-runs-'.Str::uuid());
    $runnerRoot = storage_path('framework/testing/result-runner-'.Str::uuid());
    $runPath = $runsPath.'/result-test-run';
    $runnerPath = $runnerRoot.'/result-test-run-abc123';
    File::ensureDirectoryExists($runPath.'/model-a');
    File::ensureDirectoryExists($runPath.'/_logs');
    File::ensureDirectoryExists($runnerPath);
    File::put($runPath.'/model-a/app.js', str_repeat('a', 100));
    File::put($runPath.'/_logs/model-a.log', str_repeat('b', 25));
    File::put($runPath.'/_summary.tsv', str_repeat('c', 10));
    File::put($runnerPath.'/state.json', str_repeat('d', 5));
    config()->set('benchforge.runs_path', $runsPath);
    config()->set('benchforge.runner_path', $runnerRoot);
    $run = BenchmarkRun::factory()->create([
        'slug' => 'result-test-run',
        'model_count' => 1,
        'passed_count' => 1,
    ]);
    $modelRun = ModelRun::factory()->for($run)->create(['slug' => 'model-a']);
    StageResult::factory()->for($modelRun)->create();
    ModelEvaluation::factory()->for($modelRun)->create();
    Artifact::factory()->for($run)->for($modelRun)->create();
    BenchmarkExecution::factory()->create([
        'run_id' => $run->slug,
        'status' => 'completed',
        'state_path' => $runnerPath.'/state.json',
        'log_path' => $runnerPath.'/runner.log',
    ]);

    try {
        app(BenchmarkRunStorage::class)->refresh($run);

        $this->delete(route('runs.results.destroy', $run), ['confirmation' => '1'])
            ->assertRedirect(route('runs.show', $run))
            ->assertSessionHas('status');

        expect(File::isFile($runPath.'/model-a/app.js'))->toBeTrue()
            ->and(File::exists($runPath.'/_logs'))->toBeFalse()
            ->and(File::exists($runPath.'/_summary.tsv'))->toBeFalse()
            ->and(File::exists($runnerPath))->toBeFalse()
            ->and(BenchmarkExecution::query()->where('run_id', $run->slug)->exists())->toBeFalse()
            ->and(StageResult::query()->exists())->toBeFalse()
            ->and(ModelEvaluation::query()->exists())->toBeFalse();

        $run->refresh();

        expect($run->schema)->toBe('incomplete')
            ->and($run->workspace_bytes)->toBe(100)
            ->and($run->result_bytes)->toBe(0)
            ->and($run->modelRuns()->sole()->status)->toBe('unknown')
            ->and($run->artifacts()->sole()->type)->toBe('source');
    } finally {
        File::deleteDirectory($runsPath);
        File::deleteDirectory($runnerRoot);
    }
});

test('it refuses to delete files belonging to an active benchmark', function () {
    $runsPath = storage_path('framework/testing/active-runs-'.Str::uuid());
    $runPath = $runsPath.'/active-test-run';
    File::ensureDirectoryExists($runPath.'/model-a');
    File::put($runPath.'/model-a/app.js', 'keep me');
    config()->set('benchforge.runs_path', $runsPath);
    $run = BenchmarkRun::factory()->create(['slug' => 'active-test-run']);
    BenchmarkExecution::factory()->create([
        'run_id' => $run->slug,
        'status' => 'running',
    ]);

    try {
        $this->from(route('runs.show', $run))
            ->delete(route('runs.workspace.destroy', $run), ['confirmation' => '1'])
            ->assertRedirect(route('runs.show', $run))
            ->assertSessionHasErrors(['storage']);

        expect(File::isFile($runPath.'/model-a/app.js'))->toBeTrue();
    } finally {
        File::deleteDirectory($runsPath);
    }
});

test('it refuses to delete files belonging to an active recovery attempt', function () {
    $runsPath = storage_path('framework/testing/active-attempt-runs-'.Str::uuid());
    $runPath = $runsPath.'/active-attempt-run';
    File::ensureDirectoryExists($runPath.'/model-a');
    File::put($runPath.'/model-a/app.js', 'keep me');
    config()->set('benchforge.runs_path', $runsPath);
    $run = BenchmarkRun::factory()->create(['slug' => 'active-attempt-run']);
    $modelRun = ModelRun::factory()->for($run)->create(['slug' => 'model-a']);
    ModelRunAttempt::factory()->for($modelRun)->create([
        'status' => 'running',
        'finished_at' => null,
    ]);

    try {
        $this->from(route('runs.show', $run))
            ->delete(route('runs.workspace.destroy', $run), ['confirmation' => '1'])
            ->assertRedirect(route('runs.show', $run))
            ->assertSessionHasErrors(['storage']);

        expect(File::isFile($runPath.'/model-a/app.js'))->toBeTrue();
    } finally {
        File::deleteDirectory($runsPath);
    }
});

test('it rejects an incorrect full deletion confirmation', function () {
    $run = BenchmarkRun::factory()->create(['slug' => 'confirmation-test-run']);

    $this->delete(route('runs.destroy', $run), ['confirmation' => 'wrong-run'])
        ->assertSessionHasErrors(['confirmation']);

    expect($run->fresh())->not->toBeNull();
});

test('it preserves files and records when deletion is not confirmed', function (string $routeName, string $message, array $payload) {
    $runsPath = storage_path('framework/testing/unconfirmed-runs-'.Str::uuid());
    $runPath = $runsPath.'/unconfirmed-test-run';
    File::ensureDirectoryExists($runPath.'/model-a');
    File::ensureDirectoryExists($runPath.'/_logs');
    File::put($runPath.'/model-a/app.js', 'keep workspace');
    File::put($runPath.'/_logs/model-a.log', 'keep results');
    config()->set('benchforge.runs_path', $runsPath);
    $run = BenchmarkRun::factory()->create(['slug' => 'unconfirmed-test-run']);
    $modelRun = ModelRun::factory()->for($run)->create();
    $stageResult = StageResult::factory()->for($modelRun)->create();
    Log::spy();

    try {
        $this->from(route('runs.show', $run))
            ->delete(route($routeName, $run), $payload)
            ->assertRedirect(route('runs.show', $run))
            ->assertSessionHasErrors(['confirmation' => $message]);

        $this->assertModelExists($run);
        $this->assertModelExists($modelRun);
        $this->assertModelExists($stageResult);

        expect(File::get($runPath.'/model-a/app.js'))->toBe('keep workspace')
            ->and(File::get($runPath.'/_logs/model-a.log'))->toBe('keep results');

        Log::shouldHaveReceived('notice')
            ->once()
            ->with('benchmark.storage.deletion.unconfirmed', [
                'route' => $routeName,
                'run_slug' => $run->slug,
            ]);
    } finally {
        File::deleteDirectory($runsPath);
    }
})->with([
    'workspace deletion' => ['runs.workspace.destroy', 'Çalışma alanlarını silmek için açılan onay penceresinden doğrulayın.'],
    'result deletion' => ['runs.results.destroy', 'Silme işlemini açılan onay penceresinden doğrulayın.'],
    'full deletion' => ['runs.destroy', 'Silme işlemini açılan onay penceresinden doğrulayın.'],
])->with([
    'missing confirmation' => [[]],
    'empty confirmation without JavaScript' => [['confirmation' => '']],
    'declined confirmation' => [['confirmation' => '0']],
]);

test('it forbids storage deletion from a non local address', function () {
    $run = BenchmarkRun::factory()->create(['slug' => 'remote-test-run']);

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
        ->delete(route('runs.workspace.destroy', $run), ['confirmation' => '1'])
        ->assertForbidden();
});

test('it refuses a run directory symlink that resolves outside the configured runs root', function () {
    $runsPath = storage_path('framework/testing/symlink-runs-'.Str::uuid());
    $outsidePath = storage_path('framework/testing/symlink-outside-'.Str::uuid());
    File::ensureDirectoryExists($runsPath);
    File::ensureDirectoryExists($outsidePath.'/model-a');
    File::put($outsidePath.'/model-a/app.js', 'keep me');
    symlink($outsidePath, $runsPath.'/symlink-test-run');
    config()->set('benchforge.runs_path', $runsPath);
    $run = BenchmarkRun::factory()->create(['slug' => 'symlink-test-run']);

    try {
        $this->from(route('runs.show', $run))
            ->delete(route('runs.workspace.destroy', $run), ['confirmation' => '1'])
            ->assertRedirect(route('runs.show', $run))
            ->assertSessionHasErrors(['storage']);

        expect(File::isFile($outsidePath.'/model-a/app.js'))->toBeTrue();
    } finally {
        @unlink($runsPath.'/symlink-test-run');
        File::deleteDirectory($runsPath);
        File::deleteDirectory($outsidePath);
    }
});

test('it refuses a run directory symlink that points to another run inside the configured root', function () {
    $runsPath = storage_path('framework/testing/internal-symlink-runs-'.Str::uuid());
    $runnerRoot = storage_path('framework/testing/internal-symlink-runner-'.Str::uuid());
    $targetPath = $runsPath.'/protected-target-run';
    $linkedPath = $runsPath.'/linked-run';
    File::ensureDirectoryExists($targetPath.'/model-a');
    File::ensureDirectoryExists($runnerRoot);
    File::put($targetPath.'/model-a/app.js', 'keep target');
    symlink($targetPath, $linkedPath);
    config()->set('benchforge.runs_path', $runsPath);
    config()->set('benchforge.runner_path', $runnerRoot);
    $run = BenchmarkRun::factory()->create(['slug' => 'linked-run']);

    try {
        $this->from(route('runs.show', $run))
            ->delete(route('runs.destroy', $run), ['confirmation' => '1'])
            ->assertRedirect(route('runs.show', $run))
            ->assertSessionHasErrors(['storage'])
            ->assertSessionMissing('status');

        expect(is_link($linkedPath))->toBeTrue()
            ->and(File::isFile($targetPath.'/model-a/app.js'))->toBeTrue()
            ->and(BenchmarkRun::query()->whereKey($run->id)->exists())->toBeTrue();
    } finally {
        if (is_link($linkedPath)) {
            unlink($linkedPath);
        }

        File::deleteDirectory($runsPath);
        File::deleteDirectory($runnerRoot);
    }
});

test('it preserves all run files when the database references an unavailable runner path', function (bool $runnerRootExists) {
    $runsPath = storage_path('framework/testing/unsafe-runner-runs-'.Str::uuid());
    $runnerRoot = storage_path('framework/testing/unsafe-runner-root-'.Str::uuid());
    $outsidePath = storage_path('framework/testing/unsafe-runner-outside-'.Str::uuid());
    $runPath = $runsPath.'/unsafe-runner-run';
    File::ensureDirectoryExists($runPath.'/model-a');
    File::ensureDirectoryExists($outsidePath);

    if ($runnerRootExists) {
        File::ensureDirectoryExists($runnerRoot);
    }

    File::put($runPath.'/model-a/app.js', 'keep run');
    File::put($outsidePath.'/state.json', 'unsafe state');
    config()->set('benchforge.runs_path', $runsPath);
    config()->set('benchforge.runner_path', $runnerRoot);
    $run = BenchmarkRun::factory()->create(['slug' => 'unsafe-runner-run']);
    $execution = BenchmarkExecution::factory()->create([
        'run_id' => $run->slug,
        'status' => 'completed',
        'state_path' => $outsidePath.'/state.json',
        'log_path' => $outsidePath.'/runner.log',
    ]);

    try {
        $this->from(route('runs.show', $run))
            ->delete(route('runs.destroy', $run), ['confirmation' => '1'])
            ->assertRedirect(route('runs.show', $run))
            ->assertSessionHasErrors(['storage'])
            ->assertSessionMissing('status');

        expect(File::isFile($runPath.'/model-a/app.js'))->toBeTrue()
            ->and(File::isFile($outsidePath.'/state.json'))->toBeTrue()
            ->and(BenchmarkRun::query()->whereKey($run->id)->exists())->toBeTrue()
            ->and(BenchmarkExecution::query()->whereKey($execution->id)->exists())->toBeTrue();
    } finally {
        File::deleteDirectory($runsPath);
        File::deleteDirectory($runnerRoot);
        File::deleteDirectory($outsidePath);
    }
})->with([
    'unsafe path outside an existing runner root' => [true],
    'missing runner root' => [false],
]);

test('it preserves files and records when the filesystem throws during full deletion', function () {
    $runsPath = storage_path('framework/testing/exception-delete-runs-'.Str::uuid());
    $runnerRoot = storage_path('framework/testing/exception-delete-runner-'.Str::uuid());
    $runPath = $runsPath.'/exception-delete-run';
    File::ensureDirectoryExists($runPath.'/model-a');
    File::ensureDirectoryExists($runnerRoot);
    File::put($runPath.'/model-a/app.js', 'keep source');
    config()->set('benchforge.runs_path', $runsPath);
    config()->set('benchforge.runner_path', $runnerRoot);
    $run = BenchmarkRun::factory()->create(['slug' => 'exception-delete-run']);
    $modelRun = ModelRun::factory()->for($run)->create(['slug' => 'model-a']);
    $artifact = Artifact::factory()->for($run)->for($modelRun)->create([
        'artifact_key' => 'model:model-a:source',
        'type' => 'source',
        'path' => $runPath.'/model-a',
    ]);

    File::partialMock()
        ->shouldReceive('deleteDirectory')
        ->once()
        ->with($runPath)
        ->andThrow(new RuntimeException('Controlled fixture failure.'));
    Log::spy();

    try {
        $this->from(route('runs.show', $run))
            ->delete(route('runs.destroy', $run), ['confirmation' => '1'])
            ->assertRedirect(route('runs.show', $run))
            ->assertSessionHasErrors([
                'storage' => 'Silme işlemi tamamlanamadı. Bazı dosyalar kaldırılmış olabilir; hata uygulama günlüğüne kaydedildi.',
            ])
            ->assertSessionMissing('status');

        expect(File::isFile($runPath.'/model-a/app.js'))->toBeTrue()
            ->and(BenchmarkRun::query()->whereKey($run->id)->exists())->toBeTrue()
            ->and(ModelRun::query()->whereKey($modelRun->id)->exists())->toBeTrue()
            ->and(Artifact::query()->whereKey($artifact->id)->exists())->toBeTrue();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'benchmark.storage.deletion.failed'
                && ($context['run_slug'] ?? null) === $run->slug
                && ($context['operation'] ?? null) === 'run'
                && ($context['exception_type'] ?? null) === RuntimeException::class);
        Log::shouldNotHaveReceived(
            'info',
            fn (string $message): bool => $message === 'benchmark.storage.deletion.completed',
        );
    } finally {
        File::swap(new Filesystem);
        File::deleteDirectory($runsPath);
        File::deleteDirectory($runnerRoot);
    }
});

test('it slims workspaces to source and exports and archives previews that exist only there', function () {
    $runPath = config('benchforge.runs_path').'/slim-run';
    File::ensureDirectoryExists($runPath.'/failed-model/node_modules/pkg');
    File::ensureDirectoryExists($runPath.'/failed-model/.next/cache');
    File::ensureDirectoryExists($runPath.'/failed-model/src');
    File::ensureDirectoryExists($runPath.'/failed-model/out');
    File::ensureDirectoryExists($runPath.'/passed-model/node_modules');
    File::ensureDirectoryExists($runPath.'/passed-model/out');
    File::ensureDirectoryExists($runPath.'/_gallery/passed-model');
    File::put($runPath.'/failed-model/node_modules/pkg/index.js', str_repeat('n', 400));
    File::put($runPath.'/failed-model/.next/cache/a', str_repeat('c', 100));
    File::put($runPath.'/failed-model/src/page.tsx', 'export default 1;');
    File::put($runPath.'/failed-model/out/index.html', '<p>failed model export</p>');
    File::put($runPath.'/passed-model/node_modules/x.js', str_repeat('n', 50));
    File::put($runPath.'/passed-model/out/index.html', '<p>workspace copy</p>');
    File::put($runPath.'/_gallery/passed-model/index.html', '<p>already archived</p>');
    $run = BenchmarkRun::factory()->create(['slug' => 'slim-run']);

    $preview = app(BenchmarkRunStorage::class)->slimWorkspaces($run, dryRun: true);

    expect($preview)->toBe(['freedBytes' => 550, 'slimmedCount' => 2, 'archivedPreviews' => 1])
        ->and(File::exists($runPath.'/failed-model/node_modules'))->toBeTrue()
        ->and(File::exists($runPath.'/_gallery/failed-model'))->toBeFalse();

    $this->artisan('benchforge:slim', ['run' => 'slim-run'])->assertSuccessful();

    expect(File::exists($runPath.'/failed-model/node_modules'))->toBeFalse()
        ->and(File::exists($runPath.'/failed-model/.next'))->toBeFalse()
        ->and(File::exists($runPath.'/passed-model/node_modules'))->toBeFalse()
        ->and(File::get($runPath.'/failed-model/src/page.tsx'))->toBe('export default 1;')
        ->and(File::get($runPath.'/_gallery/failed-model/index.html'))->toBe('<p>failed model export</p>')
        ->and(File::get($runPath.'/_gallery/passed-model/index.html'))->toBe('<p>already archived</p>')
        ->and($run->refresh()->storage_scanned_at)->not->toBeNull();
});

test('deleting workspaces keeps a copy of previews that existed only in a workspace', function () {
    $runPath = config('benchforge.runs_path').'/archive-run';
    File::ensureDirectoryExists($runPath.'/failed-model/out');
    File::put($runPath.'/failed-model/out/index.html', '<p>only here</p>');
    $run = BenchmarkRun::factory()->create(['slug' => 'archive-run']);

    $this->from(route('runs.show', $run))
        ->delete(route('runs.workspace.destroy', $run), ['confirmation' => '1'])
        ->assertRedirect(route('runs.show', $run));

    expect(File::exists($runPath.'/failed-model'))->toBeFalse()
        ->and(File::get($runPath.'/_gallery/failed-model/index.html'))->toBe('<p>only here</p>');
});

test('it refuses to slim a run while a recovery attempt is active', function () {
    File::ensureDirectoryExists(config('benchforge.runs_path').'/busy-run/model/node_modules');
    $run = BenchmarkRun::factory()->create(['slug' => 'busy-run']);
    ModelRunAttempt::factory()->for(ModelRun::factory()->for($run))->create(['status' => 'running']);

    expect(fn () => app(BenchmarkRunStorage::class)->slimWorkspaces($run))
        ->toThrow(BenchmarkStorageException::class, 'Aktif bir kurtarma denemesinin');
    expect(File::exists(config('benchforge.runs_path').'/busy-run/model/node_modules'))->toBeTrue();
});
