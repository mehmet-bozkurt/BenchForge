import {
    compareOpenRouterModels,
    estimateOpenRouterCost,
    matchesOpenRouterFilters,
    pricedFixedEndpoints,
    selectedFixedEndpoints,
} from './openrouter-catalog.js';
import { initializePreparationCreate, initializePreparationShow } from './preparations.js';

const statusLabels = {
    idle: 'Hazır',
    starting: 'Başlatılıyor',
    running: 'Çalışıyor',
    stopping: 'Durduruluyor',
    completed: 'Koşu tamamlandı',
    failed: 'Koşucu hatası',
    stopped: 'Durduruldu',
};

function statusTone(status) {
    if (status === 'completed') return 'pass';
    if (status === 'failed') return 'fail';
    if (['starting', 'running', 'stopping'].includes(status)) return 'running';

    return 'unknown';
}

function outcomeTone(status) {
    if (status === 'pass') return 'pass';
    if (status === 'fail') return 'fail';
    if (status === 'pending') return 'running';

    return 'unknown';
}

function formatDate(value) {
    if (!value) return '—';

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) return value;

    return new Intl.DateTimeFormat('tr-TR', {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    }).format(date);
}

function initializeRunner() {
    const panel = document.querySelector('[data-runner-panel]');

    if (!panel) return;

    const form = panel.querySelector('[data-runner-form]');
    const submitButton = panel.querySelector('[data-runner-submit]');
    const modelLimit = Number(panel.dataset.modelLimit || 10);
    const configSelect = panel.querySelector('[data-runner-config]');
    const modelModeInputs = [...panel.querySelectorAll('[data-model-mode]')];
    const promptModeInputs = [...panel.querySelectorAll('[data-prompt-mode]')];
    const customModelInputs = [...panel.querySelectorAll('[data-custom-model]')];
    const modelCards = [...panel.querySelectorAll('[data-model-card]')];
    const modelSearch = panel.querySelector('[data-model-search]');
    const modelAuthorFilter = panel.querySelector('[data-model-author-filter]');
    const modelCostFilter = panel.querySelector('[data-model-cost-filter]');
    const modelReleaseFilter = panel.querySelector('[data-model-release-filter]');
    const modelSort = panel.querySelector('[data-model-sort]');
    const modelCatalog = panel.querySelector('[data-model-catalog]');
    const modelEmptyFilter = panel.querySelector('[data-model-empty-filter]');
    const routingModeSelect = panel.querySelector('[data-routing-mode]');
    const routingExplanation = panel.querySelector('[data-routing-explanation]');
    const fixedProviderPanel = panel.querySelector('[data-fixed-provider-panel]');
    const fixedProviderList = panel.querySelector('[data-fixed-provider-list]');
    const fixedProviderRefresh = panel.querySelector('[data-fixed-provider-refresh]');
    const costBasis = panel.querySelector('[data-cost-basis]');
    const costInputTokens = panel.querySelector('[data-cost-input-tokens]');
    const costOutputTokens = panel.querySelector('[data-cost-output-tokens]');
    const goModelInputs = [...panel.querySelectorAll('[data-go-custom-model]')];
    const goModelCards = [...panel.querySelectorAll('[data-go-model-card]')];
    const goModelSearch = panel.querySelector('[data-go-model-search]');
    const goModelCostFilter = panel.querySelector('[data-go-model-cost-filter]');
    const goModelReleaseFilter = panel.querySelector('[data-go-model-release-filter]');
    const goModelSort = panel.querySelector('[data-go-model-sort]');
    const goModelCatalog = panel.querySelector('[data-go-model-catalog]');
    const goModelEmptyFilter = panel.querySelector('[data-go-model-empty-filter]');
    const goCostInputTokens = panel.querySelector('[data-go-cost-input-tokens]');
    const goCostOutputTokens = panel.querySelector('[data-go-cost-output-tokens]');
    const stopForm = panel.querySelector('[data-runner-stop-form]');
    const stopButton = panel.querySelector('[data-runner-stop-button]');
    const reportLink = panel.querySelector('[data-runner-report-link]');
    const preflightButton = panel.querySelector('[data-preflight-refresh]');
    const preflightStatus = panel.querySelector('[data-preflight-status]');
    const preflightChecks = panel.querySelector('[data-preflight-checks]');
    const concurrencySelect = panel.querySelector('[data-runner-concurrency]');
    const concurrencyHelp = panel.querySelector('[data-runner-concurrency-help]');
    const fields = [...panel.querySelectorAll('[data-runner-input]')];
    let lastActive = Boolean(form?.querySelector('[name="run_id"]')?.disabled);
    let runnerActive = lastActive;
    let preflightReady = panel.dataset.preflightReady === 'true';
    let preflightPending = false;
    let preflightTimer;
    let preflightRevision = 0;
    const providerSelections = new Map(modelCards.map((card) => [card.dataset.modelId, card.dataset.modelFixedProvider || '']));
    const endpointStates = new Map();
    const endpointRequestRevisions = new Map();

    const selectedModelMode = () => modelModeInputs.find((input) => input.checked)?.value || 'group';
    const selectedPromptMode = () => promptModeInputs.find((input) => input.checked)?.value || 'saved';
    const checkedCustomModels = () => customModelInputs.filter((input) => input.checked);
    const checkedGoModels = () => goModelInputs.filter((input) => input.checked);
    const isFixedRouting = () => selectedModelMode() === 'custom' && routingModeSelect?.value === 'fixed';
    const selectedCustomModels = () => checkedCustomModels()
        .map((input) => input.closest('[data-model-card]'))
        .filter(Boolean)
        .map(modelData);
    const readyEndpointLists = () => new Map([...endpointStates.entries()]
        .filter(([, state]) => state.status === 'ready')
        .map(([modelId, state]) => [modelId, state.endpoints]));
    const fixedSelectionMissing = () => isFixedRouting()
        && !selectedFixedEndpoints(selectedCustomModels(), providerSelections, readyEndpointLists());
    const selectionMissing = () => (selectedModelMode() === 'custom' && checkedCustomModels().length === 0)
        || (selectedModelMode() === 'opencode_go' && checkedGoModels().length === 0)
        || fixedSelectionMissing();

    function selectedModelCount() {
        const modelMode = selectedModelMode();

        if (modelMode === 'custom') return checkedCustomModels().length;
        if (modelMode === 'opencode_go') return checkedGoModels().length;

        return Number(configSelect?.selectedOptions[0]?.dataset.modelCount || 0);
    }

    function updateConcurrencyOptions() {
        if (!concurrencySelect) return;

        const modelCount = selectedModelCount();
        const currentValue = Number(concurrencySelect.value || 2);
        const safeValue = modelCount > 0 ? Math.min(currentValue, modelCount) : currentValue;

        [...concurrencySelect.options].forEach((option) => {
            option.disabled = modelCount > 0 && Number(option.value) > modelCount;
        });

        concurrencySelect.value = String(safeValue);

        if (concurrencyHelp) {
            concurrencyHelp.textContent = safeValue === 1
                ? 'Modeller sırayla çalışır. En güvenilir seçenektir ve süre karşılaştırması için kaynak rekabetini azaltır.'
                : `${safeValue} model aynı anda çalışır. Daha yüksek değerler build ve browser kontrollerinde işlemci ve bellek kullanımını artırır.`;
        }
    }

    function modelData(card) {
        return {
            element: card,
            id: card.dataset.modelId || '',
            name: card.dataset.modelName || '',
            searchText: card.dataset.modelSearchText || '',
            author: card.dataset.modelAuthor || '',
            isFree: card.dataset.modelFree === 'true',
            isPreview: card.dataset.modelPreview === 'true',
            contextLength: Number(card.dataset.modelContext || 0),
            promptPrice: Number(card.dataset.modelPromptPrice || 0),
            completionPrice: Number(card.dataset.modelCompletionPrice || 0),
            releasedAt: Number(card.dataset.modelReleasedAt || 0),
        };
    }

    function applyModelFilters() {
        if (!modelCatalog) return;

        const filters = {
            query: modelSearch?.value || '',
            author: modelAuthorFilter?.value || '',
            cost: modelCostFilter?.value || 'all',
            release: modelReleaseFilter?.value || 'all',
        };
        const models = modelCards.map(modelData);
        const visibleModels = models
            .filter((model) => matchesOpenRouterFilters(model, filters))
            .sort((left, right) => compareOpenRouterModels(left, right, modelSort?.value || 'newest'));
        const visibleElements = new Set(visibleModels.map((model) => model.element));

        models.forEach((model) => model.element.classList.toggle('hidden', !visibleElements.has(model.element)));
        visibleModels.forEach((model) => modelCatalog.insertBefore(model.element, modelEmptyFilter));
        modelEmptyFilter?.classList.toggle('hidden', visibleModels.length !== 0 || modelCards.length === 0);

        const counter = panel.querySelector('[data-model-visible-count]');
        if (counter) counter.textContent = String(visibleModels.length);
    }

    function applyGoModelFilters() {
        if (!goModelCatalog) return;

        const filters = {
            query: goModelSearch?.value || '',
            author: '',
            cost: goModelCostFilter?.value || 'all',
            release: goModelReleaseFilter?.value || 'all',
        };
        const models = goModelCards.map(modelData);
        const visibleModels = models
            .filter((model) => matchesOpenRouterFilters(model, filters))
            .sort((left, right) => compareOpenRouterModels(left, right, goModelSort?.value || 'newest'));
        const visibleElements = new Set(visibleModels.map((model) => model.element));

        models.forEach((model) => model.element.classList.toggle('hidden', !visibleElements.has(model.element)));
        visibleModels.forEach((model) => goModelCatalog.insertBefore(model.element, goModelEmptyFilter));
        goModelEmptyFilter?.classList.toggle('hidden', visibleModels.length !== 0 || goModelCards.length === 0);

        const counter = panel.querySelector('[data-go-model-visible-count]');
        if (counter) counter.textContent = String(visibleModels.length);
    }

    function formatUsd(value) {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
            minimumFractionDigits: 2,
            maximumFractionDigits: value > 0 && value < 0.01 ? 6 : 2,
        }).format(value);
    }

    function updateCostEstimate() {
        const selectedModels = selectedCustomModels();
        const pricedModels = isFixedRouting()
            ? pricedFixedEndpoints(selectedModels, providerSelections, readyEndpointLists())
            : selectedModels;
        const estimate = pricedModels ? estimateOpenRouterCost(
            pricedModels,
            costInputTokens?.value || 0,
            costOutputTokens?.value || 0,
        ) : null;
        const hasSelection = selectedModels.length > 0;

        const total = panel.querySelector('[data-cost-total]');
        const input = panel.querySelector('[data-cost-input]');
        const output = panel.querySelector('[data-cost-output]');
        const modelCount = panel.querySelector('[data-cost-model-count]');

        if (total) total.textContent = hasSelection && estimate ? formatUsd(estimate.totalCost) : '—';
        if (input) input.textContent = hasSelection && estimate ? formatUsd(estimate.inputCost) : '—';
        if (output) output.textContent = hasSelection && estimate ? formatUsd(estimate.outputCost) : '—';
        if (modelCount) modelCount.textContent = String(selectedModels.length);
        if (costBasis) {
            costBasis.textContent = isFixedRouting()
                ? (estimate
                    ? 'Seçilen sabit uç noktaların fiyatlarıyla hesaplanan tahmin. OpenCode birden fazla istek yapabilir; gerçek tutar önbellek ve yeniden denemeye göre değişebilir.'
                    : 'Tüm sabit uç noktalar seçilip fiyatları doğrulanana kadar tahmin gösterilmez. Fiyatı bilinmeyen uç noktalar için tutar — kalır.')
                : 'Katalog fiyatlarıyla hesaplanan tahmin. OpenCode birden fazla istek yapabilir; gerçek tutar önbellek, yeniden deneme ve OpenRouter yönlendirmesine göre değişebilir.';
        }
    }

    function renderFixedProviderList() {
        if (!fixedProviderList) return;

        if (!isFixedRouting()) {
            fixedProviderList.replaceChildren();
            updateCostEstimate();
            return;
        }

        const selectedModels = selectedCustomModels();
        const fragment = document.createDocumentFragment();

        if (selectedModels.length === 0) {
            const help = document.createElement('p');
            help.className = 'text-[10px] leading-4 text-[var(--muted)]';
            help.textContent = 'Önce katalogdan en az bir model seçin.';
            fragment.append(help);
        }

        selectedModels.forEach((model, index) => {
            const state = endpointStates.get(model.id);
            const row = document.createElement('div');
            const label = document.createElement('label');
            const heading = document.createElement('span');
            const select = document.createElement('select');
            const placeholder = document.createElement('option');
            const message = document.createElement('p');
            const selectedSlug = providerSelections.get(model.id) || '';

            row.className = 'grid gap-1.5 rounded-lg border border-[var(--line)] bg-[var(--surface)] p-3';
            label.className = 'grid gap-1.5 text-[11px] font-extrabold';
            heading.className = 'break-words';
            heading.textContent = `${model.name} · ${model.id}`;
            select.className = 'h-10 w-full min-w-0 rounded-lg border border-[var(--line)] bg-[var(--surface)] px-2.5 text-xs font-semibold outline-none focus:border-[var(--forest)] disabled:cursor-not-allowed disabled:bg-[var(--surface-subtle)]';
            select.id = `fixed-provider-${index}`;
            select.name = `fixed_providers[${model.id}]`;
            select.dataset.modelId = model.id;
            select.required = true;
            select.disabled = runnerActive || state?.status !== 'ready';
            placeholder.value = '';
            placeholder.textContent = state?.status === 'loading' ? 'Sağlayıcılar yükleniyor…' : 'Bir uç nokta seçin';
            select.append(placeholder);
            message.className = 'text-[10px] leading-4 text-[var(--muted)]';

            if (state?.status === 'ready') {
                state.endpoints.forEach((endpoint) => {
                    const option = document.createElement('option');
                    const hasPrices = endpoint.promptPrice !== null && endpoint.completionPrice !== null;
                    const priceLabel = hasPrices
                        ? ` · Girdi ${formatUsd(Number(endpoint.promptPrice) * 1_000_000)}/M, çıktı ${formatUsd(Number(endpoint.completionPrice) * 1_000_000)}/M`
                        : ' · fiyat bilgisi yok';

                    option.value = endpoint.slug;
                    const identity = endpoint.name === endpoint.slug
                        ? endpoint.slug
                        : `${endpoint.name || endpoint.slug} · ${endpoint.slug}`;
                    option.textContent = `${identity}${endpoint.quantization ? ` · ${endpoint.quantization}` : ''}${priceLabel}`;
                    select.append(option);
                });

                if (selectedSlug && state.endpoints.some((endpoint) => endpoint.slug === selectedSlug)) {
                    select.value = selectedSlug;
                } else if (selectedSlug) {
                    message.textContent = 'Önceki sağlayıcı artık kullanılmıyor; yeniden seçin.';
                } else if (state.endpoints.length === 0) {
                    message.textContent = 'Bu model için araç destekli bir uç nokta bulunamadı.';
                }
            } else if (state?.status === 'error') {
                message.textContent = state.message || 'Sağlayıcılar alınamadı. Yeniden deneyin.';
            } else {
                message.textContent = 'Araç destekli uç noktalar OpenRouter’dan alınıyor.';
            }

            select.addEventListener('change', () => {
                providerSelections.set(model.id, select.value);
                updateCostEstimate();
                schedulePreflight();
            });
            label.append(heading, select);
            row.append(label, message);
            fragment.append(row);
        });

        fixedProviderList.replaceChildren(fragment);
        if (fixedProviderRefresh) fixedProviderRefresh.disabled = runnerActive || selectedModels.length === 0
            || selectedModels.some((model) => endpointStates.get(model.id)?.status === 'loading');
        if (preflightButton) preflightButton.disabled = runnerActive || preflightPending || selectionMissing();
        updateCostEstimate();
    }

    async function loadEndpoints(modelId, force = false) {
        const current = endpointStates.get(modelId);

        if (!force && current?.status === 'loading') return;
        if (!force && current?.status === 'ready' && Date.now() - current.loadedAt < 300_000) return;

        const revision = (endpointRequestRevisions.get(modelId) || 0) + 1;
        endpointRequestRevisions.set(modelId, revision);
        endpointStates.set(modelId, { status: 'loading' });
        renderFixedProviderList();

        try {
            const url = new URL(panel.dataset.providerEndpointsUrl, window.location.origin);
            url.searchParams.set('model', modelId);
            if (force) url.searchParams.set('refresh', '1');
            const response = await fetch(url, {
                cache: 'no-store',
                headers: { Accept: 'application/json' },
            });
            const payload = await response.json();

            if (!response.ok) throw new Error(payload.message || 'Sağlayıcı listesi alınamadı.');
            if (payload.modelId !== modelId || !Array.isArray(payload.endpoints)
                || !payload.endpoints.every((endpoint) => typeof endpoint.slug === 'string' && typeof endpoint.name === 'string')) {
                throw new Error('Sağlayıcı listesi beklenen biçimde değil.');
            }
            if (endpointRequestRevisions.get(modelId) !== revision) return;

            endpointStates.set(modelId, {
                status: 'ready',
                endpoints: payload.endpoints,
                loadedAt: Date.now(),
            });
        } catch (error) {
            if (endpointRequestRevisions.get(modelId) !== revision) return;
            endpointStates.set(modelId, {
                status: 'error',
                message: error?.message || 'Sağlayıcılar alınamadı. Yeniden deneyin.',
            });
        }

        renderFixedProviderList();
        schedulePreflight();
    }

    function loadSelectedEndpoints(force = false) {
        if (!isFixedRouting()) return;

        selectedCustomModels().forEach((model) => void loadEndpoints(model.id, force));
    }

    function updateRoutingControls() {
        const custom = selectedModelMode() === 'custom';
        const fixed = isFixedRouting();

        if (routingModeSelect) routingModeSelect.disabled = runnerActive || !custom;
        fixedProviderPanel?.classList.toggle('hidden', !fixed);
        if (routingExplanation) {
            routingExplanation.textContent = fixed
                ? 'Yalnız seçili OpenRouter katalog modelleri için geçerlidir. Kayıtlı gruplar mevcut ayarlarını korur; OpenCode Go etkilenmez. Veri toplama izni verilmez; parametre uyumu aranır.'
                : 'Alternatif sağlayıcıya geçiş açıktır; en düşük fiyat veya en yüksek hız garanti edilmez. Yalnız OpenRouter kataloğundan seçilen modeller için geçerlidir; kayıtlı gruplar ve OpenCode Go etkilenmez. Veri toplama izni verilmez; parametre uyumu aranır.';
        }

        renderFixedProviderList();
        loadSelectedEndpoints();
    }

    function updateGoCostEstimate() {
        const selectedModels = checkedGoModels()
            .map((input) => input.closest('[data-go-model-card]'))
            .filter(Boolean)
            .map(modelData);
        const estimate = estimateOpenRouterCost(
            selectedModels,
            goCostInputTokens?.value || 0,
            goCostOutputTokens?.value || 0,
        );
        const hasSelection = selectedModels.length > 0;

        const total = panel.querySelector('[data-go-cost-total]');
        const input = panel.querySelector('[data-go-cost-input]');
        const output = panel.querySelector('[data-go-cost-output]');
        const modelCount = panel.querySelector('[data-go-cost-model-count]');

        if (total) total.textContent = hasSelection ? formatUsd(estimate.totalCost) : '—';
        if (input) input.textContent = hasSelection ? formatUsd(estimate.inputCost) : '—';
        if (output) output.textContent = hasSelection ? formatUsd(estimate.outputCost) : '—';
        if (modelCount) modelCount.textContent = String(selectedModels.length);
    }

    function updateModePanels() {
        const modelMode = selectedModelMode();
        const promptMode = selectedPromptMode();

        panel.querySelectorAll('[data-model-panel]').forEach((element) => {
            element.classList.toggle('hidden', element.dataset.modelPanel !== modelMode);
        });
        panel.querySelectorAll('[data-selection-group]').forEach((element) => {
            element.classList.toggle('hidden', modelMode === 'group');
        });
        panel.querySelectorAll('[data-prompt-panel]').forEach((element) => {
            element.classList.toggle('hidden', element.dataset.promptPanel !== promptMode);
        });
    }

    function updateCustomModelSelection() {
        const selectedCount = checkedCustomModels().length;
        const counter = panel.querySelector('[data-model-selected-count]');

        if (counter) counter.textContent = String(selectedCount);

        customModelInputs.forEach((input) => {
            input.disabled = runnerActive || (selectedCount >= modelLimit && !input.checked);
        });

        updateCostEstimate();
    }

    function updateGoModelSelection() {
        const selectedCount = checkedGoModels().length;
        const counter = panel.querySelector('[data-go-model-selected-count]');

        if (counter) counter.textContent = String(selectedCount);

        goModelInputs.forEach((input) => {
            input.disabled = runnerActive || (selectedCount >= modelLimit && !input.checked);
        });

        updateGoCostEstimate();
    }

    function updateSubmitState() {
        if (!submitButton) return;

        submitButton.disabled = runnerActive || !preflightReady;
        submitButton.textContent = runnerActive
            ? 'Aktif koşunun bitmesi bekleniyor'
            : (preflightReady ? 'Benchmark’ı başlat' : 'Ön kontrol sorunlarını giderin');
    }

    function updatePreflightStatus(status, label) {
        if (!preflightStatus) return;

        preflightStatus.classList.remove('status-pass', 'status-fail', 'status-running', 'status-unknown');
        preflightStatus.classList.add(`status-${status}`);
        panel.querySelector('[data-preflight-status-label]').textContent = label;
    }

    function renderPreflightChecks(checks) {
        if (!preflightChecks) return;

        const fragment = document.createDocumentFragment();

        checks.forEach((check) => {
            const article = document.createElement('article');
            const mark = document.createElement('span');
            const body = document.createElement('div');
            const label = document.createElement('h4');
            const message = document.createElement('p');

            article.className = `preflight-check preflight-check-${check.status} flex items-start gap-3 rounded-lg border p-3`;
            article.dataset.preflightCheck = check.key;
            mark.className = 'preflight-mark';
            mark.setAttribute('aria-hidden', 'true');
            mark.textContent = check.status === 'pass' ? '✓' : '×';
            body.className = 'min-w-0';
            label.className = 'text-[11px] font-extrabold';
            label.textContent = check.label;
            message.className = 'mt-1 break-words text-[10px] leading-4 text-[var(--muted)]';
            message.textContent = check.message;
            body.append(label, message);
            article.append(mark, body);
            fragment.append(article);
        });

        preflightChecks.replaceChildren(fragment);
    }

    function applyPreflight(preflight) {
        preflightReady = Boolean(preflight.ready);
        panel.dataset.preflightReady = String(preflightReady);
        updatePreflightStatus(preflightReady ? 'pass' : 'fail', preflightReady ? 'Başlatmaya hazır' : 'Kontrol gerekli');
        panel.querySelector('[data-preflight-summary]').textContent = preflight.summary;
        renderPreflightChecks(preflight.checks || []);
        updateSubmitState();
    }

    async function refreshPreflight() {
        const revision = ++preflightRevision;
        const modelMode = selectedModelMode();
        const selectedConfig = configSelect?.value;
        const selectedModels = checkedCustomModels().map((input) => input.value);
        const selectedGoModels = checkedGoModels().map((input) => input.value);

        if (runnerActive) {
            preflightPending = false;
            return;
        }

        if ((modelMode === 'group' && !selectedConfig) || selectionMissing()) {
            preflightReady = false;
            preflightPending = false;
            panel.dataset.preflightReady = 'false';
            updatePreflightStatus('fail', selectedModels.length > 0 && fixedSelectionMissing() ? 'Sağlayıcı seçimi gerekli' : 'Model seçimi gerekli');
            panel.querySelector('[data-preflight-summary]').textContent = modelMode === 'custom' && selectedModels.length > 0 && fixedSelectionMissing()
                ? 'Sabit yönlendirme için her modelin araç destekli sağlayıcısını seçin. Liste yüklenmediyse veya hata verdiyse yeniden deneyin.'
                : (modelMode === 'custom'
                    ? 'Ön kontrol için OpenRouter kataloğundan en az bir model seçin.'
                : (modelMode === 'opencode_go'
                    ? 'Ön kontrol için OpenCode Go kataloğundan en az bir model seçin.'
                    : 'Ön kontrol için bir model grubu seçin.'));
            renderPreflightChecks([]);
            updateSubmitState();
            if (preflightButton) preflightButton.disabled = true;
            return;
        }

        preflightReady = false;
        preflightPending = true;
        panel.dataset.preflightReady = 'false';
        updateSubmitState();
        updatePreflightStatus('running', 'Kontrol ediliyor');
        panel.querySelector('[data-preflight-summary]').textContent = 'Yerel araçlar ve seçilen model portları kontrol ediliyor…';

        if (preflightButton) {
            preflightButton.disabled = true;
            preflightButton.textContent = 'Kontrol ediliyor…';
        }

        try {
            const url = new URL(panel.dataset.preflightUrl, window.location.origin);
            url.searchParams.set('model_mode', modelMode);

            if (modelMode === 'custom') {
                selectedModels.forEach((model) => url.searchParams.append('models[]', model));
                url.searchParams.set('routing_mode', routingModeSelect?.value || 'auto');
                if (isFixedRouting()) {
                    selectedModels.forEach((model) => {
                        url.searchParams.set(`fixed_providers[${model}]`, providerSelections.get(model) || '');
                    });
                }
            } else if (modelMode === 'opencode_go') {
                selectedGoModels.forEach((model) => url.searchParams.append('go_models[]', model));
            } else {
                url.searchParams.set('config', selectedConfig);
            }
            const response = await fetch(url, {
                cache: 'no-store',
                headers: { Accept: 'application/json' },
            });

            const payload = await response.json();
            if (!response.ok) throw new Error(payload.message || 'Sistem ön kontrolü alınamadı.');
            if (revision !== preflightRevision) return;

            applyPreflight(payload);
        } catch (error) {
            if (revision !== preflightRevision) return;
            preflightReady = false;
            panel.dataset.preflightReady = 'false';
            updatePreflightStatus('fail', 'Kontrol yapılamadı');
            panel.querySelector('[data-preflight-summary]').textContent = error?.message || 'Sistem ön kontrolü alınamadı. Yerel bağlantıyı kontrol edip yeniden deneyin.';
            updateSubmitState();
        } finally {
            if (preflightButton && revision === preflightRevision) {
                preflightPending = false;
                preflightButton.disabled = runnerActive || selectionMissing();
                preflightButton.textContent = 'Yeniden kontrol et';
            }
        }
    }

    function schedulePreflight() {
        if (runnerActive) return;

        window.clearTimeout(preflightTimer);
        preflightRevision += 1;
        preflightReady = false;
        preflightPending = true;
        panel.dataset.preflightReady = 'false';
        updateSubmitState();
        updatePreflightStatus('running', 'Kontrol bekleniyor');
        panel.querySelector('[data-preflight-summary]').textContent = 'Seçim değişti; ön kontrol yenileniyor…';
        if (preflightButton) preflightButton.disabled = true;
        preflightTimer = window.setTimeout(() => void refreshPreflight(), 180);
    }

    /** Rough per-model figures: each model gets its own workspace with node_modules and a 60-minute limit. */
    function updateLoadEstimate() {
        const estimate = panel.querySelector('[data-runner-load-estimate]');

        if (!estimate) return;

        const modelCount = selectedModelCount();
        const parallel = Math.max(1, Math.min(Number(concurrencySelect?.value || 1), modelCount || 1));

        if (modelCount === 0) {
            estimate.classList.add('hidden');
            return;
        }

        const diskGigabytes = (modelCount * 0.5).toLocaleString('tr-TR', { maximumFractionDigits: 1 });
        const worstHours = Math.ceil(modelCount / parallel);
        const heavy = modelCount > 5;

        estimate.textContent = `Tahmini yük: yaklaşık ${diskGigabytes} GB çalışma alanı (model başına ~0,5 GB). `
            + `${parallel} model aynı anda çalışırsa en kötü durumda yaklaşık ${worstHours} saat sürer (model başına 60 dk sınır).`
            + (heavy ? ' Model sayısı arttıkça maliyet de doğrusal artar.' : '');
        estimate.classList.remove('hidden');
        estimate.classList.toggle('bg-[var(--amber-soft)]', heavy);
        estimate.classList.toggle('text-[var(--amber-ink)]', heavy);
        estimate.classList.toggle('bg-[var(--surface)]', !heavy);
        estimate.classList.toggle('text-[var(--muted)]', !heavy);
    }

    function updateConfigSummary() {
        const modelMode = selectedModelMode();
        const option = configSelect?.selectedOptions[0];
        const selectedCount = modelMode === 'opencode_go'
            ? checkedGoModels().length
            : checkedCustomModels().length;
        const modelCount = panel.querySelector('[data-runner-model-count]');
        const ports = panel.querySelector('[data-runner-ports]');
        const providers = panel.querySelector('[data-runner-providers]');
        const maxParallel = panel.querySelector('[data-runner-max-parallel]');

        updateConcurrencyOptions();
        updateLoadEstimate();

        if (modelMode === 'custom' || modelMode === 'opencode_go') {
            if (modelCount) modelCount.textContent = selectedCount ? String(selectedCount) : '—';
            if (ports) ports.textContent = selectedCount
                ? Array.from({ length: selectedCount }, (_, index) => 3251 + index).join(', ')
                : '—';
            if (providers) providers.textContent = selectedCount
                ? (modelMode === 'opencode_go' ? 'opencode-go' : 'openrouter')
                : '—';
            if (maxParallel) maxParallel.textContent = selectedCount ? concurrencySelect?.value || '1' : '—';
            return;
        }

        if (modelCount) modelCount.textContent = option?.dataset.modelCount || '—';
        if (ports) ports.textContent = option?.dataset.ports || '—';
        if (providers) providers.textContent = option?.dataset.providers || '—';
        if (maxParallel) maxParallel.textContent = option ? concurrencySelect?.value || '1' : '—';
    }

    function updateState(state) {
        const tone = statusTone(state.status);

        panel.querySelectorAll('[data-runner-status-badge]').forEach((badge) => {
            badge.classList.remove('status-pass', 'status-fail', 'status-running', 'status-unknown');
            badge.classList.add(`status-${tone}`);
        });
        panel.querySelectorAll('[data-runner-status-label]').forEach((label) => {
            label.textContent = statusLabels[state.status] || 'Bilinmiyor';
        });

        panel.querySelector('[data-runner-run-id]').textContent = state.runId || 'Koşucu hazır';
        panel.querySelector('[data-runner-message]').textContent = state.message || '';
        panel.querySelector('[data-runner-phase]').textContent = state.progress.phase;
        panel.querySelector('[data-runner-progress-count]').textContent = `${state.progress.completedModels}/${state.progress.totalModels} model`;
        panel.querySelector('[data-runner-started]').textContent = formatDate(state.startedAt);
        panel.querySelector('[data-runner-finished]').textContent = formatDate(state.finishedAt);
        panel.querySelector('[data-runner-exit]').textContent = state.exitCode ?? '—';
        panel.querySelector('[data-runner-log]').textContent = state.logTail || 'Koşu başladığında son günlük kayıtları burada görünecek.';

        const outcome = state.outcome || {
            status: 'unknown',
            label: 'Sonuç okunamadı',
            summary: 'Benchmark sonucu alınamadı.',
            available: false,
            passedModels: 0,
            failedModels: 0,
            otherModels: 0,
        };
        const outcomeBadge = panel.querySelector('[data-runner-outcome-badge]');

        if (outcomeBadge) {
            outcomeBadge.classList.remove('status-pass', 'status-fail', 'status-running', 'status-unknown');
            outcomeBadge.classList.add(`status-${outcomeTone(outcome.status)}`);
        }

        panel.querySelector('[data-runner-outcome-label]').textContent = outcome.label;
        panel.querySelector('[data-runner-outcome-summary]').textContent = outcome.summary;
        panel.querySelector('[data-runner-outcome-passed]').textContent = String(outcome.passedModels);
        panel.querySelector('[data-runner-outcome-failed]').textContent = String(outcome.failedModels);
        panel.querySelector('[data-runner-outcome-other]').textContent = String(outcome.otherModels);
        panel.querySelector('[data-runner-outcome-counts]').classList.toggle('hidden', !outcome.available);

        const progress = panel.querySelector('[data-runner-progress]');
        const progressBar = panel.querySelector('[data-runner-progress-bar]');
        progress.setAttribute('aria-valuenow', String(state.progress.percent));
        progressBar.style.width = `${state.progress.percent}%`;

        fields.forEach((field) => {
            field.disabled = state.active;
        });
        runnerActive = state.active;
        updateCustomModelSelection();
        updateGoModelSelection();
        if (routingModeSelect) routingModeSelect.disabled = state.active || selectedModelMode() !== 'custom';
        fixedProviderList?.querySelectorAll('select').forEach((select) => {
            select.disabled = state.active || endpointStates.get(select.dataset.modelId)?.status !== 'ready';
        });
        if (fixedProviderRefresh) fixedProviderRefresh.disabled = state.active || !isFixedRouting() || checkedCustomModels().length === 0
            || selectedCustomModels().some((model) => endpointStates.get(model.id)?.status === 'loading');
        updateSubmitState();

        if (preflightButton) preflightButton.disabled = state.active || preflightPending || selectionMissing();

        stopForm?.classList.toggle('hidden', !state.active);

        if (stopButton) {
            stopButton.disabled = state.status === 'stopping';
            stopButton.textContent = state.status === 'stopping' ? 'Durduruluyor…' : 'Koşuyu durdur';
        }

        if (reportLink) {
            const reportAvailable = state.status === 'completed' && state.runId;
            reportLink.classList.toggle('hidden', !reportAvailable);

            if (reportAvailable) {
                reportLink.href = `${panel.dataset.runsUrl}/${encodeURIComponent(state.runId)}`;
            }
        }

        if (lastActive && !state.active && state.status === 'completed') {
            window.setTimeout(() => window.location.reload(), 500);
        }

        lastActive = state.active;
    }

    async function refreshState() {
        try {
            const response = await fetch(panel.dataset.statusUrl, {
                cache: 'no-store',
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) return;

            updateState(await response.json());
        } catch {
            // Geçici yerel bağlantı hatalarında mevcut durum ekranda kalır.
        }
    }

    configSelect?.addEventListener('change', () => {
        updateConfigSummary();
        schedulePreflight();
    });
    modelModeInputs.forEach((input) => input.addEventListener('change', () => {
        updateModePanels();
        updateCustomModelSelection();
        updateGoModelSelection();
        updateRoutingControls();
        updateConfigSummary();
        schedulePreflight();
    }));
    promptModeInputs.forEach((input) => input.addEventListener('change', updateModePanels));
    customModelInputs.forEach((input) => input.addEventListener('change', () => {
        if (checkedCustomModels().length > modelLimit) input.checked = false;
        updateCustomModelSelection();
        renderFixedProviderList();
        loadSelectedEndpoints();
        updateConfigSummary();
        schedulePreflight();
    }));
    goModelInputs.forEach((input) => input.addEventListener('change', () => {
        if (checkedGoModels().length > modelLimit) input.checked = false;
        updateGoModelSelection();
        updateConfigSummary();
        schedulePreflight();
    }));
    modelSearch?.addEventListener('input', applyModelFilters);
    modelAuthorFilter?.addEventListener('change', applyModelFilters);
    modelCostFilter?.addEventListener('change', applyModelFilters);
    modelReleaseFilter?.addEventListener('change', applyModelFilters);
    modelSort?.addEventListener('change', applyModelFilters);
    routingModeSelect?.addEventListener('change', () => {
        updateRoutingControls();
        schedulePreflight();
    });
    fixedProviderRefresh?.addEventListener('click', () => {
        if (runnerActive || !isFixedRouting()) return;

        schedulePreflight();
        loadSelectedEndpoints(true);
    });
    costInputTokens?.addEventListener('input', updateCostEstimate);
    costOutputTokens?.addEventListener('input', updateCostEstimate);
    goModelSearch?.addEventListener('input', applyGoModelFilters);
    goModelCostFilter?.addEventListener('change', applyGoModelFilters);
    goModelReleaseFilter?.addEventListener('change', applyGoModelFilters);
    goModelSort?.addEventListener('change', applyGoModelFilters);
    goCostInputTokens?.addEventListener('input', updateGoCostEstimate);
    goCostOutputTokens?.addEventListener('input', updateGoCostEstimate);
    concurrencySelect?.addEventListener('change', updateConfigSummary);
    preflightButton?.addEventListener('click', () => {
        window.clearTimeout(preflightTimer);
        void refreshPreflight();
    });
    form?.addEventListener('submit', (event) => {
        if (!submitButton || event.submitter?.hasAttribute('data-save-selection-group')) return;

        submitButton.disabled = true;
        submitButton.textContent = 'Başlatılıyor…';
    });
    stopForm?.addEventListener('submit', (event) => {
        if (!window.confirm('Aktif benchmark ve ona bağlı model süreçleri durdurulsun mu?')) {
            event.preventDefault();
        }
    });

    updateModePanels();
    updateCustomModelSelection();
    updateGoModelSelection();
    updateRoutingControls();
    applyModelFilters();
    applyGoModelFilters();
    updateConfigSummary();
    if (isFixedRouting()) schedulePreflight();
    window.setInterval(refreshState, 2500);
}

