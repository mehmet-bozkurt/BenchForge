<?php

use App\Models\BenchmarkRun;
use App\Models\ModelRun;
use App\Models\ModelRunAttempt;
use App\Services\ModelRunBoundaryReport;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

/** @return array{root: string, runPath: string, run: BenchmarkRun, model: ModelRun, attempt: ModelRunAttempt, attemptPath: string} */
function ownModelBoundaryFixture(): array
{
    $root = storage_path('framework/testing/model-boundary-'.Str::uuid());
    $runPath = $root.'/boundary-run';
    $attemptPath = $runPath.'/_attempts/model-a/attempt-1';
    File::ensureDirectoryExists($attemptPath);
    config()->set('benchforge.runs_path', $root);
    $run = BenchmarkRun::factory()->create(['slug' => 'boundary-run']);
    $model = ModelRun::factory()->for($run)->create(['slug' => 'model-a']);
    ModelRun::factory()->for($run)->create(['slug' => 'model-b']);
    $attempt = ModelRunAttempt::factory()->for($model)->create(['attempt_number' => 1]);

    return compact('root', 'runPath', 'run', 'model', 'attempt', 'attemptPath');
}

test('it reports blocked requests separately from observed reads and strips raw payloads', function () {
    ['root' => $root, 'runPath' => $runPath, 'run' => $run, 'model' => $model, 'attempt' => $attempt, 'attemptPath' => $attemptPath] = ownModelBoundaryFixture();
    File::put($attemptPath.'/boundary-report.json', json_encode([
        'version' => 1,
        'policyVersion' => 'workspace-boundary-v1',
        'blockedCount' => 3,
        'observedCrossModelReadCount' => 1,
        'stoppedForBoundary' => true,
        'entries' => [[
            'kind' => 'blocked',
            'tool' => 'bash',
            'path' => $runPath.'/_logs/model-b.build.log',
            'reason' => 'RAW PRIVATE REASON',
            'output' => 'SECRET RAW OUTPUT',
        ]],
    ], JSON_THROW_ON_ERROR));

    try {
        $report = app(ModelRunBoundaryReport::class)->forAttempt($run, $model, $attempt);

        expect($report['blockedCount'])->toBe(3);
        expect($report['observedCrossModelReadCount'])->toBe(1);
        expect($report['stoppedForBoundary'])->toBeTrue();
        expect($report['entries'][0]['path'])->not->toContain($root);
        expect(json_encode($report))->not->toContain('SECRET RAW OUTPUT', 'RAW PRIVATE REASON');
    } finally {
        File::deleteDirectory($root);
    }
});

test('it detects historical cross model log exposure without reading the other models files', function () {
    ['root' => $root, 'runPath' => $runPath, 'run' => $run, 'model' => $model, 'attempt' => $attempt, 'attemptPath' => $attemptPath] = ownModelBoundaryFixture();
    $events = [
        ['type' => 'tool_use', 'part' => ['tool' => 'bash', 'state' => [
            'status' => 'completed',
            'input' => ['command' => 'grep basePath ../_attempts'],
            'output' => $runPath.'/_attempts/model-b/attempt-1/opencode.events.jsonl:other data',
        ]]],
        ['type' => 'tool_use', 'part' => ['tool' => 'bash', 'state' => [
            'status' => 'error',
            'input' => ['command' => 'cat ../_logs/model-b.build.log'],
            'error' => 'The user rejected permission to use this specific tool call.',
        ]]],
        ['type' => 'tool_use', 'part' => ['tool' => 'unknown', 'state' => ['status' => 'error', 'error' => 'Tool execution aborted']]],
    ];
    File::put($attemptPath.'/opencode.events.jsonl', "invalid\nnull\n".implode("\n", array_map(fn ($event) => json_encode($event, JSON_THROW_ON_ERROR), $events))."\n{partial");
    File::put($attemptPath.'/opencode.stderr.log', "permission requested: external_directory ({$runPath}/_logs/*); auto-rejecting\n");

    try {
        $report = app(ModelRunBoundaryReport::class)->forAttempt($run, $model, $attempt);

        expect($report['blockedCount'])->toBe(1);
        expect($report['observedCrossModelReadCount'])->toBe(1);
        expect($report['scanLimited'])->toBeTrue();
        expect($report['source'])->toBe('legacy_log');
        expect(json_encode($report))->not->toContain('other data', '{partial', 'Tool execution aborted');
    } finally {
        File::deleteDirectory($root);
    }
});

