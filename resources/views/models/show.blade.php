@extends('layouts.app')

@section('content')
@php
    $stageLabels = ['opencode' => 'Üretim', 'lint' => 'Lint', 'build' => 'Build', 'export' => 'Export', 'routes' => 'Rotalar', 'runtime' => 'Runtime'];
    $severityLabels = ['none' => 'Sorun yok', 'minor' => 'Küçük', 'major' => 'Önemli', 'critical' => 'Kritik'];
    $formatDuration = static function (int $seconds): string {
        if ($seconds < 60) return $seconds.' sn';
        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) return $minutes.' dk'.($seconds % 60 ? ' '.($seconds % 60).' sn' : '');
        return intdiv($minutes, 60).' sa'.($minutes % 60 ? ' '.($minutes % 60).' dk' : '');
    };
@endphp

<div class="content-frame">
    <nav class="mb-6 flex items-center gap-2 text-xs font-semibold text-[var(--muted)]" aria-label="Sayfa yolu">
        <a class="hover:text-[var(--forest)]" href="{{ route('models.index', ['condition' => $environmentProfile]) }}">Modeller · {{ $environmentProfiles[$environmentProfile] }}</a>
        <span aria-hidden="true">/</span>
        <span class="text-[var(--ink)]" aria-current="page">{{ $model['display_name'] }}</span>
    </nav>

    <section class="overview-hero relative overflow-hidden rounded-[28px] px-6 py-7 text-white sm:px-9 sm:py-9">
        <div class="relative grid gap-8 lg:grid-cols-[1fr_auto] lg:items-end">
            <div>
                <p class="text-[10px] font-extrabold tracking-[0.14em] text-[#67e8f9] uppercase">Model karnesi</p>
                <h1 class="mt-4 text-3xl font-black tracking-[-0.05em] sm:text-[42px]">{{ $model['display_name'] }}</h1>
                <code class="mt-2 block text-xs text-[#8f9ab6]">{{ $model['provider_model'] }}</code>
                <p class="mt-5 max-w-2xl text-sm leading-6 text-[#b7c0d5]">{{ $environmentProfiles[$environmentProfile] }} altındaki {{ $model['run_count'] }} benchmark koşusunun teknik ve manuel sonuçlarının birleştirilmiş görünümü.</p>
            </div>
            <div class="min-w-44 rounded-2xl border border-white/10 bg-white/5 p-5 text-center backdrop-blur-sm">
                <span class="block text-[9px] font-extrabold tracking-[0.12em] text-[#8f9ab6] uppercase">Birleşik puan</span>
                @if ($model['composite_score'] !== null)
                    <strong class="mt-2 block text-4xl font-black text-[#67e8f9]">{{ number_format($model['composite_score'], 1, ',', '.') }}</strong>
                    <span class="text-[10px] font-bold text-[#8f9ab6]">100 üzerinden</span>
                @else
                    <strong class="mt-3 block text-sm font-black text-[#fbbf24]">Puanlama bekliyor</strong>
                    <span class="mt-2 block text-[10px] text-[#8f9ab6]">Önce en az bir manuel inceleme tamamlayın</span>
                @endif
            </div>
        </div>
    </section>

    <section class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Karne metrikleri">
        <article class="metric-card metric-positive"><p class="metric-label">Teknik güvenilirlik</p><p class="metric-value">%{{ number_format($model['technical']['score'], 1, ',', '.') }}</p><p class="metric-detail">{{ $model['measured_run_count'] }} koşunun eşit ağırlıklı ortalaması{{ $model['mixed_stage_sets'] ? ' · farklı aşama setleri' : '' }}</p></article>
        <article class="metric-card metric-neutral"><p class="metric-label">Manuel kalite</p><p class="metric-value">{{ $model['manual']['average'] === null ? '—' : number_format($model['manual']['average'], 1, ',', '.') }}</p><p class="metric-detail">{{ $model['manual']['count'] }}/{{ $model['run_count'] }} koşu incelendi</p></article>
        <article class="metric-card metric-neutral"><p class="metric-label">Tam başarılı koşu</p><p class="metric-value">%{{ number_format($model['passed_run_rate'], 1, ',', '.') }}</p><p class="metric-detail">{{ $model['passed_run_count'] }}/{{ $model['measured_run_count'] }} teslim tamamen temiz</p></article>
        <article class="metric-card metric-neutral"><p class="metric-label">Ortalama süre</p><p class="metric-value text-[26px]">{{ $formatDuration($model['average_duration_seconds']) }}</p><p class="metric-detail">Model başına üretim ve kontrol süresi</p></article>
    </section>

    <div class="mt-8 grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
        <section class="data-panel rounded-2xl border border-[var(--line)] bg-white p-5 sm:p-6" aria-labelledby="criteria-title">
            <p class="eyebrow">Kalite profili</p>
            <h2 id="criteria-title" class="mt-1 text-xl font-black tracking-[-0.035em]">Manuel puanlar</h2>
            @if ($model['manual']['count'] === 0)
                <div class="mt-5 rounded-xl border border-dashed border-[var(--line-strong)] bg-[var(--surface-subtle)] p-6 text-center text-sm text-[var(--muted)]">Bu model için henüz manuel değerlendirme yapılmadı.</div>
            @else
                <div class="mt-5 grid gap-3 sm:grid-cols-2">
                    @foreach ($model['manual']['criteria'] as $criterion)
                        <div class="rounded-xl border border-[var(--line)] p-4">
                            <div class="flex items-center justify-between gap-3"><span class="text-xs font-bold text-[var(--muted)]">{{ $criterion['label'] }}</span><strong class="text-sm">{{ number_format($criterion['average'], 1, ',', '.') }}/10</strong></div>
                            <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-[var(--surface-subtle)]"><div class="h-full rounded-full bg-[var(--forest)]" style="width: {{ $criterion['average'] * 10 }}%"></div></div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="data-panel rounded-2xl border border-[var(--line)] bg-white p-5 sm:p-6" aria-labelledby="stages-title">
            <p class="eyebrow">Teknik profil</p>
            <h2 id="stages-title" class="mt-1 text-xl font-black tracking-[-0.035em]">Aşama güvenilirliği</h2>
            <div class="mt-5 divide-y divide-[var(--line)]">
                @forelse ($model['technical']['stages'] as $stage)
                    <div class="py-4 first:pt-0 last:pb-0">
                        <div class="flex items-center justify-between gap-4"><strong class="text-xs">{{ $stageLabels[$stage['stage']] ?? str($stage['stage'])->title() }}</strong><span class="text-[10px] font-bold text-[var(--muted)]">{{ $stage['passed'] }}/{{ $stage['total'] }} · %{{ number_format($stage['pass_rate'], 1, ',', '.') }}</span></div>
                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-[var(--surface-subtle)]"><div class="h-full rounded-full {{ $stage['failed'] > 0 ? 'bg-[var(--amber)]' : 'bg-[var(--success)]' }}" style="width: {{ $stage['pass_rate'] }}%"></div></div>
                    </div>
                @empty
                    <p class="text-sm text-[var(--muted)]">Teknik aşama kaydı yok.</p>
                @endforelse
            </div>
        </section>
    </div>

    <section class="data-panel mt-8 overflow-hidden rounded-2xl border border-[var(--line)] bg-white" aria-labelledby="history-title">
        <div class="border-b border-[var(--line)] px-5 py-5 sm:px-6"><p class="eyebrow">Geçmiş performans</p><h2 id="history-title" class="mt-1 text-xl font-black tracking-[-0.035em]">Benchmark koşuları</h2></div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[860px] border-collapse text-left">
                <thead><tr class="border-b border-[var(--line)] bg-[var(--surface-subtle)]"><th class="table-heading px-6 py-3">Benchmark</th><th class="table-heading px-4 py-3 text-center">Durum</th><th class="table-heading px-4 py-3 text-center">Teknik</th><th class="table-heading px-4 py-3 text-center">Manuel</th><th class="table-heading px-4 py-3 text-right">Süre</th><th class="table-heading px-6 py-3"></th></tr></thead>
                <tbody>
                    @foreach ($model['runs'] as $history)
                        <tr class="border-b border-[var(--line)] last:border-b-0">
                            <th class="px-6 py-4"><a class="text-xs font-black hover:text-[var(--forest)]" href="{{ route('runs.show', $history['benchmark_run']) }}">{{ $history['benchmark_run']->name }}</a><span class="mt-1 block text-[10px] font-normal text-[var(--muted)]">{{ $history['benchmark_run']->started_at?->locale('tr')->translatedFormat('j M Y · H:i') ?? 'Tarih yok' }}</span></th>
                            <td class="px-4 py-4 text-center"><span class="status-badge {{ $history['status'] === 'pass' ? 'status-pass' : 'status-fail' }}">{{ $history['status'] === 'pass' ? 'Başarılı' : 'Sorunlu' }}</span></td>
                            <td class="px-4 py-4 text-center text-xs font-black">{{ $history['technical_score'] === null ? '—' : '%'.number_format($history['technical_score'], 1, ',', '.') }}</td>
                            <td class="px-4 py-4 text-center text-xs font-black">{{ $history['manual_score'] === null ? '—' : $history['manual_score'].'/10' }}</td>
                            <td class="px-4 py-4 text-right font-mono text-[11px] font-bold">{{ $formatDuration($history['duration_seconds']) }}</td>
                            <td class="px-6 py-4 text-right">
                                @if ($history['preview_available'])
                                    <a class="text-xs font-black text-[var(--forest)]" href="{{ route('runs.models.preview', [$history['benchmark_run'], $history['model_run'], 'review' => 1]) }}" target="_blank" rel="noopener">{{ $history['manual_score'] === null ? 'Puanla' : 'İncele' }} ↗</a>
                                @else
                                    <a class="text-xs font-black text-[var(--muted)]" href="{{ route('runs.show', $history['benchmark_run']) }}">Rapor →</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <div class="mt-8 grid gap-6 xl:grid-cols-[0.65fr_1.35fr]">
        <section class="data-panel rounded-2xl border border-[var(--line)] bg-white p-5 sm:p-6">
            <p class="eyebrow">Sorun dağılımı</p><h2 class="mt-1 text-lg font-black">Manuel önem seviyeleri</h2>
            <dl class="mt-4 divide-y divide-[var(--line)]">@foreach ($model['manual']['severity'] as $severity => $count)<div class="flex items-center justify-between py-3 text-xs"><dt class="font-bold text-[var(--muted)]">{{ $severityLabels[$severity] }}</dt><dd class="font-black">{{ $count }}</dd></div>@endforeach</dl>
        </section>
        <section class="data-panel rounded-2xl border border-[var(--line)] bg-white p-5 sm:p-6">
            <p class="eyebrow">İnceleme notları</p><h2 class="mt-1 text-lg font-black">Değerlendirici gözlemleri</h2>
            <div class="mt-4 space-y-3">
                @forelse ($model['manual']['notes'] as $note)
                    <article class="rounded-xl border border-[var(--line)] bg-[var(--surface-subtle)] p-4"><p class="text-sm leading-6 text-[var(--ink)]">{{ $note['text'] }}</p><div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-[10px] text-[var(--muted)]"><a class="font-bold hover:text-[var(--forest)]" href="{{ route('runs.show', $note['benchmark_run']) }}">{{ $note['benchmark_name'] }}</a><span>{{ $severityLabels[$note['severity']] }} · {{ $note['reviewed_at']?->locale('tr')->translatedFormat('j M Y') }}</span></div></article>
                @empty
                    <p class="rounded-xl border border-dashed border-[var(--line-strong)] p-5 text-sm text-[var(--muted)]">Kaydedilmiş değerlendirme notu yok.</p>
                @endforelse
            </div>
        </section>
    </div>
</div>
@endsection
