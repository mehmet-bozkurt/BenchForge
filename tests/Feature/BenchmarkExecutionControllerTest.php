<?php

use App\Models\BenchmarkExecution;
use App\Models\BenchmarkPrompt;
use App\Models\OpenCodeGoModel;
use App\Models\OpenRouterModel;
use App\Services\BenchmarkRunner;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;

use function Pest\Laravel\mock;

uses(LazilyRefreshDatabase::class);

test('it renders the runner with available prompts configs and idle state', function () {
    $catalogModel = OpenRouterModel::factory()->make([
        'model_id' => 'acme/nova-agent',
        'author' => 'acme',
        'name' => 'Nova Agent',
        'context_length' => 131072,
        'prompt_price' => '0.000001000000',
        'completion_price' => '0.000004000000',
    ]);
    $runner = mock(BenchmarkRunner::class);
    $runner->shouldReceive('connectionStatus')->once()->andReturn([
        'checkedAt' => '2026-09-21T10:00:00+03:00',
        'cli' => ['status' => 'pass', 'label' => 'OpenCode CLI', 'message' => '1.18.31'],
        'providers' => [
            'openrouter' => ['status' => 'pass', 'label' => 'OpenRouter', 'message' => 'OpenCode kimlik deposunda bağlantı hazır.'],
            'opencode-go' => ['status' => 'pass', 'label' => 'OpenCode Go', 'message' => 'OpenCode kimlik deposunda bağlantı hazır.'],
        ],
    ]);
    $runner->shouldReceive('options')->once()->andReturn([
        'prompts' => [['value' => 'prompts/nova.txt', 'label' => 'Nova']],
        'configs' => [[
            'value' => 'config/models.conf',
            'label' => 'Models',
            'modelCount' => 2,
            'ports' => [3101, 3102],
        ]],
        'openRouterModels' => collect([$catalogModel]),
        'catalog' => ['count' => 1, 'lastSyncedAt' => null],
        'suggestedRoutes' => ['/'],
    ]);
    $runner->shouldReceive('snapshot')->once()->andReturn([
        'status' => 'idle',
        'active' => false,
        'runId' => null,
        'prompt' => null,
        'config' => null,
        'routes' => [],
        'startedAt' => null,
        'finishedAt' => null,
        'exitCode' => null,
        'message' => 'Yeni bir benchmark başlatmaya hazır.',
        'logTail' => '',
        'outcome' => [
            'status' => 'unavailable',
            'label' => 'Henüz sonuç yok',
            'summary' => 'Yeni bir koşu tamamlandığında teknik sonuç burada gösterilecek.',
            'available' => false,
            'totalModels' => 0,
            'passedModels' => 0,
            'failedModels' => 0,
            'otherModels' => 0,
        ],
        'progress' => [
            'completedModels' => 0,
            'totalModels' => 0,
            'percent' => 0,
            'phase' => 'Hazır',
        ],
    ]);
    $runner->shouldReceive('preflight')->once()->with('config/models.conf')->andReturn([
        'ready' => true,
        'checkedAt' => '2026-09-19T10:00:00+03:00',
        'summary' => '1/1 kontrol geçti. Benchmark başlatılabilir.',
        'checks' => [[
            'key' => 'database',
            'label' => 'Veritabanı',
            'status' => 'pass',
            'message' => 'SQLITE bağlantısı hazır.',
        ]],
    ]);

    $response = $this->withSession(['_old_input' => [
        'routing_mode' => ['malformed'],
        'fixed_providers' => ['acme/nova-agent' => ['malformed']],
    ]])->get(route('runner.create'));

    $response
        ->assertOk()
        ->assertSee('Yeni Benchmark')
        ->assertSee('Bağlantı ve hesap durumu')
        ->assertSee('OpenCode kimlik deposunda bağlantı hazır.')
        ->assertSee('Anahtar değerleri okunmaz veya gösterilmez')
        ->assertSee('Nova')
        ->assertSee('Nova Agent')
        ->assertSee('Yalnızca ücretsiz')
        ->assertSee('Yaklaşık OpenRouter maliyeti')
        ->assertSee('OpenRouter sağlayıcı yönlendirmesi')
        ->assertSee('Sabit sağlayıcı · model başına seç')
        ->assertSee('data-cost-total', false)
        ->assertSee('Aynı anda çalışacak model sayısı')
        ->assertSee('2 · Dengeli, önerilen')
        ->assertSee('2 model')
        ->assertSee('Benchmark sonucu')
        ->assertSee('Henüz sonuç yok')
        ->assertSee('Sistem Ön Kontrolü')
        ->assertSee('Başlatmaya hazır')
        ->assertSee('Yeni bir benchmark başlatmaya hazır.');
});

