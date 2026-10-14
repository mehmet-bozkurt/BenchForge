<?php

use App\Models\BenchmarkRun;
use App\Models\ModelRun;
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
