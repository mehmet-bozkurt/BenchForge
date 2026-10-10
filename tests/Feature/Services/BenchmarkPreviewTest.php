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
            ->assertHeader('Content-Security-Policy', 'sandbox allow-scripts')
            ->assertHeaderMissing('Set-Cookie')
            ->assertSee($previewRoot.'/_next/app.css', escape: false)
            ->assertSee($previewRoot.'/cart/', escape: false);

        $this->get($previewRoot.'/_next/app.css')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/css; charset=UTF-8')
            ->assertHeader('Content-Security-Policy', 'sandbox allow-scripts')
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

test('sandboxed previews may load their own fonts and RSC payloads without credentials', function () {
    $runsRoot = storage_path('framework/testing/preview-cors-'.Str::uuid());
    config()->set('benchforge.runs_path', $runsRoot);

    try {
        $run = BenchmarkRun::factory()->create(['slug' => 'cors-run']);
        $modelRun = ModelRun::factory()->for($run)->create(['slug' => 'cors-model']);
        $previewPath = $runsRoot.'/'.$run->slug.'/_gallery/'.$modelRun->slug;
        File::ensureDirectoryExists($previewPath.'/_next/static/media');
        File::put($previewPath.'/index.html', '<h1>Preview</h1>');
        File::put($previewPath.'/index.txt', 'rsc');
        File::put($previewPath.'/_next/static/media/font.woff2', 'font');
        $previewRoot = route('runs.previews.show', [$run, $modelRun], absolute: false);

        $this->get($previewRoot.'/_next/static/media/font.woff2', ['Origin' => 'null'])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');

        $this->call('OPTIONS', $previewRoot.'/index.txt', server: [
            'HTTP_ORIGIN' => 'null',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'rsc,next-router-state-tree',
        ])
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeader('Access-Control-Allow-Headers', 'rsc,next-router-state-tree');

        $this->get(route('dashboard'), ['Origin' => 'null'])
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    } finally {
        File::deleteDirectory($runsRoot);
    }
});
