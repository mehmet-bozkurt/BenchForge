@extends('layouts.app')

@section('content')
@php
    $stageLabels = [
        'opencode' => 'Üretim',
        'lint' => 'Lint',
        'build' => 'Build',
        'export' => 'Export',
        'routes' => 'Rotalar',
        'runtime' => 'Runtime',
    ];
    $statusLabels = [
        'pass' => 'Başarılı',
        'fail' => 'Sorunlu',
        'skip' => 'Atlandı',
        'unknown' => 'Veri yok',
    ];
    $schemaLabel = static fn (string $schema, bool $detailed = false): string => match ($schema) {
        'legacy' => 'Legacy',
        'incomplete' => 'Tamamlanmamış',
        default => $detailed ? 'V2 · Genişletilmiş' : 'V2',
    };
    $formatDuration = static function (int $seconds): string {
        if ($seconds < 60) {
            return $seconds.' sn';
        }

        $minutes = intdiv($seconds, 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes < 60) {
            return $remainingSeconds > 0 ? $minutes.' dk '.$remainingSeconds.' sn' : $minutes.' dk';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return $remainingMinutes > 0 ? $hours.' sa '.$remainingMinutes.' dk' : $hours.' sa';
    };
    $formatFileSize = static fn (?int $bytes): string => $bytes === null
        ? 'Hesaplanmadı'
        : \Illuminate\Support\Number::fileSize($bytes, 2);
@endphp

    <div class="content-frame">
        @if (session('status'))
            <div class="mb-5 rounded-xl border border-[#b9ead8] bg-[var(--success-soft)] px-4 py-3 text-sm font-semibold text-[var(--success)]" role="status">
                {{ session('status') }}
            </div>
        @endif

        <header class="mb-8 flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="eyebrow">Yerel benchmark merkezi</p>
                <h1 class="mt-2 text-3xl font-black tracking-[-0.05em] text-[var(--ink)] sm:text-[38px]">Genel Bakış</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-[var(--muted)]">
                    Model koşularını, doğrulama kapsamını ve teknik sonuçları tek görünümde karşılaştırın.
                </p>
            </div>
            <div class="sync-pill">
                <span class="h-2 w-2 rounded-full bg-[var(--success)] shadow-[0_0_0_4px_var(--success-soft)]" aria-hidden="true"></span>
                Dosya sistemiyle senkron
            </div>
        </header>

        @if ($latestRun === null)
            <section class="rounded-3xl border border-dashed border-[var(--line-strong)] bg-white px-6 py-20 text-center">
                <span class="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-[var(--mint-soft)] font-black text-[var(--forest)]">BF</span>
                <h2 class="mt-5 text-xl font-black text-[var(--ink)]">Henüz benchmark bulunamadı</h2>
                <p class="mx-auto mt-2 max-w-lg text-sm leading-6 text-[var(--muted)]">
                    Yapılandırılan runs klasöründe okunabilir bir sonuç özeti oluştuğunda burada görünecek.
                </p>
            </section>
        @else
            @php
                $latestHasFailure = $latestRun['counts']['fail'] > 0;
                $latestIncomplete = $latestRun['schema'] === 'incomplete';
            @endphp

            <section class="overview-hero relative overflow-hidden rounded-[28px] px-6 py-7 text-white sm:px-8 sm:py-8 lg:px-10" aria-labelledby="latest-run-title">
                <div class="pointer-events-none absolute -top-28 -right-24 h-72 w-72 rounded-full border-[52px] border-[color:rgba(103,232,249,0.07)]" aria-hidden="true"></div>
                <div class="relative grid gap-8 lg:grid-cols-[minmax(0,1.35fr)_minmax(320px,0.65fr)] lg:items-end">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-[10px] font-extrabold tracking-[0.14em] text-[#67e8f9] uppercase">Son benchmark</span>
                            <span class="schema-badge {{ in_array($latestRun['schema'], ['legacy', 'incomplete'], true) ? 'schema-badge-legacy' : 'schema-badge-extended' }}">
                                {{ $schemaLabel($latestRun['schema'], true) }} · {{ count($latestRun['stageNames']) }} aşama
                            </span>
                            <span class="status-badge {{ $latestIncomplete ? 'status-unknown' : ($latestHasFailure ? 'status-fail' : 'status-pass') }}">
                                <span class="status-dot"></span>
                                {{ $latestIncomplete ? 'Sonuç özeti yok' : ($latestHasFailure ? 'İnceleme gerekiyor' : 'Teknik olarak temiz') }}
                            </span>
                        </div>

                        <h2 id="latest-run-title" class="mt-5 max-w-3xl text-3xl font-black tracking-[-0.05em] sm:text-4xl lg:text-[46px] lg:leading-[1.05]">
                            {{ $latestRun['name'] }}
                        </h2>
                        <p class="mt-3 font-mono text-xs text-[#8f9ab6]">
                            {{ $latestRun['slug'] }} · {{ $latestRun['date']->locale('tr')->translatedFormat('j M Y · H:i') }}
                        </p>

                        <div class="mt-7 flex flex-wrap gap-2" aria-label="Son koşunun aşama özeti">
                            @foreach ($latestRun['stageNames'] as $stage)
                                @php
                                    $stageStatuses = collect($latestRun['models'])->pluck('stages.'.$stage.'.status');
                                    $knownStageStatuses = $stageStatuses->filter(fn (mixed $status): bool => is_string($status));
                                    $aggregateStatus = $knownStageStatuses->contains('fail')
                                        ? 'fail'
                                        : ($knownStageStatuses->isNotEmpty() && $knownStageStatuses->every(fn (string $status): bool => $status === 'pass') ? 'pass' : 'skip');
                                @endphp
                                <span class="stage-chip">
                                    <span @class([
                                        'h-1.5 w-1.5 rounded-full',
                                        'bg-[#34d399]' => $aggregateStatus === 'pass',
                                        'bg-[#fb7185]' => $aggregateStatus === 'fail',
                                        'bg-[#94a3b8]' => $aggregateStatus === 'skip',
                                    ])></span>
                                    {{ $stageLabels[$stage] ?? $stage }}
                                </span>
                            @endforeach
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-px overflow-hidden rounded-2xl border border-white/10 bg-white/10">
                        <div class="bg-[#101426]/80 p-4 backdrop-blur-sm">
                            <span class="hero-metric-label">Model</span>
                            <strong class="mt-2 block text-2xl font-black">{{ count($latestRun['models']) }}</strong>
                        </div>
                        <div class="bg-[#101426]/80 p-4 backdrop-blur-sm">
                            <span class="hero-metric-label">Başarılı</span>
                            <strong class="mt-2 block text-2xl font-black text-[#6ee7b7]">{{ $latestRun['counts']['pass'] }}</strong>
                        </div>
                        <div class="bg-[#101426]/80 p-4 backdrop-blur-sm">
                            <span class="hero-metric-label">Sorunlu</span>
                            <strong class="mt-2 block text-2xl font-black text-[#fda4af]">{{ $latestRun['counts']['fail'] }}</strong>
                        </div>
                        <div class="hero-strip col-span-3 flex items-center justify-between px-4 py-3 text-xs font-extrabold text-[var(--forest-deep)]">
                            {{ $formatDuration($latestRun['totalDurationSeconds']) }} toplam çalışma süresi
                            <span aria-hidden="true">↗</span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-5" aria-label="Genel metrikler">
                <article class="metric-card metric-neutral">
                    <p class="metric-label">Benchmark suite</p>
                    <p class="metric-value">{{ $metrics['runs'] }}</p>
                    <p class="metric-detail">Arşivdeki okunabilir toplam koşu</p>
                </article>
                <article class="metric-card metric-neutral">
                    <p class="metric-label">Model koşusu</p>
                    <p class="metric-value">{{ $metrics['modelRuns'] }}</p>
                    <p class="metric-detail">Tüm suite’lerdeki toplam teslim</p>
                </article>
                <article class="metric-card metric-positive">
                    <p class="metric-label">Kayıtlı PASS</p>
                    <p class="metric-value">{{ $metrics['passedModels'] }}</p>
                    <p class="metric-detail">Çalıştırılan kapsamda %{{ $metrics['passRate'] }}</p>
                </article>
                <article class="metric-card {{ $metrics['failedModels'] > 0 ? 'metric-danger' : 'metric-positive' }}">
                    <p class="metric-label">Sorunlu</p>
                    <p class="metric-value">{{ $metrics['failedModels'] }}</p>
                    <p class="metric-detail">En az bir doğrulama aşaması başarısız</p>
                </article>
                <article class="metric-card metric-neutral">
                    <p class="metric-label">Disk kullanımı</p>
                    <p class="metric-value text-[24px]">{{ $formatFileSize($metrics['storageBytes']) }}</p>
                    <p class="metric-detail">Tüm geçmiş koşu dosyaları</p>
                </article>
            </section>

            <div class="mt-10 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="eyebrow">Koşu geçmişi</p>
                    <h2 class="mt-1 text-2xl font-black tracking-[-0.04em] text-[var(--ink)]">Benchmark suite’leri</h2>
                </div>
                <p class="run-legend" aria-label="Aşama kısaltmaları">
                    @foreach ($stageLabels as $stage => $label)
                        <span><strong>{{ mb_substr($label, 0, 2) }}</strong> {{ $label }}</span>
                    @endforeach
                    <span class="run-legend-marks"><i class="stage-cell stage-cell-pass">✓</i> geçti <i class="stage-cell stage-cell-fail">✗</i> başarısız <i class="stage-cell stage-cell-skip">–</i> çalışmadı</span>
                </p>
            </div>

            <section class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3" aria-label="Benchmark suite'leri">
                @foreach ($runs as $index => $run)
                    @php
                        $total = $run['counts']['total'];
                        $passed = $run['counts']['pass'];
                        $isIncomplete = $run['schema'] === 'incomplete' || $run['stageNames'] === [];
                        $resultTone = match (true) {
                            $isIncomplete || $total === 0 => 'empty',
                            $passed === $total => 'all',
                            $passed === 0 => 'none',
                            default => 'some',
                        };
                        $visibleModels = array_slice($run['models'], 0, 6);
                        $hiddenModelCount = count($run['models']) - count($visibleModels);
                        $cost = $run['costUsd'];
                        $costLabel = match (true) {
                            $cost === null => null,
                            $cost <= 0.0 => '$0',
                            $cost < 0.01 => '<$0,01',
                            default => '$'.number_format($cost, 2, ',', '.'),
                        };
                    @endphp
                    <article class="run-card group relative flex flex-col rounded-2xl border border-[var(--line)] bg-white p-5">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="condition-pill condition-{{ $run['environmentProfile'] }}">
                                {{ \App\Models\BenchmarkRun::ENVIRONMENT_PROFILES[$run['environmentProfile']] }} · {{ $run['date']->locale('tr')->translatedFormat('j M Y') }}
                            </span>
                            <span class="result-pill result-{{ $resultTone }}">
                                {{ $resultTone === 'empty' ? 'Sonuç özeti yok' : $passed.' / '.$total.' geçti' }}
                            </span>
                        </div>

                        <h3 class="mt-4 text-xl font-black tracking-[-0.035em] text-[var(--ink)]">
                            <a class="stretched-link" href="{{ route('runs.show', $run['slug']) }}">{{ $run['name'] }}</a>
                        </h3>
                        <p class="run-task">{{ $run['taskSummary'] ?? 'Görev metni bulunamadı.' }}</p>
                        @if ($run['routes'] !== [])
                            <p class="mt-1 truncate font-mono text-[10.5px] text-[var(--muted)]">Rotalar: {{ implode(', ', $run['routes']) }}</p>
                        @endif

                        @if ($isIncomplete)
                            <p class="run-empty">Bu koşunun sonuç özeti yok; model klasörleri bulundu ama aşama sonuçları kaydedilmemiş.</p>
                        @else
                            <div class="run-matrix" style="--stage-count: {{ count($run['stageNames']) }}" role="table" aria-label="{{ $run['name'] }}: model ve aşama sonuçları">
                                <div class="run-matrix-row" role="row">
                                    <span role="columnheader"><span class="sr-only">Model</span></span>
                                    @foreach ($run['stageNames'] as $stage)
                                        <span class="run-matrix-head" role="columnheader" title="{{ $stageLabels[$stage] ?? $stage }}">{{ mb_substr($stageLabels[$stage] ?? $stage, 0, 2) }}</span>
                                    @endforeach
                                </div>
                                @foreach ($visibleModels as $model)
                                    <div class="run-matrix-row" role="row">
                                        <span class="run-matrix-model" role="rowheader" title="{{ $model['model'] }}">{{ $model['displayName'] }}</span>
                                        @foreach ($run['stageNames'] as $stage)
                                            @php($status = $model['stages'][$stage]['status'] ?? 'unknown')
                                            <span class="stage-cell stage-cell-{{ $status }}" role="cell" title="{{ ($stageLabels[$stage] ?? $stage).': '.($statusLabels[$status] ?? 'Veri yok') }}">{{ match ($status) { 'pass' => '✓', 'fail' => '✗', 'skip' => '–', default => '?' } }}</span>
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                            @if ($hiddenModelCount > 0)
                                <p class="mt-2 text-[11px] font-semibold text-[var(--muted)]">+{{ $hiddenModelCount }} model daha · raporda</p>
                            @endif
                        @endif

                        <div class="run-footer">
                            <span>
                                {{ $formatDuration($run['totalDurationSeconds']) }}
                                @if ($costLabel !== null)
                                    · {{ $costLabel }}
                                @endif
                                · {{ $formatFileSize($run['storage']['totalBytes']) }}
                            </span>
                            <span class="font-extrabold text-[var(--forest)]">Raporu aç →</span>
                        </div>
                    </article>
                @endforeach
            </section>

            <section class="data-panel mt-10 overflow-hidden rounded-2xl border border-[var(--line)] bg-white">
                <div class="flex flex-col gap-4 border-b border-[var(--line)] px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                    <div>
                        <p class="eyebrow">Aşama matrisi</p>
                        <h2 class="mt-1 text-lg font-black tracking-[-0.025em] text-[var(--ink)]">Son koşunun teknik görünümü</h2>
                    </div>
                    <p class="text-[10px] font-semibold text-[var(--muted)]">{{ $latestRun['name'] }}</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[760px] border-collapse text-left">
                        <caption class="sr-only">Son benchmark koşusundaki modellerin test aşaması sonuçları</caption>
                        <thead>
                            <tr class="border-b border-[var(--line)] bg-[var(--surface-subtle)]">
                                <th class="table-heading sticky left-0 z-10 min-w-48 bg-[var(--surface-subtle)] px-6 py-3" scope="col">Model</th>
                                @foreach ($latestRun['stageNames'] as $stage)
                                    <th class="table-heading px-3 py-3 text-center" scope="col">{{ $stageLabels[$stage] ?? $stage }}</th>
                                @endforeach
                                <th class="table-heading px-6 py-3 text-right" scope="col">Süre</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($latestRun['models'] as $model)
                                <tr class="border-b border-[var(--line)] last:border-b-0 hover:bg-[var(--surface-subtle)]">
                                    <th class="sticky left-0 z-10 bg-white px-6 py-4" scope="row">
                                        <span class="block text-xs font-extrabold text-[var(--ink)]">{{ $model['displayName'] }}</span>
                                        <span class="mt-1 block text-[10px] font-medium text-[var(--muted)]">{{ $statusLabels[$model['overallStatus']] }}</span>
                                    </th>
                                    @foreach ($latestRun['stageNames'] as $stage)
                                        @php($status = $model['stages'][$stage]['status'] ?? 'unknown')
                                        <td class="px-3 py-4 text-center">
                                            <span class="stage-mark stage-mark-{{ $status }}" title="{{ $statusLabels[$status] ?? 'Veri yok' }}">
                                                {{ match ($status) { 'pass' => '✓', 'fail' => '×', 'skip' => '—', default => '?' } }}
                                            </span>
                                        </td>
                                    @endforeach
                                    <td class="px-6 py-4 text-right font-mono text-[11px] font-bold whitespace-nowrap text-[var(--ink)]">{{ $formatDuration($model['durationSeconds']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <footer class="mt-8 flex flex-col gap-2 border-t border-[var(--line)] pt-5 text-[10px] leading-5 text-[var(--muted)] sm:flex-row sm:items-start sm:justify-between">
                <p class="max-w-2xl">“Kayıtlı PASS”, yalnızca ilgili suite’in çalıştırdığı teknik kontrolleri ifade eder. Legacy koşular runtime güvenilirliğiyle doğrudan karşılaştırılmamalıdır.</p>
                <p class="shrink-0 font-mono">Kaynak: runs/*/_summary.tsv</p>
            </footer>
        @endif
    </div>
@endsection
