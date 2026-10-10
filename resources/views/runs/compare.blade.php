@extends('layouts.app')

@section('content')
@php
    $viewportWidths = [
        'desktop' => 1440,
        'tablet' => 820,
        'mobile' => 390,
    ];
    $viewportLabels = [
        'desktop' => 'Masaüstü · 1440 px',
        'tablet' => 'Tablet · 820 px',
        'mobile' => 'Mobil · 390 px',
    ];
    $selectedViewportWidth = $viewportWidths[$selectedViewport];
    $stageStatusLabels = [
        'pass' => 'Geçti',
        'fail' => 'Başarısız',
        'skip' => 'Çalışmadı',
        'unknown' => 'Kayıt yok',
    ];
    $stageChangeLabels = [
        'improved' => 'Düzeldi',
        'regressed' => 'Bozuldu',
        'new_result' => 'Yeni sonuç',
        'not_run' => 'Bu denemede çalışmadı',
        'unknown' => 'Karşılaştırılamıyor',
        'unchanged' => 'Aynı',
    ];
    $previewUrl = static function ($model, string $path) use ($run): ?string {
        if ($model === null) {
            return null;
        }

        $baseUrl = route('runs.previews.show', [$run, $model], absolute: false);
        $relativePath = ltrim($path, '/');

        return $relativePath === '' ? $baseUrl.'/' : $baseUrl.'/'.$relativePath;
    };
@endphp

