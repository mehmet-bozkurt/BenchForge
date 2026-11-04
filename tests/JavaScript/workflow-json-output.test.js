import assert from 'node:assert/strict';
import test from 'node:test';
import {
    clipText,
    extractJsonText,
    jsonOutputRules,
    jsonRetrySuffix,
    parseModelJson,
    validateJsonSchema,
} from '../../bin/workflow/json-output.mjs';

const ideasSchema = {
    type: 'object',
    required: ['ideas'],
    additionalProperties: false,
    properties: {
        ideas: {
            type: 'array',
            minItems: 1,
            maxItems: 3,
            description: 'Ignored by validation.',
            items: {
                type: 'object',
                required: ['title', 'score'],
                properties: {
                    title: { type: 'string', minLength: 2, maxLength: 5 },
                    score: { type: 'integer', minimum: 1, maximum: 10 },
                    genre: { type: 'string', enum: ['puzzle', 'arcade'] },
                    tags: { type: 'array', items: { type: 'string' } },
                },
            },
        },
        note: { type: 'null' },
        ratio: { type: 'number' },
        done: { type: 'boolean' },
    },
};

test('takes the first json block, else the first fenced block, else the trimmed text', () => {
    assert.equal(extractJsonText('Plan:\n```text\nnot this\n```\n```JSON\n{"a": 1}\n```\n```json\n{"b": 2}\n```'), '{"a": 1}');
    assert.equal(extractJsonText('Intro\n```\n[1, 2]\n```\n```js\nconst x = 1;\n```'), '[1, 2]');
    assert.equal(extractJsonText('```json {"inline": true}```'), '{"inline": true}');
    assert.equal(extractJsonText('```json\n{"cut": [1, 2'), '{"cut": [1, 2');
    assert.equal(extractJsonText('  \n {"plain": true} \n'), '{"plain": true}');
    assert.equal(extractJsonText(undefined), '');
});

test('parses plain JSON without marking it repaired', () => {
    assert.deepEqual(parseModelJson('```json\n{"ideas": [{"title": "Kule"}]}\n```'), {
        ok: true, value: { ideas: [{ title: 'Kule' }] }, repaired: false, error: null,
    });
    assert.deepEqual(parseModelJson('[1, 2, 3]'), { ok: true, value: [1, 2, 3], repaired: false, error: null });
});

test('repairs broken JSON from models and finds JSON wrapped in prose', () => {
    assert.deepEqual(parseModelJson('```json\n{"a": [1, 2,],}\n```'), { ok: true, value: { a: [1, 2] }, repaired: true, error: null });
    assert.deepEqual(parseModelJson('{"title": "He said "hi" today"}').value, { title: 'He said "hi" today' });
    assert.deepEqual(parseModelJson('```json\n{"ideas": [{"title": "Kule"').value, { ideas: [{ title: 'Kule' }] });
    assert.deepEqual(parseModelJson("{'single': 'quotes'}").value, { single: 'quotes' });
    assert.deepEqual(parseModelJson('Sure! Here it is:\n{"a": 1}\nHope this helps.'), { ok: true, value: { a: 1 }, repaired: true, error: null });
    assert.deepEqual(parseModelJson('{"a": 1}\nDone.').value, { a: 1 });
});

test('reports answers that are not JSON at all', () => {
    assert.deepEqual(parseModelJson('```json\n```'), { ok: false, value: null, repaired: false, error: 'The answer contains no JSON.' });
    assert.deepEqual(parseModelJson('   '), { ok: false, value: null, repaired: false, error: 'The answer contains no JSON.' });

    const broken = parseModelJson('}{');
    assert.equal(broken.ok, false);
    assert.equal(broken.value, null);
    assert.match(broken.error, /^The answer is not valid JSON: /);
});

test('accepts values that match the schema subset', () => {
    assert.deepEqual(validateJsonSchema({
        ideas: [{ title: 'Kule', score: 7, genre: 'puzzle', tags: ['a'] }, { title: 'Işık', score: 1.0 }],
        note: null,
        ratio: 0.5,
        done: true,
    }, ideasSchema), []);
    assert.deepEqual(validateJsonSchema({ anything: [1, 'x'] }, { type: 'object' }), []);
    assert.deepEqual(validateJsonSchema('free', {}), []);
});

