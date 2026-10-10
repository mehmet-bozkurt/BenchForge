function finiteNumber(value, fallback = 0) {
    const number = Number(value);

    return Number.isFinite(number) ? number : fallback;
}

export function estimateOpenRouterCost(models, inputTokens, outputTokens) {
    const safeInputTokens = Math.max(0, finiteNumber(inputTokens));
    const safeOutputTokens = Math.max(0, finiteNumber(outputTokens));

    const estimate = models.reduce((result, model) => {
        const inputCost = Math.max(0, finiteNumber(model.promptPrice)) * safeInputTokens;
        const outputCost = Math.max(0, finiteNumber(model.completionPrice)) * safeOutputTokens;

        return {
            inputCost: result.inputCost + inputCost,
            outputCost: result.outputCost + outputCost,
            totalCost: result.totalCost + inputCost + outputCost,
        };
    }, { inputCost: 0, outputCost: 0, totalCost: 0 });

    return Object.fromEntries(
        Object.entries(estimate).map(([key, value]) => [key, Math.round(value * 1_000_000_000_000) / 1_000_000_000_000]),
    );
}

export function selectedFixedEndpoints(models, providerSlugs, endpointsByModel) {
    if (models.length === 0) return null;

    const selected = models.map((model) => {
        const slug = providerSlugs.get(model.id);
        const endpoints = endpointsByModel.get(model.id);

        return slug && Array.isArray(endpoints)
            ? endpoints.find((endpoint) => endpoint.slug === slug)
            : null;
    });

    return selected.every(Boolean) ? selected : null;
}

export function pricedFixedEndpoints(models, providerSlugs, endpointsByModel) {
    const endpoints = selectedFixedEndpoints(models, providerSlugs, endpointsByModel);

    if (!endpoints) return null;

    const prices = endpoints.map((endpoint) => {
        const promptPrice = endpoint.promptPrice;
        const completionPrice = endpoint.completionPrice;

        if (promptPrice === null || promptPrice === undefined || promptPrice === ''
            || completionPrice === null || completionPrice === undefined || completionPrice === '') return null;

        const prompt = Number(promptPrice);
        const completion = Number(completionPrice);

        if (!Number.isFinite(prompt) || prompt < 0 || !Number.isFinite(completion) || completion < 0) return null;

        return { promptPrice: prompt, completionPrice: completion };
    });

    return prices.every(Boolean) ? prices : null;
}

export function matchesOpenRouterFilters(model, filters) {
    const query = String(filters.query || '').trim().toLocaleLowerCase('tr-TR');

    if (query !== '' && !model.searchText.includes(query)) return false;
    if (filters.author && model.author !== filters.author) return false;
    if (filters.cost === 'free' && !model.isFree) return false;
    if (filters.cost === 'paid' && model.isFree) return false;
    if (filters.release === 'stable' && model.isPreview) return false;
    if (filters.release === 'preview' && !model.isPreview) return false;

    return true;
}

export function compareOpenRouterModels(left, right, sort) {
    const byName = () => left.name.localeCompare(right.name, 'tr', { sensitivity: 'base' });

    if (sort === 'name') return byName();
    if (sort === 'prompt-price') return left.promptPrice - right.promptPrice || byName();
    if (sort === 'completion-price') return left.completionPrice - right.completionPrice || byName();
    if (sort === 'context') return right.contextLength - left.contextLength || byName();

    return right.releasedAt - left.releasedAt || byName();
}