test('it presents runner completion separately from a failed benchmark outcome', function () {
    $runner = mock(BenchmarkRunner::class);
    $runner->shouldReceive('connectionStatus')->once()->andReturn([
        'checkedAt' => '2026-09-21T10:00:00+03:00',
        'cli' => ['status' => 'pass', 'label' => 'OpenCode CLI', 'message' => '1.18.31'],
        'providers' => [
            'openrouter' => ['status' => 'fail', 'label' => 'OpenRouter', 'message' => 'Yerel bağlantı bulunamadı.'],
            'opencode-go' => ['status' => 'pass', 'label' => 'OpenCode Go', 'message' => 'OpenCode kimlik deposunda bağlantı hazır.'],
        ],
    ]);
    $runner->shouldReceive('options')->once()->andReturn([
        'prompts' => [['value' => 'prompts/nova.txt', 'label' => 'Nova']],
        'configs' => [[
            'value' => 'config/models.conf',
            'label' => 'Models',
            'modelCount' => 1,
            'ports' => [3101],
        ]],
        'suggestedRoutes' => ['/'],
    ]);
    $runner->shouldReceive('snapshot')->once()->andReturn([
        'status' => 'completed',
        'active' => false,
        'runId' => 'nova-failed-checks',
        'prompt' => 'prompts/nova.txt',
        'config' => 'config/models.conf',
        'routes' => ['/'],
        'startedAt' => '2026-09-19T10:00:00+03:00',
        'finishedAt' => '2026-09-19T10:05:00+03:00',
        'exitCode' => 0,
        'message' => 'Koşucu sorunsuz tamamlandı; 1 modelde başarısız doğrulama var.',
        'logTail' => 'BENCHMARK TAMAMLANDI',
        'outcome' => [
            'status' => 'fail',
            'label' => 'Kontrollerde başarısızlık var',
            'summary' => '0/1 model tüm aşamalardan geçti.',
            'available' => true,
            'totalModels' => 1,
            'passedModels' => 0,
            'failedModels' => 1,
            'otherModels' => 0,
        ],
        'progress' => [
            'completedModels' => 1,
            'totalModels' => 1,
            'percent' => 100,
            'phase' => 'Tamamlandı',
        ],
    ]);
    $runner->shouldReceive('preflight')->once()->with('config/models.conf')->andReturn([
        'ready' => true,
        'checkedAt' => '2026-09-19T10:06:00+03:00',
        'summary' => '1/1 kontrol geçti. Benchmark başlatılabilir.',
        'checks' => [[
            'key' => 'database',
            'label' => 'Veritabanı',
            'status' => 'pass',
            'message' => 'SQLITE bağlantısı hazır.',
        ]],
    ]);

    $response = $this->get(route('runner.create'));

    $response
        ->assertOk()
        ->assertSee('Koşu tamamlandı')
        ->assertSee('Koşucu sorunsuz tamamlandı; 1 modelde başarısız doğrulama var.')
        ->assertSee('Kontrollerde başarısızlık var')
        ->assertSee('0/1 model tüm aşamalardan geçti.');
});