test('reports schema violations with JSON paths', () => {
    assert.deepEqual(validateJsonSchema({ ideas: [] }, ideasSchema), ['$.ideas: must have at least 1 items']);
    assert.deepEqual(validateJsonSchema({}, ideasSchema), ['$.ideas: required']);
    assert.deepEqual(validateJsonSchema([], ideasSchema), ['$: must be object']);
    assert.deepEqual(validateJsonSchema({
        ideas: [
            { title: 'Kule', score: 7 },
            { title: 'K', score: 0, genre: 'racing', tags: ['ok', 3] },
            { score: 4.5 },
            { title: 'Fazla uzun', score: 11 },
        ],
        note: 'x',
        ratio: '1',
        done: 'yes',
        extra: 1,
    }, ideasSchema), [
        '$.ideas: must have at most 3 items',
        '$.ideas[1].title: must have at least 2 characters',
        '$.ideas[1].score: must be >= 1',
        '$.ideas[1].genre: must be one of ["puzzle","arcade"]',
        '$.ideas[1].tags[1]: must be string',
        '$.ideas[2].title: required',
        '$.ideas[2].score: must be integer',
        '$.ideas[3].title: must have at most 5 characters',
        '$.ideas[3].score: must be <= 10',
        '$.note: must be null',
        '$.ratio: must be number',
        '$.done: must be boolean',
        '$.extra: is not allowed',
    ]);
});

test('counts characters, quotes unusual keys and caps the number of messages', () => {
    assert.deepEqual(validateJsonSchema('😀😀', { type: 'string', maxLength: 2 }), []);
    assert.deepEqual(validateJsonSchema({ 'a b': 1 }, { required: ['a b', 'c-d'], properties: { 'a b': { type: 'string' } } }), [
        '$["c-d"]: required',
        '$["a b"]: must be string',
    ]);
    assert.equal(validateJsonSchema(Array.from({ length: 80 }, () => 'x'), { items: { type: 'integer' } }).length, 50);
});

test('builds the output rules block with the pretty schema', () => {
    const schema = { type: 'object', required: ['title'], properties: { title: { type: 'string' } } };

    assert.equal(jsonOutputRules(schema), [
        'OUTPUT FORMAT (mandatory):',
        '- Reply with ONE JSON value inside a single ```json code block and nothing else.',
        '- The JSON must match this JSON Schema:',
        '{',
        '  "type": "object",',
        '  "required": [',
        '    "title"',
        '  ],',
        '  "properties": {',
        '    "title": {',
        '      "type": "string"',
        '    }',
        '  }',
        '}',
        '- Inside JSON string values never use double quotes ("). Use single quotes (\') instead.',
    ].join('\n'));
});

test('builds the retry suffix with the clipped problem and previous answer', () => {
    assert.equal(
        jsonRetrySuffix('$.ideas: required', '{"idea": 1}'),
        '\n\nYOUR PREVIOUS ANSWER WAS REJECTED.\nProblem: $.ideas: required\nYour previous answer (truncated):\n{"idea": 1}\nReturn the corrected JSON now.',
    );
    assert.match(jsonRetrySuffix(['$.a: required', '$.b: must be string'], ''), /Problem: \$\.a: required\n\$\.b: must be string\nYour previous answer \(truncated\):\n\nReturn/);

    const suffix = jsonRetrySuffix('p'.repeat(2500), 'r'.repeat(5000));
    assert.ok(suffix.includes(`Problem: ${'p'.repeat(2000)}\n`));
    assert.ok(!suffix.includes('p'.repeat(2001)));
    assert.ok(suffix.includes(`(truncated):\n${'r'.repeat(3000)}\nReturn the corrected JSON now.`));
    assert.ok(!suffix.includes('r'.repeat(3001)));
});

test('clips text without splitting a surrogate pair', () => {
    assert.equal(clipText('ab😀', 3), 'ab');
    assert.equal(clipText('ab😀', 4), 'ab😀');
    assert.equal(clipText('abc', 10), 'abc');
});
