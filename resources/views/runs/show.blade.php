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
    $attemptStatusLabels = [
        'starting' => 'Başlatılıyor',
        'running' => 'Çalışıyor',
        'stopping' => 'Durduruluyor',
        'completed' => 'Tamamlandı',
        'failed' => 'Koşucu hatası',
        'stopped' => 'Durduruldu',
    ];
    $attemptModeLabels = [
        'continue' => 'Model devamı',
        'validate' => 'Yalnız doğrulama',
    ];
    $activeAttemptStatuses = ['starting', 'running', 'stopping'];
    $schemaLabel = match ($run->schema) {
        'legacy' => 'Legacy',
        'incomplete' => 'Tamamlanmamış',
        default => 'V2 · Genişletilmiş',
    };
    $metaLabels = [
        'test' => 'Koşu kimliği',
        'node' => 'Node.js',
        'npm' => 'npm',
        'opencode' => 'OpenCode',
        'prompt_sha256' => 'Prompt SHA-256',
        'openrouter_routing' => 'OpenRouter yönlendirme tercihi',
        'openrouter_requested_providers' => 'İstenen sabit sağlayıcılar',
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
    $formatFileSize = static function (?int $bytes): string {
        if ($bytes === null) {
            return 'Hesaplanmadı';
        }

        return \Illuminate\Support\Number::fileSize($bytes, 2);
    };
    $formatExactBytes = static fn (?int $bytes): string => $bytes === null
        ? 'Ölçülmedi'
        : number_format($bytes, 0, ',', '.').' byte';
    $failedModels = $run->modelRuns->where('status', 'fail');
    $runStatus = $run->schema === 'incomplete' || $run->model_count === 0
        ? 'unknown'
        : ($run->failed_count > 0 ? 'fail' : ($run->unknown_count + $run->skipped_count > 0 ? 'unknown' : 'pass'));
    $runArtifacts = $run->artifacts->whereNull('model_run_id');
    $toneStyles = [
        'pass' => ['label' => 'Başarılı', 'badge' => 'status-pass', 'bar' => 'bg-[var(--success)]', 'text' => 'text-[var(--success)]'],
        'fail' => ['label' => 'Sorunlu', 'badge' => 'status-fail', 'bar' => 'bg-[var(--red)]', 'text' => 'text-[var(--red)]'],
        'provider' => ['label' => 'Puanlanmaz', 'badge' => 'status-unknown', 'bar' => 'bg-[var(--amber)]', 'text' => 'text-[#8b5a16]'],
        'warning' => ['label' => 'Puanlandı', 'badge' => 'status-unknown', 'bar' => 'bg-[var(--amber)]', 'text' => 'text-[#8b5a16]'],
        'unknown' => ['label' => 'Veri yok', 'badge' => 'status-unknown', 'bar' => 'bg-[var(--line-strong)]', 'text' => 'text-[var(--muted)]'],
    ];
    $usageOf = static fn ($subject): ?array => is_array($subject->usage) && ($subject->usage['source'] ?? null) === 'opencode_session' ? $subject->usage : null;
    $money = static fn (array $usage): string => '$'.number_format((float) ($usage['costUsd'] ?? 0), 6, ',', '.');
    $tokens = static fn (array $usage): string => 'Girdi '.number_format((int) ($usage['inputTokens'] ?? 0), 0, ',', '.').' · Çıktı '.number_format((int) ($usage['outputTokens'] ?? 0), 0, ',', '.');
    $totalCost = $run->modelRuns->sum(fn ($modelRun) => (float) ($usageOf($modelRun)['costUsd'] ?? 0));
    $unscoredCount = $run->modelRuns->filter(fn ($modelRun) => ($outcomes[$modelRun->id]['tone'] ?? null) === 'provider')->count();
    $viewableModelCount = $previewableModelIds->merge($recoveredPreviewModelIds)->unique()->count();
    $recoverableModels = $run->modelRuns->filter(fn ($modelRun) => $modelRun->status === 'fail' || $modelRun->modelRunAttempts->isNotEmpty());
    $boundaryTotals = $run->modelRuns->reduce(function (array $carry, $modelRun) use ($modelBoundaryReports, $attemptBoundaryReports) {
        $reports = collect([$modelBoundaryReports[$modelRun->id] ?? []])->concat($modelRun->modelRunAttempts->map(fn ($attempt) => $attemptBoundaryReports[$attempt->id] ?? []));

        return ['blocked' => $carry['blocked'] + $reports->sum('blockedCount'), 'observed' => $carry['observed'] + $reports->sum('observedCrossModelReadCount')];
    }, ['blocked' => 0, 'observed' => 0]);
@endphp

<div class="content-frame run-page" @if ($activeAttemptCount > 0) data-attempt-refresh @endif>
    @if (session('status'))
        <div class="mb-5 rounded-xl border border-[#b9ead8] bg-[var(--success-soft)] px-4 py-3 text-sm font-semibold text-[var(--success)]" role="status">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->has('storage') || $errors->has('confirmation') || $errors->has('continuation') || $errors->has('acknowledge_cost') || $errors->has('mode'))
        <div class="mb-5 rounded-xl border border-[#f2c9cf] bg-[var(--red-soft)] px-4 py-3 text-sm font-semibold text-[var(--red)]" role="alert">
            {{ $errors->first('storage') ?: $errors->first('confirmation') ?: $errors->first('continuation') ?: $errors->first('acknowledge_cost') ?: $errors->first('mode') }}
        </div>
    @endif

    <nav class="mb-5 flex items-center gap-2 text-sm font-semibold text-[var(--muted)]" aria-label="Sayfa yolu">
        <a class="transition-colors hover:text-[var(--forest)]" href="{{ route('dashboard') }}">Genel Bakış</a>
        <span aria-hidden="true">/</span>
        <span class="text-[var(--ink)]" aria-current="page">{{ $run->name }}</span>
    </nav>

    {{-- Header: identity, verdict and the key numbers in one place --}}
    <header class="detail-hero rounded-3xl border border-[var(--line)] bg-white p-6 sm:p-8">
        <div class="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0 max-w-3xl">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="status-badge status-{{ $runStatus }}"><span class="status-dot"></span>{{ $statusLabels[$runStatus] }}</span>
                    <span class="schema-badge {{ in_array($run->schema, ['legacy', 'incomplete'], true) ? 'schema-badge-legacy' : 'schema-badge-extended' }}">{{ $schemaLabel }}</span>
                    <span class="text-xs font-bold tracking-[0.08em] text-[var(--muted)] uppercase">{{ \App\Models\BenchmarkRun::ENVIRONMENT_PROFILES[$run->environmentProfile()] }}</span>
                </div>
                <h1 class="mt-3 text-3xl font-black tracking-[-0.045em] text-[var(--ink)] sm:text-[40px]">{{ $run->name }}</h1>
                <p class="mt-2 text-sm leading-6 text-[var(--muted)]">
                    {{ $run->started_at?->locale('tr')->translatedFormat('j F Y · H:i') ?? 'Tarih kaydı yok' }} ·
                    @if ($run->failed_count > 0)
                        {{ $run->model_count }} modelin {{ $run->failed_count }} tanesinde sorun var. İlk teslim sonuçları değiştirilmeden gösterilir.
                    @elseif ($run->model_count === 0)
                        Bu koşu için okunabilir bir model sonucu bulunamadı.
                    @else
                        Tüm modeller bu koşudaki doğrulamaları geçti.
                    @endif
                </p>
            </div>
            @if ($viewableModelCount > 0)
                <a class="inline-flex min-h-11 shrink-0 items-center gap-2 self-start rounded-xl bg-[var(--forest)] px-5 py-2.5 text-sm font-extrabold text-white shadow-[0_10px_24px_rgb(79_70_229/0.22)] transition hover:bg-[#4338ca]" href="{{ route('runs.compare', $run) }}">
                    <span aria-hidden="true">◫</span>
                    {{ $viewableModelCount > 1 ? 'Siteleri karşılaştır' : 'Siteyi görüntüle' }}
                </a>
            @endif
        </div>

        <dl class="mt-6 grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-[var(--line)] bg-[var(--line)] sm:grid-cols-3 xl:grid-cols-6">
            @foreach ([
                ['Modeller', $run->model_count, count($run->stage_names).' doğrulama aşaması', 'text-[var(--ink)]'],
                ['Başarılı', $run->passed_count, 'Tüm aşamalar geçti', 'text-[var(--success)]'],
                ['Sorunlu', $run->failed_count, 'En az bir aşama başarısız', $run->failed_count > 0 ? 'text-[var(--red)]' : 'text-[var(--ink)]'],
                ['Puanlanmayan', $unscoredCount, 'Sağlayıcı veya hesap hatası', $unscoredCount > 0 ? 'text-[#8b5a16]' : 'text-[var(--ink)]'],
                ['Toplam süre', $formatDuration($run->total_duration_seconds), 'Modellerin toplamı', 'text-[var(--ink)]'],
                ['Toplam maliyet', '$'.number_format($totalCost, 4, ',', '.'), 'OpenCode kaydı', 'text-[var(--ink)]'],
            ] as [$label, $value, $hint, $color])
                <div class="bg-white px-4 py-3.5">
                    <dt class="text-xs font-bold text-[var(--muted)]">{{ $label }}</dt>
                    <dd class="mt-1 text-2xl font-black tracking-[-0.03em] {{ $color }}">{{ $value }}</dd>
                    <dd class="text-xs text-[var(--muted)]">{{ $hint }}</dd>
                </div>
            @endforeach
        </dl>
    </header>

    @if ($run->schema === 'legacy')
        <aside class="mt-4 rounded-2xl border border-[#f1d7a5] bg-[var(--amber-soft)] px-5 py-4 text-sm text-[#8b5a16]">
            <strong>Legacy ölçüm kapsamı sınırlı.</strong> Bu sonucu genişletilmiş suite’lerin runtime güvencesiyle eşdeğer kabul etmeyin.
        </aside>
    @endif
    @if ($run->schema === 'incomplete')
        <aside class="mt-4 rounded-2xl border border-[#f1d7a5] bg-[var(--amber-soft)] px-5 py-4 text-sm text-[#8b5a16]">
            <strong>Bu koşu tamamlanmamış.</strong> Sonuç özeti oluşmamış; bulunan çalışma alanları ve yardımcı çıktılar depolama temizliği için gösteriliyor.
        </aside>
    @endif

    {{-- Results: one row per model with the verdict in plain words --}}
    <section class="mt-8" aria-labelledby="results-title">
        <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="eyebrow">Teknik sonuçlar</p>
                <h2 id="results-title" class="mt-1 text-2xl font-black tracking-[-0.035em]">Model sonuçları</h2>
            </div>
            <p class="text-sm text-[var(--muted)]">Aşama işaretleri ilk teslimi gösterir ve logu açar. Maliyet OpenCode kaydıdır; OpenRouter faturası değildir.</p>
        </div>

        <ol class="mt-4 grid gap-3">
            @foreach ($run->modelRuns as $modelRun)
                @php
                    $outcome = $outcomes[$modelRun->id] ?? ['tone' => 'unknown', 'title' => 'Sonuç okunamadı', 'detail' => null, 'autoContinued' => false];
                    $tone = $toneStyles[$outcome['tone']] ?? $toneStyles['unknown'];
                    $stageResults = $modelRun->stageResults->keyBy('stage');
                    $usage = $usageOf($modelRun);
                    $attemptCount = $modelRun->modelRunAttempts->count();
                @endphp
                <li class="result-row relative overflow-hidden rounded-2xl border border-[var(--line)] bg-white">
                    <span class="absolute inset-y-0 left-0 w-1 {{ $tone['bar'] }}" aria-hidden="true"></span>
                    <div class="grid gap-4 p-4 pl-6 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-base font-black text-[var(--ink)]">{{ str($modelRun->model)->after('/') }}</h3>
                                <span class="status-badge {{ $tone['badge'] }}">{{ $tone['label'] }}</span>
                                @if ($outcome['autoContinued'])
                                    <span class="rounded-full border border-[#d8d1f5] bg-[var(--violet-soft)] px-2.5 py-0.5 text-xs font-bold text-[var(--violet)]">Otomatik devam edildi</span>
                                @endif
                                @if ($attemptCount > 0)
                                    <a class="rounded-full border border-[var(--line)] bg-[var(--surface-subtle)] px-2.5 py-0.5 text-xs font-bold text-[var(--muted)] hover:text-[var(--forest)]" href="#recovery-{{ $modelRun->slug }}">{{ $attemptCount }} kurtarma denemesi</a>
                                @endif
                            </div>
                            <p class="mt-1.5 text-sm font-bold {{ $tone['text'] }}">{{ $outcome['title'] }}</p>
                            @if ($outcome['detail'])
                                <p class="mt-0.5 break-words font-mono text-xs leading-5 text-[var(--muted)]">{{ $outcome['detail'] }}</p>
                            @endif
                            <code class="mt-1 block text-xs text-[var(--muted)]">{{ $modelRun->slug }}</code>
                        </div>

                        <div class="flex flex-col gap-3 lg:items-end">
                        <div class="flex flex-wrap items-center gap-1.5" role="list" aria-label="Aşama sonuçları">
                            @foreach ($run->stage_names as $stage)
                                @php
                                    $stageResult = $stageResults->get($stage);
                                    $status = $stageResult?->status ?? 'unknown';
                                    $artifact = $artifactsByKey->get('model:'.$modelRun->slug.':log:'.$stage);
                                    $mark = match ($status) { 'pass' => '✓', 'fail' => '×', 'skip' => '—', default => '?' };
                                    $stageTitle = ($stageLabels[$stage] ?? $stage).': '.($statusLabels[$status] ?? 'Veri yok');
                                @endphp
                                <span class="stage-chip" role="listitem">
                                    @if ($artifact?->kind === 'file')
                                        <a class="stage-mark stage-mark-{{ $status }} outline-none ring-[var(--forest)] focus-visible:ring-2" href="{{ route('runs.artifacts.show', [$run, $artifact]) }}" title="{{ $stageTitle }} · logu aç" aria-label="{{ $stageTitle }}, {{ $stageLabels[$stage] ?? $stage }} logu">{{ $mark }}</a>
                                    @else
                                        <span class="stage-mark stage-mark-{{ $status }}" title="{{ $stageTitle }}" aria-label="{{ $stageTitle }}">{{ $mark }}</span>
                                    @endif
                                    <span class="stage-chip-label">{{ $stageLabels[$stage] ?? $stage }}</span>
                                </span>
                            @endforeach
                        </div>

                        <div class="flex flex-wrap items-center gap-4 lg:justify-end">
                            <div class="text-right">
                                <p class="font-mono text-sm font-bold text-[var(--ink)]">{{ $formatDuration($modelRun->duration_seconds) }}</p>
                                @if ($usage)
                                    <p class="font-mono text-xs text-[var(--muted)]" title="{{ $tokens($usage) }}">{{ $money($usage) }}</p>
                                @else
                                    <p class="text-xs text-[var(--muted)]">Kullanım kaydı yok</p>
                                @endif
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                @if ($previewableModelIds->contains($modelRun->id))
                                    <a class="btn-soft" href="{{ route('runs.compare', ['benchmarkRun' => $run, 'left' => $modelRun->id]) }}">Önizle</a>
                                    <a class="btn-ghost" href="{{ route('runs.models.preview', [$run, $modelRun]) }}" target="_blank" rel="noopener noreferrer" aria-label="{{ str($modelRun->model)->after('/') }} çıktısını yeni sekmede aç">↗</a>
                                @elseif ($recoveredPreviewModelIds->contains($modelRun->id))
                                    <a class="btn-ghost" href="{{ route('runs.models.preview', ['benchmarkRun' => $run, 'modelRun' => $modelRun, 'version' => 'recovered']) }}" target="_blank" rel="noopener noreferrer" title="İlk teslimde çıktı yok; kurtarılmış çıktı puanlanmaz">Kurtarılmış ↗</a>
                                @else
                                    <span class="text-sm font-semibold text-[var(--muted)]">Çıktı yok</span>
                                @endif
                                @if ($modelRun->evaluation)
                                    <a class="btn-soft" href="{{ route('runs.models.preview', ['benchmarkRun' => $run, 'modelRun' => $modelRun, 'review' => 1]) }}" target="_blank" rel="noopener noreferrer"><strong>{{ $modelRun->evaluation->overall_score }}</strong>/10 · Düzenle</a>
                                @elseif ($previewableModelIds->contains($modelRun->id))
                                    <a class="btn-ghost" href="{{ route('runs.models.preview', ['benchmarkRun' => $run, 'modelRun' => $modelRun, 'review' => 1]) }}" target="_blank" rel="noopener noreferrer">Puanla</a>
                                @endif
                                @if ($recoverableModels->contains('id', $modelRun->id))
                                    <a class="btn-ghost" href="#recovery-{{ $modelRun->slug }}" data-open-details="recovery-{{ $modelRun->slug }}">Kurtar</a>
                                @endif
                            </div>
                        </div>
                        </div>
                    </div>
                </li>
            @endforeach
        </ol>
    </section>

    {{-- Recovery: collapsed per model, attempts as a timeline, actions in one place --}}
    @if ($recoverableModels->isNotEmpty())
        <section class="mt-10" aria-labelledby="diagnostics-title">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="eyebrow">İlk sonuç korunur</p>
                    <h2 id="diagnostics-title" class="mt-1 text-2xl font-black tracking-[-0.035em]">Kurtarma</h2>
                </div>
                <div class="flex flex-wrap items-center gap-2 text-sm text-[var(--muted)]">
                    <span class="rounded-full border border-[var(--line)] bg-white px-3 py-1 font-bold text-[var(--ink)]">Aktif kurtarma: {{ $activeAttemptCount }}/{{ $maxParallelAttempts }}</span>
                    <span>İki farklı model aynı anda devam edebilir; aynı modelin denemeleri sırayla çalışır.</span>
                </div>
            </div>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-[var(--muted)]">
                Kurtarma denemeleri ilk teslimin sonucunu ve puanını değiştirmez. Her deneme ayrı kaydedilir; başarılı bir denemenin çıktısı “kurtarılmış” görünümde incelenir ve puanlanmaz.
            </p>

            <div class="mt-4 grid gap-3">
                @foreach ($recoverableModels as $modelRun)
                    @php
                        $outcome = $outcomes[$modelRun->id] ?? ['tone' => 'unknown', 'title' => 'Sonuç okunamadı', 'detail' => null];
                        $availability = $continuationAvailability[$modelRun->id] ?? [];
                        $canContinue = (bool) ($availability['canContinue'] ?? false);
                        $canValidate = (bool) ($availability['canValidate'] ?? false);
                        $unavailableReason = $availability['reason'] ?? null;
                        $attempts = $modelRun->modelRunAttempts->sortBy('attempt_number');
                        $hasActive = $attempts->contains(fn ($attempt) => in_array($attempt->status, $activeAttemptStatuses, true));
                        $latest = $attempts->last();
                        $usage = $usageOf($modelRun);
                    @endphp
                    <details id="recovery-{{ $modelRun->slug }}" class="recovery-card group rounded-2xl border border-[var(--line)] bg-white" @if ($hasActive) open @endif>
                        <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-3 px-5 py-4 marker:content-none">
                            <div class="min-w-0">
                                <h3 class="text-base font-black text-[var(--ink)]">{{ str($modelRun->model)->after('/') }}</h3>
                                <p class="mt-0.5 text-sm text-[var(--muted)]">{{ $outcome['title'] }}</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="rounded-full bg-[var(--surface-subtle)] px-2.5 py-1 text-xs font-bold text-[var(--muted)]">{{ $attempts->count() + 1 }} kayıt</span>
                                @if ($latest)
                                    <span class="status-badge {{ in_array($latest->status, $activeAttemptStatuses, true) ? 'status-running' : ($latest->status === 'completed' && ($latest->result['overallStatus'] ?? null) === 'pass' ? 'status-pass' : 'status-unknown') }}">Son: {{ $attemptStatusLabels[$latest->status] ?? $latest->status }}</span>
                                @endif
                                <span class="recovery-chevron text-[var(--muted)]" aria-hidden="true">⌄</span>
                            </div>
                        </summary>

                        <div class="grid gap-5 border-t border-[var(--line)] p-5 lg:grid-cols-[minmax(0,1fr)_340px]">
                            <div>
                                <h4 class="text-sm font-black text-[var(--ink)]">Deneme geçmişi</h4>
                                <ol class="timeline mt-3">
                                    <li class="timeline-item">
                                        <span class="timeline-dot timeline-dot-{{ $modelRun->status === 'pass' ? 'pass' : 'fail' }}" aria-hidden="true"></span>
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <p class="text-sm font-bold">İlk teslim <span class="font-normal text-[var(--muted)]">· kayıtlı ana sonuç</span></p>
                                            <span class="status-badge status-{{ $modelRun->status }}">{{ $statusLabels[$modelRun->status] ?? 'Veri yok' }}</span>
                                        </div>
                                        <p class="mt-1 text-sm text-[var(--muted)]">{{ $outcome['title'] }} · {{ $formatDuration($modelRun->duration_seconds) }} · Bu kayıt değiştirilmeyecek.</p>
                                        @if ($usage)
                                            <p class="mt-1 text-xs text-[var(--muted)]">OpenCode kaydı: {{ $money($usage) }} · {{ $tokens($usage) }}</p>
                                        @endif
                                        <div class="mt-2 flex flex-wrap gap-1.5">
                                            @foreach ($modelRun->stageResults->where('status', 'fail') as $stageResult)
                                                @php $artifact = $artifactsByKey->get('model:'.$modelRun->slug.':log:'.$stageResult->stage); @endphp
                                                @if ($artifact?->kind === 'file')
                                                    <a class="log-pill" href="{{ route('runs.artifacts.show', [$run, $artifact]) }}">{{ $stageLabels[$stageResult->stage] ?? $stageResult->stage }} logu →</a>
                                                @else
                                                    <span class="log-pill">{{ $stageLabels[$stageResult->stage] ?? $stageResult->stage }}</span>
                                                @endif
                                            @endforeach
                                        </div>
                                    </li>

                                    @foreach ($attempts as $attempt)
                                        @php
                                            $attemptResult = $attempt->result ?? [];
                                            $overallStatus = $attemptResult['overallStatus'] ?? null;
                                            $attemptTone = in_array($attempt->status, $activeAttemptStatuses, true)
                                                ? 'running'
                                                : ($attempt->status === 'completed' ? ($overallStatus ?? 'unknown') : ($attempt->status === 'failed' ? 'fail' : 'unknown'));
                                            $attemptDuration = $attemptResult['durationSeconds'] ?? null;
                                            $failedStages = $attemptResult['failedStages'] ?? [];
                                            $attemptLogTail = $attemptLogTails[$attempt->id] ?? null;
                                        @endphp
                                        <li class="timeline-item">
                                            <span class="timeline-dot timeline-dot-{{ $attemptTone }}" aria-hidden="true"></span>
                                            <div class="flex flex-wrap items-center justify-between gap-2">
                                                <p class="text-sm font-bold">Deneme {{ $attempt->attempt_number }} <span class="font-normal text-[var(--muted)]">· {{ $attemptModeLabels[$attempt->mode] ?? $attempt->mode }}</span></p>
                                                <span class="status-badge status-{{ $attemptTone }}">{{ $attemptStatusLabels[$attempt->status] ?? str($attempt->status)->headline() }}</span>
                                            </div>
                                            <p class="mt-1 text-xs text-[var(--muted)]">
                                                {{ $attempt->started_at?->locale('tr')->translatedFormat('j M Y · H:i') ?? 'Başlangıç kaydı yok' }}
                                                @if (is_numeric($attemptDuration))
                                                    · {{ $formatDuration((int) $attemptDuration) }}
                                                @endif
                                            </p>
                                            @if ($attempt->status === 'completed')
                                                <p class="mt-1 text-sm font-bold {{ $overallStatus === 'pass' ? 'text-[var(--success)]' : ($overallStatus === 'fail' ? 'text-[var(--red)]' : 'text-[var(--muted)]') }}">
                                                    {{ $overallStatus === 'pass' ? 'Tüm doğrulamalar başarılı.' : ($overallStatus === 'fail' ? 'Deneme tamamlandı, ancak bazı doğrulamalar başarısız.' : 'Deneme tamamlandı; kesin sonuç üretilemedi.') }}
                                                </p>
                                            @endif
                                            @if ($attemptUsage = $usageOf($attempt))
                                                <p class="mt-1 text-xs text-[var(--muted)]">OpenCode kaydı: {{ $money($attemptUsage) }} · {{ $tokens($attemptUsage) }}</p>
                                            @elseif (($attempt->usage['source'] ?? null) === 'validation_only')
                                                <p class="mt-1 text-xs text-[var(--muted)]">Yalnız doğrulama: yeni model tokeni veya model maliyeti yok.</p>
                                            @endif
                                            @if (is_array($failedStages) && $failedStages !== [])
                                                <div class="mt-2 flex flex-wrap gap-1.5">
                                                    @foreach ($failedStages as $failedStage)
                                                        <span class="log-pill">{{ $stageLabels[$failedStage] ?? $failedStage }}</span>
                                                    @endforeach
                                                </div>
                                            @endif
                                            @if ($attempt->message)
                                                <p class="mt-2 rounded-lg bg-[var(--surface-subtle)] px-3 py-2 text-xs leading-5 text-[var(--muted)]">{{ $attempt->message }}</p>
                                            @endif
                                            @include('runs.boundary-report', ['report' => $attemptBoundaryReports[$attempt->id] ?? []])
                                            @if ($attemptLogTail)
                                                <details class="mt-2 rounded-lg bg-[#10182c] text-white" open>
                                                    <summary class="cursor-pointer px-3 py-2 text-xs font-bold text-[#8ce6dc]">Canlı çalışma günlüğü</summary>
                                                    <pre class="max-h-64 overflow-auto border-t border-white/10 p-3 font-mono text-xs leading-5 whitespace-pre-wrap text-[#dfe7f5]">{{ $attemptLogTail }}</pre>
                                                </details>
                                            @endif
                                            @if (in_array($attempt->status, $activeAttemptStatuses, true))
                                                <form class="mt-3" method="POST" action="{{ route('runs.models.attempts.destroy', ['benchmarkRun' => $run, 'modelRun' => $modelRun, 'modelRunAttempt' => $attempt]) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="btn-danger" type="submit">Denemeyi durdur</button>
                                                </form>
                                            @endif
                                        </li>
                                    @endforeach
                                </ol>
                            </div>

                            <aside class="h-fit rounded-2xl border border-[var(--line)] bg-[var(--surface-subtle)] p-4" aria-label="{{ str($modelRun->model)->after('/') }} için kurtarma eylemleri">
                                <h4 class="text-sm font-black text-[var(--ink)]">Ne yapmak istersiniz?</h4>
                                @if (! $canContinue && ! $canValidate && $unavailableReason)
                                    <p class="mt-3 rounded-lg border border-[#f1d7a5] bg-[var(--amber-soft)] px-3 py-2 text-xs leading-5 text-[#8b5a16]">{{ $unavailableReason }}</p>
                                @endif

                                <div class="mt-3 grid gap-3">
                                    <div class="rounded-xl border border-[#d8d1f5] bg-white p-3">
                                        <p class="text-sm font-bold text-[var(--violet)]">Kaldığı yerden devam ettir</p>
                                        <p class="mt-1 text-xs leading-5 text-[var(--muted)]">Aynı çalışma alanı ve OpenCode oturumunun ayrılmış bir kopyasıyla model yeniden çağrılır. Token harcanır.</p>
                                        @if (! $canContinue && $canValidate)
                                            <p class="mt-1 text-xs font-semibold text-[#8b5a16]">Bu model için devam ettirilebilir bir OpenCode oturumu bulunamadı.</p>
                                        @endif
                                        <button class="btn-primary mt-3 w-full" type="button" data-dialog-open="continue-{{ $modelRun->slug }}" @disabled(! $canContinue)>
                                            {{ $canContinue ? 'Devam denemesi başlat' : 'Devam kullanılamıyor' }}
                                        </button>
                                    </div>

                                    <form class="rounded-xl border border-[#b9ead8] bg-white p-3" method="POST" action="{{ route('runs.models.attempts.store', ['benchmarkRun' => $run, 'modelRun' => $modelRun]) }}">
                                        @csrf
                                        <input type="hidden" name="mode" value="validate">
                                        <p class="text-sm font-bold text-[var(--success)]">Değişiklik yapmadan yeniden doğrula</p>
                                        <p class="mt-1 text-xs leading-5 text-[var(--muted)]">Model çağrılmaz; lint, build, export, rota ve tarayıcı kontrolleri yeniden çalışır.</p>
                                        <button class="btn-secondary mt-3 w-full" type="submit" @disabled(! $canValidate)>
                                            {{ $canValidate ? 'Yalnız doğrulamayı çalıştır' : 'Doğrulama kullanılamıyor' }}
                                        </button>
                                    </form>
                                </div>
                            </aside>
                        </div>

                    </details>

                    <dialog id="continue-{{ $modelRun->slug }}" class="confirm-dialog" aria-labelledby="continue-{{ $modelRun->slug }}-title">
                        <form method="POST" action="{{ route('runs.models.attempts.store', ['benchmarkRun' => $run, 'modelRun' => $modelRun]) }}">
                            @csrf
                            <input type="hidden" name="mode" value="continue">
                            <p class="eyebrow">Kurtarma denemesi</p>
                            <h4 id="continue-{{ $modelRun->slug }}-title" class="mt-1 text-lg font-black">{{ str($modelRun->model)->after('/') }} kaldığı yerden devam etsin mi?</h4>
                            <p class="mt-2 text-sm leading-6 text-[var(--muted)]">Model, kendi hata özeti ve zorunlu rotalarla aynı oturumun ayrılmış bir kopyasında yeniden çağrılır. İlk teslimin sonucu ve puanı değişmez.</p>
                            <label class="mt-4 flex items-start gap-2.5 rounded-xl border border-[#d8d1f5] bg-[var(--violet-soft)] p-3 text-sm font-semibold text-[var(--violet)]">
                                <input class="mt-0.5 rounded border-[#c8baf8]" type="checkbox" name="acknowledge_cost" value="1" required>
                                <span>Yeni token veya sağlayıcı kredisi harcanabileceğini anlıyorum.</span>
                            </label>
                            <div class="mt-5 flex flex-wrap justify-end gap-2">
                                <button class="btn-ghost" type="button" data-dialog-close>Vazgeç</button>
                                <button class="btn-primary" type="submit">Devam denemesi başlat</button>
                            </div>
                        </form>
                    </dialog>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Technical details: boundary audit, environment and files, collapsed by default --}}
    <section class="mt-10" aria-labelledby="details-title">
        <p class="eyebrow">Kayıtlar</p>
        <h2 id="details-title" class="mt-1 text-2xl font-black tracking-[-0.035em]">Teknik ayrıntılar</h2>

        <div class="mt-4 grid gap-3">
            <details class="detail-fold rounded-2xl border border-[var(--line)] bg-white" @if ($boundaryTotals['observed'] > 0) open @endif>
                <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-3 px-5 py-4 marker:content-none">
                    <span>
                        <span id="workspace-boundary-title" class="block text-base font-black">Model çalışma sınırları</span>
                        <span class="text-sm text-[var(--muted)]">Modellerin kendi klasörü dışına erişim denetimi</span>
                    </span>
                    <span class="flex gap-2">
                        <span class="rounded-full bg-[var(--surface-subtle)] px-2.5 py-1 text-xs font-bold {{ $boundaryTotals['blocked'] > 0 ? 'text-[#8b5a16]' : 'text-[var(--muted)]' }}">{{ $boundaryTotals['blocked'] }} engellenen erişim</span>
                        @if ($boundaryTotals['observed'] > 0)
                            <span class="rounded-full bg-[var(--red-soft)] px-2.5 py-1 text-xs font-bold text-[var(--red)]">Bağımsızlık uyarısı</span>
                        @endif
                    </span>
                </summary>
                <div class="border-t border-[var(--line)] p-5">
                    <p class="text-sm leading-6 text-[var(--muted)]">Denemeler yalnızca kendi proje alanlarında çalışacak şekilde yönlendirilir. Üç kapsam dışı erişim reddinden sonra yalnızca ilgili model çağrısı durdurulur. Bu araç politikası tam işletim sistemi izolasyonu değildir.</p>
                    <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($run->modelRuns as $modelRun)
                            @php
                                $ownReports = collect([$modelBoundaryReports[$modelRun->id] ?? []])->concat(
                                    $modelRun->modelRunAttempts->map(fn ($attempt) => $attemptBoundaryReports[$attempt->id] ?? [])
                                );
                                $blockedCount = $ownReports->sum('blockedCount');
                                $observedReadCount = $ownReports->sum('observedCrossModelReadCount');
                                $hasCurrentPolicy = $ownReports->contains(fn ($report) => ($report['policyVersion'] ?? null) === 'workspace-boundary-v1');
                            @endphp
                            <article class="rounded-xl border border-[var(--line)] bg-[var(--surface-subtle)] p-4">
                                <h3 class="break-words text-sm font-black">{{ str($modelRun->model)->after('/') }}</h3>
                                <p class="mt-1 text-xs text-[var(--muted)]">{{ $hasCurrentPolicy ? 'Yeni sınır politikası kaydı var' : 'Eski koşu · yeni sınır politikası kaydı yok' }}</p>
                                <p class="mt-2 text-sm font-bold {{ $blockedCount > 0 ? 'text-[var(--amber)]' : 'text-[var(--muted)]' }}">Engellenen erişim: {{ $blockedCount }}</p>
                                @if ($observedReadCount > 0)
                                    <p class="mt-2 rounded-lg border border-[#f2c9cf] bg-[var(--red-soft)] p-2 text-xs font-bold leading-5 text-[var(--red)]">Karşılaştırma uyarısı: {{ $observedReadCount }} araç çıktısında başka modele ait kayıt görüldü. Engellenmiş girişimden farklıdır; bu denemeyi bağımsız kabul etmeden inceleyin.</p>
                                @endif
                                @include('runs.boundary-report', ['report' => $modelBoundaryReports[$modelRun->id] ?? []])
                            </article>
                        @endforeach
                    </div>
                    <p class="mt-3 text-xs leading-5 text-[var(--muted)]">Sayılar ilk teslim ve kurtarma kayıtlarını kapsar. Eski loglar sınırlı bir bölümden incelenir; sıfır kayıt, hiç erişim olmadığının kanıtı değildir.</p>
                </div>
            </details>

            <details class="detail-fold rounded-2xl border border-[var(--line)] bg-white">
                <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-3 px-5 py-4 marker:content-none">
                    <span>
                        <span id="meta-title" class="block text-base font-black">Ortam ve rotalar</span>
                        <span class="text-sm text-[var(--muted)]">Araç sürümleri, prompt özeti ve doğrulanan rotalar</span>
                    </span>
                    <span class="rounded-full bg-[var(--surface-subtle)] px-2.5 py-1 text-xs font-bold text-[var(--muted)]">{{ count($run->routes ?? []) }} rota</span>
                </summary>
                <div class="grid gap-6 border-t border-[var(--line)] p-5 lg:grid-cols-[minmax(0,1fr)_280px]">
                    <dl class="divide-y divide-[var(--line)]">
                        <div class="flex items-start justify-between gap-4 py-2.5">
                            <dt class="text-sm font-bold text-[var(--muted)]">Klasör</dt>
                            <dd class="max-w-[65%] break-all text-right font-mono text-xs text-[var(--ink)]">{{ $run->source_path }}</dd>
                        </div>
                        @foreach (($run->metadata ?? []) as $key => $value)
                            @continue(in_array($key, ['date', 'tarih', 'routes'], true))
                            <div class="flex items-start justify-between gap-4 py-2.5">
                                <dt class="text-sm font-bold text-[var(--muted)]">{{ $metaLabels[$key] ?? str($key)->replace('_', ' ')->title() }}</dt>
                                <dd class="max-w-[65%] break-all text-right font-mono text-xs text-[var(--ink)]">{{ $key === 'openrouter_routing' ? (['auto' => 'Otomatik', 'price' => 'Düşük fiyat öncelikli', 'throughput' => 'Yüksek hız öncelikli', 'fixed' => 'Sabit sağlayıcı; fallback kapalı'][$value] ?? $value) : $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <div>
                        <h3 class="text-sm font-black text-[var(--ink)]">Zorunlu rotalar</h3>
                        @if (($run->routes ?? []) === [])
                            <p class="mt-2 text-sm text-[var(--muted)]">Rota listesi kaydedilmemiş.</p>
                        @else
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach ($run->routes as $route)
                                    <code class="rounded-md bg-[var(--surface-subtle)] px-2 py-1 text-xs text-[var(--ink)]">{{ $route }}</code>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </details>

            <details class="detail-fold rounded-2xl border border-[var(--line)] bg-white">
                <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-3 px-5 py-4 marker:content-none">
                    <span>
                        <span id="artifacts-title" class="block text-base font-black">Artifact ve loglar</span>
                        <span class="text-sm text-[var(--muted)]">Koşu düzeyindeki dosyalar; model logları yukarıdaki aşama işaretlerinden açılır</span>
                    </span>
                    <span class="rounded-full bg-[var(--surface-subtle)] px-2.5 py-1 text-xs font-bold text-[var(--muted)]">{{ $run->artifacts->count() }} kayıtlı konum</span>
                </summary>
                <div class="grid gap-3 border-t border-[var(--line)] p-5 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($runArtifacts as $artifact)
                        <article class="artifact-card">
                            <div class="flex items-start justify-between gap-3">
                                <strong class="text-sm">{{ $artifact->label }}</strong>
                                <span class="text-xs text-[var(--muted)]">{{ $artifact->kind === 'directory' ? 'Klasör' : $formatFileSize($artifact->size_bytes) }}</span>
                            </div>
                            <code class="mt-2 block truncate text-xs text-[var(--muted)]" title="{{ $artifact->path }}">{{ $artifact->relative_path }}</code>
                            @if ($artifact->kind === 'file')
                                <a class="mt-3 inline-flex text-sm font-extrabold text-[var(--forest)]" href="{{ route('runs.artifacts.show', [$run, $artifact]) }}">İçeriği görüntüle →</a>
                            @endif
                        </article>
                    @endforeach
                </div>
            </details>
        </div>
    </section>

    {{-- Storage and destructive actions live at the very end, closed by default --}}
    <section class="mt-10 mb-4" aria-labelledby="storage-title">
        <details class="detail-fold rounded-2xl border border-[#f2c9cf] bg-white">
            <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-3 px-5 py-4 marker:content-none">
                <span>
                    <span class="eyebrow block">Depolama kullanımı</span>
                    <span id="storage-title" class="mt-1 block text-base font-black">Depolama ve tehlikeli işlemler</span>
                </span>
                <span class="flex flex-wrap gap-2 text-xs font-bold">
                    <span class="rounded-full bg-[var(--surface-subtle)] px-2.5 py-1 text-[var(--ink)]">Toplam {{ $formatFileSize($run->total_bytes) }}</span>
                    <span class="rounded-full bg-[var(--violet-soft)] px-2.5 py-1 text-[var(--violet)]">Çalışma alanları {{ $formatFileSize($run->workspace_bytes) }}</span>
                </span>
            </summary>
            <div class="border-t border-[var(--line)] p-5">
                <div class="grid gap-3 md:grid-cols-3">
                    <article class="rounded-xl border border-[var(--line)] bg-[var(--surface-subtle)] p-4">
                        <p class="text-xs font-bold text-[var(--muted)]">Toplam alan</p>
                        <strong class="mt-1 block text-2xl font-black">{{ $formatFileSize($run->total_bytes) }}</strong>
                        <span class="mt-1 block font-mono text-xs text-[var(--muted)]">{{ $formatExactBytes($run->total_bytes) }}</span>
                    </article>
                    <article class="rounded-xl border border-[#d8d1f5] bg-[var(--violet-soft)] p-4">
                        <p class="text-xs font-bold text-[var(--violet)]">Çalışma alanları</p>
                        <strong class="mt-1 block text-2xl font-black">{{ $formatFileSize($run->workspace_bytes) }}</strong>
                        <span class="mt-1 block font-mono text-xs text-[var(--muted)]">{{ $formatExactBytes($run->workspace_bytes) }} · {{ $run->workspace_count }} klasör</span>
                    </article>
                    <article class="rounded-xl border border-[#b9ead8] bg-[var(--success-soft)] p-4">
                        <p class="text-xs font-bold text-[var(--success)]">Sonuç ve çıktılar</p>
                        <strong class="mt-1 block text-2xl font-black">{{ $formatFileSize($run->result_bytes) }}</strong>
                        <span class="mt-1 block font-mono text-xs text-[var(--muted)]">{{ $formatExactBytes($run->result_bytes) }}</span>
                    </article>
                </div>
                <p class="mt-3 text-xs leading-5 text-[var(--muted)]">Ölçüm: {{ $run->storage_scanned_at?->locale('tr')->translatedFormat('j M Y · H:i') ?? 'henüz ölçülmedi' }}. Değerler diskteki dosyaların mantıksal byte toplamıdır.</p>

                <div class="mt-5 grid gap-3 border-t border-[var(--line)] pt-5 xl:grid-cols-3">
                    <form class="rounded-xl border border-[#f1d7a5] bg-[var(--amber-soft)] p-4" method="POST" action="{{ route('runs.workspace.destroy', $run) }}" data-destructive-form data-confirm-message="Model çalışma alanları kalıcı olarak silinsin mi? Yalnız tüm aşamaları geçen modellerin önizlemesi ayrı arşivde durur; diğerlerinin önizlemesi çalışma alanıyla birlikte silinir.">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="confirmation" value="">
                        <h3 class="text-sm font-black text-[#8b5a16]">Yalnızca çalışma alanlarını sil</h3>
                        <p class="mt-2 text-xs leading-5 text-[#8b5a16]">Model proje klasörlerini, node_modules ve build dosyalarını siler. Sonuç özeti, loglar ve puanlar korunur. Tüm aşamaları geçmeyen modellerin önizlemesi çalışma alanında durduğu için onlar da silinir.</p>
                        <button class="mt-4 rounded-lg border border-[#d9b968] bg-white px-3.5 py-2 text-sm font-extrabold text-[#8b5a16] transition hover:border-[#8b5a16] disabled:cursor-not-allowed disabled:opacity-45" type="submit" @disabled($run->workspace_count === 0)>
                            {{ $run->workspace_count === 0 ? 'Çalışma alanı zaten yok' : 'Çalışma alanlarını sil · '.$formatFileSize($run->workspace_bytes) }}
                        </button>
                    </form>

                    <form class="rounded-xl border border-[#d8d1f5] bg-[var(--violet-soft)] p-4" method="POST" action="{{ route('runs.results.destroy', $run) }}" data-destructive-form data-confirm-message="Sonuç özeti, loglar, önizlemeler ve puanlar kalıcı olarak silinsin mi? Model çalışma alanları korunacak.">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="confirmation" value="">
                        <h3 class="text-sm font-black text-[var(--violet)]">Yalnızca sonuç arşivini sil</h3>
                        <p class="mt-2 text-xs leading-5 text-[var(--violet)]">Sonuç özetini, logları, önizlemeleri ve puanları siler. Model proje klasörleri korunur.</p>
                        <button class="mt-4 rounded-lg border border-[#c8baf8] bg-white px-3.5 py-2 text-sm font-extrabold text-[var(--violet)] transition hover:border-[var(--violet)] disabled:cursor-not-allowed disabled:opacity-45" type="submit" @disabled(($run->result_bytes ?? 0) === 0)>
                            {{ ($run->result_bytes ?? 0) === 0 ? 'Sonuç arşivi zaten yok' : 'Sonuç arşivini sil' }}
                        </button>
                    </form>

                    <form class="rounded-xl border border-[#f2c9cf] bg-[var(--red-soft)] p-4" method="POST" action="{{ route('runs.destroy', $run) }}" data-destructive-form data-confirm-message="Bu benchmark sonucu, tüm dosyaları ve değerlendirmeleri kalıcı olarak silinsin mi?">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="confirmation" value="">
                        <h3 class="text-sm font-black text-[var(--red)]">Benchmarkı tamamen sil</h3>
                        <p class="mt-2 text-xs leading-5 text-[var(--red)]">Çalışma alanlarıyla birlikte sonuçları, logları, önizlemeleri, puanları ve veritabanı kaydını kalıcı olarak siler.</p>
                        <button class="mt-4 rounded-lg bg-[var(--red)] px-3.5 py-2 text-sm font-extrabold text-white transition hover:brightness-90" type="submit">Benchmarkı tamamen sil</button>
                    </form>
                </div>
                <noscript>
                    <p class="mt-3 text-sm text-[var(--red)]">Silme işlemlerini onaylayabilmek için JavaScript etkin olmalıdır.</p>
                </noscript>
            </div>
        </details>
    </section>
</div>
@endsection
