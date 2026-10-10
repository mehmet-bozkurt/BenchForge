<?php

use App\Exceptions\BenchmarkRunnerException;
use App\Models\OpenRouterModel;
use App\Services\OpenRouterProviderRouting;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

test('it normalizes tool capable endpoints and refreshes without silently using stale data', function () {
    $model = OpenRouterModel::factory()->create([
        'model_id' => 'deepseek/deepseek-v4.1',
        'available' => true,
        'agent_compatible' => true,
    ]);
    $endpointUrl = 'https://openrouter.test/api/v1/models/deepseek/deepseek-v4.1/endpoints';
    $requestCount = 0;
    config()->set('services.openrouter.catalog_url', 'https://openrouter.test/api/v1/models');
    Http::preventStrayRequests();
    Http::fake([
        $endpointUrl => function () use (&$requestCount, $model) {
            $requestCount++;

            if ($requestCount === 1) {
                return Http::response([
                    'data' => [
                        'id' => $model->model_id,
                        'endpoints' => [
                            [
                                'tag' => 'relace/fp4',
                                'provider_name' => 'Relace',
                                'pricing' => ['prompt' => '0.000001', 'completion' => '0.000002'],
                                'context_length' => 131072,
                                'quantization' => 'fp4',
                                'supported_parameters' => ['tools'],
                            ],
                            [
                                'tag' => 'openrouter/missing-price',
                                'provider_name' => 'OpenRouter',
                                'pricing' => [],
                                'context_length' => 65536,
                                'quantization' => null,
                                'supported_parameters' => ['tools', 'tool_choice'],
                            ],
                            [
                                'tag' => 'chat/no-tools',
                                'provider_name' => 'Chat only',
                                'supported_parameters' => ['temperature'],
                            ],
                            [
                                'tag' => '../malformed',
                                'provider_name' => 'Malformed',
                                'supported_parameters' => ['tools'],
                            ],
                        ],
                    ],
                ]);
            }

            if ($requestCount === 2) {
                return Http::response([
                    'data' => [
                        'id' => $model->model_id,
                        'endpoints' => [[
                            'tag' => 'novita/fp8',
                            'provider_name' => 'Novita',
                            'pricing' => ['prompt' => '0.000003', 'completion' => '0.000004'],
                            'context_length' => 32768,
                            'quantization' => 'fp8',
                            'supported_parameters' => ['tools'],
                        ]],
                    ],
                ]);
            }

            return Http::response([], 503);
        },
    ]);
    $routing = app(OpenRouterProviderRouting::class);

    $initial = $routing->endpoints($model->model_id);
    $cached = $routing->endpoints($model->model_id);
    $refreshed = $routing->endpoints($model->model_id, refresh: true);

    expect($initial['modelId'])->toBe($model->model_id)
        ->and($initial['endpoints'])->toBe([
            [
                'slug' => 'openrouter/missing-price',
                'name' => 'OpenRouter',
                'promptPrice' => null,
                'completionPrice' => null,
                'contextLength' => 65536,
                'quantization' => null,
            ],
            [
                'slug' => 'relace/fp4',
                'name' => 'Relace',
                'promptPrice' => '0.000001000000',
                'completionPrice' => '0.000002000000',
                'contextLength' => 131072,
                'quantization' => 'fp4',
            ],
        ])
        ->and($initial['fetchedAt'])->toBeString()->not->toBeEmpty()
        ->and($cached)->toBe($initial)
        ->and($refreshed['endpoints'])->toBe([[
            'slug' => 'novita/fp8',
            'name' => 'Novita',
            'promptPrice' => '0.000003000000',
            'completionPrice' => '0.000004000000',
            'contextLength' => 32768,
            'quantization' => 'fp8',
        ]])
        ->and($requestCount)->toBe(2);

    expect(fn () => $routing->endpoints($model->model_id, refresh: true))
        ->toThrow(
            BenchmarkRunnerException::class,
            'OpenRouter sağlayıcı listesi alınamadı. Bağlantıyı kontrol edip tekrar deneyin; otomatik sağlayıcıya geçilmedi.',
        );
    expect($requestCount)->toBe(3);
});

