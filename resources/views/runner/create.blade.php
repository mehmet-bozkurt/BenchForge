@extends('layouts.app')

@section('content')
@php
    $statusLabels = [
        'idle' => 'Hazır',
        'starting' => 'Başlatılıyor',
        'running' => 'Çalışıyor',
        'stopping' => 'Durduruluyor',
        'completed' => 'Koşu tamamlandı',
        'failed' => 'Koşucu hatası',
        'stopped' => 'Durduruldu',
    ];
    $statusTone = match ($state['status']) {
        'completed' => 'pass',
        'failed' => 'fail',
        'starting', 'running', 'stopping' => 'running',
        default => 'unknown',
    };
    $active = $state['active'];
    $outcome = $state['outcome'];
    $outcomeTone = match ($outcome['status']) {
        'pass' => 'pass',
        'fail' => 'fail',
        'pending' => 'running',
        default => 'unknown',
    };
    $defaultPrompt = old('prompt', $options['prompts'][0]['value'] ?? '');
    $defaultPromptMode = old('prompt_mode', 'saved');
    $defaultConfig = old('config', $options['configs'][0]['value'] ?? '');
    $defaultModelMode = old('model_mode', 'group');
    $defaultRoutingMode = in_array(old('routing_mode', 'auto'), ['auto', 'price', 'throughput', 'fixed'], true) ? old('routing_mode', 'auto') : 'auto';
    $selectedFixedProviders = array_filter((array) old('fixed_providers', []), 'is_string');
    $selectedModels = array_values((array) old('models', []));
    $selectedGoModels = array_values((array) old('go_models', []));
    $defaultMaxParallel = (int) old('max_parallel', 2);
    $defaultRoutes = old('routes', implode("\n", $options['suggestedRoutes']));
    $selectedConfig = collect($options['configs'])->firstWhere('value', $defaultConfig);
    $openRouterModels = $options['openRouterModels'] ?? collect();
    $catalogAuthors = $openRouterModels->pluck('author')->filter()->unique()->sort()->values();
    $catalog = $options['catalog'] ?? ['count' => 0, 'lastSyncedAt' => null];
    $openCodeGoModels = $options['openCodeGoModels'] ?? collect();
    $goCatalog = $options['goCatalog'] ?? ['count' => 0, 'lastSyncedAt' => null];
    $connections = $connections ?? [
        'checkedAt' => null,
        'cli' => ['status' => 'unknown', 'label' => 'OpenCode CLI', 'message' => 'Durum alınamadı.'],
        'providers' => [
            'openrouter' => ['status' => 'unknown', 'label' => 'OpenRouter', 'message' => 'Durum alınamadı.'],
            'opencode-go' => ['status' => 'unknown', 'label' => 'OpenCode Go', 'message' => 'Durum alınamadı.'],
        ],
    ];
    $connectionItems = [
        $connections['cli'] + ['meta' => 'Yerel çalıştırıcı'],
        $connections['providers']['openrouter'] + ['meta' => $catalog['count'].' katalog modeli'],
        $connections['providers']['opencode-go'] + ['meta' => $goCatalog['count'].' katalog modeli'],
    ];
    $connectionLabels = [
        'pass' => 'Hazır',
        'fail' => 'Bağlantı yok',
        'unknown' => 'Kontrol edilemedi',
    ];
    $preflightReady = $preflight['ready'] ?? false;
@endphp

<div
    class="content-frame"
    data-runner-panel
    data-status-url="{{ route('runner.status') }}"
    data-preflight-url="{{ route('runner.preflight') }}"
    data-provider-endpoints-url="{{ route('runner.openrouter.endpoints') }}"
    data-preflight-ready="{{ $preflightReady ? 'true' : 'false' }}"
    data-runs-url="{{ url('/runs') }}"
    data-model-limit="{{ \App\Services\BenchmarkRunner::CUSTOM_MODEL_LIMIT }}"
