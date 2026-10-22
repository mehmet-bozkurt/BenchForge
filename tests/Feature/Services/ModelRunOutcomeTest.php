<?php

use App\Models\BenchmarkRun;
use App\Models\ModelRun;
use App\Models\ModelRunAttempt;
use App\Models\StageResult;
use App\Services\ModelRunOutcome;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;

uses(LazilyRefreshDatabase::class);

/**
 * @param  array<string, array{0: string, 1: ?int}>  $stages
 * @param  array<string, string>  $logs
 * @return array{0: BenchmarkRun, 1: ModelRun}
 */
function outcomeFixture(array $stages, array $logs = [], string $status = 'fail'): array
{
    $run = BenchmarkRun::factory()->create(['slug' => 'outcome-run']);
    $model = ModelRun::factory()->for($run)->create(['slug' => 'fixture-model', 'status' => $status]);

    foreach ($stages as $stage => [$stageStatus, $code]) {
        StageResult::factory()->for($model)->create(['stage' => $stage, 'status' => $stageStatus, 'exit_code' => $code]);
    }

    File::ensureDirectoryExists(config('benchforge.runs_path').'/outcome-run/_logs');

    foreach ($logs as $name => $content) {
        File::put(config('benchforge.runs_path').'/outcome-run/_logs/fixture-model.'.$name, $content);
    }

    return [$run, $model->load('stageResults')];
}

test('it explains an output-limit stop without a submission', function () {
    [$run, $model] = outcomeFixture(['opencode' => ['fail', StageResult::NO_SUBMISSION_CODE]], [
        'opencode.events.jsonl' => json_encode(['type' => 'step_finish', 'part' => ['reason' => 'length', 'tokens' => ['reasoning' => 32000, 'output' => 0]]])."\n",
    ]);

    expect(app(ModelRunOutcome::class)->describe($run, $model))->toMatchArray([
        'tone' => 'fail',
        'title' => 'Çıktı sınırına takıldı, hiç dosya yazmadı',
        'autoContinued' => false,
    ])->and(app(ModelRunOutcome::class)->describe($run, $model)['detail'])->toContain('düşünme 32.000');
});

test('it shows the provider message for unscored and cut-off runs', function (int $code, string $tone, string $title) {
    [$run, $model] = outcomeFixture(['opencode' => ['fail', $code]], [
        'opencode.events.jsonl' => json_encode(['type' => 'error', 'error' => ['name' => 'APIError', 'data' => ['message' => 'Upstream request failed: Endpoint is unavailable.']]])."\n",
    ]);

    expect(app(ModelRunOutcome::class)->describe($run, $model))->toMatchArray([
        'tone' => $tone,
        'title' => $title,
        'detail' => 'Upstream request failed: Endpoint is unavailable.',
    ]);
})->with([
    'provider error' => [StageResult::PROVIDER_ERROR_CODE, 'provider', 'Sağlayıcı veya hesap hatası · puanlanmaz'],
    'cut off after submission' => [StageResult::INTERRUPTED_AFTER_SUBMISSION_CODE, 'warning', 'Bağlantı sonda koptu · teslim puanlandı'],
]);

test('it names the first failing check with the line that holds the error', function () {
    [$run, $model] = outcomeFixture([
        'opencode' => ['pass', 0],
        'lint' => ['pass', 0],
        'runtime' => ['fail', 1],
    ], [
        'runtime.log' => "✗ /\n  PAGE ERROR: Minified React error #418; visit https://react.dev/errors/418?args[]=HTML for the full message\n",
        'auto-continue.log' => "reason=output-limit\n",
    ]);

    expect(app(ModelRunOutcome::class)->describe($run, $model))->toMatchArray([
        'tone' => 'fail',
        'title' => 'Sayfa tarayıcıda hata verdi',
        'detail' => 'PAGE ERROR: Minified React error #418',
        'autoContinued' => true,
    ]);
});

