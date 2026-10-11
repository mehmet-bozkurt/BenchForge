<?php

use App\Services\BenchmarkPreflight;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

function preparePreflightTools(string $root): void
{
    File::ensureDirectoryExists($root.'/sablon');
    File::ensureDirectoryExists($root.'/tools/browser-smoke');

    foreach (['resume-model.zsh', 'browser-smoke/check.mjs', 'select-opencode-session.mjs', 'extract-opencode-event-session.mjs', 'benchforge-model-policy.mjs'] as $file) {
        File::put($root.'/tools/'.$file, "fixture\n");
    }

    File::put($root.'/sablon/package.json', "{}\n");
    config()->set('benchforge.engine_path', $root);
}

/** @return array{root: string, binPath: string} */
function portablePreflightFixture(): array
{
    $root = storage_path('framework/testing/portable-preflight-'.Str::uuid());
    $binPath = $root.'/bin';

    foreach (['prompts', 'config', 'runs', 'runner', 'bin'] as $directory) {
        File::ensureDirectoryExists($root.'/'.$directory);
    }

    preparePreflightTools($root);
    File::put($root.'/tools/benchmark.zsh', "#!/bin/zsh\n");

    foreach (['node', 'opencode', 'npm', 'rsync', 'curl', 'python3', 'shasum', 'zsh'] as $command) {
        $path = $binPath.'/'.$command;
        $version = $command === 'zsh' ? 'zsh 5.9' : $command.'-test';
        File::put($path, "#!/bin/sh\necho {$version}\n");
        chmod($path, 0755);
    }

    config()->set('benchforge.root_path', $root);
    config()->set('benchforge.runs_path', $root.'/runs');
    config()->set('benchforge.benchmark_script', $root.'/tools/benchmark.zsh');
    config()->set('benchforge.resume_script', $root.'/tools/resume-model.zsh');
    config()->set('benchforge.runner_path', $root.'/runner');
    config()->set('benchforge.node_binary', $binPath.'/node');
    config()->set('benchforge.shell_binary', $binPath.'/zsh');
    config()->set('benchforge.command_path', $binPath);
    config()->set('benchforge.home_path', $root);

    return compact('root', 'binPath');
}

test('it reports an occupied model port while the remaining local dependencies pass', function () {
    $root = storage_path('framework/testing/preflight-root-'.Str::uuid());
    $binPath = $root.'/bin';
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $socketName = stream_socket_get_name($socket, false);
    $port = (int) Str::afterLast((string) $socketName, ':');

    File::ensureDirectoryExists($root.'/prompts');
    File::ensureDirectoryExists($root.'/config');
    File::ensureDirectoryExists($root.'/runs');
    File::ensureDirectoryExists($root.'/tools');
    File::ensureDirectoryExists($root.'/runner');
    File::ensureDirectoryExists($binPath);
    File::put($root.'/tools/benchmark.zsh', "#!/bin/zsh\n");
    preparePreflightTools($root);

    foreach (['node', 'opencode', 'npm', 'rsync', 'curl', 'python3', 'shasum'] as $command) {
        $path = $binPath.'/'.$command;
        File::put($path, "#!/bin/sh\necho {$command}-test\n");
        chmod($path, 0755);
    }

    config()->set('benchforge.root_path', $root);
    config()->set('benchforge.runs_path', $root.'/runs');
    config()->set('benchforge.benchmark_script', $root.'/tools/benchmark.zsh');
    config()->set('benchforge.resume_script', $root.'/tools/resume-model.zsh');
    config()->set('benchforge.shell_binary', '/bin/zsh');
    config()->set('benchforge.runner_path', $root.'/runner');
    config()->set('benchforge.node_binary', $binPath.'/node');
    config()->set('benchforge.command_path', $binPath);
    config()->set('benchforge.home_path', $root);

    try {
        $preflight = app(BenchmarkPreflight::class)->inspect([$port]);
        $checks = collect($preflight['checks'])->keyBy('key');

        expect($preflight['ready'])->toBeFalse()
            ->and($checks['database']['status'])->toBe('pass')
            ->and($checks['node']['status'])->toBe('pass')
            ->and($checks['opencode']['status'])->toBe('pass')
            ->and($checks['commands']['status'])->toBe('pass')
            ->and($checks['ports'])->toMatchArray([
                'status' => 'fail',
                'message' => "Kullanımda: {$port}",
            ]);
    } finally {
        fclose($socket);
        File::deleteDirectory($root);
    }
});

