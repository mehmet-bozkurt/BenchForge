@props([
    'options' => [],
    'limit' => \App\Services\ModelSelection::WORKFLOW_MODEL_LIMIT,
    'groupError' => null,
    'id' => 'model-picker',
])

@php
    $limit = max(1, (int) $limit);
    $supportedProviders = \App\Services\ModelSelection::WORKFLOW_PROVIDERS;
    $providerLabels = ['openrouter' => 'OpenRouter', 'opencode-go' => 'OpenCode Go'];
    $openRouterModels = $options['openRouterModels'] ?? collect();
    $goModels = $options['openCodeGoModels'] ?? collect();
    $catalog = $options['catalog'] ?? ['count' => $openRouterModels->count(), 'lastSyncedAt' => null];
    $goCatalog = $options['goCatalog'] ?? ['count' => $goModels->count(), 'lastSyncedAt' => null];
    $catalogAuthors = $openRouterModels->pluck('author')->filter()->unique()->sort()->values();
    $groups = collect($options['configs'] ?? [])->map(function (array $group) use ($limit, $supportedProviders): array {
        $unsupportedProviders = array_values(array_diff($group['providers'] ?? [], $supportedProviders));

        return [
            ...$group,
            'unsupportedProviders' => $unsupportedProviders,
            'selectable' => $group['modelCount'] <= $limit && $unsupportedProviders === [],
        ];
    });

    // Old input can be anything a client posted; only well-formed values are used.
    $oldMode = old('model_mode', 'group');
    $selectedMode = is_string($oldMode) && in_array($oldMode, \App\Services\ModelSelection::MODES, true) ? $oldMode : 'group';
    $oldConfig = old('config');
    $selectedConfig = is_string($oldConfig) ? $oldConfig : ($groups->firstWhere('selectable', true)['value'] ?? '');
    $selectedModels = array_values(array_filter((array) old('models', []), 'is_string'));
    $selectedGoModels = array_values(array_filter((array) old('go_models', []), 'is_string'));
    $oldRouting = old('routing_mode', 'auto');
    $selectedRouting = is_string($oldRouting) && in_array($oldRouting, \App\Services\OpenRouterProviderRouting::MODES, true) ? $oldRouting : 'auto';
    $selectedFixedProviders = array_filter(
        (array) old('fixed_providers', []),
        fn (mixed $slug, mixed $modelId): bool => is_string($slug) && is_string($modelId),
        ARRAY_FILTER_USE_BOTH,
    );
    $catalogs = [
        'openrouter' => ['models' => $openRouterModels, 'selected' => $selectedModels, 'catalog' => $catalog],
        'opencode-go' => ['models' => $goModels, 'selected' => $selectedGoModels, 'catalog' => $goCatalog],
    ];
@endphp

<fieldset
    {{ $attributes->merge(['class' => 'grid min-w-0 gap-3']) }}
    id="{{ $id }}"
    data-model-picker
    data-model-limit="{{ $limit }}"
    data-provider-endpoints-url="{{ route('runner.openrouter.endpoints', absolute: false) }}"