test('it starts a valid benchmark and redirects to the live runner', function () {
    $execution = BenchmarkExecution::factory()->make(['run_id' => 'nova-v2']);
    $runner = mock(BenchmarkRunner::class);
    $runner->shouldReceive('options')->once()->andReturn([
        'prompts' => [['value' => 'prompts/nova.txt', 'label' => 'Nova']],
        'configs' => [[
            'value' => 'config/models.conf',
            'label' => 'Models',
            'modelCount' => 2,
            'ports' => [3101, 3102],
        ]],
        'suggestedRoutes' => ['/'],
    ]);
    $runner->shouldReceive('start')
        ->once()
        ->with([
            'run_id' => 'nova-v2',
            'prompt_mode' => 'saved',
            'prompt' => 'prompts/nova.txt',
            'custom_prompt_title' => '',
            'custom_prompt_content' => '',
            'save_prompt' => false,
            'model_mode' => 'group',
            'config' => 'config/models.conf',
            'models' => [],
            'go_models' => [],
            'max_parallel' => 2,
            'routes' => ['/', '/search'],
        ])
        ->andReturn($execution);

    $response = $this->post(route('runner.store'), [
        'run_id' => 'NOVA-v2',
        'routing_mode' => 'fixed',
        'fixed_providers' => ['unselected/model' => 'unselected/provider'],
        'prompt' => 'prompts/nova.txt',
        'config' => 'config/models.conf',
        'max_parallel' => 2,
        'routes' => "/\n/search\n/search",
    ]);

    $response
        ->assertRedirect(route('runner.create'))
        ->assertSessionHas('status', 'nova-v2 benchmark koşusu başlatıldı.');
});

test('it rejects invalid run names and routes without starting a benchmark', function () {
    $runner = mock(BenchmarkRunner::class);
    $runner->shouldReceive('options')->once()->andReturn([
        'prompts' => [['value' => 'prompts/nova.txt', 'label' => 'Nova']],
        'configs' => [[
            'value' => 'config/models.conf',
            'label' => 'Models',
            'modelCount' => 1,
            'ports' => [3101],
        ]],
        'suggestedRoutes' => ['/'],
    ]);
    $runner->shouldNotReceive('start');

    $response = $this->from(route('runner.create'))->post(route('runner.store'), [
        'run_id' => '../unsafe',
        'prompt' => 'prompts/nova.txt',
        'config' => 'config/models.conf',
        'routes' => 'https://example.com',
    ]);

    $response
        ->assertRedirect(route('runner.create'))
        ->assertSessionHasErrors(['run_id', 'routes']);
});

test('it starts a benchmark with selected OpenCode Go models', function () {
    $model = OpenCodeGoModel::factory()->create(['model_id' => 'glm-5.3-flash']);
    $execution = BenchmarkExecution::factory()->make(['run_id' => 'go-model-run']);
    $runner = mock(BenchmarkRunner::class);
    $runner->shouldReceive('options')->once()->andReturn([
        'prompts' => [['value' => 'prompts/nova.txt', 'label' => 'Nova']],
        'configs' => [],
        'suggestedRoutes' => ['/'],
    ]);
    $runner->shouldReceive('start')->once()->with([
        'run_id' => 'go-model-run',
        'prompt_mode' => 'saved',
        'prompt' => 'prompts/nova.txt',
        'custom_prompt_title' => '',
        'custom_prompt_content' => '',
        'save_prompt' => false,
        'model_mode' => 'opencode_go',
        'config' => '',
        'models' => [],
        'go_models' => [$model->model_id],
        'max_parallel' => 1,
        'routes' => ['/'],
    ])->andReturn($execution);

    $this->post(route('runner.store'), [
        'run_id' => 'go-model-run',
        'prompt' => 'prompts/nova.txt',
        'model_mode' => 'opencode_go',
        'routing_mode' => 'unsupported',
        'fixed_providers' => ['unselected/model' => ['malformed']],
        'go_models' => [$model->model_id],
        'max_parallel' => 1,
        'routes' => '/',
    ])
        ->assertRedirect(route('runner.create'))
        ->assertSessionHas('status', 'go-model-run benchmark koşusu başlatıldı.');
});

test('it returns forbidden when a non local address tries to start a benchmark', function () {
    mock(BenchmarkRunner::class)->shouldNotReceive('start');

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
        ->post(route('runner.store'), []);

    $response->assertForbidden();
});

