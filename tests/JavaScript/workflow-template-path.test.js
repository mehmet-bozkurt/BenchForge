import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { findPlaceholders, parsePath, renderTemplate, resolvePath } from '../../bin/workflow/template-path.mjs';

const vectorsPath = join(dirname(fileURLToPath(import.meta.url)), '../Fixtures/workflows/path-vectors.json');
const vectors = JSON.parse(await readFile(vectorsPath, 'utf8'));

test('accepts exactly the shared path vectors that the PHP validator accepts', () => {
    assert.ok(vectors.paths.length > 0);

    for (const { path, valid } of vectors.paths) {
        if (valid) {
            assert.doesNotThrow(() => parsePath(path), `${JSON.stringify(path)} must be valid`);
        } else {
            assert.throws(() => parsePath(path), Error, `${JSON.stringify(path)} must be invalid`);
        }
    }
});

test('finds the placeholders of the shared template vectors', () => {
    assert.ok(vectors.templates.length > 0);

    for (const { text, valid, paths } of vectors.templates) {
        const result = findPlaceholders(text);

        assert.equal(result.errors.length === 0, valid, `${JSON.stringify(text)}: ${result.errors.join(' ')}`);
        assert.deepEqual(result.paths, paths, JSON.stringify(text));
        for (const error of result.errors) assert.equal(typeof error, 'string');
    }
});

test('parses roots, fields, indexes and flattening into segments', () => {
    assert.deepEqual(parsePath('steps.ideas.items[*].generate.output.ideas[12].title'), {
        root: 'steps',
        segments: [
            { type: 'field', name: 'ideas' },
            { type: 'field', name: 'items' },
            { type: 'all' },
            { type: 'field', name: 'generate' },
            { type: 'field', name: 'output' },
            { type: 'field', name: 'ideas' },
            { type: 'index', index: 12 },
            { type: 'field', name: 'title' },
        ],
    });
    assert.deepEqual(parsePath('item'), { root: 'item', segments: [] });
    assert.deepEqual(parsePath('loop.previous[0]'), { root: 'loop', segments: [{ type: 'field', name: 'previous' }, { type: 'index', index: 0 }] });
    assert.throws(() => parsePath('items[0]'), /must start with one of: inputs, constants, steps, item, loop/);
    assert.throws(() => parsePath('inputs.x[01]'), /index without leading zeros/);
    assert.throws(() => parsePath(42), /must not be empty/);
});

test('reports every malformed brace use and keeps single braces as text', () => {
    assert.deepEqual(findPlaceholders('{"a": 1} and {{inputs.x}} {{ loop.index }}'), { paths: ['inputs.x', 'loop.index'], errors: [] });
    assert.equal(findPlaceholders('a }} b }} c').errors.length, 2);
    assert.match(findPlaceholders('{{ inputs.x }} then {{ steps.a').errors[0], /Unclosed "\{\{"/);
    assert.match(findPlaceholders('{{\t}}').errors[0], /Empty placeholder/);
    assert.match(findPlaceholders('{{ item.title() }}').errors[0], /unexpected character/);
    assert.deepEqual(findPlaceholders(null), { paths: [], errors: ['A template must be a string.'] });
});

test('resolves own properties only and never reaches prototypes', () => {
    const inherited = Object.create({ secret: 'inherited' });
    inherited.own = 'own';
    const context = {
        inputs: { name: 'Ada' },
        constants: JSON.parse('{"__proto__": {"x": 1}, "list": [1, 2], "plain": {}}'),
        steps: { inherited },
    };

    assert.deepEqual(resolvePath(context, parsePath('steps.inherited.own')), { found: true, value: 'own', flattened: false });
    assert.equal(resolvePath(context, parsePath('steps.inherited.secret')).found, false);
    assert.equal(resolvePath(context, parsePath('constants.__proto__.x')).found, false);
    assert.equal(resolvePath(context, parsePath('constants.plain.constructor')).found, false);
    assert.equal(resolvePath(context, parsePath('constants.plain.toString')).found, false);
    assert.equal(resolvePath(context, parsePath('constants.plain.hasOwnProperty')).found, false);
    assert.equal(resolvePath(context, parsePath('constants.list.length')).found, false);
    assert.equal(resolvePath(context, parsePath('inputs.name.length')).found, false);
    assert.equal(resolvePath(context, parsePath('item')).found, false);
    assert.equal(resolvePath({ inputs: Object.create({ name: 'x' }) }, parsePath('inputs.name')).found, false);
    assert.equal(resolvePath(null, parsePath('inputs')).found, false);
    assert.equal(resolvePath(context, 'inputs.name').value, 'Ada');
});

