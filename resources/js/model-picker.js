import { compareOpenRouterModels, matchesOpenRouterFilters, selectedFixedEndpoints } from './openrouter-catalog.js';

export const MODEL_PICKER_MODES = ['group', 'custom', 'opencode_go'];

export const WORKFLOW_PROVIDERS = ['openrouter', 'opencode-go'];

const PROVIDER_MODES = { openrouter: 'custom', 'opencode-go': 'opencode_go' };

const ENDPOINT_CACHE_MS = 300_000;

function finiteNumber(value, fallback = 0) {
    const number = Number(value);

    return Number.isFinite(number) ? number : fallback;
}

function formatUsd(value) {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
        maximumFractionDigits: value > 0 && value < 0.01 ? 6 : 2,
    }).format(value);
}

export function normalizeModelPickerMode(value) {
    return MODEL_PICKER_MODES.includes(value) ? value : 'group';
}

export function modelPickerLimit(value, fallback = 5) {
    const limit = Number.parseInt(String(value ?? ''), 10);

    return Number.isInteger(limit) && limit > 0 ? limit : fallback;
}

/**
 * Lower-cases search text the same way for the query and the server-rendered text, so a capital I typed on any
 * keyboard layout finds "i" and Turkish dotted/dotless letters do not split matches.
 */
export function foldSearchText(value) {
    return String(value ?? '').toLowerCase().replace(/̇/g, '').replace(/ı/g, 'i');
}

/** A catalog card's data attributes (`element.dataset`) as the model object the OpenRouter helpers expect. */
export function pickerModelData(dataset = {}) {
    return {
        id: dataset.modelId || '',
        name: dataset.modelName || '',
        searchText: foldSearchText(dataset.modelSearchText),
        author: dataset.modelAuthor || '',
        isFree: dataset.modelFree === 'true',
        isPreview: dataset.modelPreview === 'true',
        contextLength: finiteNumber(dataset.modelContext),
        promptPrice: finiteNumber(dataset.modelPromptPrice),
        completionPrice: finiteNumber(dataset.modelCompletionPrice),
        releasedAt: finiteNumber(dataset.modelReleasedAt),
    };
}

/** The models that match the filters, in the chosen order. The given model objects are returned, not copies. */
export function visibleCatalogModels(models, filters = {}, sort = 'newest') {
    const query = foldSearchText(filters.query).trim();

    return models
        .filter((model) => matchesOpenRouterFilters(
            { ...model, searchText: foldSearchText(model.searchText) },
            { ...filters, query },
        ))
        .sort((left, right) => compareOpenRouterModels(left, right, sort));
}

/** Keeps the first `limit` checked boxes, for example when old input held more models than allowed. */
export function enforceSelectionLimit(checked, limit) {
    let kept = 0;

    return checked.map((isChecked) => {
        if (!isChecked) return false;

        kept += 1;

        return kept <= limit;
    });
}

/** Whether a saved group (an option's `data-model-count` and `data-providers`) can run as a workflow. */
export function groupOptionState(option = {}, limit = 5, supportedProviders = WORKFLOW_PROVIDERS) {
    const modelCount = Math.max(0, Number.parseInt(String(option.modelCount ?? '0'), 10) || 0);
    const providers = String(option.providers ?? '').split(',').map((provider) => provider.trim()).filter(Boolean);
    const unsupported = providers.filter((provider) => !supportedProviders.includes(provider));

    if (modelCount === 0) {
        return { selectable: false, modelCount, message: 'Model grubu seçin.' };
    }

    if (modelCount > limit) {
        return { selectable: false, modelCount, message: `Bu grupta ${modelCount} model var; iş akışında en fazla ${limit} model seçilebilir.` };
    }

    if (unsupported.length > 0) {
        return {
            selectable: false,
            modelCount,
            message: `Bu gruptaki ${unsupported.join(', ')} sağlayıcısı iş akışlarında desteklenmiyor (yalnız OpenRouter ve OpenCode Go).`,
        };
    }

    return { selectable: true, modelCount, message: '' };
}

