@extends('layouts.app')

@section('content')
@php
    /*
     * Labels, tones and formatters shared by the partials below. resources/js/workflows.js keeps the same maps and
     * produces the same text when it updates the page from the status snapshot; keep both in sync.
     */
    $statusLabels = [
        'pending' => 'Bekliyor', 'starting' => 'Başlatılıyor', 'running' => 'Çalışıyor', 'stopping' => 'Durduruluyor',
        'completed' => 'Tamamlandı', 'partial' => 'Kısmen tamamlandı', 'failed' => 'Başarısız', 'stopped' => 'Durduruldu',
        'skipped' => 'Atlandı', 'not_run' => 'Çalıştırılmadı', 'interrupted' => 'Yarıda kaldı',
    ];
    $statusTones = [
        'pending' => 'unknown', 'starting' => 'running', 'running' => 'running', 'stopping' => 'running',
        'completed' => 'pass', 'partial' => 'unknown', 'failed' => 'fail', 'stopped' => 'unknown', 'skipped' => 'skip',
        'not_run' => 'skip', 'interrupted' => 'unknown',
    ];
    $stepTypeLabels = [
        'llm_text' => 'Metin', 'llm_json' => 'JSON', 'foreach' => 'Döngü', 'code_text' => 'Kod (tek seferde)',
        'code_agent' => 'Kod (ajan)', 'browser_check' => 'Tarayıcı testi',
    ];
    $statusMarks = [
        'completed' => ['pass', '✓'], 'failed' => ['fail', '✕'], 'running' => ['running', '•'], 'stopped' => ['unknown', '■'],
        'partial' => ['unknown', '◐'], 'skipped' => ['skip', '–'], 'pending' => ['skip', '·'], 'not_run' => ['skip', '·'],
        'interrupted' => ['unknown', '!'],
    ];

    $statusLabel = static fn (mixed $status): string => is_string($status) ? ($statusLabels[$status] ?? 'Bilinmiyor') : 'Bilinmiyor';
    $statusTone = static fn (mixed $status): string => is_string($status) ? ($statusTones[$status] ?? 'unknown') : 'unknown';
    $stepTypeLabel = static fn (mixed $type): string => is_string($type) && $type !== '' ? ($stepTypeLabels[$type] ?? $type) : 'Adım';
    $statusMark = static fn (mixed $status): array => is_string($status) ? ($statusMarks[$status] ?? $statusMarks['pending']) : $statusMarks['pending'];
    $isKnownNumber = static fn (mixed $value): bool => is_numeric($value) && is_finite((float) $value);
    $formatNumber = static fn (mixed $value): string => $isKnownNumber($value) ? number_format(round((float) $value), 0, ',', '.') : 'bilinmiyor';
    $formatTokens = static fn (mixed $inputTokens, mixed $outputTokens): string => ! $isKnownNumber($inputTokens) && ! $isKnownNumber($outputTokens)
        ? 'bilinmiyor'
        : $formatNumber($inputTokens).' girdi / '.$formatNumber($outputTokens).' çıktı';
    $formatCost = static fn (mixed $value): string => $isKnownNumber($value) && (float) $value >= 0
        ? '$'.preg_replace('/(\.\d{4}\d*?)0+$/', '$1', number_format((float) $value, 6, '.', ','))
        : 'bilinmiyor';
    $formatDuration = static function (mixed $value) use ($isKnownNumber): string {
        if (! $isKnownNumber($value)) {
            return 'bilinmiyor';
        }

        $seconds = max(0, (float) $value);

        if ($seconds < 60) {
            return number_format($seconds, 1, ',', '.').' sn';
        }

        $totalSeconds = (int) round($seconds);
        $minutes = intdiv($totalSeconds, 60);
        $remainingSeconds = $totalSeconds % 60;

        if ($minutes < 60) {
            return $remainingSeconds > 0 ? $minutes.' dk '.$remainingSeconds.' sn' : $minutes.' dk';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return $remainingMinutes > 0 ? $hours.' sa '.$remainingMinutes.' dk' : $hours.' sa';
    };
    $formatBytes = static function (mixed $value) use ($isKnownNumber): string {
        if (! $isKnownNumber($value) || (float) $value < 0) {
            return 'bilinmiyor';
        }

        $bytes = (float) $value;

        if ($bytes < 1024) {
            return number_format(round($bytes), 0, ',', '.').' B';
        }

        return $bytes < 1024 * 1024
            ? number_format($bytes / 1024, 1, ',', '.').' KB'
            : number_format($bytes / (1024 * 1024), 1, ',', '.').' MB';
    };
    $countSuffix = static function (int $number): string {
        $unitSuffixes = ['', "'i", "'si", "'ü", "'ü", "'i", "'sı", "'si", "'i", "'u"];
        $tenSuffixes = ['', "'u", "'si", "'u", "'ı", "'si", "'ı", "'i", "'i", "'ı"];

        if ($number <= 0) {
            return "'ı";
        }

        if ($number % 10 !== 0) {
            return $unitSuffixes[$number % 10];
        }

        if ($number % 100 !== 0) {
            return $tenSuffixes[intdiv($number % 100, 10)];
        }

        if ($number % 1000 !== 0) {
            return "'ü";
        }

        return $number % 1_000_000 !== 0 ? "'i" : "'u";
    };
    $progressText = static function (mixed $counts) use ($countSuffix): string {
        $counts = is_array($counts) ? $counts : [];
        $value = static fn (string $key): int => is_numeric($counts[$key] ?? null) ? max(0, (int) $counts[$key]) : 0;
        $total = $value('total');

        if ($total === 0) {
            return 'Henüz öğe yok';
        }

        $completed = $value('completed');
        $parts = [number_format($total, 0, ',', '.').' öğeden '.number_format($completed, 0, ',', '.').$countSuffix($completed).' tamamlandı'];

        foreach (['failed' => 'başarısız', 'running' => 'çalışıyor', 'stopped' => 'durduruldu', 'skipped' => 'atlandı'] as $key => $label) {
            if ($value($key) > 0) {
                $parts[] = number_format($value($key), 0, ',', '.').' '.$label;
            }
        }

        return implode(' · ', $parts);
    };
    $itemLabel = static function (mixed $item): string {
        $label = is_array($item) && is_string($item['label'] ?? null) ? trim($item['label']) : '';

        if ($label !== '') {
            return $label;
        }

        $key = is_array($item) ? ($item['key'] ?? null) : null;

        return is_string($key) && is_numeric($key) ? 'Öğe '.(int) $key : 'Öğe';
    };
    /*
     * Snapshot times are ISO strings that the runner already gives in the app timezone. Both helpers read the wall
     * clock straight from the string and never convert it, exactly like workflowClockTime() and workflowDateTime().
     */
    $clockTime = static fn (mixed $time): string => is_string($time) && preg_match('/T(\d{2}:\d{2}:\d{2})/', $time, $clockParts) === 1
        ? $clockParts[1]
        : '--:--:--';
    $formatDateTime = static function (mixed $time): ?string {
        $months = ['Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];

        if (! is_string($time) || preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/', $time, $parts) !== 1 || ! isset($months[(int) $parts[2] - 1])) {
            return null;
        }

        return (int) $parts[3].' '.$months[(int) $parts[2] - 1].' '.$parts[1].' · '.$parts[4].':'.$parts[5];
    };
    /* Seconds since an ISO time with an offset (an absolute instant, so the timezone does not matter); null when unknown. */
    $secondsSince = static function (mixed $time): ?float {
        if (! is_string($time) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $time) !== 1) {
            return null;
        }

        try {
            $startedAt = \Carbon\CarbonImmutable::parse($time);
        } catch (\Throwable) {
            return null;
        }

        return round(max(0, now()->getTimestampMs() - $startedAt->getTimestampMs()) / 1000, 1);
    };

    $runStatus = (string) ($snapshot['status'] ?? $workflowRun->status);
    $runActive = (bool) ($snapshot['active'] ?? $workflowRun->isActive());
    /*
     * The status a step, item mark or gallery card shows. Work that never started stays `pending` on disk (a later
     * phase can resume it), but once the run is no longer active nothing will start it, so it reads as not run
     * ("Çalıştırılmadı") instead of waiting. workflowDisplayStatus() in workflows.js does the same.
     */
    $displayStatus = static fn (mixed $status): mixed => match (true) {
        $runActive => $status,
        $status === 'pending' => 'not_run',
        $status === 'running' || $status === 'starting' => 'interrupted',
        default => $status,
    };
    $runTotals = is_array($snapshot['totals'] ?? null) ? $snapshot['totals'] : [];
    $runModels = array_values(array_filter(
        is_array($snapshot['models'] ?? null) ? $snapshot['models'] : [],
        static fn (mixed $model): bool => is_array($model) && is_string($model['slug'] ?? null),
    ));
    $modelNames = [];

    foreach ($runModels as $runModel) {
        $modelNames[$runModel['slug']] = is_string($runModel['name'] ?? null) && $runModel['name'] !== '' ? $runModel['name'] : $runModel['slug'];
    }

    $runStartedText = $formatDateTime($snapshot['startedAt'] ?? $workflowRun->started_at?->toIso8601String());
    $runFinishedText = $formatDateTime($snapshot['finishedAt'] ?? $workflowRun->finished_at?->toIso8601String());
    $templateName = is_string($template['name'] ?? null) ? $template['name'] : $workflowRun->title;
    $galleryDefinition = is_array($template['gallery'] ?? null) ? $template['gallery'] : null;
    $gallerySignature = (int) ($runTotals['itemsCompleted'] ?? 0).'|'.(int) ($runTotals['itemsFailed'] ?? 0).'|'.($runActive ? 'true' : 'false');
@endphp

<div class="content-frame" data-workflow-run data-status-url="{{ route('workflows.runs.status', $workflowRun) }}" data-detail-url="{{ route('workflows.runs.detail', $workflowRun) }}" data-active="{{ $runActive ? 'true' : 'false' }}" data-current-status="{{ $runStatus }}" data-gallery-signature="{{ $gallerySignature }}">
    @include('workflows.runs._header')
    @include('workflows.runs._totals')
    @include('workflows.runs._models')
    @include('workflows.runs._events')
    @include('workflows.runs._gallery')
    @include('workflows.runs._dialogs')
</div>
@endsection
