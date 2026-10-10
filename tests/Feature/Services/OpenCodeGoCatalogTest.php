<?php

use App\Models\OpenCodeGoModel;
use App\Services\OpenCodeGoCatalog;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config()->set('services.opencode_go.models_url', 'https://go.test/models');
    config()->set('services.opencode_go.metadata_url', 'https://metadata.test/api.json');
});

test('it intersects live availability with metadata and exposes only tool capable models', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://go.test/models' => Http::response([
            'data' => [
                ['id' => 'glm-5.3-flash'],
                ['id' => 'chat-only'],
                ['id' => 'missing-metadata'],
            ],
        ]),
        'https://metadata.test/api.json' => Http::response([
            'opencode-go' => ['models' => [
                'glm-5.3-flash' => [
                    'name' => 'GLM 5.3 Flash',
                    'family' => 'glm',
                    'tool_call' => true,
                    'reasoning' => true,
                    'attachment' => false,
                    'release_date' => '2026-09-10',
                    'limit' => ['context' => 262144, 'output' => 65536],
                    'cost' => ['input' => 0.1, 'output' => 0.4, 'cache_read' => 0.02],
                    'modalities' => ['input' => ['text'], 'output' => ['text']],
                    'reasoning_options' => [['type' => 'effort', 'values' => ['low', 'high']]],
                ],
                'chat-only' => [
                    'name' => 'Chat Only',
                    'tool_call' => false,
                    'cost' => ['input' => 0, 'output' => 0],
                ],
                'metadata-only' => [
                    'name' => 'Metadata Only',
                    'tool_call' => true,
                    'cost' => ['input' => 1, 'output' => 2],
                ],
            ]],
        ]),
    ]);

    $count = app(OpenCodeGoCatalog::class)->sync();

    expect($count)->toBe(2)
        ->and(OpenCodeGoModel::query()->count())->toBe(2)
        ->and(app(OpenCodeGoCatalog::class)->selectableModels()->pluck('model_id')->all())
        ->toBe(['glm-5.3-flash']);

    $this->assertDatabaseHas('open_code_go_models', [
        'model_id' => 'chat-only',
        'agent_compatible' => false,
        'is_free' => true,
        'available' => true,
    ]);
    $this->assertDatabaseHas('open_code_go_models', [
        'model_id' => 'glm-5.3-flash',
        'context_length' => 262144,
        'input_cost' => '0.100000',
        'supports_reasoning' => true,
    ]);
    $this->assertDatabaseMissing('open_code_go_models', ['model_id' => 'metadata-only']);
});

test('it marks models missing from a later availability response as unavailable', function () {
    OpenCodeGoModel::factory()->create([
        'model_id' => 'old-model',
        'available' => true,
    ]);
    Http::fake([
        'https://go.test/models' => Http::response([
            'data' => [['id' => 'new-model']],
        ]),
        'https://metadata.test/api.json' => Http::response([
            'opencode-go' => ['models' => [
                'new-model' => [
                    'name' => 'New Model',
                    'tool_call' => true,
                    'cost' => ['input' => 0.1, 'output' => 0.2],
                ],
            ]],
        ]),
    ]);

    app(OpenCodeGoCatalog::class)->sync();

    expect(OpenCodeGoModel::query()->where('model_id', 'old-model')->value('available'))->toBeFalse()
        ->and(OpenCodeGoModel::query()->where('model_id', 'new-model')->value('available'))->toBeTrue();
});

test('it preserves complete metadata and matching availability without filtering nested fields', function () {
    Http::preventStrayRequests();
    $availability = [
        'id' => 'detailed-model',
        'owned_by' => 'opencode',
        'public_metadata' => ['ready' => true, 'rate_limit' => null],
    ];
    $metadata = [
        'name' => 'Detailed Model',
        'description' => 'Çok dilli model.',
        'tool_call' => true,
        'open_weights' => false,
        'limit' => ['context' => 200000, 'output' => 32000, 'input' => 160000],
        'cost' => ['input' => 0.12345678, 'output' => 0.4, 'cache_write' => 0.08, 'future_fee' => '0.001'],
        'modalities' => ['input' => ['text', 'image'], 'output' => ['text']],
        'future_metadata' => ['providers' => [['name' => 'primary', 'settings' => ['enabled' => false]]]],
        'deprecation_date' => null,
    ];
    Http::fake([
        'https://go.test/models' => Http::response(['data' => [$availability]]),
        'https://metadata.test/api.json' => Http::response([
            'opencode-go' => ['models' => ['detailed-model' => $metadata]],
        ]),
    ]);

    app(OpenCodeGoCatalog::class)->sync();

    Http::assertSentCount(2);
    $model = OpenCodeGoModel::query()->where('model_id', 'detailed-model')->firstOrFail();

    expect($model->catalog_payload)->toBe(['metadata' => $metadata, 'availability' => $availability]);
    expect($model->input_cost)->toBe('0.123457');
    expect($model->cache_write_cost)->toBe('0.080000');
});

test('it refreshes both public source snapshots for an existing go model', function () {
    Http::preventStrayRequests();
    OpenCodeGoModel::factory()->create([
        'model_id' => 'detailed-model',
        'catalog_payload' => [
            'metadata' => ['name' => 'Old Model', 'obsolete_field' => true],
            'availability' => ['id' => 'detailed-model', 'obsolete_field' => true],
        ],
    ]);
    $availability = ['id' => 'detailed-model', 'revision' => 2];
    $metadata = [
        'name' => 'Updated Model',
        'tool_call' => true,
        'cost' => ['input' => 0.2, 'output' => 0.6],
        'future_metadata' => ['revision' => 2],
    ];
    Http::fake([
        'https://go.test/models' => Http::response(['data' => [$availability]]),
        'https://metadata.test/api.json' => Http::response([
            'opencode-go' => ['models' => ['detailed-model' => $metadata]],
        ]),
    ]);

    app(OpenCodeGoCatalog::class)->sync();

    Http::assertSentCount(2);
    $model = OpenCodeGoModel::query()->where('model_id', 'detailed-model')->firstOrFail();

    expect($model->catalog_payload)->toBe(['metadata' => $metadata, 'availability' => $availability]);
    expect($model->name)->toBe('Updated Model');
    expect($model->output_cost)->toBe('0.600000');
});