test('it rejects models that are not selectable before making an upstream request', function (array $attributes) {
    $model = OpenRouterModel::factory()->create([
        'model_id' => 'acme/gated-model',
        ...$attributes,
    ]);
    Http::preventStrayRequests();

    expect(fn () => app(OpenRouterProviderRouting::class)->endpoints($model->model_id))
        ->toThrow(BenchmarkRunnerException::class);
    Http::assertNothingSent();
})->with([
    'unavailable model' => [['available' => false, 'agent_compatible' => true]],
    'model without tool support' => [['available' => true, 'agent_compatible' => false]],
]);

test('it rejects an unknown model before making an upstream request', function () {
    Http::preventStrayRequests();

    expect(fn () => app(OpenRouterProviderRouting::class)->endpoints('acme/unknown-model'))
        ->toThrow(BenchmarkRunnerException::class);
    Http::assertNothingSent();
});

test('it rejects incomplete or mismatched endpoint responses', function (array $payload) {
    $model = OpenRouterModel::factory()->create([
        'model_id' => 'deepseek/deepseek-v4.1',
        'available' => true,
        'agent_compatible' => true,
    ]);
    config()->set('services.openrouter.catalog_url', 'https://openrouter.test/api/v1/models');
    Http::preventStrayRequests();
    Http::fake([
        'https://openrouter.test/api/v1/models/deepseek/deepseek-v4.1/endpoints' => Http::response($payload),
    ]);

    expect(fn () => app(OpenRouterProviderRouting::class)->endpoints($model->model_id))
        ->toThrow(BenchmarkRunnerException::class);
})->with([
    'missing endpoint data' => [['data' => ['id' => 'deepseek/deepseek-v4.1']]],
    'mismatched response model' => [[
        'data' => [
            'id' => 'deepseek/deepseek-v4',
            'endpoints' => [],
        ],
    ]],
]);

test('it builds baseline routing and optional provider sorting', function (string $mode, array $expected) {
    $model = OpenRouterModel::factory()->create([
        'model_id' => 'deepseek/deepseek-v4.1',
        'available' => true,
        'agent_compatible' => true,
    ]);
    Http::preventStrayRequests();

    $routing = app(OpenRouterProviderRouting::class)->routingForModels([$model->model_id], $mode);

    expect($routing)->toBe([$model->model_id => $expected]);
    Http::assertNothingSent();
})->with([
    'automatic provider selection' => ['auto', [
        'allow_fallbacks' => true,
        'data_collection' => 'deny',
        'require_parameters' => true,
    ]],
    'lowest price preference' => ['price', [
        'allow_fallbacks' => true,
        'data_collection' => 'deny',
        'require_parameters' => true,
        'sort' => 'price',
    ]],
    'highest throughput preference' => ['throughput', [
        'allow_fallbacks' => true,
        'data_collection' => 'deny',
        'require_parameters' => true,
        'sort' => 'throughput',
    ]],
]);