test('it reports a passing model without reading logs', function () {
    [$run, $model] = outcomeFixture(['opencode' => ['pass', 0], 'build' => ['pass', 0]], status: 'pass');

    expect(app(ModelRunOutcome::class)->describe($run, $model))->toMatchArray(['tone' => 'pass', 'title' => 'Tüm kontroller geçti', 'detail' => null]);
});

test('it says when the provider stalled instead of blaming the model', function (int $code, string $tone, string $title) {
    [$run, $model] = outcomeFixture(['opencode' => ['fail', $code]], [
        'opencode.log' => "{\"type\":\"step_start\"}\nBENCHFORGE_STALLS count=2 seconds=3000 compensated=2700\n",
        'opencode.continue.log' => "BENCHFORGE_STALLS count=1 seconds=600 compensated=0\n",
    ]);

    $outcome = app(ModelRunOutcome::class)->describe($run, $model);

    expect($outcome)->toMatchArray(['tone' => $tone, 'title' => $title])
        ->and($outcome['detail'])->toContain('Sağlayıcı 3 kez yanıt vermedi (toplam 60 dk); 45 dakikası modelin süresinden sayılmadı.');
})->with([
    'not scored' => [StageResult::PROVIDER_ERROR_CODE, 'provider', 'Sağlayıcı yanıt vermedi · puanlanmaz'],
    'scored submission' => [StageResult::INTERRUPTED_AFTER_SUBMISSION_CODE, 'warning', 'Bağlantı sonda koptu · teslim puanlandı'],
    'still timed out' => [124, 'fail', 'Süre sınırı doldu'],
]);

test('it finds provider stalls in the event stream of runs from before the monitor counted them', function (int $minutesAfterStall, string $tone, string $title) {
    $start = 1792000000000;
    $events = collect([
        ['type' => 'step_start', 'timestamp' => $start],
        ['type' => 'step_finish', 'timestamp' => $start + 60_000],
        ['type' => 'step_start', 'timestamp' => $start + 61_000],
        ['type' => 'step_start', 'timestamp' => $start + 61_000 + 30 * 60_000],
        ['type' => 'step_finish', 'timestamp' => $start + 61_000 + (30 + $minutesAfterStall) * 60_000],
    ])->map(fn (array $event): string => json_encode($event))->implode("\n");
    [$run, $model] = outcomeFixture(['opencode' => ['fail', 124], 'lint' => ['pass', 0]], ['opencode.events.jsonl' => $events."\n"]);

    $outcome = app(ModelRunOutcome::class)->describe($run, $model);

    expect($outcome)->toMatchArray(['tone' => $tone, 'title' => $title])
        ->and($outcome['detail'])->toContain('Sağlayıcı 1 kez yanıt vermedi (toplam 30 dk).');
})->with([
    'mostly stalled' => [20, 'warning', 'Sağlayıcı takıldı, süre doldu'],
    'mostly working' => [40, 'fail', 'Süre sınırı doldu'],
]);

test('it marks outside causes instead of red and mutes an unscored model', function () {
    [$run, $model] = outcomeFixture(['opencode' => ['fail', StageResult::PROVIDER_ERROR_CODE], 'lint' => ['pass', 0], 'build' => ['fail', 1]]);

    expect(app(ModelRunOutcome::class)->describe($run, $model)['stageMarks'])->toEqual([
        'opencode' => ['variant' => 'provider', 'note' => 'sağlayıcı kaynaklı'],
        'lint' => ['variant' => 'muted', 'note' => 'puanlanmaz'],
        'build' => ['variant' => 'muted', 'note' => 'puanlanmaz'],
    ]);
});

test('a check that passes when the same code is validated again is a measurement failure', function () {
    [$run, $model] = outcomeFixture(['opencode' => ['pass', 0], 'lint' => ['pass', 0], 'runtime' => ['fail', 1]], ['runtime.log' => "CONSOLE ERROR: No WebGPU adapter found\n"]);
    $run->update(['stage_names' => ['opencode', 'lint', 'runtime']]);
    ModelRunAttempt::factory()->for($model)->create([
        'mode' => 'validate',
        'result' => ['mode' => 'validate', 'stages' => ['lint' => ['status' => 'pass'], 'runtime' => ['status' => 'pass']]],
    ]);

    $outcome = app(ModelRunOutcome::class)->describe($run, $model->fresh(['stageResults', 'modelRunAttempts']));

    expect($outcome)->toMatchArray([
        'tone' => 'warning',
        'title' => 'Ölçüm hatası · yeniden doğrulamada geçti',
        'stageMarks' => ['runtime' => ['variant' => 'provider', 'note' => 'yeniden doğrulamada geçti']],
        'recovery' => ['tone' => 'pass', 'label' => 'Yeniden doğrulama 1', 'title' => 'Tüm kontroller geçti'],
    ]);
});

