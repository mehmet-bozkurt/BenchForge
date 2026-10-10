<?php

use App\Exceptions\BenchmarkRunnerException;
use App\Models\OpenCodeGoModel;
use App\Models\OpenRouterModel;
use App\Models\PreparationComparison;
use App\Models\PreparationPackage;
use App\Services\PreparationRunner;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\mock;

uses(LazilyRefreshDatabase::class);

function preparationHttpPayload(string $modelId): array
{
    return [
        'title' => 'Ürün yönetimi şartnamesi',
        'specification' => 'Yönetici ürünleri ekleyebilmeli ve düzenleyebilmelidir. Kullanıcılar ürün listesini görüntüleyebilmelidir.',
        'technologies' => 'Laravel, Blade ve SQLite; dağıtım yapılmayacak.',
        'provider' => 'openrouter',
        'models' => [$modelId],
        'confirmed_external' => '1',
    ];
}

function preparationReviewPayload(): array
{
    return [
        'coverage_score' => 8, 'feasibility_score' => 7, 'clarity_score' => 9,
        'assumption_management_score' => 6, 'overall_score' => 8,
        'issue_severity' => 'minor', 'review_notes' => 'Zorunlu maddeler korunmuş; varsayımlar daha net olabilir.',
    ];
}

test('it renders preparation history without starting generation', function () {
    $comparison = PreparationComparison::factory()->create(['title' => 'Geçmiş hazırlık']);
    PreparationPackage::factory()->for($comparison)->create();
    mock(PreparationRunner::class)->shouldNotReceive('start');

    $this->get(route('preparations.index'))
        ->assertSee('Geçmiş hazırlık')
        ->assertSee(route('preparations.show', $comparison), escape: false)
        ->assertHeaderContains('Cache-Control', 'no-store');
});

test('it renders both catalogs and required technical inputs on the creation page', function () {
    $model = OpenRouterModel::factory()->create(['name' => 'OpenRouter hazırlık modeli']);
    $goModel = OpenCodeGoModel::factory()->create(['name' => 'Go hazırlık modeli']);
    mock(PreparationRunner::class)->shouldReceive('options')->once()->andReturn([
        'openRouterModels' => collect([$model]), 'openCodeGoModels' => collect([$goModel]),
        'catalog' => ['count' => 1, 'lastSyncedAt' => now()],
        'goCatalog' => ['count' => 1, 'lastSyncedAt' => now()],
        'connections' => ['cli' => ['status' => 'pass', 'message' => 'Hazır'], 'providers' => [
            'openrouter' => ['status' => 'pass', 'message' => 'Hazır'],
            'opencode-go' => ['status' => 'pass', 'message' => 'Hazır'],
        ]],
    ])->shouldNotReceive('start');

    $this->get(route('preparations.create'))
        ->assertSee('OpenRouter hazırlık modeli')->assertSee('Go hazırlık modeli')
        ->assertSee('name="technologies"', escape: false)
        ->assertSee('name="confirmed_external"', escape: false)
        ->assertSee('name="specification_file"', escape: false);
});

test('it starts preparation with only validated source technology and model selection', function () {
    $model = OpenRouterModel::factory()->create();
    $payload = preparationHttpPayload($model->model_id);
    $comparison = PreparationComparison::factory()->create();
    mock(PreparationRunner::class)->shouldReceive('start')->once()->with([
        'title' => $payload['title'], 'specification' => $payload['specification'],
        'technologies' => $payload['technologies'], 'provider' => $payload['provider'], 'models' => $payload['models'],
    ])->andReturn($comparison);

    $this->post(route('preparations.store'), [...$payload, 'status' => 'completed', 'command' => 'do-not-execute', 'runner_pid' => 123])
        ->assertRedirectToRoute('preparations.show', $comparison)
        ->assertSessionHas('status', 'Hazırlık paketi üretimi başlatıldı. Proje geliştirme başlatılmadı.');
});

