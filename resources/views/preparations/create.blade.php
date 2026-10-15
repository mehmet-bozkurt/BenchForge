@extends('layouts.app')

@section('content')
@php
    $openRouterModels = $options['openRouterModels'] ?? collect();
    $goModels = $options['openCodeGoModels'] ?? collect();
    $catalog = $options['catalog'] ?? ['count' => $openRouterModels->count(), 'lastSyncedAt' => null];
    $goCatalog = $options['goCatalog'] ?? ['count' => $goModels->count(), 'lastSyncedAt' => null];
    $selectedProvider = in_array(old('provider', 'openrouter'), ['openrouter', 'opencode-go'], true) ? old('provider', 'openrouter') : 'openrouter';
    $selectedModels = array_values(array_filter((array) old('models', []), 'is_string'));
@endphp

<div class="content-frame">
    <nav class="mb-6 flex items-center gap-2 text-xs font-semibold text-[var(--muted)]" aria-label="Sayfa yolu">
        <a class="hover:text-[var(--forest)]" href="{{ route('preparations.index') }}">Hazırlık Karşılaştırmaları</a>
        <span aria-hidden="true">/</span>
        <span class="text-[var(--ink)]" aria-current="page">Yeni hazırlık</span>
    </nav>

    <header class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="eyebrow">Aynı şartname, bağımsız paketler</p>
            <h1 class="mt-2 text-3xl font-black tracking-[-0.05em] sm:text-[38px]">Yeni Hazırlık Karşılaştırması</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-[var(--muted)]">Bir proje şartnamesi ve teknoloji seçimi verin. Seçilen modeller sırayla beş bölümlük bağımsız hazırlık paketi üretsin.</p>
        </div>
        <a class="inline-flex rounded-xl border border-[var(--line)] bg-[var(--surface)] px-4 py-2.5 text-xs font-extrabold text-[var(--forest)]" href="{{ route('preparations.index') }}">Geçmiş hazırlıklar →</a>
    </header>

    @if ($errors->any())
        <div class="mt-6 rounded-xl border border-[var(--red-line)] bg-[var(--red-soft)] px-4 py-3 text-sm text-[var(--red)]" role="alert">
            <strong class="font-extrabold">Hazırlık başlatılamadı.</strong>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-xs leading-5">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    @if ($activeComparison)
        <div class="mt-6 rounded-xl border border-[var(--indigo-line)] bg-[var(--indigo-soft)] px-4 py-3 text-xs leading-5 text-[var(--forest)]" role="status">
            <strong>Başka bir hazırlık sürüyor:</strong> {{ $activeComparison->title }}. Aynı anda yalnızca bir karşılaştırma çalışır.
            <a class="ml-1 underline" href="{{ route('preparations.show', $activeComparison) }}">Canlı durumu aç</a>
        </div>
    @endif

    <div class="mt-7 grid gap-6 2xl:grid-cols-[minmax(0,1.2fr)_minmax(300px,0.8fr)]">
        <form class="runner-card min-w-0 rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-5 sm:p-7" method="POST" action="{{ route('preparations.store') }}" enctype="multipart/form-data" data-preparation-create>
            @csrf
            <fieldset class="grid gap-6" @disabled($activeComparison)>
                <legend class="sr-only">Hazırlık karşılaştırması bilgileri</legend>

                <label class="grid gap-2 text-xs font-extrabold" for="preparation-title">
                    Karşılaştırma adı
                    <input class="h-11 rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3.5 text-sm font-medium outline-none focus:border-[var(--forest)]" id="preparation-title" type="text" name="title" maxlength="120" value="{{ old('title') }}" placeholder="Örneğin: Incident Ops hazırlık turu" required>
                </label>

                <section class="grid gap-3" aria-labelledby="preparation-source-title">
                    <div>
                        <h2 id="preparation-source-title" class="text-xs font-extrabold">Proje şartnamesi</h2>
                        <p class="mt-1 text-[11px] leading-5 text-[var(--muted)]">Şartnameyi buraya yapıştırın veya UTF-8 TXT dosyası yükleyin. İkisini birlikte kullanmayın; PDF bu ilk sürümde desteklenmez.</p>
                    </div>
                    <label class="grid gap-2 text-[11px] font-bold" for="preparation-specification">
                        Şartname metni · 50–100.000 karakter
                        <textarea class="min-h-64 resize-y rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3.5 py-3 font-mono text-xs leading-5 outline-none focus:border-[var(--forest)]" id="preparation-specification" name="specification" minlength="50" maxlength="100000" placeholder="Projenin amacı, kullanıcıları, özellikleri ve kabul kriterleri…" data-preparation-specification>{{ old('specification') }}</textarea>
                    </label>
                    <label class="grid gap-2 text-[11px] font-bold" for="preparation-specification-file">
                        Veya TXT dosyası · en fazla 1 MB
                        <input class="block w-full rounded-xl border border-[var(--line)] bg-[var(--surface-subtle)] px-3 py-2.5 text-xs file:mr-3 file:rounded-lg file:border-0 file:bg-[var(--violet-soft)] file:px-3 file:py-2 file:font-extrabold file:text-[var(--violet)]" id="preparation-specification-file" type="file" name="specification_file" accept=".txt,text/plain" data-preparation-file>
                    </label>
                    <p class="hidden text-xs font-semibold text-[var(--red)]" role="alert" data-preparation-source-error></p>
                </section>

                <label class="grid gap-2 text-xs font-extrabold" for="preparation-technologies">
                    Teknolojiler ve sınırlar
                    <textarea class="min-h-32 resize-y rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3.5 py-3 text-sm leading-6 outline-none focus:border-[var(--forest)]" id="preparation-technologies" name="technologies" minlength="5" maxlength="5000" placeholder="Örneğin: Laravel, SQLite, Tailwind CSS; harici SaaS bağımlılığı yok." required>{{ old('technologies') }}</textarea>
                    <span class="text-[10px] font-normal leading-4 text-[var(--muted)]">Tüm modellere aynı teknoloji ve kapsam sınırları gönderilir.</span>
                </label>

                <section class="grid gap-3" aria-labelledby="preparation-provider-title">
                    <div>
                        <h2 id="preparation-provider-title" class="text-xs font-extrabold">Model kaynağı</h2>
                        <p class="mt-1 text-[11px] leading-5 text-[var(--muted)]">Bir karşılaştırmada tek kaynak kullanılır. En fazla beş model seçebilirsiniz.</p>
                    </div>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-[var(--line)] bg-[var(--surface-subtle)] p-3 text-xs font-extrabold has-checked:border-[var(--forest)] has-checked:bg-[var(--mint-soft)]">
                            <input type="radio" name="provider" value="openrouter" data-preparation-provider @checked($selectedProvider === 'openrouter')>
                            <span>OpenRouter <small class="block font-medium text-[var(--muted)]">{{ $catalog['count'] ?? $openRouterModels->count() }} katalog modeli</small></span>
                        </label>
                        <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-[var(--line)] bg-[var(--surface-subtle)] p-3 text-xs font-extrabold has-checked:border-[var(--violet)] has-checked:bg-[var(--violet-soft)]">
                            <input type="radio" name="provider" value="opencode-go" data-preparation-provider @checked($selectedProvider === 'opencode-go')>
                            <span>OpenCode Go <small class="block font-medium text-[var(--muted)]">{{ $goCatalog['count'] ?? $goModels->count() }} katalog modeli</small></span>
                        </label>
                    </div>
                    <div class="rounded-xl border border-[var(--violet-line)] bg-[var(--violet-soft)] px-3 py-2.5 text-[11px] leading-5 text-[var(--muted)]">
                        OpenRouter bu ilk sürümde otomatik sağlayıcı yönlendirmesi kullanır. Katalog güncel değilse <a class="font-extrabold text-[var(--forest)] underline" href="{{ route('runner.create') }}">Yeni Benchmark ekranından yenileyin</a>.
                        <span class="mt-1 block">Fiyatlar koşu tahmini değil, kaynaktan alınan birim değerlerdir. Model kartındaki kaynak ve son eşitleme zamanını kontrol edin; yayımlanmayan değerler sıfır sayılmaz.</span>
                    </div>

                    <div @class(['grid gap-2', 'hidden' => $selectedProvider !== 'openrouter']) data-preparation-catalog="openrouter">
                        <p class="text-[10px] font-bold text-[var(--muted)]">OpenRouter kataloğu · <span data-preparation-count="openrouter">{{ $selectedProvider === 'openrouter' ? count($selectedModels) : 0 }}</span>/5 seçili</p>
                        <input class="h-10 rounded-lg border border-[var(--line)] bg-[var(--surface)] px-3 text-xs outline-none focus:border-[var(--forest)]" type="search" placeholder="Model adı veya kimliği ara…" aria-label="OpenRouter model ara" data-preparation-search="openrouter">
                        <div class="max-h-[36rem] space-y-3 overflow-y-auto pr-1">
                            @forelse ($openRouterModels as $model)
                                <x-preparation-model-card :model="$model" :details="$modelDetails['openrouter'][$model->model_id]" provider="openrouter" :selected="$selectedProvider === 'openrouter' && in_array($model->model_id, $selectedModels, true)" :disabled="$selectedProvider !== 'openrouter'" />
                            @empty
                                <p class="rounded-xl border border-dashed border-[var(--line)] p-4 text-xs text-[var(--muted)]">Katalogda kullanılabilir model yok. Yenilemek için Yeni Benchmark ekranına gidin.</p>
                            @endforelse
                            <p class="hidden rounded-xl border border-dashed border-[var(--line)] p-4 text-xs text-[var(--muted)]" data-preparation-search-empty="openrouter">Bu aramayla eşleşen model yok.</p>
                        </div>
                    </div>

                    <div @class(['grid gap-2', 'hidden' => $selectedProvider !== 'opencode-go']) data-preparation-catalog="opencode-go">
                        <p class="text-[10px] font-bold text-[var(--muted)]">OpenCode Go kataloğu · <span data-preparation-count="opencode-go">{{ $selectedProvider === 'opencode-go' ? count($selectedModels) : 0 }}</span>/5 seçili</p>
                        <input class="h-10 rounded-lg border border-[var(--line)] bg-[var(--surface)] px-3 text-xs outline-none focus:border-[var(--violet)]" type="search" placeholder="Model adı veya kimliği ara…" aria-label="OpenCode Go model ara" data-preparation-search="opencode-go">
                        <div class="max-h-[36rem] space-y-3 overflow-y-auto pr-1">
                            @forelse ($goModels as $model)
                                <x-preparation-model-card :model="$model" :details="$modelDetails['opencode-go'][$model->model_id]" provider="opencode-go" :selected="$selectedProvider === 'opencode-go' && in_array($model->model_id, $selectedModels, true)" :disabled="$selectedProvider !== 'opencode-go'" />
                            @empty
                                <p class="rounded-xl border border-dashed border-[var(--line)] p-4 text-xs text-[var(--muted)]">Katalogda kullanılabilir model yok. Yenilemek için Yeni Benchmark ekranına gidin.</p>
                            @endforelse
                            <p class="hidden rounded-xl border border-dashed border-[var(--line)] p-4 text-xs text-[var(--muted)]" data-preparation-search-empty="opencode-go">Bu aramayla eşleşen model yok.</p>
                        </div>
                    </div>
                    <p class="text-xs font-semibold text-[var(--red)]" role="status" aria-live="polite" data-preparation-model-error></p>
                </section>

                <label class="flex items-start gap-3 rounded-xl border border-[var(--amber-line)] bg-[var(--amber-soft)] p-4 text-xs leading-5 text-[var(--amber-ink)]">
                    <input class="mt-1" type="checkbox" name="confirmed_external" value="1" required @checked(old('confirmed_external'))>
                    <span><strong>Harici model çağrısını ve ücret oluşabileceğini onaylıyorum.</strong> Proje şartnamesi ve teknoloji notları seçilen sağlayıcı üzerinden modellere gönderilir. Modeller sırayla çalışır; her biri ek maliyet oluşturabilir.</span>
                </label>

                <button class="runner-primary-action flex min-h-12 w-full items-center justify-center rounded-xl px-5 text-sm font-extrabold text-white disabled:cursor-not-allowed disabled:opacity-50" type="submit" data-preparation-submit @disabled($activeComparison)>Hazırlık paketlerini üret</button>
            </fieldset>
        </form>

        <aside class="self-start rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-5 sm:p-6" aria-labelledby="preparation-scope-title">
            <p class="eyebrow">Bu aşamanın sınırı</p>
            <h2 id="preparation-scope-title" class="mt-2 text-xl font-black tracking-[-0.03em]">Ne üretilecek?</h2>
            <ol class="mt-5 grid gap-3 text-xs leading-5">
                @foreach (['Başlangıç promptu', 'Gereksinimler', 'Uygulama planı', 'Bellek bankası', 'Varsayımlar ve riskler'] as $number => $label)
                    <li class="flex items-center gap-3 rounded-xl border border-[var(--line)] bg-[var(--surface-subtle)] p-3"><span class="grid h-7 w-7 shrink-0 place-items-center rounded-lg bg-[var(--violet-soft)] font-mono font-black text-[var(--violet)]">{{ $number + 1 }}</span><span class="font-bold">{{ $label }}</span></li>
                @endforeach
            </ol>
            <div class="mt-5 rounded-xl border border-[var(--violet-line)] bg-[var(--violet-soft)] p-4 text-[11px] leading-5 text-[var(--muted)]">
                Modeller soru sormadan bağımsız taslaklar hazırlar. Biçim kontrolü yapılır; içerik doğruluğu ve uygulanabilirlik sizin manuel değerlendirmenize bırakılır. Henüz proje açılmaz, kod yazılmaz, build veya rota testi yapılmaz.
            </div>
        </aside>
    </div>
</div>
@endsection
