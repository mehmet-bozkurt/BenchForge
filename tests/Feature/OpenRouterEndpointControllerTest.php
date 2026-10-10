<?php

use App\Models\OpenRouterModel;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

test('it returns 422 without an upstream request for an invalid model selection', function (array $input) {
    Http::preventStrayRequests();

    $this->getJson(route('runner.openrouter.endpoints', $input))
        ->assertUnprocessable()->assertJsonValidationErrors(['model']);

    Http::assertNothingSent();
})->with([
    'missing model' => [[]],
    'unknown model' => [['model' => 'acme/unknown-model']],
    'unsafe model path' => [['model' => '../external-model']],
]);

test('it returns normalized tool capable providers without sending an authorization header', function () {
    $model = OpenRouterModel::factory()->create([
        'model_id' => 'deepseek/deepseek-v4.1',
        'available' => true,
        'agent_compatible' => true,
    ]);
    $endpointUrl = 'https://openrouter.test/api/v1/models/deepseek/deepseek-v4.1/endpoints';
    config()->set('services.openrouter.catalog_url', 'https://openrouter.test/api/v1/models');
    Http::preventStrayRequests();
    $upstreamRequestCount = 0;
    Http::fake([
        $endpointUrl => function () use (&$upstreamRequestCount, $model) {
            $upstreamRequestCount++;

            return Http::response([
                'data' => [
                    'id' => $model->model_id,
                    'endpoints' => [
                        [
                            'tag' => 'relace/fp4',
                            'provider_name' => $upstreamRequestCount === 1 ? 'Relace' : 'Relace refreshed',
                            'pricing' => ['prompt' => '0.000001', 'completion' => '0.000002'],
                            'context_length' => 131072,
                            'quantization' => 'fp4',
                            'supported_parameters' => ['tools', 'temperature'],
                        ],
                        [
                            'tag' => 'chat/without-tools',
                            'provider_name' => 'Chat only',
                            'pricing' => ['prompt' => '0.000003', 'completion' => '0.000004'],
                            'supported_parameters' => ['temperature'],
                        ],
                    ],
                ],
            ]);
        },
    ]);

    $response = $this->getJson(route('runner.openrouter.endpoints', [
        'model' => $model->model_id,
    ]));

    $response
        ->assertOk()
        ->assertJsonPath('modelId', $model->model_id)
        ->assertJsonCount(1, 'endpoints')
        ->assertJsonPath('endpoints.0', [
            'slug' => 'relace/fp4',
            'name' => 'Relace',
            'promptPrice' => '0.000001000000',
            'completionPrice' => '0.000002000000',
            'contextLength' => 131072,
            'quantization' => 'fp4',
        ])
        ->assertJsonStructure(['modelId', 'endpoints', 'fetchedAt']);

    expect(array_keys($response->json()))->toBe(['modelId', 'endpoints', 'fetchedAt'])
        ->and($response->json('fetchedAt'))->toBeString()->not->toBeEmpty();

    $this->getJson(route('runner.openrouter.endpoints', [
        'model' => $model->model_id,
        'refresh' => '1',
    ]))
        ->assertOk()
        ->assertJsonPath('endpoints.0.name', 'Relace refreshed');

    Http::assertSent(fn (Request $request): bool => $request->url() === $endpointUrl
        && ! $request->hasHeader('Authorization'));
    Http::assertSentCount(2);
});

test('it returns 503 with a generic message when the provider list cannot be fetched', function (int $status) {
    $model = OpenRouterModel::factory()->create([
        'model_id' => 'acme/failing-model',
        'available' => true,
        'agent_compatible' => true,
    ]);
    config()->set('services.openrouter.catalog_url', 'https://openrouter.test/api/v1/models');
    Http::preventStrayRequests();
    Http::fake([
        'https://openrouter.test/api/v1/models/acme/failing-model/endpoints' => Http::response([], $status),
    ]);

    $response = $this->getJson(route('runner.openrouter.endpoints', [
        'model' => $model->model_id,
    ]));

    $response
        ->assertServiceUnavailable()
        ->assertExactJson([
            'message' => 'OpenRouter sağlayıcı listesi alınamadı. Bağlantıyı kontrol edip tekrar deneyin; otomatik sağlayıcıya geçilmedi.',
        ]);
})->with([
    'upstream service failure' => 503,
    'unsupported redirect' => 302,
]);

test('it returns 403 before making an upstream request for a non local address', function () {
    Http::preventStrayRequests();

    $this
        ->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
        ->getJson(route('runner.openrouter.endpoints', ['model' => 'acme/agent-model']))
        ->assertForbidden();

    Http::assertNothingSent();
});