test('it displays published model prices and escapes complete catalog details without external calls', function () {
    Http::preventStrayRequests();
    $model = OpenRouterModel::factory()->create([
        'model_id' => 'fixture/catalog-details',
        'description' => '<script>alert("description")</script>',
        'prompt_price' => '0.000000150000',
        'completion_price' => '0.000000000000',
        'context_length' => 128000,
        'catalog_payload' => [
            'id' => 'fixture/catalog-details', 'name' => 'Detaylı model',
            'description' => '<script>alert("description")</script>',
            'context_length' => 128000,
            'architecture' => ['tokenizer' => 'ExampleTokenizer', 'input_modalities' => ['text', 'image'], 'output_modalities' => ['text']],
            'pricing' => ['prompt' => '0.00000015', 'completion' => '0', 'request' => '0.01', 'input_cache_read' => '0.00000001'],
            'supported_parameters' => ['tools', 'temperature'],
            'top_provider' => ['max_completion_tokens' => 4242, 'is_moderated' => true],
            'future_field' => ['value' => '<img src=x onerror=alert(1)>'],
        ],
    ]);
    mock(PreparationRunner::class)->shouldReceive('options')->once()->andReturn([
        'openRouterModels' => collect([$model]), 'openCodeGoModels' => collect(),
    ])->shouldNotReceive('start');

    $this->get(route('preparations.create'))
        ->assertSee('OpenRouter yayımlanan birim fiyatı')
        ->assertSee('$0.15')
        ->assertSee('128.000 token')
        ->assertSee('4.242 token')
        ->assertSee('ExampleTokenizer')
        ->assertSee('input_cache_read')
        ->assertSee('future_field')
        ->assertSee('<script>alert("description")</script>')
        ->assertDontSee('<script>alert("description")</script>', escape: false)
        ->assertDontSee('<img src=x onerror=alert(1)>', escape: false)
        ->assertSee('Tüm model detayları')
        ->assertSee('https://openrouter.ai/api/v1/models');
    Http::assertNothingSent();
});

test('it identifies legacy metadata and does not present Go reference costs as official tariffs', function () {
    Http::preventStrayRequests();
    $model = OpenRouterModel::factory()->create([
        'prompt_price' => null, 'completion_price' => null, 'catalog_payload' => null,
    ]);
    $goModel = OpenCodeGoModel::factory()->create([
        'input_cost' => '0.150000', 'output_cost' => '0.600000', 'catalog_payload' => null,
    ]);
    mock(PreparationRunner::class)->shouldReceive('options')->once()->andReturn([
        'openRouterModels' => collect([$model]), 'openCodeGoModels' => collect([$goModel]),
    ])->shouldNotReceive('start');

    $this->get(route('preparations.create'))
        ->assertSee('Yayımlanmamış')
        ->assertSee('Bu kayıt eski katalogdan geliyor')
        ->assertSee('Katalogdan doğrulanamıyor')
        ->assertSee('models.dev referans maliyetleri')
        ->assertSee('Resmî Go tarifesi değildir')
        ->assertSee('https://opencode.ai/docs/go/#usage-limits');
    Http::assertNothingSent();
});

test('it accepts and normalizes a UTF8 TXT specification without storing the upload', function () {
    $model = OpenRouterModel::factory()->create();
    $payload = preparationHttpPayload($model->model_id);
    $source = "Yönetici ürünleri ekleyebilmeli ve düzenleyebilmelidir.\nKullanıcılar ürün listesini görüntüleyebilmelidir.";
    $comparison = PreparationComparison::factory()->create();
    mock(PreparationRunner::class)->shouldReceive('start')->once()->with([
        'title' => $payload['title'], 'specification' => $source,
        'technologies' => $payload['technologies'], 'provider' => 'openrouter', 'models' => [$model->model_id],
    ])->andReturn($comparison);
    unset($payload['specification']);

    $this->post(route('preparations.store'), [
        ...$payload, 'specification_file' => UploadedFile::fake()->createWithContent('sartname.txt', "\xEF\xBB\xBF".str_replace("\n", "\r\n", $source)),
    ])->assertRedirectToRoute('preparations.show', $comparison);
});