test('it saves an inline prompt to the library before starting the benchmark', function () {
    $content = 'Build a focused marketplace interface with a working cart and checkout.';
    $execution = BenchmarkExecution::factory()->make(['run_id' => 'custom-prompt-run']);
    $runner = mock(BenchmarkRunner::class);
    $runner->shouldReceive('options')->once()->andReturn([
        'prompts' => [['value' => 'prompts/nova.txt', 'label' => 'Nova', 'source' => 'file']],
        'configs' => [[
            'value' => 'config/models.conf',
            'label' => 'Models',
            'modelCount' => 1,
            'ports' => [3101],
        ]],
        'suggestedRoutes' => ['/'],
    ]);
    $runner->shouldReceive('start')->once()->with([
        'run_id' => 'custom-prompt-run',
        'prompt_mode' => 'custom',
        'prompt' => '',
        'custom_prompt_title' => 'Marketplace görevim',
        'custom_prompt_content' => $content,
        'save_prompt' => true,
        'model_mode' => 'group',
        'config' => 'config/models.conf',
        'models' => [],
        'go_models' => [],
        'max_parallel' => 1,
        'routes' => ['/'],
    ])->andReturn($execution);

    $this->post(route('runner.store'), [
        'run_id' => 'custom-prompt-run',
        'prompt_mode' => 'custom',
        'custom_prompt_title' => 'Marketplace görevim',
        'custom_prompt_content' => $content,
        'save_prompt' => '1',
        'model_mode' => 'group',
        'config' => 'config/models.conf',
        'max_parallel' => 1,
        'routes' => '/',
    ])->assertRedirect(route('runner.create'));

    $prompt = BenchmarkPrompt::query()->sole();

    expect($prompt->title)->toBe('Marketplace görevim')
        ->and($prompt->content)->toBe($content)
        ->and($prompt->content_hash)->toBe(hash('sha256', $content));
});

test('it forwards an exact provider choice for a custom OpenRouter benchmark', function () {
    $model = OpenRouterModel::factory()->create(['model_id' => 'deepseek/deepseek-v4.1']);
    $execution = BenchmarkExecution::factory()->make(['run_id' => 'fixed-provider-run']);
    $runner = mock(BenchmarkRunner::class);
    $runner->shouldReceive('options')->once()->andReturn([
        'prompts' => [['value' => 'prompts/nova.txt', 'label' => 'Nova']],
        'configs' => [],
        'suggestedRoutes' => ['/'],
    ]);
    $runner->shouldReceive('start')->once()->with([
        'run_id' => 'fixed-provider-run',
        'prompt_mode' => 'saved',
        'prompt' => 'prompts/nova.txt',
        'custom_prompt_title' => '',
        'custom_prompt_content' => '',
        'save_prompt' => false,
        'model_mode' => 'custom',
        'config' => '',
        'models' => [$model->model_id],
        'go_models' => [],
        'routing_mode' => 'fixed',
        'fixed_providers' => [$model->model_id => 'relace/fp4'],
        'max_parallel' => 1,
        'routes' => ['/'],
    ])->andReturn($execution);

    $response = $this->post(route('runner.store'), [
        'run_id' => 'fixed-provider-run',
        'prompt' => 'prompts/nova.txt',
        'model_mode' => 'custom',
        'models' => [$model->model_id],
        'routing_mode' => 'fixed',
        'fixed_providers' => [$model->model_id => 'relace/fp4'],
        'max_parallel' => 1,
        'routes' => '/',
    ]);

    $response
        ->assertRedirect(route('runner.create'))
        ->assertSessionHas('status', 'fixed-provider-run benchmark koşusu başlatıldı.');
});

test('it defaults custom OpenRouter benchmarks to automatic provider routing', function () {
    $model = OpenRouterModel::factory()->create(['model_id' => 'deepseek/deepseek-v4.1']);
    $execution = BenchmarkExecution::factory()->make(['run_id' => 'automatic-provider-run']);
    $runner = mock(BenchmarkRunner::class);
    $runner->shouldReceive('options')->once()->andReturn([
        'prompts' => [['value' => 'prompts/nova.txt', 'label' => 'Nova']],
        'configs' => [],
        'suggestedRoutes' => ['/'],
    ]);
    $runner->shouldReceive('start')->once()->with([
        'run_id' => 'automatic-provider-run',
        'prompt_mode' => 'saved',
        'prompt' => 'prompts/nova.txt',
        'custom_prompt_title' => '',
        'custom_prompt_content' => '',
        'save_prompt' => false,
        'model_mode' => 'custom',
        'config' => '',
        'models' => [$model->model_id],
        'go_models' => [],
        'routing_mode' => 'auto',
        'fixed_providers' => [],
        'max_parallel' => 1,
        'routes' => ['/'],
    ])->andReturn($execution);

    $this->post(route('runner.store'), [
        'run_id' => 'automatic-provider-run',
        'prompt' => 'prompts/nova.txt',
        'model_mode' => 'custom',
        'models' => [$model->model_id],
        'max_parallel' => 1,
        'routes' => '/',
    ])
        ->assertRedirect(route('runner.create'))
        ->assertSessionHas('status', 'automatic-provider-run benchmark koşusu başlatıldı.');
});

