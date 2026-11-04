<?php

use App\Exceptions\BenchmarkRunnerException;
use App\Models\OpenCodeGoModel;
use App\Models\OpenRouterModel;
use App\Services\ModelSelection;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->root = storage_path('framework/testing/model-selection-'.Str::uuid());
    File::ensureDirectoryExists($this->root.'/config');
    config()->set('benchforge.root_path', $this->root);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

test('it lists the models of a group with the folders and ports of the group file', function () {
    File::put($this->root.'/config/pilot.conf', "# max_parallel=1\nminimax|openrouter/minimax/minimax-m3|3101\nglm|opencode-go/glm-5.3-flash|3102\n");

    $selection = app(ModelSelection::class)->resolve(['model_mode' => 'group', 'config' => 'config/pilot.conf']);

    expect($selection['reference'])->toBe('config/pilot.conf')
        ->and($selection['option'])->toBe([
            'value' => 'config/pilot.conf',
            'label' => 'Pilot',
            'modelCount' => 2,
            'ports' => [3101, 3102],
            'providers' => ['openrouter', 'opencode-go'],
            'maxParallel' => 1,
        ])
        ->and($selection['models'])->toBe([
            ['provider' => 'openrouter', 'modelId' => 'minimax/minimax-m3', 'model' => 'openrouter/minimax/minimax-m3', 'folder' => 'minimax', 'port' => 3101, 'routing' => null],
            ['provider' => 'opencode-go', 'modelId' => 'glm-5.3-flash', 'model' => 'opencode-go/glm-5.3-flash', 'folder' => 'glm', 'port' => 3102, 'routing' => null],
        ]);
});

test('it lists OpenRouter catalog models with the routing of each model', function () {
    OpenRouterModel::factory()->create(['model_id' => 'deepseek/deepseek-v4.1-flash']);
    OpenRouterModel::factory()->create(['model_id' => 'minimax/minimax-m3.1-flash:free']);
    Http::preventStrayRequests();
    $routing = ['allow_fallbacks' => true, 'data_collection' => 'deny', 'require_parameters' => true, 'sort' => 'price'];

    $selection = app(ModelSelection::class)->resolve([
        'model_mode' => 'custom',
        'models' => ['deepseek/deepseek-v4.1-flash', 'minimax/minimax-m3.1-flash:free'],
        'routing_mode' => 'price',
    ]);

    expect($selection['option']['ports'])->toBe([3251, 3252])
        ->and($selection['option']['providers'])->toBe(['openrouter'])
        ->and($selection['models'])->toBe([
            ['provider' => 'openrouter', 'modelId' => 'deepseek/deepseek-v4.1-flash', 'model' => 'openrouter/deepseek/deepseek-v4.1-flash', 'folder' => 'or-deepseek-v41-flash-ba1de9', 'port' => 3251, 'routing' => $routing],
            ['provider' => 'openrouter', 'modelId' => 'minimax/minimax-m3.1-flash:free', 'model' => 'openrouter/minimax/minimax-m3.1-flash:free', 'folder' => 'or-minimax-m31-flashfree-0ee2cf', 'port' => 3252, 'routing' => $routing],
        ]);
});

test('it lists OpenCode Go catalog models without routing', function () {
    OpenCodeGoModel::factory()->create(['model_id' => 'glm-5.3-flash']);

    $selection = app(ModelSelection::class)->resolve(['model_mode' => 'opencode_go', 'go_models' => ['glm-5.3-flash']]);

    expect($selection['openCodeConfigContents'])->toBeNull()
        ->and($selection['models'])->toBe([
            ['provider' => 'opencode-go', 'modelId' => 'glm-5.3-flash', 'model' => 'opencode-go/glm-5.3-flash', 'folder' => 'go-glm-53-flash-302328', 'port' => 3251, 'routing' => null],
        ]);
});

test('it fetches fresh endpoints for fixed OpenRouter routing in a workflow', function () {
    $model = OpenRouterModel::factory()->create(['model_id' => 'deepseek/deepseek-v4.1-flash']);
    Http::preventStrayRequests();
    Http::fake([
        'https://openrouter.ai/api/v1/models/deepseek/deepseek-v4.1-flash/endpoints' => Http::response([
            'data' => ['id' => $model->model_id, 'endpoints' => [['tag' => 'relace/fp4', 'supported_parameters' => ['tools']]]],
        ]),
    ]);

    $selection = app(ModelSelection::class)->resolveForWorkflow([
        'model_mode' => 'custom',
        'models' => [$model->model_id],
        'routing_mode' => 'fixed',
        'fixed_providers' => [$model->model_id => 'relace/fp4'],
    ]);

    expect($selection['models'][0]['routing'])->toBe([
        'allow_fallbacks' => false, 'data_collection' => 'deny', 'require_parameters' => true,
        'order' => ['relace/fp4'], 'only' => ['relace/fp4'],
    ]);
    Http::assertSentCount(1);
});

test('it gives OpenRouter models from a group automatic routing in a workflow', function () {
    File::put($this->root.'/config/pilot.conf', "minimax|openrouter/minimax/minimax-m3|3101\nglm|opencode-go/glm-5.3-flash|3102\n");
    Http::preventStrayRequests();

    $selection = app(ModelSelection::class)->resolveForWorkflow(['model_mode' => 'group', 'config' => 'config/pilot.conf']);

    expect($selection['models'][0]['routing'])->toBe(['allow_fallbacks' => true, 'data_collection' => 'deny', 'require_parameters' => true])
        ->and($selection['models'][1]['routing'])->toBeNull();
});