test('it accepts an available Go model in the selected provider catalog', function () {
    $model = OpenCodeGoModel::factory()->create();
    $payload = [...preparationHttpPayload($model->model_id), 'provider' => 'opencode-go'];
    $comparison = PreparationComparison::factory()->create(['provider' => 'opencode-go']);
    mock(PreparationRunner::class)->shouldReceive('start')->once()->withArgs(fn (array $input): bool => $input['provider'] === 'opencode-go' && $input['models'] === [$model->model_id]
    )->andReturn($comparison);

    $this->post(route('preparations.store'), $payload)->assertRedirectToRoute('preparations.show', $comparison);
});

test('it rejects missing preparation inputs without invoking the runner', function () {
    mock(PreparationRunner::class)->shouldNotReceive('start');

    $this->post(route('preparations.store'), [])->assertSessionHasErrors([
        'title', 'specification', 'specification_file', 'technologies', 'provider', 'models', 'confirmed_external',
    ]);
    $this->assertDatabaseCount('preparation_comparisons', 0);
});

test('it rejects invalid catalog selections or missing external consent before generation', function (string $case, string $field) {
    $model = OpenRouterModel::factory()->create();
    $payload = preparationHttpPayload($model->model_id);
    $payload = match ($case) {
        'unavailable' => $payload,
        'unknown' => [...$payload, 'models' => ['fixture/not-in-catalog']],
        'duplicate' => [...$payload, 'models' => [$model->model_id, $model->model_id]],
        'associative' => [...$payload, 'models' => ['selected' => $model->model_id]],
        'too-many' => [...$payload, 'models' => OpenRouterModel::factory()->count(6)->create()->pluck('model_id')->all()],
        'wrong-provider' => [...$payload, 'provider' => 'opencode-go'],
        'provider' => [...$payload, 'provider' => 'invalid'],
        'consent' => [...$payload, 'confirmed_external' => '0'],
    };
    if ($case === 'unavailable') {
        $model->update(['available' => false]);
    }
    mock(PreparationRunner::class)->shouldNotReceive('start');

    $this->post(route('preparations.store'), $payload)->assertSessionHasErrors([$field]);
    $this->assertDatabaseCount('preparation_comparisons', 0);
})->with([
    'unavailable model' => ['unavailable', 'models.0'],
    'unknown model' => ['unknown', 'models.0'],
    'duplicate model' => ['duplicate', 'models.0'],
    'non-list selection' => ['associative', 'models'],
    'more than five' => ['too-many', 'models'],
    'wrong catalog' => ['wrong-provider', 'models.0'],
    'invalid provider' => ['provider', 'provider'],
    'external consent refused' => ['consent', 'confirmed_external'],
]);

test('it rejects ambiguous invalid and oversized specification sources', function (string $case, string $field) {
    $model = OpenRouterModel::factory()->create();
    $payload = preparationHttpPayload($model->model_id);
    $payload = match ($case) {
        'both' => [...$payload, 'specification_file' => UploadedFile::fake()->createWithContent('sartname.txt', $payload['specification'])],
        'short' => [...$payload, 'specification' => 'Kısa şartname.'],
        'long' => [...$payload, 'specification' => str_repeat('a', 100001)],
        'binary' => [...$payload, 'specification' => $payload['specification']."\0"],
        'non-utf8' => [...$payload, 'specification' => $payload['specification']."\xFF"],
        'pdf' => [...array_diff_key($payload, ['specification' => true]), 'specification_file' => UploadedFile::fake()->create('sartname.pdf', 10, 'application/pdf')],
        'extension' => [...array_diff_key($payload, ['specification' => true]), 'specification_file' => UploadedFile::fake()->createWithContent('sartname.html', $payload['specification'])],
        'oversized-file' => [...array_diff_key($payload, ['specification' => true]), 'specification_file' => UploadedFile::fake()->create('sartname.txt', 1025, 'text/plain')],
    };
    mock(PreparationRunner::class)->shouldNotReceive('start');

    $this->post(route('preparations.store'), $payload)->assertSessionHasErrors([$field]);
    $this->assertDatabaseCount('preparation_comparisons', 0);
})->with([
    'two sources' => ['both', 'specification'], 'too short' => ['short', 'specification'],
    'too long' => ['long', 'specification'], 'NUL byte' => ['binary', 'specification'],
    'invalid encoding' => ['non-utf8', 'specification'], 'PDF not supported' => ['pdf', 'specification_file'],
    'wrong file extension' => ['extension', 'specification_file'], 'file exceeds 1MB' => ['oversized-file', 'specification_file'],
]);

