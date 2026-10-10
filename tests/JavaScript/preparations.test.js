import assert from 'node:assert/strict';
import test from 'node:test';

import { matchesPreparationModel, preparationSourceProblem } from '../../resources/js/preparations.js';

test('finds catalogue models by name or id without changing selected state', () => {
    const searchable = 'Örnek Model acme/ornek-model';

    assert.equal(matchesPreparationModel('ÖRNEK', searchable), true);
    assert.equal(matchesPreparationModel('acme/ornek', searchable), true);
    assert.equal(matchesPreparationModel('başka', searchable), false);
    assert.equal(matchesPreparationModel(' ', searchable), true);
});

test('requires exactly one specification source with the allowed text or file size', () => {
    const validText = 'x'.repeat(50);

    assert.match(preparationSourceProblem('', null), /Şartname metni yazın/);
    assert.match(preparationSourceProblem(validText, { size: 20 }), /yalnız birini/);
    assert.match(preparationSourceProblem('x'.repeat(49), null), /en az 50/);
    assert.equal(preparationSourceProblem(validText, null), '');
    assert.equal(preparationSourceProblem('', { size: 1_048_576 }), '');
    assert.match(preparationSourceProblem('', { size: 1_048_577 }), /1 MB/);
});
