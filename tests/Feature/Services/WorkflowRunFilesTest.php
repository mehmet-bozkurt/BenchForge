<?php

use App\Exceptions\BenchmarkRunnerException;
use App\Models\WorkflowRun;
use App\Models\WorkflowRunModel;
use App\Services\WorkflowRunFiles;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function workflowFilesTemplate(): array
{
    return [
        'schema' => 'benchforge-workflow/1',
        'id' => 'demo-flow',
        'name' => 'Demo akışı',
        'version' => 1,
        'steps' => [
            ['id' => 'brief', 'type' => 'llm_text', 'title' => 'Özet', 'prompt' => 'Write a brief.'],
            ['id' => 'ideas', 'type' => 'llm_json', 'title' => 'Fikirler', 'prompt' => 'List ideas.', 'schema' => ['type' => 'object']],
            ['id' => 'demos', 'type' => 'foreach', 'title' => 'Demolar', 'over' => 'steps.ideas.output.ideas', 'steps' => [
                ['id' => 'build', 'type' => 'code_text', 'title' => 'Demo yaz', 'prompt' => 'Build {{item.title}}.'],
                ['id' => 'check', 'type' => 'browser_check', 'title' => 'Tarayıcı testi', 'target' => 'build'],
            ]],
        ],
        'gallery' => ['from' => 'demos', 'title' => '{{item.title}}', 'file' => 'build', 'check' => 'check'],
    ];
}

/**
 * A finished run with one model, its private directory and template snapshot.
 *
 * @return array{run: WorkflowRun, model: WorkflowRunModel, directory: string}
 */
