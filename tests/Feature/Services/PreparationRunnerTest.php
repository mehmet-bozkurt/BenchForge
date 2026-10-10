<?php

use App\Exceptions\BenchmarkRunnerException;
use App\Models\OpenRouterModel;
use App\Models\PreparationComparison;
use App\Models\PreparationPackage;
use App\Services\BenchForgeSettings;
use App\Services\BenchmarkProcessEnvironment;
use App\Services\OpenCodeGoCatalog;
use App\Services\OpenRouterCatalog;
use App\Services\OpenRouterProviderRouting;
use App\Services\PreparationContract;
use App\Services\PreparationRunner;
use App\Services\ProviderConnectionStatus;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

use function Pest\Laravel\mock;

uses(LazilyRefreshDatabase::class);

test('it preserves a terminal comparison when its private directory no longer exists', function () {
    Http::preventStrayRequests();
    $comparison = PreparationComparison::factory()->create([
        'status' => 'completed',
        'working_directory' => '/private/tmp/benchforge-missing-'.Str::uuid(),
    ]);
    $package = PreparationPackage::factory()->for($comparison)->create([
        'status' => 'completed',
        'requirements' => 'Korunacak sonuç',
        'reviewed_at' => now(),
    ]);

    $result = app(PreparationRunner::class)->reconcile($comparison);

    expect($result->status)->toBe('completed')
        ->and($result->packages)->toHaveCount(1)
        ->and($package->refresh()->requirements)->toBe('Korunacak sonuç')
        ->and($package->status)->toBe('completed');
    Http::assertNothingSent();
});

test('it fails an active comparison without following an unsafe working path', function () {
    Http::preventStrayRequests();
    $root = storage_path('framework/testing/preparation-unsafe-'.Str::uuid());
    File::ensureDirectoryExists($root, 0700, true);
    config()->set('benchforge.preparation_path', $root);
    $comparison = PreparationComparison::factory()->create([
        'status' => 'running',
        'working_directory' => $root.'/missing-workspace',
        'finished_at' => null,
    ]);
    $running = PreparationPackage::factory()->for($comparison)->create([
        'model_id' => 'fixture/running',
        'status' => 'running',
        'startup_prompt' => null,
    ]);
    $reviewed = PreparationPackage::factory()->for($comparison)->create([
        'model_id' => 'fixture/reviewed',
        'status' => 'completed',
        'requirements' => 'İnsan tarafından incelendi.',
        'reviewed_at' => now(),
    ]);
    File::put($root.'/active.lock', (string) $comparison->id);

    try {
        $result = app(PreparationRunner::class)->reconcile($comparison);

        expect($result->status)->toBe('failed')
            ->and($result->finished_at)->not->toBeNull()
            ->and($running->refresh()->status)->toBe('failed')
            ->and($reviewed->refresh()->status)->toBe('completed')
            ->and($reviewed->requirements)->toBe('İnsan tarafından incelendi.')
            ->and(File::exists($root.'/active.lock'))->toBeFalse();
        Http::assertNothingSent();
    } finally {
        File::deleteDirectory($root);
    }
});

