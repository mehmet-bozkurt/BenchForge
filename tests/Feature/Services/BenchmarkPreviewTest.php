<?php

use App\Models\BenchmarkRun;
use App\Models\ModelRun;
use App\Services\BenchmarkPreview;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

test('it serves and rewrites static preview assets from the configured runs directory', function () {
    $runsRoot = storage_path('framework/testing/preview-assets-'.Str::uuid());
    config()->set('benchforge.runs_path', $runsRoot);

    try {
        $run = BenchmarkRun::factory()->create(['slug' => 'aeron-commerce-v1']);
        $modelRun = ModelRun::factory()->for($run)->create(['slug' => 'longcat-2.0']);
        $previewPath = $runsRoot.'/'.$run->slug.'/_gallery/'.$modelRun->slug;

        File::ensureDirectoryExists($previewPath.'/_next');
        File::put($previewPath.'/index.html', '<link href="/longcat-2.0/_next/app.css"><a href="/longcat-2.0/cart/">Cart</a>');
        File::put($previewPath.'/_next/app.css', 'body { color: black; }');

        $previewRoot = route('runs.previews.show', [$run, $modelRun], absolute: false);

        $this->get($previewRoot)
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeaderMissing('Set-Cookie')
            ->assertSee($previewRoot.'/_next/app.css', escape: false)
            ->assertSee($previewRoot.'/cart/', escape: false);

        $this->get($previewRoot.'/_next/app.css')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/css; charset=UTF-8')
            ->assertSee('color: black', escape: false);
    } finally {
        File::deleteDirectory($runsRoot);
    }
});

test('it refuses preview paths that escape the model export directory', function () {
    $runsRoot = storage_path('framework/testing/preview-escape-'.Str::uuid());
    config()->set('benchforge.runs_path', $runsRoot);

    try {
        $run = BenchmarkRun::factory()->make(['slug' => 'nova-marketplace-v1']);
        $modelRun = ModelRun::factory()->for($run)->make(['slug' => 'minimax-m3']);
        $previewPath = $runsRoot.'/'.$run->slug.'/'.$modelRun->slug.'/out';

        File::ensureDirectoryExists($previewPath);
        File::put($previewPath.'/index.html', '<h1>Preview</h1>');
        File::put($runsRoot.'/'.$run->slug.'/secret.txt', 'secret');

        $resolvedPath = app(BenchmarkPreview::class)->resolveFile($run, $modelRun, '../../secret.txt');

        expect($resolvedPath)->toBeNull();
    } finally {
        File::deleteDirectory($runsRoot);
    }
});
