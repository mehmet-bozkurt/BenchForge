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
                <p class="max-w-lg text-xs leading-5 text-[var(--muted)] sm:text-right">
                    Legacy sonuçlar üç temel aşamayı; V2 sonuçlar export, rota ve runtime kontrollerini de kapsar.
                </p>
            </div>

            <section class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3" aria-label="Benchmark suite'leri">
                @foreach ($runs as $index => $run)
                    @php
                        $passRate = $run['counts']['total'] > 0
                            ? (int) round(($run['counts']['pass'] / $run['counts']['total']) * 100)
                            : 0;
                        $hasFailure = $run['counts']['fail'] > 0;
                        $isIncomplete = $run['schema'] === 'incomplete';
                    @endphp
                    <article class="run-card group relative flex min-h-72 flex-col rounded-2xl border border-[var(--line)] bg-white p-5">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex flex-wrap gap-2">
                                <span class="schema-badge {{ in_array($run['schema'], ['legacy', 'incomplete'], true) ? 'schema-badge-legacy' : 'schema-badge-extended' }}">
                                    {{ $schemaLabel($run['schema']) }} · {{ count($run['stageNames']) }} aşama
                                </span>
                                <span class="status-badge {{ $isIncomplete ? 'status-unknown' : ($hasFailure ? 'status-fail' : 'status-pass') }}">
                                    <span class="status-dot"></span>
                                    {{ $isIncomplete ? 'Sonuç özeti yok' : ($hasFailure ? $run['counts']['fail'].' sorunlu' : 'Teknik olarak temiz') }}
                                </span>
                            </div>
                            <span class="font-mono text-[10px] font-bold tracking-[0.12em] text-[var(--line-strong)]">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                        </div>

                        <div class="mt-6">
                            <p class="text-[11px] font-bold tracking-[0.08em] text-[var(--muted)] uppercase">{{ $run['date']->locale('tr')->translatedFormat('j M Y') }}</p>
                            <h3 class="mt-2 text-xl font-black tracking-[-0.035em] text-[var(--ink)]">
                                <a class="stretched-link" href="{{ route('runs.show', $run['slug']) }}">{{ $run['name'] }}</a>
                            </h3>
                            <p class="mt-2 truncate font-mono text-[11px] text-[var(--muted)]">{{ $run['slug'] }}</p>
                        </div>

                        <div class="mt-6 grid grid-cols-2 gap-4 border-y border-[var(--line)] py-4">
                            <div>
                                <span class="card-stat-label">Model koşusu</span>
                                <strong class="mt-1 block text-lg font-black">{{ count($run['models']) }}</strong>
                            </div>
                            <div>
                                <span class="card-stat-label">Toplam süre</span>
                                <strong class="mt-1 block text-lg font-black">{{ $formatDuration($run['totalDurationSeconds']) }}</strong>
                            </div>
                        </div>

                        <div class="mt-4 grid grid-cols-3 gap-2 rounded-xl bg-[var(--surface-subtle)] p-3" aria-label="Disk kullanımı">
                            <div>
                                <span class="block text-[8px] font-extrabold tracking-[0.06em] text-[var(--muted)] uppercase">Toplam</span>
                                <strong class="mt-1 block text-[11px]">{{ $formatFileSize($run['storage']['totalBytes']) }}</strong>
                            </div>
                            <div>
                                <span class="block text-[8px] font-extrabold tracking-[0.06em] text-[var(--muted)] uppercase">Çalışma</span>
                                <strong class="mt-1 block text-[11px]">{{ $formatFileSize($run['storage']['workspaceBytes']) }}</strong>
                            </div>
                            <div>
                                <span class="block text-[8px] font-extrabold tracking-[0.06em] text-[var(--muted)] uppercase">Sonuç</span>
                                <strong class="mt-1 block text-[11px]">{{ $formatFileSize($run['storage']['resultBytes']) }}</strong>
                            </div>
                        </div>

                        <div class="mt-4">
                            <div class="mb-2 flex items-center justify-between text-[11px]">
                                <span class="font-semibold text-[var(--muted)]">Başarı oranı</span>
                                <span class="font-extrabold text-[var(--ink)]">%{{ $passRate }}</span>
                            </div>
                            <div class="h-1.5 overflow-hidden rounded-full bg-[var(--surface-subtle)]" role="progressbar" aria-valuenow="{{ $passRate }}" aria-valuemin="0" aria-valuemax="100">
                                <div class="h-full rounded-full bg-[var(--success)]" style="width: {{ $passRate }}%"></div>
                            </div>
                        </div>

                        <div class="mt-auto flex items-end justify-between gap-3 pt-5">
                            <div class="flex -space-x-1">
                                @foreach ($run['stageNames'] as $stage)
                                    <span class="stage-abbreviation" title="{{ $stageLabels[$stage] ?? $stage }}">{{ mb_substr($stageLabels[$stage] ?? $stage, 0, 2) }}</span>
                                @endforeach
                            </div>
                            <span class="text-xs font-extrabold text-[var(--forest)]">Raporu aç →</span>
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
