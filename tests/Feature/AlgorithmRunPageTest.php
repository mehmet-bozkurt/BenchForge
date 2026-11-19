<?php

use App\Exceptions\BenchmarkRunnerException;
use App\Models\BenchmarkRun;
use App\Models\ModelRun;
use App\Models\ModelRunAttempt;
use App\Models\StageResult;
use App\Services\ModelRunContinuationRunner;
use App\Services\ModelRunOutcome;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;

uses(LazilyRefreshDatabase::class);

/**
 * A failed algorithm run: the model finished, 20 of 27 unit tests passed.
 *
 * @param  array<string, mixed>  $metrics
 * @return array{0: BenchmarkRun, 1: ModelRun}
 */
function failedAlgorithmRun(array $metrics = ['passed' => 20, 'total' => 27, 'tampered' => []], string $slug = 'algo-failed-run'): array
{
    $run = BenchmarkRun::factory()->isolated()->create([
        'slug' => $slug,
        'schema' => 'algorithm',
        'metadata' => ['environment_profile' => 'isolated-v1', 'task_type' => 'algorithm', 'algorithm_task' => 'roman-numerals'],
        'stage_names' => ['opencode', 'tests'],
        'routes' => [],
    ]);
    $model = ModelRun::factory()->for($run)->create(['slug' => 'algo-model', 'model' => 'openrouter/algo-model', 'status' => 'fail', 'failed_stages' => ['tests']]);
    StageResult::factory()->for($model)->create(['stage' => 'opencode', 'status' => 'pass', 'exit_code' => 0]);
    StageResult::factory()->for($model)->create(['stage' => 'tests', 'status' => 'fail', 'exit_code' => 1, 'metrics' => $metrics]);

    return [$run, $model->load('stageResults')];
}

test('a failed model of an algorithm run cannot be recovered', function () {
    [$run, $model] = failedAlgorithmRun();
    $runner = app(ModelRunContinuationRunner::class);

    expect($runner->availability($run, $model))->toBe([
        'canContinue' => false,
        'canValidate' => false,
        'reason' => ModelRunContinuationRunner::ALGORITHM_UNAVAILABLE_MESSAGE,
        'sessionId' => null,
    ]);

    foreach (['continue', 'validate'] as $mode) {
        expect(fn () => $runner->start($run, $model, $mode))
            ->toThrow(BenchmarkRunnerException::class, ModelRunContinuationRunner::ALGORITHM_UNAVAILABLE_MESSAGE);
    }

    expect(ModelRunAttempt::count())->toBe(0);
});

test('the recovery request for an algorithm run is refused with an explanation', function () {
    [$run, $model] = failedAlgorithmRun();

    $this->from(route('runs.show', $run))
        ->post(route('runs.models.attempts.store', [$run, $model]), ['mode' => 'validate'])
        ->assertRedirect(route('runs.show', $run))
        ->assertSessionHasErrors(['continuation' => ModelRunContinuationRunner::ALGORITHM_UNAVAILABLE_MESSAGE]);

    expect(ModelRunAttempt::count())->toBe(0);
});

test('the run page of an algorithm run shows test counts and offers no recovery', function () {
    [$run] = failedAlgorithmRun();

    $this->get(route('runs.show', $run))
        ->assertOk()
        ->assertSee('20/27')
        ->assertSee('Birim testler geçmedi')
        ->assertSee('Ortam ve görev')
        ->assertSee('roman-numerals')
        ->assertDontSee('Zorunlu rotalar')
        ->assertDontSee('Devam denemesi başlat')
        ->assertDontSee('Yalnız doğrulamayı çalıştır')
        ->assertDontSee('Puanlanamaz')
        ->assertDontSee('Çıktı yok');
});

test('a site run still offers recovery for a failed model', function () {
    $run = BenchmarkRun::factory()->isolated()->create(['stage_names' => ['opencode', 'lint']]);
    $model = ModelRun::factory()->for($run)->create(['status' => 'fail']);
    StageResult::factory()->for($model)->create(['stage' => 'opencode', 'status' => 'pass', 'exit_code' => 0]);
    StageResult::factory()->for($model)->create(['stage' => 'lint', 'status' => 'fail', 'exit_code' => 1]);

    $this->get(route('runs.show', $run))
        ->assertOk()
        ->assertSee('Kurtarma')
        ->assertSee('Zorunlu rotalar')
        ->assertDontSee('Ortam ve görev');
});

test('it explains failed unit tests with the count and the first failing test', function () {
    [$run, $model] = failedAlgorithmRun(['passed' => 20, 'total' => 27, 'tampered' => ['roman-numerals.spec.js']], 'algo-outcome-run');
    File::ensureDirectoryExists(config('benchforge.runs_path').'/algo-outcome-run/_logs');
    File::put(config('benchforge.runs_path').'/algo-outcome-run/_logs/algo-model.tests.log', "BENCHFORGE_TESTS passed=20 total=27\nBASARISIZ: converts 4 to IV\nBeklenen: \"IV\" ile esit\nBASARISIZ: converts 9 to IX\n");

    $outcome = app(ModelRunOutcome::class)->describe($run, $model);

    expect($outcome['tone'])->toBe('fail')
        ->and($outcome['title'])->toBe('Birim testler geçmedi')
        ->and($outcome['detail'])->toBe('20/27 test geçti. İlk başarısız test: converts 4 to IV Model test dosyalarını değiştirdi; doğrulamada orijinalleri kullanıldı.');
});
