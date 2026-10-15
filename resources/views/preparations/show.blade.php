@extends('layouts.app')

@section('content')
@php
    $sections = \App\Models\PreparationPackage::SECTION_LABELS;
    $scoreLabels = \App\Models\PreparationPackage::EVALUATION_LABELS;
    $packages = $comparison->packages;
    $firstPackage = $packages->first();
    $secondPackage = $packages->skip(1)->first();
    $statusLabels = [
        'pending' => 'Bekliyor', 'starting' => 'Başlatılıyor', 'running' => 'Üretiliyor',
        'stopping' => 'Durduruluyor', 'completed' => 'Tamamlandı', 'partial' => 'Kısmen tamamlandı',
        'failed' => 'Başarısız', 'stopped' => 'Durduruldu',
    ];
    $statusTones = [
        'pending' => 'unknown', 'starting' => 'running', 'running' => 'running', 'stopping' => 'running',
        'completed' => 'pass', 'partial' => 'unknown', 'failed' => 'fail', 'stopped' => 'unknown',
    ];
    $completedCount = $packages->where('status', 'completed')->count();
    $totalCount = count((array) $comparison->selected_models);
@endphp

<div class="content-frame" data-preparation-show data-status-url="{{ route('preparations.status', $comparison) }}" data-active="{{ $comparison->isActive() ? 'true' : 'false' }}" data-current-status="{{ $comparison->status }}">
    <nav class="mb-6 flex items-center gap-2 text-xs font-semibold text-[var(--muted)]" aria-label="Sayfa yolu">
        <a class="hover:text-[var(--forest)]" href="{{ route('preparations.index') }}">Hazırlık Karşılaştırmaları</a>
        <span aria-hidden="true">/</span>
        <span class="min-w-0 truncate text-[var(--ink)]" aria-current="page">{{ $comparison->title }}</span>
    </nav>

    <header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="eyebrow">Hazırlık paketi karşılaştırması · {{ $comparison->contract_version }}</p>
            <h1 class="mt-2 break-words text-3xl font-black tracking-[-0.05em] sm:text-[38px]">{{ $comparison->title }}</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-[var(--muted)]">Aynı kaynak şartnameyle hazırlanan belgeleri bölüm bölüm okuyun, indirin ve kendi değerlendirmenizi kaydedin.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a class="rounded-xl border border-[var(--line)] bg-[var(--surface)] px-4 py-2.5 text-xs font-extrabold text-[var(--forest)]" href="{{ route('preparations.index') }}">Geçmişe dön</a>
            <a class="rounded-xl border border-[var(--line)] bg-[var(--surface)] px-4 py-2.5 text-xs font-extrabold text-[var(--forest)]" href="{{ route('preparations.create') }}">Yeni hazırlık</a>
        </div>
    </header>

    @if (session('status'))
        <div class="mt-6 rounded-xl border border-[var(--success-line)] bg-[var(--success-soft)] px-4 py-3 text-sm font-semibold text-[var(--success)]" role="status">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="mt-6 rounded-xl border border-[var(--red-line)] bg-[var(--red-soft)] px-4 py-3 text-sm text-[var(--red)]" role="alert">
            <strong class="font-extrabold">İnceleme kaydedilemedi.</strong>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-xs leading-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <section class="runner-card mt-7 rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-5 sm:p-6" aria-labelledby="preparation-progress-title">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="eyebrow">Üretim durumu</p>
                <h2 id="preparation-progress-title" class="mt-1 text-xl font-black tracking-[-0.03em]">Paket durumu</h2>
                <p class="mt-2 text-xs leading-5 text-[var(--muted)]" data-preparation-message>{{ $comparison->message ?: 'Durum bilgisi bekleniyor.' }}</p>
            </div>
            <span class="status-badge status-{{ $statusTones[$comparison->status] ?? 'unknown' }}" data-preparation-comparison-status><span class="status-dot"></span><span data-preparation-comparison-status-label>{{ $statusLabels[$comparison->status] ?? 'Bilinmiyor' }}</span></span>
        </div>
        <div class="mt-5 flex flex-wrap items-center gap-3 text-xs font-semibold text-[var(--muted)]">
            <span><strong class="text-[var(--ink)]" data-preparation-progress>{{ $completedCount }}/{{ $totalCount }}</strong> paket tamamlandı</span>
            <span>·</span>
            <span>{{ $comparison->provider === 'opencode-go' ? 'OpenCode Go' : 'OpenRouter' }}</span>
            <span>·</span>
            <span>Modeller sırayla çalışır</span>
            @if ($comparison->started_at)<span>· Başlangıç {{ $comparison->started_at->locale('tr')->translatedFormat('j M Y · H:i') }}</span>@endif
        </div>
        <div class="mt-4 hidden rounded-xl border border-[var(--indigo-line)] bg-[var(--indigo-soft)] p-3 text-xs leading-5 text-[var(--forest)]" role="status" data-preparation-refresh-notice>
            Yeni sonuçlar geldi. Belgeleri güncellemek için <a class="font-extrabold underline" href="{{ route('preparations.show', $comparison) }}">Sonuçları yenile</a>. Sayfa, yazdığınız inceleme notlarını korumak için kendiliğinden yenilenmez.
        </div>
        @if ($comparison->isActive())
            <form class="mt-5" method="POST" action="{{ route('preparations.stop', $comparison) }}" data-preparation-stop>
                @csrf
                @method('DELETE')
                <button class="rounded-lg border border-[var(--red-line)] bg-[var(--red-soft)] px-3 py-2.5 text-xs font-extrabold text-[var(--red)] disabled:opacity-50" type="submit" @disabled($comparison->status === 'stopping')>Üretimi durdur</button>
            </form>
        @endif
    </section>

    <section class="mt-6 grid gap-4 lg:grid-cols-2" aria-label="Karşılaştırma kaynağı">
        <details class="rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-5">
            <summary class="cursor-pointer text-sm font-black">Kaynak proje şartnamesi</summary>
            <p class="mt-3 text-[10px] text-[var(--muted)]">SHA-256: <code class="break-all">{{ $comparison->source_hash }}</code></p>
            <pre class="mt-4 max-h-72 overflow-auto whitespace-pre-wrap break-words rounded-xl bg-[var(--surface-subtle)] p-4 font-mono text-[11px] leading-5">{{ $comparison->specification }}</pre>
        </details>
        <details class="rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-5">
            <summary class="cursor-pointer text-sm font-black">Teknoloji ve kapsam sınırları</summary>
            <pre class="mt-4 max-h-72 overflow-auto whitespace-pre-wrap break-words rounded-xl bg-[var(--surface-subtle)] p-4 font-mono text-[11px] leading-5">{{ $comparison->technologies }}</pre>
        </details>
    </section>

    <section class="mt-6 rounded-2xl border border-[var(--violet-line)] bg-[var(--violet-soft)] px-5 py-4 text-xs leading-5 text-[var(--muted)]" aria-label="Değerlendirme sınırları">
        <strong class="text-[var(--violet)]">Bu bir hazırlık karşılaştırmasıdır.</strong> Biçim kontrolü içerik doğruluğunu ölçmez. Gerçek geliştirme, kod kalitesi, build, rota ve çalışma zamanı <strong>henüz test edilmedi</strong>. Bu sürümde otomatik ücretli hakem modeli kullanılmaz; içerik puanlarını siz verirsiniz.
    </section>

    @if ($packages->isNotEmpty())
        <section class="runner-card mt-7 rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-5 sm:p-6" aria-labelledby="preparation-compare-title" data-preparation-compare>
            <div>
                <p class="eyebrow">Aynı bölüm, farklı model</p>
                <h2 id="preparation-compare-title" class="mt-1 text-xl font-black tracking-[-0.03em]">Yan yana incele</h2>
                <p class="mt-2 text-xs leading-5 text-[var(--muted)]">İki paket seçin; aynı bölümü karşılaştırın. Tam belgeler ve manuel puanlama aşağıdadır.</p>
            </div>
            <div class="mt-5 grid gap-3 sm:grid-cols-3">
                <label class="grid gap-1 text-xs font-extrabold" for="preparation-left">Sol model
                    <select class="h-11 min-w-0 rounded-lg border border-[var(--line)] bg-[var(--surface)] px-3 text-xs" id="preparation-left" data-preparation-left>
                        @foreach ($packages as $package)<option value="{{ $package->id }}" @selected($firstPackage?->is($package))>{{ $package->model_name ?: $package->model_id }}</option>@endforeach
                    </select>
                </label>
                <label class="grid gap-1 text-xs font-extrabold" for="preparation-right">Sağ model
                    <select class="h-11 min-w-0 rounded-lg border border-[var(--line)] bg-[var(--surface)] px-3 text-xs" id="preparation-right" data-preparation-right>
                        <option value="">Tekli görünüm</option>
                        @foreach ($packages as $package)<option value="{{ $package->id }}" @selected($secondPackage?->is($package))>{{ $package->model_name ?: $package->model_id }}</option>@endforeach
                    </select>
                </label>
                <label class="grid gap-1 text-xs font-extrabold" for="preparation-section">Bölüm
                    <select class="h-11 min-w-0 rounded-lg border border-[var(--line)] bg-[var(--surface)] px-3 text-xs" id="preparation-section" data-preparation-section>
                        @foreach ($sections as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                    </select>
                </label>
            </div>
            <div class="mt-5 grid gap-4 xl:grid-cols-2" data-preparation-compare-grid>
                @foreach ([['side' => 'left', 'package' => $firstPackage], ['side' => 'right', 'package' => $secondPackage]] as $selection)
                    @php $selectedPackage = $selection['package']; @endphp
                    <article class="min-w-0 rounded-xl border border-[var(--line)] bg-[var(--surface-subtle)] p-4 {{ $selectedPackage ? '' : 'hidden' }}" data-preparation-side="{{ $selection['side'] }}">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-[9px] font-black tracking-[0.1em] text-[var(--forest)] uppercase">{{ $selection['side'] === 'left' ? 'Sol' : 'Sağ' }} paket</p>
                                <h3 class="mt-1 break-words text-sm font-black" data-preparation-side-name>{{ ($selectedPackage?->model_name ?: $selectedPackage?->model_id) ?: '—' }}</h3>
                                <p class="mt-1 text-[10px] text-[var(--muted)]" data-preparation-side-status>{{ $statusLabels[$selectedPackage?->status] ?? 'Bekliyor' }}</p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button class="rounded-lg border border-[var(--line)] bg-[var(--surface)] px-2.5 py-1.5 text-[10px] font-extrabold text-[var(--forest)]" type="button" data-preparation-copy="{{ $selection['side'] }}">Metni kopyala</button>
                                <a class="rounded-lg border border-[var(--line)] bg-[var(--surface)] px-2.5 py-1.5 text-[10px] font-extrabold text-[var(--forest)] {{ $selectedPackage?->status === 'completed' ? '' : 'hidden' }}" href="{{ $selectedPackage ? route('preparations.packages.download', [$comparison, $selectedPackage, 'section' => 'startup_prompt']) : '#' }}" data-preparation-side-download>Bölümü indir</a>
                            </div>
                        </div>
                        <pre class="mt-4 max-h-[42rem] min-h-48 overflow-auto whitespace-pre-wrap break-words rounded-lg border border-[var(--line)] bg-[var(--surface)] p-4 font-mono text-[11px] leading-5" data-preparation-side-content>{{ $selectedPackage?->status === 'completed' ? ($selectedPackage->startup_prompt ?: 'Bu bölüm boş.') : 'Paket henüz hazır değil.' }}</pre>
                    </article>
                @endforeach
            </div>
            <p class="mt-3 text-[10px] text-[var(--muted)]" role="status" aria-live="polite" data-preparation-copy-status></p>
        </section>

        <section class="mt-8" aria-labelledby="preparation-packages-title">
            <p class="eyebrow">Model teslimleri</p>
            <h2 id="preparation-packages-title" class="mt-1 text-xl font-black tracking-[-0.03em]">Tüm hazırlık paketleri</h2>
            <div class="mt-5 grid gap-5">
                @foreach ($packages as $package)
                    @php
                        $evaluation = (array) ($package->manual_evaluation ?? []);
                        $reviewInputPackage = old('review_package_id');
                        $restoringReview = is_scalar($reviewInputPackage) && (string) $reviewInputPackage === (string) $package->id;
                        $reviewNotes = $restoringReview ? (is_string(old('review_notes')) ? old('review_notes') : '') : $package->review_notes;
                        $issueSeverity = $restoringReview && is_string(old('issue_severity')) ? old('issue_severity') : ($package->issue_severity ?? 'none');
                    @endphp
                    <article class="runner-card min-w-0 rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-5 sm:p-6" data-preparation-package="{{ $package->id }}" data-preparation-package-status="{{ $package->status }}" data-preparation-document-ready="{{ $package->status === 'completed' ? 'true' : 'false' }}" data-preparation-package-name="{{ $package->model_name ?: $package->model_id }}" data-preparation-download-base="{{ route('preparations.packages.download', [$comparison, $package]) }}">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-[9px] font-black tracking-[0.1em] text-[var(--forest)] uppercase">Model hazırlık paketi</p>
                                <h3 class="mt-1 break-words text-lg font-black">{{ $package->model_name ?: $package->model_id }}</h3>
                                <code class="mt-1 block break-all text-[10px] text-[var(--muted)]">{{ $package->model_id }}</code>
                            </div>
                            <span class="status-badge status-{{ $statusTones[$package->status] ?? 'unknown' }}" data-preparation-package-badge><span class="status-dot"></span><span data-preparation-package-status-label>{{ $statusLabels[$package->status] ?? 'Bilinmiyor' }}</span></span>
                        </div>
                        <div class="mt-4 flex flex-wrap gap-x-5 gap-y-2 text-[11px] text-[var(--muted)]">
                            <span>Süre: <strong class="text-[var(--ink)]" data-preparation-duration>{{ $package->duration_seconds !== null ? number_format((float) $package->duration_seconds, 1, ',', '.').' sn' : '—' }}</strong></span>
                            <span>Maliyet: <strong class="text-[var(--ink)]" data-preparation-cost>{{ $package->cost_usd !== null ? '$'.number_format((float) $package->cost_usd, 8, '.', ',') : 'Veri yok' }}</strong></span>
                            <span>Token: <strong class="text-[var(--ink)]" data-preparation-tokens>{{ $package->input_tokens !== null || $package->output_tokens !== null ? ($package->input_tokens !== null ? number_format($package->input_tokens, 0, ',', '.') : '—').' girdi / '.($package->output_tokens !== null ? number_format($package->output_tokens, 0, ',', '.') : '—').' çıktı' : 'Veri yok' }}</strong></span>
                        </div>
                        <p class="mt-3 text-xs leading-5 text-[var(--red)] {{ $package->error_message ? '' : 'hidden' }}" data-preparation-package-error>{{ $package->error_message }}</p>

                        @if ($package->status === 'completed')
                            <div class="mt-5 flex flex-wrap gap-2">
                                <a class="rounded-lg bg-[var(--forest)] px-3 py-2 text-[10px] font-extrabold text-white" href="{{ route('preparations.packages.download', [$comparison, $package]) }}">Tam paketi Markdown indir ↓</a>
                                <span class="self-center text-[10px] text-[var(--muted)]">Her bölüm ayrıca aşağıdan indirilebilir.</span>
                            </div>
                        @endif

                        <div class="mt-5 grid gap-2">
                            @foreach ($sections as $key => $label)
                                <details class="min-w-0 rounded-xl border border-[var(--line)] bg-[var(--surface-subtle)] p-3.5" @if ($loop->first && $package->status === 'completed') open @endif>
                                    <summary class="cursor-pointer text-xs font-extrabold">{{ $label }}</summary>
                                    @if ($package->status === 'completed')
                                        <a class="mt-3 inline-flex text-[10px] font-extrabold text-[var(--forest)] underline" href="{{ route('preparations.packages.download', [$comparison, $package, 'section' => $key]) }}">Bu bölümü Markdown indir ↓</a>
                                    @endif
                                    <pre class="mt-3 max-h-[36rem] overflow-auto whitespace-pre-wrap break-words rounded-lg border border-[var(--line)] bg-[var(--surface)] p-4 font-mono text-[11px] leading-5" data-preparation-source-section="{{ $key }}">{{ $package->{$key} ?: 'Henüz üretilmedi.' }}</pre>
                                </details>
                            @endforeach
                        </div>

                        @if ($package->structural_checks)
                            <div class="mt-5 rounded-xl border border-[var(--line)] bg-[var(--surface)] p-4">
                                <h4 class="text-xs font-black">Biçim kontrolleri</h4>
                                <p class="mt-1 text-[10px] leading-4 text-[var(--muted)]">Bu kontroller belge yapısını inceler; içeriğin doğru veya uygulanabilir olduğunu kanıtlamaz.</p>
                                <ul class="mt-3 grid gap-2 sm:grid-cols-2">
                                    @foreach ((array) $package->structural_checks as $check)
                                        <li class="rounded-lg border border-[var(--line)] bg-[var(--surface-subtle)] p-3 text-[10px] leading-4">
                                            <strong class="{{ ($check['status'] ?? null) === 'pass' ? 'text-[var(--success)]' : 'text-[var(--red)]' }}">{{ ($check['status'] ?? null) === 'pass' ? '✓' : '×' }} {{ $check['label'] ?? $check['key'] ?? 'Kontrol' }}</strong>
                                            <p class="mt-1 text-[var(--muted)]">{{ $check['message'] ?? '' }}</p>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if ($package->status === 'completed')
                            <form class="mt-5 rounded-xl border border-[var(--violet-line)] bg-[var(--violet-soft)] p-4 sm:p-5" method="POST" action="{{ route('preparations.packages.evaluation.update', [$comparison, $package]) }}">
                                @csrf
                                @method('PUT')
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <h4 class="text-sm font-black">Manuel değerlendirme</h4>
                                        <p class="mt-1 text-[10px] leading-4 text-[var(--muted)]">Puanlar sizin yorumunuzdur; benchmark teknik skoru veya otomatik hakem sonucu değildir.</p>
                                    </div>
                                    @if ($package->reviewed_at)<span class="text-[10px] text-[var(--muted)]">Son inceleme {{ $package->reviewed_at->locale('tr')->translatedFormat('j M Y · H:i') }}</span>@endif
                                </div>
                                <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                                    @foreach ($scoreLabels as $key => $label)
                                        @php $scoreValue = $restoringReview && is_scalar(old($key)) ? old($key) : ($evaluation[$key] ?? null); @endphp
                                        <label class="grid gap-1 text-[10px] font-extrabold" for="review-{{ $package->id }}-{{ $key }}">{{ $label }}
                                            <select class="h-10 rounded-lg border border-[var(--violet-line)] bg-[var(--surface)] px-2.5 text-xs" id="review-{{ $package->id }}-{{ $key }}" name="{{ $key }}" required>
                                                <option value="">Seçin</option>
                                                @for ($score = 1; $score <= 10; $score++)<option value="{{ $score }}" @selected($scoreValue == $score)>{{ $score }}/10</option>@endfor
                                            </select>
                                        </label>
                                    @endforeach
                                </div>
                                <div class="mt-4 grid gap-3 lg:grid-cols-[220px_minmax(0,1fr)]">
                                    <label class="grid gap-1 text-[10px] font-extrabold" for="review-{{ $package->id }}-severity">Sorun düzeyi
                                        <select class="h-10 rounded-lg border border-[var(--violet-line)] bg-[var(--surface)] px-2.5 text-xs" id="review-{{ $package->id }}-severity" name="issue_severity" required>
                                            @foreach (['none' => 'Sorun yok', 'minor' => 'Küçük', 'major' => 'Önemli', 'critical' => 'Kritik'] as $key => $label)<option value="{{ $key }}" @selected($issueSeverity === $key)>{{ $label }}</option>@endforeach
                                        </select>
                                    </label>
                                    <label class="grid gap-1 text-[10px] font-extrabold" for="review-{{ $package->id }}-notes">İnceleme notları · isteğe bağlı
                                        <textarea class="min-h-24 resize-y rounded-lg border border-[var(--violet-line)] bg-[var(--surface)] px-3 py-2 text-xs leading-5" id="review-{{ $package->id }}-notes" name="review_notes" maxlength="5000" placeholder="Güçlü yanlar, eksikler, düzeltme önerileri…">{{ $reviewNotes }}</textarea>
                                    </label>
                                </div>
                                <button class="mt-4 rounded-lg bg-[var(--forest)] px-4 py-2.5 text-xs font-extrabold text-white" type="submit">Değerlendirmeyi kaydet</button>
                            </form>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>
    @else
        <section class="mt-7 rounded-2xl border border-dashed border-[var(--line-strong)] bg-[var(--surface)] p-8 text-center">
            <h2 class="text-lg font-black">Henüz model paketi yok</h2>
            <p class="mt-2 text-xs text-[var(--muted)]">Üretim başladığında seçilen modeller burada görünecek.</p>
        </section>
    @endif
</div>
@endsection
