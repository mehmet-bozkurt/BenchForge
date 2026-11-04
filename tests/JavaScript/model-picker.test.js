import assert from 'node:assert/strict';
import test from 'node:test';

import {
    endpointOptionLabel,
    enforceSelectionLimit,
    foldSearchText,
    groupOptionState,
    initializeModelPickers,
    isEndpointPayload,
    modelPickerLimit,
    normalizeModelPickerMode,
    parallelOptionState,
    pickerModelData,
    pickerSelectionProblem,
    visibleCatalogModels,
} from '../../resources/js/model-picker.js';

function catalogModel(overrides) {
    return pickerModelData({
        modelId: 'acme/model',
        modelName: 'Model',
        modelSearchText: 'model acme/model acme',
        modelAuthor: 'acme',
        modelFree: 'false',
        modelPreview: 'false',
        modelContext: '0',
        modelPromptPrice: '0',
        modelCompletionPrice: '0',
        modelReleasedAt: '0',
        ...overrides,
    });
}

test('reads catalog cards into model objects with safe numbers', () => {
    assert.deepEqual(pickerModelData({
        modelId: 'acme/nova',
        modelName: 'Nova',
        modelSearchText: 'nova acme/nova acme',
        modelAuthor: 'acme',
        modelFree: 'true',
        modelPreview: 'false',
        modelContext: '131072',
        modelPromptPrice: '1.5E-7',
        modelCompletionPrice: 'not-a-number',
        modelReleasedAt: '1760000000',
    }), {
        id: 'acme/nova',
        name: 'Nova',
        searchText: 'nova acme/nova acme',
        author: 'acme',
        isFree: true,
        isPreview: false,
        contextLength: 131072,
        promptPrice: 0.00000015,
        completionPrice: 0,
        releasedAt: 1760000000,
    });
    assert.equal(pickerModelData().id, '');
});

test('finds models with a capital I typed on any keyboard layout', () => {
    const models = [
        catalogModel({ modelId: 'acme/iris', modelName: 'Iris', modelSearchText: 'iris acme/iris acme' }),
        catalogModel({ modelId: 'acme/other', modelName: 'Other', modelSearchText: 'other acme/other acme' }),
    ];

    assert.equal(foldSearchText('İZMİR Iris'), 'izmir iris');
    assert.deepEqual(visibleCatalogModels(models, { query: 'IRIS' }).map((model) => model.id), ['acme/iris']);
    assert.deepEqual(visibleCatalogModels(models, { query: 'ırıs' }).map((model) => model.id), ['acme/iris']);
    assert.deepEqual(visibleCatalogModels(models, { query: '  ' }).map((model) => model.id).sort(), ['acme/iris', 'acme/other']);
});

test('filters by author, cost and release and returns the given objects in the chosen order', () => {
    const cheap = { ...catalogModel({ modelId: 'acme/cheap', modelName: 'Cheap', modelFree: 'true', modelPromptPrice: '0' }), element: 'cheap' };
    const preview = { ...catalogModel({ modelId: 'beta/preview', modelName: 'Preview', modelAuthor: 'beta', modelPreview: 'true', modelPromptPrice: '0.000002' }), element: 'preview' };
    const paid = { ...catalogModel({ modelId: 'acme/paid', modelName: 'Paid', modelPromptPrice: '0.000001', modelReleasedAt: '20' }), element: 'paid' };
    const models = [cheap, preview, paid];

    assert.deepEqual(visibleCatalogModels(models, { author: 'acme' }, 'name').map((model) => model.element), ['cheap', 'paid']);
    assert.deepEqual(visibleCatalogModels(models, { cost: 'free' }).map((model) => model.element), ['cheap']);
    assert.deepEqual(visibleCatalogModels(models, { release: 'preview' }).map((model) => model.element), ['preview']);
    assert.deepEqual(visibleCatalogModels(models, {}, 'prompt-price').map((model) => model.element), ['cheap', 'paid', 'preview']);
    assert.equal(visibleCatalogModels(models, {}, 'newest')[0], paid);
});

test('keeps only the first models up to the limit', () => {
    assert.deepEqual(enforceSelectionLimit([true, false, true, true, true, true, true], 5), [true, false, true, true, true, true, false]);
    assert.deepEqual(enforceSelectionLimit([false, false], 5), [false, false]);
    assert.equal(modelPickerLimit('5'), 5);
    assert.equal(modelPickerLimit(''), 5);
    assert.equal(modelPickerLimit('0', 3), 3);
    assert.equal(modelPickerLimit('abc', 16), 16);
});

