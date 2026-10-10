<?php

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

test('it refreshes the OpenCode Go catalog from the local runner page', function () {
    config()->set('services.opencode_go.models_url', 'https://go.test/models');
    config()->set('services.opencode_go.metadata_url', 'https://metadata.test/api.json');
    Http::fake([
        'https://go.test/models' => Http::response([
            'data' => [['id' => 'glm-5.3-flash']],
        ]),
        'https://metadata.test/api.json' => Http::response([
            'opencode-go' => ['models' => [
                'glm-5.3-flash' => [
                    'name' => 'GLM 5.3 Flash',
                    'tool_call' => true,
                    'cost' => ['input' => 0.1, 'output' => 0.4],
                ],
            ]],
        ]),
    ]);

    $response = $this->from(route('runner.create'))->post(route('runner.opencode_go.sync'));

    $response
        ->assertRedirect(route('runner.create'))
        ->assertSessionHas('status', 'OpenCode Go kataloğu güncellendi: 1 model.');
    $this->assertDatabaseHas('open_code_go_models', ['model_id' => 'glm-5.3-flash']);
});

test('it reports an upstream OpenCode Go catalog failure without exposing details', function () {
    Http::fake(['*' => Http::response([], 503)]);

    $response = $this->from(route('runner.create'))->post(route('runner.opencode_go.sync'));

    $response
        ->assertRedirect(route('runner.create'))
        ->assertSessionHasErrors(['catalog']);
});

test('it forbids OpenCode Go catalog refresh from a non local address', function () {
    Http::preventStrayRequests();

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
        ->post(route('runner.opencode_go.sync'))
        ->assertForbidden();
});