test('it verifies the OpenRouter credential only for OpenRouter model groups', function () {
    $root = storage_path('framework/testing/preflight-openrouter-'.Str::uuid());
    $binPath = $root.'/bin';

    File::ensureDirectoryExists($root.'/prompts');
    File::ensureDirectoryExists($root.'/config');
    File::ensureDirectoryExists($root.'/runs');
    File::ensureDirectoryExists($root.'/tools');
    File::ensureDirectoryExists($root.'/runner');
    File::ensureDirectoryExists($binPath);
    File::put($root.'/tools/benchmark.zsh', "#!/bin/zsh\n");
    preparePreflightTools($root);

    foreach (['node', 'npm', 'rsync', 'curl', 'python3', 'shasum'] as $command) {
        $path = $binPath.'/'.$command;
        File::put($path, "#!/bin/sh\necho {$command}-test\n");
        chmod($path, 0755);
    }

    File::put($binPath.'/opencode', "#!/bin/sh\nif [ \"\$1 \$2\" = \"auth list\" ]; then echo OpenRouter; else echo 1.18.31; fi\n");
    chmod($binPath.'/opencode', 0755);

    config()->set('benchforge.root_path', $root);
    config()->set('benchforge.runs_path', $root.'/runs');
    config()->set('benchforge.benchmark_script', $root.'/tools/benchmark.zsh');
    config()->set('benchforge.resume_script', $root.'/tools/resume-model.zsh');
    config()->set('benchforge.shell_binary', '/bin/zsh');
    config()->set('benchforge.runner_path', $root.'/runner');
    config()->set('benchforge.node_binary', $binPath.'/node');
    config()->set('benchforge.command_path', $binPath);
    config()->set('benchforge.home_path', $root);

    try {
        $preflight = app(BenchmarkPreflight::class)->inspect([49152], ['openrouter']);
        $checks = collect($preflight['checks'])->keyBy('key');

        expect($preflight['ready'])->toBeTrue()
            ->and($checks['openrouter'])->toMatchArray([
                'status' => 'pass',
                'message' => 'OpenCode kimlik deposunda bağlantı hazır.',
            ]);
    } finally {
        File::deleteDirectory($root);
    }
});

test('it blocks an OpenRouter model group when the credential is missing', function () {
    $root = storage_path('framework/testing/preflight-openrouter-missing-'.Str::uuid());
    $binPath = $root.'/bin';

    File::ensureDirectoryExists($root.'/prompts');
    File::ensureDirectoryExists($root.'/config');
    File::ensureDirectoryExists($root.'/runs');
    File::ensureDirectoryExists($root.'/tools');
    File::ensureDirectoryExists($root.'/runner');
    File::ensureDirectoryExists($binPath);
    File::put($root.'/tools/benchmark.zsh', "#!/bin/zsh\n");
    preparePreflightTools($root);

    foreach (['node', 'npm', 'rsync', 'curl', 'python3', 'shasum'] as $command) {
        $path = $binPath.'/'.$command;
        File::put($path, "#!/bin/sh\necho {$command}-test\n");
        chmod($path, 0755);
    }

    File::put($binPath.'/opencode', "#!/bin/sh\nif [ \"\$1 \$2\" = \"auth list\" ]; then echo 'OpenCode Go'; else echo 1.18.31; fi\n");
    chmod($binPath.'/opencode', 0755);

    config()->set('benchforge.root_path', $root);
    config()->set('benchforge.runs_path', $root.'/runs');
    config()->set('benchforge.benchmark_script', $root.'/tools/benchmark.zsh');
    config()->set('benchforge.resume_script', $root.'/tools/resume-model.zsh');
    config()->set('benchforge.shell_binary', '/bin/zsh');
    config()->set('benchforge.runner_path', $root.'/runner');
    config()->set('benchforge.node_binary', $binPath.'/node');
    config()->set('benchforge.command_path', $binPath);
    config()->set('benchforge.home_path', $root);

    try {
        $preflight = app(BenchmarkPreflight::class)->inspect([49153], ['openrouter']);
        $checks = collect($preflight['checks'])->keyBy('key');

        expect($preflight['ready'])->toBeFalse()
            ->and($checks['openrouter'])->toMatchArray([
                'status' => 'fail',
                'message' => 'Kimlik bulunamadı. OpenCode içinde /connect ile OpenRouter bağlayın.',
            ]);
    } finally {
        File::deleteDirectory($root);
    }
});

