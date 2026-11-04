{{-- Breadcrumb, run identity, overall status, message and the Stop form. Uses the formatters defined by show.blade.php. --}}
@php
    $runSettings = is_array($workflowRun->settings) ? $workflowRun->settings : [];
    $parallelModels = is_int($runSettings['maxParallelModels'] ?? null) ? $runSettings['maxParallelModels'] : null;
    $concurrentCalls = is_int($runSettings['maxConcurrentCalls'] ?? null) ? $runSettings['maxConcurrentCalls'] : null;
    $runMessage = is_string($snapshot['message'] ?? null) ? $snapshot['message'] : '';
@endphp

<nav class="mb-6 flex items-center gap-2 text-xs font-semibold text-[var(--muted)]" aria-label="Sayfa yolu">
    <a class="hover:text-[var(--forest)]" href="{{ route('workflows.index') }}">İş Akışları</a>
    <span aria-hidden="true">/</span>
    <span class="min-w-0 truncate text-[var(--ink)]" aria-current="page">{{ $workflowRun->title }}</span>
</nav>

<header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
    <div class="min-w-0">
        <p class="eyebrow">İş akışı çalıştırması</p>
        <h1 class="mt-2 break-words text-3xl font-black tracking-[-0.05em] sm:text-[38px]">{{ $workflowRun->title }}</h1>
        <p class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs font-semibold text-[var(--muted)]">
            <span>Şablon <strong class="text-[var(--ink)]">{{ $templateName }}</strong> · Sürüm {{ $workflowRun->template_version }}</span>
            <span aria-hidden="true">·</span>
            <span class="break-all font-mono text-[11px]">{{ $workflowRun->slug }}</span>
            @if ($runStartedText !== null)
                <span aria-hidden="true">·</span>
                <span>Başlangıç {{ $runStartedText }}</span>
            @endif
            <span @class(['hidden' => $runFinishedText === null]) aria-hidden="true" data-workflow-finished>·</span>
            <span @class(['hidden' => $runFinishedText === null]) data-workflow-finished>Bitiş <span data-workflow-finished-time>{{ $runFinishedText }}</span></span>
        </p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a class="rounded-xl border border-[var(--line)] bg-[var(--surface)] px-4 py-2.5 text-xs font-extrabold text-[var(--forest)]" href="{{ route('workflows.index') }}">Tüm iş akışları</a>
        @if (is_array($template))
            <a class="rounded-xl border border-[var(--line)] bg-[var(--surface)] px-4 py-2.5 text-xs font-extrabold text-[var(--forest)]" href="{{ route('workflows.create', $workflowRun->template_id) }}">Yeniden çalıştır</a>
        @endif
    </div>
</header>

@if (session('status'))
    <div class="mt-6 rounded-xl border border-[var(--success-line)] bg-[var(--success-soft)] px-4 py-3 text-sm font-semibold text-[var(--success)]" role="status">{{ session('status') }}</div>
@endif

@if ($errors->any())
    <div class="mt-6 rounded-xl border border-[var(--red-line)] bg-[var(--red-soft)] px-4 py-3 text-sm text-[var(--red)]" role="alert">{{ $errors->first() }}</div>
@endif

<section class="runner-card mt-7 rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-5 sm:p-6" aria-labelledby="workflow-status-title">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="eyebrow">Çalıştırma durumu</p>
            <h2 id="workflow-status-title" class="mt-1 text-xl font-black tracking-[-0.03em]">Genel durum</h2>
            <p @class(['mt-2 max-w-3xl break-words text-xs leading-5 text-[var(--muted)]', 'hidden' => $runMessage === '']) data-workflow-message>{{ $runMessage }}</p>
        </div>
        <span class="status-badge status-{{ $statusTone($runStatus) }}" role="status" data-workflow-status><span class="status-dot"></span><span data-workflow-status-label>{{ $statusLabel($runStatus) }}</span></span>
    </div>
    <div class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs font-semibold text-[var(--muted)]">
        <span>{{ count($runModels) }} model</span>
        @if ($parallelModels !== null)
            <span aria-hidden="true">·</span>
            <span>Aynı anda {{ $parallelModels }} model</span>
        @endif
        @if ($concurrentCalls !== null)
            <span aria-hidden="true">·</span>
            <span>En fazla {{ $concurrentCalls }} eş zamanlı çağrı</span>
        @endif
        @if ($runActive)
            {{-- workflows.js hides the hint as soon as polling stops. --}}
            <span aria-hidden="true" data-workflow-live-hint>·</span>
            <span data-workflow-live-hint>Sayfa 2 sn arayla güncellenir</span>
        @endif
    </div>
    @if ($runActive)
        <form class="mt-5" method="POST" action="{{ route('workflows.runs.stop', $workflowRun) }}" data-workflow-stop data-confirm="Bu iş akışını durdurmak istiyor musunuz? Tamamlanan adımlar korunur.">
            @csrf
            <button class="rounded-lg border border-[var(--red-line)] bg-[var(--red-soft)] px-3.5 py-2.5 text-xs font-extrabold text-[var(--red)] transition hover:border-[var(--red)] disabled:cursor-not-allowed disabled:opacity-50" type="submit" @disabled($runStatus === 'stopping')>{{ $runStatus === 'stopping' ? 'Durduruluyor…' : 'İş akışını durdur' }}</button>
        </form>
    @endif
</section>