test('it rejects a group with more models than a workflow allows', function () {
    File::put($this->root.'/config/large.conf', implode("\n", array_map(
        fn (int $index): string => "m{$index}|opencode-go/model-{$index}|".(3100 + $index),
        range(1, 6),
    ))."\n");

    expect(fn () => app(ModelSelection::class)->resolveForWorkflow(['model_mode' => 'group', 'config' => 'config/large.conf']))
        ->toThrow(BenchmarkRunnerException::class, 'Bu grupta 6 model var; iş akışında en fazla 5 model seçilebilir.');
});

test('it keeps benchmark groups free of a model limit', function () {
    File::put($this->root.'/config/large.conf', implode("\n", array_map(
        fn (int $index): string => "m{$index}|opencode-go/model-{$index}|".(3100 + $index),
        range(1, ModelSelection::BENCHMARK_MODEL_LIMIT + 1),
    ))."\n");

    $selection = app(ModelSelection::class)->resolve(['model_mode' => 'group', 'config' => 'config/large.conf']);

    expect($selection['option']['modelCount'])->toBe(17)
        ->and($selection['models'])->toHaveCount(17);
});

test('it rejects a group model from a provider that workflows do not support', function () {
    File::put($this->root.'/config/mixed.conf', "glm|opencode-go/glm-5.3-flash|3101\nqwen|ollama/qwen3|3102\n");

    expect(fn () => app(ModelSelection::class)->resolveForWorkflow(['model_mode' => 'group', 'config' => 'config/mixed.conf']))
        ->toThrow(BenchmarkRunnerException::class, 'Bu gruptaki ollama/qwen3 modeli iş akışlarında desteklenmiyor (yalnız OpenRouter ve OpenCode Go).');
});

test('it rejects a group that lists the same model twice in a workflow', function () {
    File::put($this->root.'/config/twice.conf', "glm-a|opencode-go/glm-5.3-flash|3101\nglm-b|opencode-go/glm-5.3-flash|3102\n");

    expect(fn () => app(ModelSelection::class)->resolveForWorkflow(['model_mode' => 'group', 'config' => 'config/twice.conf']))
        ->toThrow(BenchmarkRunnerException::class, 'Bu grupta opencode-go/glm-5.3-flash modeli birden fazla kez var.');
});

dataset('catalog choices above the workflow limit', [
    'OpenRouter' => ['custom', 'models', fn (): array => OpenRouterModel::factory()->count(6)->sequence(fn ($sequence): array => ['model_id' => 'acme/model-'.$sequence->index])->create()->pluck('model_id')->all(), 'OpenRouter kataloğundan 1–5 model seçin.'],
    'OpenCode Go' => ['opencode_go', 'go_models', fn (): array => OpenCodeGoModel::factory()->count(6)->sequence(fn ($sequence): array => ['model_id' => 'model-'.$sequence->index])->create()->pluck('model_id')->all(), 'OpenCode Go kataloğundan 1–5 model seçin.'],
]);

test('it rejects catalog choices above the workflow model limit', function (string $mode, string $field, array $models, string $message) {
    Http::preventStrayRequests();

    expect(fn () => app(ModelSelection::class)->resolveForWorkflow(['model_mode' => $mode, $field => $models]))
        ->toThrow(BenchmarkRunnerException::class, $message);
})->with('catalog choices above the workflow limit');

test('it resolves a workflow group while another group file is invalid', function () {
    File::put($this->root.'/config/broken.conf', "not a model line\n");
    File::put($this->root.'/config/pilot.conf', "glm|opencode-go/glm-5.3-flash|3101\n");

    $selection = app(ModelSelection::class)->resolveForWorkflow(['model_mode' => 'group', 'config' => 'config/pilot.conf']);

    expect($selection['option']['value'])->toBe('config/pilot.conf');
});

dataset('groups outside the group list', [
    'a missing group' => ['config/other.conf'],
    'a path that leaves the config directory' => ['config/../pilot.conf'],
]);

test('it rejects a workflow group that is not a group file', function (string $group) {
    File::put($this->root.'/config/pilot.conf', "glm|opencode-go/glm-5.3-flash|3101\n");
    File::put($this->root.'/pilot.conf', "glm|opencode-go/glm-5.3-flash|3101\n");

    expect(fn () => app(ModelSelection::class)->resolveForWorkflow(['model_mode' => 'group', 'config' => $group]))
        ->toThrow(BenchmarkRunnerException::class, 'Listeden geçerli bir model grubu seçin.');
})->with('groups outside the group list');

test('it reads the data root again on every call', function () {
    $otherRoot = storage_path('framework/testing/model-selection-'.Str::uuid());
    File::ensureDirectoryExists($otherRoot.'/config');
    File::put($this->root.'/config/first.conf', "glm|opencode-go/glm-5.3-flash|3101\n");
    File::put($otherRoot.'/config/second.conf', "kimi|opencode-go/kimi-k3|3101\n");
    $modelSelection = app(ModelSelection::class);

    try {
        $before = $modelSelection->groupOptions();
        config()->set('benchforge.root_path', $otherRoot);
        $after = $modelSelection->groupOptions();

        expect(array_column($before, 'value'))->toBe(['config/first.conf'])
            ->and(array_column($after, 'value'))->toBe(['config/second.conf']);
    } finally {
        File::deleteDirectory($otherRoot);
    }
});

dataset('provider-prefixed models', [
    'an OpenRouter model' => ['openrouter/deepseek/deepseek-v4.1-flash', 'or-deepseek-v41-flash-ba1de9'],
    'an OpenCode Go model' => ['opencode-go/glm-5.3-flash', 'go-glm-53-flash-302328'],
]);

test('it names model folders after the provider and model', function (string $model, string $folder) {
    expect(ModelSelection::folderFor($model))->toBe($folder);
})->with('provider-prefixed models');