test('it requires the OpenCode Go credential for OpenCode Go model groups', function () {
    $root = storage_path('framework/testing/preflight-opencode-go-'.Str::uuid());
    $binPath = $root.'/bin';

    File::ensureDirectoryExists($root.'/prompts');
    File::ensureDirectoryExists($root.'/config');
    File::ensureDirectoryExists($root.'/runs');
    File::ensureDirectoryExists($root.'/tools');
    File::ensureDirectoryExists($root.'/runner');
    File::ensureDirectoryExists($binPath);
    File::put($root.'/tools/benchmark.zsh', "#!/bin/zsh\n");
    preparePreflightTools($root);

    foreach (['node', 'npm', 'rsync', 'curl', 'python3', 'shasum'] as $command) {
        $path = $binPath.'/'.$command;
        File::put($path, "#!/bin/sh\necho {$command}-test\n");
        chmod($path, 0755);
    }

    File::put($binPath.'/opencode', "#!/bin/sh\nif [ \"\$1 \$2\" = \"auth list\" ]; then echo 'OpenCode Go'; else echo 1.18.31; fi\n");
    chmod($binPath.'/opencode', 0755);

    config()->set('benchforge.root_path', $root);
    config()->set('benchforge.runs_path', $root.'/runs');
    config()->set('benchforge.benchmark_script', $root.'/tools/benchmark.zsh');
    config()->set('benchforge.resume_script', $root.'/tools/resume-model.zsh');
    config()->set('benchforge.shell_binary', '/bin/zsh');
    config()->set('benchforge.runner_path', $root.'/runner');
    config()->set('benchforge.node_binary', $binPath.'/node');
    config()->set('benchforge.command_path', $binPath);
    config()->set('benchforge.home_path', $root);

    try {
        $preflight = app(BenchmarkPreflight::class)->inspect([49154], ['opencode-go']);
        $checks = collect($preflight['checks'])->keyBy('key');

        expect($preflight['ready'])->toBeTrue()
            ->and($checks['opencode-go'])->toMatchArray([
                'status' => 'pass',
                'message' => 'OpenCode kimlik deposunda bağlantı hazır.',
            ]);
    } finally {
        File::deleteDirectory($root);
    }
});

test('it rejects a result directory that differs from the benchmark root', function () {
    ['root' => $root] = portablePreflightFixture();
    File::ensureDirectoryExists($root.'/other-runs');
    config()->set('benchforge.runs_path', $root.'/other-runs');

    try {
        $checks = collect(app(BenchmarkPreflight::class)->inspect([])['checks'])->keyBy('key');

        expect($checks['project'])->toMatchArray([
            'status' => 'fail',
            'message' => 'Sonuç klasörü benchmark kökündeki runs klasörüyle eşleşmiyor.',
        ]);
    } finally {
        File::deleteDirectory($root);
    }
});

test('it rejects an incomplete benchmark tool installation', function (string $file) {
    ['root' => $root] = portablePreflightFixture();
    File::delete($root.'/'.$file);

    try {
        $checks = collect(app(BenchmarkPreflight::class)->inspect([])['checks'])->keyBy('key');

        expect($checks['project'])->toMatchArray([
            'status' => 'fail',
            'message' => basename($file).' bulunamadı veya okunamıyor.',
        ]);
    } finally {
        File::deleteDirectory($root);
    }
})->with([
    'initial benchmark' => 'tools/benchmark.zsh',
    'recovery' => 'tools/resume-model.zsh',
    'browser runtime checker' => 'tools/browser-smoke/check.mjs',
    'session selection' => 'tools/select-opencode-session.mjs',
    'event session extraction' => 'tools/extract-opencode-event-session.mjs',
    'model policy' => 'tools/benchforge-model-policy.mjs',
]);

test('it rejects a benchmark installation without its template', function () {
    ['root' => $root] = portablePreflightFixture();
    File::deleteDirectory($root.'/sablon');

    try {
        $checks = collect(app(BenchmarkPreflight::class)->inspect([])['checks'])->keyBy('key');

        expect($checks['project'])->toMatchArray([
            'status' => 'fail',
            'message' => 'sablon klasörü bulunamadı.',
        ]);
    } finally {
        File::deleteDirectory($root);
    }
});

