<?php

use App\Exceptions\BenchmarkRunnerException;
use App\Services\WorkflowTemplateStore;
use Illuminate\Support\Facades\File;

/**
 * @return array<string, mixed>
 */
function workflowStoreTemplate(string $id = 'notes-flow', string $name = 'Not akışı'): array
{
    return [
        'schema' => 'benchforge-workflow/1',
        'id' => $id,
        'name' => $name,
        'version' => 1,
        'steps' => [
            ['id' => 'draft', 'type' => 'llm_text', 'title' => 'Taslak', 'prompt' => 'Write a short note.'],
        ],
    ];
}

function workflowUserTemplatePath(string $id, int $version): string
{
    return config('benchforge.root_path').'/workflows/'.$id.'/'.sprintf('v%03d.json', $version);
}

function writeWorkflowUserTemplate(string $id, int $version, string $contents): void
{
    File::ensureDirectoryExists(dirname(workflowUserTemplatePath($id, $version)));
    File::put(workflowUserTemplatePath($id, $version), $contents);
}

function builtinWorkflowTemplatePath(): string
{
    return base_path('engine/workflow-templates/game-idea-factory/v001.json');
}

test('it lists the built-in game idea factory and returns its exact bytes', function () {
    $store = app(WorkflowTemplateStore::class);
    $raw = File::get(builtinWorkflowTemplatePath());

    $templates = $store->all();
    $found = $store->find('game-idea-factory');

    expect(collect($templates)->firstWhere('id', 'game-idea-factory'))->toBe([
        'id' => 'game-idea-factory',
        'name' => 'Oyun Fikir Fabrikası',
        'description' => 'Dört kategoride (var olan, özgün, melez ve yeniden yorumlanan oyunlar) oyun fikirleri üretir, fikirleri puanlar ve her fikir için tek dosyalık, oynanabilir bir HTML5 demo yazar.',
        'version' => 1,
        'source' => 'builtin',
        'stepCount' => 3,
        'valid' => true,
        'error' => null,
    ]);
    expect($found)->not->toBeNull()
        ->and($found['raw'])->toBe($raw)
        ->and($found['sha256'])->toBe(hash('sha256', $raw))
        ->and($found['version'])->toBe(1)
        ->and($found['source'])->toBe('builtin')
        ->and($found['template']['id'])->toBe('game-idea-factory');
    expect($store->versions('game-idea-factory'))->toBe([['version' => 1, 'source' => 'builtin']]);
});

test('it saves every change as a new version and never rewrites an earlier one', function () {
    $store = app(WorkflowTemplateStore::class);

    $first = $store->save(workflowStoreTemplate());
    $firstBytes = File::get(workflowUserTemplatePath('notes-flow', 1));
    $second = $store->save(workflowStoreTemplate(name: 'Not akışı 2'));

    expect($first['version'])->toBe(1)
        ->and($first['source'])->toBe('user')
        ->and($second['version'])->toBe(2)
        ->and($second['template']['version'])->toBe(2)
        ->and($second['raw'])->toBe(File::get(workflowUserTemplatePath('notes-flow', 2)))
        ->and(File::get(workflowUserTemplatePath('notes-flow', 1)))->toBe($firstBytes);
    expect($store->find('notes-flow')['template']['name'])->toBe('Not akışı 2')
        ->and($store->find('notes-flow', 1)['template']['name'])->toBe('Not akışı')
        ->and($store->versions('notes-flow'))->toBe([['version' => 1, 'source' => 'user'], ['version' => 2, 'source' => 'user']]);
    expect(File::files(dirname(workflowUserTemplatePath('notes-flow', 1))))->toHaveCount(2);
});

test('it numbers a new version after every existing version file, even an unreadable one', function () {
    writeWorkflowUserTemplate('notes-flow', 7, '{ bozuk');

    $saved = app(WorkflowTemplateStore::class)->save(workflowStoreTemplate());

    expect($saved['version'])->toBe(8)
        ->and(File::get(workflowUserTemplatePath('notes-flow', 7)))->toBe('{ bozuk');
});

test('it saves an edited built-in template as the next user version and leaves the built-in file alone', function () {
    $store = app(WorkflowTemplateStore::class);
    $builtinHash = hash_file('sha256', builtinWorkflowTemplatePath());
    $template = $store->find('game-idea-factory')['template'];
    $template['name'] = 'Oyun Fikir Fabrikası (kişisel)';

    $saved = $store->save($template);

    expect($saved['version'])->toBe(2)
        ->and($saved['source'])->toBe('user')
        ->and(File::exists(workflowUserTemplatePath('game-idea-factory', 2)))->toBeTrue()
        ->and(hash_file('sha256', builtinWorkflowTemplatePath()))->toBe($builtinHash);
    expect(collect($store->all())->firstWhere('id', 'game-idea-factory'))
        ->toMatchArray(['name' => 'Oyun Fikir Fabrikası (kişisel)', 'version' => 2, 'source' => 'user', 'valid' => true]);
    expect($store->versions('game-idea-factory'))->toBe([['version' => 1, 'source' => 'builtin'], ['version' => 2, 'source' => 'user']])
        ->and($store->find('game-idea-factory', 1)['source'])->toBe('builtin');
});

