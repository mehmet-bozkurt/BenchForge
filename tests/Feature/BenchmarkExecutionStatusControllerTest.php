<?php

use App\Services\BenchmarkRunner;

use function Pest\Laravel\mock;

test('it returns the current runner snapshot without caching', function () {
    $snapshot = [
        'status' => 'running',
        'active' => true,
        'runId' => 'nova-v2',
        'progress' => [
            'completedModels' => 2,
            'totalModels' => 5,
            'percent' => 40,
            'phase' => 'Modeller üretiliyor ve doğrulanıyor',
        ],
    ];
    mock(BenchmarkRunner::class)->shouldReceive('snapshot')->once()->andReturn($snapshot);

    $response = $this->getJson(route('runner.status'));

    $response
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson($snapshot);
});
