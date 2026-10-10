<?php

use App\Exceptions\BenchmarkRunnerException;
use App\Models\OpenCodeGoModel;
use App\Models\OpenRouterModel;
use App\Services\BenchmarkRunner;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

use function Pest\Laravel\mock;

uses(LazilyRefreshDatabase::class);

test('it returns the selected model group preflight without caching', function () {
    $preflight = [
        'ready' => true,
        'checkedAt' => '2026-09-19T10:00:00+03:00',
        'summary' => '1/1 kontrol geçti. Benchmark başlatılabilir.',
        'checks' => [[
            'key' => 'ports',
            'label' => 'Model portları',
            'status' => 'pass',
            'message' => '3101 kullanılabilir.',
        ]],
    ];
    $runner = mock(BenchmarkRunner::class);
    $runner->shouldReceive('options')->once()->andReturn([
        'prompts' => [],
        'configs' => [[
            'value' => 'config/models.conf',
            'label' => 'Models',
            'modelCount' => 1,
            'ports' => [3101],
        ]],
        'suggestedRoutes' => ['/'],
    ]);
    $runner->shouldReceive('preflight')
        ->once()
        ->with('config/models.conf')
        ->andReturn($preflight);

    $response = $this->getJson(route('runner.preflight', ['config' => 'config/models.conf']));

    $response
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson($preflight);
});

test('it returns validation errors for an unknown model group', function () {
    $runner = mock(BenchmarkRunner::class);
    $runner->shouldReceive('options')->once()->andReturn([
        'prompts' => [],
        'configs' => [[
            'value' => 'config/models.conf',
            'label' => 'Models',
            'modelCount' => 1,
            'ports' => [3101],
        ]],
        'suggestedRoutes' => ['/'],
    ]);
    $runner->shouldNotReceive('preflight');

    $response = $this->getJson(route('runner.preflight', ['config' => 'config/unknown.conf']));

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['config'])
        ->assertJsonPath('errors.config.0', 'Listeden geçerli bir model grubu seçin.');
});

test('it returns forbidden when a non local address requests a preflight', function () {
    mock(BenchmarkRunner::class)->shouldNotReceive('preflight');

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
        ->getJson(route('runner.preflight', ['config' => 'config/models.conf']));

    $response->assertForbidden();
});

test('it checks ports for a custom OpenRouter model selection', function () {
    $model = OpenRouterModel::factory()->create(['model_id' => 'acme/agent-model']);
    $preflight = [
        'ready' => true,
        'checkedAt' => '2026-09-21T10:00:00+03:00',
        'summary' => 'Kontroller geçti.',
        'checks' => [],
    ];
    $runner = mock(BenchmarkRunner::class);
    $runner->shouldReceive('options')->once()->andReturn([
        'prompts' => [],
        'configs' => [],
        'suggestedRoutes' => ['/'],
    ]);
    $runner->shouldReceive('preflight')
        ->once()
        ->with(null, [$model->model_id], 'custom', 'auto', [])
        ->andReturn($preflight);

    $this->getJson(route('runner.preflight', [
        'model_mode' => 'custom',
        'models' => [$model->model_id],
    ]))
        ->assertOk()
        ->assertExactJson($preflight);
});

test('it forwards the exact provider map for a custom OpenRouter preflight', function () {
    $model = OpenRouterModel::factory()->create(['model_id' => 'deepseek/deepseek-v4.1']);
    $preflight = [
        'ready' => true,
        'checkedAt' => '2026-10-03T10:00:00+03:00',
        'summary' => 'Kontroller geçti.',
        'checks' => [],
    ];
    $runner = mock(BenchmarkRunner::class);
    $runner->shouldReceive('options')->once()->andReturn([
        'prompts' => [],
        'configs' => [],
        'suggestedRoutes' => ['/'],
    ]);
    $runner->shouldReceive('preflight')
        ->once()
        ->with(
            null,
            [$model->model_id],
            'custom',
            'fixed',
            [$model->model_id => 'relace/fp4'],
        )
        ->andReturn($preflight);

    $this->getJson(route('runner.preflight', [
        'model_mode' => 'custom',
        'models' => [$model->model_id],
        'routing_mode' => 'fixed',
        'fixed_providers' => [$model->model_id => 'relace/fp4'],
    ]))
        ->assertOk()
        ->assertExactJson($preflight);
});

test('it rejects a fixed provider map that does not match the selected dotted model ids', function () {
    $model = OpenRouterModel::factory()->create(['model_id' => 'deepseek/deepseek-v4.1']);
    $runner = mock(BenchmarkRunner::class);
    $runner->shouldReceive('options')->once()->andReturn([
        'prompts' => [],
        'configs' => [],
        'suggestedRoutes' => ['/'],
    ]);
    $runner->shouldReceive('preflight')
        ->once()
        ->with(
            null,
            [$model->model_id],
            'custom',
            'fixed',
            ['deepseek/deepseek-v4' => 'relace/fp4'],
        )
        ->andThrow(new BenchmarkRunnerException('Seçilen her model için bir sabit sağlayıcı seçin.'));

    $response = $this->getJson(route('runner.preflight', [
        'model_mode' => 'custom',
        'models' => [$model->model_id],
        'routing_mode' => 'fixed',
        'fixed_providers' => ['deepseek/deepseek-v4' => 'relace/fp4'],
    ]));

    $response
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'Seçilen her model için bir sabit sağlayıcı seçin.',
            'ready' => false,
        ]);
});

test('it checks ports for an OpenCode Go model selection', function () {
    $model = OpenCodeGoModel::factory()->create(['model_id' => 'glm-5.3-flash']);
    $preflight = [
        'ready' => true,
        'checkedAt' => '2026-09-21T10:00:00+03:00',
        'summary' => 'Kontroller geçti.',
        'checks' => [],
    ];
    $runner = mock(BenchmarkRunner::class);
    $runner->shouldReceive('options')->once()->andReturn([
        'prompts' => [],
        'configs' => [],
        'suggestedRoutes' => ['/'],
    ]);
    $runner->shouldReceive('preflight')
        ->once()
        ->with(null, [$model->model_id], 'opencode_go')
        ->andReturn($preflight);

    $this->getJson(route('runner.preflight', [
        'model_mode' => 'opencode_go',
        'go_models' => [$model->model_id],
    ]))
        ->assertOk()
        ->assertExactJson($preflight);
});