test('it imports a complete private result and preserves zero usage values after the worker exits', function () {
    Http::preventStrayRequests();
    $root = storage_path('framework/testing/preparation-result-'.Str::uuid());
    $workingDirectory = $root.'/comparison-fixture';
    File::ensureDirectoryExists($workingDirectory.'/models', 0700, true);
    config()->set('benchforge.preparation_path', $root);
    $comparison = PreparationComparison::factory()->create([
        'status' => 'running',
        'working_directory' => $workingDirectory,
        'runner_pid' => 999_999,
        'finished_at' => null,
    ]);
    $package = PreparationPackage::factory()->for($comparison)->create([
        'model_id' => 'fixture/preparation-model',
        'status' => 'running',
        'startup_prompt' => null,
        'requirements' => null,
        'implementation_plan' => null,
        'memory_bank' => null,
        'assumptions_risks' => null,
    ]);
    $modelDirectory = $workingDirectory.'/models/'.$package->id.'-fixture';
    File::ensureDirectoryExists($modelDirectory, 0700, true);
    $configPath = $modelDirectory.'/opencode.json';
    $resultPath = $modelDirectory.'/result.txt';
    $statePath = $workingDirectory.'/state.json';
    $sections = [
        'startup_prompt' => 'Başlangıç promptu',
        'requirements' => 'REQ-01 ve kabul ölçütü',
        'implementation_plan' => 'Aşamalı plan',
        'memory_bank' => "# Hafıza\nGeliştirme başlamadı.",
        'assumptions_risks' => 'Varsayım ve riskler',
    ];
    File::put($workingDirectory.'/prompt.txt', 'Paylaşılan hazırlık promptu');
    File::put($configPath, '{}');
    File::put($resultPath, json_encode($sections, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    File::put($workingDirectory.'/job.json', json_encode([
        'version' => 1,
        'comparisonId' => $comparison->id,
        'statePath' => $statePath,
        'promptPath' => $workingDirectory.'/prompt.txt',
        'models' => [[
            'packageId' => $package->id,
            'modelId' => $package->model_id,
            'directory' => $modelDirectory,
            'configPath' => $configPath,
            'resultPath' => $resultPath,
        ]],
    ], JSON_THROW_ON_ERROR));
    File::put($statePath, json_encode([
        'version' => 1,
        'comparisonId' => $comparison->id,
        'runnerPid' => 999_999,
        'status' => 'completed',
        'finishedAt' => now()->toIso8601String(),
        'models' => [[
            'packageId' => $package->id,
            'status' => 'completed',
            'errorMessage' => null,
            'durationSeconds' => 0,
            'costUsd' => 0,
            'inputTokens' => 0,
            'outputTokens' => 0,
            'resultPath' => $resultPath,
        ]],
    ], JSON_THROW_ON_ERROR));
    File::put($root.'/active.lock', (string) $comparison->id);

    try {
        $result = app(PreparationRunner::class)->reconcile($comparison);
        $package->refresh();

        expect($result->status)->toBe('completed')
            ->and($package->status)->toBe('completed')
            ->and($package->requirements)->toBe('REQ-01 ve kabul ölçütü')
            ->and($package->structural_checks)->toHaveCount(5)
            ->and($package->duration_seconds)->toBe('0.00')
            ->and($package->cost_usd)->toBe('0.00000000')
            ->and($package->input_tokens)->toBe(0)
            ->and($package->output_tokens)->toBe(0)
            ->and(File::exists($root.'/active.lock'))->toBeFalse();
        Http::assertNothingSent();
    } finally {
        File::deleteDirectory($root);
    }
});

test('it launches the isolated worker and reconciles a fake CLI package without external requests', function () {
    Http::preventStrayRequests();
    $root = storage_path('framework/testing/preparation-worker-'.Str::uuid());
    $binPath = $root.'/bin';
    $homePath = $root.'/home';
    $nodeBinary = (string) config('benchforge.node_binary');
    File::ensureDirectoryExists($binPath, 0700, true);
    File::ensureDirectoryExists($homePath, 0700, true);
    $sections = [
        'startup_prompt' => 'Başlangıç promptu',
        'requirements' => 'REQ-01 ve kabul ölçütü',
        'implementation_plan' => 'Aşamalı plan ve görev promptu',
        'memory_bank' => "# Hafıza\nGeliştirme başlamadı.",
        'assumptions_risks' => 'Varsayım ve riskler',
    ];
    $fakeCli = <<<'JS'
if (process.env.APP_KEY !== undefined || process.env.BENCHFORGE_TEST_PREPARATION_SECRET !== undefined) process.exit(91);
const sections = __SECTIONS__;
process.stdout.write(`${JSON.stringify({type: "step_finish", part: {cost: 0.0125, tokens: {input: 120, output: 80}}})}\n`);
process.stdout.write(`${JSON.stringify({type: "text", part: {id: "package", text: JSON.stringify(sections)}})}\n`);
JS;
    $fakeCli = '#!'.$nodeBinary.PHP_EOL.str_replace(
        '__SECTIONS__',
        json_encode($sections, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        $fakeCli,
    );
    File::put($binPath.'/opencode', $fakeCli);
    chmod($binPath.'/opencode', 0755);
    config()->set('benchforge.preparation_path', $root.'/preparations');
    config()->set('benchforge.command_path', $binPath);
    config()->set('benchforge.home_path', $homePath);
    config()->set('benchforge.preparation_timeout_seconds', 30);
    $model = OpenRouterModel::factory()->create([
        'model_id' => 'fixture/fake-preparation',
        'name' => str_repeat('Uzun model adı ', 30),
        'available' => true,
        'agent_compatible' => true,
    ]);
    $connections = mock(ProviderConnectionStatus::class);
    $connections->shouldReceive('inspect')->once()->andReturn([
        'cli' => ['status' => 'pass'],
        'providers' => ['openrouter' => ['status' => 'pass']],
    ]);
    $routing = mock(OpenRouterProviderRouting::class);
    $routing->shouldReceive('routingForModels')->once()->with([$model->model_id], 'auto')->andReturn([
        $model->model_id => [
            'allow_fallbacks' => true,
            'data_collection' => 'deny',
            'require_parameters' => true,
        ],
    ]);
    $runner = new PreparationRunner(
        app(PreparationContract::class),
        mock(OpenRouterCatalog::class),
        mock(OpenCodeGoCatalog::class),
        $connections,
        $routing,
        app(BenchmarkProcessEnvironment::class),
    );
    $comparison = null;
    $originalEnvironment = $_ENV;
    $_ENV['APP_KEY'] = 'preparation-launch-test-only-secret';
    $_ENV['BENCHFORGE_TEST_PREPARATION_SECRET'] = 'preparation-unknown-test-only-secret';

    try {
        $comparison = $runner->start([
            'title' => 'Sahte CLI hazırlığı',
            'specification' => str_repeat('Yönetici ürünleri yönetebilmelidir. ', 2),
            'technologies' => 'Laravel ve SQLite',
            'provider' => 'openrouter',
            'models' => [$model->model_id],
        ]);

        for ($attempt = 0; $attempt < 80 && $comparison->isActive(); $attempt++) {
            usleep(50_000);
            $comparison = $runner->reconcile($comparison);
        }

        $package = $comparison->packages->sole();
        $job = json_decode(File::get($comparison->working_directory.'/job.json'), true, flags: JSON_THROW_ON_ERROR);
        $config = json_decode(File::get($job['models'][0]['configPath']), true, flags: JSON_THROW_ON_ERROR);

        expect($comparison->status)->toBe('completed')
            ->and($package->status)->toBe('completed')
            ->and($package->requirements)->toBe($sections['requirements'])
            ->and($package->cost_usd)->toBe('0.01250000')
            ->and($package->input_tokens)->toBe(120)
            ->and($package->output_tokens)->toBe(80)
            ->and(mb_strlen($package->model_name))->toBeLessThanOrEqual(255)
            ->and($job['version'])->toBe(1)
            ->and($job['comparisonId'])->toBe($comparison->id)
            ->and($config['permission'])->toBe(['*' => 'deny'])
            ->and($config['agent']['benchforge-preparation']['tools'])->toBe(['*' => false])
            ->and($config['agent']['benchforge-preparation']['permission'])->toBe(['*' => 'deny'])
            ->and($config['agent']['benchforge-preparation']['prompt'])->toContain('Geliştirme başlamadı')
            ->and($config['plugin'])->toBe([])
            ->and($config['instructions'])->toBe([])
            ->and($config['mcp'])->toBe([])
            ->and(File::exists(config('benchforge.preparation_path').'/active.lock'))->toBeFalse();
        Http::assertNothingSent();
    } finally {
        $_ENV = $originalEnvironment;
        if ($comparison instanceof PreparationComparison && $comparison->refresh()->isActive()) {
            try {
                $runner->stop($comparison);
            } catch (BenchmarkRunnerException) {
                // The fixture process may already be exiting between reconciliation and cleanup.
            }
        }

        File::deleteDirectory($root);
    }
});

test('it rejects invalid service inputs before catalog or provider inspection', function (array $input, string $message) {
    Http::preventStrayRequests();
    mock(BenchForgeSettings::class)->shouldReceive('apply')->once();

    expect(fn () => app(PreparationRunner::class)->start($input))
        ->toThrow(BenchmarkRunnerException::class, $message);
    expect(PreparationComparison::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'short specification' => [[
        'title' => 'Hazırlık', 'specification' => 'çok kısa', 'technologies' => 'Laravel',
        'provider' => 'openrouter', 'models' => ['fixture/model'],
    ], 'Şartname 50 ile 100000 karakter arasında olmalıdır.'],
    'short technology constraints' => [[
        'title' => 'Hazırlık', 'specification' => str_repeat('Gereksinim ', 6), 'technologies' => 'PHP',
        'provider' => 'openrouter', 'models' => ['fixture/model'],
    ], 'Teknoloji kısıtları 5 ile 5000 karakter arasında olmalıdır.'],
    'duplicate models' => [[
        'title' => 'Hazırlık', 'specification' => str_repeat('Gereksinim ', 6), 'technologies' => 'Laravel',
        'provider' => 'openrouter', 'models' => ['fixture/model', 'fixture/model'],
    ], 'Aynı sağlayıcıdan 1–5 farklı model seçin.'],
    'trailing NUL' => [[
        'title' => 'Hazırlık', 'specification' => str_repeat('Gereksinim ', 6)."\0", 'technologies' => 'Laravel',
        'provider' => 'openrouter', 'models' => ['fixture/model'],
    ], 'Hazırlık metinleri geçerli UTF-8 düz metin olmalıdır.'],
]);

test('it stops before launch when the shared prompt exceeds the worker boundary', function () {
    Http::preventStrayRequests();
    $root = storage_path('framework/testing/preparation-prompt-limit-'.Str::uuid());
    $binPath = $root.'/bin';
    File::ensureDirectoryExists($binPath, 0700, true);
    File::put($binPath.'/opencode', '#!/bin/sh');
    chmod($binPath.'/opencode', 0755);
    config()->set('benchforge.preparation_path', $root.'/preparations');
    config()->set('benchforge.command_path', $binPath);
    $model = OpenRouterModel::factory()->create([
        'model_id' => 'fixture/large-prompt',
        'available' => true,
        'agent_compatible' => true,
    ]);
    $contract = mock(PreparationContract::class);
    $contract->shouldReceive('prompt')->once()->andReturn(str_repeat('x', 1_048_577));
    $connections = mock(ProviderConnectionStatus::class);
    $connections->shouldReceive('inspect')->once()->andReturn([
        'cli' => ['status' => 'pass'],
        'providers' => ['openrouter' => ['status' => 'pass']],
    ]);
    $runner = new PreparationRunner(
        $contract,
        mock(OpenRouterCatalog::class),
        mock(OpenCodeGoCatalog::class),
        $connections,
        mock(OpenRouterProviderRouting::class),
        app(BenchmarkProcessEnvironment::class),
    );

    try {
        expect(fn () => $runner->start([
            'title' => 'Büyük prompt sınırı',
            'specification' => str_repeat('Gereksinim ', 6),
            'technologies' => 'Laravel',
            'provider' => 'openrouter',
            'models' => [$model->model_id],
        ]))->toThrow(BenchmarkRunnerException::class, 'Hazırlık promptu güvenli 1 MiB sınırını aşıyor. Şartnameyi kısaltın.');

        $comparison = PreparationComparison::query()->sole();
        expect($comparison->status)->toBe('failed')
            ->and($comparison->packages()->count())->toBe(0)
            ->and(File::exists(config('benchforge.preparation_path').'/active.lock'))->toBeFalse();
        Http::assertNothingSent();
    } finally {
        File::deleteDirectory($root);
    }
});