test('it preserves validated form inputs when generation cannot start', function () {
    $model = OpenRouterModel::factory()->create();
    mock(PreparationRunner::class)->shouldReceive('start')->once()->andThrow(new BenchmarkRunnerException('Bir hazırlık karşılaştırması halen devam ediyor.'));

    $this->from(route('preparations.create'))->post(route('preparations.store'), preparationHttpPayload($model->model_id))
        ->assertRedirectToRoute('preparations.create')
        ->assertSessionHasErrors(['preparation' => 'Bir hazırlık karşılaştırması halen devam ediyor.'])
        ->assertSessionHasInput('technologies', 'Laravel, Blade ve SQLite; dağıtım yapılmayacak.');
});

test('it scopes package downloads and evaluations to their own comparison', function () {
    $comparison = PreparationComparison::factory()->create();
    $other = PreparationComparison::factory()->create();
    $package = PreparationPackage::factory()->for($other)->create();

    $this->get(route('preparations.packages.download', [$comparison, $package]))->assertNotFound();
    $this->put(route('preparations.packages.evaluation.update', [$comparison, $package]), preparationReviewPayload())->assertNotFound();
    expect($package->fresh()->manual_evaluation)->toBeNull();
});

test('it downloads a complete UTF8 Markdown package or an individual section as an attachment', function () {
    $comparison = PreparationComparison::factory()->create();
    $package = PreparationPackage::factory()->for($comparison)->create();

    $this->get(route('preparations.packages.download', [$comparison, $package]))
        ->assertDownload('benchforge-hazirlik-'.$comparison->id.'-'.$package->id.'.md')
        ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeaderContains('Cache-Control', 'no-store')
        ->assertSee($comparison->specification, escape: false)
        ->assertSee($comparison->technologies, escape: false)
        ->assertSee($package->startup_prompt, escape: false)
        ->assertSee('Gerçek proje geliştirme ve tamamlanma başarısı henüz test edilmedi.', escape: false);
    $this->get(route('preparations.packages.download', [$comparison, $package, 'section' => 'memory_bank']))
        ->assertDownload('benchforge-hazirlik-'.$comparison->id.'-'.$package->id.'-memory_bank.md')
        ->assertSee($package->memory_bank, escape: false)->assertDontSee($package->startup_prompt, escape: false);
});

test('it rejects invalid download sections and incomplete packages', function () {
    $comparison = PreparationComparison::factory()->create();
    $package = PreparationPackage::factory()->for($comparison)->create();

    $this->get(route('preparations.packages.download', [$comparison, $package, 'section' => '../../secret']))->assertNotFound();
    $this->get(route('preparations.packages.download', [$comparison, $package, 'section' => ['memory_bank']]))->assertNotFound();
    $package->update(['status' => 'failed']);
    $this->get(route('preparations.packages.download', [$comparison, $package]))->assertConflict();
    $this->put(route('preparations.packages.evaluation.update', [$comparison, $package]), preparationReviewPayload())->assertConflict();
    expect($package->fresh()->manual_evaluation)->toBeNull();
});

