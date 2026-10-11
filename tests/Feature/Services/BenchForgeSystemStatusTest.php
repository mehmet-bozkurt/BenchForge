<?php

use App\Services\BenchForgeSystemStatus;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

function createSystemStatusFixture(): string
{
    $root = storage_path('framework/testing/system-status-'.Str::uuid());

    foreach (['prompts', 'config', 'runs', 'sablon', 'bin', 'tools/browser-smoke/node_modules/playwright', 'private'] as $directory) {
        File::ensureDirectoryExists($root.'/'.$directory);
    }

    foreach (['tools/benchmark.zsh', 'tools/resume-model.zsh', 'tools/browser-smoke/check.mjs', 'tools/select-opencode-session.mjs', 'tools/extract-opencode-event-session.mjs', 'tools/benchforge-model-policy.mjs', 'tools/browser-smoke/node_modules/playwright/package.json', 'sablon/package.json'] as $file) {
        File::put($root.'/'.$file, '{}');
    }

    foreach (['node', 'zsh', 'npm', 'opencode', 'rsync', 'curl', 'python3', 'shasum'] as $command) {
        File::put($root.'/bin/'.$command, "#!/bin/sh\nexit 99\n");
        chmod($root.'/bin/'.$command, 0755);
    }

    config()->set('benchforge.engine_path', $root);
    config()->set('benchforge.root_path', $root);
    config()->set('benchforge.runs_path', $root.'/runs');
    config()->set('benchforge.runner_path', $root.'/private/runner');
    config()->set('benchforge.preparation_path', $root.'/private/preparations');
    config()->set('benchforge.settings_path', $root.'/private/system/settings.json');
    config()->set('benchforge.node_binary', $root.'/bin/node');
    config()->set('benchforge.shell_binary', $root.'/bin/zsh');
    config()->set('benchforge.command_path', $root.'/bin');
    config()->set('benchforge.home_path', $root);

    return $root;
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function systemVersionFakes(string $root, array $overrides = []): array
{
    $versions = array_replace([
        'node' => 'v22.22.2', 'zsh' => 'zsh 5.9', 'npm' => '10.9.4',
        'opencode' => '1.18.31', 'rsync' => 'rsync version 2.6.9',
        'curl' => 'curl 8.7.1', 'python3' => 'Python 3.14.0', 'shasum' => '6.02',
    ], $overrides);
    $responses = [];

    foreach ($versions as $command => $output) {
        $responses['*'.$root.'/bin/'.$command.'*--version*'] = $output;
    }

    return $responses;
}

test('it reports local readiness using only version probes and keeps isolation unverified', function () {
    $root = createSystemStatusFixture();
    Process::fake(systemVersionFakes($root));
    Process::preventStrayProcesses();

    try {
        $result = app(BenchForgeSystemStatus::class)->inspect();
        $checks = collect($result['checks'])->keyBy('key');

        expect($result['ready'])->toBeTrue();
        expect($checks['node'])->toMatchArray(['status' => 'pass', 'message' => 'Sürüm: 22.22.2', 'detail' => $root.'/bin/node']);
        expect($checks['node_path']['status'])->toBe('pass');
        expect($checks['isolation']['status'])->toBe('warning');
        expect($checks['playwright']['status'])->toBe('warning');
        Process::assertRanTimes(fn ($process): bool => true, 8);
        Process::assertRan(fn ($process): bool => $process->command === [$root.'/bin/node', '--version']
            && $process->timeout === 3 && $process->environment['HOME'] === $root
            && $process->environment['PATH'] === $root.'/bin'
            && ($process->environment['APP_KEY'] ?? false) === false);
    } finally {
        File::deleteDirectory($root);
    }
});

test('it rejects a missing helper before considering the install ready', function () {
    $root = createSystemStatusFixture();
    File::delete($root.'/tools/resume-model.zsh');
    Process::fake(systemVersionFakes($root));
    Process::preventStrayProcesses();

    try {
        $result = app(BenchForgeSystemStatus::class)->inspect();

        expect($result['ready'])->toBeFalse();
        expect(collect($result['checks'])->keyBy('key')['project'])->toMatchArray([
            'status' => 'fail', 'message' => 'Eksik veya okunamayan: engine/tools/resume-model.zsh',
        ]);
        Process::assertRanTimes(fn ($process): bool => true, 8);
    } finally {
        File::deleteDirectory($root);
    }
});

test('it reports incomplete migrations despite a working database connection', function () {
    $root = createSystemStatusFixture();
    Schema::drop('preparation_packages');
    Process::fake(systemVersionFakes($root));
    Process::preventStrayProcesses();

    try {
        $result = app(BenchForgeSystemStatus::class)->inspect();

        expect($result['ready'])->toBeFalse();
        expect(collect($result['checks'])->keyBy('key')['database'])->toMatchArray([
            'status' => 'fail',
            'message' => 'Bağlantı hazır; uygulama tabloları eksik. Yerel veritabanı kurulumunu (migration) tamamlayın.',
        ]);
        Process::assertRanTimes(fn ($process): bool => true, 8);
    } finally {
        File::deleteDirectory($root);
    }
});

test('it rejects an unrelated results directory', function () {
    $root = createSystemStatusFixture();
    File::ensureDirectoryExists($root.'/other-runs');
    config()->set('benchforge.runs_path', $root.'/other-runs');
    Process::fake(systemVersionFakes($root));
    Process::preventStrayProcesses();

    try {
        $result = app(BenchForgeSystemStatus::class)->inspect();

        expect($result['ready'])->toBeFalse();
        expect(collect($result['checks'])->keyBy('key')['results']['status'])->toBe('fail');
        Process::assertRanTimes(fn ($process): bool => true, 8);
    } finally {
        File::deleteDirectory($root);
    }
});

test('it reports a missing executable without invoking it', function () {
    $root = createSystemStatusFixture();
    File::delete($root.'/bin/opencode');
    Process::fake(systemVersionFakes($root));
    Process::preventStrayProcesses();

    try {
        $result = app(BenchForgeSystemStatus::class)->inspect();

        expect($result['ready'])->toBeFalse();
        expect(collect($result['checks'])->keyBy('key')['opencode']['status'])->toBe('fail');
        Process::assertRanTimes(fn ($process): bool => true, 7);
    } finally {
        File::deleteDirectory($root);
    }
});

test('it rejects a different Node executable in the command path', function () {
    $root = createSystemStatusFixture();
    File::ensureDirectoryExists($root.'/selected');
    File::put($root.'/selected/node', "#!/bin/sh\nexit 99\n");
    chmod($root.'/selected/node', 0755);
    config()->set('benchforge.node_binary', $root.'/selected/node');
    Process::fake([
        ...systemVersionFakes($root),
        '*'.$root.'/selected/node*--version*' => 'v22.22.2',
    ]);
    Process::preventStrayProcesses();

    try {
        $result = app(BenchForgeSystemStatus::class)->inspect();

        expect($result['ready'])->toBeFalse();
        expect(collect($result['checks'])->keyBy('key')['node_path']['status'])->toBe('fail');
        Process::assertRanTimes(fn ($process): bool => true, 8);
    } finally {
        File::deleteDirectory($root);
    }
});

test('it reports missing Playwright dependencies without starting a browser', function () {
    $root = createSystemStatusFixture();
    File::delete($root.'/tools/browser-smoke/node_modules/playwright/package.json');
    Process::fake(systemVersionFakes($root));
    Process::preventStrayProcesses();

    try {
        $result = app(BenchForgeSystemStatus::class)->inspect();

        expect($result['ready'])->toBeFalse();
        expect(collect($result['checks'])->keyBy('key')['playwright']['status'])->toBe('fail');
        Process::assertRanTimes(fn ($process): bool => true, 8);
    } finally {
        File::deleteDirectory($root);
    }
});

test('it checks the supported Node range for the installed frontend toolchain', function (string $version, string $status) {
    $root = createSystemStatusFixture();
    Process::fake(systemVersionFakes($root, ['node' => $version]));
    Process::preventStrayProcesses();

    try {
        $result = app(BenchForgeSystemStatus::class)->inspect();

        expect(collect($result['checks'])->keyBy('key')['node']['status'])->toBe($status);
        Process::assertRanTimes(fn ($process): bool => true, 8);
    } finally {
        File::deleteDirectory($root);
    }
})->with([
    'too old on 20' => ['v20.18.0', 'fail'],
    'unsupported 21' => ['v21.7.0', 'fail'],
    'too old on 22' => ['v22.11.0', 'fail'],
    'supported 20' => ['v20.19.0', 'pass'],
    'supported 22' => ['v22.12.0', 'pass'],
    'supported later' => ['v24.1.0', 'pass'],
]);

test('it hides raw command failure output and marks unknown OpenCode majors for review', function () {
    $root = createSystemStatusFixture();
    Process::fake(systemVersionFakes($root, [
        'npm' => Process::result(output: 'sensitive-value', errorOutput: 'sensitive-error', exitCode: 1),
        'opencode' => '2.0.0',
    ]));
    Process::preventStrayProcesses();

    try {
        $result = app(BenchForgeSystemStatus::class)->inspect();
        $checks = collect($result['checks'])->keyBy('key');

        expect($checks['npm'])->toMatchArray(['status' => 'fail', 'message' => 'Sürüm bilgisi doğrulanamadı.']);
        expect($checks['opencode']['status'])->toBe('warning');
        expect(json_encode($result))->not->toContain('sensitive-value', 'sensitive-error');
        Process::assertRanTimes(fn ($process): bool => true, 8);
    } finally {
        File::deleteDirectory($root);
    }
});

test('it rejects a non zsh shell even when it returns a valid numeric version', function () {
    $root = createSystemStatusFixture();
    Process::fake(systemVersionFakes($root, ['zsh' => 'GNU bash, version 5.2.0']));
    Process::preventStrayProcesses();

    try {
        $result = app(BenchForgeSystemStatus::class)->inspect();

        expect($result['ready'])->toBeFalse();
        expect(collect($result['checks'])->keyBy('key')['zsh'])->toMatchArray([
            'status' => 'fail',
            'message' => 'Seçilen çalıştırıcı zsh değil. Benchmark betikleri başka bir kabukla çalıştırılamaz.',
        ]);
        Process::assertRanTimes(fn ($process): bool => true, 8);
    } finally {
        File::deleteDirectory($root);
    }
});