test('it rejects an unsupported custom OpenRouter routing mode', function () {
    $model = OpenRouterModel::factory()->create(['model_id' => 'deepseek/deepseek-v4.1']);
    $runner = mock(BenchmarkRunner::class);
    $runner->shouldReceive('options')->once()->andReturn([
        'prompts' => [['value' => 'prompts/nova.txt', 'label' => 'Nova']],
        'configs' => [],
        'suggestedRoutes' => ['/'],
    ]);
    $runner->shouldNotReceive('start');

    $response = $this->from(route('runner.create'))->post(route('runner.store'), [
        'run_id' => 'invalid-routing-run',
        'prompt' => 'prompts/nova.txt',
        'model_mode' => 'custom',
        'models' => [$model->model_id],
        'routing_mode' => 'random',
        'max_parallel' => 1,
        'routes' => '/',
    ]);

    $response
        ->assertRedirect(route('runner.create'))
        ->assertSessionHasErrors([
            'routing_mode' => 'Geçerli bir OpenRouter sağlayıcı tercihi seçin.',
        ]);
});

test('it rejects concurrency above the selected model count', function () {
    $model = OpenRouterModel::factory()->create(['model_id' => 'acme/single-agent']);
    $runner = mock(BenchmarkRunner::class);
    $runner->shouldReceive('options')->once()->andReturn([
        'prompts' => [['value' => 'prompts/nova.txt', 'label' => 'Nova']],
        'configs' => [],
        'suggestedRoutes' => ['/'],
    ]);
    $runner->shouldNotReceive('start');

    $response = $this->from(route('runner.create'))->post(route('runner.store'), [
        'run_id' => 'single-model-run',
        'prompt' => 'prompts/nova.txt',
        'model_mode' => 'custom',
        'models' => [$model->model_id],
        'max_parallel' => 2,
        'routes' => '/',
    ]);

    $response
        ->assertRedirect(route('runner.create'))
        ->assertSessionHasErrors([
            'max_parallel' => 'Eşzamanlılık seçilen 1 modelden fazla olamaz.',
        ]);
});

test('it saves the catalog selection as a group when a group name is given at start', function () {
    $model = OpenCodeGoModel::factory()->create(['model_id' => 'glm-5.3-flash']);
    $runner = mock(BenchmarkRunner::class);
    $runner->shouldReceive('options')->andReturn(['prompts' => [['value' => 'prompts/nova.txt', 'label' => 'Nova']], 'configs' => [], 'suggestedRoutes' => ['/']]);
    $runner->shouldReceive('start')->once()->andReturn(BenchmarkExecution::factory()->make(['run_id' => 'group-run']));
    $payload = [
        'run_id' => 'group-run',
        'prompt' => 'prompts/nova.txt',
        'model_mode' => 'opencode_go',
        'go_models' => [$model->model_id],
        'max_parallel' => 1,
        'routes' => '/',
        'group_name' => 'Kurtuluş Savaşı',
    ];

    $this->post(route('runner.store'), $payload)
        ->assertRedirect(route('runner.create'))
        ->assertSessionHas('status', 'group-run benchmark koşusu başlatıldı. Model seçimi “kurtulus-savasi” grubu olarak kaydedildi.');

    expect(File::get(config('benchforge.root_path').'/config/kurtulus-savasi.conf'))->toContain('|opencode-go/glm-5.3-flash|3251');

    $this->from(route('runner.create'))->post(route('runner.store'), [...$payload, 'run_id' => 'group-run-2'])
        ->assertSessionHasErrors(['group_name' => '“kurtulus-savasi” adında bir grup zaten var; başka bir ad yazın ya da alanı boş bırakın.']);
});