test('it does not label blocked access or own build failures as successful cross model reads', function () {
    ['root' => $root, 'run' => $run, 'model' => $model, 'attempt' => $attempt, 'attemptPath' => $attemptPath] = ownModelBoundaryFixture();
    File::put($attemptPath.'/opencode.events.jsonl', json_encode([
        'type' => 'tool_use', 'part' => ['tool' => 'bash', 'state' => [
            'status' => 'completed', 'input' => ['command' => 'npm run build'],
            'output' => 'Turbopack build failed with one error.',
        ]],
    ], JSON_THROW_ON_ERROR));
    File::put($attemptPath.'/opencode.stderr.log', "permission requested: external_directory (/Users/example/projects/other/*); auto-rejecting\n");

    try {
        $report = app(ModelRunBoundaryReport::class)->forAttempt($run, $model, $attempt);

        expect($report['blockedCount'])->toBe(1);
        expect($report['observedCrossModelReadCount'])->toBe(0);
    } finally {
        File::deleteDirectory($root);
    }
});

test('it refuses boundary report symlinks and attempts belonging to another model', function () {
    ['root' => $root, 'runPath' => $runPath, 'run' => $run, 'model' => $model, 'attempt' => $attempt, 'attemptPath' => $attemptPath] = ownModelBoundaryFixture();
    File::ensureDirectoryExists($runPath.'/model-b');
    File::put($runPath.'/model-b/private.json', '{"version":1,"blockedCount":88}');
    symlink($runPath.'/model-b/private.json', $attemptPath.'/boundary-report.json');
    $otherModel = $run->modelRuns()->where('slug', 'model-b')->firstOrFail();

    try {
        $service = app(ModelRunBoundaryReport::class);

        expect($service->forAttempt($run, $model, $attempt)['blockedCount'])->toBe(0);
        expect($service->forAttempt($run, $otherModel, $attempt)['source'])->toBe('unavailable');
    } finally {
        File::deleteDirectory($root);
    }
});

test('it loads the initial policy and report only for the selected model', function () {
    ['root' => $root, 'runPath' => $runPath, 'run' => $run, 'model' => $model] = ownModelBoundaryFixture();
    File::ensureDirectoryExists($runPath.'/_policies/model-a');
    File::ensureDirectoryExists($runPath.'/_boundary');
    File::put($runPath.'/_policies/model-a/metadata.json', '{"policyVersion":"workspace-boundary-v1"}');
    File::put($runPath.'/_boundary/model-b.json', '{"version":1,"blockedCount":12}');

    try {
        $report = app(ModelRunBoundaryReport::class)->forModel($run, $model);

        expect($report['policyVersion'])->toBe('workspace-boundary-v1');
        expect($report['blockedCount'])->toBe(0);
        expect($report['source'])->toBe('unavailable');
    } finally {
        File::deleteDirectory($root);
    }
});

test('it normalizes own paths and identifies absolute external permission failures in legacy records', function () {
    ['root' => $root, 'runPath' => $runPath, 'run' => $run, 'model' => $model, 'attempt' => $attempt, 'attemptPath' => $attemptPath] = ownModelBoundaryFixture();
    $events = array_map(fn ($path) => [
        'type' => 'tool_use', 'part' => ['tool' => 'read', 'state' => [
            'status' => 'error', 'input' => ['filePath' => $path], 'error' => 'Permission denied',
        ]],
    ], [$runPath.'/model-a/src/../page.tsx', '/etc/private-file']);
    File::put($attemptPath.'/opencode.events.jsonl', implode("\n", array_map(fn ($event) => json_encode($event, JSON_THROW_ON_ERROR), $events)));

    try {
        expect(app(ModelRunBoundaryReport::class)->forAttempt($run, $model, $attempt)['blockedCount'])->toBe(1);
    } finally {
        File::deleteDirectory($root);
    }
});