test('a validation after the model continued says nothing about the first submission', function () {
    [$run, $model] = outcomeFixture(['opencode' => ['pass', 0], 'runtime' => ['fail', 1]], ['runtime.log' => "PAGE ERROR: boom\n"]);
    $run->update(['stage_names' => ['opencode', 'runtime']]);
    ModelRunAttempt::factory()->for($model)->create(['attempt_number' => 1, 'mode' => 'continue']);
    ModelRunAttempt::factory()->for($model)->create([
        'attempt_number' => 2,
        'mode' => 'validate',
        'result' => ['mode' => 'validate', 'stages' => ['runtime' => ['status' => 'pass']]],
    ]);

    $outcome = app(ModelRunOutcome::class)->describe($run, $model->fresh(['stageResults', 'modelRunAttempts']));

    expect($outcome)->toMatchArray(['tone' => 'fail', 'title' => 'Sayfa tarayıcıda hata verdi', 'stageMarks' => []]);
});

test('it summarises the latest finished recovery attempt', function (array $attempt, array $recovery) {
    [$run, $model] = outcomeFixture(['opencode' => ['fail', 124], 'lint' => ['pass', 0], 'build' => ['fail', 1]]);
    ModelRunAttempt::factory()->for($model)->create(['attempt_number' => 1, 'status' => 'stopped', 'result' => null]);
    ModelRunAttempt::factory()->for($model)->create(['attempt_number' => 2] + $attempt);
    ModelRunAttempt::factory()->for($model)->create(['attempt_number' => 3, 'status' => 'running', 'result' => null]);

    expect(app(ModelRunOutcome::class)->describe($run, $model->fresh(['stageResults', 'modelRunAttempts']))['recovery'])->toBe($recovery);
})->with([
    'checks pass after another timeout' => [
        ['result' => ['mode' => 'continue', 'stages' => ['opencode' => ['status' => 'fail', 'code' => 124], 'lint' => ['status' => 'pass'], 'build' => ['status' => 'pass']]]],
        ['tone' => 'pass', 'label' => 'Kurtarma 2', 'title' => 'Tüm kontroller geçti; model süresi yine doldu'],
    ],
    'a check still fails' => [
        ['result' => ['mode' => 'continue', 'stages' => ['opencode' => ['status' => 'pass', 'code' => 0], 'lint' => ['status' => 'fail'], 'build' => ['status' => 'pass']]]],
        ['tone' => 'fail', 'label' => 'Kurtarma 2', 'title' => 'Lint kontrolü başarısız'],
    ],
    'stopped without a result' => [
        ['status' => 'stopped', 'result' => null],
        ['tone' => 'unknown', 'label' => 'Kurtarma 2', 'title' => 'Durduruldu'],
    ],
]);

test('it says when a validation step was stopped at its time limit', function () {
    [$run, $model] = outcomeFixture(['opencode' => ['pass', 0], 'lint' => ['pass', 0], 'build' => ['fail', 124]], [
        'build.log' => "  FAIL  /cart/  errors=cart: confirmation message did not appear\n\nBENCHFORGE: Adim 900 saniyelik sure sinirini asti; komut ve baslattigi surecler durduruldu.\n",
    ]);

    $outcome = app(ModelRunOutcome::class)->describe($run, $model);

    expect($outcome)->toMatchArray(['tone' => 'fail', 'title' => 'Build süre sınırını aştı'])
        ->and($outcome['detail'])->toStartWith('Adım 15 dakikada bitmedi;');
});
