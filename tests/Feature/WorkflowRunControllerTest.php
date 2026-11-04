<?php

use App\Exceptions\BenchmarkRunnerException;
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
 * A valid start form for the built-in Game Idea Factory with one OpenRouter model.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function workflowRunHttpPayload(string $modelId, array $overrides = []): array
{
    return array_replace([
        'template_version' => '1',
        'inputs' => ['ideasPerCategory' => '2', 'docLanguage' => 'English', 'extraBrief' => ''],
        'model_mode' => 'custom',
        'models' => [$modelId],
        'routing_mode' => 'auto',
        'max_parallel' => '1',
        'max_concurrent_calls' => '3',
        'timeouts' => ['llm' => '5', 'code' => ''],
        'confirmed_external' => '1',
    ], $overrides);
}

/**
 * @param  list<string>  $models  provider-prefixed model ids
 */
function writeWorkflowRunHttpGroup(string $name, array $models): void
{
    $lines = array_map(
        fn (string $model, int $index): string => 'model-'.($index + 1).'|'.$model.'|'.(3251 + $index),
        $models,
        array_keys($models),
    );

    File::put(config('benchforge.root_path').'/config/'.$name.'.conf', implode("\n", $lines)."\n");
}

/**
 * A finished run with one model, its private directory and the template snapshot the files service reads.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{run: WorkflowRun, model: WorkflowRunModel, directory: string}
 */
