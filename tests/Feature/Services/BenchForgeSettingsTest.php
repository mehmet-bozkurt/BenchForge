<?php

use App\Models\BenchmarkExecution;
use App\Models\BenchmarkRun;
use App\Models\ModelRunAttempt;
use App\Models\PreparationComparison;
use App\Services\BenchForgeSettings;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

function withBenchForgeSettingsFixture(callable $test): void
{
    $directory = storage_path('framework/testing/settings-service-'.Str::uuid());
    $values = [
        'root_path' => $directory.'/benchmark',
        'node_binary' => $directory.'/bin/node',
        'shell_binary' => $directory.'/bin/zsh',
        'command_path' => $directory.'/commands:'.$directory.'/bin',
        'home_path' => $directory.'/home',
    ];

    foreach (['benchmark', 'other-benchmark'] as $root) {
        foreach (['prompts', 'config', 'runs', 'sablon', 'tools/browser-smoke'] as $folder) {
            File::ensureDirectoryExists($directory.'/'.$root.'/'.$folder, 0700, true);
        }

        foreach (['benchmark.zsh', 'resume-model.zsh', 'browser-smoke/check.mjs', 'select-opencode-session.mjs', 'extract-opencode-event-session.mjs', 'benchforge-model-policy.mjs'] as $script) {
            File::put($directory.'/'.$root.'/tools/'.$script, 'fixture');
        }
    }

    foreach (['bin', 'commands', 'home', 'other-home', 'private'] as $folder) {
        File::ensureDirectoryExists($directory.'/'.$folder, 0700, true);
    }

    foreach (['node', 'zsh'] as $binary) {
        File::put($directory.'/bin/'.$binary, "#!/bin/sh\nexit 0\n");
        chmod($directory.'/bin/'.$binary, 0700);
    }

    config()->set([
        'cache.default' => 'array',
        'benchforge.root_path' => $values['root_path'],
        'benchforge.runs_path' => $values['root_path'].'/runs',
        'benchforge.benchmark_script' => $values['root_path'].'/tools/benchmark.zsh',
        'benchforge.resume_script' => $values['root_path'].'/tools/resume-model.zsh',
        'benchforge.node_binary' => $values['node_binary'],
        'benchforge.shell_binary' => $values['shell_binary'],
        'benchforge.command_path' => $values['command_path'],
        'benchforge.home_path' => $values['home_path'],
        'benchforge.settings_path' => $directory.'/private/settings.json',
        'benchforge.opencode_db_path' => $directory.'/explicit-opencode.db',
        'benchforge.opencode_db_path_derived' => false,
    ]);

    try {
        $test(['directory' => $directory, 'values' => $values, 'path' => $directory.'/private/settings.json']);
    } finally {
        File::deleteDirectory($directory);
    }
}

test('it resolves command names against configured PATH without creating settings', function () {
    withBenchForgeSettingsFixture(function (array $fixture): void {
        config()->set('benchforge.node_binary', 'node');
        config()->set('benchforge.shell_binary', 'zsh');

        app(BenchForgeSettings::class)->apply();

        expect(app(BenchForgeSettings::class)->values())->toBe([
            ...$fixture['values'],
            'command_path' => $fixture['directory'].'/bin:'.$fixture['directory'].'/commands',
        ]);
        expect(File::exists($fixture['path']))->toBeFalse();
        expect(config('benchforge.opencode_db_path'))->toBe($fixture['directory'].'/explicit-opencode.db');
    });
});

test('it preserves unresolved names and root overrides so readiness can explain missing tools', function () {
    withBenchForgeSettingsFixture(function (array $fixture): void {
        config()->set([
            'benchforge.node_binary' => 'node',
            'benchforge.shell_binary' => 'zsh',
            'benchforge.command_path' => $fixture['directory'].'/commands',
            'benchforge.resume_script' => $fixture['directory'].'/custom-resume.zsh',
        ]);

        app(BenchForgeSettings::class)->apply();

        expect(config('benchforge.node_binary'))->toBe('node');
        expect(config('benchforge.shell_binary'))->toBe('zsh');
        expect(config('benchforge.resume_script'))->toBe($fixture['directory'].'/custom-resume.zsh');
        expect(File::exists($fixture['path']))->toBeFalse();
    });
});