function initializePreviewComparison() {
    const canvases = [...document.querySelectorAll('[data-preview-canvas]')];

    if (canvases.length === 0) return;

    const resize = () => {
        canvases.forEach((canvas) => {
            const frame = canvas.querySelector('[data-preview-frame]');
            const viewportWidth = Number(canvas.dataset.viewportWidth || 1440);
            const scale = Math.min(1, canvas.clientWidth / viewportWidth);

            frame.style.width = `${viewportWidth}px`;
            frame.style.transform = `scale(${scale})`;
            canvas.style.height = `${900 * scale}px`;
            canvas.style.minHeight = '0';
        });
    };

    resize();
    new ResizeObserver(resize).observe(document.querySelector('[data-preview-comparison]'));
}

function initializeEvaluationPanel() {
    const panel = document.querySelector('[data-evaluation-panel]');

    if (!panel) return;

    const overlay = document.querySelector('[data-evaluation-overlay]');
    const toggles = [...document.querySelectorAll('[data-evaluation-toggle]')];
    const closeButton = panel.querySelector('[data-evaluation-close]');
    const scoreInputs = [...panel.querySelectorAll('[data-score-input]')];
    const criterionInputs = [...panel.querySelectorAll('[data-criterion-score]')];
    const average = panel.querySelector('[data-criteria-average]');

    const updateScores = () => {
        scoreInputs.forEach((input) => {
            const output = input.closest('label')?.querySelector('[data-score-value]');

            if (output) output.textContent = input.value;
        });

        if (!average || criterionInputs.length === 0) return;

        const total = criterionInputs.reduce((sum, input) => sum + Number(input.value), 0);
        average.textContent = (total / criterionInputs.length).toLocaleString('tr-TR', {
            minimumFractionDigits: 1,
            maximumFractionDigits: 1,
        });
    };

    const open = () => {
        panel.classList.remove('hidden');
        panel.classList.add('flex');
        overlay?.classList.remove('hidden');
        toggles.forEach((toggle) => toggle.setAttribute('aria-expanded', 'true'));
    };

    const close = () => {
        panel.classList.add('hidden');
        panel.classList.remove('flex');
        overlay?.classList.add('hidden');
        toggles.forEach((toggle) => toggle.setAttribute('aria-expanded', 'false'));
    };

    toggles.forEach((toggle) => toggle.addEventListener('click', open));
    closeButton?.addEventListener('click', close);
    overlay?.addEventListener('click', close);
    scoreInputs.forEach((input) => input.addEventListener('input', updateScores));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !panel.classList.contains('hidden')) close();
    });

    updateScores();

    if (panel.dataset.evaluationOpen === 'true') open();
}

