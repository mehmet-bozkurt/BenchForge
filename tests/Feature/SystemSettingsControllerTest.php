<?php

use App\Models\PreparationComparison;
use App\Services\BenchForgeSystemStatus;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

use function Pest\Laravel\mock;

uses(LazilyRefreshDatabase::class);

function withSystemSettingsFormFixture(callable $test): void
{
    $directory = storage_path('framework/testing/settings-controller-'.Str::uuid());
    $values = [
        'root_path' => $directory.'/benchmark',
        'node_binary' => $directory.'/bin/node',
        'shell_binary' => $directory.'/bin/zsh',
        'command_path' => $directory.'/bin',
        'home_path' => $directory.'/home',
    ];

    foreach (['prompts', 'config', 'runs', 'sablon', 'tools/browser-smoke'] as $folder) {
        File::ensureDirectoryExists($values['root_path'].'/'.$folder, 0700, true);
    }

    foreach (['benchmark.zsh', 'resume-model.zsh', 'browser-smoke/check.mjs', 'select-opencode-session.mjs', 'extract-opencode-event-session.mjs', 'benchforge-model-policy.mjs'] as $script) {
        File::put($values['root_path'].'/tools/'.$script, 'fixture');
    }

    foreach (['bin', 'home', 'private'] as $folder) {
        File::ensureDirectoryExists($directory.'/'.$folder, 0700, true);
    }

    foreach (['node', 'zsh'] as $binary) {
        File::put($directory.'/bin/'.$binary, "#!/bin/sh\nexit 0\n");
        chmod($directory.'/bin/'.$binary, 0700);
    }

    foreach ($values as $field => $value) {
        config()->set('benchforge.'.$field, $value);
    }

    config()->set([
        'cache.default' => 'array',
        'benchforge.runs_path' => $values['root_path'].'/runs',
        'benchforge.benchmark_script' => $values['root_path'].'/tools/benchmark.zsh',
        'benchforge.resume_script' => $values['root_path'].'/tools/resume-model.zsh',
        'benchforge.settings_path' => $directory.'/private/settings.json',
    ]);

    try {
        $test(['directory' => $directory, 'values' => $values, 'path' => $directory.'/private/settings.json']);
    } finally {
        File::deleteDirectory($directory);
    }
}

test('it renders local readiness and current settings without model calls', function () {
    withSystemSettingsFormFixture(function (array $fixture): void {
        $system = [
            'ready' => true,
            'checkedAt' => '2026-10-03T12:00:00+03:00',
            'summary' => 'Sistem kontrolleri geçti.',
            'checks' => [['key' => 'node', 'label' => 'Node.js', 'status' => 'pass', 'message' => 'v22.22.2']],
        ];
        mock(BenchForgeSystemStatus::class)->shouldReceive('inspect')->once()->andReturn($system);

        $this->get(route('system.index'))
            ->assertViewIs('system.index')
            ->assertViewHas('system', $system)
            ->assertViewHas('settings', $fixture['values'])
            ->assertViewHas('busy', false)
            ->assertSee('Node.js');
    });
});

test('it disables settings changes while showing an active preparation', function () {
    withSystemSettingsFormFixture(function (): void {
        PreparationComparison::factory()->create(['status' => 'running', 'finished_at' => null]);
        mock(BenchForgeSystemStatus::class)->shouldReceive('inspect')->once()->andReturn([
            'ready' => true,
            'checkedAt' => '2026-10-03T12:00:00+03:00',
            'summary' => 'Araçlar hazır.',
            'checks' => [],
        ]);

        $this->get(route('system.index'))
            ->assertViewHas('busy', true)
            ->assertSee('disabled', false);
    });
});

test('it escapes diagnostic text rather than rendering it as HTML', function () {
    withSystemSettingsFormFixture(function (): void {
        mock(BenchForgeSystemStatus::class)->shouldReceive('inspect')->once()->andReturn([
            'ready' => false,
            'checkedAt' => '2026-10-03T12:00:00+03:00',
            'summary' => 'Kontrol başarısız.',
            'checks' => [['key' => 'node', 'label' => 'Node.js', 'status' => 'fail', 'message' => '<script>alert("diagnostic")</script>']],
        ]);

        $this->get(route('system.index'))
            ->assertSee('&lt;script&gt;', false)
            ->assertDontSee('<script>alert("diagnostic")</script>', false);
    });
});