test('it preserves a programmatic usage database override when no settings have been saved', function () {
    withBenchForgeSettingsFixture(function (array $fixture): void {
        config()->set('benchforge.opencode_db_path_derived', true);
        config()->set('benchforge.opencode_db_path', $fixture['directory'].'/custom-tracking.db');

        app(BenchForgeSettings::class)->apply();

        expect(config('benchforge.opencode_db_path'))->toBe($fixture['directory'].'/custom-tracking.db');
        expect(File::exists($fixture['path']))->toBeFalse();
    });
});

test('it saves only the allowlisted settings in a private versioned file', function () {
    withBenchForgeSettingsFixture(function (array $fixture): void {
        app(BenchForgeSettings::class)->save($fixture['values']);

        expect(json_decode(File::get($fixture['path']), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'version' => 1,
            ...$fixture['values'],
            'command_path' => $fixture['directory'].'/bin:'.$fixture['directory'].'/commands',
        ]);
        expect(fileperms($fixture['path']) & 0777)->toBe(0600);
    });
});

test('it applies saved paths together and follows HOME only for a derived usage database', function () {
    withBenchForgeSettingsFixture(function (array $fixture): void {
        $values = [...$fixture['values'], 'root_path' => $fixture['directory'].'/other-benchmark', 'home_path' => $fixture['directory'].'/other-home'];
        app(BenchForgeSettings::class)->save($values);
        config()->set('benchforge.opencode_db_path_derived', true);

        app(BenchForgeSettings::class)->apply();

        expect(config('benchforge.root_path'))->toBe($fixture['directory'].'/other-benchmark');
        expect(config('benchforge.runs_path'))->toBe($fixture['directory'].'/other-benchmark/runs');
        expect(config('benchforge.benchmark_script'))->toBe($fixture['directory'].'/other-benchmark/tools/benchmark.zsh');
        expect(config('benchforge.resume_script'))->toBe($fixture['directory'].'/other-benchmark/tools/resume-model.zsh');
        expect(config('benchforge.opencode_db_path'))->toBe($fixture['directory'].'/other-home/.local/share/opencode/opencode.db');
    });
});

test('it keeps an explicit usage database when saved HOME changes', function () {
    withBenchForgeSettingsFixture(function (array $fixture): void {
        app(BenchForgeSettings::class)->save([...$fixture['values'], 'home_path' => $fixture['directory'].'/other-home']);

        app(BenchForgeSettings::class)->apply();

        expect(config('benchforge.home_path'))->toBe($fixture['directory'].'/other-home');
        expect(config('benchforge.opencode_db_path'))->toBe($fixture['directory'].'/explicit-opencode.db');
    });
});

test('it leaves disappeared saved paths available for diagnosis instead of failing bootstrap', function () {
    withBenchForgeSettingsFixture(function (array $fixture): void {
        app(BenchForgeSettings::class)->save($fixture['values']);
        File::delete($fixture['values']['node_binary']);
        File::deleteDirectory($fixture['values']['root_path']);

        app(BenchForgeSettings::class)->apply();

        expect(config('benchforge.node_binary'))->toBe($fixture['values']['node_binary']);
        expect(config('benchforge.root_path'))->toBe($fixture['values']['root_path']);
    });
});

test('it fails closed for malformed saved settings without exposing their contents', function (callable $contents): void {
    withBenchForgeSettingsFixture(function (array $fixture) use ($contents): void {
        $raw = $contents(['version' => 1, ...$fixture['values']]);
        File::put($fixture['path'], $raw);
        $before = app(BenchForgeSettings::class)->values();

        expect(fn () => app(BenchForgeSettings::class)->apply())->toThrow(
            RuntimeException::class,
            'Kaydedilmiş sistem ayarları okunamıyor veya geçersiz. Ayar dosyasını düzeltmeden çalıştırmaya devam etmeyin.',
        );
        expect(app(BenchForgeSettings::class)->values())->toBe($before);
        expect(File::get($fixture['path']))->toBe($raw);
    });
})->with([
    'invalid JSON' => [fn (array $values): string => '{do-not-expose-private-value'],
    'unknown version' => [fn (array $values): string => json_encode([...$values, 'version' => 2])],
    'unknown field even when empty' => [fn (array $values): string => json_encode([...$values, 'api_key' => null])],
    'non-string field' => [fn (array $values): string => json_encode([...$values, 'home_path' => []])],
    'missing field' => [fn (array $values): string => json_encode(array_diff_key($values, ['shell_binary' => true]))],
    'relative executable' => [fn (array $values): string => json_encode([...$values, 'node_binary' => 'node'])],
    'empty PATH entry' => [fn (array $values): string => json_encode([...$values, 'command_path' => '/usr/bin:'])],
    'control character' => [fn (array $values): string => json_encode([...$values, 'home_path' => "/home\nprivate"])],
]);