test('allows only saved groups that fit the limit and use workflow providers', () => {
    assert.deepEqual(groupOptionState({ modelCount: '2', providers: 'openrouter,opencode-go' }, 5), { selectable: true, modelCount: 2, message: '' });
    assert.deepEqual(groupOptionState({ modelCount: '6', providers: 'openrouter' }, 5), {
        selectable: false,
        modelCount: 6,
        message: 'Bu grupta 6 model var; iş akışında en fazla 5 model seçilebilir.',
    });
    assert.deepEqual(groupOptionState({ modelCount: '1', providers: 'openrouter, anthropic' }, 5), {
        selectable: false,
        modelCount: 1,
        message: 'Bu gruptaki anthropic sağlayıcısı iş akışlarında desteklenmiyor (yalnız OpenRouter ve OpenCode Go).',
    });
    assert.equal(groupOptionState({}, 5).selectable, false);
    assert.equal(groupOptionState({ modelCount: 'many', providers: 'openrouter' }, 5).message, 'Model grubu seçin.');
});

test('caps the models running at the same time at the selected model count', () => {
    assert.deepEqual(parallelOptionState(1, '2'), { cap: 1, value: 1 });
    assert.deepEqual(parallelOptionState(3, '2'), { cap: 3, value: 2 });
    assert.deepEqual(parallelOptionState(0, '4'), { cap: 5, value: 4 });
    assert.deepEqual(parallelOptionState(9, '5'), { cap: 5, value: 5 });
    assert.deepEqual(parallelOptionState(2, 'x'), { cap: 2, value: 1 });
});

test('names the missing part of a model selection in Turkish', () => {
    const group = groupOptionState({ modelCount: '6', providers: 'openrouter' }, 5);

    assert.equal(normalizeModelPickerMode('opencode_go'), 'opencode_go');
    assert.equal(normalizeModelPickerMode('openrouter'), 'group');
    assert.equal(pickerSelectionProblem({ mode: 'group', group }), group.message);
    assert.equal(pickerSelectionProblem({ mode: 'group', group: null }), 'Model grubu seçin.');
    assert.equal(pickerSelectionProblem({ mode: 'group', group: { selectable: true } }), '');
    assert.equal(pickerSelectionProblem({ mode: 'custom', selectedCount: 0 }), 'OpenRouter kataloğundan en az bir model seçin.');
    assert.equal(pickerSelectionProblem({ mode: 'opencode_go', selectedCount: 0 }), 'OpenCode Go kataloğundan en az bir model seçin.');
    assert.equal(pickerSelectionProblem({ mode: 'custom', selectedCount: 6, limit: 5 }), 'Bir iş akışında en fazla 5 model seçilebilir.');
    assert.equal(pickerSelectionProblem({ mode: 'custom', selectedCount: 2, fixedRouting: true, fixedReady: false }), 'Seçilen her model için bir sabit sağlayıcı seçin.');
    assert.equal(pickerSelectionProblem({ mode: 'opencode_go', selectedCount: 2, fixedRouting: true, fixedReady: false }), '');
    assert.equal(pickerSelectionProblem({ mode: 'custom', selectedCount: 2, fixedRouting: true, fixedReady: true }), '');
});

test('accepts only endpoint lists for the requested model and labels their prices', () => {
    const payload = { modelId: 'acme/nova', endpoints: [{ slug: 'fast', name: 'Fast' }] };

    assert.equal(isEndpointPayload(payload, 'acme/nova'), true);
    assert.equal(isEndpointPayload(payload, 'acme/other'), false);
    assert.equal(isEndpointPayload({ modelId: 'acme/nova', endpoints: [{ slug: 'fast' }] }, 'acme/nova'), false);
    assert.equal(isEndpointPayload(null, 'acme/nova'), false);
    assert.equal(
        endpointOptionLabel({ slug: 'fast', name: 'Fast Cloud', quantization: 'fp8', promptPrice: '0.000001', completionPrice: '0.000002' }),
        'Fast Cloud · fast · fp8 · Girdi $1.00/M, çıktı $2.00/M',
    );
    assert.equal(endpointOptionLabel({ slug: 'plain', name: 'plain', promptPrice: null, completionPrice: '0' }), 'plain · fiyat bilgisi yok');
});

test('does nothing without a document root and touches no DOM on import', () => {
    assert.doesNotThrow(() => initializeModelPickers(null));
    assert.doesNotThrow(() => initializeModelPickers({}));
    assert.equal(typeof globalThis.document, 'undefined');
});