test('it saves valid local system settings and redirects without starting a run', function () {
    withSystemSettingsFormFixture(function (array $fixture): void {
        mock(BenchForgeSystemStatus::class)->shouldNotReceive('inspect');

        $this->put(route('system.update'), [...$fixture['values'], '_token' => 'fixture', '_method' => 'PUT'])
            ->assertRedirectToRoute('system.index')
            ->assertSessionHas('status', 'Sistem ayarları kaydedildi. Yeni işlemler bu ayarları kullanacak; mevcut dosyalar taşınmadı.');

        expect(json_decode(File::get($fixture['path']), true, flags: JSON_THROW_ON_ERROR))->toBe(['version' => 1, ...$fixture['values']]);
        $this->assertDatabaseCount('benchmark_executions', 0);
        $this->assertDatabaseCount('preparation_comparisons', 0);
    });
});

test('it returns 422 with clear messages when required settings are absent', function () {
    withSystemSettingsFormFixture(function (array $fixture): void {
        $this->putJson(route('system.update'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['root_path', 'node_binary', 'shell_binary', 'command_path', 'home_path'])
            ->assertJsonPath('errors.root_path.0', 'Bu sistem ayarı zorunludur.');

        expect(File::exists($fixture['path']))->toBeFalse();
    });
});

test('it returns 422 for unrecognized settings even when their value is empty', function () {
    withSystemSettingsFormFixture(function (array $fixture): void {
        $this->putJson(route('system.update'), [...$fixture['values'], 'settings_path' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['settings_path'])
            ->assertJsonPath('errors.settings_path.0', 'Bu sistem ayarı değiştirilemez.');

        expect(File::exists($fixture['path']))->toBeFalse();
    });
});

test('it returns 422 when a setting is not text', function () {
    withSystemSettingsFormFixture(function (array $fixture): void {
        $this->putJson(route('system.update'), [...$fixture['values'], 'node_binary' => ['unsafe']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['node_binary'])
            ->assertJsonPath('errors.node_binary.0', 'Sistem ayarı metin olmalıdır.');

        expect(File::exists($fixture['path']))->toBeFalse();
    });
});

test('it returns 422 when the command path exceeds the input limit', function () {
    withSystemSettingsFormFixture(function (array $fixture): void {
        $this->putJson(route('system.update'), [...$fixture['values'], 'command_path' => '/'.str_repeat('a', 16384)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['command_path'])
            ->assertJsonPath('errors.command_path.0', 'Sistem ayarı izin verilen uzunluğu aşıyor.');

        expect(File::exists($fixture['path']))->toBeFalse();
    });
});

test('it reports filesystem validation errors without creating settings or moving results', function () {
    withSystemSettingsFormFixture(function (array $fixture): void {
        $missing = $fixture['directory'].'/missing-root';

        $this->from(route('system.index'))->put(route('system.update'), [...$fixture['values'], 'root_path' => $missing])
            ->assertRedirectToRoute('system.index')
            ->assertSessionHasErrors(['settings' => 'Benchmark kök klasörü için mevcut, okunabilir ve mutlak bir klasör yolu girin.']);

        expect(File::exists($fixture['path']))->toBeFalse();
        expect(File::isDirectory($fixture['values']['root_path'].'/runs'))->toBeTrue();
    });
});

test('it refuses a settings update during an active preparation without reconciling it', function () {
    withSystemSettingsFormFixture(function (array $fixture): void {
        $comparison = PreparationComparison::factory()->create(['status' => 'running', 'finished_at' => null]);
        $before = $comparison->refresh()->getAttributes();

        $this->from(route('system.index'))->put(route('system.update'), $fixture['values'])
            ->assertRedirectToRoute('system.index')
            ->assertSessionHasErrors(['settings' => 'Çalışan benchmark, kurtarma veya hazırlık tamamlanmadan sistem ayarları değiştirilemez.']);

        expect(File::exists($fixture['path']))->toBeFalse();
        expect($comparison->refresh()->getAttributes())->toBe($before);
    });
});

test('it returns 403 for remote system inspection without running probes', function () {
    mock(BenchForgeSystemStatus::class)->shouldNotReceive('inspect');

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])->get(route('system.index'))
        ->assertForbidden();
});

test('it returns 403 for a remote settings update without writing a file', function () {
    withSystemSettingsFormFixture(function (array $fixture): void {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])->putJson(route('system.update'), $fixture['values'])
            ->assertForbidden();

        expect(File::exists($fixture['path']))->toBeFalse();
    });
});