test('it saves and updates human scores without accepting generated content or execution fields', function () {
    $comparison = PreparationComparison::factory()->create();
    $package = PreparationPackage::factory()->for($comparison)->create();

    $this->put(route('preparations.packages.evaluation.update', [$comparison, $package]), [
        ...preparationReviewPayload(), 'status' => 'running', 'startup_prompt' => 'Overwritten prompt', 'cost_usd' => 999,
    ])->assertRedirectToRoute('preparations.show', $comparison);
    expect($package->fresh()->manual_evaluation)->toBe([
        'coverage_score' => 8, 'feasibility_score' => 7, 'clarity_score' => 9,
        'assumption_management_score' => 6, 'overall_score' => 8,
    ]);
    $this->assertDatabaseHas('preparation_packages', ['id' => $package->id, 'status' => 'completed', 'issue_severity' => 'minor']);
    expect($package->fresh()->startup_prompt)->not->toBe('Overwritten prompt');
    expect($package->fresh()->cost_usd)->toBeNull();

    $this->put(route('preparations.packages.evaluation.update', [$comparison, $package]), [
        ...preparationReviewPayload(), 'overall_score' => '9', 'review_notes' => 'İkinci inceleme.',
    ])->assertRedirectToRoute('preparations.show', $comparison);
    expect($package->fresh()->manual_evaluation['overall_score'])->toBe(9);
    $this->assertDatabaseHas('preparation_packages', ['id' => $package->id, 'review_notes' => 'İkinci inceleme.']);
    $this->assertDatabaseCount('preparation_packages', 1);
});

test('it refuses invalid human review scores and notes without persisting a review', function (array $invalid, string $field) {
    $comparison = PreparationComparison::factory()->create();
    $package = PreparationPackage::factory()->for($comparison)->create();

    $this->put(route('preparations.packages.evaluation.update', [$comparison, $package]), [...preparationReviewPayload(), ...$invalid])
        ->assertSessionHasErrors([$field]);
    expect($package->fresh()->manual_evaluation)->toBeNull();
})->with([
    'above range' => [['coverage_score' => 11], 'coverage_score'],
    'below range' => [['feasibility_score' => 0], 'feasibility_score'],
    'fractional score' => [['clarity_score' => 5.5], 'clarity_score'],
    'unknown severity' => [['issue_severity' => 'invented'], 'issue_severity'],
    'excessive notes' => [['review_notes' => str_repeat('a', 5001)], 'review_notes'],
]);

test('it restores a rejected review only to the route bound package without changing stored reviews', function () {
    $comparison = PreparationComparison::factory()->create();
    $package = PreparationPackage::factory()->for($comparison)->create();
    $other = PreparationPackage::factory()->for($comparison)->create(['review_notes' => 'Diğer paketin mevcut incelemesi.']);
    $notes = 'Kaybolmaması gereken taslak inceleme notları.';
    mock(PreparationRunner::class)->shouldReceive('reconcile')->once()->andReturn($comparison);

    $this->from(route('preparations.show', $comparison))
        ->put(route('preparations.packages.evaluation.update', [$comparison, $package]), [
            ...preparationReviewPayload(), 'coverage_score' => 11,
            'review_notes' => $notes, 'review_package_id' => $other->id,
        ])->assertSessionHasErrors(['coverage_score'])->assertSessionHasInput('review_package_id', $package->id);

    $response = $this->get(route('preparations.show', $comparison));
    $response->assertOk()->assertSee($notes)->assertSee($other->review_notes);
    expect(substr_count($response->getContent(), e($notes)))->toBe(1)
        ->and($package->fresh()->manual_evaluation)->toBeNull()
        ->and($package->fresh()->review_notes)->toBeNull()
        ->and($other->fresh()->review_notes)->toBe('Diğer paketin mevcut incelemesi.');
});

test('it exposes only progress metadata in status JSON without source or generated text', function () {
    $comparison = PreparationComparison::factory()->create();
    $package = PreparationPackage::factory()->for($comparison)->create();
    mock(PreparationRunner::class)->shouldReceive('reconcile')->once()->withArgs(fn (PreparationComparison $model): bool => $model->is($comparison))->andReturn($comparison);

    $this->getJson(route('preparations.status', $comparison))
        ->assertJsonPath('status', 'completed')->assertJsonPath('active', false)
        ->assertJsonPath('completedModels', 1)->assertJsonPath('totalModels', 1)
        ->assertJsonPath('packages.0.id', $package->id)->assertJsonPath('packages.0.costUsd', null)
        ->assertJsonMissingPath('specification')->assertJsonMissingPath('working_directory')
        ->assertJsonMissingPath('packages.0.startup_prompt')->assertHeaderContains('Cache-Control', 'no-store');
});

