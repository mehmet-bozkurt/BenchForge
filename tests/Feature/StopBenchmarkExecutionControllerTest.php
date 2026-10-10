<?php

use App\Models\BenchmarkExecution;
use App\Services\BenchmarkRunner;

use function Pest\Laravel\mock;

test('it sends a stop request for the active local benchmark', function () {
    $execution = BenchmarkExecution::factory()->make(['run_id' => 'nova-v2']);
    mock(BenchmarkRunner::class)->shouldReceive('stop')->once()->andReturn($execution);

    $response = $this->delete(route('runner.stop'));

    $response
        ->assertRedirect(route('runner.create'))
        ->assertSessionHas('status', 'nova-v2 için durdurma isteği gönderildi.');
});

test('it returns forbidden when a non local address tries to stop a benchmark', function () {
    mock(BenchmarkRunner::class)->shouldNotReceive('stop');

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
        ->delete(route('runner.stop'));

    $response->assertForbidden();
});
