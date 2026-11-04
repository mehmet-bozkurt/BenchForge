<?php

use App\Models\OpenCodeGoModel;
use App\Models\OpenRouterModel;
use App\Models\WorkflowRun;
use App\Models\WorkflowRunModel;
use App\Services\WorkflowRunFiles;
use App\Services\WorkflowRunner;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;

use function Pest\Laravel\mock;

uses(LazilyRefreshDatabase::class);

/**
 * A valid user template whose texts try to inject markup, with every kind of input the form renders.
 *
 * @return array<string, mixed>
 */
function workflowControllerUnsafeTemplate(): array
{
    return [
        'schema' => 'benchforge-workflow/1',
        'id' => 'unsafe-flow',
        'name' => "<script>alert('workflow-name')</script>",
        'description' => '<img src=x onerror=alert(1)>',
        'version' => 1,
        'inputs' => [
            ['id' => 'tone', 'type' => 'select', 'label' => '<b>Ton</b>', 'default' => 'calm', 'options' => [
                ['value' => 'calm', 'label' => '<i>Sakin</i>'],
                ['value' => 'loud', 'label' => 'Gürültülü'],
            ]],
            ['id' => 'withNotes', 'type' => 'boolean', 'label' => 'Notlar eklensin', 'default' => true],
            ['id' => 'topic', 'type' => 'string', 'label' => 'Konu', 'default' => '"><script>alert(2)</script>'],
        ],
        'steps' => [
            ['id' => 'answer', 'type' => 'llm_text', 'title' => '<svg onload=alert(3)>', 'prompt' => 'Write about {{inputs.topic}} in a {{inputs.tone}} tone.'],
        ],
    ];
}

function writeWorkflowControllerTemplate(string $id, int $version, string $contents): void
{
    $path = config('benchforge.root_path').'/workflows/'.$id.'/'.sprintf('v%03d.json', $version);
    File::ensureDirectoryExists(dirname($path));
    File::put($path, $contents);
}

/**
 * @param  list<string>  $models  provider-prefixed model ids
 */
function writeWorkflowControllerGroup(string $name, array $models): void
{
    $lines = array_map(
        fn (string $model, int $index): string => 'model-'.($index + 1).'|'.$model.'|'.(3251 + $index),
        $models,
        array_keys($models),
    );

    File::put(config('benchforge.root_path').'/config/'.$name.'.conf', implode("\n", $lines)."\n");
}

test('it lists built-in and user templates and recent runs without starting anything', function () {
    writeWorkflowControllerTemplate('unsafe-flow', 1, json_encode(workflowControllerUnsafeTemplate(), JSON_THROW_ON_ERROR));
    $finished = WorkflowRun::factory()->create(['title' => 'Bitmiş fikir turu', 'status' => 'completed']);
    WorkflowRunModel::factory()->count(2)->for($finished)->create();
    WorkflowRun::factory()->create(['title' => 'Yarım kalan tur', 'status' => 'partial']);
    mock(WorkflowRunner::class)->shouldReceive('active')->once()->andReturnNull()->shouldNotReceive('start');

    $this->get(route('workflows.index'))
        ->assertOk()
        ->assertHeaderContains('Cache-Control', 'no-store')
        ->assertSee('Oyun Fikir Fabrikası')
        ->assertSee(route('workflows.create', 'game-idea-factory'), false)
        ->assertSee(route('workflows.create', 'unsafe-flow'), false)
        ->assertSee('Yerleşik')
        ->assertSee('Kullanıcı')
        ->assertSee('&lt;script&gt;alert', false)
        ->assertDontSee("<script>alert('workflow-name')</script>", false)
        ->assertDontSee('<img src=x onerror=alert(1)>', false)
        ->assertSee('Bitmiş fikir turu')
        ->assertSee('Tamamlandı')
        ->assertSee('Kısmen tamamlandı')
        ->assertSee(route('workflows.runs.show', $finished), false)
        ->assertSeeInOrder(['Bitmiş fikir turu', 'Tamamlandı', '>2</td>'], false)
        ->assertSee('2 çalıştırma');
});

test('the sidebar links to the workflows page after the prompts', function () {
    mock(WorkflowRunner::class)->shouldReceive('active')->andReturnNull();

    $this->get(route('workflows.index'))
        ->assertSee(route('workflows.index'), false)
        ->assertSeeInOrder(['Promptlar', 'İş Akışları', 'Model Grupları']);
});

