@extends('layouts.app')

@section('content')
@php
    $formatDuration = static function (int $seconds): string {
        if ($seconds < 60) return $seconds.' sn';
        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) return $minutes.' dk'.($seconds % 60 ? ' '.($seconds % 60).' sn' : '');
        return intdiv($minutes, 60).' sa'.($minutes % 60 ? ' '.($minutes % 60).' dk' : '');
    };
@endphp

<div class="content-frame">
    <header class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="eyebrow">Model performansı</p>
            <h1 class="mt-2 text-3xl font-black tracking-[-0.05em] text-[var(--ink)] sm:text-[38px]">Liderlik Tablosu</h1>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-[var(--muted)]">
                Aynı çalışma koşulundaki benchmark koşularının teknik güvenilirliğini, hızını ve manuel kalite değerlendirmelerini birlikte görün. Farklı koşullar tek tabloda karıştırılmaz.
            </p>
            <nav class="mt-4 inline-flex overflow-hidden rounded-lg border border-[var(--line)] bg-white text-[11px] font-extrabold" aria-label="Çalışma koşulu">
                @foreach ($environmentProfiles as $profile => $profileLabel)
                    <a
                        class="px-3 py-2 transition {{ $profile === $environmentProfile ? 'bg-[var(--forest)] text-white' : 'text-[var(--muted)] hover:text-[var(--forest)]' }} {{ $loop->first ? '' : 'border-l border-[var(--line)]' }}"
                        href="{{ route('models.index', ['condition' => $profile]) }}"
                        @if ($profile === $environmentProfile) aria-current="page" @endif
                    >{{ $profileLabel }}</a>
                @endforeach
            </nav>
        </div>
        <div class="sync-pill">
            <span class="h-2 w-2 rounded-full bg-[var(--forest)]" aria-hidden="true"></span>
            {{ $summary['models'] }} model izleniyor
        </div>
    </header>

    <section class="mt-8 grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Model özeti">
        <article class="metric-card metric-neutral">
            <p class="metric-label">Model</p>
            <p class="metric-value">{{ $summary['models'] }}</p>
            <p class="metric-detail">Benzersiz model profili</p>
        </article>
        <article class="metric-card metric-neutral">
            <p class="metric-label">Toplam koşu</p>
            <p class="metric-value">{{ $summary['runs'] }}</p>
            <p class="metric-detail">Geçmiş suite’lerdeki teslimler</p>
        </article>
        <article class="metric-card metric-positive">
            <p class="metric-label">Manuel inceleme</p>
            <p class="metric-value">{{ $summary['reviews'] }}</p>
            <p class="metric-detail">Kaydedilmiş kalite değerlendirmesi</p>
        </article>
        <article class="metric-card {{ $summary['reviewed_models'] === $summary['models'] && $summary['models'] > 0 ? 'metric-positive' : 'metric-warning' }}">
            <p class="metric-label">Puanlanan model</p>
            <p class="metric-value">{{ $summary['reviewed_models'] }}/{{ $summary['models'] }}</p>
            <p class="metric-detail">Liderlik için değerlendirme kapsamı</p>
        </article>
    </section>

    <section class="mt-8 overflow-hidden rounded-[26px] border border-[var(--line)] bg-white shadow-[0_20px_60px_rgb(26_32_65/7%)]" aria-labelledby="ranking-title">
        <div class="flex flex-col gap-4 border-b border-[var(--line)] px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-7">
            <div>
                <p class="eyebrow">Genel sıralama</p>
                <h2 id="ranking-title" class="mt-1 text-xl font-black tracking-[-0.035em]">Modellerin birleşik performansı</h2>
            </div>
            <div class="max-w-xl rounded-xl border border-[#d7ccff] bg-[var(--violet-soft)] px-4 py-3 text-[11px] leading-5 text-[var(--muted)]">
                <strong class="text-[var(--violet)]">Puan formülü:</strong> %60 teknik başarı + %40 manuel ilk teslim puanı. Teknik başarı, her koşunun kendi aşama oranının eşit ağırlıklı ortalamasıdır; model adımı başarısız olan koşu 0 sayılır. Manuel değerlendirmesi olmayan modeller sıralamaya alınmaz. Koşular farklı görev ve aşama setleri içerebildiği için bu tablo evrensel ve tam adil bir sıralama değildir.
            </div>
        </div>

        @if ($models->isEmpty())
            <div class="px-6 py-20 text-center">
                <h3 class="text-lg font-black">Bu koşulda henüz model sonucu yok</h3>
                <p class="mt-2 text-sm text-[var(--muted)]">{{ $environmentProfiles[$environmentProfile] }} ile ilk benchmark tamamlandığında model karneleri burada oluşacak. Önceki koşulların sonuçları üstteki seçiciden ayrı görülebilir.</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[980px] border-collapse text-left">
                    <caption class="sr-only">Modellerin genel liderlik sıralaması</caption>
                    <thead>
                        <tr class="border-b border-[var(--line)] bg-[var(--surface-subtle)]">
                            <th class="table-heading w-20 px-6 py-4 text-center" scope="col">Sıra</th>
                            <th class="table-heading px-4 py-4" scope="col">Model</th>
                            <th class="table-heading px-4 py-4 text-center" scope="col">Birleşik</th>
                            <th class="table-heading px-4 py-4" scope="col">Teknik güven</th>
                            <th class="table-heading px-4 py-4 text-center" scope="col">Manuel</th>
                            <th class="table-heading px-4 py-4 text-center" scope="col">Koşu</th>
                            <th class="table-heading px-4 py-4 text-right" scope="col">Ort. süre</th>
                            <th class="table-heading w-20 px-6 py-4" scope="col"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($models as $model)
                            <tr class="border-b border-[var(--line)] last:border-b-0 hover:bg-[var(--surface-subtle)]">
                                <td class="px-6 py-5 text-center">
                                    @if ($model['rank'])
                                        <span class="inline-grid h-9 w-9 place-items-center rounded-xl {{ $model['rank'] === 1 ? 'bg-[var(--forest-deep)] text-white' : 'bg-[var(--surface-subtle)] text-[var(--ink)]' }} text-xs font-black">#{{ $model['rank'] }}</span>
                                    @else
                                        <span class="text-xs font-bold text-[var(--muted)]">—</span>
                                    @endif
                                </td>
                                <th class="px-4 py-5" scope="row">
                                    <a class="text-sm font-black text-[var(--ink)] transition-colors hover:text-[var(--forest)]" href="{{ route('models.show', [$model['slug'], 'condition' => $environmentProfile]) }}">{{ $model['display_name'] }}</a>
                                    <code class="mt-1 block text-[10px] font-medium text-[var(--muted)]">{{ $model['provider_model'] }}</code>
                                    @if ($model['mixed_stage_sets'])
                                        <span class="mt-1.5 inline-flex rounded-full bg-[var(--amber-soft)] px-2 py-0.5 text-[9px] font-extrabold text-[var(--amber)]" title="Bu modelin koşuları farklı doğrulama aşamalarıyla ölçüldü; teknik oranlar birebir karşılaştırılabilir değildir.">Farklı aşama setleri</span>
                                    @endif
                                </th>
                                <td class="px-4 py-5 text-center">
                                    @if ($model['composite_score'] !== null)
                                        <strong class="text-lg font-black text-[var(--forest)]">{{ number_format($model['composite_score'], 1, ',', '.') }}</strong><span class="text-[10px] font-bold text-[var(--muted)]">/100</span>
                                    @else
                                        <span class="rounded-full bg-[var(--amber-soft)] px-2.5 py-1 text-[9px] font-extrabold tracking-[0.06em] text-[var(--amber)] uppercase">Değerlendirme bekliyor</span>
                                    @endif
                                </td>
                                <td class="px-4 py-5">
                                    <div class="flex items-center justify-between gap-3 text-[10px] font-bold">
                                        <span>{{ $model['measured_run_count'] }} koşu ortalaması</span>
                                        <span>%{{ number_format($model['technical']['score'], 1, ',', '.') }}</span>
                                    </div>
                                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-[var(--surface-subtle)]">
                                        <div class="h-full rounded-full bg-[var(--success)]" style="width: {{ $model['technical']['score'] }}%"></div>
                                    </div>
                                </td>
                                <td class="px-4 py-5 text-center">
                                    <strong class="block text-sm">{{ $model['manual']['average'] === null ? '—' : number_format($model['manual']['average'], 1, ',', '.').'/10' }}</strong>
                                    <span class="text-[10px] text-[var(--muted)]">{{ $model['manual']['count'] }}/{{ $model['run_count'] }} incelendi</span>
                                </td>
                                <td class="px-4 py-5 text-center text-sm font-black">{{ $model['run_count'] }}</td>
                                <td class="px-4 py-5 text-right font-mono text-[11px] font-bold whitespace-nowrap">{{ $formatDuration($model['average_duration_seconds']) }}</td>
                                <td class="px-6 py-5 text-right"><a class="text-xs font-black text-[var(--forest)]" href="{{ route('models.show', [$model['slug'], 'condition' => $environmentProfile]) }}" aria-label="{{ $model['display_name'] }} karnesini aç">Karne →</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <p class="mt-5 text-[10px] leading-5 text-[var(--muted)]">Teknik puan, kaydedilmiş tüm aşama sonuçlarının başarı oranıdır. Farklı kapsamdaki legacy ve genişletilmiş koşular ayrıntılı model karnesinde görünür.</p>
</div>
@endsection
