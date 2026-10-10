const statusLabels = {
    pending: 'Bekliyor',
    starting: 'Başlatılıyor',
    running: 'Üretiliyor',
    stopping: 'Durduruluyor',
    completed: 'Tamamlandı',
    partial: 'Kısmen tamamlandı',
    failed: 'Başarısız',
    stopped: 'Durduruldu',
};

function statusTone(status) {
    if (status === 'completed') return 'pass';
    if (status === 'failed') return 'fail';
    if (['starting', 'running', 'stopping'].includes(status)) return 'running';

    return 'unknown';
}

export function preparationSourceProblem(value, file) {
    const text = String(value || '').trim();

    if (text && file) return 'Şartname metni veya TXT dosyasından yalnız birini kullanın.';
    if (!text && !file) return 'Şartname metni yazın veya UTF-8 TXT dosyası seçin.';
    if (text && text.length < 50) return 'Şartname metni en az 50 karakter olmalı.';
    if (text.length > 100_000) return 'Şartname metni 100.000 karakteri geçemez.';
    if (file && file.size > 1_048_576) return 'TXT dosyası 1 MB sınırını aşıyor.';

    return '';
}

export function matchesPreparationModel(query, searchText) {
    const needle = String(query || '').trim().toLocaleLowerCase('tr-TR');

    return needle === '' || String(searchText || '').toLocaleLowerCase('tr-TR').includes(needle);
}

export function initializePreparationCreate() {
    const form = document.querySelector('[data-preparation-create]');

    if (!form) return;

    const providerInputs = [...form.querySelectorAll('[data-preparation-provider]')];
    const modelInputs = [...form.querySelectorAll('[data-preparation-model]')];
    const specification = form.querySelector('[data-preparation-specification]');
    const specificationFile = form.querySelector('[data-preparation-file]');
    const sourceError = form.querySelector('[data-preparation-source-error]');
    const modelError = form.querySelector('[data-preparation-model-error]');
    const submitButton = form.querySelector('[data-preparation-submit]');
    const searches = [...form.querySelectorAll('[data-preparation-search]')];
    const modelCards = [...form.querySelectorAll('[data-preparation-model-card]')];
    const selectedProvider = () => providerInputs.find((input) => input.checked)?.value || 'openrouter';
    const selectedModels = () => modelInputs.filter((input) => input.dataset.preparationModel === selectedProvider() && input.checked);

    function updateModels() {
        const provider = selectedProvider();
        const counts = new Map(['openrouter', 'opencode-go'].map((name) => [name,
            modelInputs.filter((input) => input.dataset.preparationModel === name && input.checked).length,
        ]));

        form.querySelectorAll('[data-preparation-catalog]').forEach((catalog) => {
            catalog.classList.toggle('hidden', catalog.dataset.preparationCatalog !== provider);
        });

        modelInputs.forEach((input) => {
            const selectedInCatalog = counts.get(input.dataset.preparationModel) || 0;
            input.disabled = input.dataset.preparationModel !== provider || (selectedInCatalog >= 5 && !input.checked);
        });

        form.querySelectorAll('[data-preparation-count]').forEach((counter) => {
            counter.textContent = String(counts.get(counter.dataset.preparationCount) || 0);
        });
        if (selectedModels().length > 0 && modelError) modelError.textContent = '';
    }

    function applySearch(provider) {
        const query = searches.find((input) => input.dataset.preparationSearch === provider)?.value || '';
        const cards = modelCards.filter((card) => card.dataset.preparationModelCard === provider);
        let visible = 0;

        cards.forEach((card) => {
            const matches = matchesPreparationModel(query, card.dataset.preparationSearchText);
            card.classList.toggle('hidden', !matches);
            if (matches) visible += 1;
        });
        const empty = form.querySelector(`[data-preparation-search-empty="${provider}"]`);
        empty?.classList.toggle('hidden', visible !== 0 || cards.length === 0);
    }

    function validateSource(showError = true) {
        const message = preparationSourceProblem(specification?.value, specificationFile?.files?.[0]);

        if (specification) specification.setCustomValidity(message);
        if (sourceError) {
            sourceError.textContent = showError ? message : '';
            sourceError.classList.toggle('hidden', !showError || message === '');
        }

        return message === '';
    }

    providerInputs.forEach((input) => input.addEventListener('change', updateModels));
    searches.forEach((input) => input.addEventListener('input', () => applySearch(input.dataset.preparationSearch)));
    modelInputs.forEach((input) => input.addEventListener('change', () => {
        if (selectedModels().length > 5) input.checked = false;
        updateModels();
    }));
    specification?.addEventListener('input', () => validateSource());
    specificationFile?.addEventListener('change', () => validateSource());
    form.addEventListener('submit', (event) => {
        const sourceValid = validateSource();
        const modelValid = selectedModels().length > 0;

        if (modelError) modelError.textContent = modelValid ? '' : 'En az bir model seçin.';

        if (!sourceValid || !modelValid) {
            event.preventDefault();
            if (!sourceValid) specification?.focus();
            else modelInputs.find((input) => !input.disabled)?.focus();

            return;
        }

        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Başlatılıyor…';
        }
    });

    updateModels();
    searches.forEach((input) => applySearch(input.dataset.preparationSearch));
    validateSource(false);
}