/** The value the "models at the same time" select may keep, and the highest option that stays enabled. */
export function parallelOptionState(selectedCount, currentValue, maxOption = 5) {
    const cap = selectedCount > 0 ? Math.min(selectedCount, maxOption) : maxOption;
    const current = Number.parseInt(String(currentValue ?? ''), 10);

    return { cap, value: Math.min(Math.max(Number.isInteger(current) ? current : 1, 1), cap) };
}

/** The Turkish problem that keeps the selection from being started, or '' when it is complete. */
export function pickerSelectionProblem({ mode, group = null, selectedCount = 0, limit = 5, fixedRouting = false, fixedReady = true }) {
    const normalizedMode = normalizeModelPickerMode(mode);

    if (normalizedMode === 'group') {
        return group?.selectable ? '' : (group?.message || 'Model grubu seçin.');
    }

    if (selectedCount === 0) {
        return normalizedMode === 'opencode_go'
            ? 'OpenCode Go kataloğundan en az bir model seçin.'
            : 'OpenRouter kataloğundan en az bir model seçin.';
    }

    if (selectedCount > limit) {
        return `Bir iş akışında en fazla ${limit} model seçilebilir.`;
    }

    if (normalizedMode === 'custom' && fixedRouting && !fixedReady) {
        return 'Seçilen her model için bir sabit sağlayıcı seçin.';
    }

    return '';
}

export function isEndpointPayload(payload, modelId) {
    return Boolean(payload)
        && payload.modelId === modelId
        && Array.isArray(payload.endpoints)
        && payload.endpoints.every((endpoint) => endpoint && typeof endpoint.slug === 'string' && typeof endpoint.name === 'string');
}

export function endpointOptionLabel(endpoint) {
    const missing = (value) => value === null || value === undefined || value === '';
    const identity = !endpoint.name || endpoint.name === endpoint.slug ? endpoint.slug : `${endpoint.name} · ${endpoint.slug}`;
    const priceLabel = missing(endpoint.promptPrice) || missing(endpoint.completionPrice)
        ? ' · fiyat bilgisi yok'
        : ` · Girdi ${formatUsd(finiteNumber(endpoint.promptPrice) * 1_000_000)}/M, çıktı ${formatUsd(finiteNumber(endpoint.completionPrice) * 1_000_000)}/M`;

    return `${identity}${endpoint.quantization ? ` · ${endpoint.quantization}` : ''}${priceLabel}`;
}