>
    <header class="mb-8 flex flex-col gap-4 min-[1700px]:flex-row min-[1700px]:items-end min-[1700px]:justify-between">
        <div>
            <p class="eyebrow">Yerel koşu yöneticisi</p>
            <h1 class="mt-2 text-3xl font-black tracking-[-0.05em] sm:text-[38px]">Yeni Benchmark</h1>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-[var(--muted)]">
                Görevi, model grubunu ve doğrulanacak rotaları seçin. Aynı anda yalnızca bir koşu çalışır.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <div class="rounded-xl border border-[var(--line)] bg-white px-3.5 py-2 text-xs">
                <span class="text-[var(--muted)]">OpenRouter kataloğu</span>
                <strong class="ml-1">{{ $catalog['count'] }} model</strong>
                @if ($catalog['lastSyncedAt'])
                    <span class="ml-1 text-[10px] text-[var(--muted)]">· {{ $catalog['lastSyncedAt']->diffForHumans() }}</span>
                @endif
            </div>
            <form method="POST" action="{{ route('runner.openrouter.sync') }}">
                @csrf
                <button class="rounded-xl border border-[var(--line)] bg-white px-3.5 py-2 text-xs font-extrabold text-[var(--forest)] transition hover:border-[var(--forest)] disabled:cursor-not-allowed disabled:opacity-45" type="submit" @disabled($active)>
                    Kataloğu yenile
                </button>
            </form>
            <div class="rounded-xl border border-[var(--line)] bg-white px-3.5 py-2 text-xs">
                <span class="text-[var(--muted)]">OpenCode Go</span>
                <strong class="ml-1">{{ $goCatalog['count'] }} model</strong>
                @if ($goCatalog['lastSyncedAt'])
                    <span class="ml-1 text-[10px] text-[var(--muted)]">· {{ $goCatalog['lastSyncedAt']->diffForHumans() }}</span>
                @endif
            </div>
            <form method="POST" action="{{ route('runner.opencode_go.sync') }}">
                @csrf
                <button class="rounded-xl border border-[var(--line)] bg-white px-3.5 py-2 text-xs font-extrabold text-[var(--violet)] transition hover:border-[var(--violet)] disabled:cursor-not-allowed disabled:opacity-45" type="submit" @disabled($active)>
                    Go kataloğunu yenile
                </button>
            </form>
            <span class="status-badge status-{{ $statusTone }}" data-runner-status-badge>
                <span class="status-dot"></span>
                <span data-runner-status-label>{{ $statusLabels[$state['status']] ?? 'Bilinmiyor' }}</span>
            </span>
        </div>
    </header>

    <section class="mb-5 rounded-2xl border border-[var(--line)] bg-white p-4 sm:p-5" aria-labelledby="provider-connections-title" data-provider-connections>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="eyebrow">Bağlantı ve hesap durumu</p>
                <h2 id="provider-connections-title" class="mt-1 text-lg font-black tracking-[-0.03em]">Model sağlayıcıları</h2>
            </div>
            <a class="rounded-xl border border-[var(--line)] px-3.5 py-2 text-xs font-extrabold text-[var(--forest)] transition hover:border-[var(--forest)]" href="{{ route('runner.create') }}">
                Durumu yenile
            </a>
        </div>

        <div class="mt-4 grid gap-3 md:grid-cols-3">
            @foreach ($connectionItems as $connection)
                <article class="rounded-xl border border-[var(--line)] bg-[var(--surface-subtle)] p-3.5" data-provider-connection="{{ Str::slug($connection['label']) }}">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-black">{{ $connection['label'] }}</h3>
                            <p class="mt-0.5 text-[9px] font-extrabold tracking-[0.07em] text-[var(--muted)] uppercase">{{ $connection['meta'] }}</p>
                        </div>
                        <span class="status-badge status-{{ $connection['status'] }}">
                            <span class="status-dot"></span>
                            {{ $connectionLabels[$connection['status']] ?? 'Bilinmiyor' }}
                        </span>
                    </div>
                    <p class="mt-3 text-[10px] leading-4 text-[var(--muted)]">{{ $connection['message'] }}</p>
                </article>
            @endforeach
        </div>

        <p class="mt-3 text-[9px] leading-4 text-[var(--muted)]">
            Bu kontrol yalnızca OpenCode kimlik deposunda bağlantının bulunup bulunmadığını gösterir. Anahtar değerleri okunmaz veya gösterilmez; bakiye ve abonelik limiti doğrulanmaz.
        </p>
    </section>

    @if (session('status'))
        <div class="mb-5 rounded-xl border border-[#b9ead8] bg-[var(--success-soft)] px-4 py-3 text-sm font-semibold text-[var(--success)]" role="status">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-5 rounded-xl border border-[#f2c9cf] bg-[var(--red-soft)] px-4 py-3 text-sm text-[var(--red)]" role="alert">
            <strong class="font-extrabold">{{ $errors->has('group_name') ? 'Grup kaydedilemedi.' : 'Koşu başlatılamadı.' }}</strong>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-xs leading-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid gap-6 min-[1700px]:grid-cols-[minmax(0,1.08fr)_minmax(520px,0.92fr)]">
        <form class="runner-card rounded-2xl border border-[var(--line)] bg-white p-5 sm:p-6" method="POST" action="{{ route('runner.store') }}" data-runner-form>
            @csrf

            <div class="flex items-start justify-between gap-4 border-b border-[var(--line)] pb-5">
                <div>
                    <p class="eyebrow">Koşu tanımı</p>
                    <h2 class="mt-1 text-xl font-black tracking-[-0.03em]">Yeni teslim grubu</h2>
                </div>
                <span class="rounded-lg border border-[#d7ccff] bg-[var(--violet-soft)] px-2.5 py-1.5 text-[9px] font-extrabold tracking-[0.08em] text-[var(--violet)] uppercase">
                    Üzerine yazma kapalı
                </span>
            </div>

            <div class="mt-5 grid gap-5">
                <label class="grid gap-2 text-xs font-extrabold text-[var(--ink)]">
                    Koşu adı
                    <input
                        class="h-11 rounded-xl border border-[var(--line)] bg-white px-3.5 font-mono text-sm font-medium outline-none transition focus:border-[var(--forest)] focus:ring-3 focus:ring-[var(--mint-soft)] disabled:cursor-not-allowed disabled:bg-[var(--surface-subtle)]"
                        type="text"
                        name="run_id"
                        value="{{ old('run_id') }}"
                        placeholder="nova-marketplace-v2"
                        pattern="[a-z0-9][a-z0-9-]{2,63}"
                        maxlength="64"
                        autocomplete="off"
                        data-runner-input
                        @disabled($active)
                        required
                    >
                    <span class="font-normal leading-5 text-[var(--muted)]">Küçük harf, sayı ve tire. Var olan bir ad yeniden kullanılamaz.</span>
                </label>

                <fieldset class="grid gap-3" data-prompt-mode-fieldset>
                    <legend class="text-xs font-extrabold text-[var(--ink)]">Görev promptu</legend>
                    <div class="grid grid-cols-3 gap-2 rounded-xl bg-[var(--surface-subtle)] p-1">
                        <label class="cursor-pointer rounded-lg px-3 py-2 text-center text-xs font-extrabold has-checked:bg-white has-checked:text-[var(--forest)] has-checked:shadow-sm">
                            <input class="sr-only" type="radio" name="prompt_mode" value="saved" data-prompt-mode data-runner-input @checked($defaultPromptMode === 'saved') @disabled($active)>
                            Kayıtlı prompt
                        </label>
                        <label class="cursor-pointer rounded-lg px-3 py-2 text-center text-xs font-extrabold has-checked:bg-white has-checked:text-[var(--forest)] has-checked:shadow-sm">
                            <input class="sr-only" type="radio" name="prompt_mode" value="custom" data-prompt-mode data-runner-input @checked($defaultPromptMode === 'custom') @disabled($active)>
                            Yeni prompt yaz
                        </label>
                    </div>

                    <div data-prompt-panel="saved" @class(['hidden' => $defaultPromptMode !== 'saved'])>
                        <select class="h-11 w-full rounded-xl border border-[var(--line)] bg-white px-3.5 text-sm font-semibold outline-none transition focus:border-[var(--forest)] focus:ring-3 focus:ring-[var(--mint-soft)] disabled:cursor-not-allowed disabled:bg-[var(--surface-subtle)]" name="prompt" data-runner-input @disabled($active)>
                            @foreach (collect($options['prompts'])->groupBy('source') as $source => $prompts)
                                <optgroup label="{{ $source === 'library' ? 'Prompt kütüphanesi' : 'Proje promptları' }}">
                                    @foreach ($prompts as $prompt)
                                        <option value="{{ $prompt['value'] }}" @selected($defaultPrompt === $prompt['value'])>{{ $prompt['label'] }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </div>

                    <div class="grid gap-3" data-prompt-panel="custom" @class(['hidden' => $defaultPromptMode !== 'custom'])>
                        <input class="h-11 rounded-xl border border-[var(--line)] bg-white px-3.5 text-sm font-semibold outline-none focus:border-[var(--forest)] focus:ring-3 focus:ring-[var(--mint-soft)]" type="text" name="custom_prompt_title" value="{{ old('custom_prompt_title') }}" maxlength="120" placeholder="Prompt başlığı (kaydetmek isterseniz gerekli)" data-runner-input @disabled($active)>
                        <textarea class="min-h-48 resize-y rounded-xl border border-[var(--line)] bg-white px-3.5 py-3 font-mono text-xs leading-5 outline-none focus:border-[var(--forest)] focus:ring-3 focus:ring-[var(--mint-soft)]" name="custom_prompt_content" maxlength="60000" placeholder="Modele verilecek görevi ve kabul kriterlerini buraya yazın…" data-runner-input @disabled($active)>{{ old('custom_prompt_content') }}</textarea>
                        <p class="text-[10px] leading-4 text-[var(--muted)]">Lint, static export, model klasörüne özel basePath ve rota doğrulama kuralları prompta otomatik eklenir.</p>
                        <label class="flex items-start gap-2 text-xs font-semibold text-[var(--muted)]">
                            <input class="mt-0.5 rounded border-[var(--line)] text-[var(--forest)]" type="checkbox" name="save_prompt" value="1" data-runner-input @checked(old('save_prompt')) @disabled($active)>
                            <span>Bu promptu sonraki benchmarklarda kullanmak üzere kütüphaneye kaydet.</span>
                        </label>
                    </div>
                </fieldset>

                <fieldset class="grid gap-3" data-model-mode-fieldset>
                    <legend class="text-xs font-extrabold text-[var(--ink)]">Modeller</legend>
                    <div class="grid grid-cols-3 gap-2 rounded-xl bg-[var(--surface-subtle)] p-1">
                        <label class="cursor-pointer rounded-lg px-3 py-2 text-center text-xs font-extrabold has-checked:bg-white has-checked:text-[var(--forest)] has-checked:shadow-sm">
                            <input class="sr-only" type="radio" name="model_mode" value="group" data-model-mode data-runner-input @checked($defaultModelMode === 'group') @disabled($active)>
                            Kayıtlı grup
                        </label>
                        <label class="cursor-pointer rounded-lg px-3 py-2 text-center text-xs font-extrabold has-checked:bg-white has-checked:text-[var(--forest)] has-checked:shadow-sm">
                            <input class="sr-only" type="radio" name="model_mode" value="custom" data-model-mode data-runner-input @checked($defaultModelMode === 'custom') @disabled($active)>
                            OpenRouter kataloğu
                        </label>
                        <label class="cursor-pointer rounded-lg px-3 py-2 text-center text-xs font-extrabold has-checked:bg-white has-checked:text-[var(--violet)] has-checked:shadow-sm">
                            <input class="sr-only" type="radio" name="model_mode" value="opencode_go" data-model-mode data-runner-input @checked($defaultModelMode === 'opencode_go') @disabled($active)>
                            OpenCode Go
                        </label>
                    </div>

                    <div data-model-panel="group" @class(['hidden' => $defaultModelMode !== 'group'])>
                        <select class="h-11 w-full rounded-xl border border-[var(--line)] bg-white px-3.5 text-sm font-semibold outline-none transition focus:border-[var(--forest)] focus:ring-3 focus:ring-[var(--mint-soft)] disabled:cursor-not-allowed disabled:bg-[var(--surface-subtle)]" name="config" data-runner-config data-runner-input @disabled($active)>
                            @foreach ($options['configs'] as $config)
                                <option value="{{ $config['value'] }}" data-model-count="{{ $config['modelCount'] }}" data-ports="{{ implode(', ', $config['ports']) }}" data-providers="{{ implode(', ', $config['providers'] ?? []) }}" data-max-parallel="{{ $config['maxParallel'] ?? $config['modelCount'] }}" @selected($defaultConfig === $config['value'])>{{ $config['label'] }} · {{ $config['modelCount'] }} model</option>
                            @endforeach
                        </select>
                        <p class="mt-2 text-[10px] leading-4 text-[var(--muted)]">
                            Grupları eklemek, düzenlemek veya silmek için <a class="font-extrabold text-[var(--forest)] underline" href="{{ route('model-groups.index') }}">Model Grupları</a> sayfasını kullanın.
                        </p>
                    </div>

                    <div class="grid gap-3" data-model-panel="custom" @class(['hidden' => $defaultModelMode !== 'custom'])>
                        <div class="flex items-center gap-2">
                            <input class="h-10 min-w-0 flex-1 rounded-xl border border-[var(--line)] bg-white px-3.5 text-sm outline-none focus:border-[var(--forest)] focus:ring-3 focus:ring-[var(--mint-soft)]" type="search" placeholder="Model adı veya yayıncı ara…" data-model-search data-runner-input @disabled($active)>
                            <span class="whitespace-nowrap text-[10px] font-extrabold text-[var(--muted)]"><span data-model-visible-count>{{ $openRouterModels->count() }}</span> sonuç · <span data-model-selected-count>{{ count($selectedModels) }}</span>/{{ \App\Services\BenchmarkRunner::CUSTOM_MODEL_LIMIT }} seçili</span>
                        </div>
                        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                            <label class="grid gap-1 text-[9px] font-extrabold tracking-[0.07em] text-[var(--muted)] uppercase">
                                Model yayıncısı
                                <select class="h-9 rounded-lg border border-[var(--line)] bg-white px-2.5 text-[11px] font-semibold normal-case outline-none focus:border-[var(--forest)]" data-model-author-filter data-runner-input @disabled($active)>
                                    <option value="">Tümü</option>
                                    @foreach ($catalogAuthors as $author)
                                        <option value="{{ $author }}">{{ $author }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="grid gap-1 text-[9px] font-extrabold tracking-[0.07em] text-[var(--muted)] uppercase">
                                Ücret
                                <select class="h-9 rounded-lg border border-[var(--line)] bg-white px-2.5 text-[11px] font-semibold normal-case outline-none focus:border-[var(--forest)]" data-model-cost-filter data-runner-input @disabled($active)>
                                    <option value="all">Tümü</option>
                                    <option value="free">Yalnızca ücretsiz</option>
                                    <option value="paid">Yalnızca ücretli</option>
                                </select>
                            </label>
                            <label class="grid gap-1 text-[9px] font-extrabold tracking-[0.07em] text-[var(--muted)] uppercase">
                                Sürüm
                                <select class="h-9 rounded-lg border border-[var(--line)] bg-white px-2.5 text-[11px] font-semibold normal-case outline-none focus:border-[var(--forest)]" data-model-release-filter data-runner-input @disabled($active)>
                                    <option value="all">Tümü</option>
                                    <option value="stable">Kararlı</option>
                                    <option value="preview">Preview</option>
                                </select>
                            </label>
                            <label class="grid gap-1 text-[9px] font-extrabold tracking-[0.07em] text-[var(--muted)] uppercase">
                                Sıralama
                                <select class="h-9 rounded-lg border border-[var(--line)] bg-white px-2.5 text-[11px] font-semibold normal-case outline-none focus:border-[var(--forest)]" data-model-sort data-runner-input @disabled($active)>
                                    <option value="newest">En yeni</option>
                                    <option value="name">Ada göre</option>
                                    <option value="prompt-price">Girdi fiyatı</option>
                                    <option value="completion-price">Çıktı fiyatı</option>
                                    <option value="context">Bağlam uzunluğu</option>
                                </select>
                            </label>
                        </div>
                        <div class="grid gap-2 rounded-xl border border-[#f0d9a6] bg-[#fff9eb] p-3">
                            <label class="grid gap-1 text-xs font-extrabold text-[var(--ink)]" for="openrouter-routing-mode">
                                OpenRouter sağlayıcı yönlendirmesi
                            </label>
                            <select class="h-10 w-full rounded-lg border border-[#e8cf97] bg-white px-3 text-xs font-semibold outline-none focus:border-[var(--forest)] disabled:cursor-not-allowed disabled:opacity-50" id="openrouter-routing-mode" name="routing_mode" data-routing-mode data-runner-input @disabled($active || $defaultModelMode !== 'custom')>
                                <option value="auto" @selected($defaultRoutingMode === 'auto')>Otomatik · OpenRouter seçsin</option>
                                <option value="price" @selected($defaultRoutingMode === 'price')>Düşük fiyat öncelikli</option>
                                <option value="throughput" @selected($defaultRoutingMode === 'throughput')>Yüksek hız öncelikli</option>
                                <option value="fixed" @selected($defaultRoutingMode === 'fixed')>Sabit sağlayıcı · model başına seç</option>
                            </select>
                            <p class="text-[10px] leading-4 text-[#815d0c]" data-routing-explanation>
                                Yalnız OpenRouter kataloğundan seçilen modeller için geçerlidir. Kayıtlı gruplar mevcut yönlendirme ayarlarını korur; OpenCode Go etkilenmez. Veri toplama izni verilmez; parametre uyumu aranır.
                            </p>
                        </div>
                        <div class="max-h-80 space-y-2 overflow-y-auto pr-1" data-model-catalog>
                            @forelse ($openRouterModels as $model)
                                <label
                                    class="block cursor-pointer rounded-xl border border-[var(--line)] bg-white p-3 transition has-checked:border-[var(--forest)] has-checked:bg-[var(--mint-soft)]"
                                    data-model-card
                                    data-model-name="{{ $model->name }}"
                                    data-model-id="{{ $model->model_id }}"
                                    data-model-fixed-provider="{{ $selectedFixedProviders[$model->model_id] ?? '' }}"
                                    data-model-search-text="{{ Str::lower($model->name.' '.$model->model_id.' '.$model->author) }}"
                                    data-model-author="{{ $model->author }}"
                                    data-model-free="{{ $model->is_free ? 'true' : 'false' }}"
                                    data-model-preview="{{ $model->is_preview ? 'true' : 'false' }}"
                                    data-model-context="{{ $model->context_length ?? 0 }}"
                                    data-model-prompt-price="{{ $model->prompt_price ?? 0 }}"
                                    data-model-completion-price="{{ $model->completion_price ?? 0 }}"
                                    data-model-released-at="{{ $model->released_at?->timestamp ?? 0 }}"
                                >
                                    <div class="flex items-start gap-3">
                                        <input class="mt-1 rounded border-[var(--line)] text-[var(--forest)]" type="checkbox" name="models[]" value="{{ $model->model_id }}" data-custom-model data-runner-input @checked(in_array($model->model_id, $selectedModels, true)) @disabled($active)>
                                        <span class="min-w-0 flex-1">
                                            <span class="flex flex-wrap items-center gap-1.5">
                                                <strong class="truncate text-xs">{{ $model->name }}</strong>
                                                @if ($model->is_free)<span class="rounded bg-[var(--success-soft)] px-1.5 py-0.5 text-[8px] font-black text-[var(--success)]">ÜCRETSİZ</span>@endif
                                                @if ($model->is_preview)<span class="rounded bg-[var(--violet-soft)] px-1.5 py-0.5 text-[8px] font-black text-[var(--violet)]">PREVIEW</span>@endif
                                            </span>
                                            <span class="mt-1 block break-all font-mono text-[9px] text-[var(--muted)]">{{ $model->model_id }}</span>
                                            <span class="mt-2 block text-[9px] text-[var(--muted)]">
                                                {{ $model->context_length ? number_format($model->context_length, 0, ',', '.') : '—' }} bağlam
                                                · Girdi ${{ number_format(((float) $model->prompt_price) * 1000000, 2) }}/M
                                                · Çıktı ${{ number_format(((float) $model->completion_price) * 1000000, 2) }}/M
                                            </span>
                                        </span>
                                    </div>
                                </label>
                            @empty
                                <div class="rounded-xl border border-dashed border-[var(--line)] p-5 text-center text-xs leading-5 text-[var(--muted)]">
                                    Henüz kullanılabilir model yok. “Kataloğu yenile” ile OpenRouter listesini alın.
                                </div>
                            @endforelse
                            <div class="hidden rounded-xl border border-dashed border-[var(--line)] p-5 text-center text-xs leading-5 text-[var(--muted)]" data-model-empty-filter>
                                Bu filtrelerle eşleşen model bulunamadı.
                            </div>
                        </div>

                        <section class="grid gap-3 rounded-xl border border-[var(--line)] bg-[var(--surface-subtle)] p-4 {{ $defaultRoutingMode === 'fixed' && $defaultModelMode === 'custom' ? '' : 'hidden' }}" data-fixed-provider-panel aria-labelledby="fixed-provider-title">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <h3 class="text-xs font-black" id="fixed-provider-title">Model başına sabit sağlayıcı</h3>
                                    <p class="mt-1 text-[10px] leading-4 text-[var(--muted)]">Her seçili model için araç kullanımını destekleyen bir uç nokta seçin. OpenRouter’ın sağlayıcı etiketi kullanılır ve seçim başlatmadan önce yeniden doğrulanır; anlık kullanılabilirlik veya veri politikası garantisi değildir.</p>
                                </div>
                                <button class="rounded-lg border border-[var(--line)] bg-white px-2.5 py-1.5 text-[10px] font-extrabold text-[var(--forest)] disabled:cursor-not-allowed disabled:opacity-50" type="button" data-fixed-provider-refresh @disabled($active)>Sağlayıcıları yenile</button>
                            </div>
                            <div class="grid gap-3" data-fixed-provider-list aria-live="polite"></div>
                        </section>

                        <section class="rounded-xl border border-[#d8d1f5] bg-[var(--violet-soft)] p-4" aria-labelledby="cost-estimate-title" data-cost-estimate>
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="text-[9px] font-extrabold tracking-[0.08em] text-[var(--violet)] uppercase">Bütçe senaryosu</p>
                                    <h3 id="cost-estimate-title" class="mt-1 text-sm font-black">Yaklaşık OpenRouter maliyeti</h3>
                                </div>
                                <strong class="font-mono text-xl font-black text-[var(--violet)]" data-cost-total>—</strong>
                            </div>
                            <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                <label class="grid gap-1 text-[9px] font-extrabold tracking-[0.07em] text-[var(--muted)] uppercase">
                                    Model başına toplam girdi tokenı
                                    <input class="h-9 rounded-lg border border-[#d8d1f5] bg-white px-2.5 font-mono text-xs font-bold normal-case outline-none focus:border-[var(--violet)]" type="number" min="0" max="10000000" step="1000" value="100000" inputmode="numeric" data-cost-input-tokens data-runner-input @disabled($active)>
                                </label>
                                <label class="grid gap-1 text-[9px] font-extrabold tracking-[0.07em] text-[var(--muted)] uppercase">
                                    Model başına toplam çıktı tokenı
                                    <input class="h-9 rounded-lg border border-[#d8d1f5] bg-white px-2.5 font-mono text-xs font-bold normal-case outline-none focus:border-[var(--violet)]" type="number" min="0" max="10000000" step="1000" value="25000" inputmode="numeric" data-cost-output-tokens data-runner-input @disabled($active)>
                                </label>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-[10px] text-[var(--muted)]">
                                <span>Girdi: <strong class="text-[var(--ink)]" data-cost-input>—</strong></span>
                                <span>Çıktı: <strong class="text-[var(--ink)]" data-cost-output>—</strong></span>
                                <span><strong data-cost-model-count>0</strong> model</span>
                            </div>
                            <p class="mt-2 text-[9px] leading-4 text-[var(--muted)]" data-cost-basis>
                                Katalog fiyatlarıyla hesaplanan tahmin. OpenCode birden fazla istek yapabilir; gerçek tutar önbellek, yeniden deneme ve yönlendirmeye göre değişebilir.
                            </p>
                        </section>
                    </div>

                    <div class="grid gap-3" data-model-panel="opencode_go" @class(['hidden' => $defaultModelMode !== 'opencode_go'])>
                        <div class="flex items-center gap-2">
                            <input class="h-10 min-w-0 flex-1 rounded-xl border border-[var(--line)] bg-white px-3.5 text-sm outline-none focus:border-[var(--violet)] focus:ring-3 focus:ring-[var(--violet-soft)]" type="search" placeholder="OpenCode Go modeli ara…" data-go-model-search data-runner-input @disabled($active)>
                            <span class="whitespace-nowrap text-[10px] font-extrabold text-[var(--muted)]"><span data-go-model-visible-count>{{ $openCodeGoModels->count() }}</span> sonuç · <span data-go-model-selected-count>{{ count($selectedGoModels) }}</span>/{{ \App\Services\BenchmarkRunner::CUSTOM_MODEL_LIMIT }} seçili</span>
                        </div>
                        <div class="grid gap-2 sm:grid-cols-3">
                            <label class="grid gap-1 text-[9px] font-extrabold tracking-[0.07em] text-[var(--muted)] uppercase">
                                Ücret
                                <select class="h-9 rounded-lg border border-[var(--line)] bg-white px-2.5 text-[11px] font-semibold normal-case outline-none focus:border-[var(--violet)]" data-go-model-cost-filter data-runner-input @disabled($active)>
                                    <option value="all">Tümü</option>
                                    <option value="free">Yalnızca ücretsiz</option>
                                    <option value="paid">Yalnızca ücretli</option>
                                </select>
                            </label>
                            <label class="grid gap-1 text-[9px] font-extrabold tracking-[0.07em] text-[var(--muted)] uppercase">
                                Sürüm
                                <select class="h-9 rounded-lg border border-[var(--line)] bg-white px-2.5 text-[11px] font-semibold normal-case outline-none focus:border-[var(--violet)]" data-go-model-release-filter data-runner-input @disabled($active)>
                                    <option value="all">Tümü</option>
                                    <option value="stable">Kararlı</option>
                                    <option value="preview">Preview</option>
                                </select>
                            </label>
                            <label class="grid gap-1 text-[9px] font-extrabold tracking-[0.07em] text-[var(--muted)] uppercase">
                                Sıralama
                                <select class="h-9 rounded-lg border border-[var(--line)] bg-white px-2.5 text-[11px] font-semibold normal-case outline-none focus:border-[var(--violet)]" data-go-model-sort data-runner-input @disabled($active)>
                                    <option value="newest">En yeni</option>
                                    <option value="name">Ada göre</option>
                                    <option value="prompt-price">Girdi maliyeti</option>
                                    <option value="completion-price">Çıktı maliyeti</option>
                                    <option value="context">Bağlam uzunluğu</option>
                                </select>
                            </label>
                        </div>
                        <p class="rounded-lg border border-[#d8d1f5] bg-[var(--violet-soft)] px-3 py-2 text-[10px] leading-4 text-[var(--violet)]">
                            OpenCode Go aboneliğiniz kullanılır. Gösterilen maliyet, ek ücret değil; aboneliğinizdeki kullanım limitinin yaklaşık dolar karşılığıdır. Modeller aşağıdaki eşzamanlılık seçimine göre çalışır.
                        </p>
                        <div class="max-h-80 space-y-2 overflow-y-auto pr-1" data-go-model-catalog>
                            @forelse ($openCodeGoModels as $model)
                                <label
                                    class="block cursor-pointer rounded-xl border border-[var(--line)] bg-white p-3 transition has-checked:border-[var(--violet)] has-checked:bg-[var(--violet-soft)]"
                                    data-go-model-card
                                    data-model-name="{{ $model->name }}"
                                    data-model-search-text="{{ Str::lower($model->name.' '.$model->model_id.' '.$model->family) }}"
                                    data-model-author=""
                                    data-model-free="{{ $model->is_free ? 'true' : 'false' }}"
                                    data-model-preview="{{ $model->is_preview ? 'true' : 'false' }}"
                                    data-model-context="{{ $model->context_length ?? 0 }}"
                                    data-model-prompt-price="{{ ((float) ($model->input_cost ?? 0)) / 1000000 }}"
                                    data-model-completion-price="{{ ((float) ($model->output_cost ?? 0)) / 1000000 }}"
                                    data-model-released-at="{{ $model->released_at?->timestamp ?? 0 }}"
                                >
                                    <div class="flex items-start gap-3">
                                        <input class="mt-1 rounded border-[var(--line)] text-[var(--violet)]" type="checkbox" name="go_models[]" value="{{ $model->model_id }}" data-go-custom-model data-runner-input @checked(in_array($model->model_id, $selectedGoModels, true)) @disabled($active)>
                                        <span class="min-w-0 flex-1">
                                            <span class="flex flex-wrap items-center gap-1.5">
                                                <strong class="truncate text-xs">{{ $model->name }}</strong>
                                                @if ($model->supports_reasoning)<span class="rounded bg-[var(--mint-soft)] px-1.5 py-0.5 text-[8px] font-black text-[var(--forest)]">REASONING</span>@endif
                                                @if ($model->is_free)<span class="rounded bg-[var(--success-soft)] px-1.5 py-0.5 text-[8px] font-black text-[var(--success)]">ÜCRETSİZ</span>@endif
                                                @if ($model->is_preview)<span class="rounded bg-[var(--violet-soft)] px-1.5 py-0.5 text-[8px] font-black text-[var(--violet)]">PREVIEW</span>@endif
                                            </span>
                                            <span class="mt-1 block break-all font-mono text-[9px] text-[var(--muted)]">opencode-go/{{ $model->model_id }}</span>
                                            <span class="mt-2 block text-[9px] text-[var(--muted)]">
                                                {{ $model->context_length ? number_format($model->context_length, 0, ',', '.') : '—' }} bağlam
                                                · Girdi ${{ number_format((float) $model->input_cost, 3) }}/M
                                                · Çıktı ${{ number_format((float) $model->output_cost, 3) }}/M
                                            </span>
                                        </span>
                                    </div>
                                </label>
                            @empty
                                <div class="rounded-xl border border-dashed border-[var(--line)] p-5 text-center text-xs leading-5 text-[var(--muted)]">
                                    Henüz kullanılabilir OpenCode Go modeli yok. “Go kataloğunu yenile” düğmesini kullanın.
                                </div>
                            @endforelse
                            <div class="hidden rounded-xl border border-dashed border-[var(--line)] p-5 text-center text-xs leading-5 text-[var(--muted)]" data-go-model-empty-filter>
                                Bu filtrelerle eşleşen OpenCode Go modeli bulunamadı.
                            </div>
                        </div>

                        <section class="rounded-xl border border-[#d8d1f5] bg-[var(--violet-soft)] p-4" aria-labelledby="go-cost-estimate-title">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="text-[9px] font-extrabold tracking-[0.08em] text-[var(--violet)] uppercase">Abonelik kullanım senaryosu</p>
                                    <h3 id="go-cost-estimate-title" class="mt-1 text-sm font-black">Yaklaşık Go kullanım değeri</h3>
                                </div>
                                <strong class="font-mono text-xl font-black text-[var(--violet)]" data-go-cost-total>—</strong>
                            </div>
                            <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                <label class="grid gap-1 text-[9px] font-extrabold tracking-[0.07em] text-[var(--muted)] uppercase">
                                    Model başına toplam girdi tokenı
                                    <input class="h-9 rounded-lg border border-[#d8d1f5] bg-white px-2.5 font-mono text-xs font-bold normal-case outline-none focus:border-[var(--violet)]" type="number" min="0" max="10000000" step="1000" value="100000" inputmode="numeric" data-go-cost-input-tokens data-runner-input @disabled($active)>
                                </label>
                                <label class="grid gap-1 text-[9px] font-extrabold tracking-[0.07em] text-[var(--muted)] uppercase">
                                    Model başına toplam çıktı tokenı
                                    <input class="h-9 rounded-lg border border-[#d8d1f5] bg-white px-2.5 font-mono text-xs font-bold normal-case outline-none focus:border-[var(--violet)]" type="number" min="0" max="10000000" step="1000" value="25000" inputmode="numeric" data-go-cost-output-tokens data-runner-input @disabled($active)>
                                </label>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-[10px] text-[var(--muted)]">
                                <span>Girdi: <strong class="text-[var(--ink)]" data-go-cost-input>—</strong></span>
                                <span>Çıktı: <strong class="text-[var(--ink)]" data-go-cost-output>—</strong></span>
                                <span><strong data-go-cost-model-count>0</strong> model</span>
                            </div>
                        </section>
                    </div>

                    <div @class(['grid gap-2 rounded-xl border border-[var(--line)] bg-[var(--surface-subtle)] p-3', 'hidden' => $defaultModelMode === 'group']) data-selection-group>
                        <label class="text-xs font-extrabold text-[var(--ink)]" for="selection-group-name">Bu seçimi grup olarak kaydet</label>
                        <div class="flex flex-col gap-2 sm:flex-row">
                            <input class="h-10 min-w-0 flex-1 rounded-lg border border-[var(--line)] bg-white px-3 font-mono text-xs outline-none focus:border-[var(--forest)]" id="selection-group-name" type="text" name="group_name" value="{{ old('group_name') }}" maxlength="63" placeholder="ornek-grup-adi" @disabled($active)>
                            <button class="rounded-lg border border-[var(--line)] bg-white px-3 py-2 text-[11px] font-extrabold text-[var(--forest)] disabled:opacity-50" type="submit" formaction="{{ route('model-groups.selection.store') }}" formnovalidate data-save-selection-group @disabled($active)>Grup olarak kaydet</button>
                        </div>
                        @error('group_name')
                            <p class="text-[10px] font-semibold text-[var(--red)]" role="alert">{{ $message }}</p>
                        @enderror
                        <p class="text-[10px] leading-4 text-[var(--muted)]">Koşu başlatmaz; yalnız seçili modelleri kayıtlı gruplara ekler. OpenRouter grupları, buradaki sabit sağlayıcı seçimini değil ortak yönlendirme ayarını kullanır.</p>
                    </div>
                </fieldset>

                <label class="grid gap-2 text-xs font-extrabold text-[var(--ink)]">
                    Aynı anda çalışacak model sayısı
                    <select
                        class="h-11 rounded-xl border border-[var(--line)] bg-white px-3.5 text-sm font-semibold outline-none transition focus:border-[var(--forest)] focus:ring-3 focus:ring-[var(--mint-soft)] disabled:cursor-not-allowed disabled:bg-[var(--surface-subtle)]"
                        name="max_parallel"
                        data-runner-concurrency
                        data-runner-input
                        @disabled($active)
                        required
                    >
                        <option value="1" @selected($defaultMaxParallel === 1)>1 · Sırayla, en güvenilir</option>
                        <option value="2" @selected($defaultMaxParallel === 2)>2 · Dengeli, önerilen</option>
                        <option value="3" @selected($defaultMaxParallel === 3)>3 · Hızlı, yüksek sistem yükü</option>
                        <option value="4" @selected($defaultMaxParallel === 4)>4 · Çok yüksek sistem yükü</option>
                        <option value="5" @selected($defaultMaxParallel === 5)>5 · Tüm modeller aynı anda</option>
                    </select>
                    <span class="font-normal leading-5 text-[var(--muted)]" data-runner-concurrency-help>
                        Önerilen değer 2’dir. Daha yüksek değerler build ve browser kontrolleri sırasında bilgisayarın işlemci ve belleğini daha fazla kullanır.
                    </span>
                </label>

                <label class="grid gap-2 text-xs font-extrabold text-[var(--ink)]">
                    Doğrulanacak rotalar
                    <textarea
                        class="resize-y rounded-xl border border-[var(--line)] bg-white px-3.5 py-3 font-mono text-sm leading-6 outline-none transition focus:border-[var(--forest)] focus:ring-3 focus:ring-[var(--mint-soft)] disabled:cursor-not-allowed disabled:bg-[var(--surface-subtle)]"
                        name="routes"
                        rows="5"
                        placeholder="/&#10;/product/example&#10;/cart"
                        data-runner-input
                        @disabled($active)
                        required
                    >{{ $defaultRoutes }}</textarea>
                    <span class="font-normal leading-5 text-[var(--muted)]">Her satıra bir rota yazın. En fazla 20 rota.</span>
                </label>
            </div>

            <div class="mt-6 rounded-xl border border-[var(--line)] bg-[var(--surface-subtle)] p-4">
                <p class="text-[10px] font-extrabold tracking-[0.1em] text-[var(--muted)] uppercase">Başlatma özeti</p>
                <div class="mt-3 grid grid-cols-2 gap-3 text-xs sm:grid-cols-4">
                    <div>
                        <span class="block text-[var(--muted)]">Model</span>
                        <strong class="mt-1 block" data-runner-model-count>{{ $selectedConfig['modelCount'] ?? '—' }}</strong>
                    </div>
                    <div>
                        <span class="block text-[var(--muted)]">Portlar</span>
                        <strong class="mt-1 block font-mono text-[11px]" data-runner-ports>{{ isset($selectedConfig['ports']) ? implode(', ', $selectedConfig['ports']) : '—' }}</strong>
                    </div>
                    <div>
                        <span class="block text-[var(--muted)]">Sağlayıcı</span>
                        <strong class="mt-1 block font-mono text-[11px]" data-runner-providers>{{ isset($selectedConfig['providers']) ? implode(', ', $selectedConfig['providers']) : '—' }}</strong>
                    </div>
                    <div>
                        <span class="block text-[var(--muted)]">Eşzamanlılık</span>
                        <strong class="mt-1 block" data-runner-max-parallel>{{ $defaultMaxParallel }}</strong>
                    </div>
                </div>
                <p class="mt-3 hidden rounded-lg px-3 py-2 text-[10px] leading-4" data-runner-load-estimate aria-live="polite"></p>
            </div>

            <section class="mt-5 rounded-xl border border-[var(--line)] bg-white p-4" aria-labelledby="preflight-title" data-preflight-panel>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="eyebrow">Başlatma güvenliği</p>
                        <h3 id="preflight-title" class="mt-1 text-base font-black tracking-[-0.02em]">Sistem Ön Kontrolü</h3>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="status-badge {{ $preflightReady ? 'status-pass' : 'status-fail' }}" data-preflight-status>
                            <span class="status-dot"></span>
                            <span data-preflight-status-label>{{ $preflightReady ? 'Başlatmaya hazır' : 'Kontrol gerekli' }}</span>
                        </span>
                        <button class="rounded-lg border border-[var(--line)] bg-[var(--surface-subtle)] px-3 py-2 text-[10px] font-extrabold text-[var(--forest)] transition hover:border-[var(--forest)] disabled:cursor-wait disabled:opacity-50" type="button" data-preflight-refresh @disabled($active || $options['configs'] === [])>
                            Yeniden kontrol et
                        </button>
                    </div>
                </div>

                <p class="mt-3 text-xs leading-5 text-[var(--muted)]" data-preflight-summary>
                    {{ $preflight['summary'] ?? 'Kontrol edilecek bir model grubu seçin.' }}
                </p>

                <div class="mt-4 grid gap-2 sm:grid-cols-2" data-preflight-checks>
                    @foreach (($preflight['checks'] ?? []) as $check)
                        <article class="preflight-check preflight-check-{{ $check['status'] }} flex items-start gap-3 rounded-lg border p-3" data-preflight-check="{{ $check['key'] }}">
                            <span class="preflight-mark" data-preflight-check-mark aria-hidden="true">{{ $check['status'] === 'pass' ? '✓' : '×' }}</span>
                            <div class="min-w-0">
                                <h4 class="text-[11px] font-extrabold" data-preflight-check-label>{{ $check['label'] }}</h4>
                                <p class="mt-1 break-words text-[10px] leading-4 text-[var(--muted)]" data-preflight-check-message>{{ $check['message'] }}</p>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>

            <button class="runner-primary-action mt-5 flex h-12 w-full items-center justify-center rounded-xl px-5 text-sm font-extrabold text-white transition hover:-translate-y-0.5 hover:shadow-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--forest)] disabled:cursor-not-allowed disabled:translate-y-0 disabled:opacity-45 disabled:shadow-none" type="submit" data-runner-submit @disabled($active || ! $preflightReady)>
                {{ $active ? 'Aktif koşunun bitmesi bekleniyor' : ($preflightReady ? 'Benchmark’ı başlat' : 'Ön kontrol sorunlarını giderin') }}
            </button>
        </form>

        <section class="runner-card overflow-hidden rounded-2xl border border-[var(--line)] bg-white" aria-labelledby="runner-status-title">
            <div class="border-b border-[var(--line)] p-5 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="eyebrow">Canlı durum</p>
                        <h2 id="runner-status-title" class="mt-1 text-xl font-black tracking-[-0.03em]" data-runner-run-id>{{ $state['runId'] ?? 'Koşucu hazır' }}</h2>
                        <p class="mt-2 text-xs leading-5 text-[var(--muted)]" data-runner-message>{{ $state['message'] }}</p>
                    </div>
                    <span class="status-badge status-{{ $statusTone }}" data-runner-status-badge>
                        <span class="status-dot"></span>
                        <span data-runner-status-label>{{ $statusLabels[$state['status']] ?? 'Bilinmiyor' }}</span>
                    </span>
                </div>

                <div class="mt-6">
                    <div class="mb-2 flex items-center justify-between text-[11px] font-bold">
                        <span class="text-[var(--muted)]" data-runner-phase>{{ $state['progress']['phase'] }}</span>
                        <span data-runner-progress-count>{{ $state['progress']['completedModels'] }}/{{ $state['progress']['totalModels'] }} model</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-[var(--surface-subtle)]" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $state['progress']['percent'] }}" data-runner-progress>
                        <div class="runner-progress h-full rounded-full transition-[width] duration-500" style="width: {{ $state['progress']['percent'] }}%" data-runner-progress-bar></div>
                    </div>
                </div>

                <div class="mt-5 rounded-xl border border-[var(--line)] bg-[var(--surface-subtle)] p-4" aria-live="polite" data-runner-outcome>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-[10px] font-extrabold tracking-[0.1em] text-[var(--muted)] uppercase">Benchmark sonucu</p>
                            <p class="mt-2 max-w-lg text-xs leading-5 text-[var(--muted)]" data-runner-outcome-summary>{{ $outcome['summary'] }}</p>
                        </div>
                        <span class="status-badge status-{{ $outcomeTone }}" data-runner-outcome-badge>
                            <span class="status-dot"></span>
                            <span data-runner-outcome-label>{{ $outcome['label'] }}</span>
                        </span>
                    </div>

                    <dl class="mt-4 grid grid-cols-3 gap-2 text-center {{ $outcome['available'] ? '' : 'hidden' }}" data-runner-outcome-counts>
                        <div class="rounded-lg border border-[#b9ead8] bg-white px-2 py-2.5">
                            <dt class="text-[9px] font-bold tracking-[0.08em] text-[var(--muted)] uppercase">Geçen</dt>
                            <dd class="mt-1 text-lg font-black text-[var(--success)]" data-runner-outcome-passed>{{ $outcome['passedModels'] }}</dd>
                        </div>
                        <div class="rounded-lg border border-[#f2c9cf] bg-white px-2 py-2.5">
                            <dt class="text-[9px] font-bold tracking-[0.08em] text-[var(--muted)] uppercase">Sorunlu</dt>
                            <dd class="mt-1 text-lg font-black text-[var(--red)]" data-runner-outcome-failed>{{ $outcome['failedModels'] }}</dd>
                        </div>
                        <div class="rounded-lg border border-[var(--line)] bg-white px-2 py-2.5">
                            <dt class="text-[9px] font-bold tracking-[0.08em] text-[var(--muted)] uppercase">Diğer</dt>
                            <dd class="mt-1 text-lg font-black text-[var(--amber)]" data-runner-outcome-other>{{ $outcome['otherModels'] }}</dd>
                        </div>
                    </dl>
                </div>

                <dl class="mt-5 grid grid-cols-2 gap-3 text-xs sm:grid-cols-3">
                    <div><dt class="text-[var(--muted)]">Başlangıç</dt><dd class="mt-1 font-bold" data-runner-started>{{ $state['startedAt'] ?? '—' }}</dd></div>
                    <div><dt class="text-[var(--muted)]">Bitiş</dt><dd class="mt-1 font-bold" data-runner-finished>{{ $state['finishedAt'] ?? '—' }}</dd></div>
                    <div><dt class="text-[var(--muted)]">Çıkış kodu</dt><dd class="mt-1 font-mono font-bold" data-runner-exit>{{ $state['exitCode'] ?? '—' }}</dd></div>
                </dl>

                <div class="mt-5 flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('runner.stop') }}" data-runner-stop-form @class(['hidden' => ! $active])>
                        @csrf
                        @method('DELETE')
                        <button class="rounded-lg border border-[#e9b9b5] bg-[var(--red-soft)] px-3.5 py-2 text-xs font-extrabold text-[var(--red)] transition hover:border-[var(--red)] disabled:cursor-not-allowed disabled:opacity-50" type="submit" data-runner-stop-button @disabled($state['status'] === 'stopping')>
                            {{ $state['status'] === 'stopping' ? 'Durduruluyor…' : 'Koşuyu durdur' }}
                        </button>
                    </form>
                    <a class="rounded-lg bg-[var(--success-soft)] px-3.5 py-2 text-xs font-extrabold text-[var(--success)] no-underline {{ $state['status'] === 'completed' ? '' : 'hidden' }}" href="{{ $state['runId'] ? url('/runs/'.$state['runId']) : '#' }}" data-runner-report-link>
                        Sonuç raporunu aç →
                    </a>
                </div>
            </div>

            <div class="runner-terminal p-5 text-[#cdd6f4] sm:p-6">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-[10px] font-extrabold tracking-[0.1em] text-[#67e8f9] uppercase">Çalışma günlüğü</p>
                    <span class="text-[9px] font-bold tracking-[0.08em] text-[#7783a5] uppercase">2,5 sn yenileme</span>
                </div>
                <pre class="mt-4 max-h-[360px] min-h-48 overflow-auto whitespace-pre-wrap break-words font-mono text-[11px] leading-5" data-runner-log>{{ $state['logTail'] ?: 'Koşu başladığında son günlük kayıtları burada görünecek.' }}</pre>
            </div>
        </section>
    </div>

    <section class="maintenance-card mt-5 rounded-2xl border border-[var(--line)] bg-white p-5 sm:p-6">
        <p class="eyebrow text-[var(--amber)]">Çalışma ilkeleri</p>
        <div class="mt-3 grid gap-4 text-xs leading-5 text-[var(--muted)] sm:grid-cols-3">
            <p><strong class="block text-[var(--ink)]">Tek aktif koşu</strong>Port ve dosya çakışmalarını önlemek için aynı anda yalnızca bir benchmark yürütülür.</p>
            <p><strong class="block text-[var(--ink)]">Değiştirilemez sonuç</strong>Var olan bir koşu adı yeniden kullanılamaz ve eski sonuçların üzerine yazılmaz.</p>
            <p><strong class="block text-[var(--ink)]">Herd ile kalıcı erişim</strong>Arka plan koşucusu otomatik başlar; ayrıca geliştirme sunucusu veya queue worker açmanız gerekmez.</p>
        </div>
    </section>
</div>
@endsection
