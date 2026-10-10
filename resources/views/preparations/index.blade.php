@extends('layouts.app')

@section('content')
@php
    $statusLabels = [
        'starting' => 'Başlatılıyor',
        'running' => 'Üretiliyor',
        'stopping' => 'Durduruluyor',
        'completed' => 'Tamamlandı',
        'partial' => 'Kısmen tamamlandı',
        'failed' => 'Başarısız',
        'stopped' => 'Durduruldu',
    ];
    $statusTones = [
        'starting' => 'running', 'running' => 'running', 'stopping' => 'running',
        'completed' => 'pass', 'partial' => 'unknown', 'failed' => 'fail', 'stopped' => 'unknown',
    ];
@endphp

<div class="content-frame">
    <header class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="eyebrow">Hazırlık laboratuvarı</p>
            <h1 class="mt-2 text-3xl font-black tracking-[-0.05em] sm:text-[38px]">Hazırlık Karşılaştırmaları</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-[var(--muted)]">Aynı proje şartnamesinden üretilen başlangıç promptlarını, gereksinimleri, planları ve bellek notlarını karşılaştırın. Bu aşamada proje kodu üretilmez.</p>
        </div>
        <a class="runner-primary-action inline-flex min-h-11 items-center justify-center rounded-xl px-5 text-xs font-extrabold text-white" href="{{ route('preparations.create') }}">Yeni karşılaştırma oluştur →</a>
    </header>

    @if (session('status'))
        <div class="mt-6 rounded-xl border border-[#b9ead8] bg-[var(--success-soft)] px-4 py-3 text-sm font-semibold text-[var(--success)]" role="status">{{ session('status') }}</div>
    @endif

    @if ($activeComparison)
        <section class="mt-7 flex flex-col gap-3 rounded-2xl border border-[#c7d2fe] bg-[#eef2ff] p-5 sm:flex-row sm:items-center sm:justify-between" aria-label="Etkin hazırlık karşılaştırması">
            <div>
                <p class="text-[10px] font-black tracking-[0.1em] text-[var(--forest)] uppercase">Şu anda hazırlanıyor</p>
                <h2 class="mt-1 text-base font-black">{{ $activeComparison->title }}</h2>
                <p class="mt-1 text-xs leading-5 text-[var(--muted)]">Modeller sırayla çalışır. Durumu ve tamamlanan belgeleri ayrıntı ekranında izleyebilirsiniz.</p>
            </div>
            <a class="inline-flex shrink-0 rounded-lg bg-[var(--forest)] px-4 py-2.5 text-xs font-extrabold text-white" href="{{ route('preparations.show', $activeComparison) }}">Canlı durumu aç →</a>
        </section>
    @endif

    <section class="mt-7" aria-labelledby="preparation-history-title">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="eyebrow">Kalıcı kayıtlar</p>
                <h2 id="preparation-history-title" class="mt-1 text-xl font-black tracking-[-0.03em]">Geçmiş hazırlıklar</h2>
            </div>
            <span class="sync-pill">{{ $comparisons->total() }} karşılaştırma</span>
        </div>

        @if ($comparisons->isEmpty())
            <div class="rounded-2xl border border-dashed border-[var(--line-strong)] bg-white px-6 py-14 text-center">
                <h3 class="text-lg font-black">Henüz hazırlık paketi yok</h3>
                <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-[var(--muted)]">İlk karşılaştırmayı oluşturduğunuzda model belgeleri ve manuel notlar burada saklanır.</p>
                <a class="mt-5 inline-flex rounded-lg bg-[var(--forest)] px-4 py-2.5 text-xs font-extrabold text-white" href="{{ route('preparations.create') }}">İlk hazırlığı oluştur</a>
            </div>
        @else
            <div class="grid gap-4 xl:grid-cols-2">
                @foreach ($comparisons as $comparison)
                    @php
                        $completedCount = $comparison->packages->where('status', 'completed')->count();
                        $modelCount = count((array) $comparison->selected_models);
                    @endphp
                    <article class="runner-card flex min-w-0 flex-col rounded-2xl border border-[var(--line)] bg-white p-5 sm:p-6">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-[9px] font-black tracking-[0.1em] text-[var(--forest)] uppercase">{{ $comparison->provider === 'opencode-go' ? 'OpenCode Go' : 'OpenRouter' }} · {{ $comparison->created_at?->locale('tr')->translatedFormat('j M Y · H:i') }}</p>
                                <h3 class="mt-2 break-words text-lg font-black tracking-[-0.03em]">{{ $comparison->title }}</h3>
                            </div>
                            <span class="status-badge status-{{ $statusTones[$comparison->status] ?? 'unknown' }}">{{ $statusLabels[$comparison->status] ?? 'Bilinmiyor' }}</span>
                        </div>
                        <p class="mt-3 line-clamp-2 text-xs leading-5 text-[var(--muted)]">{{ $comparison->message ?: 'Model hazırlık belgeleri ve değerlendirmeleri.' }}</p>
                        <div class="mt-4 flex flex-wrap gap-2 text-[10px] font-semibold text-[var(--muted)]">
                            <span class="rounded-lg bg-[var(--surface-subtle)] px-2.5 py-1.5">{{ $completedCount }}/{{ $modelCount }} paket tamamlandı</span>
                            <span class="rounded-lg bg-[var(--surface-subtle)] px-2.5 py-1.5">Geliştirme testi: henüz yapılmadı</span>
                        </div>
                        <a class="mt-5 inline-flex w-fit rounded-lg border border-[var(--line)] px-4 py-2.5 text-xs font-extrabold text-[var(--forest)] transition hover:border-[var(--forest)]" href="{{ route('preparations.show', $comparison) }}">Belgeleri ve incelemeleri aç →</a>
                    </article>
                @endforeach
            </div>
            <div class="mt-6">{{ $comparisons->links() }}</div>
        @endif
    </section>
</div>
@endsection