function workflowFilesRun(string $slug = 'demo-flow-20261022-120000'): array
{
    $directory = app(WorkflowRunFiles::class)->runsRoot().'/'.$slug;
    File::ensureDirectoryExists($directory, 0700, true);
    File::put($directory.'/template.json', json_encode(workflowFilesTemplate(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $run = WorkflowRun::factory()->create(['slug' => $slug, 'working_directory' => $directory]);
    $model = WorkflowRunModel::factory()->for($run)->create([
        'model' => 'openrouter/fixture/files-model',
        'model_name' => 'Dosya modeli',
    ]);

    return ['run' => $run, 'model' => $model, 'directory' => $directory];
}

/**
 * @param  array<string, mixed>  $value
 */
function writeWorkflowFilesJson(string $path, array $value): void
{
    File::ensureDirectoryExists(dirname($path), 0700, true);
    File::put($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

test('it creates the runs root lazily as a private directory and refuses a linked one', function () {
    $root = realpath((string) config('benchforge.root_path'));
    $runsRoot = app(WorkflowRunFiles::class)->runsRoot();

    expect($runsRoot)->toBe($root.'/workflow-runs')
        ->and(fileperms($runsRoot) & 0777)->toBe(0700);

    File::deleteDirectory($runsRoot);
    File::ensureDirectoryExists($root.'/elsewhere');
    symlink($root.'/elsewhere', $runsRoot);

    expect(fn () => app(WorkflowRunFiles::class)->runsRoot())
        ->toThrow(BenchmarkRunnerException::class, 'İş akışı çalıştırma klasörü sembolik bağlantı olamaz.');
});

test('it reads nothing through a run directory that is linked, misplaced or misnamed', function (Closure $workingDirectory) {
    $fixture = workflowFilesRun();
    $outside = realpath((string) config('benchforge.root_path')).'/outside-run';
    File::copyDirectory($fixture['directory'], $outside);
    writeWorkflowFilesJson($outside.'/state.json', ['schema' => 'benchforge-workflow-state/1', 'runId' => $fixture['run']->slug]);
    $fixture['run']->update(['working_directory' => $workingDirectory($fixture['directory'], $outside)]);
    $files = app(WorkflowRunFiles::class);

    expect($files->runDirectory($fixture['run']))->toBeNull()
        ->and($files->readState($fixture['run']))->toBeNull()
        ->and($files->template($fixture['run']))->toBeNull()
        ->and($files->eventsTail($fixture['run']))->toBe([])
        ->and($files->galleryItems($fixture['run']))->toBe([]);
})->with([
    'a symbolic link inside the runs root' => [function (string $directory, string $outside): string {
        File::deleteDirectory($directory);
        symlink($outside, $directory);

        return $directory;
    }],
    'a directory outside the runs root' => [fn (string $directory, string $outside): string => $outside],
    'a directory named after another run' => [function (string $directory): string {
        File::copyDirectory($directory, dirname($directory).'/other-flow-20261022-120000');

        return dirname($directory).'/other-flow-20261022-120000';
    }],
    'a path with a parent segment' => [fn (string $directory): string => $directory.'/../'.basename($directory)],
]);

test('it reads the state of its own run and ignores an oversized or foreign state file', function () {
    $fixture = workflowFilesRun();
    $statePath = $fixture['directory'].'/state.json';
    $files = app(WorkflowRunFiles::class);
    writeWorkflowFilesJson($statePath, ['schema' => 'benchforge-workflow-state/1', 'runId' => $fixture['run']->slug, 'status' => 'running']);

    expect($files->readState($fixture['run']))->toMatchArray(['status' => 'running']);

    writeWorkflowFilesJson($statePath, ['schema' => 'benchforge-workflow-state/1', 'runId' => 'another-run-20261022', 'status' => 'running']);
    expect($files->readState($fixture['run']))->toBeNull();

    writeWorkflowFilesJson($statePath, ['schema' => 'benchforge-preparation-state/1', 'runId' => $fixture['run']->slug]);
    expect($files->readState($fixture['run']))->toBeNull();

    writeWorkflowFilesJson($statePath, [
        'schema' => 'benchforge-workflow-state/1',
        'runId' => $fixture['run']->slug,
        'padding' => str_repeat('x', 2_097_152),
    ]);
    expect($files->readState($fixture['run']))->toBeNull();
});

test('it rejects traversal and malformed segments in step details and demo paths', function (string $model, string $step, ?string $item, ?string $child) {
    $fixture = workflowFilesRun();
    File::put($fixture['directory'].'/demo.html', '<html>gizli</html>');
    File::ensureDirectoryExists($fixture['directory'].'/models/'.$fixture['model']->slug.'/steps/demos/items/001/build');
    File::put($fixture['directory'].'/models/'.$fixture['model']->slug.'/steps/demos/items/001/build/demo.html', '<html>demo</html>');
    $files = app(WorkflowRunFiles::class);

    expect($files->stepDetail($fixture['run'], $model, $step, $item, $child))->toBeNull()
        ->and($files->demoPath($fixture['run'], $model, $step, $item ?? '001', $child ?? 'build'))->toBeNull();
})->with([
    'parent model segment' => ['..', 'demos', '001', 'build'],
    'model with a slash' => ['or-files-model/../..', 'demos', '001', 'build'],
    'encoded parent step' => ['or-files-model', '%2e%2e', '001', 'build'],
    'step with a slash' => ['or-files-model', 'demos/../..', '001', 'build'],
    'parent item' => ['or-files-model', 'demos', '..', 'build'],
    'item with a slash' => ['or-files-model', 'demos', '001/..', 'build'],
    'parent child' => ['or-files-model', 'demos', '001', '..'],
    'child with a slash' => ['or-files-model', 'demos', '001', 'build/../../..'],
    'child with a NUL byte' => ['or-files-model', 'demos', '001', "build\0"],
    'uppercase model' => ['OR-FILES', 'demos', '001', 'build'],
]);

test('it returns the newest events across a rotated log and skips unreadable lines', function () {
    $fixture = workflowFilesRun();
    $event = fn (int $number, string $level = 'info'): string => json_encode([
        'time' => '2026-10-22T12:00:0'.($number % 10).'+03:00',
        'level' => $level,
        'type' => 'step_started',
        'model' => 'or-files-model',
        'step' => 'brief',
        'item' => null,
        'message' => "Olay {$number}",
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    File::put($fixture['directory'].'/events.1.jsonl', $event(1)."\n".$event(2)."\n".$event(3, 'debug')."\n");
    File::put($fixture['directory'].'/events.jsonl', $event(4)."\n{bozuk satır\n\n[1,2]\n".$event(5, 'error')."\n");

    $events = app(WorkflowRunFiles::class)->eventsTail($fixture['run'], 4);

    expect(array_column($events, 'message'))->toBe(['Olay 2', 'Olay 3', 'Olay 4', 'Olay 5'])
        ->and(array_column($events, 'level'))->toBe(['info', 'info', 'info', 'error'])
        ->and($events[3])->toBe([
            'time' => '2026-10-22T12:00:05+03:00',
            'level' => 'error',
            'type' => 'step_started',
            'model' => 'or-files-model',
            'step' => 'brief',
            'item' => null,
            'message' => 'Olay 5',
        ]);
});

test('it reads only the last 64 KiB of a long event log and drops the cut first line', function () {
    $fixture = workflowFilesRun();
    $lines = array_map(fn (int $number): string => json_encode([
        'time' => '2026-10-22T12:00:00+03:00',
        'level' => 'info',
        'type' => 'attempt_started',
        'model' => 'or-files-model',
        'step' => 'brief',
        'item' => null,
        'message' => sprintf('Olay %04d %s', $number, str_repeat('.', 60)),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), range(1, 2000));
    File::put($fixture['directory'].'/events.1.jsonl', json_encode(['type' => 'run_started', 'message' => 'Eski'])."\n");
    File::put($fixture['directory'].'/events.jsonl', implode("\n", $lines)."\n");

    $events = app(WorkflowRunFiles::class)->eventsTail($fixture['run'], 500);
    $numbers = array_map(fn (array $event): int => (int) substr($event['message'], 5, 4), $events);

    expect(count($events))->toBeLessThan(2000)
        ->and(end($numbers))->toBe(2000)
        ->and($numbers)->toBe(range(2001 - count($events), 2000))
        ->and(array_column($events, 'message'))->not->toContain('Eski');
});

test('it clips step detail texts at 200 KB on a character boundary and refuses attempt paths outside the step', function () {
    $fixture = workflowFilesRun();
    $stepDirectory = $fixture['directory'].'/models/'.$fixture['model']->slug.'/steps/brief';
    $longText = str_repeat('ğ', 150_000);
    File::ensureDirectoryExists($stepDirectory.'/attempt-1');
    File::put($stepDirectory.'/attempt-1/prompt.txt', 'Kısa prompt');
    File::put($stepDirectory.'/attempt-1/response.txt', $longText);
    File::put($fixture['directory'].'/secret.txt', 'gizli');
    writeWorkflowFilesJson($stepDirectory.'/result.json', [
        'schema' => 'benchforge-workflow-result/1',
        'stepId' => 'brief',
        'type' => 'llm_text',
        'item' => null,
        'status' => 'completed',
        'message' => null,
        'startedAt' => '2026-10-22T12:00:00+03:00',
        'finishedAt' => '2026-10-22T12:00:12+03:00',
        'durationSeconds' => 12.345,
        'usage' => ['inputTokens' => 10, 'outputTokens' => null, 'costUsd' => 0, 'calls' => 2],
        'attempts' => [
            ['n' => 1, 'kind' => 'initial', 'status' => 'completed', 'error' => null, 'durationSeconds' => 12.3,
                'usage' => ['inputTokens' => 10, 'outputTokens' => null, 'costUsd' => 0, 'calls' => 1],
                'files' => ['prompt' => 'attempt-1/prompt.txt', 'response' => 'attempt-1/response.txt', 'output' => null]],
            ['n' => 2, 'kind' => 'retry', 'status' => 'failed', 'error' => 'Hata',
                'files' => ['prompt' => '../../../../secret.txt', 'response' => '/etc/hosts']],
        ],
        'output' => $longText,
    ]);

    $detail = app(WorkflowRunFiles::class)->stepDetail($fixture['run'], $fixture['model']->slug, 'brief');

    expect($detail['model'])->toBe(['slug' => $fixture['model']->slug, 'name' => 'Dosya modeli'])
        ->and($detail['step'])->toBe(['id' => 'brief', 'type' => 'llm_text', 'title' => 'Özet'])
        ->and($detail['item'])->toBeNull()
        ->and($detail['status'])->toBe('completed')
        ->and($detail['durationSeconds'])->toBe(12.35)
        ->and($detail['usage'])->toBe(['inputTokens' => 10, 'outputTokens' => null, 'costUsd' => 0, 'calls' => 2])
        ->and($detail['attempts'][0]['prompt'])->toBe('Kısa prompt')
        ->and($detail['attempts'][0]['promptTruncated'])->toBeFalse()
        ->and(strlen($detail['attempts'][0]['response']))->toBe(204_800)
        ->and(mb_check_encoding($detail['attempts'][0]['response'], 'UTF-8'))->toBeTrue()
        ->and($detail['attempts'][0]['responseTruncated'])->toBeTrue()
        ->and($detail['attempts'][1]['prompt'])->toBeNull()
        ->and($detail['attempts'][1]['response'])->toBeNull()
        ->and($detail['attempts'][1]['error'])->toBe('Hata')
        ->and(strlen($detail['output']))->toBe(204_800)
        ->and($detail['outputTruncated'])->toBeTrue()
        ->and($detail['demoUrl'])->toBeNull()
        ->and(json_encode($detail, JSON_THROW_ON_ERROR))->not->toContain($fixture['directory']);
});

test('it gives null fields for a step without files and nothing for an unknown model, step or item', function () {
    $fixture = workflowFilesRun();
    writeWorkflowFilesJson($fixture['directory'].'/state.json', [
        'schema' => 'benchforge-workflow-state/1',
        'runId' => $fixture['run']->slug,
        'models' => [$fixture['model']->slug => ['steps' => [
            'ideas' => ['status' => 'running', 'message' => null, 'usage' => ['inputTokens' => null, 'outputTokens' => null, 'costUsd' => null, 'calls' => 0]],
        ]]],
    ]);
    writeWorkflowFilesJson($fixture['directory'].'/models/'.$fixture['model']->slug.'/steps/demos/items.json', [
        ['key' => '001', 'index' => 0, 'label' => 'Kule', 'item' => ['title' => 'Kule']],
    ]);
    $files = app(WorkflowRunFiles::class);

    $pending = $files->stepDetail($fixture['run'], $fixture['model']->slug, 'ideas');
    $item = $files->stepDetail($fixture['run'], $fixture['model']->slug, 'demos', '001', 'build');

    expect($pending)->toMatchArray([
        'status' => 'running',
        'message' => null,
        'startedAt' => null,
        'durationSeconds' => null,
        'attempts' => [],
        'output' => null,
        'outputTruncated' => false,
        'demoUrl' => null,
    ])
        ->and($item)->toMatchArray([
            'step' => ['id' => 'build', 'type' => 'code_text', 'title' => 'Demo yaz'],
            'item' => ['key' => '001', 'label' => 'Kule'],
            'status' => 'pending',
            'attempts' => [],
            'output' => null,
            'demoUrl' => null,
        ])
        ->and($files->stepDetail($fixture['run'], 'or-unknown-000000', 'ideas'))->toBeNull()
        ->and($files->stepDetail($fixture['run'], $fixture['model']->slug, 'missing'))->toBeNull()
        ->and($files->stepDetail($fixture['run'], $fixture['model']->slug, 'demos', '002', 'build'))->toBeNull()
        ->and($files->stepDetail($fixture['run'], $fixture['model']->slug, 'demos', '001', 'missing'))->toBeNull()
        ->and($files->stepDetail($fixture['run'], $fixture['model']->slug, 'ideas', '001', 'build'))->toBeNull()
        ->and($files->stepDetail($fixture['run'], $fixture['model']->slug, 'demos', '001'))->toBeNull();
});

test('it builds gallery cards from items, item files and build results', function () {
    $fixture = workflowFilesRun();
    $foreachDirectory = $fixture['directory'].'/models/'.$fixture['model']->slug.'/steps/demos';
    writeWorkflowFilesJson($foreachDirectory.'/items.json', [
        ['key' => '001', 'index' => 0, 'label' => 'Kule', 'item' => ['title' => 'Kule']],
        ['key' => '002', 'index' => 1, 'label' => 'Labirent', 'item' => ['title' => 'Labirent']],
        ['key' => '../003', 'index' => 2, 'label' => 'Kaçış', 'item' => ['title' => 'Kaçış']],
    ]);
    writeWorkflowFilesJson($foreachDirectory.'/items/001/item.json', [
        'key' => '001', 'index' => 0, 'label' => 'Kule', 'galleryTitle' => 'Kule Savunması', 'status' => 'completed',
    ]);
    writeWorkflowFilesJson($foreachDirectory.'/items/001/build/result.json', [
        'schema' => 'benchforge-workflow-result/1',
        'stepId' => 'build',
        'type' => 'code_text',
        'item' => '001',
        'status' => 'completed',
        'output' => ['file' => 'demo.html', 'bytes' => 2048, 'checks' => [
            'critical' => [],
            'warnings' => [['code' => 'no-resize', 'message' => 'Yeniden boyutlandırma dinleyicisi yok.'], 'Sayfa görünürlüğü kullanılmıyor.'],
        ]],
    ]);
    File::put($foreachDirectory.'/items/001/build/demo.html', '<!doctype html><html></html>');
    writeWorkflowFilesJson($foreachDirectory.'/items/002/item.json', ['key' => '002', 'index' => 1, 'label' => 'Labirent', 'status' => 'failed']);

    $cards = app(WorkflowRunFiles::class)->galleryItems($fixture['run']);

    expect($cards)->toBe([
        [
            'model' => $fixture['model']->slug,
            'modelName' => 'Dosya modeli',
            'step' => 'demos',
            'child' => 'build',
            'item' => '001',
            'title' => 'Kule Savunması',
            'status' => 'completed',
            'message' => null,
            'checks' => [
                'critical' => [],
                'warnings' => [
                    ['code' => 'no-resize', 'message' => 'Yeniden boyutlandırma dinleyicisi yok.'],
                    ['code' => 'check', 'message' => 'Sayfa görünürlüğü kullanılmıyor.'],
                ],
            ],
            'bytes' => 2048,
            'demoUrl' => '/workflows/runs/'.$fixture['run']->slug.'/demos/'.$fixture['model']->slug.'/demos/001/build',
        ],
        [
            'model' => $fixture['model']->slug,
            'modelName' => 'Dosya modeli',
            'step' => 'demos',
            'child' => 'build',
            'item' => '002',
            'title' => 'Labirent',
            'status' => 'failed',
            'message' => null,
            'checks' => null,
            'bytes' => null,
            'demoUrl' => null,
        ],
    ]);
});

test('it explains a failed demo card with the checks of its last checked attempt and the step message', function () {
    $fixture = workflowFilesRun();
    $foreachDirectory = $fixture['directory'].'/models/'.$fixture['model']->slug.'/steps/demos';
    $buildDirectory = $foreachDirectory.'/items/001/build';
    writeWorkflowFilesJson($foreachDirectory.'/items.json', [
        ['key' => '001', 'index' => 0, 'label' => 'Kule', 'item' => ['title' => 'Kule']],
        ['key' => '002', 'index' => 1, 'label' => 'Labirent', 'item' => ['title' => 'Labirent']],
    ]);
    writeWorkflowFilesJson($buildDirectory.'/attempt-1/checks.json', [
        'critical' => [['code' => 'missing-html-end', 'message' => 'Çıktı kesilmiş: <html> veya </html> etiketi yok.']],
        'warnings' => [],
    ]);
    writeWorkflowFilesJson($buildDirectory.'/attempt-2/checks.json', [
        'critical' => [['code' => 'missing-input-listener', 'message' => 'Dokunma/fare dinleyicisi yok (pointerdown, touchstart veya mousedown).']],
        'warnings' => [['code' => 'no-resize', 'message' => 'Yeniden boyutlandırma dinleyicisi yok.']],
    ]);
    writeWorkflowFilesJson($buildDirectory.'/result.json', [
        'schema' => 'benchforge-workflow-result/1',
        'stepId' => 'build',
        'type' => 'code_text',
        'item' => '001',
        'status' => 'failed',
        'message' => 'Demo statik kontrollerden geçemedi: '.str_repeat('ğ', 600),
        'attempts' => [
            ['n' => 1, 'kind' => 'initial', 'status' => 'failed', 'files' => ['prompt' => 'attempt-1/prompt.txt', 'checks' => 'attempt-1/checks.json']],
            ['n' => 2, 'kind' => 'repair', 'status' => 'failed', 'files' => ['prompt' => 'attempt-2/prompt.txt', 'checks' => 'attempt-2/checks.json']],
            ['n' => 3, 'kind' => 'repair', 'status' => 'failed', 'error' => 'Süre sınırı (600 sn) doldu.', 'files' => ['prompt' => 'attempt-3/prompt.txt', 'checks' => null]],
        ],
        'output' => null,
    ]);
    File::put($fixture['directory'].'/checks.json', json_encode(['critical' => [['code' => 'secret', 'message' => 'gizli']]]));
    writeWorkflowFilesJson($foreachDirectory.'/items/002/build/result.json', [
        'schema' => 'benchforge-workflow-result/1',
        'stepId' => 'build',
        'type' => 'code_text',
        'item' => '002',
        'status' => 'failed',
        'message' => 'Model sağlayıcıda bulunamadı veya kaldırıldı.',
        'attempts' => [
            ['n' => 1, 'kind' => 'initial', 'status' => 'failed', 'files' => ['checks' => '../../../../../../checks.json']],
        ],
        'output' => null,
    ]);

    $cards = app(WorkflowRunFiles::class)->galleryItems($fixture['run']);

    expect($cards[0])->toMatchArray([
        'item' => '001',
        'status' => 'failed',
        'checks' => [
            'critical' => [['code' => 'missing-input-listener', 'message' => 'Dokunma/fare dinleyicisi yok (pointerdown, touchstart veya mousedown).']],
            'warnings' => [['code' => 'no-resize', 'message' => 'Yeniden boyutlandırma dinleyicisi yok.']],
        ],
        'bytes' => null,
        'demoUrl' => null,
    ])
        ->and($cards[0]['message'])->toStartWith('Demo statik kontrollerden geçemedi: ğğ')
        ->and(mb_strlen($cards[0]['message']))->toBe(501)
        ->and($cards[1])->toMatchArray([
            'item' => '002',
            'status' => 'failed',
            'message' => 'Model sağlayıcıda bulunamadı veya kaldırıldı.',
            'checks' => null,
        ]);
});

test('it lists no gallery cards for a template without a gallery', function () {
    $fixture = workflowFilesRun();
    $template = workflowFilesTemplate();
    unset($template['gallery']);
    writeWorkflowFilesJson($fixture['directory'].'/template.json', $template);
    writeWorkflowFilesJson($fixture['directory'].'/models/'.$fixture['model']->slug.'/steps/demos/items.json', [
        ['key' => '001', 'index' => 0, 'label' => 'Kule', 'item' => ['title' => 'Kule']],
    ]);

    expect(app(WorkflowRunFiles::class)->galleryItems($fixture['run']))->toBe([]);
});

test('it resolves a demo only as a regular file inside the run directory', function () {
    $fixture = workflowFilesRun();
    $buildDirectory = $fixture['directory'].'/models/'.$fixture['model']->slug.'/steps/demos/items/001/build';
    $outside = realpath((string) config('benchforge.root_path')).'/outside-demo-'.Str::lower(Str::random(6));
    File::ensureDirectoryExists($buildDirectory);
    File::ensureDirectoryExists($outside.'/build');
    File::put($buildDirectory.'/demo.html', '<html>demo</html>');
    File::put($outside.'/build/demo.html', '<html>dışarıda</html>');
    $files = app(WorkflowRunFiles::class);
    $slug = $fixture['model']->slug;

    expect($files->demoPath($fixture['run'], $slug, 'demos', '001', 'build'))->toBe($buildDirectory.'/demo.html');

    $itemsDirectory = dirname($buildDirectory, 2);
    File::ensureDirectoryExists($itemsDirectory.'/002');
    symlink($outside.'/build', $itemsDirectory.'/002/build');
    File::ensureDirectoryExists($itemsDirectory.'/003/build');
    symlink($outside.'/build/demo.html', $itemsDirectory.'/003/build/demo.html');
    symlink($outside, $itemsDirectory.'/004');

    expect($files->demoPath($fixture['run'], $slug, 'demos', '002', 'build'))->toBeNull()
        ->and($files->demoPath($fixture['run'], $slug, 'demos', '003', 'build'))->toBeNull()
        ->and($files->demoPath($fixture['run'], $slug, 'demos', '004', 'build'))->toBeNull()
        ->and($files->demoPath($fixture['run'], $slug, 'demos', '005', 'build'))->toBeNull();
});

/**
 * A run whose template snapshot also has a top-level code step (`poster`) next to the foreach demos.
 *
 * @return array{run: WorkflowRun, model: WorkflowRunModel, directory: string}
 */
function workflowFilesTopLevelDemoRun(): array
{
    $fixture = workflowFilesRun();
    $template = workflowFilesTemplate();
    $template['steps'][] = ['id' => 'poster', 'type' => 'code_text', 'title' => 'Afiş demosu', 'prompt' => 'Build a poster.'];
    writeWorkflowFilesJson($fixture['directory'].'/template.json', $template);

    return $fixture;
}

test('it resolves the demo of a top-level code step and links it from the step detail', function () {
    $fixture = workflowFilesTopLevelDemoRun();
    $stepDirectory = $fixture['directory'].'/models/'.$fixture['model']->slug.'/steps/poster';
    $files = app(WorkflowRunFiles::class);

    expect($files->demoPath($fixture['run'], $fixture['model']->slug, 'poster'))->toBeNull()
        ->and($files->stepDetail($fixture['run'], $fixture['model']->slug, 'poster')['demoUrl'])->toBeNull();

    File::ensureDirectoryExists($stepDirectory);
    File::put($stepDirectory.'/demo.html', '<!doctype html><html><body>afiş</body></html>');
    writeWorkflowFilesJson($stepDirectory.'/result.json', [
        'schema' => 'benchforge-workflow-result/1',
        'stepId' => 'poster',
        'type' => 'code_text',
        'item' => null,
        'status' => 'completed',
        'output' => ['file' => 'demo.html', 'bytes' => 44, 'checks' => ['critical' => [], 'warnings' => []]],
    ]);

    $detail = $files->stepDetail($fixture['run'], $fixture['model']->slug, 'poster');

    expect($files->demoPath($fixture['run'], $fixture['model']->slug, 'poster'))->toBe($stepDirectory.'/demo.html')
        ->and($detail['step'])->toBe(['id' => 'poster', 'type' => 'code_text', 'title' => 'Afiş demosu'])
        ->and($detail['item'])->toBeNull()
        ->and($detail['status'])->toBe('completed')
        ->and($detail['demoUrl'])->toBe('/workflows/runs/'.$fixture['run']->slug.'/demos/'.$fixture['model']->slug.'/poster');
});

test('it resolves no top-level demo for a step that is not a top-level code step', function (string $stepId) {
    $fixture = workflowFilesTopLevelDemoRun();
    $stepsDirectory = $fixture['directory'].'/models/'.$fixture['model']->slug.'/steps';
    File::ensureDirectoryExists($stepsDirectory.'/'.$stepId);
    File::put($stepsDirectory.'/'.$stepId.'/demo.html', '<html>demo</html>');
    $files = app(WorkflowRunFiles::class);

    expect($files->demoPath($fixture['run'], $fixture['model']->slug, $stepId))->toBeNull()
        ->and($files->stepDetail($fixture['run'], $fixture['model']->slug, $stepId)['demoUrl'] ?? null)->toBeNull();
})->with([
    'a text step' => ['brief'],
    'a json step' => ['ideas'],
    'a foreach step' => ['demos'],
    'a code child of a foreach' => ['build'],
    'a step that is not in the template' => ['ghost'],
]);

test('it resolves no top-level demo through traversal, a half item reference or a linked file', function () {
    $fixture = workflowFilesTopLevelDemoRun();
    $root = realpath((string) config('benchforge.root_path'));
    $modelDirectory = $fixture['directory'].'/models/'.$fixture['model']->slug;
    File::put($fixture['directory'].'/demo.html', '<html>gizli</html>');
    File::ensureDirectoryExists($modelDirectory.'/steps/poster');
    File::put($modelDirectory.'/steps/poster/demo.html', '<html>afiş</html>');
    File::put($root.'/outside-demo.html', '<html>dışarıda</html>');
    $files = app(WorkflowRunFiles::class);
    $slug = $fixture['model']->slug;

    expect($files->demoPath($fixture['run'], '..', 'poster'))->toBeNull()
        ->and($files->demoPath($fixture['run'], $slug.'/../..', 'poster'))->toBeNull()
        ->and($files->demoPath($fixture['run'], $slug, '..'))->toBeNull()
        ->and($files->demoPath($fixture['run'], $slug, 'poster/../..'))->toBeNull()
        ->and($files->demoPath($fixture['run'], $slug, '%2e%2e'))->toBeNull()
        ->and($files->demoPath($fixture['run'], $slug, "poster\0"))->toBeNull()
        ->and($files->demoPath($fixture['run'], $slug, 'poster', '001'))->toBeNull()
        ->and($files->demoPath($fixture['run'], $slug, 'poster', null, 'build'))->toBeNull()
        ->and($files->demoPath($fixture['run'], $slug, 'poster'))->toBe($modelDirectory.'/steps/poster/demo.html');

    File::delete($modelDirectory.'/steps/poster/demo.html');
    symlink($root.'/outside-demo.html', $modelDirectory.'/steps/poster/demo.html');

    expect($files->demoPath($fixture['run'], $slug, 'poster'))->toBeNull();

    File::delete($modelDirectory.'/steps/poster/demo.html');
    File::deleteDirectory($modelDirectory.'/steps/poster');
    File::ensureDirectoryExists($root.'/outside-step');
    File::put($root.'/outside-step/demo.html', '<html>dışarıda</html>');
    symlink($root.'/outside-step', $modelDirectory.'/steps/poster');

    expect($files->demoPath($fixture['run'], $slug, 'poster'))->toBeNull();
});
