<?php

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

test('it refreshes the OpenRouter catalog from the local runner page', function () {
    Http::fake([
        '*' => Http::response([
            'data' => [[
                'id' => 'acme/agent-model',
                'name' => 'Agent Model',
                'supported_parameters' => ['tools'],
                'pricing' => ['prompt' => '0.000001', 'completion' => '0.000002'],
            ]],
        ]),
    ]);

    $response = $this->from(route('runner.create'))->post(route('runner.openrouter.sync'));

    $response
        ->assertRedirect(route('runner.create'))
        ->assertSessionHas('status', 'OpenRouter kataloğu güncellendi: 1 model.');
    $this->assertDatabaseHas('open_router_models', ['model_id' => 'acme/agent-model']);
});

test('it reports an upstream catalog failure without exposing details', function () {
    Http::fake(['*' => Http::response([], 503)]);

    $response = $this->from(route('runner.create'))->post(route('runner.openrouter.sync'));

    $response
        ->assertRedirect(route('runner.create'))
        ->assertSessionHasErrors(['catalog']);
});

test('it forbids catalog refresh from a non local address', function () {
    Http::preventStrayRequests();

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
        ->post(route('runner.openrouter.sync'))
        ->assertForbidden();
});