test('it rejects invalid new settings before creating a settings file', function (callable $invalidValues): void {
    withBenchForgeSettingsFixture(function (array $fixture) use ($invalidValues): void {
        $values = $invalidValues($fixture['values']);

        expect(fn () => app(BenchForgeSettings::class)->save($values))->toThrow(RuntimeException::class);

        expect(File::exists($fixture['path']))->toBeFalse();
    });
})->with([
    'unexpected field' => [fn (array $values): array => [...$values, 'runner_path' => '/tmp/outside']],
    'missing field' => [fn (array $values): array => array_diff_key($values, ['shell_binary' => true])],
    'non-string value' => [fn (array $values): array => [...$values, 'home_path' => []]],
    'relative root' => [fn (array $values): array => [...$values, 'root_path' => 'benchmark']],
    'missing absolute directory' => [fn (array $values): array => [...$values, 'home_path' => $values['home_path'].'/missing']],
    'filesystem root' => [fn (array $values): array => [...$values, 'home_path' => '/']],
    'unresolved Node name' => [fn (array $values): array => [...$values, 'node_binary' => 'node']],
    'missing absolute executable' => [fn (array $values): array => [...$values, 'shell_binary' => $values['shell_binary'].'-missing']],
    'relative PATH entry' => [fn (array $values): array => [...$values, 'command_path' => './bin:/usr/bin']],
    'empty PATH entry' => [fn (array $values): array => [...$values, 'command_path' => '/usr/bin::/bin']],
    'control character in PATH' => [fn (array $values): array => [...$values, 'command_path' => "/usr/bin\n:/bin"]],
    'too long PATH' => [fn (array $values): array => [...$values, 'command_path' => '/'.str_repeat('a', 16384)]],
]);

test('it rejects an incomplete or linked benchmark layout', function (callable $damage): void {
    withBenchForgeSettingsFixture(function (array $fixture) use ($damage): void {
        $damage($fixture);

        expect(fn () => app(BenchForgeSettings::class)->save($fixture['values']))->toThrow(RuntimeException::class);

        expect(File::exists($fixture['path']))->toBeFalse();
    });
})->with([
    'missing template' => [fn (array $fixture): bool => File::deleteDirectory($fixture['values']['root_path'].'/sablon')],
    'missing resume script' => [fn (array $fixture): bool => File::delete($fixture['values']['root_path'].'/tools/resume-model.zsh')],
    'missing boundary policy' => [fn (array $fixture): bool => File::delete($fixture['values']['root_path'].'/tools/benchforge-model-policy.mjs')],
    'linked runs directory' => [function (array $fixture): void {
        File::deleteDirectory($fixture['values']['root_path'].'/runs');
        symlink($fixture['directory'].'/other-benchmark/runs', $fixture['values']['root_path'].'/runs');
    }],
]);

test('it refuses updates during every active lifecycle without reconciling or changing records', function (callable $activeRecord): void {
    withBenchForgeSettingsFixture(function (array $fixture) use ($activeRecord): void {
        app(BenchForgeSettings::class)->save($fixture['values']);
        $original = File::get($fixture['path']);
        $record = $activeRecord();
        $attributes = $record->refresh()->getAttributes();

        expect(app(BenchForgeSettings::class)->busy())->toBeTrue();
        expect(fn () => app(BenchForgeSettings::class)->save([...$fixture['values'], 'home_path' => $fixture['directory'].'/other-home']))->toThrow(
            RuntimeException::class,
            'Çalışan benchmark, kurtarma veya hazırlık tamamlanmadan sistem ayarları değiştirilemez.',
        );
        expect(File::get($fixture['path']))->toBe($original);
        expect($record->refresh()->getAttributes())->toBe($attributes);
    });
})->with([
    'initial benchmark' => [fn () => BenchmarkExecution::factory()->create(['status' => 'running'])],
    'recovery attempt' => [fn () => ModelRunAttempt::factory()->create(['status' => 'starting'])],
    'preparation comparison' => [fn () => PreparationComparison::factory()->create(['status' => 'stopping', 'finished_at' => null])],
]);

