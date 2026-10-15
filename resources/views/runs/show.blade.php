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
        'pass' => ['label' => 'Başarılı', 'row' => 'result-row-pass', 'dot' => 'bg-[var(--success)]', 'text' => 'text-[var(--success)]'],
        'fail' => ['label' => 'Sorunlu', 'row' => 'result-row-fail', 'dot' => 'bg-[var(--red)]', 'text' => 'text-[var(--red)]'],
        'provider' => ['label' => 'Puanlanmaz', 'row' => 'result-row-provider', 'dot' => 'bg-[var(--amber)]', 'text' => 'text-[var(--amber-ink)]'],
        'warning' => ['label' => 'Puanlandı', 'row' => 'result-row-warning', 'dot' => 'bg-[var(--amber)]', 'text' => 'text-[var(--amber-ink)]'],
        'unknown' => ['label' => 'Veri yok', 'row' => 'result-row-unknown', 'dot' => 'bg-[var(--line-strong)]', 'text' => 'text-[var(--muted)]'],
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
    $modelRunCount = $run->modelRuns->count();
    $hasUsage = $run->modelRuns->contains(fn ($modelRun) => $usageOf($modelRun) !== null);
    $scoredCount = $run->modelRuns->filter(fn ($modelRun) => $modelRun->evaluation !== null)->count();
    $displayNameCounts = $run->modelRuns->countBy(fn ($modelRun) => (string) str($modelRun->model)->after('/'));
    $stagePassCounts = collect($run->stage_names)->mapWithKeys(fn ($stage) => [$stage => $run->modelRuns
        ->filter(fn ($modelRun) => $modelRun->stageResults->firstWhere('stage', $stage)?->status === 'pass')
        ->count()]);
    $resultColumnCount = count($run->stage_names) + ($hasUsage ? 6 : 5);
@endphp

