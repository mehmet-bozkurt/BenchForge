<?php

use App\Models\Artifact;
use App\Models\BenchmarkRun;
use App\Models\ModelRun;
use App\Models\ModelRunAttempt;
use App\Models\StageResult;
use App\Services\ModelRunBoundaryReport;
use App\Services\ModelRunContinuationRunner;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

use function Pest\Laravel\mock;

uses(LazilyRefreshDatabase::class);

test('it shows a benchmark run report with stage logs and route metadata', function () {
    $run = BenchmarkRun::factory()->create([
        'slug' => 'nova-marketplace-v1',
        'name' => 'Nova Marketplace V1',
        'schema' => 'extended',
        'metadata' => ['prompt' => 'Marketplace benchmark', 'openrouter_routing' => 'fixed', 'openrouter_requested_providers' => 'minimax/minimax-m3:coreweave/fp4'],
        'routes' => ['/', '/search'],
        'stage_names' => ['lint', 'build', 'runtime'],
        'model_count' => 1,
        'passed_count' => 0,
        'failed_count' => 1,
        'total_bytes' => 3_000_000,
        'workspace_bytes' => 2_500_000,
        'result_bytes' => 500_000,
        'workspace_count' => 1,
        'storage_scanned_at' => now(),
    ]);

    $modelRun = ModelRun::factory()->for($run)->create([
        'slug' => 'minimax-m3',
        'model' => 'opencode-go/minimax-m3',
        'status' => 'fail',
        'duration_seconds' => 859,
        'usage' => [
            'source' => 'opencode_session',
            'costUsd' => '0.014562',
            'inputTokens' => 12345,
            'outputTokens' => 6789,
        ],
    ]);

    StageResult::factory()->for($modelRun)->create([
        'stage' => 'runtime',
        'status' => 'fail',
        'exit_code' => 1,
    ]);
    ModelRunAttempt::factory()->for($modelRun)->create([
        'attempt_number' => 1,
        'mode' => 'validate',
        'status' => 'completed',
        'result' => [
            'mode' => 'validate',
            'durationSeconds' => 8,
            'overallStatus' => 'fail',
            'failedStages' => ['runtime'],
            'stages' => [],
        ],
        'message' => '<script>alert("unsafe")</script>',
        'usage' => [
            'source' => 'validation_only',
            'costUsd' => '0',
            'inputTokens' => 0,
            'outputTokens' => 0,
        ],
    ]);
    ModelRunAttempt::factory()->for($modelRun)->create([
        'attempt_number' => 2,
        'mode' => 'continue',
        'status' => 'stopped',
        'result' => null,
        'usage' => [
            'source' => 'opencode_session',
            'costUsd' => '0.002540',
            'inputTokens' => 250,
            'outputTokens' => 90,
        ],
    ]);

    $artifact = Artifact::factory()->for($run)->for($modelRun)->create([
        'artifact_key' => 'model:minimax-m3:log:runtime',
        'kind' => 'file',
        'label' => 'Runtime logu',
        'path' => '/tmp/runtime.log',
    ]);
    $continuationRunner = mock(ModelRunContinuationRunner::class);
    $continuationRunner->shouldReceive('reconcileForRun')->once()->withArgs(fn (BenchmarkRun $resolved): bool => $resolved->is($run));
    $continuationRunner->shouldReceive('availability')->once()->andReturn([
        'canContinue' => false,
        'canValidate' => true,
        'reason' => null,
        'sessionId' => null,
    ]);

    $response = $this->get(route('runs.show', $run));

    $response
        ->assertOk()
        ->assertSee('Nova Marketplace V1')
        ->assertSee('minimax-m3')
        ->assertSee('Runtime logu')
        ->assertSee('Depolama kullanımı')
        ->assertSee('3.000.000 byte')
        ->assertSee('Çalışma alanlarını sil')
        ->assertSee('Yalnızca sonuç arşivini sil')
        ->assertSee('Benchmarkı tamamen sil')
        ->assertSee('OpenRouter yönlendirme tercihi')
        ->assertSee('Sabit sağlayıcı; fallback kapalı')
        ->assertSee('İstenen sabit sağlayıcılar')
        ->assertSee('minimax/minimax-m3:coreweave/fp4')
        ->assertSee('Kaldığı yerden devam ettir')
        ->assertSee('Aktif kurtarma: 0/2')
        ->assertSee('İki farklı model aynı anda devam edebilir')
        ->assertSee('Yalnız doğrulamayı çalıştır')
        ->assertSee('Deneme 1')
        ->assertSee('Deneme 2')
        ->assertSee('Durduruldu')
        ->assertSee('Maliyet OpenCode kaydıdır; OpenRouter faturası değildir.')
        ->assertSee('$0,014562')
        ->assertSee('Girdi 12.345')
        ->assertSee('Yalnız doğrulama: yeni model tokeni veya model maliyeti yok.')
        ->assertSee('OpenCode kaydı: $0,002540')
        ->assertSee('&lt;script&gt;alert(&quot;unsafe&quot;)&lt;/script&gt;', escape: false)
        ->assertDontSee('<script>alert("unsafe")</script>', escape: false)
        ->assertSee(route('runs.artifacts.show', [$run, $artifact]), escape: false)
        ->assertSee('/search');
});

