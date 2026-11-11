import assert from 'node:assert/strict';
import test from 'node:test';
import { buildPrompt, convertSpec, parseArguments } from '../../engine/tools/import-exercism.mjs';
import { SUPPORTED_MATCHERS, expect, looselyEqual } from '../../engine/tools/algorithm-jest-shim.mjs';

test('converts an Exercism spec: skipped tests are enabled and the solution import gets an extension', () => {
    const { source, unsupported } = convertSpec("import { twoFer } from './two-fer';\nxtest('x', () => { expect(twoFer()).toBe('y'); });\nxdescribe('g', () => {});\n", 'two-fer.js');

    assert.deepEqual(unsupported, []);
    assert.match(source, /^import \{[^}]*\bexpect\b[^}]*\} from '\.\/jest-shim\.mjs';\n/u);
    assert.match(source, /from '\.\/two-fer\.js'/u);
    assert.match(source, /\ntest\('x'/u);
    assert.match(source, /\ndescribe\('g'/u);
    assert.doesNotMatch(source.split('\n').slice(1).join('\n'), /xtest|xdescribe/u);
});

test('drops the @jest/globals import and ignores the spec own methods named like matchers', () => {
    const { source, unsupported } = convertSpec("import { expect, test } from '@jest/globals';\nimport { List } from './list';\ntest('x', () => { expect(new List().toArray()).toEqual([]); });\n", 'list.js');

    assert.deepEqual(unsupported, []);
    assert.doesNotMatch(source, /@jest\/globals/u);
    assert.match(source, /from '\.\/list\.js'/u);
    assert.match(source, /toArray\(\)/u);
});

test('rejects specs that need more than the shim offers', () => {
    const reasons = (source) => convertSpec(`import { f } from './f';\n${source}`, 'f.js').unsupported.join(' | ');

    assert.match(reasons("test('a', () => { expect(f()).toMatchSnapshot(); });"), /toMatchSnapshot/u);
    assert.match(reasons("test('a', () => { const m = jest.fn(); });"), /jest\./u);
    assert.match(reasons("test.each([[1]])('a', () => {});"), /each/u);
    assert.match(reasons("import lodash from 'lodash';"), /lodash/u);
    assert.match(reasons("import { g } from './helpers';"), /helpers/u);
    assert.match(reasons("const x = require('fs');"), /require/u);
    assert.equal(reasons("test('a', () => { expect(f()).toEqual(1); });"), '');
});

test('every matcher the converter accepts exists in the shim', () => {
    const matchers = expect(1);

    for (const name of SUPPORTED_MATCHERS) {
        assert.equal(typeof matchers[name], 'function', name);
    }
});

test('builds a prompt that names the solution and test files', () => {
    const prompt = buildPrompt({ slug: 'two-fer', solutionFile: 'two-fer.js', testFile: 'two-fer.spec.js', description: 'Say hello.' });

    assert.match(prompt, /`two-fer\.js`/u);
    assert.match(prompt, /node --test two-fer\.spec\.js/u);
    assert.match(prompt, /Say hello\./u);
});

test('the shim follows Jest equality and throw semantics', () => {
    assert.equal(looselyEqual({ a: 1, b: undefined }, { a: 1 }), true);
    assert.equal(looselyEqual([1, [2]], [1, [2]]), true);
    assert.equal(looselyEqual(1, '1'), false);
    assert.equal(looselyEqual(0, -0), true);
    assert.equal(looselyEqual(new Map([[1, { a: 1 }]]), new Map([[1, { a: 1 }]])), true);
    assert.equal(looselyEqual(new Set([1, 2]), new Set([2, 1])), true);

    expect(() => {
        throw new Error('Boom now');
    }).toThrow('Boom');
    expect(() => {
        throw new Error('Boom');
    }).toThrow(new Error('Boom'));
    expect(0.1 + 0.2).toBeCloseTo(0.3);
    expect([1, 2, 3]).toContain(2);
    expect('abc').not.toBe('abd');
    assert.throws(() => expect(1).toBe(2));
    assert.throws(() => expect(() => {}).toThrow());
    assert.throws(() => expect(() => {
        throw new Error('other');
    }).toThrow('Boom'));
    assert.throws(() => expect({ a: 1 }).toStrictEqual({ a: 1, b: undefined }));
});

test('parses importer options and rejects unknown ones', () => {
    assert.deepEqual(parseArguments(['--root', '/tmp/x', '--only', 'a,b', '--limit', '3']).only, ['a', 'b']);
    assert.equal(parseArguments(['--root', '/tmp/x']).root, '/tmp/x');
    assert.throws(() => parseArguments(['--nope', 'x']), /Bilinmeyen/u);
    assert.throws(() => parseArguments(['--limit', '0']), /pozitif/u);
    assert.throws(() => parseArguments(['--root']), /deger/u);
});