let attemptRefreshTimeout;

export function initializeDestructiveForms() {
    const forms = [...document.querySelectorAll('[data-destructive-form]')];
    let submissionPending = false;

    forms.forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (submissionPending) {
                event.preventDefault();

                return;
            }

            const confirmation = form.querySelector('input[name="confirmation"]');
            const message = form.dataset.confirmMessage || 'Bu kalıcı silme işlemini onaylıyor musunuz?';

            if (!confirmation) {
                event.preventDefault();

                return;
            }

            confirmation.value = '';

            if (!window.confirm(message)) {
                event.preventDefault();

                return;
            }

            confirmation.value = '1';
            submissionPending = true;
            form.dataset.submitting = 'true';

            if (attemptRefreshTimeout !== undefined) {
                window.clearTimeout(attemptRefreshTimeout);
                attemptRefreshTimeout = undefined;
            }

            const submitter = event.submitter || form.querySelector('button[type="submit"]');

            if (submitter) submitter.textContent = 'Siliniyor…';

            forms.forEach((destructiveForm) => {
                destructiveForm.querySelectorAll('button[type="submit"]').forEach((button) => {
                    button.disabled = true;
                });
            });

            const feedback = document.createElement('p');
            feedback.className = 'mt-3 text-xs font-semibold text-[var(--muted)]';
            feedback.setAttribute('role', 'status');
            feedback.setAttribute('aria-live', 'polite');
            feedback.textContent = 'Dosyalar siliniyor; klasör büyüklüğüne göre sürebilir.';
            form.append(feedback);
        });
    });
}