function initializeModelPicker(picker) {
    const limit = modelPickerLimit(picker.dataset.modelLimit);
    const modeInputs = [...picker.querySelectorAll('[data-model-picker-mode]')];
    const panels = [...picker.querySelectorAll('[data-model-picker-panel]')];
    const configSelect = picker.querySelector('[data-model-picker-config]');
    const routingSelect = picker.querySelector('[data-model-picker-routing]');
    const routingExplanation = picker.querySelector('[data-model-picker-routing-explanation]');
    const fixedPanel = picker.querySelector('[data-model-picker-fixed-panel]');
    const fixedList = picker.querySelector('[data-model-picker-fixed-list]');
    const fixedRefresh = picker.querySelector('[data-model-picker-fixed-refresh]');
    const message = picker.querySelector('[data-model-picker-message]');
    const form = picker.closest('form');
    const parallelSelect = form?.querySelector('[data-model-picker-parallel]') ?? null;
    const submitButton = form?.querySelector('[data-model-picker-submit]') ?? null;
    const catalogs = Object.keys(PROVIDER_MODES).map((provider) => ({
        provider,
        mode: PROVIDER_MODES[provider],
        container: picker.querySelector(`[data-model-picker-catalog="${provider}"]`),
        empty: picker.querySelector(`[data-model-picker-empty="${provider}"]`),
        cards: [...picker.querySelectorAll(`[data-model-picker-card="${provider}"]`)],
        inputs: [...picker.querySelectorAll(`[data-model-picker-model="${provider}"]`)],
        search: picker.querySelector(`[data-model-picker-search="${provider}"]`),
        author: picker.querySelector(`[data-model-picker-author="${provider}"]`),
        cost: picker.querySelector(`[data-model-picker-cost="${provider}"]`),
        release: picker.querySelector(`[data-model-picker-release="${provider}"]`),
        sort: picker.querySelector(`[data-model-picker-sort="${provider}"]`),
        visibleCounter: picker.querySelector(`[data-model-picker-visible="${provider}"]`),
        selectedCounter: picker.querySelector(`[data-model-picker-selected="${provider}"]`),
    }));
    const openRouter = catalogs[0];
    const providerSelections = new Map(openRouter.cards.map((card) => [card.dataset.modelId, card.dataset.modelFixedProvider || '']));
    const endpointStates = new Map();
    const endpointRevisions = new Map();
    let submitAttempted = false;
    let preferredParallel = parallelSelect?.value;

    const selectedMode = () => normalizeModelPickerMode(modeInputs.find((input) => input.checked)?.value);
    const isActiveCatalog = (catalog) => selectedMode() === catalog.mode;
    const checkedInputs = (catalog) => catalog.inputs.filter((input) => input.checked);
    const isFixedRouting = () => selectedMode() === 'custom' && routingSelect?.value === 'fixed';
    const selectedOpenRouterModels = () => checkedInputs(openRouter)
        .map((input) => input.closest('[data-model-picker-card]'))
        .filter(Boolean)
        .map((card) => pickerModelData(card.dataset));
    const readyEndpointLists = () => new Map([...endpointStates.entries()]
        .filter(([, state]) => state.status === 'ready')
        .map(([modelId, state]) => [modelId, state.endpoints]));
    const groupState = () => groupOptionState(configSelect?.selectedOptions?.[0]?.dataset ?? {}, limit);

    function selectedModelIds() {
        const mode = selectedMode();

        if (mode === 'group') return configSelect?.value ? [configSelect.value] : [];

        return checkedInputs(catalogs.find((catalog) => catalog.mode === mode)).map((input) => input.value);
    }

    function selectedCount() {
        const mode = selectedMode();

        if (mode === 'group') {
            const group = groupState();

            return group.selectable ? group.modelCount : 0;
        }

        return selectedModelIds().length;
    }

    function selectionProblem() {
        const mode = selectedMode();

        return pickerSelectionProblem({
            mode,
            group: mode === 'group' ? (configSelect ? groupState() : null) : null,
            selectedCount: mode === 'group' ? 0 : selectedModelIds().length,
            limit,
            fixedRouting: isFixedRouting(),
            fixedReady: Boolean(selectedFixedEndpoints(selectedOpenRouterModels(), providerSelections, readyEndpointLists())),
        });
    }

    function applyFilters(catalog) {
        if (!catalog.container) return;

        const models = catalog.cards.map((card) => ({ ...pickerModelData(card.dataset), element: card }));
        const visibleModels = visibleCatalogModels(models, {
            query: catalog.search?.value || '',
            author: catalog.author?.value || '',
            cost: catalog.cost?.value || 'all',
            release: catalog.release?.value || 'all',
        }, catalog.sort?.value || 'newest');
        const visibleElements = new Set(visibleModels.map((model) => model.element));

        models.forEach((model) => model.element.classList.toggle('hidden', !visibleElements.has(model.element)));
        visibleModels.forEach((model) => catalog.container.insertBefore(model.element, catalog.empty));
        catalog.empty?.classList.toggle('hidden', visibleModels.length !== 0 || catalog.cards.length === 0);
        if (catalog.visibleCounter) catalog.visibleCounter.textContent = String(visibleModels.length);
    }

    function updateSelectionLimit(catalog) {
        const count = checkedInputs(catalog).length;

        if (catalog.selectedCounter) catalog.selectedCounter.textContent = String(count);
        if (!isActiveCatalog(catalog)) return;

        catalog.inputs.forEach((input) => {
            input.disabled = !input.checked && count >= limit;
        });
    }

    function renderFixedProviderList() {
        if (!fixedList) return;

        if (!isFixedRouting()) {
            fixedList.replaceChildren();

            return;
        }

        const models = selectedOpenRouterModels();
        const fragment = document.createDocumentFragment();

        if (models.length === 0) {
            const help = document.createElement('p');
            help.className = 'text-[10px] leading-4 text-[var(--muted)]';
            help.textContent = 'Önce katalogdan en az bir model seçin.';
            fragment.append(help);
        }

        models.forEach((model, index) => {
            const state = endpointStates.get(model.id);
            const row = document.createElement('div');
            const label = document.createElement('label');
            const heading = document.createElement('span');
            const select = document.createElement('select');
            const placeholder = document.createElement('option');
            const note = document.createElement('p');
            const selectedSlug = providerSelections.get(model.id) || '';

            row.className = 'grid gap-1.5 rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3';
            label.className = 'grid gap-1.5 text-[11px] font-extrabold';
            heading.className = 'break-words';
            heading.textContent = `${model.name} · ${model.id}`;
            select.className = 'h-10 w-full min-w-0 rounded-lg border border-[var(--line)] bg-[var(--surface)] px-2.5 text-xs font-semibold outline-none focus:border-[var(--forest)] disabled:cursor-not-allowed disabled:bg-[var(--surface-subtle)]';
            select.id = `${picker.id || 'model-picker'}-fixed-${index}`;
            select.name = `fixed_providers[${model.id}]`;
            select.required = true;
            select.disabled = state?.status !== 'ready';
            placeholder.value = '';
            placeholder.textContent = state?.status === 'loading' ? 'Sağlayıcılar yükleniyor…' : 'Bir uç nokta seçin';
            select.append(placeholder);
            note.className = 'text-[10px] leading-4 text-[var(--muted)]';

            if (state?.status === 'ready') {
                state.endpoints.forEach((endpoint) => {
                    const option = document.createElement('option');
                    option.value = endpoint.slug;
                    option.textContent = endpointOptionLabel(endpoint);
                    select.append(option);
                });

                if (selectedSlug && state.endpoints.some((endpoint) => endpoint.slug === selectedSlug)) {
                    select.value = selectedSlug;
                } else if (selectedSlug) {
                    note.textContent = 'Önceki sağlayıcı artık kullanılmıyor; yeniden seçin.';
                } else if (state.endpoints.length === 0) {
                    note.textContent = 'Bu model için araç destekli bir uç nokta bulunamadı.';
                }
            } else if (state?.status === 'error') {
                note.textContent = state.message || 'Sağlayıcılar alınamadı. Yeniden deneyin.';
            } else {
                note.textContent = 'Araç destekli uç noktalar OpenRouter’dan alınıyor.';
            }

            select.addEventListener('change', () => {
                providerSelections.set(model.id, select.value);
                update();
            });
            label.append(heading, select);
            row.append(label, note);
            fragment.append(row);
        });

        fixedList.replaceChildren(fragment);
        if (fixedRefresh) {
            fixedRefresh.disabled = models.length === 0
                || models.some((model) => endpointStates.get(model.id)?.status === 'loading');
        }
    }

    async function loadEndpoints(modelId, force = false) {
        const current = endpointStates.get(modelId);

        if (!force && current?.status === 'loading') return;
        if (!force && current?.status === 'ready' && Date.now() - current.loadedAt < ENDPOINT_CACHE_MS) return;

        const revision = (endpointRevisions.get(modelId) || 0) + 1;
        endpointRevisions.set(modelId, revision);
        endpointStates.set(modelId, { status: 'loading' });
        renderFixedProviderList();

        try {
            const url = new URL(picker.dataset.providerEndpointsUrl, window.location.origin);
            url.searchParams.set('model', modelId);
            if (force) url.searchParams.set('refresh', '1');
            const response = await fetch(url, { cache: 'no-store', headers: { Accept: 'application/json' } });
            const payload = await response.json();

            if (!response.ok) throw new Error(payload?.message || 'Sağlayıcı listesi alınamadı.');
            if (!isEndpointPayload(payload, modelId)) throw new Error('Sağlayıcı listesi beklenen biçimde değil.');
            if (endpointRevisions.get(modelId) !== revision) return;

            endpointStates.set(modelId, { status: 'ready', endpoints: payload.endpoints, loadedAt: Date.now() });
        } catch (error) {
            if (endpointRevisions.get(modelId) !== revision) return;

            endpointStates.set(modelId, { status: 'error', message: error?.message || 'Sağlayıcılar alınamadı. Yeniden deneyin.' });
        }

        renderFixedProviderList();
        update();
    }

    function loadSelectedEndpoints(force = false) {
        if (!isFixedRouting()) return;

        selectedOpenRouterModels().forEach((model) => void loadEndpoints(model.id, force));
    }

    function updateRoutingControls() {
        const fixed = isFixedRouting();

        fixedPanel?.classList.toggle('hidden', !fixed);
        if (routingExplanation) {
            routingExplanation.textContent = fixed
                ? 'Her seçili model yalnız seçtiğiniz uç noktada çalışır; başka sağlayıcıya geçilmez. Veri toplama izni verilmez; parametre uyumu aranır.'
                : 'Alternatif sağlayıcıya geçiş açıktır; en düşük fiyat veya en yüksek hız garanti edilmez. Veri toplama izni verilmez; parametre uyumu aranır.';
        }

        renderFixedProviderList();
        loadSelectedEndpoints();
    }

    function updatePanels() {
        const mode = selectedMode();

        panels.forEach((panel) => {
            const active = panel.dataset.modelPickerPanel === mode;

            panel.classList.toggle('hidden', !active);
            panel.querySelectorAll('input, select, textarea, button').forEach((control) => {
                control.disabled = !active;
            });
        });

        catalogs.forEach(updateSelectionLimit);
        updateRoutingControls();
    }

    function updateParallel() {
        if (!parallelSelect) return;

        const state = parallelOptionState(selectedCount(), preferredParallel, parallelSelect.options.length || 5);

        [...parallelSelect.options].forEach((option) => {
            option.disabled = Number(option.value) > state.cap;
        });
        parallelSelect.value = String(state.value);
    }

    function update() {
        const problem = selectionProblem();
        const mode = selectedMode();

        updateParallel();

        if (message) {
            message.textContent = submitAttempted || (mode === 'group' && configSelect && !groupState().selectable) ? problem : '';
        }

        picker.dispatchEvent(new CustomEvent('model-picker:change', {
            bubbles: true,
            detail: { mode, models: selectedModelIds(), count: selectedCount(), ready: problem === '' },
        }));
    }

    catalogs.forEach((catalog) => {
        const allowed = enforceSelectionLimit(catalog.inputs.map((input) => input.checked), limit);
        catalog.inputs.forEach((input, index) => {
            input.checked = allowed[index];
        });

        [catalog.search, catalog.author, catalog.cost, catalog.release, catalog.sort].forEach((control) => {
            control?.addEventListener(control.tagName === 'SELECT' ? 'change' : 'input', () => applyFilters(catalog));
        });

        catalog.inputs.forEach((input) => input.addEventListener('change', () => {
            if (input.checked && checkedInputs(catalog).length > limit) {
                input.checked = false;
            }

            updateSelectionLimit(catalog);

            if (catalog === openRouter) {
                renderFixedProviderList();
                loadSelectedEndpoints();
            }

            update();
        }));

        applyFilters(catalog);
    });

    modeInputs.forEach((input) => input.addEventListener('change', () => {
        updatePanels();
        update();
    }));
    configSelect?.addEventListener('change', update);
    parallelSelect?.addEventListener('change', () => {
        preferredParallel = parallelSelect.value;
    });
    routingSelect?.addEventListener('change', () => {
        updateRoutingControls();
        update();
    });
    fixedRefresh?.addEventListener('click', () => loadSelectedEndpoints(true));
    form?.addEventListener('submit', (event) => {
        submitAttempted = true;
        const problem = selectionProblem();

        if (problem !== '') {
            event.preventDefault();
            update();
            message?.scrollIntoView?.({ block: 'center', behavior: 'smooth' });

            return;
        }

        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Başlatılıyor…';
        }
    });

    updatePanels();
    update();
}

/**
 * Wires every `[data-model-picker]` under `root` once: mode tabs, the limit from `data-model-limit`, catalog filters,
 * OpenRouter routing with per-model fixed providers, and a `model-picker:change` event for the host form.
 * Inputs of the panels that are not selected are disabled, so they are never submitted.
 */
export function initializeModelPickers(root = document) {
    if (!root || typeof root.querySelectorAll !== 'function') return;

    const pickers = [...root.querySelectorAll('[data-model-picker]')];

    if (typeof root.matches === 'function' && root.matches('[data-model-picker]')) pickers.unshift(root);

    pickers.forEach((picker) => {
        if (picker.dataset.modelPickerReady === 'true') return;

        picker.dataset.modelPickerReady = 'true';
        initializeModelPicker(picker);
    });
}
