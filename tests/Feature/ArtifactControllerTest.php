<?php

use App\Models\Artifact;
use App\Models\BenchmarkRun;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

test('it safely previews a log inside the configured runs directory', function () {
    $runsRoot = storage_path('framework/testing/artifact-runs-'.Str::uuid());
    $logPath = $runsRoot.'/nova-marketplace-v1/_logs/runtime.log';

    File::ensureDirectoryExists(dirname($logPath));
    File::put($logPath, "CONSOLE ERROR\n<script>alert('xss')</script>");
    config()->set('benchforge.runs_path', $runsRoot);

    try {
        $run = BenchmarkRun::factory()->create(['slug' => 'nova-marketplace-v1']);
        $artifact = Artifact::factory()->for($run)->create([
            'artifact_key' => 'run:log:runtime',
            'kind' => 'file',
            'label' => 'Runtime logu',
            'path' => $logPath,
        ]);

        $response = $this->get(route('runs.artifacts.show', [$run, $artifact]));

        $response
            ->assertOk()
            ->assertSee('Runtime logu')
            ->assertSee('CONSOLE ERROR')
            ->assertSee('&lt;script&gt;alert', escape: false)
            ->assertDontSee("<script>alert('xss')</script>", escape: false);
    } finally {
        File::deleteDirectory($runsRoot);
    }
});

test('it refuses to preview files outside the configured runs directory', function () {
    $runsRoot = storage_path('framework/testing/artifact-runs-'.Str::uuid());
    File::ensureDirectoryExists($runsRoot);
    config()->set('benchforge.runs_path', $runsRoot);

    try {
        $run = BenchmarkRun::factory()->create();
        $artifact = Artifact::factory()->for($run)->create([
            'kind' => 'file',
            'path' => base_path('composer.json'),
        ]);

        $this->get(route('runs.artifacts.show', [$run, $artifact]))->assertNotFound();
    } finally {
        File::deleteDirectory($runsRoot);
    }
});

test('an artifact cannot be opened through another benchmark run', function () {
    $firstRun = BenchmarkRun::factory()->create();
    $secondRun = BenchmarkRun::factory()->create();
    $artifact = Artifact::factory()->for($secondRun)->create();

    $this->get(route('runs.artifacts.show', [$firstRun, $artifact]))->assertNotFound();
});