const LIVE_REFRESH_MS = 3000;

function scheduleAttemptRefresh() {
    attemptRefreshTimeout = window.setTimeout(() => void refreshLiveRegions(), LIVE_REFRESH_MS);
}

/** True while the person is in the middle of something the update must not interrupt. */
function isPersonBusy() {
    const active = document.activeElement;

    return Boolean(document.querySelector('dialog[open]'))
        || Boolean(active && ['INPUT', 'TEXTAREA', 'SELECT'].includes(active.tagName));
}

/** Keeps a log at the bottom while new lines arrive, unless the person scrolled up to read. */
function scrollLogsToEnd(root, previous = new Map()) {
    root.querySelectorAll('pre[data-log-id]').forEach((log) => {
        const before = previous.get(log.dataset.logId);
        log.scrollTop = before === undefined || before.atEnd ? log.scrollHeight : before.scrollTop;
    });
}

/**
 * While a recovery attempt runs, re-renders only the live parts of the page (results and recovery) from a
 * background fetch. Scroll position, open panels, open dialogs and focused fields are left untouched.
 */
let resultSort = { key: null, direction: 'none' };
const appliedLiveMarkup = new Map();

/** Orders result rows by a numeric data-sort-* value; rows without a value always go last. */
export function sortResultRows(rows, key, direction) {
    const indexOf = (row) => Number(row.dataset.sortIndex ?? 0);
    if (!key || direction === 'none') return [...rows].sort((a, b) => indexOf(a) - indexOf(b));

    const valueOf = (row) => {
        const raw = row.dataset[`sort${key.charAt(0).toUpperCase()}${key.slice(1)}`];
        return raw === undefined || raw === '' ? null : Number(raw);
    };
    const factor = direction === 'descending' ? -1 : 1;

    return [...rows].sort((a, b) => {
        const left = valueOf(a);
        const right = valueOf(b);
        if (left === null || right === null) return left === right ? indexOf(a) - indexOf(b) : (left === null ? 1 : -1);

        return (left - right) * factor || indexOf(a) - indexOf(b);
    });
}