test('it locks each dotted model id to its exact selected provider', function () {
    $deepSeek = OpenRouterModel::factory()->create([
        'model_id' => 'deepseek/deepseek-v4.1',
        'available' => true,
        'agent_compatible' => true,
    ]);
    $miniMax = OpenRouterModel::factory()->create([
        'model_id' => 'minimax/minimax-m3.2',
        'available' => true,
        'agent_compatible' => true,
    ]);
    config()->set('services.openrouter.catalog_url', 'https://openrouter.test/api/v1/models');
    Http::preventStrayRequests();
    Http::fake([
        'https://openrouter.test/api/v1/models/deepseek/deepseek-v4.1/endpoints' => Http::response([
            'data' => [
                'id' => $deepSeek->model_id,
                'endpoints' => [[
                    'tag' => 'relace/fp4',
                    'provider_name' => 'Relace',
                    'supported_parameters' => ['tools'],
                ]],
            ],
        ]),
        'https://openrouter.test/api/v1/models/minimax/minimax-m3.2/endpoints' => Http::response([
            'data' => [
                'id' => $miniMax->model_id,
                'endpoints' => [[
                    'tag' => 'novita/fp8',
                    'provider_name' => 'Novita',
                    'supported_parameters' => ['tools'],
                ]],
            ],
        ]),
    ]);

    $routing = app(OpenRouterProviderRouting::class)->routingForModels(
        [$deepSeek->model_id, $miniMax->model_id],
        'fixed',
        [
            $deepSeek->model_id => 'relace/fp4',
            $miniMax->model_id => 'novita/fp8',
        ],
    );

    expect($routing)->toBe([
        $deepSeek->model_id => [
            'allow_fallbacks' => false,
            'data_collection' => 'deny',
            'require_parameters' => true,
            'order' => ['relace/fp4'],
            'only' => ['relace/fp4'],
        ],
        $miniMax->model_id => [
            'allow_fallbacks' => false,
            'data_collection' => 'deny',
            'require_parameters' => true,
            'order' => ['novita/fp8'],
            'only' => ['novita/fp8'],
        ],
    ]);
});

test('it rejects an unsupported routing mode', function () {
    $model = OpenRouterModel::factory()->create(['model_id' => 'acme/agent-model']);

    expect(fn () => app(OpenRouterProviderRouting::class)->routingForModels([$model->model_id], 'random'))
        ->toThrow(BenchmarkRunnerException::class, 'Geçerli bir OpenRouter sağlayıcı tercihi seçin.');
});

test('it rejects incomplete or unexpected fixed provider maps', function (array $fixedProviders) {
    $deepSeek = OpenRouterModel::factory()->create(['model_id' => 'deepseek/deepseek-v4.1']);
    $miniMax = OpenRouterModel::factory()->create(['model_id' => 'minimax/minimax-m3.2']);
    Http::preventStrayRequests();

    expect(fn () => app(OpenRouterProviderRouting::class)->routingForModels(
        [$deepSeek->model_id, $miniMax->model_id],
        'fixed',
        $fixedProviders,
    ))->toThrow(BenchmarkRunnerException::class, 'Seçilen her model için bir sabit sağlayıcı seçin.');
    Http::assertNothingSent();
})->with([
    'missing model key' => [[
        'deepseek/deepseek-v4.1' => 'relace/fp4',
    ]],
    'unexpected model key' => [[
        'deepseek/deepseek-v4.1' => 'relace/fp4',
        'minimax/minimax-m3.2' => 'novita/fp8',
        'other/model' => 'other/provider',
    ]],
    'empty provider value' => [[
        'deepseek/deepseek-v4.1' => 'relace/fp4',
        'minimax/minimax-m3.2' => '',
    ]],
]);

test('it rejects a fixed provider that is absent from the fresh tool capable list', function () {
    $model = OpenRouterModel::factory()->create([
        'model_id' => 'deepseek/deepseek-v4.1',
        'available' => true,
        'agent_compatible' => true,
    ]);
    config()->set('services.openrouter.catalog_url', 'https://openrouter.test/api/v1/models');
    Http::preventStrayRequests();
    Http::fake([
        'https://openrouter.test/api/v1/models/deepseek/deepseek-v4.1/endpoints' => Http::response([
            'data' => [
                'id' => $model->model_id,
                'endpoints' => [[
                    'tag' => 'relace/fp4',
                    'provider_name' => 'Relace',
                    'supported_parameters' => ['tools'],
                ]],
            ],
        ]),
    ]);

    expect(fn () => app(OpenRouterProviderRouting::class)->routingForModels(
        [$model->model_id],
        'fixed',
        [$model->model_id => 'novita/fp8'],
        refresh: true,
    ))->toThrow(
        BenchmarkRunnerException::class,
        'deepseek/deepseek-v4.1 için seçilen sağlayıcı artık listede yok veya araç desteği sunmuyor. Sağlayıcı listesini yenileyin.',
    );
});
