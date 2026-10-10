<?php

use App\Models\BenchmarkRun;
use App\Models\ModelRun;
use App\Models\ModelRunAttempt;
use App\Services\ModelRunContinuationRunner;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

use function Pest\Laravel\mock;

uses(LazilyRefreshDatabase::class);

test('it starts a continuation after the token cost is acknowledged', function () {
    $run = BenchmarkRun::factory()->create(['slug' => 'continue-run']);
    $modelRun = ModelRun::factory()->for($run)->create([
        'slug' => 'failed-model',
        'status' => 'fail',
    ]);
    $attempt = ModelRunAttempt::factory()->for($modelRun)->make(['mode' => 'continue']);

    mock(ModelRunContinuationRunner::class)
        ->shouldReceive('start')
        ->once()
        ->withArgs(fn (BenchmarkRun $resolvedRun, ModelRun $resolvedModel, string $mode): bool => $resolvedRun->is($run)
            && $resolvedModel->is($modelRun)
            && $mode === 'continue')
        ->andReturn($attempt);

    $response = $this
        ->from(route('runs.show', $run))
        ->post(route('runs.models.attempts.store', [$run, $modelRun]), [
            'mode' => 'continue',
            'acknowledge_cost' => '1',
        ]);

    $response
        ->assertRedirect(route('runs.show', $run))
        ->assertSessionHas('status', 'Kaldığı yerden devam denemesi başlatıldı.')
        ->assertSessionHasNoErrors();
});

test('it requires an explicit token cost acknowledgement for continuation', function () {
    $run = BenchmarkRun::factory()->create();
    $modelRun = ModelRun::factory()->for($run)->create(['status' => 'fail']);

    mock(ModelRunContinuationRunner::class)->shouldNotReceive('start');

    $response = $this
        ->from(route('runs.show', $run))
        ->post(route('runs.models.attempts.store', [$run, $modelRun]), [
            'mode' => 'continue',
        ]);

    $response
        ->assertRedirect(route('runs.show', $run))
        ->assertSessionHasErrors([
            'acknowledge_cost' => 'Devam işleminin yeni token ve ücret oluşturabileceğini onaylayın.',
        ]);
});

test('it starts validation without a token cost acknowledgement', function () {
    $run = BenchmarkRun::factory()->create(['slug' => 'validate-run']);
    $modelRun = ModelRun::factory()->for($run)->create([
        'slug' => 'failed-model',
        'status' => 'fail',
    ]);
    $attempt = ModelRunAttempt::factory()->for($modelRun)->make(['mode' => 'validate']);

    mock(ModelRunContinuationRunner::class)
        ->shouldReceive('start')
        ->once()
        ->withArgs(fn (BenchmarkRun $resolvedRun, ModelRun $resolvedModel, string $mode): bool => $resolvedRun->is($run)
            && $resolvedModel->is($modelRun)
            && $mode === 'validate')
        ->andReturn($attempt);

    $response = $this
        ->from(route('runs.show', $run))
        ->post(route('runs.models.attempts.store', [$run, $modelRun]), [
            'mode' => 'validate',
        ]);

    $response
        ->assertRedirect(route('runs.show', $run))
        ->assertSessionHas('status', 'Token harcamayan doğrulama başlatıldı.')
        ->assertSessionHasNoErrors();
});

test('it forbids non local continuation requests', function () {
    $run = BenchmarkRun::factory()->create();
    $modelRun = ModelRun::factory()->for($run)->create(['status' => 'fail']);

    mock(ModelRunContinuationRunner::class)->shouldNotReceive('start');

    $this
        ->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
        ->post(route('runs.models.attempts.store', [$run, $modelRun]), [
            'mode' => 'validate',
        ])
        ->assertForbidden();
});

test('it rejects a model that belongs to another benchmark run', function () {
    $firstRun = BenchmarkRun::factory()->create();
    $secondRun = BenchmarkRun::factory()->create();
    $modelRun = ModelRun::factory()->for($secondRun)->create(['status' => 'fail']);

    mock(ModelRunContinuationRunner::class)->shouldNotReceive('start');

    $this->post(route('runs.models.attempts.store', [$firstRun, $modelRun]), [
        'mode' => 'validate',
    ])->assertNotFound();
});