test('an unknown benchmark run returns not found', function () {
    $this->get('/runs/bilinmeyen-kosu')->assertNotFound();
});

test('it separates boundary warnings from original benchmark scores and escapes paths', function () {
    $run = BenchmarkRun::factory()->create(['failed_count' => 1]);
    $model = ModelRun::factory()->for($run)->create(['status' => 'fail']);
    $attempt = ModelRunAttempt::factory()->for($model)->create([
        'attempt_number' => 1,
        'status' => 'completed',
        'result' => ['overallStatus' => 'fail', 'stages' => []],
    ]);
    $runner = mock(ModelRunContinuationRunner::class);
    $runner->shouldReceive('reconcileForRun')->once();
    $runner->shouldReceive('availability')->once()->andReturn([
        'canContinue' => false, 'canValidate' => true, 'reason' => null, 'sessionId' => null,
    ]);
    $reports = mock(ModelRunBoundaryReport::class);
    $reports->shouldReceive('forModel')->once()->withArgs(
        fn (BenchmarkRun $resolvedRun, ModelRun $resolvedModel): bool => $resolvedRun->is($run) && $resolvedModel->is($model),
    )->andReturn([
        'policyVersion' => 'workspace-boundary-v1', 'blockedCount' => 1,
        'observedCrossModelReadCount' => 0, 'entries' => [],
    ]);
    $reports->shouldReceive('forAttempt')->once()->withArgs(
        fn (BenchmarkRun $resolvedRun, ModelRun $resolvedModel, ModelRunAttempt $resolvedAttempt): bool => $resolvedRun->is($run)
            && $resolvedModel->is($model) && $resolvedAttempt->is($attempt),
    )->andReturn([
        'policyVersion' => 'workspace-boundary-v1', 'blockedCount' => 3,
        'observedCrossModelReadCount' => 1, 'stoppedForBoundary' => true,
        'entries' => [[
            'kind' => 'blocked', 'tool' => 'read', 'reason' => 'Kapsam dışı erişim engellendi.',
            'path' => '<script>alert("unsafe-path")</script>',
        ]],
    ]);

    $this->get(route('runs.show', $run))
        ->assertOk()
        ->assertSee('Model çalışma sınırları')
        ->assertSee('Yeni sınır politikası kaydı var')
        ->assertSee('Engellenen erişim: 4')
        ->assertSee('Karşılaştırma uyarısı: 1 araç çıktısında başka modele ait kayıt görüldü.')
        ->assertSee('Tekrarlanan sınır ihlalleri nedeniyle model çağrısı durduruldu.')
        ->assertSee('&lt;script&gt;alert(&quot;unsafe-path&quot;)&lt;/script&gt;', escape: false)
        ->assertDontSee('<script>alert("unsafe-path")</script>', escape: false)
        ->assertSee('name="confirmation" value=""', escape: false)
        ->assertDontSee('type="text" name="confirmation"', escape: false);

    expect($model->fresh()->status)->toBe('fail');
    expect($run->fresh()->failed_count)->toBe(1);
    expect($attempt->fresh()->result['overallStatus'])->toBe('fail');
});

test('it shows a recovery slot occupied by another benchmark run', function () {
    $run = BenchmarkRun::factory()->create(['failed_count' => 1]);
    $modelRun = ModelRun::factory()->for($run)->create(['status' => 'fail']);
    $otherRun = BenchmarkRun::factory()->create();
    $otherModel = ModelRun::factory()->for($otherRun)->create(['status' => 'fail']);
    ModelRunAttempt::factory()->for($otherModel)->create(['status' => 'running']);

    $continuationRunner = mock(ModelRunContinuationRunner::class);
    $continuationRunner->shouldReceive('reconcileForRun')->once();
    $continuationRunner->shouldReceive('availability')->once()->withArgs(
        fn (BenchmarkRun $resolvedRun, ModelRun $resolvedModel): bool => $resolvedRun->is($run)
            && $resolvedModel->is($modelRun),
    )->andReturn([
        'canContinue' => false,
        'canValidate' => false,
        'reason' => 'Başka deneme çalışıyor.',
        'sessionId' => null,
    ]);

    $this->get(route('runs.show', $run))
        ->assertOk()
        ->assertSee('Aktif kurtarma: 1/2')
        ->assertSee('data-attempt-refresh', escape: false);
});
