@extends('layouts.app')

@section('content')
@php
    $statusLabels = [
        'starting' => 'Başlatılıyor',
        'running' => 'Çalışıyor',
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
@endphp

<div class="content-frame">
    <header class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="eyebrow">Çok adımlı model akışları</p>
            <h1 class="mt-2 text-3xl font-black tracking-[-0.05em] sm:text-[38px]">İş Akışları</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-[var(--muted)]">Bir şablon seçin, girdileri doldurun ve 1–{{ \App\Services\ModelSelection::WORKFLOW_MODEL_LIMIT }} modelle yan yana çalıştırın. Her model iş akışının tüm adımlarını kendi başına yürütür; ilerlemeyi canlı izleyebilir, üretilen demoları galeride açabilirsiniz.</p>
        </div>
    </header>

    @if (session('status'))
        <div class="mt-6 rounded-xl border border-[var(--success-line)] bg-[var(--success-soft)] px-4 py-3 text-sm font-semibold text-[var(--success)]" role="status">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="mt-6 rounded-xl border border-[var(--red-line)] bg-[var(--red-soft)] px-4 py-3 text-sm text-[var(--red)]" role="alert">{{ $errors->first() }}</div>
    @endif

    @if ($activeRun)
        <section class="mt-7 flex flex-col gap-3 rounded-2xl border border-[var(--indigo-line)] bg-[var(--indigo-soft)] p-5 sm:flex-row sm:items-center sm:justify-between" aria-label="Etkin iş akışı">
            <div>
                <p class="text-[10px] font-black tracking-[0.1em] text-[var(--forest)] uppercase">Şu anda çalışıyor</p>
                <h2 class="mt-1 text-base font-black">{{ $activeRun->title }}</h2>
                <p class="mt-1 text-xs leading-5 text-[var(--muted)]">Aynı anda tek iş akışı çalışır. Adımları, canlı günlüğü ve demoları ayrıntı ekranında izleyebilirsiniz.</p>
            </div>
            <a class="inline-flex shrink-0 rounded-lg bg-[var(--forest)] px-4 py-2.5 text-xs font-extrabold text-white" href="{{ route('workflows.runs.show', $activeRun) }}">Canlı durumu aç →</a>
        </section>
    @endif

    <section class="mt-7" aria-labelledby="workflow-templates-title">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="eyebrow">Yerleşik ve kayıtlı</p>
                <h2 id="workflow-templates-title" class="mt-1 text-xl font-black tracking-[-0.03em]">Şablonlar</h2>
            </div>
            <span class="sync-pill">{{ count($templates) }} şablon</span>
        </div>

        @if ($templates === [])
            <div class="rounded-2xl border border-dashed border-[var(--line-strong)] bg-[var(--surface)] px-6 py-14 text-center">
                <h3 class="text-lg font-black">Henüz iş akışı şablonu yok</h3>
                <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-[var(--muted)]">Yerleşik şablonlar <code class="font-mono text-xs">engine/workflow-templates</code>, kayıtlı şablonlar veri kökündeki <code class="font-mono text-xs">workflows</code> klasöründe aranır.</p>
            </div>
        @else
            <div class="grid gap-4 xl:grid-cols-2">
                @foreach ($templates as $workflowTemplate)
                    <article class="runner-card flex min-w-0 flex-col rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-5 sm:p-6">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-[9px] font-black tracking-[0.1em] text-[var(--forest)] uppercase">{{ $workflowTemplate['source'] === 'builtin' ? 'Yerleşik' : 'Kullanıcı' }} · Sürüm {{ $workflowTemplate['version'] }}</p>
                                <h3 class="mt-2 break-words text-lg font-black tracking-[-0.03em]">{{ $workflowTemplate['name'] }}</h3>
                                <p class="mt-1 break-all font-mono text-[10px] text-[var(--muted)]">{{ $workflowTemplate['id'] }}</p>
                            </div>
                            <span @class([
                                'shrink-0 rounded-lg px-2.5 py-1 text-[10px] font-black',
                                'bg-[var(--violet-soft)] text-[var(--violet)]' => $workflowTemplate['source'] === 'builtin',
                                'bg-[var(--mint-soft)] text-[var(--forest)]' => $workflowTemplate['source'] !== 'builtin',
                            ])>{{ $workflowTemplate['source'] === 'builtin' ? 'Yerleşik' : 'Kullanıcı' }}</span>
                        </div>
                        <p class="mt-3 line-clamp-3 text-xs leading-5 text-[var(--muted)]">{{ $workflowTemplate['description'] ?? 'Bu şablon için açıklama yazılmamış.' }}</p>
                        <div class="mt-4 flex flex-wrap gap-2 text-[10px] font-semibold text-[var(--muted)]">
                            <span class="rounded-lg bg-[var(--surface-subtle)] px-2.5 py-1.5">Sürüm {{ $workflowTemplate['version'] }}</span>
                            <span class="rounded-lg bg-[var(--surface-subtle)] px-2.5 py-1.5">{{ $workflowTemplate['stepCount'] }} adım</span>
                        </div>
                        @if ($workflowTemplate['valid'])
                            <a class="mt-5 inline-flex w-fit rounded-lg border border-[var(--line)] px-4 py-2.5 text-xs font-extrabold text-[var(--forest)] transition hover:border-[var(--forest)]" href="{{ route('workflows.create', $workflowTemplate['id']) }}">Çalıştır →</a>
                        @else
                            <p class="mt-5 rounded-xl border border-[var(--red-line)] bg-[var(--red-soft)] px-3 py-2.5 text-[11px] leading-5 text-[var(--red)]" role="note">
                                <strong class="font-extrabold">Bu sürüm çalıştırılamaz.</strong>
                                <span class="block break-words">{{ $workflowTemplate['error'] ?? 'Şablon doğrulanamadı.' }}</span>
                            </p>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <section class="mt-10" aria-labelledby="workflow-runs-title">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="eyebrow">Kalıcı kayıtlar</p>
                <h2 id="workflow-runs-title" class="mt-1 text-xl font-black tracking-[-0.03em]">Son çalıştırmalar</h2>
            </div>
            <span class="sync-pill">{{ $runs->total() }} çalıştırma</span>
        </div>

        @if ($runs->isEmpty())
            <div class="rounded-2xl border border-dashed border-[var(--line-strong)] bg-[var(--surface)] px-6 py-14 text-center">
                <h3 class="text-lg font-black">Henüz çalıştırma yok</h3>
                <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-[var(--muted)]">Bir şablonu çalıştırdığınızda modellerin adımları, çıktıları ve demoları burada saklanır.</p>
            </div>
        @else
            <div class="overflow-hidden rounded-2xl border border-[var(--line)] bg-[var(--surface)]">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[760px] border-collapse text-left">
                        <caption class="sr-only">Son iş akışı çalıştırmaları</caption>
                        <thead>
                            <tr class="border-b border-[var(--line)] bg-[var(--surface-subtle)]">
                                <th class="table-heading px-6 py-3" scope="col">Çalıştırma</th>
                                <th class="table-heading px-3 py-3" scope="col">Durum</th>
                                <th class="table-heading px-3 py-3 text-right" scope="col">Model</th>
                                <th class="table-heading px-3 py-3" scope="col">Başlangıç</th>
                                <th class="table-heading px-3 py-3 text-right" scope="col">Süre</th>
                                <th class="table-heading px-6 py-3 text-right" scope="col"><span class="sr-only">Eylemler</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($runs as $workflowRun)
                                @php
                                    $isActiveRun = $workflowRun->isActive();
                                    $durationSeconds = $workflowRun->started_at !== null && $workflowRun->finished_at !== null
                                        ? max(0, (int) round($workflowRun->started_at->diffInSeconds($workflowRun->finished_at)))
                                        : null;
                                @endphp
                                <tr class="border-b border-[var(--line)] last:border-b-0 hover:bg-[var(--surface-subtle)]">
                                    <th class="px-6 py-4 align-top" scope="row">
                                        <span class="block text-xs font-extrabold break-words text-[var(--ink)]">{{ $workflowRun->title }}</span>
                                        <span class="mt-1 block font-mono text-[10px] font-medium break-all text-[var(--muted)]">{{ $workflowRun->slug }} · {{ $workflowRun->template_id }} v{{ $workflowRun->template_version }}</span>
                                        @if ($workflowRun->message && ! $isActiveRun)
                                            <span class="mt-1 block line-clamp-2 text-[10px] font-medium text-[var(--muted)]">{{ $workflowRun->message }}</span>
                                        @endif
                                    </th>
                                    <td class="px-3 py-4 align-top">
                                        <span class="status-badge status-{{ $statusTones[$workflowRun->status] ?? 'unknown' }}">
                                            <span class="status-dot"></span>
                                            {{ $statusLabels[$workflowRun->status] ?? 'Bilinmiyor' }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-4 text-right align-top font-mono text-[11px] font-bold text-[var(--ink)]">{{ $workflowRun->models_count }}</td>
                                    <td class="px-3 py-4 align-top text-[11px] whitespace-nowrap text-[var(--muted)]">{{ $workflowRun->started_at?->locale('tr')->translatedFormat('j M Y · H:i') ?? '—' }}</td>
                                    <td class="px-3 py-4 text-right align-top font-mono text-[11px] font-bold whitespace-nowrap text-[var(--ink)]">
                                        {{ $durationSeconds !== null ? $formatDuration($durationSeconds) : ($isActiveRun ? 'Sürüyor' : '—') }}
                                    </td>
                                    <td class="px-6 py-4 text-right align-top">
                                        <a class="text-xs font-extrabold whitespace-nowrap text-[var(--forest)] hover:underline" href="{{ route('workflows.runs.show', $workflowRun) }}">{{ $isActiveRun ? 'Canlı izle →' : 'Sonuçları aç →' }}</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="mt-6">{{ $runs->links() }}</div>
        @endif
    </section>
</div>
@endsection