<div class="content-frame run-page" @if ($activeAttemptCount > 0) data-attempt-refresh @endif>
    @if (session('status'))
        <div class="mb-4 rounded-xl border border-[var(--success-line)] bg-[var(--success-soft)] px-4 py-3 text-sm font-semibold text-[var(--success)]" role="status">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->has('storage') || $errors->has('confirmation') || $errors->has('continuation') || $errors->has('acknowledge_cost') || $errors->has('mode'))
        <div class="mb-4 rounded-xl border border-[var(--red-line)] bg-[var(--red-soft)] px-4 py-3 text-sm font-semibold text-[var(--red)]" role="alert">
            {{ $errors->first('storage') ?: $errors->first('confirmation') ?: $errors->first('continuation') ?: $errors->first('acknowledge_cost') ?: $errors->first('mode') }}
        </div>
    @endif

    <nav class="mb-3 flex items-center gap-2 text-[13px] font-semibold text-[var(--muted)]" aria-label="Sayfa yolu">
        <a class="transition-colors hover:text-[var(--forest)]" href="{{ route('dashboard') }}">Genel Bakış</a>
        <span aria-hidden="true">/</span>
        <span class="text-[var(--ink)]" aria-current="page">{{ $run->name }}</span>
    </nav>

    {{-- Header: identity, verdict and the key numbers on one band --}}
    <header class="flex flex-wrap items-center gap-x-6 gap-y-3">
        <div class="min-w-0 flex-[1_1_20rem]">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                <h1 class="text-2xl leading-8 font-black tracking-[-0.04em] text-[var(--ink)] @3xl:text-[28px] @3xl:leading-9">{{ $run->name }}</h1>
                <span class="status-badge status-{{ $runStatus }}"><span class="status-dot"></span>{{ $statusLabels[$runStatus] }}</span>
                <span class="schema-badge {{ in_array($run->schema, ['legacy', 'incomplete'], true) ? 'schema-badge-legacy' : 'schema-badge-extended' }}">{{ $schemaLabel }}</span>
            </div>
            <p class="mt-0.5 text-[13px] leading-5 text-[var(--muted)]">
                {{ $run->started_at?->locale('tr')->translatedFormat('j F Y · H:i') ?? 'Tarih kaydı yok' }} · {{ \App\Models\BenchmarkRun::ENVIRONMENT_PROFILES[$run->environmentProfile()] }} · {{ count($run->stage_names) }} doğrulama aşaması
            </p>
        </div>

        <dl class="run-stats order-last grid w-full grid-cols-3 @xl:grid-cols-6 @7xl:order-none @7xl:w-auto @7xl:grid-cols-[repeat(6,auto)]">
            @foreach ([
                ['Modeller', $run->model_count, 'Koşudaki model sayısı', ''],
                ['Başarılı', $run->passed_count, 'Tüm aşamalar geçti', 'text-[var(--success-ink)]'],
                ['Sorunlu', $run->failed_count, 'En az bir aşama başarısız', $run->failed_count > 0 ? 'text-[var(--red)]' : ''],
                ['Puanlanamaz', $unscoredCount, 'Sağlayıcı veya hesap hatası', $unscoredCount > 0 ? 'text-[var(--amber-ink)]' : ''],
                ['Toplam süre', $formatDuration($run->total_duration_seconds), 'Modellerin toplamı', ''],
                ['Toplam maliyet', $hasUsage ? '$'.number_format($totalCost, 4, ',', '.') : '—', 'OpenCode kaydı', ''],
            ] as [$label, $value, $hint, $color])
                <div title="{{ $hint }}">
                    <dt>{{ $label }}</dt>
                    <dd class="{{ $color }}">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>

        @if ($viewableModelCount > 0)
            <a class="inline-flex min-h-10 shrink-0 items-center gap-2 rounded-xl bg-[var(--forest)] px-4 py-2 text-sm font-extrabold text-white shadow-[0_8px_20px_rgb(79_70_229/0.2)] transition hover:bg-[#4338ca]" href="{{ route('runs.compare', $run) }}">
                <span aria-hidden="true">◫</span>
                {{ $viewableModelCount > 1 ? 'Siteleri karşılaştır' : 'Siteyi görüntüle' }}
            </a>
        @endif
    </header>

    {{-- Results: one scoreboard row per model, stage names only in the header --}}
    <section class="results-panel mt-5 overflow-clip rounded-2xl border border-[var(--line)] bg-[var(--surface)] shadow-[0_12px_38px_rgb(26_32_65/0.05)]" aria-labelledby="results-title">
        <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1 border-b border-[var(--line)] px-4 py-2.5">
            <h2 id="results-title" class="text-base font-black tracking-[-0.02em]">Model sonuçları <span class="font-bold text-[var(--muted)]">· {{ $modelRunCount }}</span></h2>
            <p class="text-xs text-[var(--muted)]">Aşama işaretleri ilk teslimi gösterir ve logu açar. Maliyet OpenCode kaydıdır; OpenRouter faturası değildir.</p>
            @if ($modelRunCount > 1)
                <div class="result-sort-bar" role="group" aria-label="Sonuçları sırala">
                    <span>Sırala:</span>
                    <button class="sort-button" type="button" data-sort-key="duration">Süre<span class="sort-indicator" aria-hidden="true"></span></button>
                    @if ($hasUsage)
                        <button class="sort-button" type="button" data-sort-key="cost">Maliyet<span class="sort-indicator" aria-hidden="true"></span></button>
                    @endif
                </div>
            @endif
        </div>

        @if ($run->schema === 'legacy')
            <p class="run-note"><strong>Legacy ölçüm kapsamı sınırlı.</strong> Bu sonucu genişletilmiş suite’lerin runtime güvencesiyle eşdeğer kabul etmeyin.</p>
        @elseif ($run->schema === 'incomplete')
            <p class="run-note"><strong>Bu koşu tamamlanmamış.</strong> Sonuç özeti oluşmamış; bulunan çalışma alanları ve yardımcı çıktılar depolama temizliği için gösteriliyor.</p>
        @endif

        <div data-live-region="results">
            <table class="results-table" role="table" aria-labelledby="results-title">
                <thead role="rowgroup">
                    <tr role="row">
                        <th class="result-model" scope="col" role="columnheader">Model</th>
                        @foreach ($run->stage_names as $stage)
                            <th class="result-stage" scope="col" role="columnheader">{{ $stageLabels[$stage] ?? $stage }}</th>
                        @endforeach
                        <th class="result-outcome" scope="col" role="columnheader">Sonuç</th>
                        <th class="result-numbers" scope="col" role="columnheader" aria-sort="none" data-sort-column="duration">
                            <button class="sort-button" type="button" data-sort-key="duration" title="Süreye göre sırala">Süre<span class="sort-indicator" aria-hidden="true"></span></button>
                        </th>
                        @if ($hasUsage)
                            <th class="result-numbers" scope="col" role="columnheader" aria-sort="none" data-sort-column="cost">
                                <button class="sort-button" type="button" data-sort-key="cost" title="Maliyete göre sırala">Maliyet<span class="sort-indicator" aria-hidden="true"></span></button>
                            </th>
                        @endif
                        <th class="result-score" scope="col" role="columnheader">Puan</th>
                        <th class="result-actions" scope="col" role="columnheader"><span class="sr-only">Eylemler</span></th>
                    </tr>
                </thead>
                <tbody role="rowgroup" data-sortable-rows>
                    @forelse ($run->modelRuns as $modelRun)
                        @php
                            $outcome = $outcomes[$modelRun->id] ?? ['tone' => 'unknown', 'title' => 'Sonuç okunamadı', 'detail' => null, 'autoContinued' => false];
                            $tone = $toneStyles[$outcome['tone']] ?? $toneStyles['unknown'];
                            $stageResults = $modelRun->stageResults->keyBy('stage');
                            $usage = $usageOf($modelRun);
                            $attemptCount = $modelRun->modelRunAttempts->count();
                            $modelName = (string) str($modelRun->model)->after('/');
                            $isPreviewable = $previewableModelIds->contains($modelRun->id);
                            $isRecoverable = $recoverableModels->contains('id', $modelRun->id);
                            $isRecovering = $modelRun->modelRunAttempts->contains(fn ($attempt) => in_array($attempt->status, $activeAttemptStatuses, true));
                            $evaluationNotes = trim((string) ($modelRun->evaluation?->notes ?? ''));
                        @endphp
                        <tr class="result-row {{ $tone['row'] }}" role="row" data-sort-index="{{ $loop->index }}" data-sort-duration="{{ $modelRun->duration_seconds }}" data-sort-cost="{{ $usage ? (float) ($usage['costUsd'] ?? 0) : '' }}">
                            <th class="result-model" scope="row" role="rowheader">
                                <span class="block text-sm font-black text-[var(--ink)] [overflow-wrap:anywhere]" title="{{ $modelRun->model }} · {{ $modelRun->slug }}">
                                    {{ $modelName }}
                                    @if (($displayNameCounts[$modelName] ?? 0) > 1)
                                        <code class="ml-1 text-[11px] font-normal text-[var(--muted)]">{{ $modelRun->slug }}</code>
                                    @endif
                                </span>
                                @if ($outcome['autoContinued'])
                                    <span class="mt-0.5 block text-[11px] leading-4 font-bold text-[var(--violet)]">Otomatik devam edildi</span>
                                @endif
                                <span class="result-numbers-inline mt-0.5 text-[11px] text-[var(--muted)] tabular-nums">{{ $formatDuration($modelRun->duration_seconds) }}@if ($usage) · {{ $money($usage) }}@endif</span>
                            </th>

                            @foreach ($run->stage_names as $stage)
                                @php
                                    $status = $stageResults->get($stage)?->status ?? 'unknown';
                                    $artifact = $artifactsByKey->get('model:'.$modelRun->slug.':log:'.$stage);
                                    $mark = match ($status) { 'pass' => '✓', 'fail' => '×', 'skip' => '—', default => '?' };
                                    $stageTitle = ($stageLabels[$stage] ?? $stage).': '.($statusLabels[$status] ?? 'Veri yok');
                                @endphp
                                <td class="result-stage" role="cell">
                                    @if ($artifact?->kind === 'file')
                                        <a class="stage-mark stage-mark-{{ $status }}" href="{{ route('runs.artifacts.show', [$run, $artifact]) }}" title="{{ $stageTitle }} · logu aç" aria-label="{{ $stageTitle }}, {{ $stageLabels[$stage] ?? $stage }} logu">{{ $mark }}</a>
                                    @else
                                        <span class="stage-mark stage-mark-{{ $status }}" role="img" title="{{ $stageTitle }}" aria-label="{{ $stageTitle }}">{{ $mark }}</span>
                                    @endif
                                    <span class="result-stage-label" aria-hidden="true">{{ $stageLabels[$stage] ?? $stage }}</span>
                                </td>
                            @endforeach

                            <td class="result-outcome" role="cell">
                                <p class="text-[13px] leading-5 font-bold {{ $tone['text'] }}"><span class="sr-only">{{ $tone['label'] }}: </span>{{ $outcome['title'] }}</p>
                                @if ($outcome['detail'] || $evaluationNotes !== '' || $isRecoverable)
                                    <div class="mt-0.5 flex items-center gap-3">
                                        @if ($outcome['detail'])
                                            <details id="outcome-detail-{{ $modelRun->slug }}" class="result-outcome-more min-w-0 flex-1">
                                                <summary class="result-outcome-detail" title="Tamamını göster">{{ $outcome['detail'] }}</summary>
                                            </details>
                                        @elseif ($evaluationNotes !== '')
                                            <p class="min-w-0 flex-1 truncate text-xs text-[var(--muted)]" title="{{ $evaluationNotes }}">Not: {{ $evaluationNotes }}</p>
                                        @else
                                            <span class="flex-1"></span>
                                        @endif
                                        @if ($isRecoverable && $isRecovering)
                                            <a class="status-badge status-running shrink-0" href="#recovery-{{ $modelRun->slug }}" data-open-details="recovery-{{ $modelRun->slug }}"><span class="status-dot"></span>Kurtarılıyor</a>
                                        @elseif ($isRecoverable)
                                            <a class="btn-ghost btn-xs shrink-0" href="#recovery-{{ $modelRun->slug }}" data-open-details="recovery-{{ $modelRun->slug }}">
                                                Kurtar
                                                @if ($attemptCount > 0)
                                                    <span class="btn-count">{{ $attemptCount }}<span class="sr-only"> deneme</span></span>
                                                @endif
                                            </a>
                                        @endif
                                    </div>
                                @endif
                            </td>

                            <td class="result-numbers" role="cell">
                                <span class="text-[13px] font-bold text-[var(--ink)] tabular-nums">{{ $formatDuration($modelRun->duration_seconds) }}</span>
                            </td>
                            @if ($hasUsage)
                                <td class="result-numbers" role="cell">
                                    @if ($usage)
                                        <span class="font-mono text-xs font-semibold text-[var(--ink)]" title="{{ $tokens($usage) }}">{{ $money($usage) }}</span>
                                    @else
                                        <span class="text-xs text-[var(--muted)]">Kayıt yok</span>
                                    @endif
                                </td>
                            @endif

                            <td class="result-score" role="cell">
                                @if ($modelRun->evaluation)
                                    <a class="score-chip" href="{{ route('runs.models.preview', ['benchmarkRun' => $run, 'modelRun' => $modelRun, 'review' => 1]) }}" target="_blank" rel="noopener noreferrer" title="Değerlendirmeyi düzenle" aria-label="Puan {{ $modelRun->evaluation->overall_score }}/10, değerlendirmeyi düzenle"><strong>{{ $modelRun->evaluation->overall_score }}</strong>/10</a>
                                @elseif ($isPreviewable)
                                    <a class="btn-ghost btn-xs" href="{{ route('runs.models.preview', ['benchmarkRun' => $run, 'modelRun' => $modelRun, 'review' => 1]) }}" target="_blank" rel="noopener noreferrer">Puanla</a>
                                @else
                                    <span class="text-[var(--muted)]" aria-hidden="true">—</span><span class="sr-only">Puanlanamaz</span>
                                @endif
                            </td>

                            <td class="result-actions" role="cell">
                                <div class="flex items-center justify-end gap-1.5">
                                    @if ($isPreviewable)
                                        <a class="btn-soft btn-xs" href="{{ route('runs.compare', ['benchmarkRun' => $run, 'left' => $modelRun->id]) }}">Önizle</a>
                                        <a class="btn-ghost btn-xs btn-icon" href="{{ route('runs.models.preview', [$run, $modelRun]) }}" target="_blank" rel="noopener noreferrer" aria-label="{{ $modelName }} çıktısını yeni sekmede aç">↗</a>
                                    @elseif ($recoveredPreviewModelIds->contains($modelRun->id))
                                        <a class="btn-ghost btn-xs" href="{{ route('runs.models.preview', ['benchmarkRun' => $run, 'modelRun' => $modelRun, 'version' => 'recovered']) }}" target="_blank" rel="noopener noreferrer" title="İlk teslimde çıktı yok; kurtarılmış çıktı puanlanmaz">Kurtarılmış ↗</a>
                                    @else
                                        <span class="px-1 text-xs font-semibold text-[var(--muted)]">Çıktı yok</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr role="row">
                            <td class="result-empty text-center text-sm text-[var(--muted)]" role="cell" colspan="{{ $resultColumnCount }}">Bu koşu için okunabilir bir model sonucu bulunamadı.</td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($modelRunCount > 1)
                    <tfoot role="rowgroup">
                        <tr role="row">
                            <th class="result-model" scope="row" role="rowheader">Aşamayı geçen</th>
                            @foreach ($run->stage_names as $stage)
                                <td class="result-stage tabular-nums {{ $stagePassCounts[$stage] === $modelRunCount ? 'is-complete' : '' }}" role="cell">{{ $stagePassCounts[$stage] }}/{{ $modelRunCount }}<span class="sr-only"> geçti</span></td>
                            @endforeach
                            <td class="result-outcome" role="cell">{{ $run->passed_count }}/{{ $run->model_count }} model tüm aşamaları geçti</td>
                            <td class="result-numbers tabular-nums" role="cell">{{ $formatDuration($run->total_duration_seconds) }}</td>
                            @if ($hasUsage)
                                <td class="result-numbers font-mono" role="cell">{{ '$'.number_format($totalCost, 4, ',', '.') }}</td>
                            @endif
                            <td class="result-score tabular-nums" role="cell">{{ $scoredCount }}/{{ $previewableModelIds->count() }}<span class="sr-only"> puanlandı</span></td>
                            <td class="result-actions" role="cell"></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </section>

    {{-- Recovery: one bordered list, collapsed per model; dialogs live outside every <details> --}}
    @if ($recoverableModels->isNotEmpty())
        <section class="mt-8" aria-labelledby="diagnostics-title" data-live-region="recovery">
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h2 id="diagnostics-title" class="text-base font-black tracking-[-0.02em]">Kurtarma</h2>
                <span class="rounded-full border border-[var(--line)] bg-[var(--surface)] px-2.5 py-0.5 text-xs font-bold text-[var(--ink)]">Aktif kurtarma: {{ $activeAttemptCount }}/{{ $maxParallelAttempts }}</span>
                <span class="text-xs text-[var(--muted)]">İki farklı model aynı anda devam edebilir; aynı modelin denemeleri sırayla çalışır.</span>
            </div>
            <p class="mt-1 text-xs leading-5 text-[var(--muted)]">
                Kurtarma denemeleri ilk teslimin sonucunu ve puanını değiştirmez. Her deneme ayrı kaydedilir; başarılı bir denemenin çıktısı “kurtarılmış” görünümde incelenir ve puanlanmaz.
            </p>

            <div class="fold-list mt-3 overflow-clip rounded-2xl border border-[var(--line)] bg-[var(--surface)]">
                @foreach ($recoverableModels as $modelRun)
                    @php
                        $outcome = $outcomes[$modelRun->id] ?? ['tone' => 'unknown', 'title' => 'Sonuç okunamadı', 'detail' => null];
                        $tone = $toneStyles[$outcome['tone']] ?? $toneStyles['unknown'];
                        $availability = $continuationAvailability[$modelRun->id] ?? [];
                        $canContinue = (bool) ($availability['canContinue'] ?? false);
                        $canValidate = (bool) ($availability['canValidate'] ?? false);
                        $unavailableReason = $availability['reason'] ?? null;
                        $attempts = $modelRun->modelRunAttempts->sortBy('attempt_number');
                        $hasActive = $attempts->contains(fn ($attempt) => in_array($attempt->status, $activeAttemptStatuses, true));
                        $latest = $attempts->last();
                        $usage = $usageOf($modelRun);
                    @endphp
                    <details id="recovery-{{ $modelRun->slug }}" class="recovery-card group" @if ($hasActive) open @endif>
                        <summary class="flex cursor-pointer list-none items-center gap-3 px-4 py-2.5 marker:content-none">
                            <span class="size-2 shrink-0 rounded-full {{ $tone['dot'] }}" aria-hidden="true"></span>
                            <h3 class="min-w-0 flex-1 text-sm font-normal [overflow-wrap:anywhere] @2xl:truncate">
                                <strong class="font-black text-[var(--ink)]">{{ str($modelRun->model)->after('/') }}</strong>
                                <span class="text-[var(--muted)]"> · {{ $outcome['title'] }}</span>
                            </h3>
                            <span class="hidden shrink-0 rounded-full bg-[var(--surface-subtle)] px-2.5 py-0.5 text-xs font-bold text-[var(--muted)] sm:inline">{{ $attempts->count() + 1 }} kayıt</span>
                            @if ($latest)
                                <span class="status-badge shrink-0 {{ in_array($latest->status, $activeAttemptStatuses, true) ? 'status-running' : ($latest->status === 'completed' && ($latest->result['overallStatus'] ?? null) === 'pass' ? 'status-pass' : 'status-unknown') }}">Son: {{ $attemptStatusLabels[$latest->status] ?? $latest->status }}</span>
                            @endif
                            <span class="recovery-chevron shrink-0 text-[var(--muted)]" aria-hidden="true">⌄</span>
                        </summary>

                        <div class="grid gap-4 border-t border-[var(--line)] p-4 @4xl:grid-cols-[minmax(0,1fr)_18rem]">
                            <div class="min-w-0">
                                <h4 class="text-sm font-black text-[var(--ink)]">Deneme geçmişi</h4>
                                <ol class="timeline mt-3">
                                    <li class="timeline-item">
                                        <span class="timeline-dot timeline-dot-{{ $modelRun->status === 'pass' ? 'pass' : 'fail' }}" aria-hidden="true"></span>
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <p class="text-sm font-bold">İlk teslim <span class="font-normal text-[var(--muted)]">· kayıtlı ana sonuç</span></p>
                                            <span class="status-badge status-{{ $modelRun->status }}">{{ $statusLabels[$modelRun->status] ?? 'Veri yok' }}</span>
                                        </div>
                                        <p class="mt-1 text-sm text-[var(--muted)]">{{ $outcome['title'] }} · {{ $formatDuration($modelRun->duration_seconds) }} · Bu kayıt değiştirilmeyecek.</p>
                                        @if ($outcome['detail'])
                                            <p class="mt-1 font-mono text-xs leading-5 break-words text-[var(--muted)]">{{ $outcome['detail'] }}</p>
                                        @endif
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
                                                <details class="mt-2 min-w-0 rounded-lg bg-[#10182c] text-white" data-log-id="attempt-{{ $attempt->id }}" open>
                                                    <summary class="cursor-pointer px-3 py-2 text-xs font-bold text-[#8ce6dc]">Canlı çalışma günlüğü <span class="font-normal text-[#9fb0c9]">· son satırlar, kendiliğinden güncellenir</span></summary>
                                                    <pre class="max-h-72 overflow-auto border-t border-white/10 p-3 font-mono text-xs leading-5 whitespace-pre-wrap text-[#dfe7f5] [overflow-wrap:anywhere]" data-log-id="attempt-{{ $attempt->id }}">{{ $attemptLogTail }}</pre>
                                                </details>
                                            @endif
                                            @if (in_array($attempt->status, $activeAttemptStatuses, true))
                                                <form class="mt-3" method="POST" action="{{ route('runs.models.attempts.destroy', ['benchmarkRun' => $run, 'modelRun' => $modelRun, 'modelRunAttempt' => $attempt]) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="btn-danger" type="submit" data-focus-key="stop-{{ $attempt->id }}">Denemeyi durdur</button>
                                                </form>
                                            @endif
                                        </li>
                                    @endforeach
                                </ol>
                            </div>

                            <aside class="h-fit rounded-2xl border border-[var(--line)] bg-[var(--surface-subtle)] p-3" aria-label="{{ str($modelRun->model)->after('/') }} için kurtarma eylemleri">
                                <h4 class="text-sm font-black text-[var(--ink)]">Ne yapmak istersiniz?</h4>
                                @if (! $canContinue && ! $canValidate && $unavailableReason)
                                    <p class="mt-2 rounded-lg border border-[var(--amber-line)] bg-[var(--amber-soft)] px-3 py-2 text-xs leading-5 text-[var(--amber-ink)]">{{ $unavailableReason }}</p>
                                @endif

                                <div class="mt-2 grid gap-2">
                                    <div class="rounded-xl border border-[var(--violet-line)] bg-[var(--surface)] p-3">
                                        <p class="text-sm font-bold text-[var(--violet)]">Kaldığı yerden devam ettir</p>
                                        <p class="mt-1 text-xs leading-5 text-[var(--muted)]">Aynı çalışma alanı ve OpenCode oturumunun ayrılmış bir kopyasıyla model yeniden çağrılır. Token harcanır.</p>
                                        @if (! $canContinue && $canValidate)
                                            <p class="mt-1 text-xs font-semibold text-[var(--amber-ink)]">Bu model için devam ettirilebilir bir OpenCode oturumu bulunamadı.</p>
                                        @endif
                                        <button class="btn-primary mt-3 w-full" type="button" data-dialog-open="continue-{{ $modelRun->slug }}" @disabled(! $canContinue)>
                                            {{ $canContinue ? 'Devam denemesi başlat' : 'Devam kullanılamıyor' }}
                                        </button>
                                    </div>

                                    <form class="rounded-xl border border-[var(--success-line)] bg-[var(--surface)] p-3" method="POST" action="{{ route('runs.models.attempts.store', ['benchmarkRun' => $run, 'modelRun' => $modelRun]) }}">
                                        @csrf
                                        <input type="hidden" name="mode" value="validate">
                                        <p class="text-sm font-bold text-[var(--success)]">Değişiklik yapmadan yeniden doğrula</p>
                                        <p class="mt-1 text-xs leading-5 text-[var(--muted)]">Model çağrılmaz; lint, build, export, rota ve tarayıcı kontrolleri yeniden çalışır.</p>
                                        <button class="btn-secondary mt-3 w-full" type="submit" data-focus-key="validate-{{ $modelRun->slug }}" @disabled(! $canValidate)>
                                            {{ $canValidate ? 'Yalnız doğrulamayı çalıştır' : 'Doğrulama kullanılamıyor' }}
                                        </button>
                                    </form>
                                </div>
                            </aside>
                        </div>
                    </details>
                @endforeach
            </div>

            @foreach ($recoverableModels as $modelRun)
                <dialog id="continue-{{ $modelRun->slug }}" class="confirm-dialog" aria-labelledby="continue-{{ $modelRun->slug }}-title">
                    <form method="POST" action="{{ route('runs.models.attempts.store', ['benchmarkRun' => $run, 'modelRun' => $modelRun]) }}">
                        @csrf
                        <input type="hidden" name="mode" value="continue">
                        <p class="eyebrow">Kurtarma denemesi</p>
                        <h4 id="continue-{{ $modelRun->slug }}-title" class="mt-1 text-lg font-black">{{ str($modelRun->model)->after('/') }} kaldığı yerden devam etsin mi?</h4>
                        <p class="mt-2 text-sm leading-6 text-[var(--muted)]">Model, kendi hata özeti ve zorunlu rotalarla aynı oturumun ayrılmış bir kopyasında yeniden çağrılır. İlk teslimin sonucu ve puanı değişmez.</p>
                        <label class="mt-4 flex items-start gap-2.5 rounded-xl border border-[var(--violet-line)] bg-[var(--violet-soft)] p-3 text-sm font-semibold text-[var(--violet)]">
                            <input class="mt-0.5 rounded border-[var(--violet-line)]" type="checkbox" name="acknowledge_cost" value="1" required>
                            <span>Yeni token veya sağlayıcı kredisi harcanabileceğini anlıyorum.</span>
                        </label>
                        <div class="mt-5 flex flex-wrap justify-end gap-2">
                            <button class="btn-ghost" type="button" data-dialog-close>Vazgeç</button>
                            <button class="btn-primary" type="submit">Devam denemesi başlat</button>
                        </div>
                    </form>
                </dialog>
            @endforeach
        </section>
    @endif

    {{-- Technical details: boundary audit, environment and files, one list of folds --}}
    <section class="mt-8" aria-labelledby="details-title">
        <h2 id="details-title" class="text-base font-black tracking-[-0.02em]">Teknik ayrıntılar</h2>

        <div class="fold-list mt-3 overflow-clip rounded-2xl border border-[var(--line)] bg-[var(--surface)]">
            <details class="detail-fold" @if ($boundaryTotals['observed'] > 0) open @endif>
                <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-3 px-4 py-3 marker:content-none">
                    <span class="min-w-0">
                        <span id="workspace-boundary-title" class="text-sm font-black">Model çalışma sınırları</span>
                        <span class="hidden text-xs text-[var(--muted)] md:inline">· Modellerin kendi klasörü dışına erişim denetimi</span>
                    </span>
                    <span class="flex gap-2">
                        <span class="rounded-full bg-[var(--surface-subtle)] px-2.5 py-0.5 text-xs font-bold {{ $boundaryTotals['blocked'] > 0 ? 'text-[var(--amber-ink)]' : 'text-[var(--muted)]' }}">{{ $boundaryTotals['blocked'] }} engellenen erişim</span>
                        @if ($boundaryTotals['observed'] > 0)
                            <span class="rounded-full bg-[var(--red-soft)] px-2.5 py-0.5 text-xs font-bold text-[var(--red)]">Bağımsızlık uyarısı</span>
                        @endif
                    </span>
                </summary>
                <div class="border-t border-[var(--line)] p-4">
                    <p class="text-xs leading-5 text-[var(--muted)]">Denemeler yalnızca kendi proje alanlarında çalışacak şekilde yönlendirilir. Üç kapsam dışı erişim reddinden sonra yalnızca ilgili model çağrısı durdurulur. Bu araç politikası tam işletim sistemi izolasyonu değildir.</p>
                    <div class="mt-3 grid gap-2 @3xl:grid-cols-2 @6xl:grid-cols-3">
                        @foreach ($run->modelRuns as $modelRun)
                            @php
                                $ownReports = collect([$modelBoundaryReports[$modelRun->id] ?? []])->concat(
                                    $modelRun->modelRunAttempts->map(fn ($attempt) => $attemptBoundaryReports[$attempt->id] ?? [])
                                );
                                $blockedCount = $ownReports->sum('blockedCount');
                                $observedReadCount = $ownReports->sum('observedCrossModelReadCount');
                                $hasCurrentPolicy = $ownReports->contains(fn ($report) => ($report['policyVersion'] ?? null) === 'workspace-boundary-v1');
                            @endphp
                            <article class="rounded-xl border border-[var(--line)] bg-[var(--surface-subtle)] p-3">
                                <h3 class="text-sm font-black break-words">{{ str($modelRun->model)->after('/') }}</h3>
                                <p class="mt-0.5 text-xs text-[var(--muted)]">{{ $hasCurrentPolicy ? 'Yeni sınır politikası kaydı var' : 'Eski koşu · yeni sınır politikası kaydı yok' }}</p>
                                <p class="mt-1.5 text-sm font-bold {{ $blockedCount > 0 ? 'text-[var(--amber)]' : 'text-[var(--muted)]' }}">Engellenen erişim: {{ $blockedCount }}</p>
                                @if ($observedReadCount > 0)
                                    <p class="mt-2 rounded-lg border border-[var(--red-line)] bg-[var(--red-soft)] p-2 text-xs leading-5 font-bold text-[var(--red)]">Karşılaştırma uyarısı: {{ $observedReadCount }} araç çıktısında başka modele ait kayıt görüldü. Engellenmiş girişimden farklıdır; bu denemeyi bağımsız kabul etmeden inceleyin.</p>
                                @endif
                                @include('runs.boundary-report', ['report' => $modelBoundaryReports[$modelRun->id] ?? []])
                            </article>
                        @endforeach
                    </div>
                    <p class="mt-3 text-xs leading-5 text-[var(--muted)]">Sayılar ilk teslim ve kurtarma kayıtlarını kapsar. Eski loglar sınırlı bir bölümden incelenir; sıfır kayıt, hiç erişim olmadığının kanıtı değildir.</p>
                </div>
            </details>

            <details class="detail-fold">
                <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-3 px-4 py-3 marker:content-none">
                    <span class="min-w-0">
                        <span id="meta-title" class="text-sm font-black">Ortam ve rotalar</span>
                        <span class="hidden text-xs text-[var(--muted)] md:inline">· Araç sürümleri, prompt özeti ve doğrulanan rotalar</span>
                    </span>
                    <span class="rounded-full bg-[var(--surface-subtle)] px-2.5 py-0.5 text-xs font-bold text-[var(--muted)]">{{ count($run->routes ?? []) }} rota</span>
                </summary>
                <div class="grid gap-6 border-t border-[var(--line)] p-4 lg:grid-cols-[minmax(0,1fr)_280px]">
                    <dl class="divide-y divide-[var(--line)]">
                        <div class="flex items-start justify-between gap-4 py-2">
                            <dt class="text-sm font-bold text-[var(--muted)]">Klasör</dt>
                            <dd class="max-w-[65%] text-right font-mono text-xs break-all text-[var(--ink)]">{{ $run->source_path }}</dd>
                        </div>
                        @foreach (($run->metadata ?? []) as $key => $value)
                            @continue(in_array($key, ['date', 'tarih', 'routes'], true))
                            <div class="flex items-start justify-between gap-4 py-2">
                                <dt class="text-sm font-bold text-[var(--muted)]">{{ $metaLabels[$key] ?? str($key)->replace('_', ' ')->title() }}</dt>
                                <dd class="max-w-[65%] text-right font-mono text-xs break-all text-[var(--ink)]">{{ $key === 'openrouter_routing' ? (['auto' => 'Otomatik', 'price' => 'Düşük fiyat öncelikli', 'throughput' => 'Yüksek hız öncelikli', 'fixed' => 'Sabit sağlayıcı; fallback kapalı'][$value] ?? $value) : $value }}</dd>
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

            <details class="detail-fold">
                <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-3 px-4 py-3 marker:content-none">
                    <span class="min-w-0">
                        <span id="artifacts-title" class="text-sm font-black">Artifact ve loglar</span>
                        <span class="hidden text-xs text-[var(--muted)] md:inline">· Koşu düzeyindeki dosyalar; model logları tablodaki aşama işaretlerinden açılır</span>
                    </span>
                    <span class="rounded-full bg-[var(--surface-subtle)] px-2.5 py-0.5 text-xs font-bold text-[var(--muted)]">{{ $run->artifacts->count() }} kayıtlı konum</span>
                </summary>
                <ul class="divide-y divide-[var(--line)] border-t border-[var(--line)] px-4">
                    @forelse ($runArtifacts as $artifact)
                        <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
                            <strong class="text-sm">{{ $artifact->label }}</strong>
                            <code class="min-w-0 flex-1 truncate text-[11px] text-[var(--muted)]" title="{{ $artifact->path }}">{{ $artifact->relative_path }}</code>
                            <span class="text-xs text-[var(--muted)]">{{ $artifact->kind === 'directory' ? 'Klasör' : $formatFileSize($artifact->size_bytes) }}</span>
                            @if ($artifact->kind === 'file')
                                <a class="text-sm font-extrabold text-[var(--forest)]" href="{{ route('runs.artifacts.show', [$run, $artifact]) }}">İçeriği görüntüle →</a>
                            @endif
                        </li>
                    @empty
                        <li class="py-3 text-sm text-[var(--muted)]">Koşu düzeyinde kayıtlı dosya yok.</li>
                    @endforelse
                </ul>
            </details>
        </div>
    </section>

    {{-- Storage and destructive actions live at the very end, closed by default --}}
    <section class="mt-3 mb-4" aria-labelledby="storage-title">
        <details class="detail-fold rounded-2xl border border-[var(--red-line)] bg-[var(--surface)]">
            <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-3 px-4 py-3 marker:content-none">
                <span class="min-w-0">
                    <span class="eyebrow">Depolama kullanımı</span>
                    <span id="storage-title" class="ml-2 text-sm font-black">Depolama ve tehlikeli işlemler</span>
                </span>
                <span class="flex flex-1 flex-wrap justify-end gap-2 text-xs font-bold">
                    <span class="rounded-full bg-[var(--surface-subtle)] px-2.5 py-0.5 text-[var(--ink)]">Toplam {{ $formatFileSize($run->total_bytes) }}</span>
                    <span class="rounded-full bg-[var(--violet-soft)] px-2.5 py-0.5 text-[var(--violet)]">Çalışma alanları {{ $formatFileSize($run->workspace_bytes) }}</span>
                </span>
            </summary>
            <div class="border-t border-[var(--line)] p-4">
                <dl class="grid gap-px overflow-hidden rounded-xl border border-[var(--line)] bg-[var(--line)] sm:grid-cols-3">
                    <div class="bg-[var(--surface)] px-4 py-2.5">
                        <dt class="text-xs font-bold text-[var(--muted)]">Toplam alan</dt>
                        <dd class="mt-0.5 text-lg font-black">{{ $formatFileSize($run->total_bytes) }}</dd>
                        <dd class="font-mono text-[11px] text-[var(--muted)]">{{ $formatExactBytes($run->total_bytes) }}</dd>
                    </div>
                    <div class="bg-[var(--surface)] px-4 py-2.5">
                        <dt class="text-xs font-bold text-[var(--violet)]">Çalışma alanları</dt>
                        <dd class="mt-0.5 text-lg font-black">{{ $formatFileSize($run->workspace_bytes) }}</dd>
                        <dd class="font-mono text-[11px] text-[var(--muted)]">{{ $formatExactBytes($run->workspace_bytes) }} · {{ $run->workspace_count }} klasör</dd>
                    </div>
                    <div class="bg-[var(--surface)] px-4 py-2.5">
                        <dt class="text-xs font-bold text-[var(--success)]">Sonuç ve çıktılar</dt>
                        <dd class="mt-0.5 text-lg font-black">{{ $formatFileSize($run->result_bytes) }}</dd>
                        <dd class="font-mono text-[11px] text-[var(--muted)]">{{ $formatExactBytes($run->result_bytes) }}</dd>
                    </div>
                </dl>
                <p class="mt-2 text-xs leading-5 text-[var(--muted)]">Ölçüm: {{ $run->storage_scanned_at?->locale('tr')->translatedFormat('j M Y · H:i') ?? 'henüz ölçülmedi' }}. Değerler diskteki dosyaların mantıksal byte toplamıdır.</p>

                <div class="mt-4 grid gap-3 border-t border-[var(--line)] pt-4 xl:grid-cols-3">
                    <form class="rounded-xl border border-[var(--amber-line)] bg-[var(--amber-soft)] p-3" method="POST" action="{{ route('runs.workspace.destroy', $run) }}" data-destructive-form data-confirm-message="Model çalışma alanları (kaynak kod dahil) kalıcı olarak silinsin mi? Önizlemeler önce arşive kopyalanır; sonuçlar, loglar ve puanlar korunur. Bu koşuda kurtarma denemesi artık yapılamaz.">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="confirmation" value="">
                        <h3 class="text-sm font-black text-[var(--amber-ink)]">Yalnızca çalışma alanlarını sil</h3>
                        <p class="mt-1.5 text-xs leading-5 text-[var(--amber-ink)]">Modellerin kaynak kodunu da siler. Önizlemeler önce arşive kopyalanır; sonuç özeti, loglar ve puanlar korunur. Sonrasında bu koşuda kurtarma denemesi yapılamaz. Bağımlılıklar (node_modules) her koşudan sonra zaten otomatik silinir.</p>
                        <button class="mt-3 rounded-lg border border-[var(--amber-line)] bg-[var(--surface)] px-3.5 py-2 text-sm font-extrabold text-[var(--amber-ink)] transition hover:border-[var(--amber-ink)] disabled:cursor-not-allowed disabled:opacity-45" type="submit" @disabled($run->workspace_count === 0)>
                            {{ $run->workspace_count === 0 ? 'Çalışma alanı zaten yok' : 'Çalışma alanlarını sil · '.$formatFileSize($run->workspace_bytes) }}
                        </button>
                    </form>

                    <form class="rounded-xl border border-[var(--violet-line)] bg-[var(--violet-soft)] p-3" method="POST" action="{{ route('runs.results.destroy', $run) }}" data-destructive-form data-confirm-message="Sonuç özeti, loglar, önizlemeler ve puanlar kalıcı olarak silinsin mi? Model çalışma alanları korunacak.">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="confirmation" value="">
                        <h3 class="text-sm font-black text-[var(--violet)]">Yalnızca sonuç arşivini sil</h3>
                        <p class="mt-1.5 text-xs leading-5 text-[var(--violet)]">Sonuç özetini, logları, önizlemeleri ve puanları siler. Model proje klasörleri korunur.</p>
                        <button class="mt-3 rounded-lg border border-[var(--violet-line)] bg-[var(--surface)] px-3.5 py-2 text-sm font-extrabold text-[var(--violet)] transition hover:border-[var(--violet)] disabled:cursor-not-allowed disabled:opacity-45" type="submit" @disabled(($run->result_bytes ?? 0) === 0)>
                            {{ ($run->result_bytes ?? 0) === 0 ? 'Sonuç arşivi zaten yok' : 'Sonuç arşivini sil' }}
                        </button>
                    </form>

                    <form class="rounded-xl border border-[var(--red-line)] bg-[var(--red-soft)] p-3" method="POST" action="{{ route('runs.destroy', $run) }}" data-destructive-form data-confirm-message="Bu benchmark sonucu, tüm dosyaları ve değerlendirmeleri kalıcı olarak silinsin mi?">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="confirmation" value="">
                        <h3 class="text-sm font-black text-[var(--red)]">Benchmarkı tamamen sil</h3>
                        <p class="mt-1.5 text-xs leading-5 text-[var(--red)]">Çalışma alanlarıyla birlikte sonuçları, logları, önizlemeleri, puanları ve veritabanı kaydını kalıcı olarak siler.</p>
                        <button class="mt-3 rounded-lg bg-[var(--red)] px-3.5 py-2 text-sm font-extrabold text-white transition hover:brightness-90" type="submit">Benchmarkı tamamen sil</button>
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