test('flattens with [*], drops missing elements and concatenates only for a later [*]', () => {
    const context = {
        constants: {
            matrix: [[1, 2], [3]],
            categories: [
                { label: 'A', ideas: [{ title: 'a1' }, { title: 'a2' }] },
                { label: 'B', ideas: [] },
                { ideas: [{ title: 'c1' }, { name: 'no title' }] },
                'not an object',
            ],
        },
    };

    assert.deepEqual(resolvePath(context, parsePath('constants.categories[*].label')), { found: true, value: ['A', 'B'], flattened: true });
    assert.deepEqual(resolvePath(context, parsePath('constants.categories[*].ideas[*].title')).value, ['a1', 'a2', 'c1']);
    assert.deepEqual(resolvePath(context, parsePath('constants.categories[*].ideas')).value, [
        [{ title: 'a1' }, { title: 'a2' }], [], [{ title: 'c1' }, { name: 'no title' }],
    ]);
    assert.deepEqual(resolvePath(context, parsePath('constants.matrix[*]')).value, [[1, 2], [3]]);
    assert.deepEqual(resolvePath(context, parsePath('constants.matrix[*][*]')).value, [1, 2, 3]);
    assert.deepEqual(resolvePath(context, parsePath('constants.categories[0].ideas[1]')).value, { title: 'a2' });
    assert.equal(resolvePath(context, parsePath('constants.categories[9]')).found, false);
    assert.deepEqual(resolvePath(context, parsePath('constants.categories[0].label[*]')), { found: false, value: undefined, flattened: true });
    assert.equal(resolvePath(context, parsePath('constants.categories.label')).found, false);
});

test('renders values by the substitution rules without evaluating anything', () => {
    const context = {
        inputs: { count: 3, ratio: 1.5, enabled: false, brief: 'Make it {{inputs.count}} times', empty: '', none: null },
        constants: { tags: ['puzzle', 'arcade'], scores: [7, 8.5], mixed: ['a', { b: 1 }], none: [], idea: { title: 'Kule', tags: ['x'] } },
        steps: {},
        item: { title: 'Gölge Avcısı', reasons: ['hızlı', 'basit'] },
        loop: {
            index: 1,
            number: 2,
            total: 4,
            previous: [{ generate: { output: { ideas: [{ title: 'Bir' }, { title: 'İki' }] } } }],
        },
    };

    assert.equal(renderTemplate('{{inputs.count}}/{{ inputs.ratio }}/{{inputs.enabled}}', context), '3/1.5/false');
    assert.equal(renderTemplate('Brief: {{inputs.brief}}', context), 'Brief: Make it {{inputs.count}} times');
    assert.equal(renderTemplate('[{{inputs.empty}}][{{inputs.none}}][{{inputs.missing}}][{{steps.x.output}}]', context), '[][][][]');
    assert.equal(renderTemplate('{{constants.tags[*]}}', context), '- puzzle\n- arcade');
    assert.equal(renderTemplate('{{constants.scores[*]}}', context), '- 7\n- 8.5');
    assert.equal(renderTemplate('[{{constants.none[*]}}]', context), '[]');
    assert.equal(renderTemplate('{{constants.mixed[*]}}', context), JSON.stringify(['a', { b: 1 }], null, 2));
    assert.equal(renderTemplate('{{constants.tags}}', context), JSON.stringify(['puzzle', 'arcade'], null, 2));
    assert.equal(renderTemplate('{{constants.idea}}', context), JSON.stringify({ title: 'Kule', tags: ['x'] }, null, 2));
    assert.equal(renderTemplate('{{item.title}} ({{loop.number}}/{{loop.total}})', context), 'Gölge Avcısı (2/4)');
    assert.equal(renderTemplate('{{item.reasons[*]}}', context), '- hızlı\n- basit');
    assert.equal(renderTemplate('Do not repeat:\n{{\n  loop.previous[*].generate.output.ideas[*].title\n}}', context), 'Do not repeat:\n- Bir\n- İki');
    assert.equal(renderTemplate('No placeholders { here }', context), 'No placeholders { here }');
    assert.equal(renderTemplate('{{inputs.count}}', {}), '');
});

test('refuses to render a template with an invalid placeholder', () => {
    assert.throws(() => renderTemplate('Hello {{ inputs.x + 1 }}', { inputs: { x: 1 } }), /Invalid placeholder/);
    assert.throws(() => renderTemplate('Hello }}', {}), /Unexpected "\}\}"/);
    assert.throws(() => renderTemplate('Hello {{inputs.x', {}), /Unclosed/);
});