function workflowRunHttpFixture(array $attributes = []): array
{
    $slug = 'demo-flow-20261022-120000';
    $directory = app(WorkflowRunFiles::class)->runsRoot().'/'.$slug;
    File::ensureDirectoryExists($directory, 0700, true);
    File::put($directory.'/template.json', json_encode([
        'schema' => 'benchforge-workflow/1',
        'id' => 'demo-flow',
        'name' => 'Demo akışı',
        'version' => 1,
        'steps' => [
            ['id' => 'brief', 'type' => 'llm_text', 'title' => 'Özet', 'prompt' => 'Write a brief.'],
            ['id' => 'demos', 'type' => 'foreach', 'title' => 'Demolar', 'over' => 'constants.ideas', 'steps' => [
                ['id' => 'build', 'type' => 'code_text', 'title' => 'Demo yaz', 'prompt' => 'Build {{item.title}}.'],
            ]],
        ],
        'gallery' => ['from' => 'demos', 'title' => '{{item.title}}', 'file' => 'build'],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $run = WorkflowRun::factory()->create(['slug' => $slug, 'working_directory' => $directory, 'title' => 'Demo akışı', ...$attributes]);
    $model = WorkflowRunModel::factory()->for($run)->create(['model' => 'openrouter/fixture/http-model', 'model_name' => 'HTTP modeli']);

    return ['run' => $run, 'model' => $model, 'directory' => $directory];
}

/**
 * @param  array<string, mixed>  $value
 */
function writeWorkflowRunHttpJson(string $path, array $value): void
{
    File::ensureDirectoryExists(dirname($path), 0700, true);
    File::put($path, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

/**
 * Adds a top-level code step (`poster`) to the fixture's template snapshot and writes its accepted demo.
 *
 * @param  array{run: WorkflowRun, model: WorkflowRunModel, directory: string}  $fixture
 */
function writeWorkflowRunHttpTopLevelDemo(array $fixture): string
{
    $template = json_decode(File::get($fixture['directory'].'/template.json'), true, flags: JSON_THROW_ON_ERROR);
    $template['steps'][] = ['id' => 'poster', 'type' => 'code_text', 'title' => 'Afiş demosu', 'prompt' => 'Build a poster.'];
    writeWorkflowRunHttpJson($fixture['directory'].'/template.json', $template);
    $demoPath = $fixture['directory'].'/models/'.$fixture['model']->slug.'/steps/poster/demo.html';
    File::ensureDirectoryExists(dirname($demoPath));
    File::put($demoPath, '<!doctype html><html><body>afiş</body></html>');

    return $demoPath;
}

test('it starts an OpenRouter workflow with only the validated input of the selected panel', function () {
    $model = OpenRouterModel::factory()->create();
    $run = WorkflowRun::factory()->create(['status' => 'running', 'finished_at' => null]);
    mock(WorkflowRunner::class)->shouldReceive('start')->once()->with([
        'template_id' => 'game-idea-factory',
        'template_version' => 1,
        'inputs' => ['ideasPerCategory' => 2, 'docLanguage' => 'English', 'extraBrief' => ''],
        'model_mode' => 'custom',
        'models' => [$model->model_id],
        'routing_mode' => 'auto',
        'fixed_providers' => [],
        'max_parallel' => 1,
        'max_concurrent_calls' => 3,
        'timeouts' => ['llm' => 300, 'code' => null],
    ])->andReturn($run);

    $this->post(route('workflows.runs.store', 'game-idea-factory'), workflowRunHttpPayload($model->model_id, [
        'inputs' => ['ideasPerCategory' => '2', 'docLanguage' => 'English', 'extraBrief' => '', 'unknown' => 'ignored'],
        'go_models' => ['stale-go-model'],
        'config' => 'config/missing.conf',
        'status' => 'completed',
        'runner_pid' => 123,
        'working_directory' => '/tmp/elsewhere',
    ]))
        ->assertRedirectToRoute('workflows.runs.show', $run)
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'İş akışı başlatıldı. Adımları bu sayfadan canlı izleyebilirsiniz.');
});

test('it starts OpenCode Go and saved group workflows with the keys of their own mode', function () {
    $goModel = OpenCodeGoModel::factory()->create(['model_id' => 'go-fixture']);
    writeWorkflowRunHttpGroup('workflow-pair', ['openrouter/acme/alpha', 'opencode-go/beta']);
    $run = WorkflowRun::factory()->create();
    $common = [
        'template_id' => 'game-idea-factory',
        'template_version' => 1,
        'inputs' => ['ideasPerCategory' => 1, 'docLanguage' => 'Türkçe', 'extraBrief' => 'Kısa demolar'],
    ];
    $runner = mock(WorkflowRunner::class);
    $runner->shouldReceive('start')->once()->with([
        ...$common,
        'model_mode' => 'opencode_go',
        'go_models' => ['go-fixture'],
        'max_parallel' => 1,
        'max_concurrent_calls' => 2,
        'timeouts' => ['llm' => null, 'code' => 7200],
    ])->andReturn($run);
    $runner->shouldReceive('start')->once()->with([
        ...$common,
        'model_mode' => 'group',
        'config' => 'config/workflow-pair.conf',
        'max_parallel' => 2,
        'max_concurrent_calls' => 2,
        'timeouts' => ['llm' => null, 'code' => 7200],
    ])->andReturn($run);
    $inputs = ['ideasPerCategory' => '1', 'docLanguage' => 'Türkçe', 'extraBrief' => '  Kısa demolar  '];

    $this->post(route('workflows.runs.store', 'game-idea-factory'), workflowRunHttpPayload('missing/openrouter-model', [
        'model_mode' => 'opencode_go',
        'go_models' => [$goModel->model_id, ' '.$goModel->model_id.' '],
        'routing_mode' => ['not-a-mode'],
        'fixed_providers' => 'broken',
        'inputs' => $inputs,
        'max_concurrent_calls' => '',
        'timeouts' => ['llm' => '', 'code' => '120'],
    ]))->assertRedirectToRoute('workflows.runs.show', $run)->assertSessionHasNoErrors();

    $this->post(route('workflows.runs.store', 'game-idea-factory'), workflowRunHttpPayload('missing/openrouter-model', [
        'model_mode' => 'group',
        'config' => 'config/workflow-pair.conf',
        'go_models' => ['missing-go-model'],
        'inputs' => $inputs,
        'max_parallel' => '2',
        'max_concurrent_calls' => null,
        'timeouts' => ['code' => '120'],
    ]))->assertRedirectToRoute('workflows.runs.show', $run)->assertSessionHasNoErrors();
});

test('it reports each invalid template input under its own field', function () {
    $model = OpenRouterModel::factory()->create();
    mock(WorkflowRunner::class)->shouldNotReceive('start');

    $this->from(route('workflows.create', 'game-idea-factory'))
        ->post(route('workflows.runs.store', 'game-idea-factory'), workflowRunHttpPayload($model->model_id, [
            'inputs' => ['ideasPerCategory' => '11', 'docLanguage' => str_repeat('x', 41), 'extraBrief' => ['nested']],
        ]))
        ->assertRedirect(route('workflows.create', 'game-idea-factory'))
        ->assertSessionHasErrors([
            'inputs.ideasPerCategory' => 'Kategori başına fikir en fazla 10 olabilir.',
            'inputs.docLanguage' => 'Belge dili en fazla 40 karakter olabilir.',
            'inputs.extraBrief' => 'Ek açıklama bir metin olmalıdır.',
        ]);
});

test('it rejects an invalid start form with a Turkish message', function (Closure $payload, string $field, string $message) {
    $model = OpenRouterModel::factory()->create();
    mock(WorkflowRunner::class)->shouldNotReceive('start');

    $this->post(route('workflows.runs.store', 'game-idea-factory'), $payload(workflowRunHttpPayload($model->model_id)))
        ->assertSessionHasErrors([$field => $message]);
})->with([
    'more than five models' => [
        fn (array $payload): array => [...$payload, 'models' => OpenRouterModel::factory()->count(6)->create()->pluck('model_id')->all(), 'max_parallel' => '1'],
        'models',
        'Bir iş akışında en fazla 5 model seçilebilir.',
    ],
    'a catalog model that is no longer available' => [
        fn (array $payload): array => [...$payload, 'models' => ['acme/retired-model']],
        'models.0',
        'Seçilen modellerden biri artık kullanılamıyor. Kataloğu yenileyin.',
    ],
    'no consent to external calls' => [
        fn (array $payload): array => array_diff_key($payload, ['confirmed_external' => true]),
        'confirmed_external',
        'Girdilerin ve şablon promptlarının seçili model sağlayıcılarına gönderilmesini onaylayın.',
    ],
    'more parallel models than selected' => [
        fn (array $payload): array => [...$payload, 'max_parallel' => '2'],
        'max_parallel',
        'Eşzamanlılık seçilen 1 modelden fazla olamaz.',
    ],
    'too many concurrent calls' => [
        fn (array $payload): array => [...$payload, 'max_concurrent_calls' => '11'],
        'max_concurrent_calls',
        'Eşzamanlı model çağrısı sayısı 1 ile 10 arasında olmalıdır.',
    ],
    'a timeout over two hours' => [
        fn (array $payload): array => [...$payload, 'timeouts' => ['llm' => '121']],
        'timeouts.llm',
        'Süre sınırı 1 ile 120 dakika arasında olmalıdır.',
    ],
    'a malformed fixed provider' => [
        fn (array $payload): array => [...$payload, 'routing_mode' => 'fixed', 'fixed_providers' => [$payload['models'][0] => ['nested']]],
        'fixed_providers',
        'Geçerli bir sabit sağlayıcı seçin.',
    ],
    'fixed routing without providers' => [
        fn (array $payload): array => [...$payload, 'routing_mode' => 'fixed'],
        'fixed_providers',
        'Seçilen her model için bir sabit sağlayıcı seçin.',
    ],
    'a template version that does not exist' => [
        fn (array $payload): array => [...$payload, 'template_version' => '7'],
        'template_version',
        'Bu şablon sürümü bulunamadı ya da geçersiz. İş akışı listesini yenileyin.',
    ],
    'an unknown model mode' => [
        fn (array $payload): array => [...$payload, 'model_mode' => ['custom']],
        'model_mode',
        'Kayıtlı grup, OpenRouter veya OpenCode Go seçin.',
    ],
]);

test('it rejects saved groups that a workflow cannot run', function (Closure $writeGroups, string $message) {
    $writeGroups();
    mock(WorkflowRunner::class)->shouldNotReceive('start');

    $this->post(route('workflows.runs.store', 'game-idea-factory'), workflowRunHttpPayload('acme/unused', [
        'model_mode' => 'group',
        'config' => 'config/chosen.conf',
    ]))->assertSessionHasErrors(['config' => $message]);
})->with([
    'a group with six models' => [
        fn () => writeWorkflowRunHttpGroup('chosen', array_map(fn (int $number): string => 'openrouter/acme/model-'.$number, range(1, 6))),
        'Bu grupta 6 model var; iş akışında en fazla 5 model seçilebilir.',
    ],
    'a group with another provider' => [
        fn () => writeWorkflowRunHttpGroup('chosen', ['openrouter/acme/alpha', 'anthropic/claude-fixture']),
        'Bu gruptaki anthropic sağlayıcısı iş akışlarında desteklenmiyor (yalnız OpenRouter ve OpenCode Go).',
    ],
    'an unreadable group file' => [
        function (): void {
            writeWorkflowRunHttpGroup('chosen', ['openrouter/acme/alpha']);
            File::put(config('benchforge.root_path').'/config/broken.conf', "not a model line\n");
        },
        'Kayıtlı model grupları okunamadı: broken.conf:1 geçerli bir model kaydı değil.',
    ],
]);

test('it shows the runner refusal on the form and keeps what was typed', function () {
    $model = OpenRouterModel::factory()->create();
    $message = '“Oyun Fikir Fabrikası” iş akışı halen çalışıyor. Aynı anda tek iş akışı çalışabilir.';
    $runner = mock(WorkflowRunner::class);
    $runner->shouldReceive('start')->once()->andThrow(new BenchmarkRunnerException($message));
    $runner->shouldReceive('active')->once()->andReturnNull();

    $this->from(route('workflows.create', 'game-idea-factory'))
        ->followingRedirects()
        ->post(route('workflows.runs.store', 'game-idea-factory'), workflowRunHttpPayload($model->model_id))
        ->assertOk()
        ->assertSee('İş akışı başlatılamadı.')
        ->assertSee($message)
        ->assertSee('name="inputs[docLanguage]" value="English"', false)
        ->assertSee('name="models[]" value="'.$model->model_id.'" data-model-picker-model="openrouter" checked', false);
});

test('it returns the live snapshot without file paths and without a rate limit', function () {
    $fixture = workflowRunHttpFixture(['runner_pid' => 999_999]);
    writeWorkflowRunHttpJson($fixture['directory'].'/state.json', [
        'schema' => 'benchforge-workflow-state/1',
        'runId' => $fixture['run']->slug,
        'pid' => 999_999,
        'status' => 'completed',
        'models' => [$fixture['model']->slug => [
            'model' => $fixture['model']->model,
            'status' => 'completed',
            'usage' => ['inputTokens' => 10, 'outputTokens' => 20, 'costUsd' => 0, 'calls' => 1],
            'items' => ['total' => 0, 'completed' => 0, 'failed' => 0],
            'steps' => ['brief' => ['status' => 'completed', 'attempts' => 1]],
        ]],
    ]);

    $response = $this->getJson(route('workflows.runs.status', $fixture['run']))
        ->assertOk()
        ->assertHeaderContains('Cache-Control', 'no-store')
        ->assertHeaderMissing('X-RateLimit-Limit')
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('active', false)
        ->assertJsonPath('models.0.slug', $fixture['model']->slug)
        ->assertJsonPath('models.0.name', 'HTTP modeli')
        ->assertJsonPath('models.0.steps.0.id', 'brief')
        ->assertJsonPath('models.0.steps.0.status', 'completed')
        ->assertJsonPath('models.0.steps.1.id', 'demos')
        ->assertJsonPath('models.0.steps.1.children.0.id', 'build')
        ->assertJsonStructure(['status', 'active', 'message', 'startedAt', 'finishedAt', 'durationSeconds', 'totals', 'models', 'events'])
        ->assertJsonMissingPath('working_directory');

    expect($response->getContent())->not->toContain($fixture['directory'])
        ->and($response->getContent())->not->toContain((string) config('benchforge.root_path'));
});

test('it renders the run page with the escaped run title', function () {
    $dangerous = "<script>alert('workflow-run')</script>";
    $run = WorkflowRun::factory()->create(['title' => $dangerous, 'status' => 'partial']);

    $this->get(route('workflows.runs.show', $run))
        ->assertOk()
        ->assertSee('&lt;script&gt;alert', false)
        ->assertDontSee($dangerous, false)
        ->assertSee('Kısmen tamamlandı');
});

test('it returns one step detail from the run files', function () {
    $fixture = workflowRunHttpFixture();
    $stepDirectory = $fixture['directory'].'/models/'.$fixture['model']->slug.'/steps/brief';
    File::ensureDirectoryExists($stepDirectory.'/attempt-1');
    File::put($stepDirectory.'/attempt-1/prompt.txt', 'Prompt metni');
    File::put($stepDirectory.'/attempt-1/response.txt', 'Yanıt metni');
    writeWorkflowRunHttpJson($stepDirectory.'/result.json', [
        'schema' => 'benchforge-workflow-result/1',
        'stepId' => 'brief', 'type' => 'llm_text', 'item' => null, 'status' => 'completed', 'message' => null,
        'durationSeconds' => 3.5, 'usage' => ['inputTokens' => 5, 'outputTokens' => 7, 'costUsd' => null, 'calls' => 1],
        'attempts' => [['n' => 1, 'kind' => 'initial', 'status' => 'completed', 'error' => null,
            'files' => ['prompt' => 'attempt-1/prompt.txt', 'response' => 'attempt-1/response.txt']]],
        'output' => 'Kısa çıktı',
    ]);

    $response = $this->getJson(route('workflows.runs.detail', [$fixture['run'], 'model' => $fixture['model']->slug, 'step' => 'brief']))
        ->assertOk()
        ->assertHeaderContains('Cache-Control', 'no-store')
        ->assertJsonPath('model.name', 'HTTP modeli')
        ->assertJsonPath('step.id', 'brief')
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('output', 'Kısa çıktı')
        ->assertJsonPath('attempts.0.prompt', 'Prompt metni')
        ->assertJsonPath('attempts.0.response', 'Yanıt metni');

    expect($response->getContent())->not->toContain($fixture['directory']);
});

test('it returns no step detail for unknown references', function (Closure $query) {
    $fixture = workflowRunHttpFixture();

    $this->getJson(route('workflows.runs.detail', [$fixture['run'], ...$query($fixture['model']->slug)]))->assertNotFound();
})->with([
    'an unknown model' => [fn (string $slug): array => ['model' => 'or-unknown-000000', 'step' => 'brief']],
    'an unknown step' => [fn (string $slug): array => ['model' => $slug, 'step' => 'missing']],
    'an item of a step that is not a foreach' => [fn (string $slug): array => ['model' => $slug, 'step' => 'brief', 'item' => '001', 'child' => 'build']],
    'an item that was never expanded' => [fn (string $slug): array => ['model' => $slug, 'step' => 'demos', 'item' => '001', 'child' => 'build']],
]);

test('it rejects malformed step detail references before reading files', function (array $query, string $field) {
    $fixture = workflowRunHttpFixture();

    $this->getJson(route('workflows.runs.detail', [$fixture['run'], ...$query]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'a missing model' => [['step' => 'brief'], 'model'],
    'a model with a parent segment' => [['model' => '../secret', 'step' => 'brief'], 'model'],
    'a step with a slash' => [['model' => 'or-model-000000', 'step' => 'brief/../x'], 'step'],
    'an item that is not a key' => [['model' => 'or-model-000000', 'step' => 'demos', 'item' => '1', 'child' => 'build'], 'item'],
    'a child without an item' => [['model' => 'or-model-000000', 'step' => 'demos', 'child' => 'build'], 'item'],
    'an array model' => [['model' => ['or-model-000000'], 'step' => 'brief'], 'model'],
]);

test('it serves a demo sandboxed without network access, with the preview security headers and without session cookies', function () {
    $fixture = workflowRunHttpFixture();
    $demoPath = $fixture['directory'].'/models/'.$fixture['model']->slug.'/steps/demos/items/001/build/demo.html';
    File::ensureDirectoryExists(dirname($demoPath));
    File::put($demoPath, '<!doctype html><html><body>demo</body></html>');

    $response = $this->get(route('workflows.runs.demos.show', [$fixture['run'], $fixture['model']->slug, 'demos', '001', 'build']))
        ->assertOk()
        ->assertHeader('Content-Security-Policy', "sandbox allow-scripts; default-src 'none'; script-src 'unsafe-inline' 'unsafe-eval'; style-src 'unsafe-inline'; img-src data: blob:; media-src data: blob:; font-src data:; connect-src 'none'; worker-src blob:; form-action 'none'; base-uri 'none'")
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeaderContains('Cache-Control', 'no-store')
        ->assertHeaderContains('Cache-Control', 'private')
        ->assertHeaderMissing('Set-Cookie');

    expect($response->baseResponse->getFile()->getPathname())->toBe($demoPath);
});

test('it serves no demo outside the run directory or for a missing item', function (string $path) {
    $fixture = workflowRunHttpFixture();
    $secret = (string) config('benchforge.root_path').'/secret.html';
    File::put($secret, '<html>gizli</html>');
    $prefix = '/workflows/runs/'.$fixture['run']->slug.'/demos/'.$fixture['model']->slug;

    $this->get($prefix.$path)->assertNotFound();
})->with([
    'an encoded parent segment' => ['/demos/..%2F..%2F..%2F/build'],
    'a parent segment as the step' => ['/../001/build'],
    'an item key that is not numeric' => ['/demos/abc/build'],
    'a missing item' => ['/demos/002/build'],
    'a file name instead of a child step' => ['/demos/001/demo.html'],
]);

test('it serves a top-level demo with the same sandbox, security headers and sessionless response as an item demo', function () {
    $fixture = workflowRunHttpFixture();
    $demoPath = writeWorkflowRunHttpTopLevelDemo($fixture);

    $response = $this->get(route('workflows.runs.demos.step', [$fixture['run'], $fixture['model']->slug, 'poster']))
        ->assertOk()
        ->assertHeader('Content-Security-Policy', "sandbox allow-scripts; default-src 'none'; script-src 'unsafe-inline' 'unsafe-eval'; style-src 'unsafe-inline'; img-src data: blob:; media-src data: blob:; font-src data:; connect-src 'none'; worker-src blob:; form-action 'none'; base-uri 'none'")
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeaderContains('Cache-Control', 'no-store')
        ->assertHeaderContains('Cache-Control', 'private')
        ->assertHeaderMissing('Set-Cookie');

    expect($response->baseResponse->getFile()->getPathname())->toBe($demoPath)
        ->and(route('workflows.runs.demos.step', [$fixture['run'], $fixture['model']->slug, 'poster'], false))
        ->toBe('/workflows/runs/'.$fixture['run']->slug.'/demos/'.$fixture['model']->slug.'/poster');
});

test('it serves no top-level demo for a step that is not a top-level code step or outside the run directory', function (string $path) {
    $fixture = workflowRunHttpFixture();
    writeWorkflowRunHttpTopLevelDemo($fixture);
    $stepsDirectory = $fixture['directory'].'/models/'.$fixture['model']->slug.'/steps';

    foreach (['brief', 'demos', 'build', 'ghost'] as $stepId) {
        File::ensureDirectoryExists($stepsDirectory.'/'.$stepId);
        File::put($stepsDirectory.'/'.$stepId.'/demo.html', '<html>demo</html>');
    }

    File::put($fixture['directory'].'/demo.html', '<html>gizli</html>');
    File::put((string) config('benchforge.root_path').'/secret.html', '<html>gizli</html>');

    $this->get('/workflows/runs/'.$fixture['run']->slug.'/demos/'.$fixture['model']->slug.$path)->assertNotFound();
})->with([
    'a text step' => ['/brief'],
    'a foreach step' => ['/demos'],
    'a code child of a foreach' => ['/build'],
    'a step that is not in the template' => ['/ghost'],
    'an encoded parent segment' => ['/..%2F..%2Fsecret'],
    'a parent segment as the step' => ['/..'],
    'a file name instead of a step' => ['/demo.html'],
]);

test('it serves no top-level demo before the step has written one or for an unknown model', function () {
    $fixture = workflowRunHttpFixture();
    $demoPath = writeWorkflowRunHttpTopLevelDemo($fixture);
    File::delete($demoPath);

    $this->get(route('workflows.runs.demos.step', [$fixture['run'], $fixture['model']->slug, 'poster']))->assertNotFound();
    $this->get(route('workflows.runs.demos.step', [$fixture['run'], 'or-unknown-000000', 'poster']))->assertNotFound();
});

test('it links the demo of a finished top-level code step from its step detail', function () {
    $fixture = workflowRunHttpFixture();
    writeWorkflowRunHttpTopLevelDemo($fixture);
    writeWorkflowRunHttpJson($fixture['directory'].'/models/'.$fixture['model']->slug.'/steps/poster/result.json', [
        'schema' => 'benchforge-workflow-result/1',
        'stepId' => 'poster', 'type' => 'code_text', 'item' => null, 'status' => 'completed', 'message' => null,
        'attempts' => [],
        'output' => ['file' => 'demo.html', 'bytes' => 44, 'checks' => ['critical' => [], 'warnings' => []]],
    ]);
    $demoUrl = route('workflows.runs.demos.step', [$fixture['run'], $fixture['model']->slug, 'poster'], false);

    $this->getJson(route('workflows.runs.detail', [$fixture['run'], 'model' => $fixture['model']->slug, 'step' => 'poster']))
        ->assertOk()
        ->assertJsonPath('step.id', 'poster')
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('output.file', 'demo.html')
        ->assertJsonPath('demoUrl', $demoUrl);

    $this->get($demoUrl)->assertOk()->assertHeaderMissing('Set-Cookie');

    $this->getJson(route('workflows.runs.detail', [$fixture['run'], 'model' => $fixture['model']->slug, 'step' => 'brief']))
        ->assertOk()
        ->assertJsonPath('demoUrl', null);
});

test('it stops the selected run and reports a refused stop', function () {
    $fixture = workflowRunHttpFixture(['status' => 'running', 'finished_at' => null]);
    $runner = mock(WorkflowRunner::class);
    $runner->shouldReceive('stop')->once()->withArgs(fn (WorkflowRun $run): bool => $run->is($fixture['run']))->andReturn($fixture['run']);
    $runner->shouldReceive('stop')->once()->andThrow(new BenchmarkRunnerException('Bu iş akışı artık çalışmıyor.'));

    $this->post(route('workflows.runs.stop', $fixture['run']))
        ->assertRedirectToRoute('workflows.runs.show', $fixture['run'])
        ->assertSessionHas('status', 'İş akışı durduruluyor; tamamlanan adımlar korunacak.');

    $this->from(route('workflows.runs.show', $fixture['run']))
        ->post(route('workflows.runs.stop', $fixture['run']))
        ->assertRedirectToRoute('workflows.runs.show', $fixture['run'])
        ->assertSessionHasErrors(['workflow' => 'Bu iş akışı artık çalışmıyor.']);
});

test('it refuses nonlocal access to every workflow page, file and action', function () {
    $fixture = workflowRunHttpFixture(['status' => 'running', 'finished_at' => null]);
    $model = OpenRouterModel::factory()->create();
    mock(WorkflowRunner::class)
        ->shouldNotReceive('start')
        ->shouldNotReceive('stop')
        ->shouldNotReceive('reconcile')
        ->shouldNotReceive('snapshot')
        ->shouldNotReceive('active');
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10']);

    $this->get(route('workflows.index'))->assertForbidden();
    $this->get(route('workflows.create', 'game-idea-factory'))->assertForbidden();
    $this->post(route('workflows.runs.store', 'game-idea-factory'), workflowRunHttpPayload($model->model_id))->assertForbidden();
    $this->get(route('workflows.runs.show', $fixture['run']))->assertForbidden();
    $this->getJson(route('workflows.runs.status', $fixture['run']))->assertForbidden();
    $this->getJson(route('workflows.runs.detail', [$fixture['run'], 'model' => $fixture['model']->slug, 'step' => 'brief']))->assertForbidden();
    $this->post(route('workflows.runs.stop', $fixture['run']))->assertForbidden();
    $this->get(route('workflows.runs.demos.show', [$fixture['run'], $fixture['model']->slug, 'demos', '001', 'build']))->assertForbidden();
    $this->get(route('workflows.runs.demos.step', [$fixture['run'], $fixture['model']->slug, 'poster']))->assertForbidden();

    expect(WorkflowRun::query()->count())->toBe(1)
        ->and($fixture['run']->fresh()->status)->toBe('running');
});

test('it counts starts and stops separately so neither limit blocks the other', function () {
    $fixture = workflowRunHttpFixture(['status' => 'running', 'finished_at' => null]);
    $runner = mock(WorkflowRunner::class);
    $runner->shouldNotReceive('start');
    $runner->shouldReceive('stop')->times(10)->andReturn($fixture['run']);

    foreach (range(1, 5) as $attempt) {
        $this->post(route('workflows.runs.store', 'game-idea-factory'), [])->assertRedirect();
    }

    $this->post(route('workflows.runs.store', 'game-idea-factory'), [])->assertTooManyRequests();

    foreach (range(1, 10) as $attempt) {
        $this->post(route('workflows.runs.stop', $fixture['run']))->assertRedirectToRoute('workflows.runs.show', $fixture['run']);
    }

    $this->post(route('workflows.runs.stop', $fixture['run']))->assertTooManyRequests();
});
