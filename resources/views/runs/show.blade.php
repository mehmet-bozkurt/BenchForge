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
@endphp

<div class="content-frame" @if ($activeAttemptCount > 0) data-attempt-refresh @endif>
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

    <nav class="mb-6 flex items-center gap-2 text-xs font-semibold text-[var(--muted)]" aria-label="Sayfa yolu">
        <a class="transition-colors hover:text-[var(--forest)]" href="{{ route('dashboard') }}">Genel Bakış</a>
        <span aria-hidden="true">/</span>
        <span class="text-[var(--ink)]" aria-current="page">{{ $run->name }}</span>
    </nav>

    <header class="detail-hero rounded-[28px] border border-[var(--line)] bg-white p-6 sm:p-8">
        <div class="flex flex-col gap-7 lg:flex-row lg:items-end lg:justify-between">
            <div class="max-w-3xl">
                <p class="eyebrow">Benchmark koşusu · {{ \App\Models\BenchmarkRun::ENVIRONMENT_PROFILES[$run->environmentProfile()] }}</p>
                <h1 class="mt-3 text-3xl font-black tracking-[-0.05em] text-[var(--ink)] sm:text-[44px]">{{ $run->name }}</h1>
                <p class="mt-3 max-w-2xl text-sm leading-6 text-[var(--muted)]">
                    @if ($run->failed_count > 0)
                        {{ $run->failed_count }} modelde doğrulama sorunu bulundu. Sonuçlar ilk teslim kayıtları değiştirilmeden gösteriliyor.
                    @elseif ($run->model_count === 0)
                        Bu koşu için okunabilir bir model sonucu bulunamadı.
                    @else
                        Tüm kayıtlı modeller, bu koşuda bulunan doğrulama aşamalarını geçti.
                    @endif
                </p>
                <code class="mt-4 block truncate rounded-lg bg-[var(--surface-subtle)] px-3 py-2 text-[11px] text-[var(--muted)]" title="{{ $run->source_path }}">{{ $run->source_path }}</code>
            </div>
            <div class="flex flex-wrap gap-2">
                @php
                    $viewableModelCount = $previewableModelIds->merge($recoveredPreviewModelIds)->unique()->count();
                @endphp
                @if ($viewableModelCount > 0)
                    <a class="inline-flex min-h-9 items-center gap-2 rounded-lg bg-[var(--forest)] px-4 py-2 text-xs font-extrabold text-white shadow-[0_10px_24px_rgb(79_70_229/0.18)] transition hover:bg-[#4338ca]" href="{{ route('runs.compare', $run) }}">
                        <span aria-hidden="true">◫</span>
                        {{ $viewableModelCount > 1 ? 'Siteleri karşılaştır' : 'Siteyi görüntüle' }}
                    </a>
                @endif
                <span class="status-badge status-{{ $runStatus }}">
                    <span class="status-dot"></span>
                    {{ $statusLabels[$runStatus] }}
                </span>
                <span class="schema-badge {{ in_array($run->schema, ['legacy', 'incomplete'], true) ? 'schema-badge-legacy' : 'schema-badge-extended' }}">
                    {{ $schemaLabel }}
                </span>
            </div>
        </div>
    </header>

    @if ($run->schema === 'legacy')
        <aside class="mt-4 flex flex-col gap-1 rounded-2xl border border-[#f1d7a5] bg-[var(--amber-soft)] px-5 py-4 text-sm text-[#8b5a16] sm:flex-row sm:gap-2">
            <strong>Legacy ölçüm kapsamı sınırlı.</strong>
            <span>Bu sonucu genişletilmiş suite’lerin runtime güvencesiyle eşdeğer kabul etmeyin.</span>
        </aside>
    @endif

    @if ($run->schema === 'incomplete')
        <aside class="mt-4 flex flex-col gap-1 rounded-2xl border border-[#f1d7a5] bg-[var(--amber-soft)] px-5 py-4 text-sm text-[#8b5a16] sm:flex-row sm:gap-2">
            <strong>Bu koşu tamamlanmamış.</strong>
            <span>Sonuç özeti oluşmamış; bulunan çalışma alanları ve yardımcı çıktılar depolama temizliği için gösteriliyor.</span>
        </aside>
    @endif

    <section class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Koşu özeti">
        <article class="metric-card metric-neutral">
            <p class="metric-label">Modeller</p>
            <p class="metric-value">{{ $run->model_count }}</p>
            <p class="metric-detail">{{ count($run->stage_names) }} doğrulama aşaması</p>
        </article>
        <article class="metric-card metric-positive">
            <p class="metric-label">Kayıtlı PASS</p>
            <p class="metric-value">{{ $run->passed_count }}</p>
            <p class="metric-detail">{{ $run->model_count }} model içinde</p>
        </article>
        <article class="metric-card {{ $run->failed_count > 0 ? 'metric-danger' : 'metric-positive' }}">
            <p class="metric-label">Sorunlu</p>
            <p class="metric-value">{{ $run->failed_count }}</p>
            <p class="metric-detail">Kesin başarısız aşama sonucu</p>
        </article>
        <article class="metric-card metric-neutral">
            <p class="metric-label">Toplam süre</p>
            <p class="metric-value text-[24px]">{{ $formatDuration($run->total_duration_seconds) }}</p>
            <p class="metric-detail">{{ $run->started_at?->locale('tr')->translatedFormat('j M Y · H:i') ?? 'Tarih kaydı yok' }}</p>
        </article>
    </section>

    <section class="data-panel mt-8 rounded-2xl border border-[var(--line)] bg-white p-5 sm:p-6" aria-labelledby="storage-title">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="eyebrow">Depolama kullanımı</p>
                <h2 id="storage-title" class="mt-1 text-xl font-black tracking-[-0.035em]">Koşu dosyaları</h2>
            </div>
            <p class="text-[10px] text-[var(--muted)]">{{ $run->storage_scanned_at?->locale('tr')->translatedFormat('j M Y · H:i') ?? 'Henüz ölçülmedi' }}</p>
        </div>

        <div class="mt-5 grid gap-3 md:grid-cols-3">
            <article class="rounded-xl border border-[var(--line)] bg-[var(--surface-subtle)] p-4">
                <p class="text-[9px] font-extrabold tracking-[0.08em] text-[var(--muted)] uppercase">Toplam alan</p>
                <strong class="mt-2 block text-2xl font-black">{{ $formatFileSize($run->total_bytes) }}</strong>
                <span class="mt-1 block font-mono text-[9px] text-[var(--muted)]">{{ $formatExactBytes($run->total_bytes) }}</span>
            </article>
            <article class="rounded-xl border border-[#d8d1f5] bg-[var(--violet-soft)] p-4">
                <p class="text-[9px] font-extrabold tracking-[0.08em] text-[var(--violet)] uppercase">Çalışma alanları</p>
                <strong class="mt-2 block text-2xl font-black">{{ $formatFileSize($run->workspace_bytes) }}</strong>
                <span class="mt-1 block font-mono text-[9px] text-[var(--muted)]">{{ $formatExactBytes($run->workspace_bytes) }} · {{ $run->workspace_count }} klasör</span>
            </article>
            <article class="rounded-xl border border-[#b9ead8] bg-[var(--success-soft)] p-4">
                <p class="text-[9px] font-extrabold tracking-[0.08em] text-[var(--success)] uppercase">Sonuç ve çıktılar</p>
                <strong class="mt-2 block text-2xl font-black">{{ $formatFileSize($run->result_bytes) }}</strong>
                <span class="mt-1 block font-mono text-[9px] text-[var(--muted)]">{{ $formatExactBytes($run->result_bytes) }}</span>
            </article>
        </div>

        <p class="mt-3 text-[10px] leading-5 text-[var(--muted)]">
            Değerler diskte duran dosyaların mantıksal byte toplamıdır. Geçmiş koşuların ağ üzerinden indirdiği veri miktarı kaydedilmediği için geriye dönük ölçülemez.
        </p>

        <div class="mt-5 grid gap-4 border-t border-[var(--line)] pt-5 xl:grid-cols-3">
            <form class="rounded-xl border border-[#f1d7a5] bg-[var(--amber-soft)] p-4" method="POST" action="{{ route('runs.workspace.destroy', $run) }}" data-destructive-form data-confirm-message="Model çalışma alanları kalıcı olarak silinsin mi? Sonuç, log ve önizleme arşivi korunacak.">
                @csrf
                @method('DELETE')
                <input type="hidden" name="confirmation" value="">
                <h3 class="text-sm font-black text-[#8b5a16]">Yalnızca çalışma alanlarını sil</h3>
                <p class="mt-2 text-[10px] leading-5 text-[#8b5a16]">Model proje klasörlerini ve içlerindeki node_modules/build dosyalarını siler. Sonuç özeti, loglar, ekran çıktıları ve puanlar korunur.</p>
                <p class="mt-3 text-[10px] font-semibold text-[#8b5a16]">{{ $formatFileSize($run->workspace_bytes) }} alan silinecek. Butona tıkladığınızda onayınız istenir.</p>
                <button class="mt-4 rounded-lg border border-[#d9b968] bg-white px-3.5 py-2 text-xs font-extrabold text-[#8b5a16] transition hover:border-[#8b5a16] disabled:cursor-not-allowed disabled:opacity-45" type="submit" @disabled($run->workspace_count === 0)>
                    {{ $run->workspace_count === 0 ? 'Çalışma alanı zaten yok' : 'Çalışma alanlarını sil' }}
                </button>
            </form>

            <form class="rounded-xl border border-[#d8d1f5] bg-[var(--violet-soft)] p-4" method="POST" action="{{ route('runs.results.destroy', $run) }}" data-destructive-form data-confirm-message="Sonuç özeti, loglar, önizlemeler ve puanlar kalıcı olarak silinsin mi? Model çalışma alanları korunacak.">
                @csrf
                @method('DELETE')
                <input type="hidden" name="confirmation" value="">
                <h3 class="text-sm font-black text-[var(--violet)]">Yalnızca sonuç arşivini sil</h3>
                <p class="mt-2 text-[10px] leading-5 text-[var(--violet)]">Sonuç özetini, logları, runtime/gallery önizlemelerini ve puanları siler. Model proje klasörleri korunur.</p>
                <p class="mt-3 text-[10px] font-semibold text-[var(--violet)]">{{ $formatFileSize($run->result_bytes) }} alan silinecek. Butona tıkladığınızda onayınız istenir.</p>
                <button class="mt-4 rounded-lg border border-[#c8baf8] bg-white px-3.5 py-2 text-xs font-extrabold text-[var(--violet)] transition hover:border-[var(--violet)] disabled:cursor-not-allowed disabled:opacity-45" type="submit" @disabled(($run->result_bytes ?? 0) === 0)>
                    {{ ($run->result_bytes ?? 0) === 0 ? 'Sonuç arşivi zaten yok' : 'Sonuç arşivini sil' }}
                </button>
            </form>

            <form class="rounded-xl border border-[#f2c9cf] bg-[var(--red-soft)] p-4" method="POST" action="{{ route('runs.destroy', $run) }}" data-destructive-form data-confirm-message="Bu benchmark sonucu, tüm dosyaları ve değerlendirmeleri kalıcı olarak silinsin mi?">
                @csrf
                @method('DELETE')
                <input type="hidden" name="confirmation" value="">
                <h3 class="text-sm font-black text-[var(--red)]">Benchmarkı tamamen sil</h3>
                <p class="mt-2 text-[10px] leading-5 text-[var(--red)]">Çalışma alanlarıyla birlikte sonuçları, logları, önizlemeleri, puanları ve veritabanı kaydını kalıcı olarak siler.</p>
                <p class="mt-3 text-[10px] font-semibold text-[var(--red)]">Butona tıkladığınızda kalıcı silme işlemini onaylamanız istenir.</p>
                <button class="mt-4 rounded-lg bg-[var(--red)] px-3.5 py-2 text-xs font-extrabold text-white transition hover:brightness-90" type="submit">Benchmarkı tamamen sil</button>
            </form>
        </div>
        <noscript>
            <p class="mt-3 text-xs text-[var(--red)]">Silme işlemlerini onaylayabilmek için JavaScript etkin olmalıdır.</p>
        </noscript>
    </section>

    <section class="data-panel mt-8 overflow-hidden rounded-2xl border border-[var(--line)] bg-white" aria-labelledby="stage-matrix-title">
        <div class="flex flex-col gap-2 border-b border-[var(--line)] px-5 py-5 sm:flex-row sm:items-end sm:justify-between sm:px-6">
            <div>
                <p class="eyebrow">Teknik sonuçlar</p>
                <h2 id="stage-matrix-title" class="mt-1 text-xl font-black tracking-[-0.035em]">Model × aşama matrisi</h2>
            </div>
            <p class="text-xs text-[var(--muted)]">Her hücre ilk teslim sonucunu gösterir. Maliyet OpenCode kaydıdır; OpenRouter faturası değildir.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[980px] border-collapse text-left">
                <thead>
                    <tr class="border-b border-[var(--line)] bg-[var(--surface-subtle)]">
                        <th class="table-heading sticky left-0 z-10 min-w-52 bg-[var(--surface-subtle)] px-6 py-3" scope="col">Model</th>
                        <th class="table-heading px-3 py-3 text-center" scope="col">Genel</th>
                        <th class="table-heading px-3 py-3 text-center" scope="col">Görsel</th>
                        <th class="table-heading px-3 py-3 text-center" scope="col">Manuel</th>
                        @foreach ($run->stage_names as $stage)
                            <th class="table-heading px-3 py-3 text-center" scope="col">{{ $stageLabels[$stage] ?? $stage }}</th>
                        @endforeach
                        <th class="table-heading px-3 py-3 text-right" scope="col">Kullanım</th>
                        <th class="table-heading px-6 py-3 text-right" scope="col">Süre</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($run->modelRuns as $modelRun)
                        @php
                            $stageResults = $modelRun->stageResults->keyBy('stage');
                        @endphp
                        <tr class="border-b border-[var(--line)] last:border-b-0 hover:bg-[var(--surface-subtle)]">
                            <th class="sticky left-0 z-10 bg-white px-6 py-4" scope="row">
                                <span class="block text-xs font-extrabold text-[var(--ink)]">{{ str($modelRun->model)->after('/') }}</span>
                                <code class="mt-1 block text-[10px] font-medium text-[var(--muted)]">{{ $modelRun->slug }}</code>
                            </th>
                            <td class="px-3 py-4 text-center">
                                <span class="status-badge status-{{ $modelRun->status }}">{{ $statusLabels[$modelRun->status] ?? 'Veri yok' }}</span>
                            </td>
                            <td class="px-3 py-4 text-center">
                                @if ($previewableModelIds->contains($modelRun->id))
                                    <div class="flex items-center justify-center gap-2">
                                        <a class="inline-flex rounded-lg border border-[#d7ccff] bg-[var(--violet-soft)] px-3 py-2 text-[10px] font-extrabold text-[var(--violet)] transition hover:border-[var(--violet)]" href="{{ route('runs.compare', ['benchmarkRun' => $run, 'left' => $modelRun->id]) }}">Önizle →</a>
                                        <a class="inline-flex rounded-lg border border-[var(--line)] bg-white px-3 py-2 text-[10px] font-extrabold text-[var(--forest)] transition hover:border-[var(--forest)]" href="{{ route('runs.models.preview', [$run, $modelRun]) }}" target="_blank" rel="noopener noreferrer" aria-label="{{ str($modelRun->model)->after('/') }} çıktısını yeni sekmede aç">↗</a>
                                    </div>
                                @elseif ($recoveredPreviewModelIds->contains($modelRun->id))
                                    <a class="inline-flex rounded-lg border border-[var(--line)] bg-white px-3 py-2 text-[10px] font-extrabold text-[var(--forest)] transition hover:border-[var(--forest)]" href="{{ route('runs.models.preview', ['benchmarkRun' => $run, 'modelRun' => $modelRun, 'version' => 'recovered']) }}" target="_blank" rel="noopener noreferrer" title="İlk teslimde çıktı yok; kurtarılmış çıktı puanlanmaz">Kurtarılmış ↗</a>
                                @else
                                    <span class="text-[10px] font-bold text-[var(--muted)]">Çıktı yok</span>
                                @endif
                            </td>
                            <td class="px-3 py-4 text-center">
                                @if ($modelRun->evaluation)
                                    <a class="inline-flex items-center gap-1 rounded-lg border border-[#d7ccff] bg-[var(--violet-soft)] px-3 py-2 text-[10px] font-extrabold text-[var(--violet)] transition hover:border-[var(--violet)]" href="{{ route('runs.models.preview', ['benchmarkRun' => $run, 'modelRun' => $modelRun, 'review' => 1]) }}" target="_blank" rel="noopener noreferrer">
                                        <strong class="text-sm">{{ $modelRun->evaluation->overall_score }}</strong>/10 · Düzenle
                                    </a>
                                @elseif ($previewableModelIds->contains($modelRun->id))
                                    <a class="inline-flex rounded-lg border border-[var(--line)] bg-white px-3 py-2 text-[10px] font-extrabold text-[var(--forest)] transition hover:border-[var(--forest)]" href="{{ route('runs.models.preview', ['benchmarkRun' => $run, 'modelRun' => $modelRun, 'review' => 1]) }}" target="_blank" rel="noopener noreferrer">Puanla →</a>
                                @else
                                    <span class="text-[10px] font-bold text-[var(--muted)]">—</span>
                                @endif
                            </td>
                            @foreach ($run->stage_names as $stage)
                                @php
                                    $stageResult = $stageResults->get($stage);
                                    $status = $stageResult?->status ?? 'unknown';
                                    $artifact = $artifactsByKey->get('model:'.$modelRun->slug.':log:'.$stage);
                                @endphp
                                <td class="px-3 py-4 text-center">
                                    @if ($artifact?->kind === 'file')
                                        <a class="inline-flex rounded-lg outline-none ring-[var(--forest)] focus-visible:ring-2" href="{{ route('runs.artifacts.show', [$run, $artifact]) }}" title="{{ $stageLabels[$stage] ?? $stage }} logunu aç">
                                            <span class="stage-mark stage-mark-{{ $status }}">{{ match ($status) { 'pass' => '✓', 'fail' => '×', 'skip' => '—', default => '?' } }}</span>
                                        </a>
                                    @else
                                        <span class="stage-mark stage-mark-{{ $status }}">{{ match ($status) { 'pass' => '✓', 'fail' => '×', 'skip' => '—', default => '?' } }}</span>
                                    @endif
                                </td>
                            @endforeach
                            <td class="px-3 py-4 text-right whitespace-nowrap">
                                @if (is_array($modelRun->usage) && ($modelRun->usage['source'] ?? null) === 'opencode_session')
                                    <span class="block font-mono text-[11px] font-bold text-[var(--ink)]">${{ number_format((float) ($modelRun->usage['costUsd'] ?? 0), 6, ',', '.') }}</span>
                                    <span class="block text-[9px] text-[var(--muted)]">G {{ number_format((int) ($modelRun->usage['inputTokens'] ?? 0), 0, ',', '.') }} · Ç {{ number_format((int) ($modelRun->usage['outputTokens'] ?? 0), 0, ',', '.') }}</span>
                                @else
                                    <span class="text-[10px] text-[var(--muted)]">Kayıt yok</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right font-mono text-[11px] font-bold whitespace-nowrap">{{ $formatDuration($modelRun->duration_seconds) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="data-panel mt-8 rounded-2xl border border-[var(--line)] bg-white p-5 sm:p-6" aria-labelledby="workspace-boundary-title">
        <p class="eyebrow">Bağımsız çalışma</p>
        <h2 id="workspace-boundary-title" class="mt-1 text-xl font-black tracking-[-0.035em]">Model çalışma sınırları</h2>
        <p class="mt-2 text-xs leading-5 text-[var(--muted)]">Yeni denemeler yalnızca kendi proje alanlarında çalışacak şekilde yönlendirilir. Kendi hata özeti devam mesajına eklenir. Üç kapsam dışı erişim reddinden sonra yalnızca ilgili model çağrısı durdurulur; doğrulama sonuçları ayrı tutulur. Bu araç politikası tam işletim sistemi izolasyonu değildir.</p>
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
                    <h3 class="break-words text-xs font-black">{{ str($modelRun->model)->after('/') }}</h3>
                    <p class="mt-2 text-[10px] text-[var(--muted)]">{{ $hasCurrentPolicy ? 'Yeni sınır politikası kaydı var' : 'Eski koşu · yeni sınır politikası kaydı yok' }}</p>
                    <p class="mt-2 text-[11px] font-bold {{ $blockedCount > 0 ? 'text-[var(--amber)]' : 'text-[var(--muted)]' }}">Engellenen erişim: {{ $blockedCount }}</p>
                    @if ($observedReadCount > 0)
                        <p class="mt-2 rounded-lg border border-[#f2c9cf] bg-[var(--red-soft)] p-2 text-[10px] font-bold leading-4 text-[var(--red)]">Karşılaştırma uyarısı: {{ $observedReadCount }} araç çıktısında başka modele ait kayıt görüldü. Engellenmiş girişimden farklıdır; bu denemeyi bağımsız kabul etmeden inceleyin.</p>
                    @endif
                    @include('runs.boundary-report', ['report' => $modelBoundaryReports[$modelRun->id] ?? []])
                </article>
            @endforeach
        </div>
        <p class="mt-3 text-[10px] leading-4 text-[var(--muted)]">Sayılar ilk teslim ve kurtarma kayıtlarını kapsar. Eski loglar sınırlı bir bölümden incelenir; sıfır kayıt, hiç erişim olmadığının kanıtı değildir. İlk teslim puanları ve sonuçları değiştirilmez.</p>
    </section>

    <div class="mt-8 grid gap-5 xl:grid-cols-[minmax(0,1.25fr)_minmax(320px,0.75fr)]">
        <section class="data-panel rounded-2xl border border-[var(--line)] bg-white p-5 sm:p-6" aria-labelledby="diagnostics-title">
            <p class="eyebrow">Teşhis yüzeyi</p>
            <h2 id="diagnostics-title" class="mt-1 text-xl font-black tracking-[-0.035em]">Hata sinyalleri</h2>
            <div class="mt-3 flex flex-wrap items-center gap-2 text-[11px] font-semibold text-[var(--muted)]">
                <span class="rounded-full border border-[var(--line)] bg-[var(--surface-subtle)] px-3 py-1">Aktif kurtarma: {{ $activeAttemptCount }}/{{ $maxParallelAttempts }}</span>
                <span>İki farklı model aynı anda devam edebilir; aynı modelin denemeleri sırayla çalışır.</span>
            </div>

            @if ($failedModels->isEmpty())
                <div class="mt-5 rounded-xl border border-dashed border-[var(--line-strong)] bg-[var(--surface-subtle)] px-5 py-8 text-center">
                    <strong class="text-sm">Kayıtlı hata sinyali yok</strong>
                    <p class="mt-1 text-xs leading-5 text-[var(--muted)]">Özet sonuçlarında başarısız bir aşama bulunmuyor.</p>
                </div>
            @else
                <div class="mt-5 grid gap-3">
                    @foreach ($failedModels as $modelRun)
                        @php
                            $availability = $continuationAvailability[$modelRun->id] ?? [];
                            $canContinue = (bool) ($availability['canContinue'] ?? false);
                            $canValidate = (bool) ($availability['canValidate'] ?? false);
                            $unavailableReason = $availability['reason'] ?? null;
                            $attempts = $modelRun->modelRunAttempts->sortBy('attempt_number');
                        @endphp
                        <article class="rounded-xl border border-[#f2c9cf] bg-[var(--red-soft)] p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-sm font-black text-[var(--ink)]">{{ str($modelRun->model)->after('/') }}</h3>
                                    <code class="mt-1 block text-[10px] text-[var(--muted)]">{{ $modelRun->slug }}</code>
                                </div>
                                <span class="status-badge status-fail">Sorunlu</span>
                            </div>
                            <div class="mt-4 flex flex-wrap gap-2">
                                @foreach ($modelRun->stageResults->where('status', 'fail') as $stageResult)
                                    @php
                                        $artifact = $artifactsByKey->get('model:'.$modelRun->slug.':log:'.$stageResult->stage);
                                    @endphp
                                    @if ($artifact?->kind === 'file')
                                        <a class="rounded-lg border border-[#efbcc4] bg-white px-3 py-2 text-[10px] font-extrabold tracking-[0.06em] text-[var(--red)] uppercase hover:border-[var(--red)]" href="{{ route('runs.artifacts.show', [$run, $artifact]) }}">
                                            {{ $stageLabels[$stageResult->stage] ?? $stageResult->stage }} logu →
                                        </a>
                                    @else
                                        <span class="rounded-lg border border-[#efbcc4] px-3 py-2 text-[10px] font-extrabold text-[var(--red)] uppercase">{{ $stageLabels[$stageResult->stage] ?? $stageResult->stage }}</span>
                                    @endif
                                @endforeach
                            </div>

                            <div class="mt-4 rounded-xl border border-[#d8d1f5] bg-white p-4">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-[9px] font-extrabold tracking-[0.08em] text-[var(--violet)] uppercase">Kurtarma alanı</p>
                                        <h4 class="mt-1 text-sm font-black text-[var(--ink)]">Yarım kalan işi güvenli biçimde sürdür</h4>
                                    </div>
                                    <span class="self-start rounded-full border border-[#d8d1f5] bg-[var(--violet-soft)] px-2.5 py-1 text-[9px] font-extrabold text-[var(--violet)]">İlk sonuç korunur</span>
                                </div>

                                <p class="mt-3 text-[11px] leading-5 text-[var(--muted)]">
                                    İlk teslimin sonucu ve karşılaştırma puanı değişmez. Her yeni deneme ayrı kaydedilir; başarılı bir denemenin çıktısı ayrı “kurtarılmış” görünümde incelenir, puanlanmaz.
                                </p>

                                @if (! $canContinue && ! $canValidate && $unavailableReason)
                                    <div class="mt-4 rounded-lg border border-[#f1d7a5] bg-[var(--amber-soft)] px-3 py-2.5 text-[10px] font-semibold leading-5 text-[#8b5a16]">
                                        {{ $unavailableReason }}
                                    </div>
                                @endif

                                <div class="mt-4 grid gap-3 lg:grid-cols-2">
                                    <form class="rounded-xl border border-[#d8d1f5] bg-[var(--violet-soft)] p-3" method="POST" action="{{ route('runs.models.attempts.store', ['benchmarkRun' => $run, 'modelRun' => $modelRun]) }}">
                                        @csrf
                                        <input type="hidden" name="mode" value="continue">
                                        <strong class="text-xs font-black text-[var(--violet)]">Modeli kaldığı yerden devam ettir</strong>
                                        <p class="mt-1.5 text-[10px] leading-4 text-[var(--muted)]">Aynı çalışma alanı ve OpenCode oturumunun ayrılmış bir kopyası kullanılır.</p>
                                        <label class="mt-3 flex items-start gap-2 text-[10px] font-semibold leading-4 text-[var(--violet)]">
                                            <input class="mt-0.5 rounded border-[#c8baf8]" type="checkbox" name="acknowledge_cost" value="1" required @disabled(! $canContinue)>
                                            <span>Yeni token veya sağlayıcı kredisi harcanabileceğini anlıyorum.</span>
                                        </label>
                                        @if (! $canContinue && $canValidate)
                                            <p class="mt-2 text-[9px] font-semibold leading-4 text-[#8b5a16]">Bu model için devam ettirilebilir bir OpenCode oturumu bulunamadı.</p>
                                        @endif
                                        <button class="mt-3 inline-flex min-h-9 items-center justify-center rounded-lg bg-[var(--violet)] px-3.5 py-2 text-[10px] font-extrabold text-white transition hover:brightness-95 disabled:cursor-not-allowed disabled:opacity-45" type="submit" @disabled(! $canContinue)>
                                            {{ $canContinue ? 'Devam denemesi başlat' : 'Devam kullanılamıyor' }}
                                        </button>
                                    </form>

                                    <form class="rounded-xl border border-[#b9ead8] bg-[var(--success-soft)] p-3" method="POST" action="{{ route('runs.models.attempts.store', ['benchmarkRun' => $run, 'modelRun' => $modelRun]) }}">
                                        @csrf
                                        <input type="hidden" name="mode" value="validate">
                                        <strong class="text-xs font-black text-[var(--success)]">Değişiklik yapmadan yeniden doğrula</strong>
                                        <p class="mt-1.5 text-[10px] leading-4 text-[var(--muted)]">Model çağrılmaz; lint, build, export, rota ve runtime kontrolleri yeniden çalışır.</p>
                                        <button class="mt-3 inline-flex min-h-9 items-center justify-center rounded-lg border border-[#8dd9bd] bg-white px-3.5 py-2 text-[10px] font-extrabold text-[var(--success)] transition hover:border-[var(--success)] disabled:cursor-not-allowed disabled:opacity-45" type="submit" @disabled(! $canValidate)>
                                            {{ $canValidate ? 'Yalnız doğrulamayı çalıştır' : 'Doğrulama kullanılamıyor' }}
                                        </button>
                                    </form>
                                </div>

                                <details class="mt-4 overflow-hidden rounded-xl border border-[var(--line)] bg-[var(--surface-subtle)]" @if ($attempts->isNotEmpty()) open @endif>
                                    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-3.5 py-3 text-xs font-black text-[var(--ink)] marker:content-none">
                                        <span>Deneme geçmişi</span>
                                        <span class="rounded-full bg-white px-2 py-1 text-[9px] text-[var(--muted)]">{{ $attempts->count() + 1 }} kayıt</span>
                                    </summary>
                                    <ol class="grid gap-2 border-t border-[var(--line)] p-3">
                                        <li class="rounded-lg border border-[#efbcc4] bg-white p-3">
                                            <div class="flex flex-wrap items-center justify-between gap-2">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <strong class="text-[11px]">İlk teslim</strong>
                                                    <span class="text-[9px] font-bold text-[var(--muted)]">Kayıtlı ana sonuç</span>
                                                </div>
                                                <span class="status-badge status-fail">Sorunlu</span>
                                            </div>
                                            <p class="mt-2 text-[10px] text-[var(--muted)]">{{ $formatDuration($modelRun->duration_seconds) }} · Bu kayıt değiştirilmeyecek.</p>
                                            @if (is_array($modelRun->usage) && ($modelRun->usage['source'] ?? null) === 'opencode_session')
                                                <p class="mt-1 text-[10px] font-semibold text-[var(--ink)]">OpenCode kaydı: ${{ number_format((float) ($modelRun->usage['costUsd'] ?? 0), 6, ',', '.') }} · Girdi {{ number_format((int) ($modelRun->usage['inputTokens'] ?? 0), 0, ',', '.') }} · Çıktı {{ number_format((int) ($modelRun->usage['outputTokens'] ?? 0), 0, ',', '.') }}</p>
                                            @endif
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
                                            <li class="rounded-lg border border-[var(--line)] bg-white p-3">
                                                <div class="flex flex-wrap items-start justify-between gap-2">
                                                    <div>
                                                        <div class="flex flex-wrap items-center gap-2">
                                                            <strong class="text-[11px]">Deneme {{ $attempt->attempt_number }}</strong>
                                                            <span class="rounded-full bg-[var(--surface-subtle)] px-2 py-1 text-[9px] font-bold text-[var(--muted)]">{{ $attemptModeLabels[$attempt->mode] ?? $attempt->mode }}</span>
                                                        </div>
                                                        <p class="mt-1 text-[9px] text-[var(--muted)]">
                                                            {{ $attempt->started_at?->locale('tr')->translatedFormat('j M Y · H:i') ?? 'Başlangıç kaydı yok' }}
                                                            @if (is_numeric($attemptDuration))
                                                                · {{ $formatDuration((int) $attemptDuration) }}
                                                            @endif
                                                        </p>
                                                    </div>
                                                    <span class="status-badge status-{{ $attemptTone }}">{{ $attemptStatusLabels[$attempt->status] ?? str($attempt->status)->headline() }}</span>
                                                </div>

                                                @if ($attempt->status === 'completed')
                                                    <p class="mt-2 text-[10px] font-bold {{ $overallStatus === 'pass' ? 'text-[var(--success)]' : ($overallStatus === 'fail' ? 'text-[var(--red)]' : 'text-[var(--muted)]') }}">
                                                        {{ $overallStatus === 'pass' ? 'Tüm doğrulamalar başarılı.' : ($overallStatus === 'fail' ? 'Deneme tamamlandı, ancak bazı doğrulamalar başarısız.' : 'Deneme tamamlandı; kesin sonuç üretilemedi.') }}
                                                    </p>
                                                @endif

                                                @if (is_array($attempt->usage) && ($attempt->usage['source'] ?? null) === 'opencode_session')
                                                    <p class="mt-1 text-[10px] font-semibold text-[var(--ink)]">OpenCode kaydı: ${{ number_format((float) ($attempt->usage['costUsd'] ?? 0), 6, ',', '.') }} · Girdi {{ number_format((int) ($attempt->usage['inputTokens'] ?? 0), 0, ',', '.') }} · Çıktı {{ number_format((int) ($attempt->usage['outputTokens'] ?? 0), 0, ',', '.') }}</p>
                                                @elseif (($attempt->usage['source'] ?? null) === 'validation_only')
                                                    <p class="mt-1 text-[10px] text-[var(--muted)]">Yalnız doğrulama: yeni model tokeni veya model maliyeti yok.</p>
                                                @endif

                                                @if (is_array($failedStages) && $failedStages !== [])
                                                    <div class="mt-2 flex flex-wrap gap-1.5">
                                                        @foreach ($failedStages as $failedStage)
                                                            <span class="rounded-md border border-[#efbcc4] bg-[var(--red-soft)] px-2 py-1 text-[9px] font-extrabold text-[var(--red)]">{{ $stageLabels[$failedStage] ?? $failedStage }}</span>
                                                        @endforeach
                                                    </div>
                                                @endif

                                                @if ($attempt->message)
                                                    <p class="mt-2 rounded-md bg-[var(--surface-subtle)] px-2.5 py-2 text-[10px] leading-4 text-[var(--muted)]">{{ $attempt->message }}</p>
                                                @endif

                                                @include('runs.boundary-report', ['report' => $attemptBoundaryReports[$attempt->id] ?? []])

                                                @if ($attemptLogTail)
                                                    <details class="mt-2 rounded-lg bg-[#10182c] text-white">
                                                        <summary class="cursor-pointer px-3 py-2 text-[9px] font-extrabold tracking-[0.06em] text-[#8ce6dc] uppercase">Canlı çalışma günlüğü</summary>
                                                        <pre class="max-h-56 overflow-auto border-t border-white/10 p-3 font-mono text-[9px] leading-4 whitespace-pre-wrap text-[#dfe7f5]">{{ $attemptLogTail }}</pre>
                                                    </details>
                                                @endif

                                                @if (in_array($attempt->status, $activeAttemptStatuses, true))
                                                    <form class="mt-3" method="POST" action="{{ route('runs.models.attempts.destroy', ['benchmarkRun' => $run, 'modelRun' => $modelRun, 'modelRunAttempt' => $attempt]) }}">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button class="rounded-lg border border-[#efbcc4] bg-white px-3 py-2 text-[10px] font-extrabold text-[var(--red)] transition hover:border-[var(--red)]" type="submit">Denemeyi durdur</button>
                                                    </form>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ol>
                                </details>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="data-panel rounded-2xl border border-[var(--line)] bg-white p-5 sm:p-6" aria-labelledby="meta-title">
            <p class="eyebrow">Ortam kaydı</p>
            <h2 id="meta-title" class="mt-1 text-xl font-black tracking-[-0.035em]">Meta bilgiler</h2>
            <dl class="mt-5 divide-y divide-[var(--line)]">
                <div class="flex items-start justify-between gap-4 py-3">
                    <dt class="text-xs font-bold text-[var(--muted)]">Tarih</dt>
                    <dd class="text-right text-xs font-semibold">{{ $run->started_at?->locale('tr')->translatedFormat('j M Y · H:i') ?? 'Kayıt yok' }}</dd>
                </div>
                @foreach (($run->metadata ?? []) as $key => $value)
                    @continue(in_array($key, ['date', 'tarih', 'routes'], true))
                    <div class="flex items-start justify-between gap-4 py-3">
                        <dt class="text-xs font-bold text-[var(--muted)]">{{ $metaLabels[$key] ?? str($key)->replace('_', ' ')->title() }}</dt>
                        <dd class="max-w-[65%] break-all text-right font-mono text-[10px] text-[var(--ink)]">{{ $key === 'openrouter_routing' ? (['auto' => 'Otomatik', 'price' => 'Düşük fiyat öncelikli', 'throughput' => 'Yüksek hız öncelikli', 'fixed' => 'Sabit sağlayıcı; fallback kapalı'][$value] ?? $value) : $value }}</dd>
                    </div>
                @endforeach
            </dl>

            <div class="mt-6 border-t border-[var(--line)] pt-5">
                <h3 class="text-xs font-black tracking-[0.08em] text-[var(--muted)] uppercase">Zorunlu rotalar</h3>
                @if (($run->routes ?? []) === [])
                    <p class="mt-3 text-xs text-[var(--muted)]">Rota listesi kaydedilmemiş.</p>
                @else
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($run->routes as $route)
                            <code class="rounded-md bg-[var(--surface-subtle)] px-2 py-1 text-[10px] text-[var(--ink)]">{{ $route }}</code>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>
    </div>

    <section class="data-panel mt-8 rounded-2xl border border-[var(--line)] bg-white p-5 sm:p-6" aria-labelledby="artifacts-title">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="eyebrow">Yerel kayıtlar</p>
                <h2 id="artifacts-title" class="mt-1 text-xl font-black tracking-[-0.035em]">Artifact ve loglar</h2>
            </div>
            <p class="text-xs text-[var(--muted)]">{{ $run->artifacts->count() }} kayıtlı konum</p>
        </div>

        <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($runArtifacts as $artifact)
                <article class="artifact-card">
                    <div class="flex items-start justify-between gap-3">
                        <strong class="text-xs">{{ $artifact->label }}</strong>
                        <span class="text-[10px] text-[var(--muted)]">{{ $artifact->kind === 'directory' ? 'Klasör' : $formatFileSize($artifact->size_bytes) }}</span>
                    </div>
                    <code class="mt-3 block truncate text-[10px] text-[var(--muted)]" title="{{ $artifact->path }}">{{ $artifact->relative_path }}</code>
                    @if ($artifact->kind === 'file')
                        <a class="mt-4 inline-flex text-xs font-extrabold text-[var(--forest)]" href="{{ route('runs.artifacts.show', [$run, $artifact]) }}">İçeriği görüntüle →</a>
                    @endif
                </article>
            @endforeach
        </div>
    </section>
</div>
@endsection
