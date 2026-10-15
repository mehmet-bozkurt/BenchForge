import assert from 'node:assert/strict';
import test from 'node:test';

const originalDocument = globalThis.document;
globalThis.document = { addEventListener: () => {} };
const { resolveTheme } = await import('../../resources/js/app.js');
globalThis.document = originalDocument;

test('an explicit theme choice wins over the system setting', () => {
    assert.equal(resolveTheme('light', true), 'light');
    assert.equal(resolveTheme('dark', false), 'dark');
});

test('the system choice follows the operating system', () => {
    assert.equal(resolveTheme('system', true), 'dark');
    assert.equal(resolveTheme('system', false), 'light');
    assert.equal(resolveTheme(undefined, true), 'dark');
});