test('it points to the active run and does not offer an invalid template version', function () {
    writeWorkflowControllerTemplate('broken-flow', 1, '{"schema":');
    $activeRun = WorkflowRun::factory()->create(['title' => 'Canlı tur', 'status' => 'running', 'finished_at' => null]);
    mock(WorkflowRunner::class)->shouldReceive('active')->once()->andReturn($activeRun);

    $this->get(route('workflows.index'))
        ->assertOk()
        ->assertSee('Şu anda çalışıyor')
        ->assertSee(route('workflows.runs.show', $activeRun), false)
        ->assertSee('broken-flow')
        ->assertSee('Bu sürüm çalıştırılamaz.')
        ->assertSee('Şablon dosyası geçerli bir JSON değil.')
        ->assertDontSee(route('workflows.create', 'broken-flow'), false);
});

test('it lists a run whose worker finished since the last poll with its final status on the first visit', function () {
    $slug = 'game-idea-factory-20261022-130000';
    $directory = app(WorkflowRunFiles::class)->runsRoot().'/'.$slug;
    File::ensureDirectoryExists($directory, 0700, true);
    $finishedRun = WorkflowRun::factory()->create([
        'slug' => $slug,
        'title' => 'Az önce biten tur',
        'status' => 'running',
        'working_directory' => $directory,
        'runner_pid' => 999_999,
        'message' => null,
        'started_at' => now()->subMinutes(3),
        'finished_at' => null,
    ]);
    $model = WorkflowRunModel::factory()->for($finishedRun)->create(['status' => 'running']);
    File::put($directory.'/state.json', json_encode([
        'schema' => 'benchforge-workflow-state/1',
        'runId' => $slug,
        'pid' => 999_999,
        'status' => 'completed',
        'message' => 'Tüm adımlar bitti.',
        'finishedAt' => now()->subMinute()->toIso8601String(),
        'models' => [$model->slug => [
            'model' => $model->model,
            'status' => 'completed',
            'usage' => ['inputTokens' => 10, 'outputTokens' => 20, 'costUsd' => 0, 'calls' => 1],
            'items' => ['total' => 0, 'completed' => 0, 'failed' => 0],
        ]],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

    $this->get(route('workflows.index'))
        ->assertOk()
        ->assertSeeInOrder(['Az önce biten tur', 'Tüm adımlar bitti.', 'Tamamlandı', '2 dk', 'Sonuçları aç →'])
        ->assertDontSee('Şu anda çalışıyor')
        ->assertDontSee('Canlı durumu aç')
        ->assertDontSee('Canlı izle')
        ->assertDontSee('Sürüyor');

    expect($finishedRun->fresh()->status)->toBe('completed')
        ->and($model->fresh()->status)->toBe('completed');
});

test('it explains where templates come from when there are no templates or runs', function () {
    $emptyEngine = config('benchforge.test_sandbox_path').'/empty-engine';
    File::ensureDirectoryExists($emptyEngine);
    config()->set('benchforge.engine_path', $emptyEngine);
    mock(WorkflowRunner::class)->shouldReceive('active')->once()->andReturnNull();

    $this->get(route('workflows.index'))
        ->assertOk()
        ->assertSee('Henüz iş akışı şablonu yok')
        ->assertSee('Henüz çalıştırma yok')
        ->assertSee('0 şablon');
});

test('it renders the run form with template inputs, a five model picker and the phase one code step mode', function () {
    OpenRouterModel::factory()->create(['model_id' => 'acme/picker-alpha', 'author' => 'acme', 'name' => 'Picker OpenRouter modeli']);
    OpenCodeGoModel::factory()->create(['model_id' => 'picker-go', 'name' => 'Picker Go modeli']);
    writeWorkflowControllerGroup('small-pair', ['openrouter/acme/picker-alpha', 'opencode-go/picker-go']);
    writeWorkflowControllerGroup('too-big', array_map(fn (int $number): string => 'openrouter/acme/model-'.$number, range(1, 6)));
    writeWorkflowControllerGroup('other-provider', ['anthropic/claude-fixture']);
    mock(WorkflowRunner::class)->shouldReceive('active')->once()->andReturnNull()->shouldNotReceive('start');

    $this->get(route('workflows.create', 'game-idea-factory'))
        ->assertOk()
        ->assertSee('<title>Oyun Fikir Fabrikası · BenchForge</title>', false)
        ->assertSee(route('workflows.runs.store', 'game-idea-factory'), false)
        ->assertSee('name="template_version" value="1"', false)
        ->assertSee('name="inputs[ideasPerCategory]" value="5"', false)
        ->assertSee('name="inputs[docLanguage]" value="Türkçe"', false)
        ->assertSee('name="inputs[extraBrief]"', false)
        ->assertSee('Kategori başına fikir')
        ->assertSee('data-model-picker', false)
        ->assertSee('data-model-limit="5"', false)
        ->assertSee('data-provider-endpoints-url="'.route('runner.openrouter.endpoints', absolute: false).'"', false)
        ->assertSee('Picker OpenRouter modeli')
        ->assertSee('Picker Go modeli')
        ->assertSee('name="models[]" value="acme/picker-alpha"', false)
        ->assertSee('name="go_models[]" value="picker-go"', false)
        ->assertSee('Small Pair · 2 model')
        ->assertSee('Too Big · 6 model · en fazla 5 model seçilebilir')
        ->assertSee('desteklenmeyen sağlayıcı: anthropic')
        ->assertSee('data-selectable="false"', false)
        ->assertSee('name="max_parallel"', false)
        ->assertSee('name="max_concurrent_calls"', false)
        ->assertSee('name="timeouts[llm]"', false)
        ->assertSee('name="timeouts[code]"', false)
        ->assertSee('Demo yaz')
        ->assertSee('Tek seferde yaz (code_text)')
        ->assertSee('Araçlarla yaz ve test et” biçimi Aşama 2’de gelecek', false)
        ->assertSee('name="confirmed_external"', false)
        ->assertSee('İş akışını başlat →');
});

test('it escapes user template texts on the run form and renders select and boolean inputs', function () {
    writeWorkflowControllerTemplate('unsafe-flow', 1, json_encode(workflowControllerUnsafeTemplate(), JSON_THROW_ON_ERROR));
    mock(WorkflowRunner::class)->shouldReceive('active')->once()->andReturnNull();

    $this->get(route('workflows.create', 'unsafe-flow'))
        ->assertOk()
        ->assertSee('&lt;script&gt;alert', false)
        ->assertSee('&lt;b&gt;Ton&lt;/b&gt;', false)
        ->assertSee('&lt;i&gt;Sakin&lt;/i&gt;', false)
        ->assertSee('&lt;svg onload=alert(3)&gt;', false)
        ->assertSee('value="&quot;&gt;&lt;script&gt;alert(2)&lt;/script&gt;"', false)
        ->assertDontSee("<script>alert('workflow-name')</script>", false)
        ->assertDontSee('<img src=x onerror=alert(1)>', false)
        ->assertDontSee('<svg onload=alert(3)>', false)
        ->assertDontSee('"><script>alert(2)</script>', false)
        ->assertSee('name="inputs[tone]"', false)
        ->assertSee('value="calm" selected', false)
        ->assertSee('name="inputs[withNotes]" value="1" checked', false);
});

test('it does not render a run form for an unknown template or version', function (string $template, array $query) {
    mock(WorkflowRunner::class)->shouldNotReceive('active')->shouldNotReceive('start');

    $this->get(route('workflows.create', [$template, ...$query]))->assertNotFound();
})->with([
    'an unknown template' => ['missing-flow', []],
    'a missing version' => ['game-idea-factory', ['version' => 99]],
    'a version that is not a number' => ['game-idea-factory', ['version' => 'abc']],
    'version zero' => ['game-idea-factory', ['version' => 0]],
]);

test('it rejects template ids outside the template id pattern at the route', function () {
    mock(WorkflowRunner::class)->shouldNotReceive('active');

    $this->get('/workflows/templates/Game_Idea/new')->assertNotFound();
    $this->get('/workflows/templates/..%2Fsecret/new')->assertNotFound();
});

test('it renders the run form with malformed old input and an unreadable group file', function () {
    File::put(config('benchforge.root_path').'/config/broken.conf', "not a model line\n");
    mock(WorkflowRunner::class)->shouldReceive('active')->once()->andReturnNull();

    $this->withSession(['_old_input' => [
        'template_version' => '1',
        'model_mode' => ['custom'],
        'config' => ['config/broken.conf'],
        'models' => [['nested']],
        'routing_mode' => ['fixed'],
        'fixed_providers' => ['acme/model' => ['slug']],
        'inputs' => ['ideasPerCategory' => ['7'], 'docLanguage' => 'English'],
        'max_parallel' => ['3'],
        'timeouts' => 'abc',
    ]])->get(route('workflows.create', 'game-idea-factory'))
        ->assertOk()
        ->assertSee('Kayıtlı model grupları okunamadı.')
        ->assertSee('broken.conf:1 geçerli bir model kaydı değil.')
        ->assertSee('name="inputs[docLanguage]" value="English"', false)
        ->assertSee('name="inputs[ideasPerCategory]" value=""', false);
});