test('it does not count failed or stopped packages as successfully completed documents', function () {
    $comparison = PreparationComparison::factory()->create(['status' => 'partial']);
    PreparationPackage::factory()->for($comparison)->create();
    PreparationPackage::factory()->for($comparison)->create(['status' => 'failed']);
    PreparationPackage::factory()->for($comparison)->create(['status' => 'stopped']);
    mock(PreparationRunner::class)->shouldReceive('reconcile')->once()->andReturn($comparison);

    $this->getJson(route('preparations.status', $comparison))
        ->assertJsonPath('completedModels', 1)->assertJsonPath('totalModels', 3);
});

test('it rejects invalid title and technology text before starting generation', function (string $field, string $suffix) {
    $model = OpenRouterModel::factory()->create();
    $payload = preparationHttpPayload($model->model_id);
    $payload[$field] .= $suffix;
    mock(PreparationRunner::class)->shouldNotReceive('start');

    $this->post(route('preparations.store'), $payload)->assertSessionHasErrors([$field]);
    $this->assertDatabaseCount('preparation_comparisons', 0);
})->with([
    'title control byte' => ['title', "\0"],
    'technology encoding' => ['technologies', "\xFF"],
]);

test('it renders source model outputs and review notes safely without claiming project completion', function () {
    $dangerous = "<script>alert('preparation-xss')</script>";
    $comparison = PreparationComparison::factory()->create(['title' => $dangerous, 'specification' => $dangerous, 'technologies' => $dangerous]);
    PreparationPackage::factory()->for($comparison)->create([
        'model_name' => $dangerous, 'startup_prompt' => $dangerous,
        'requirements' => $dangerous, 'memory_bank' => $dangerous, 'implementation_plan' => $dangerous,
        'assumptions_risks' => $dangerous, 'review_notes' => $dangerous,
        'manual_evaluation' => array_diff_key(preparationReviewPayload(), ['issue_severity' => true, 'review_notes' => true]),
    ]);
    mock(PreparationRunner::class)->shouldReceive('reconcile')->once()->andReturn($comparison);

    $this->get(route('preparations.show', $comparison))
        ->assertSee('&lt;script&gt;alert', escape: false)->assertDontSee($dangerous, escape: false)
        ->assertSee('henüz test edilmedi')->assertSee(route('preparations.packages.evaluation.update', [$comparison, $comparison->packages()->first()]), escape: false);
});

test('it stops only the selected preparation execution through the verified runner boundary', function () {
    $comparison = PreparationComparison::factory()->create(['status' => 'running', 'finished_at' => null]);
    mock(PreparationRunner::class)->shouldReceive('stop')->once()->withArgs(fn (PreparationComparison $model): bool => $model->is($comparison))->andReturn($comparison);

    $this->delete(route('preparations.stop', $comparison))->assertRedirectToRoute('preparations.show', $comparison);
});

test('it refuses nonlocal access to preparation sources exports progress and writes', function () {
    $comparison = PreparationComparison::factory()->create();
    $package = PreparationPackage::factory()->for($comparison)->create();
    mock(PreparationRunner::class)->shouldNotReceive('start')->shouldNotReceive('reconcile')->shouldNotReceive('options')->shouldNotReceive('stop');
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10']);

    $this->get(route('preparations.index'))->assertForbidden();
    $this->get(route('preparations.create'))->assertForbidden();
    $this->get(route('preparations.show', $comparison))->assertForbidden();
    $this->getJson(route('preparations.status', $comparison))->assertForbidden();
    $this->get(route('preparations.packages.download', [$comparison, $package]))->assertForbidden();
    $this->post(route('preparations.store'), [])->assertForbidden();
    $this->put(route('preparations.packages.evaluation.update', [$comparison, $package]), preparationReviewPayload())->assertForbidden();
    $this->delete(route('preparations.stop', $comparison))->assertForbidden();
    expect($package->fresh()->manual_evaluation)->toBeNull();
});