>
    <legend class="text-xs font-extrabold text-[var(--ink)]">Modeller <span class="font-semibold text-[var(--muted)]">· en fazla {{ $limit }}</span></legend>
    <p class="text-[11px] leading-5 text-[var(--muted)]">Kayıtlı bir grup ya da katalogdan 1–{{ $limit }} model seçin. Her model iş akışının tamamını ayrı ayrı çalıştırır.</p>

    <div class="grid grid-cols-3 gap-2 rounded-xl bg-[var(--surface-subtle)] p-1">
        <label class="cursor-pointer rounded-lg px-3 py-2 text-center text-xs font-extrabold has-checked:bg-[var(--surface)] has-checked:text-[var(--forest)] has-checked:shadow-sm">
            <input class="sr-only" type="radio" name="model_mode" value="group" data-model-picker-mode @checked($selectedMode === 'group')>
            Kayıtlı grup
        </label>
        <label class="cursor-pointer rounded-lg px-3 py-2 text-center text-xs font-extrabold has-checked:bg-[var(--surface)] has-checked:text-[var(--forest)] has-checked:shadow-sm">
            <input class="sr-only" type="radio" name="model_mode" value="custom" data-model-picker-mode @checked($selectedMode === 'custom')>
            OpenRouter kataloğu
        </label>
        <label class="cursor-pointer rounded-lg px-3 py-2 text-center text-xs font-extrabold has-checked:bg-[var(--surface)] has-checked:text-[var(--violet)] has-checked:shadow-sm">
            <input class="sr-only" type="radio" name="model_mode" value="opencode_go" data-model-picker-mode @checked($selectedMode === 'opencode_go')>
            OpenCode Go
        </label>
    </div>

    <div @class(['grid gap-2', 'hidden' => $selectedMode !== 'group']) data-model-picker-panel="group">
        @if ($groupError)
            <p class="rounded-xl border border-[var(--red-line)] bg-[var(--red-soft)] px-3 py-2.5 text-[11px] leading-5 text-[var(--red)]" role="alert">
                <strong class="font-extrabold">Kayıtlı model grupları okunamadı.</strong>
                <span class="block break-words">{{ $groupError }}</span>
            </p>
        @elseif ($groups->isEmpty())
            <p class="rounded-xl border border-dashed border-[var(--line)] p-4 text-xs leading-5 text-[var(--muted)]">Henüz kayıtlı model grubu yok. Katalogdan model seçin ya da <a class="font-extrabold text-[var(--forest)] underline" href="{{ route('model-groups.index') }}">Model Grupları</a> sayfasından bir grup oluşturun.</p>
        @else
            <label class="sr-only" for="{{ $id }}-config">Model grubu</label>
            <select class="h-11 w-full rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3.5 text-sm font-semibold outline-none transition focus:border-[var(--forest)] focus:ring-3 focus:ring-[var(--mint-soft)] disabled:cursor-not-allowed disabled:bg-[var(--surface-subtle)]" id="{{ $id }}-config" name="config" data-model-picker-config>
                @foreach ($groups as $group)
                    <option
                        value="{{ $group['value'] }}"
                        data-model-count="{{ $group['modelCount'] }}"
                        data-providers="{{ implode(',', $group['providers'] ?? []) }}"
                        data-selectable="{{ $group['selectable'] ? 'true' : 'false' }}"
                        @selected($selectedConfig === $group['value'])
                        @disabled(! $group['selectable'])
                    >{{ $group['label'] }} · {{ $group['modelCount'] }} model{{ $group['modelCount'] > $limit ? ' · en fazla '.$limit.' model seçilebilir' : '' }}{{ $group['modelCount'] <= $limit && $group['unsupportedProviders'] !== [] ? ' · desteklenmeyen sağlayıcı: '.implode(', ', $group['unsupportedProviders']) : '' }}</option>
                @endforeach
            </select>
            <p class="text-[10px] leading-4 text-[var(--muted)]">
                {{ $limit }} modelden büyük gruplar ve OpenRouter ile OpenCode Go dışındaki sağlayıcıları içeren gruplar seçilemez. Gruplardaki OpenRouter modelleri otomatik sağlayıcı yönlendirmesiyle çalışır. Grupları <a class="font-extrabold text-[var(--forest)] underline" href="{{ route('model-groups.index') }}">Model Grupları</a> sayfasından yönetin.
            </p>
        @endif
    </div>

    @foreach ($catalogs as $provider => $catalogData)
        @php
            $isGo = $provider === 'opencode-go';
            $panelMode = $isGo ? 'opencode_go' : 'custom';
            $focusBorder = $isGo ? 'focus:border-[var(--violet)]' : 'focus:border-[var(--forest)]';
        @endphp
        <div @class(['grid gap-3', 'hidden' => $selectedMode !== $panelMode]) data-model-picker-panel="{{ $panelMode }}">
            <div class="flex items-center gap-2">
                <input class="h-10 min-w-0 flex-1 rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3.5 text-sm outline-none {{ $focusBorder }}" type="search" placeholder="{{ $isGo ? 'OpenCode Go modeli ara…' : 'Model adı veya yayıncı ara…' }}" aria-label="{{ $providerLabels[$provider] }} modeli ara" data-model-picker-search="{{ $provider }}">
                <span class="whitespace-nowrap text-[10px] font-extrabold text-[var(--muted)]"><span data-model-picker-visible="{{ $provider }}">{{ $catalogData['models']->count() }}</span> sonuç · <span data-model-picker-selected="{{ $provider }}">{{ count($catalogData['selected']) }}</span>/{{ $limit }} seçili</span>
            </div>
            <div @class(['grid gap-2', 'sm:grid-cols-2 lg:grid-cols-4' => ! $isGo, 'sm:grid-cols-3' => $isGo])>
                @unless ($isGo)
                    <label class="grid gap-1 text-[9px] font-extrabold tracking-[0.07em] text-[var(--muted)] uppercase">
                        Model yayıncısı
                        <select class="h-9 rounded-lg border border-[var(--line)] bg-[var(--surface)] px-2.5 text-[11px] font-semibold normal-case outline-none focus:border-[var(--forest)]" data-model-picker-author="{{ $provider }}">
                            <option value="">Tümü</option>
                            @foreach ($catalogAuthors as $author)
                                <option value="{{ $author }}">{{ $author }}</option>
                            @endforeach
                        </select>
                    </label>
                @endunless
                <label class="grid gap-1 text-[9px] font-extrabold tracking-[0.07em] text-[var(--muted)] uppercase">
                    Ücret
                    <select class="h-9 rounded-lg border border-[var(--line)] bg-[var(--surface)] px-2.5 text-[11px] font-semibold normal-case outline-none {{ $focusBorder }}" data-model-picker-cost="{{ $provider }}">
                        <option value="all">Tümü</option>
                        <option value="free">Yalnızca ücretsiz</option>
                        <option value="paid">Yalnızca ücretli</option>
                    </select>
                </label>
                <label class="grid gap-1 text-[9px] font-extrabold tracking-[0.07em] text-[var(--muted)] uppercase">
                    Sürüm
                    <select class="h-9 rounded-lg border border-[var(--line)] bg-[var(--surface)] px-2.5 text-[11px] font-semibold normal-case outline-none {{ $focusBorder }}" data-model-picker-release="{{ $provider }}">
                        <option value="all">Tümü</option>
                        <option value="stable">Kararlı</option>
                        <option value="preview">Preview</option>
                    </select>
                </label>
                <label class="grid gap-1 text-[9px] font-extrabold tracking-[0.07em] text-[var(--muted)] uppercase">
                    Sıralama
                    <select class="h-9 rounded-lg border border-[var(--line)] bg-[var(--surface)] px-2.5 text-[11px] font-semibold normal-case outline-none {{ $focusBorder }}" data-model-picker-sort="{{ $provider }}">
                        <option value="newest">En yeni</option>
                        <option value="name">Ada göre</option>
                        <option value="prompt-price">{{ $isGo ? 'Girdi maliyeti' : 'Girdi fiyatı' }}</option>
                        <option value="completion-price">{{ $isGo ? 'Çıktı maliyeti' : 'Çıktı fiyatı' }}</option>
                        <option value="context">Bağlam uzunluğu</option>
                    </select>
                </label>
            </div>

            @if ($isGo)
                <p class="rounded-lg border border-[var(--violet-line)] bg-[var(--violet-soft)] px-3 py-2 text-[10px] leading-4 text-[var(--violet)]">
                    OpenCode Go aboneliğiniz kullanılır. Gösterilen maliyet ek ücret değil, aboneliğinizdeki kullanım limitinin yaklaşık dolar karşılığıdır.
                </p>
            @else
                <div class="grid gap-2 rounded-xl border border-[var(--amber-line)] bg-[var(--amber-soft)] p-3">
                    <label class="grid gap-1 text-xs font-extrabold text-[var(--ink)]" for="{{ $id }}-routing">OpenRouter sağlayıcı yönlendirmesi</label>
                    <select class="h-10 w-full rounded-lg border border-[var(--amber-line)] bg-[var(--surface)] px-3 text-xs font-semibold outline-none focus:border-[var(--forest)] disabled:cursor-not-allowed disabled:opacity-50" id="{{ $id }}-routing" name="routing_mode" data-model-picker-routing>
                        <option value="auto" @selected($selectedRouting === 'auto')>Otomatik · OpenRouter seçsin</option>
                        <option value="price" @selected($selectedRouting === 'price')>Düşük fiyat öncelikli</option>
                        <option value="throughput" @selected($selectedRouting === 'throughput')>Yüksek hız öncelikli</option>
                        <option value="fixed" @selected($selectedRouting === 'fixed')>Sabit sağlayıcı · model başına seç</option>
                    </select>
                    <p class="text-[10px] leading-4 text-[var(--amber-ink)]" data-model-picker-routing-explanation>
                        Yalnız OpenRouter kataloğundan seçilen modeller için geçerlidir. Veri toplama izni verilmez; parametre uyumu aranır. Katalog güncel değilse <a class="font-extrabold underline" href="{{ route('runner.create') }}">Yeni Benchmark</a> ekranından yenileyin.
                    </p>
                </div>
            @endif

            <div class="max-h-80 space-y-2 overflow-y-auto pr-1" data-model-picker-catalog="{{ $provider }}">
                @forelse ($catalogData['models'] as $model)
                    <label
                        @class([
                            'block cursor-pointer rounded-xl border border-[var(--line)] bg-[var(--surface)] p-3 transition',
                            'has-checked:border-[var(--violet)] has-checked:bg-[var(--violet-soft)]' => $isGo,
                            'has-checked:border-[var(--forest)] has-checked:bg-[var(--mint-soft)]' => ! $isGo,
                        ])
                        data-model-picker-card="{{ $provider }}"
                        data-model-id="{{ $model->model_id }}"
                        data-model-name="{{ $model->name }}"
                        data-model-search-text="{{ Str::lower($model->name.' '.$model->model_id.' '.($isGo ? $model->family : $model->author)) }}"
                        data-model-author="{{ $isGo ? '' : $model->author }}"
                        data-model-free="{{ $model->is_free ? 'true' : 'false' }}"
                        data-model-preview="{{ $model->is_preview ? 'true' : 'false' }}"
                        data-model-context="{{ $model->context_length ?? 0 }}"
                        data-model-prompt-price="{{ $isGo ? ((float) ($model->input_cost ?? 0)) / 1000000 : ($model->prompt_price ?? 0) }}"
                        data-model-completion-price="{{ $isGo ? ((float) ($model->output_cost ?? 0)) / 1000000 : ($model->completion_price ?? 0) }}"
                        data-model-released-at="{{ $model->released_at?->timestamp ?? 0 }}"
                        data-model-fixed-provider="{{ $isGo ? '' : ($selectedFixedProviders[$model->model_id] ?? '') }}"
                    >
                        <span class="flex items-start gap-3">
                            <input class="mt-1 rounded border-[var(--line)]" type="checkbox" name="{{ $isGo ? 'go_models[]' : 'models[]' }}" value="{{ $model->model_id }}" data-model-picker-model="{{ $provider }}" @checked(in_array($model->model_id, $catalogData['selected'], true))>
                            <span class="min-w-0 flex-1">
                                <span class="flex flex-wrap items-center gap-1.5">
                                    <strong class="truncate text-xs">{{ $model->name }}</strong>
                                    @if ($model->is_free)<span class="rounded bg-[var(--success-soft)] px-1.5 py-0.5 text-[8px] font-black text-[var(--success)]">ÜCRETSİZ</span>@endif
                                    @if ($model->is_preview)<span class="rounded bg-[var(--violet-soft)] px-1.5 py-0.5 text-[8px] font-black text-[var(--violet)]">PREVIEW</span>@endif
                                </span>
                                <span class="mt-1 block break-all font-mono text-[9px] text-[var(--muted)]">{{ $isGo ? 'opencode-go/' : '' }}{{ $model->model_id }}</span>
                                <span class="mt-2 block text-[9px] text-[var(--muted)]">
                                    {{ $model->context_length ? number_format($model->context_length, 0, ',', '.') : '—' }} bağlam
                                    @if ($isGo)
                                        · Girdi ${{ number_format((float) $model->input_cost, 3) }}/M
                                        · Çıktı ${{ number_format((float) $model->output_cost, 3) }}/M
                                    @else
                                        · Girdi ${{ number_format(((float) $model->prompt_price) * 1000000, 2) }}/M
                                        · Çıktı ${{ number_format(((float) $model->completion_price) * 1000000, 2) }}/M
                                    @endif
                                </span>
                            </span>
                        </span>
                    </label>
                @empty
                    <div class="rounded-xl border border-dashed border-[var(--line)] p-5 text-center text-xs leading-5 text-[var(--muted)]">
                        Henüz kullanılabilir {{ $providerLabels[$provider] }} modeli yok. Kataloğu <a class="font-extrabold text-[var(--forest)] underline" href="{{ route('runner.create') }}">Yeni Benchmark</a> ekranından yenileyin.
                    </div>
                @endforelse
                <div class="hidden rounded-xl border border-dashed border-[var(--line)] p-5 text-center text-xs leading-5 text-[var(--muted)]" data-model-picker-empty="{{ $provider }}">
                    Bu filtrelerle eşleşen model bulunamadı.
                </div>
            </div>
            <p class="text-[10px] leading-4 text-[var(--muted)]">
                {{ $catalogData['catalog']['count'] ?? $catalogData['models']->count() }} katalog modeli · son eşitleme {{ ($catalogData['catalog']['lastSyncedAt'] ?? null)?->locale('tr')->translatedFormat('j M Y · H:i') ?? 'bilinmiyor' }}
            </p>

            @unless ($isGo)
                <section @class(['grid gap-3 rounded-xl border border-[var(--line)] bg-[var(--surface-subtle)] p-4', 'hidden' => $selectedRouting !== 'fixed']) data-model-picker-fixed-panel aria-labelledby="{{ $id }}-fixed-title">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <h3 class="text-xs font-black" id="{{ $id }}-fixed-title">Model başına sabit sağlayıcı</h3>
                            <p class="mt-1 text-[10px] leading-4 text-[var(--muted)]">Her seçili model için bir OpenRouter uç noktası seçin. Seçim başlatmadan önce yeniden doğrulanır; anlık kullanılabilirlik veya veri politikası garantisi değildir.</p>
                        </div>
                        <button class="rounded-lg border border-[var(--line)] bg-[var(--surface)] px-2.5 py-1.5 text-[10px] font-extrabold text-[var(--forest)] disabled:cursor-not-allowed disabled:opacity-50" type="button" data-model-picker-fixed-refresh>Sağlayıcıları yenile</button>
                    </div>
                    <div class="grid gap-3" data-model-picker-fixed-list aria-live="polite">
                        @foreach ($selectedFixedProviders as $modelId => $slug)
                            @if (in_array($modelId, $selectedModels, true))
                                <input type="hidden" name="fixed_providers[{{ $modelId }}]" value="{{ $slug }}">
                            @endif
                        @endforeach
                    </div>
                </section>
            @endunless
        </div>
    @endforeach

    <p class="text-xs font-semibold text-[var(--red)]" role="status" aria-live="polite" data-model-picker-message></p>
</fieldset>
