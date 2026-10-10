<?php

use App\Models\OpenRouterModel;
use App\Services\OpenRouterCatalog;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

test('it synchronizes the catalog and exposes only tool capable models', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://openrouter.test/models' => Http::response([
            'data' => [
                [
                    'id' => 'acme/agent-model',
                    'name' => 'Agent Model',
                    'description' => 'Tool capable model.',
                    'context_length' => 131072,
                    'created' => 1_700_000_000,
                    'supported_parameters' => ['tools', 'temperature'],
                    'pricing' => ['prompt' => '0.000001', 'completion' => '0.000003'],
                    'architecture' => ['modality' => 'text->text'],
                ],
                [
                    'id' => 'acme/chat-only:free',
                    'name' => 'Chat Only',
                    'supported_parameters' => ['temperature'],
                    'pricing' => ['prompt' => '0', 'completion' => '0'],
                ],
            ],
        ]),
    ]);
    config()->set('services.openrouter.catalog_url', 'https://openrouter.test/models');

    $count = app(OpenRouterCatalog::class)->sync();

    expect($count)->toBe(2)
        ->and(OpenRouterModel::query()->count())->toBe(2)
        ->and(app(OpenRouterCatalog::class)->selectableModels()->pluck('model_id')->all())
        ->toBe(['acme/agent-model']);

    $this->assertDatabaseHas('open_router_models', [
        'model_id' => 'acme/chat-only:free',
        'agent_compatible' => false,
        'is_free' => true,
        'available' => true,
    ]);
});

test('it marks models missing from a later response as unavailable', function () {
    OpenRouterModel::factory()->create([
        'model_id' => 'old/model',
        'available' => true,
    ]);
    Http::fake([
        '*' => Http::response([
            'data' => [[
                'id' => 'new/model',
                'name' => 'New Model',
                'supported_parameters' => ['tools'],
                'pricing' => ['prompt' => '0.000001', 'completion' => '0.000002'],
            ]],
        ]),
    ]);

    app(OpenRouterCatalog::class)->sync();

    expect(OpenRouterModel::query()->where('model_id', 'old/model')->value('available'))->toBeFalse()
        ->and(OpenRouterModel::query()->where('model_id', 'new/model')->value('available'))->toBeTrue();
});

test('it preserves the complete public model payload including unknown nested fields', function () {
    Http::preventStrayRequests();
    config()->set('services.openrouter.catalog_url', 'https://openrouter.test/models');
    $payload = [
        'id' => 'acme/detailed-model',
        'name' => 'Detailed Model',
        'description' => 'Çok dilli model.',
        'supported_parameters' => ['tools', 'response_format'],
        'pricing' => [
            'prompt' => '0.000000123456789',
            'completion' => '0.000002',
            'input_cache_read' => '0.0000000123456789',
            'request' => '0',
        ],
        'top_provider' => ['context_length' => 200000, 'max_completion_tokens' => 32000, 'is_moderated' => false],
        'per_request_limits' => null,
        'future_metadata' => ['tiers' => [['name' => 'fast', 'enabled' => true]], 'labels' => []],
    ];
    Http::fake([
        'https://openrouter.test/models' => Http::response(['data' => [$payload]]),
    ]);

    app(OpenRouterCatalog::class)->sync();

    Http::assertSentCount(1);
    $model = OpenRouterModel::query()->where('model_id', 'acme/detailed-model')->firstOrFail();

    expect($model->catalog_payload)->toBe($payload);
    expect($model->prompt_price)->toBe('0.000000123457');
    expect($model->completion_price)->toBe('0.000002000000');
});

test('it replaces an existing public payload with the latest catalog snapshot', function () {
    Http::preventStrayRequests();
    config()->set('services.openrouter.catalog_url', 'https://openrouter.test/models');
    OpenRouterModel::factory()->create([
        'model_id' => 'acme/detailed-model',
        'catalog_payload' => ['id' => 'acme/detailed-model', 'obsolete_field' => 'old'],
    ]);
    $payload = [
        'id' => 'acme/detailed-model',
        'name' => 'Updated Model',
        'supported_parameters' => ['tools'],
        'pricing' => ['prompt' => '0.000001', 'completion' => '0.000004'],
        'future_metadata' => ['revision' => 2],
    ];
    Http::fake([
        'https://openrouter.test/models' => Http::response(['data' => [$payload]]),
    ]);

    app(OpenRouterCatalog::class)->sync();

    Http::assertSentCount(1);
    $model = OpenRouterModel::query()->where('model_id', 'acme/detailed-model')->firstOrFail();

    expect($model->catalog_payload)->toBe($payload);
    expect($model->name)->toBe('Updated Model');
    expect($model->completion_price)->toBe('0.000004000000');
});