test('it refuses a different canonical root when benchmark history exists', function (callable $history): void {
    withBenchForgeSettingsFixture(function (array $fixture) use ($history): void {
        $history();

        expect(fn () => app(BenchForgeSettings::class)->save([...$fixture['values'], 'root_path' => $fixture['directory'].'/other-benchmark']))->toThrow(
            RuntimeException::class,
            'Geçmiş benchmark kayıtları varken kök veya sonuç klasörü değiştirilemez. Mevcut sonuçları taşımak ayrı bir işlem gerektirir.',
        );
        expect(File::exists($fixture['path']))->toBeFalse();
    });
})->with([
    'archived run' => [fn () => BenchmarkRun::factory()->create()],
    'completed execution without an archive' => [fn () => BenchmarkExecution::factory()->create(['status' => 'completed'])],
]);

test('it accepts the same physical root with history without relocating results', function () {
    withBenchForgeSettingsFixture(function (array $fixture): void {
        BenchmarkRun::factory()->create(['source_path' => $fixture['values']['root_path'].'/runs/old-run']);
        File::put($fixture['values']['root_path'].'/runs/keep.txt', 'unchanged');

        app(BenchForgeSettings::class)->save([...$fixture['values'], 'root_path' => $fixture['values']['root_path'].'/../benchmark']);

        expect(json_decode(File::get($fixture['path']), true)['root_path'])->toBe($fixture['values']['root_path']);
        expect(File::get($fixture['values']['root_path'].'/runs/keep.txt'))->toBe('unchanged');
    });
});

test('it rejects a different shell and a Node executable that differs from PATH', function (string $field, string $filename): void {
    withBenchForgeSettingsFixture(function (array $fixture) use ($field, $filename): void {
        $source = $fixture['values'][$field];
        $target = $fixture['directory'].'/bin/'.$filename;
        File::copy($source, $target);
        chmod($target, 0700);

        expect(fn () => app(BenchForgeSettings::class)->save([...$fixture['values'], $field => $target]))->toThrow(RuntimeException::class);

        expect(File::exists($fixture['path']))->toBeFalse();
    });
})->with([
    'bash instead of zsh' => ['shell_binary', 'bash'],
    'alternate Node executable' => ['node_binary', 'node-alternate'],
]);

test('it does not replace a legacy separate runs root when history exists', function () {
    withBenchForgeSettingsFixture(function (array $fixture): void {
        BenchmarkRun::factory()->create();
        config()->set('benchforge.runs_path', $fixture['directory'].'/other-benchmark/runs');

        expect(fn () => app(BenchForgeSettings::class)->save($fixture['values']))->toThrow(RuntimeException::class);

        expect(File::exists($fixture['path']))->toBeFalse();
        expect(config('benchforge.runs_path'))->toBe($fixture['directory'].'/other-benchmark/runs');
    });
});

test('it reloads the latest saved root inside lifecycle locks before checking history', function () {
    withBenchForgeSettingsFixture(function (array $fixture): void {
        $values = [...$fixture['values'], 'root_path' => $fixture['directory'].'/other-benchmark'];
        app(BenchForgeSettings::class)->save($values);
        BenchmarkRun::factory()->create(['source_path' => $values['root_path'].'/runs/current-run']);
        config()->set('benchforge.root_path', $fixture['values']['root_path']);

        app(BenchForgeSettings::class)->save([...$values, 'home_path' => $fixture['directory'].'/other-home']);

        expect(json_decode(File::get($fixture['path']), true)['home_path'])->toBe($fixture['directory'].'/other-home');
        expect(config('benchforge.root_path'))->toBe($values['root_path']);
    });
});

test('it serializes settings with runner and preparation locks in a fixed order', function () {
    withBenchForgeSettingsFixture(function (array $fixture): void {
        $store = Cache::store();
        Cache::shouldReceive('lock')->once()->with('benchforge:runner:start', 120)->ordered()->andReturn($store->lock('benchforge:runner:start', 120));
        Cache::shouldReceive('lock')->once()->with('benchforge:preparation:lifecycle', 120)->ordered()->andReturn($store->lock('benchforge:preparation:lifecycle', 120));

        app(BenchForgeSettings::class)->save($fixture['values']);

        expect(File::exists($fixture['path']))->toBeTrue();
    });
});

test('it rejects a linked settings file without touching its target', function () {
    withBenchForgeSettingsFixture(function (array $fixture): void {
        $target = $fixture['directory'].'/outside.json';
        File::put($target, 'keep this file');
        symlink($target, $fixture['path']);

        expect(fn () => app(BenchForgeSettings::class)->save($fixture['values']))->toThrow(RuntimeException::class);

        expect(File::get($target))->toBe('keep this file');
        expect(is_link($fixture['path']))->toBeTrue();
    });
});