test('it lists broken template files as invalid and never returns them', function (string $contents, string $error) {
    writeWorkflowUserTemplate('broken-flow', 1, $contents);
    $store = app(WorkflowTemplateStore::class);

    expect(collect($store->all())->firstWhere('id', 'broken-flow'))
        ->toMatchArray(['version' => 1, 'source' => 'user', 'valid' => false, 'error' => $error])
        ->and($store->find('broken-flow'))->toBeNull()
        ->and($store->find('broken-flow', 1))->toBeNull();
})->with([
    'invalid JSON' => ['{"schema": "benchforge-workflow/1",', 'Şablon dosyası geçerli bir JSON değil.'],
    'unknown step type' => [
        json_encode([...workflowStoreTemplate('broken-flow'), 'steps' => [['id' => 'draft', 'type' => 'llm_magic', 'title' => 'Taslak']]], JSON_THROW_ON_ERROR),
        'steps[0].type: Bilinmeyen adım türü: llm_magic.',
    ],
    'id of another folder' => [
        json_encode(workflowStoreTemplate('other-flow'), JSON_THROW_ON_ERROR),
        'id: Şablon kimliği (other-flow) bulunduğu klasörün adıyla (broken-flow) aynı olmalı.',
    ],
]);

test('it refuses to save an invalid template and writes nothing', function () {
    $template = workflowStoreTemplate();
    $template['steps'][0]['type'] = 'llm_magic';

    expect(fn () => app(WorkflowTemplateStore::class)->save($template))
        ->toThrow(BenchmarkRunnerException::class, 'steps[0].type: Bilinmeyen adım türü: llm_magic.');
    expect(File::exists(config('benchforge.root_path').'/workflows'))->toBeFalse();
});

test('it ignores and refuses to write through a symbolic link template folder', function () {
    $outside = config('benchforge.test_sandbox_path').'/outside-workflow';
    File::ensureDirectoryExists($outside);
    File::put($outside.'/v001.json', json_encode(workflowStoreTemplate('linked-flow'), JSON_THROW_ON_ERROR));
    File::ensureDirectoryExists(config('benchforge.root_path').'/workflows');
    symlink($outside, config('benchforge.root_path').'/workflows/linked-flow');
    $store = app(WorkflowTemplateStore::class);

    expect(collect($store->all())->pluck('id'))->not->toContain('linked-flow')
        ->and($store->find('linked-flow'))->toBeNull()
        ->and($store->versions('linked-flow'))->toBe([]);
    expect(fn () => $store->save(workflowStoreTemplate('linked-flow')))
        ->toThrow(BenchmarkRunnerException::class, 'Şablon klasörü sembolik bağlantı olamaz: workflows/linked-flow');
    expect(File::files($outside))->toHaveCount(1);
});

test('it ignores and refuses a data root whose workflows folder is a symbolic link', function () {
    $outside = config('benchforge.test_sandbox_path').'/outside-workflows';
    File::ensureDirectoryExists($outside.'/notes-flow');
    File::put($outside.'/notes-flow/v001.json', json_encode(workflowStoreTemplate(), JSON_THROW_ON_ERROR));
    symlink($outside, config('benchforge.root_path').'/workflows');
    $store = app(WorkflowTemplateStore::class);

    expect(collect($store->all())->pluck('id'))->toContain('game-idea-factory')->not->toContain('notes-flow')
        ->and($store->find('notes-flow'))->toBeNull();
    expect(fn () => $store->save(workflowStoreTemplate()))
        ->toThrow(BenchmarkRunnerException::class, 'Şablon klasörü sembolik bağlantı olamaz: workflows');
    expect(File::files($outside.'/notes-flow'))->toHaveCount(1);
});

test('it does not read a version file that is a symbolic link', function () {
    $outside = config('benchforge.test_sandbox_path').'/outside-template.json';
    File::put($outside, json_encode(workflowStoreTemplate(), JSON_THROW_ON_ERROR));
    File::ensureDirectoryExists(dirname(workflowUserTemplatePath('notes-flow', 1)));
    symlink($outside, workflowUserTemplatePath('notes-flow', 1));
    $store = app(WorkflowTemplateStore::class);

    expect($store->find('notes-flow'))->toBeNull()
        ->and($store->save(workflowStoreTemplate())['version'])->toBe(2)
        ->and(is_link(workflowUserTemplatePath('notes-flow', 1)))->toBeTrue();
});

test('it rejects template ids that could leave the template folders', function (string $id) {
    File::ensureDirectoryExists(config('benchforge.root_path').'/secret');
    File::put(config('benchforge.root_path').'/secret/v001.json', json_encode(workflowStoreTemplate('secret'), JSON_THROW_ON_ERROR));
    $store = app(WorkflowTemplateStore::class);

    expect($store->find($id))->toBeNull()
        ->and($store->find($id, 1))->toBeNull()
        ->and($store->versions($id))->toBe([]);
    expect(fn () => $store->save(workflowStoreTemplate($id)))
        ->toThrow(BenchmarkRunnerException::class, 'id: Şablon kimliği 1–64 karakter olmalı');
})->with(['../secret', '..', 'game-idea-factory/../secret', '/tmp', 'Secret', '-secret', 'secret-']);

test('it returns null for versions that do not exist', function () {
    $store = app(WorkflowTemplateStore::class);

    expect($store->find('game-idea-factory', 2))->toBeNull()
        ->and($store->find('game-idea-factory', 0))->toBeNull()
        ->and($store->find('missing-flow'))->toBeNull()
        ->and($store->versions('missing-flow'))->toBe([]);
});