function applyResultSort(root = document) {
    root.querySelectorAll('[data-sort-column]').forEach((header) => {
        header.setAttribute('aria-sort', header.dataset.sortColumn === resultSort.key ? resultSort.direction : 'none');
    });
    document.querySelectorAll('[data-sort-key]').forEach((button) => {
        button.dataset.sortDirection = button.dataset.sortKey === resultSort.key ? resultSort.direction : 'none';
    });
    root.querySelectorAll('tbody[data-sortable-rows]').forEach((body) => {
        const rows = [...body.querySelectorAll(':scope > tr[data-sort-index]')];
        if (rows.length < 2) return;
        sortResultRows(rows, resultSort.key, resultSort.direction).forEach((row) => body.append(row));
    });
}

function initializeResultSorting() {
    if (!document.querySelector('[data-sort-key]')) return;

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-sort-key]');
        if (!button) return;

        const key = button.dataset.sortKey;
        const next = { none: 'ascending', ascending: 'descending', descending: 'none' };
        resultSort = { key, direction: resultSort.key === key ? next[resultSort.direction] : 'ascending' };
        applyResultSort();
    });
}

/** Finds the focused control inside a live region again after its markup is replaced. */
function liveFocusSelector(region) {
    const focused = document.activeElement;
    if (!focused || focused === region || !region.contains(focused)) return null;

    const escape = (value) => (window.CSS?.escape ? window.CSS.escape(value) : value.replace(/["\\]/g, '\\$&'));
    if (focused.id) return `#${escape(focused.id)}`;
    if (focused.dataset.openDetails) return `[data-open-details="${escape(focused.dataset.openDetails)}"]`;
    if (focused.dataset.dialogOpen) return `[data-dialog-open="${escape(focused.dataset.dialogOpen)}"]`;
    if (focused.dataset.sortKey) return `[data-sort-key="${escape(focused.dataset.sortKey)}"]`;
    if (focused.dataset.focusKey) return `[data-focus-key="${escape(focused.dataset.focusKey)}"]`;
    if (focused.dataset.logId) return `pre[data-log-id="${escape(focused.dataset.logId)}"]`;
    if (focused.tagName === 'SUMMARY' && focused.parentElement?.dataset.logId) return `details[data-log-id="${escape(focused.parentElement.dataset.logId)}"] > summary`;
    if (focused.tagName === 'SUMMARY' && focused.parentElement?.id) return `#${escape(focused.parentElement.id)} > summary`;
    if (focused.getAttribute('href')) return `a[href="${escape(focused.getAttribute('href'))}"]`;

    return null;
}

async function refreshLiveRegions() {
    attemptRefreshTimeout = undefined;
    if (document.querySelector('[data-destructive-form][data-submitting]')) return;
    if (isPersonBusy()) {
        scheduleAttemptRefresh();
        return;
    }

    let html;
    try {
        const response = await fetch(window.location.href, { headers: { Accept: 'text/html' }, cache: 'no-store' });
        if (!response.ok) throw new Error('refresh failed');
        html = await response.text();
    } catch {
        scheduleAttemptRefresh();
        return;
    }

    if (document.querySelector('[data-destructive-form][data-submitting]')) return;
    if (isPersonBusy()) {
        scheduleAttemptRefresh();
        return;
    }

    const next = new DOMParser().parseFromString(html, 'text/html');
    next.querySelectorAll('[data-live-region]').forEach((fresh) => {
        const current = document.querySelector(`[data-live-region="${fresh.dataset.liveRegion}"]`);
        if (!current) return;

        // Re-rendering identical markup would reset hover, selection and screen reader position for nothing.
        const markup = fresh.innerHTML;
        if (appliedLiveMarkup.get(fresh.dataset.liveRegion) === markup) return;
        appliedLiveMarkup.set(fresh.dataset.liveRegion, markup);

        const openPanels = new Map([...current.querySelectorAll('details[id], details[data-log-id]')]
            .map((panel) => [panel.id || panel.dataset.logId, panel.open]));
        const logs = new Map([...current.querySelectorAll('pre[data-log-id]')].map((log) => [log.dataset.logId, {
            scrollTop: log.scrollTop,
            atEnd: log.scrollTop + log.clientHeight >= log.scrollHeight - 8,
        }]));
        const focusSelector = liveFocusSelector(current);

        current.innerHTML = fresh.innerHTML;
        current.querySelectorAll('details[id], details[data-log-id]').forEach((panel) => {
            const wasOpen = openPanels.get(panel.id || panel.dataset.logId);
            if (wasOpen !== undefined) panel.open = wasOpen;
        });
        scrollLogsToEnd(current, logs);
        applyResultSort(current);
        if (focusSelector) current.querySelector(focusSelector)?.focus({ preventScroll: true });
    });

    if (next.querySelector('[data-attempt-refresh]')) scheduleAttemptRefresh();
}

function initializeAttemptRefresh() {
    scrollLogsToEnd(document);
    if (!document.querySelector('[data-attempt-refresh]')) return;

    scheduleAttemptRefresh();
}

const THEME_STORAGE_KEY = 'benchforge-theme';

/** Resolves a stored preference (light, dark or system) to the theme that should be shown. */
export function resolveTheme(preference, prefersDark) {
    if (preference === 'light' || preference === 'dark') return preference;
    return prefersDark ? 'dark' : 'light';
}

function initializeThemeSwitch() {
    const root = document.documentElement;
    if (!root?.dataset) return;
    const media = window.matchMedia?.('(prefers-color-scheme: dark)');
    const buttons = [...document.querySelectorAll('[data-theme-choice]')];

    const apply = (preference) => {
        root.dataset.themePreference = preference;
        root.dataset.theme = resolveTheme(preference, media?.matches ?? false);
        buttons.forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.themeChoice === preference)));
    };

    apply(root.dataset.themePreference || 'system');

    buttons.forEach((button) => button.addEventListener('click', () => {
        const preference = button.dataset.themeChoice;
        try {
            localStorage.setItem(THEME_STORAGE_KEY, preference);
        } catch {
            // Private windows can refuse storage; the choice still applies to this page.
        }
        apply(preference);
    }));

    media?.addEventListener?.('change', () => {
        if ((root.dataset.themePreference || 'system') === 'system') apply('system');
    });
}