export function initializePreparationShow() {
    const panel = document.querySelector('[data-preparation-show]');

    if (!panel) return;

    const comparison = panel.querySelector('[data-preparation-compare]');
    const leftSelect = comparison?.querySelector('[data-preparation-left]');
    const rightSelect = comparison?.querySelector('[data-preparation-right]');
    const sectionSelect = comparison?.querySelector('[data-preparation-section]');
    const packageElements = new Map([...panel.querySelectorAll('[data-preparation-package]')]
        .map((element) => [element.dataset.preparationPackage, element]));
    const refreshNotice = panel.querySelector('[data-preparation-refresh-notice]');
    const copyStatus = panel.querySelector('[data-preparation-copy-status]');
    let active = panel.dataset.active === 'true';

    function renderSide(side, packageId) {
        const sideElement = comparison?.querySelector(`[data-preparation-side="${side}"]`);

        if (!sideElement) return;

        const packageElement = packageElements.get(packageId);
        sideElement.classList.toggle('hidden', !packageElement);
        if (!packageElement) return;

        const status = packageElement.dataset.preparationPackageStatus;
        const documentReady = packageElement.dataset.preparationDocumentReady === 'true';
        const section = sectionSelect?.value || 'startup_prompt';
        const source = packageElement.querySelector(`[data-preparation-source-section="${section}"]`);
        const content = documentReady ? (source?.textContent || 'Bu bölüm boş.')
            : (status === 'completed' ? 'Yeni belge hazır. Görmek için Sonuçları yenile bağlantısını kullanın.' : 'Paket henüz hazır değil.');
        const download = sideElement.querySelector('[data-preparation-side-download]');
        const copy = sideElement.querySelector('[data-preparation-copy]');

        sideElement.querySelector('[data-preparation-side-name]').textContent = packageElement.dataset.preparationPackageName || '—';
        sideElement.querySelector('[data-preparation-side-status]').textContent = statusLabels[status] || 'Bilinmiyor';
        sideElement.querySelector('[data-preparation-side-content]').textContent = content;
        if (copy) copy.disabled = !documentReady;
        if (download) {
            const url = new URL(packageElement.dataset.preparationDownloadBase, window.location.origin);
            url.searchParams.set('section', section);
            download.href = url.toString();
            download.classList.toggle('hidden', !documentReady);
        }
    }

    function renderComparison() {
        renderSide('left', leftSelect?.value);
        renderSide('right', rightSelect?.value);
        comparison?.querySelector('[data-preparation-compare-grid]')?.classList.toggle('xl:grid-cols-2', Boolean(rightSelect?.value));
    }

    async function copyText(side) {
        const content = comparison?.querySelector(`[data-preparation-side="${side}"] [data-preparation-side-content]`)?.textContent || '';

        try {
            let copied = false;

            if (navigator.clipboard?.writeText) {
                try {
                    await navigator.clipboard.writeText(content);
                    copied = true;
                } catch {
                    // A non-secure local domain may expose but reject the Clipboard API.
                }
            }

            if (!copied) {
                const fallback = document.createElement('textarea');
                fallback.value = content;
                fallback.style.position = 'fixed';
                fallback.style.opacity = '0';
                document.body.append(fallback);
                try {
                    fallback.select();
                    if (!document.execCommand('copy')) throw new Error('Copy unavailable');
                    copied = true;
                } finally {
                    fallback.remove();
                }
            }
            if (copyStatus && copied) copyStatus.textContent = 'Bölüm metni panoya kopyalandı.';
        } catch {
            if (copyStatus) copyStatus.textContent = 'Kopyalanamadı. Metni seçip elle kopyalayabilirsiniz.';
        }
    }

    [leftSelect, rightSelect, sectionSelect].forEach((select) => select?.addEventListener('change', () => {
        renderComparison();
        if (copyStatus) copyStatus.textContent = '';
    }));
    comparison?.querySelectorAll('[data-preparation-copy]').forEach((button) => {
        button.addEventListener('click', () => void copyText(button.dataset.preparationCopy));
    });
    renderComparison();

    const stopForm = panel.querySelector('[data-preparation-stop]');
    stopForm?.addEventListener('submit', (event) => {
        if (!window.confirm('Bu hazırlık üretimini durdurmak istiyor musunuz? Tamamlanan paketler korunur.')) {
            event.preventDefault();

            return;
        }

        const button = stopForm.querySelector('button[type="submit"]');
        if (button) {
            button.disabled = true;
            button.textContent = 'Durduruluyor…';
        }
    });

    async function pollStatus() {
        if (!active) return;

        try {
            const response = await fetch(panel.dataset.statusUrl, {
                cache: 'no-store',
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) throw new Error('Status unavailable');
            const state = await response.json();
            const badge = panel.querySelector('[data-preparation-comparison-status]');
            const previousStatus = panel.dataset.currentStatus;

            if (badge) {
                badge.classList.remove('status-pass', 'status-fail', 'status-running', 'status-unknown');
                badge.classList.add(`status-${statusTone(state.status)}`);
            }
            panel.querySelector('[data-preparation-comparison-status-label]').textContent = statusLabels[state.status] || 'Bilinmiyor';
            panel.querySelector('[data-preparation-message]').textContent = state.message || '';
            panel.querySelector('[data-preparation-progress]').textContent = `${state.completedModels ?? 0}/${state.totalModels ?? 0}`;

            let newResult = previousStatus && previousStatus !== state.status && !state.active;

            (state.packages || []).forEach((item) => {
                const element = packageElements.get(String(item.id));
                if (!element) return;

                const prior = element.dataset.preparationPackageStatus;
                if (prior !== item.status && ['completed', 'failed', 'stopped'].includes(item.status)) newResult = true;
                element.dataset.preparationPackageStatus = item.status;

                const itemBadge = element.querySelector('[data-preparation-package-badge]');
                if (itemBadge) {
                    itemBadge.classList.remove('status-pass', 'status-fail', 'status-running', 'status-unknown');
                    itemBadge.classList.add(`status-${statusTone(item.status)}`);
                }
                element.querySelector('[data-preparation-package-status-label]').textContent = statusLabels[item.status] || 'Bilinmiyor';
                element.querySelector('[data-preparation-duration]').textContent = item.durationSeconds == null ? '—' : `${Number(item.durationSeconds).toLocaleString('tr-TR', { maximumFractionDigits: 1 })} sn`;
                element.querySelector('[data-preparation-cost]').textContent = item.costUsd == null
                    ? 'Veri yok'
                    : `$${Number(item.costUsd).toLocaleString('en-US', { minimumFractionDigits: 4, maximumFractionDigits: 8 })}`;
                const inputTokens = item.inputTokens == null ? '—' : Number(item.inputTokens).toLocaleString('tr-TR');
                const outputTokens = item.outputTokens == null ? '—' : Number(item.outputTokens).toLocaleString('tr-TR');
                element.querySelector('[data-preparation-tokens]').textContent = `${inputTokens} girdi / ${outputTokens} çıktı`;
                const error = element.querySelector('[data-preparation-package-error]');
                if (error) {
                    error.textContent = item.errorMessage || '';
                    error.classList.toggle('hidden', !item.errorMessage);
                }
            });

            if (newResult && refreshNotice) refreshNotice.classList.remove('hidden');
            panel.dataset.currentStatus = state.status;
            active = Boolean(state.active);
            panel.dataset.active = String(active);
            if (stopForm) stopForm.classList.toggle('hidden', !active);
            renderComparison();
        } catch {
            // A temporary local connection issue leaves the current view intact.
        } finally {
            if (active) window.setTimeout(() => void pollStatus(), 2500);
        }
    }

    if (active) window.setTimeout(() => void pollStatus(), 2500);
}