test('it rejects an unwritable results directory even when runner storage is writable', function () {
    ['root' => $root] = portablePreflightFixture();
    chmod($root.'/runs', 0555);

    try {
        $checks = collect(app(BenchmarkPreflight::class)->inspect([])['checks'])->keyBy('key');

        expect($checks['storage'])->toMatchArray([
            'status' => 'fail',
            'message' => 'Sonuç klasörüne yazma izni yok.',
        ]);
    } finally {
        chmod($root.'/runs', 0755);
        File::deleteDirectory($root);
    }
});

test('it rejects a PATH Node.js that differs from the configured launcher', function () {
    ['root' => $root, 'binPath' => $binPath] = portablePreflightFixture();
    File::put($binPath.'/other-node', "#!/bin/sh\necho alternative-node\n");
    chmod($binPath.'/other-node', 0755);
    config()->set('benchforge.node_binary', $binPath.'/other-node');

    try {
        $checks = collect(app(BenchmarkPreflight::class)->inspect([])['checks'])->keyBy('key');

        expect($checks['node'])->toMatchArray([
            'status' => 'fail',
            'message' => 'Node.js seçimi ile komut yolundaki Node.js eşleşmiyor. Sistem ayarlarını kontrol edin.',
        ]);
    } finally {
        File::deleteDirectory($root);
    }
});

test('it recognizes the same Node.js through a symbolic link on PATH', function () {
    ['root' => $root, 'binPath' => $binPath] = portablePreflightFixture();
    File::move($binPath.'/node', $binPath.'/actual-node');
    symlink($binPath.'/actual-node', $binPath.'/node');
    config()->set('benchforge.node_binary', $binPath.'/actual-node');

    try {
        $checks = collect(app(BenchmarkPreflight::class)->inspect([])['checks'])->keyBy('key');

        expect($checks['node']['status'])->toBe('pass');
    } finally {
        File::deleteDirectory($root);
    }
});

test('it resolves the configured zsh command through PATH', function () {
    ['root' => $root] = portablePreflightFixture();
    config()->set('benchforge.shell_binary', 'zsh');

    try {
        $checks = collect(app(BenchmarkPreflight::class)->inspect([])['checks'])->keyBy('key');

        expect($checks['process_control']['status'])->toBe('pass');
    } finally {
        File::deleteDirectory($root);
    }
});

test('it rejects an unavailable configured shell without using a fallback', function () {
    ['root' => $root] = portablePreflightFixture();
    config()->set('benchforge.shell_binary', $root.'/missing-zsh');

    try {
        $checks = collect(app(BenchmarkPreflight::class)->inspect([])['checks'])->keyBy('key');

        expect($checks['process_control']['status'])->toBe('fail');
    } finally {
        File::deleteDirectory($root);
    }
});

test('it rejects an executable shell whose version does not identify zsh', function () {
    ['root' => $root, 'binPath' => $binPath] = portablePreflightFixture();
    File::put($binPath.'/zsh', "#!/bin/sh\necho 'GNU bash, version 5.2'\n");

    try {
        $checks = collect(app(BenchmarkPreflight::class)->inspect([])['checks'])->keyBy('key');

        expect($checks['process_control'])->toMatchArray([
            'status' => 'fail',
            'message' => 'Yapılandırılan shell çalıştırıcısı zsh olarak doğrulanamadı.',
        ]);
    } finally {
        File::deleteDirectory($root);
    }
});

test('it does not reveal database exception details in readiness messages', function () {
    ['root' => $root] = portablePreflightFixture();
    DB::shouldReceive('connection')->once()->andThrow(new RuntimeException('secret-password-do-not-show'));

    try {
        $checks = collect(app(BenchmarkPreflight::class)->inspect([])['checks'])->keyBy('key');

        expect($checks['database'])->toMatchArray([
            'status' => 'fail',
            'message' => 'Bağlantı kurulamadı. Veritabanı yapılandırmasını ve erişim izinlerini kontrol edin.',
        ]);
        expect(json_encode($checks))->not->toContain('secret-password-do-not-show');
    } finally {
        File::deleteDirectory($root);
    }
});