/** Mirrors Str::slug for group names, so the runner can show the file name before saving. */
export function groupFileName(value) {
    const turkish = { ç: 'c', ğ: 'g', ı: 'i', i̇: 'i', ö: 'o', ş: 's', ü: 'u' };
    return value
        .toLocaleLowerCase('tr-TR')
        .replace(/[çğıöşü]|i̇/g, (letter) => turkish[letter] ?? letter)
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 63);
}

function initializeGroupNamePreview() {
    const input = document.querySelector('[data-group-name-input]');
    const preview = document.querySelector('[data-group-name-preview]');

    if (!input || !preview) return;

    const update = () => {
        const name = groupFileName(input.value);
        preview.classList.toggle('hidden', input.value.trim() === '');
        preview.textContent = name.length >= 2 ? `Kaydedilecek ad: ${name}` : 'Ad en az 2 harf veya rakam içermeli.';
    };

    input.addEventListener('input', update);
    update();
}

/** Native confirmation dialogs (data-dialog-open="id") and links that open a <details> section before jumping to it. */
function initializeDialogs() {
    // Delegated, so buttons inside regions re-rendered by the live refresh keep working.
    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!target || typeof target.closest !== 'function') return;

        const opener = target.closest('[data-dialog-open]');
        if (opener && !opener.disabled) {
            const dialog = document.getElementById(opener.dataset.dialogOpen);
            if (dialog && typeof dialog.showModal === 'function') dialog.showModal();
            return;
        }

        const closer = target.closest('dialog [data-dialog-close]');
        if (closer) {
            closer.closest('dialog')?.close();
            return;
        }

        const link = target.closest('[data-open-details]');
        if (link) {
            const details = document.getElementById(link.dataset.openDetails);
            if (!details) return;
            // Open and scroll without putting a #fragment in the address bar, so reloads do not jump back here.
            event.preventDefault();
            details.open = true;
            details.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
    const hash = window.location?.hash ?? '';
    if (hash.startsWith('#recovery-')) {
        const details = document.getElementById(hash.slice(1));
        if (details) details.open = true;
    }
}

function initializeModelGroups() {
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm)) event.preventDefault();
        });
    });

    document.querySelectorAll('[data-group-editor]').forEach((editor) => {
        const textarea = editor.querySelector('[data-group-models]');
        const picker = editor.querySelector('[data-group-model-picker]');
        const addButton = editor.querySelector('[data-group-model-add]');

        if (!textarea || !picker || !addButton) return;

        const addPickedModel = () => {
            const model = picker.value.trim();

            if (!model) return;

            const lines = textarea.value.split(/\r?\n/).map((line) => line.trim()).filter(Boolean);

            if (!lines.includes(model)) lines.push(model);

            textarea.value = lines.join('\n');
            picker.value = '';
            picker.focus();
        };

        addButton.addEventListener('click', addPickedModel);
        picker.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter') return;

            event.preventDefault();
            addPickedModel();
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initializeModelGroups();
    initializeDialogs();
    initializeGroupNamePreview();
    initializeRunner();
    initializePreparationCreate();
    initializePreparationShow();
    initializePreviewComparison();
    initializeEvaluationPanel();
    initializeDestructiveForms();
    initializeAttemptRefresh();
    initializeResultSorting();
    initializeThemeSwitch();
});
