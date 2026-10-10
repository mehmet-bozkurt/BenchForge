import assert from 'node:assert/strict';
import test from 'node:test';

import {
    compareOpenRouterModels,
    estimateOpenRouterCost,
    matchesOpenRouterFilters,
    pricedFixedEndpoints,
    selectedFixedEndpoints,
} from '../../resources/js/openrouter-catalog.js';

test('estimates the combined input and output cost for selected models', () => {
    const estimate = estimateOpenRouterCost([
        { promptPrice: 0.000001, completionPrice: 0.000004 },
        { promptPrice: 0.000002, completionPrice: 0.000006 },
    ], 100_000, 25_000);

    assert.deepEqual(estimate, {
        inputCost: 0.3,
        outputCost: 0.25,
        totalCost: 0.55,
    });
});

test('filters models by query provider cost and release status', () => {
    const model = {
        searchText: 'nova agent acme/nova acme',
        author: 'acme',
        isFree: true,
        isPreview: false,
    };

    assert.equal(matchesOpenRouterFilters(model, {
        query: 'NOVA',
        author: 'acme',
        cost: 'free',
        release: 'stable',
    }), true);
    assert.equal(matchesOpenRouterFilters(model, {
        query: '',
        author: 'other',
        cost: 'all',
        release: 'all',
    }), false);
});

test('sorts models by price context and release date', () => {
    const older = { name: 'Older', promptPrice: 0.1, completionPrice: 0.5, contextLength: 200_000, releasedAt: 10 };
    const newer = { name: 'Newer', promptPrice: 0.2, completionPrice: 0.4, contextLength: 100_000, releasedAt: 20 };

    assert.ok(compareOpenRouterModels(older, newer, 'newest') > 0);
    assert.ok(compareOpenRouterModels(older, newer, 'prompt-price') < 0);
    assert.ok(compareOpenRouterModels(older, newer, 'context') < 0);
});

test('uses exact fixed endpoints and their token prices for the estimate', () => {
    const models = [{ id: 'acme/alpha' }, { id: 'acme/beta' }];
    const selections = new Map([['acme/alpha', 'provider/a'], ['acme/beta', 'provider/b']]);
    const endpoints = new Map([
        ['acme/alpha', [{ slug: 'provider/a', name: 'Provider A', promptPrice: '0.000001', completionPrice: '0.000004' }]],
        ['acme/beta', [{ slug: 'provider/b', name: 'Provider B', promptPrice: '0', completionPrice: '0.000006' }]],
    ]);

    assert.deepEqual(selectedFixedEndpoints(models, selections, endpoints).map((endpoint) => endpoint.slug), [
        'provider/a', 'provider/b',
    ]);
    assert.deepEqual(estimateOpenRouterCost(pricedFixedEndpoints(models, selections, endpoints), 100_000, 25_000), {
        inputCost: 0.1,
        outputCost: 0.25,
        totalCost: 0.35,
    });
});

test('a missing or retired fixed endpoint is not treated as a valid selection', () => {
    const models = [{ id: 'acme/alpha' }];
    const endpoints = new Map([['acme/alpha', [{ slug: 'provider/new', name: 'New' }]]]);

    assert.equal(selectedFixedEndpoints(models, new Map(), endpoints), null);
    assert.equal(selectedFixedEndpoints(models, new Map([['acme/alpha', 'provider/old']]), endpoints), null);
    assert.equal(selectedFixedEndpoints([], new Map(), endpoints), null);
});

test('an unknown fixed endpoint price does not become a zero-dollar estimate', () => {
    const models = [{ id: 'acme/alpha' }];
    const selections = new Map([['acme/alpha', 'provider/a']]);
    const endpoints = new Map([['acme/alpha', [{
        slug: 'provider/a', name: 'Provider A', promptPrice: null, completionPrice: '0',
    }]]]);

    assert.ok(selectedFixedEndpoints(models, selections, endpoints));
    assert.equal(pricedFixedEndpoints(models, selections, endpoints), null);
});
