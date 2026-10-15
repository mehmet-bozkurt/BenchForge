import assert from 'node:assert/strict';
import test from 'node:test';

const originalDocument = globalThis.document;
globalThis.document = { addEventListener: () => {} };
const { sortResultRows } = await import('../../resources/js/app.js');
globalThis.document = originalDocument;

const row = (name, index, duration, cost) => ({ name, dataset: { sortIndex: String(index), sortDuration: String(duration), sortCost: cost } });
const rows = [
    row('a', 0, 300, '0.12'),
    row('b', 1, 60, ''),
    row('c', 2, 900, '0.02'),
    row('d', 3, 60, '0.5'),
];
const names = (sorted) => sorted.map((item) => item.name);

test('result rows sort by duration in both directions, keeping run order for ties', () => {
    assert.deepEqual(names(sortResultRows(rows, 'duration', 'ascending')), ['b', 'd', 'a', 'c']);
    assert.deepEqual(names(sortResultRows(rows, 'duration', 'descending')), ['c', 'a', 'b', 'd']);
});

test('result rows sort by cost and always put rows without a cost record last', () => {
    assert.deepEqual(names(sortResultRows(rows, 'cost', 'ascending')), ['c', 'a', 'd', 'b']);
    assert.deepEqual(names(sortResultRows(rows, 'cost', 'descending')), ['d', 'a', 'c', 'b']);
});

test('clearing the sort restores the original run order', () => {
    const shuffled = [rows[2], rows[0], rows[3], rows[1]];
    assert.deepEqual(names(sortResultRows(shuffled, 'cost', 'none')), ['a', 'b', 'c', 'd']);
    assert.deepEqual(names(sortResultRows(shuffled, null, 'ascending')), ['a', 'b', 'c', 'd']);
});
