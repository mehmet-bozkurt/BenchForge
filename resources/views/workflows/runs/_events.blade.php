{{-- The newest worker events (snapshot.events), one line each, oldest first. Event times are already in the app timezone. --}}
@php
    $eventLines = [];

    foreach (is_array($snapshot['events'] ?? null) ? $snapshot['events'] : [] as $event) {
        if (! is_array($event)) {
            continue;
        }

        $eventModel = is_string($event['model'] ?? null) && $event['model'] !== '' ? ($modelNames[$event['model']] ?? $event['model']) : null;
        $eventParts = [
            $clockTime($event['time'] ?? null),
            is_string($event['level'] ?? null) ? (['warn' => 'UYARI', 'error' => 'HATA'][$event['level']] ?? null) : null,
            $eventModel,
            implode('/', array_filter([$event['step'] ?? null, $event['item'] ?? null], static fn (mixed $part): bool => is_string($part) && $part !== '')),
            is_string($event['message'] ?? null) ? $event['message'] : '',
        ];
        $eventLines[] = implode(' · ', array_filter($eventParts, static fn (mixed $part): bool => is_string($part) && $part !== ''));
    }
@endphp

<section class="runner-terminal mt-8 overflow-hidden rounded-2xl p-5 text-[#cdd6f4] sm:p-6" aria-labelledby="workflow-events-title">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 id="workflow-events-title" class="text-[10px] font-extrabold tracking-[0.1em] text-[#67e8f9] uppercase">Olay günlüğü</h2>
        <span class="text-[9px] font-bold tracking-[0.08em] text-[#7783a5] uppercase"><span>Son 80 olay</span>@if ($runActive)<span data-workflow-live-hint> · 2 sn yenileme</span>@endif</span>
    </div>
    <pre class="mt-4 max-h-[360px] min-h-40 overflow-auto whitespace-pre-wrap break-words font-mono text-[11px] leading-5" tabindex="0" aria-label="Olay günlüğü" data-workflow-events data-workflow-autoscroll>{{ $eventLines !== [] ? implode("\n", $eventLines) : 'Henüz olay kaydı yok.' }}</pre>
</section>
