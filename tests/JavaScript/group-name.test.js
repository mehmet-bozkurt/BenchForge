import assert from 'node:assert/strict';
import test from 'node:test';

const originalDocument = globalThis.document;
globalThis.document = { addEventListener: () => {} };
const { groupFileName } = await import('../../resources/js/app.js');
globalThis.document = originalDocument;

test('group names are turned into the same file names the server uses', () => {
    assert.equal(groupFileName('Kurtuluş Savaşı'), 'kurtulus-savasi');
    assert.equal(groupFileName('İSTANBUL Çığ ÖĞÜŞ'), 'istanbul-cig-ogus');
    assert.equal(groupFileName('  10 model / Go  '), '10-model-go');
    assert.equal(groupFileName('!!!'), '');
});
