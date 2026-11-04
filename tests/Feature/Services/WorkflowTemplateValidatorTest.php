<?php

use App\Services\WorkflowTemplateValidator;

/**
 * A small valid template: a JSON step, a text step that reads it, and a foreach over constants.
 *
 * @return array<string, mixed>
 */
function workflowValidatorTemplate(): array
{
    return [
        'schema' => 'benchforge-workflow/1',
        'id' => 'test-flow',
        'name' => 'Deneme akışı',
        'version' => 1,
        'constants' => ['topics' => [['name' => 'Uzay', 'brief' => 'Space travel']]],
        'inputs' => [
            ['id' => 'count', 'type' => 'integer', 'label' => 'Adet', 'default' => 3, 'min' => 1, 'max' => 5],
        ],
        'steps' => [
            [
                'id' => 'outline',
                'type' => 'llm_json',
                'title' => 'Taslak',
                'prompt' => 'Write {{ inputs.count }} ideas.',
                'schema' => [
                    'type' => 'object',
                    'required' => ['ideas'],
                    'properties' => [
                        'ideas' => [
                            'type' => 'array',
                            'items' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]],
                        ],
                    ],
                ],
            ],
            ['id' => 'summary', 'type' => 'llm_text', 'title' => 'Özet', 'dependsOn' => ['outline'], 'prompt' => 'Summarize {{steps.outline.output.ideas[*].title}}'],
            [
                'id' => 'topics',
                'type' => 'foreach',
                'title' => 'Konular',
                'over' => 'constants.topics',
                'itemLabel' => '{{item.name}}',
                'steps' => [
                    ['id' => 'write', 'type' => 'llm_text', 'title' => 'Yaz', 'prompt' => 'Write about {{item.brief}} ({{loop.number}}/{{loop.total}}).'],
                ],
            ],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function workflowBuiltinTemplate(): array
{
    return json_decode((string) file_get_contents(base_path('engine/workflow-templates/game-idea-factory/v001.json')), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * @return array{paths: list<array{path: string, valid: bool}>, templates: list<array{text: string, valid: bool, paths: list<string>}>}
 */
function workflowPathVectors(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/workflows/path-vectors.json'), true, 512, JSON_THROW_ON_ERROR);
}

test('it accepts the built-in game idea factory template', function () {
    expect(app(WorkflowTemplateValidator::class)->validate(workflowBuiltinTemplate()))->toBe([]);
});

test('it lists the placeholders and foreach sources of the built-in template with their location', function () {
    $references = app(WorkflowTemplateValidator::class)->references(workflowBuiltinTemplate());

    expect($references)
        ->toContain(['path' => 'constants.categories', 'at' => 'steps[0].over'])
        ->toContain(['path' => 'loop.previous[*].generate.output.ideas[*].title', 'at' => 'steps[0].steps[0].prompt'])
        ->toContain(['path' => 'steps.ideas.items[*].generate.output.ideas[*]', 'at' => 'steps[1].prompt'])
        ->toContain(['path' => 'steps.ideas.items[*].generate.output.ideas[*]', 'at' => 'steps[2].over'])
        ->toContain(['path' => 'item.demoSpec', 'at' => 'steps[2].steps[0].prompt'])
        ->toContain(['path' => 'item.title', 'at' => 'gallery.title']);
});

test('it parses paths with the same grammar as the worker', function (string $path, bool $valid) {
    expect(app(WorkflowTemplateValidator::class)->parsePath($path) !== null)->toBe($valid);
})->with(function (): array {
    $cases = [];

    foreach (workflowPathVectors()['paths'] as $vector) {
        $cases[json_encode($vector['path'], JSON_THROW_ON_ERROR)] = [$vector['path'], $vector['valid']];
    }

    return $cases;
});

test('it finds placeholders with the same rules as the worker', function (string $text, bool $valid, array $paths) {
    $placeholders = app(WorkflowTemplateValidator::class)->findPlaceholders($text);

    expect($placeholders['errors'] === [])->toBe($valid)
        ->and($placeholders['paths'])->toBe($paths);
})->with(function (): array {
    $cases = [];

    foreach (workflowPathVectors()['templates'] as $vector) {
        $cases[json_encode($vector['text'], JSON_THROW_ON_ERROR)] = [$vector['text'], $vector['valid'], $vector['paths']];
    }

    return $cases;
});

test('it allows only ASCII whitespace around a placeholder path, like the worker', function () {
    $placeholders = app(WorkflowTemplateValidator::class)->findPlaceholders("{{\f inputs.ready\v}} {{\u{00A0}inputs.count}}");

    expect($placeholders['paths'])->toBe(['inputs.ready'])
        ->and($placeholders['errors'])->toHaveCount(1);
});

test('it never resolves prototype names in constants', function (string $name) {
    $template = workflowValidatorTemplate();
    $template['constants'][$name] = 'hidden';
    data_set($template, 'steps.1.prompt', "Use {{constants.{$name}}}");

    expect(app(WorkflowTemplateValidator::class)->validate($template))
        ->toBe(["steps[1].prompt: {{constants.{$name}}}: “{$name}” alanı bulunamadı."]);
})->with(['__proto__', 'constructor', 'prototype']);

test('it splits a path into root and segments', function () {
    expect(app(WorkflowTemplateValidator::class)->parsePath('steps.ideas.items[*].generate.output.ideas[12].title'))->toBe([
        'root' => 'steps',
        'segments' => [
            ['type' => 'field', 'name' => 'ideas'],
            ['type' => 'field', 'name' => 'items'],
            ['type' => 'all'],
            ['type' => 'field', 'name' => 'generate'],
            ['type' => 'field', 'name' => 'output'],
            ['type' => 'field', 'name' => 'ideas'],
            ['type' => 'index', 'index' => 12],
            ['type' => 'field', 'name' => 'title'],
        ],
    ]);
});

test('it rejects placeholders that do not resolve', function (string $field, string $text, string $error) {
    $template = workflowValidatorTemplate();
    data_set($template, $field, $text);

    expect(app(WorkflowTemplateValidator::class)->validate($template))->toBe([$error]);
})->with([
    'missing input' => ['steps.1.prompt', 'Use {{inputs.missing}}', 'steps[1].prompt: {{inputs.missing}}: “missing” adında bir girdi yok.'],
    'path after an input' => ['steps.1.prompt', 'Use {{inputs.count.value}}', 'steps[1].prompt: {{inputs.count.value}}: Girdi değerinden sonra başka bir yol parçası gelemez.'],
    'missing constant field' => ['steps.1.prompt', 'Use {{constants.topics[0].title}}', 'steps[1].prompt: {{constants.topics[0].title}}: “title” alanı bulunamadı.'],
    'constant index out of range' => ['steps.1.prompt', 'Use {{constants.topics[3]}}', 'steps[1].prompt: {{constants.topics[3]}}: [3] bulunamadı; değer bir dizi değil ya da dizi daha kısa.'],
    'field missing from the JSON schema' => ['steps.1.prompt', 'Use {{steps.outline.output.ideas[*].titel}}', 'steps[1].prompt: {{steps.outline.output.ideas[*].titel}}: Şemada “titel” alanı yok.'],
    'step without output' => ['steps.1.prompt', 'Use {{steps.outline}}', 'steps[1].prompt: {{steps.outline}}: “outline” adımından sonra .output gelmeli.'],
    'missing step' => ['steps.1.prompt', 'Use {{steps.nothing.output}}', 'steps[1].prompt: {{steps.nothing.output}}: “nothing” adında bir üst düzey adım yok.'],
    'later step' => ['steps.0.prompt', 'Use {{steps.summary.output}}', 'steps[0].prompt: {{steps.summary.output}}: “summary” adımı daha sonra geliyor; yalnız önceki adımlara başvurulabilir.'],
    'own output' => ['steps.1.prompt', 'Use {{steps.summary.output}}', 'steps[1].prompt: {{steps.summary.output}}: Adım kendi çıktısına başvuramaz.'],
    'foreach child addressed directly' => ['steps.1.prompt', 'Use {{steps.write.output}}', 'steps[1].prompt: {{steps.write.output}}: “write” bir foreach alt adımı; steps.topics.items[*].write.output biçimini kullanın.'],
    'field after a text output' => ['steps.2.steps.0.prompt', 'Use {{steps.summary.output.text}}', 'steps[2].steps[0].prompt: {{steps.summary.output.text}}: Metin çıktısından sonra .text gelemez.'],
    'invalid placeholder' => ['steps.1.prompt', 'Use {{ inputs.count + 1 }}', 'steps[1].prompt: Geçersiz yer tutucu: {{ inputs.count + 1 }}. Yalnız {{ inputs.x }} gibi yol ifadeleri kullanılabilir.'],
]);

test('it checks references into the built-in template idea schema', function (string $field, string $text, string $error) {
    $template = workflowBuiltinTemplate();
    data_set($template, $field, $text);

    expect(app(WorkflowTemplateValidator::class)->validate($template))->toBe([$error]);
})->with([
    'foreach item record field' => ['steps.1.prompt', 'Rate {{steps.ideas.items[*].generate.output.ideas[*].titel}}', 'steps[1].prompt: {{steps.ideas.items[*].generate.output.ideas[*].titel}}: Şemada “titel” alanı yok.'],
    'item field from the schema' => ['steps.2.steps.0.prompt', 'Build {{item.pitchh}}', 'steps[2].steps[0].prompt: {{item.pitchh}}: Şemada “pitchh” alanı yok.'],
    'unknown child of an item record' => ['steps.1.prompt', 'Rate {{steps.ideas.items[0].score.output}}', 'steps[1].prompt: {{steps.ideas.items[0].score.output}}: Öğe kaydında “score” yok; item, index, key, label veya “ideas” adımının bir alt adım kimliğini kullanın.'],
]);

test('it rejects dependsOn entries that are not earlier steps at the same level', function (string $field, array $dependsOn, string $error) {
    $template = workflowValidatorTemplate();
    data_set($template, $field, $dependsOn);

    expect(app(WorkflowTemplateValidator::class)->validate($template))->toBe([$error]);
})->with([
    'itself' => ['steps.1.dependsOn', ['summary'], 'steps[1].dependsOn[0]: Adım kendisine bağlı olamaz.'],
    'a later step' => ['steps.0.dependsOn', ['summary'], 'steps[0].dependsOn[0]: “summary” bu adımdan sonra geliyor; yalnız daha önceki adımlara bağlanılabilir.'],
    'a missing step' => ['steps.1.dependsOn', ['nothing'], 'steps[1].dependsOn[0]: “nothing” adında bir adım yok.'],
    'a step on another level' => ['steps.1.dependsOn', ['write'], 'steps[1].dependsOn[0]: “write” başka bir düzeyde; dependsOn yalnız aynı düzeydeki önceki adımları gösterebilir.'],
]);

test('it rejects unknown step types and unknown keys', function (string $field, mixed $value, string $error) {
    $template = workflowValidatorTemplate();
    data_set($template, $field, $value);

    expect(app(WorkflowTemplateValidator::class)->validate($template))->toBe([$error]);
})->with([
    'unknown step type' => ['steps.1.type', 'llm_magic', 'steps[1].type: Bilinmeyen adım türü: llm_magic.'],
    'misspelled step key' => ['steps.1.promt', 'Hello', 'steps[1].promt: Bilinmeyen alan.'],
    'unknown top-level key' => ['author', 'Mehmet', 'author: Bilinmeyen alan.'],
    'unknown retry key' => ['steps.0.retry', ['attempts' => 2, 'delay' => 5], 'steps[0].retry.delay: Bilinmeyen alan.'],
    'unknown input key' => ['inputs.0.placeholder', 'Örnek', 'inputs[0].placeholder: Bilinmeyen alan.'],
    'prompt on a foreach' => ['steps.2.prompt', 'Hello', 'steps[2].prompt: Bilinmeyen alan.'],
]);

test('it rejects JSON schema keywords outside the supported subset', function (string $field, mixed $value, string $error) {
    $template = workflowValidatorTemplate();
    data_set($template, $field, $value);

    expect(app(WorkflowTemplateValidator::class)->validate($template))->toBe([$error]);
})->with([
    'combinator' => ['steps.0.schema.properties.ideas.oneOf', [['type' => 'array']], 'steps[0].schema.properties.ideas: Desteklenmeyen şema anahtarı: oneOf ($.ideas)'],
    'pattern on an item field' => ['steps.0.schema.properties.ideas.items.properties.title.pattern', '^[A-Z]', 'steps[0].schema.properties.ideas.items.properties.title: Desteklenmeyen şema anahtarı: pattern ($.ideas[*].title)'],
    'type list' => ['steps.0.schema.properties.ideas.items.properties.title.type', ['string', 'null'], 'steps[0].schema.properties.ideas.items.properties.title: type tek bir değer olmalı: object, array, string, integer, number, boolean veya null ($.ideas[*].title).'],
    'schema additionalProperties' => ['steps.0.schema.additionalProperties', ['type' => 'string'], 'steps[0].schema: additionalProperties yalnız true veya false olabilir ($).'],
]);

test('it rejects item and loop outside foreach steps', function (string $text, string $error) {
    $template = workflowValidatorTemplate();
    data_set($template, 'steps.1.prompt', $text);

    expect(app(WorkflowTemplateValidator::class)->validate($template))->toBe([$error]);
})->with([
    'item' => ['About {{item.brief}}', 'steps[1].prompt: {{item.brief}}: item yalnız foreach alt adımlarında kullanılabilir.'],
    'loop' => ['Number {{loop.number}}', 'steps[1].prompt: {{loop.number}}: loop yalnız foreach alt adımlarında kullanılabilir.'],
]);

test('it allows loop.previous only in a foreach that runs one item at a time', function () {
    $sequential = workflowValidatorTemplate();
    data_set($sequential, 'steps.2.steps.0.prompt', 'Avoid {{loop.previous[*].write.output}}');
    $parallel = $sequential;
    data_set($parallel, 'steps.2.concurrency', 2);

    $validator = app(WorkflowTemplateValidator::class);

    expect($validator->validate($sequential))->toBe([])
        ->and($validator->validate($parallel))->toBe([
            'steps[2].steps[0].prompt: {{loop.previous[*].write.output}}: loop.previous yalnız concurrency değeri 1 olan foreach içinde kullanılabilir.',
        ]);
});

test('it rejects foreach sources and children that cannot run', function (string $field, mixed $value, string $error) {
    $template = workflowValidatorTemplate();
    data_set($template, $field, $value);

    expect(app(WorkflowTemplateValidator::class)->validate($template))->toBe([$error]);
})->with([
    'source is not an array' => ['steps.2.over', 'constants.topics[0]', 'steps[2].over: constants.topics[0]: Bir diziye çözümlenmeli.'],
    'source uses item' => ['steps.2.over', 'item.topics', 'steps[2].over: item.topics: foreach.over yalnız inputs, constants veya steps ile başlayabilir.'],
    'source with braces' => ['steps.2.over', '{{constants.topics}}', 'steps[2].over: Süslü parantezsiz geçerli bir yol ifadesi olmalı (ör. constants.categories).'],
    'nested foreach' => ['steps.2.steps.0.type', 'foreach', 'steps[2].steps[0].type: İç içe foreach desteklenmiyor.'],
    'loop.previous in the item label' => ['steps.2.itemLabel', '{{loop.previous[0].label}}', 'steps[2].itemLabel: {{loop.previous[0].label}}: loop.previous öğe etiketinde ve galeri başlığında kullanılamaz.'],
    'child id reserved by item records' => ['steps.2.steps.0.id', 'label', 'steps[2].steps[0].id: “label” ayrılmış bir ad; başka bir kimlik seçin.'],
    'concurrency above five' => ['steps.2.concurrency', 6, 'steps[2].concurrency: 1–5 arasında bir tam sayı olmalı.'],
]);

test('it requires step ids to be unique across the whole template', function () {
    $template = workflowValidatorTemplate();
    data_set($template, 'steps.2.steps.0.id', 'outline');

    expect(app(WorkflowTemplateValidator::class)->validate($template))
        ->toContain('steps[2].steps[0].id: “outline” kimliği şablonda birden fazla adımda kullanılmış.');
});

test('it rejects a gallery that does not point at a foreach and its code step', function (array $gallery, string $error) {
    $template = workflowBuiltinTemplate();
    $template['gallery'] = [...$template['gallery'], ...$gallery];

    expect(app(WorkflowTemplateValidator::class)->validate($template))->toBe([$error]);
})->with([
    'non-foreach source' => [['from' => 'score'], 'gallery.from: “score” üst düzeydeki bir foreach adımının kimliği değil.'],
    'browser check as file' => [['file' => 'check'], 'gallery.file: “demos” adımının code_text veya code_agent alt adımlarından birinin kimliği olmalı.'],
    'code step as check' => [['check' => 'build'], 'gallery.check: “demos” adımının browser_check alt adımlarından birinin kimliği olmalı.'],
]);

test('it rejects browser checks whose target is not an earlier code step', function () {
    $template = workflowBuiltinTemplate();
    data_set($template, 'steps.2.steps.1.target', 'check');

    expect(app(WorkflowTemplateValidator::class)->validate($template))
        ->toBe(['steps[2].steps[1].target: “check” bu adımdan önce gelen bir code_text veya code_agent kardeş adımı değil.']);
});

test('it rejects input defaults that break their own constraints', function (array $input, string $error) {
    $template = workflowValidatorTemplate();
    $template['inputs'][0] = $input;

    expect(app(WorkflowTemplateValidator::class)->validate($template))->toBe([$error]);
})->with([
    'integer above max' => [['id' => 'count', 'type' => 'integer', 'label' => 'Adet', 'default' => 9, 'min' => 1, 'max' => 5], 'inputs[0].default: Varsayılan değer en fazla 5 olabilir.'],
    'integer below min' => [['id' => 'count', 'type' => 'integer', 'label' => 'Adet', 'default' => 0, 'min' => 1], 'inputs[0].default: Varsayılan değer en az 1 olmalıdır.'],
    'string default for an integer' => [['id' => 'count', 'type' => 'integer', 'label' => 'Adet', 'default' => '3'], 'inputs[0].default: Varsayılan değer bir tam sayı olmalıdır.'],
    'string longer than maxLength' => [['id' => 'count', 'type' => 'string', 'label' => 'Dil', 'default' => 'Türkçe', 'maxLength' => 3], 'inputs[0].default: Varsayılan değer en fazla 3 karakter olabilir.'],
    'empty default of a required string' => [['id' => 'count', 'type' => 'string', 'label' => 'Dil', 'default' => ''], 'inputs[0].default: Zorunlu bir girdinin varsayılan değeri boş olamaz.'],
    'select value outside the options' => [['id' => 'count', 'type' => 'select', 'label' => 'Mod', 'default' => 'c', 'options' => [['value' => 'a', 'label' => 'A']]], 'inputs[0].default: Varsayılan değer için listeden geçerli bir seçenek seçin.'],
    'missing default of an integer' => [['id' => 'count', 'type' => 'integer', 'label' => 'Adet'], 'inputs[0].default: Zorunlu alan eksik.'],
    'min on a string' => [['id' => 'count', 'type' => 'string', 'label' => 'Dil', 'default' => 'Türkçe', 'min' => 1], 'inputs[0].min: Yalnız integer ve number girdilerinde kullanılabilir.'],
]);

test('it rejects templates that are not objects or exceed 512 KB', function () {
    $validator = app(WorkflowTemplateValidator::class);
    $template = workflowValidatorTemplate();
    $template['constants']['padding'] = str_repeat('a', WorkflowTemplateValidator::MAX_TEMPLATE_BYTES);

    expect($validator->validate('{"schema": "benchforge-workflow/1"}'))->toBe(['şablon: Şablon bir JSON nesnesi olmalı.'])
        ->and($validator->validate([['id' => 'x']]))->toBe(['şablon: Şablon bir JSON nesnesi olmalı.'])
        ->and($validator->validate($template))->toBe(['şablon: Şablon 512 KB sınırını aşıyor.']);
});

/**
 * @return array<string, mixed>
 */
function workflowInputTemplate(): array
{
    return ['inputs' => [
        ['id' => 'count', 'type' => 'integer', 'label' => 'Adet', 'default' => 3, 'min' => 1, 'max' => 5],
        ['id' => 'ratio', 'type' => 'number', 'label' => 'Oran', 'default' => 0.5, 'min' => 0, 'max' => 1],
        ['id' => 'language', 'type' => 'string', 'label' => 'Dil', 'default' => 'Türkçe', 'maxLength' => 10],
        ['id' => 'notes', 'type' => 'text', 'label' => 'Notlar', 'default' => '', 'required' => false],
        ['id' => 'fast', 'type' => 'boolean', 'label' => 'Hızlı', 'default' => false],
        ['id' => 'mode', 'type' => 'select', 'label' => 'Mod', 'default' => 'a', 'options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']]],
    ]];
}

test('it converts run form strings to typed input values', function () {
    $result = app(WorkflowTemplateValidator::class)->validateInputs(workflowInputTemplate(), [
        'count' => ' 004 ',
        'ratio' => '0.25',
        'language' => ' English ',
        'notes' => "satır 1\r\nsatır 2",
        'fast' => 'on',
        'mode' => 'b',
        'unknown' => 'ignored',
    ]);

    expect($result)->toBe([
        'values' => ['count' => 4, 'ratio' => 0.25, 'language' => 'English', 'notes' => "satır 1\nsatır 2", 'fast' => true, 'mode' => 'b'],
        'errors' => [],
    ]);
});

test('it treats empty optional values as empty and a missing checkbox as false', function () {
    $result = app(WorkflowTemplateValidator::class)->validateInputs(workflowInputTemplate(), [
        'count' => 2,
        'ratio' => 1,
        'language' => 'Türkçe',
        'notes' => null,
        'mode' => 'a',
    ]);

    expect($result['errors'])->toBe([])
        ->and($result['values'])->toBe(['count' => 2, 'ratio' => 1, 'language' => 'Türkçe', 'notes' => '', 'fast' => false, 'mode' => 'a']);
});

test('it reports invalid run input values under their input key', function (string $id, mixed $value, string $error) {
    $input = ['count' => '3', 'ratio' => '0.5', 'language' => 'Türkçe', 'notes' => '', 'fast' => '1', 'mode' => 'a'];
    $input[$id] = $value;

    $result = app(WorkflowTemplateValidator::class)->validateInputs(workflowInputTemplate(), $input);

    expect($result['errors'])->toBe(["inputs.{$id}" => $error])
        ->and($result['values'])->not->toHaveKey($id)
        ->and($result['values'])->toHaveCount(5);
})->with([
    'decimal integer' => ['count', '2.5', 'Adet bir tam sayı olmalıdır.'],
    'integer above max' => ['count', '9', 'Adet en fazla 5 olabilir.'],
    'empty required integer' => ['count', '', 'Adet zorunludur.'],
    'missing required string' => ['language', null, 'Dil zorunludur.'],
    'text that is not numeric' => ['ratio', 'yarım', 'Oran bir sayı olmalıdır.'],
    'number below min' => ['ratio', '-0.1', 'Oran en az 0 olmalıdır.'],
    'string over maxLength' => ['language', 'Azerbaycanca', 'Dil en fazla 10 karakter olabilir.'],
    'unknown option' => ['mode', 'c', 'Mod için listeden geçerli bir seçenek seçin.'],
    'checkbox value that is not boolean' => ['fast', 'belki', 'Hızlı evet/hayır değeri olmalıdır.'],
    'array instead of text' => ['notes', ['a'], 'Notlar bir metin olmalıdır.'],
]);