<div class="content-frame">
    <nav class="mb-6 flex items-center gap-2 text-xs font-semibold text-[var(--muted)]" aria-label="Sayfa yolu">
        <a class="transition-colors hover:text-[var(--forest)]" href="{{ route('dashboard') }}">Genel Bakış</a>
        <span aria-hidden="true">/</span>
        <a class="transition-colors hover:text-[var(--forest)]" href="{{ route('runs.show', $run) }}">{{ $run->name }}</a>
        <span aria-hidden="true">/</span>
        <span class="text-[var(--ink)]" aria-current="page">Görsel karşılaştırma</span>
    </nav>

    <header class="comparison-hero overflow-hidden rounded-[28px] border border-[var(--line)] px-6 py-7 sm:px-8">
        <div class="flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
            <div class="max-w-3xl">
                <p class="eyebrow text-[#67e8f9]">Canlı çıktı laboratuvarı</p>
                <h1 class="mt-3 text-3xl font-black tracking-[-0.05em] text-white sm:text-[42px]">Modelleri aynı çerçevede inceleyin</h1>
                <p class="mt-3 max-w-2xl text-sm leading-6 text-[#adb7d1]">
                    Aynı rota ve ekran genişliğinde iki üretimi yan yana değerlendirin. Önizlemeler mevcut static export dosyalarından açılır; ayrı bir sunucu başlatmanız gerekmez.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <span class="comparison-stat"><strong>{{ $previewableModels->count() }}</strong> önizleme</span>
                <span class="comparison-stat"><strong>{{ $availableRoutes->count() }}</strong> rota</span>
            </div>
        </div>
    </header>

    @if ($models->isEmpty())
        <section class="data-panel mt-6 rounded-2xl border border-dashed border-[var(--line-strong)] bg-white px-6 py-16 text-center">
            <span class="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-[var(--violet-soft)] text-xl text-[var(--violet)]" aria-hidden="true">◇</span>
            <h2 class="mt-5 text-xl font-black tracking-[-0.035em]">Karşılaştırılacak model bulunamadı</h2>
            <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-[var(--muted)]">Bu koşuda henüz model sonucu kayıtlı değil.</p>
            <a class="mt-6 inline-flex rounded-lg bg-[var(--forest)] px-4 py-2.5 text-xs font-extrabold text-white" href="{{ route('runs.show', $run) }}">Teknik rapora dön</a>
        </section>
    @else
        <form class="data-panel mt-6 grid gap-4 rounded-2xl border border-[var(--line)] bg-white p-5 lg:grid-cols-[minmax(180px,1fr)_minmax(180px,1fr)_minmax(180px,0.8fr)_minmax(170px,0.7fr)_auto] lg:items-end" method="GET" action="{{ route('runs.compare', $run) }}">
            <label class="grid gap-2 text-[10px] font-extrabold tracking-[0.1em] text-[var(--muted)] uppercase">
                Sol model
                <select class="h-11 rounded-lg border border-[var(--line)] bg-[var(--surface-subtle)] px-3 text-xs font-bold tracking-normal text-[var(--ink)] normal-case outline-none focus:border-[var(--forest)]" name="left">
                    @foreach ($models as $modelRun)
                        <option value="{{ $modelRun->id }}" @selected($leftModel?->is($modelRun))>{{ str($modelRun->model)->after('/') }}</option>
                    @endforeach
                </select>
            </label>

            <label class="grid gap-2 text-[10px] font-extrabold tracking-[0.1em] text-[var(--muted)] uppercase">
                Sağ model
                <select class="h-11 rounded-lg border border-[var(--line)] bg-[var(--surface-subtle)] px-3 text-xs font-bold tracking-normal text-[var(--ink)] normal-case outline-none focus:border-[var(--forest)]" name="right">
                    <option value="0" @selected($rightModel === null)>Tekli görünüm</option>
                    @foreach ($models as $modelRun)
                        <option value="{{ $modelRun->id }}" @selected($rightModel?->is($modelRun))>{{ str($modelRun->model)->after('/') }}</option>
                    @endforeach
                </select>
            </label>

            <label class="grid gap-2 text-[10px] font-extrabold tracking-[0.1em] text-[var(--muted)] uppercase">
                Sayfa rotası
                <select class="h-11 rounded-lg border border-[var(--line)] bg-[var(--surface-subtle)] px-3 font-mono text-xs font-bold tracking-normal text-[var(--ink)] normal-case outline-none focus:border-[var(--forest)]" name="path">
                    @foreach ($availableRoutes as $routePath)
                        <option value="{{ $routePath }}" @selected($selectedRoute === $routePath)>{{ $routePath }}</option>
                    @endforeach
                </select>
            </label>

            <label class="grid gap-2 text-[10px] font-extrabold tracking-[0.1em] text-[var(--muted)] uppercase">
                Ekran genişliği
                <select class="h-11 rounded-lg border border-[var(--line)] bg-[var(--surface-subtle)] px-3 text-xs font-bold tracking-normal text-[var(--ink)] normal-case outline-none focus:border-[var(--forest)]" name="viewport">
                    @foreach ($viewportLabels as $viewport => $label)
                        <option value="{{ $viewport }}" @selected($selectedViewport === $viewport)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <button class="h-11 rounded-lg bg-[var(--forest)] px-5 text-xs font-extrabold text-white shadow-[0_10px_24px_rgb(79_70_229/0.2)] transition hover:bg-[#4338ca]" type="submit">Görünümü güncelle</button>
        </form>

        <div class="mt-5 grid gap-5 {{ $rightModel === null ? 'grid-cols-1' : '2xl:grid-cols-2' }}" data-preview-comparison>
            @foreach ([['side' => 'Sol', 'model' => $leftModel], ['side' => 'Sağ', 'model' => $rightModel]] as $preview)
                @continue($preview['model'] === null)
                @php
                    $modelRun = $preview['model'];
                    $hasPreview = $previewableModelIds->contains($modelRun->id);
                    $latestAttempt = $resultComparisons[$modelRun->id]['attempt'] ?? null;
                @endphp
                <article class="preview-panel overflow-hidden rounded-2xl border border-[var(--line)] bg-white">
                    <header class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--line)] px-4 py-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="text-[9px] font-black tracking-[0.12em] text-[var(--forest)] uppercase">{{ $preview['side'] }}</span>
                                <span class="status-badge status-{{ $modelRun->status }}">İlk teslim · {{ $modelRun->status === 'pass' ? 'Başarılı' : 'Sorunlu' }}</span>
                                @if ($modelRun->evaluation)
                                    <span class="rounded-md bg-[var(--violet-soft)] px-2 py-1 text-[10px] font-black text-[var(--violet)]">İlk teslim puanı {{ $modelRun->evaluation->overall_score }}/10</span>
                                @endif
                            </div>
                            <h2 class="mt-1 truncate text-sm font-black">{{ str($modelRun->model)->after('/') }}</h2>
                        </div>
                        <div class="flex flex-wrap items-center justify-end gap-2">
                            @if ($rightModel !== null)
                                <a class="rounded-lg border border-[var(--line)] px-3 py-2 text-[10px] font-extrabold text-[var(--forest)] transition hover:border-[var(--forest)]" href="{{ route('runs.compare', ['benchmarkRun' => $run, 'left' => $modelRun->id, 'right' => 0, 'path' => $selectedRoute, 'viewport' => $selectedViewport]) }}">Tek başına incele</a>
                            @endif
                            @if ($hasPreview)
                                <a class="rounded-lg bg-[var(--forest)] px-3 py-2 text-[10px] font-extrabold text-white transition hover:bg-[#4338ca]" href="{{ route('runs.models.preview', ['benchmarkRun' => $run, 'modelRun' => $modelRun, 'path' => $selectedRoute]) }}" target="_blank" rel="noopener noreferrer">Yeni sekmede aç ↗</a>
                                <a class="rounded-lg border border-[#d7ccff] bg-[var(--violet-soft)] px-3 py-2 text-[10px] font-extrabold text-[var(--violet)] transition hover:border-[var(--violet)]" href="{{ route('runs.models.preview', ['benchmarkRun' => $run, 'modelRun' => $modelRun, 'path' => $selectedRoute, 'review' => 1]) }}" target="_blank" rel="noopener noreferrer">{{ $modelRun->evaluation ? 'Puanı düzenle' : 'Puanla' }}</a>
                            @endif
                        </div>
                    </header>
                    @if ($hasPreview)
                        @if (($latestAttempt?->result['overallStatus'] ?? null) === 'pass')
                            <p class="border-b border-[var(--line)] bg-[var(--success-soft)] px-4 py-2 text-[10px] font-bold text-[var(--success)]">Güncel çıktı · Deneme {{ $latestAttempt->attempt_number }} doğrulamaları geçti</p>
                        @endif
                        <div class="preview-stage" data-preview-stage>
                            <div class="preview-canvas" data-preview-canvas data-viewport-width="{{ $selectedViewportWidth }}">
                                <iframe
                                    class="preview-frame"
                                    data-preview-frame
                                    src="{{ $previewUrl($modelRun, $selectedRoute) }}"
                                    title="{{ str($modelRun->model)->after('/') }} güncel önizlemesi"
                                    loading="eager"
                                    referrerpolicy="no-referrer"
                                    sandbox="allow-scripts"
                                ></iframe>
                            </div>
                        </div>
                    @else
                        <div class="grid min-h-56 place-items-center bg-[var(--surface-subtle)] px-6 py-10 text-center">
                            <div>
                                <p class="text-sm font-black text-[var(--ink)]">Görüntülenebilir çıktı yok</p>
                                <p class="mt-2 max-w-sm text-xs leading-5 text-[var(--muted)]">Bu model için geçerli bir static export bulunamadı. Aşağıdaki aşama sonuçları yine karşılaştırılabilir.</p>
                            </div>
                        </div>
                    @endif
                </article>
            @endforeach
        </div>

        @if ($hasRecoveryAttempts)
            <section class="data-panel mt-6 rounded-2xl border border-[var(--line)] bg-white p-5 sm:p-6" aria-labelledby="recovery-comparison-title">
                <p class="eyebrow">Kurtarma karşılaştırması</p>
                <h2 id="recovery-comparison-title" class="mt-1 text-xl font-black tracking-[-0.035em]">İlk teslim ve son tamamlanan deneme</h2>
                <p class="mt-2 max-w-3xl text-xs leading-5 text-[var(--muted)]">Aşama sonuçları yan yana gösterilir. İlk teslimin puanı ve ana sonuç kaydı korunur; yukarıdaki görsel alan varsa mevcut static export dosyasını gösterir.</p>

                <div class="mt-5 grid gap-4 2xl:grid-cols-2">
                    @foreach ([['side' => 'Sol', 'model' => $leftModel], ['side' => 'Sağ', 'model' => $rightModel]] as $selection)
                        @continue($selection['model'] === null)
                        @php
                            $modelRun = $selection['model'];
                            $comparison = $resultComparisons[$modelRun->id];
                            $attempt = $comparison['attempt'];
                        @endphp
                        <article class="min-w-0 overflow-hidden rounded-xl border border-[var(--line)] bg-[var(--surface-subtle)]">
                            <div class="flex flex-wrap items-start justify-between gap-2 border-b border-[var(--line)] bg-white px-4 py-3">
                                <div>
                                    <p class="text-[9px] font-black tracking-[0.1em] text-[var(--forest)] uppercase">{{ $selection['side'] }} model</p>
                                    <h3 class="mt-1 text-sm font-black">{{ str($modelRun->model)->after('/') }}</h3>
                                </div>
                                @if ($attempt)
                                    <span class="status-badge status-{{ ($attempt->result['overallStatus'] ?? null) === 'pass' ? 'pass' : 'fail' }}">Deneme {{ $attempt->attempt_number }} · {{ ($attempt->result['overallStatus'] ?? null) === 'pass' ? 'Başarılı' : 'Sorunlu' }}</span>
                                @endif
                            </div>

                            @if ($attempt)
                                <p class="px-4 pt-3 text-[10px] text-[var(--muted)]">{{ $attempt->mode === 'validate' ? 'Yalnız doğrulama' : 'Model devamı' }} · {{ $attempt->finished_at?->locale('tr')->translatedFormat('j M Y · H:i') }}</p>
                                <div class="overflow-x-auto px-4 pb-4 pt-3">
                                    <table class="w-full min-w-[440px] border-collapse text-left text-[10px]" aria-label="{{ str($modelRun->model)->after('/') }} ilk teslim ve kurtarma aşama karşılaştırması">
                                        <thead class="text-[var(--muted)]">
                                            <tr class="border-b border-[var(--line)]">
                                                <th scope="col" class="px-2 py-2 font-black">Aşama</th>
                                                <th scope="col" class="px-2 py-2 font-black">İlk teslim</th>
                                                <th scope="col" class="px-2 py-2 font-black">Deneme {{ $attempt->attempt_number }}</th>
                                                <th scope="col" class="px-2 py-2 font-black">Fark</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($comparison['rows'] as $row)
                                                <tr class="border-b border-[var(--line)] last:border-0" data-stage-change="{{ $row['change'] }}">
                                                    <th scope="row" class="px-2 py-2.5 font-bold text-[var(--ink)]">{{ $row['label'] }}</th>
                                                    <td class="px-2 py-2.5"><span class="status-badge status-{{ $row['initial'] }}">{{ $stageStatusLabels[$row['initial']] }}</span></td>
                                                    <td class="px-2 py-2.5"><span class="status-badge status-{{ $row['latest'] }}">{{ $stageStatusLabels[$row['latest']] }}</span></td>
                                                    <td class="px-2 py-2.5 font-bold @if ($row['change'] === 'improved') text-[var(--success)] @elseif ($row['change'] === 'regressed') text-[var(--red)] @else text-[var(--muted)] @endif">{{ $stageChangeLabels[$row['change']] }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @else
                                <p class="px-4 py-5 text-xs leading-5 text-[var(--muted)]">Bu model için tamamlanmış kurtarma denemesi henüz yok. İlk teslim: {{ $modelRun->status === 'pass' ? 'başarılı' : 'sorunlu' }}.</p>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        <aside class="mt-4 flex items-start gap-3 rounded-xl border border-[#d7ccff] bg-[var(--violet-soft)] px-4 py-3 text-xs leading-5 text-[#5b35ad]">
            <span class="font-black" aria-hidden="true">i</span>
            <p><strong>Güvenli önizleme:</strong> Model çıktıları kısıtlı bir çerçevede çalışır. Harici pencere, form gönderimi ve BenchForge sayfasına erişim kapalıdır.</p>
        </aside>
    @endif
</div>
@endsection
