{{--
    Run totals as metric cards. An unknown value is shown as "bilinmiyor", never as 0. The note under the cards says what
    the token counts include: input counts cached reads and writes, output counts reasoning tokens.
--}}
@php
    $itemsCompleted = (int) ($runTotals['itemsCompleted'] ?? 0);
    $itemsTotal = (int) ($runTotals['itemsTotal'] ?? 0);
    $itemsFailed = (int) ($runTotals['itemsFailed'] ?? 0);
@endphp

<section class="mt-6" aria-label="Çalıştırma toplamları">
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
        <article class="metric-card metric-neutral">
            <p class="metric-label">Süre</p>
            <p class="metric-value workflow-total-value" data-workflow-total="duration">{{ $formatDuration($snapshot['durationSeconds'] ?? null) }}</p>
            <p class="metric-detail">Başlangıçtan bu yana</p>
        </article>
        <article class="metric-card metric-neutral" aria-describedby="workflow-token-note">
            <p class="metric-label">Girdi token</p>
            <p class="metric-value workflow-total-value" data-workflow-total="inputTokens">{{ $formatNumber($runTotals['inputTokens'] ?? null) }}</p>
            <p class="metric-detail">Tüm modellerin prompt tokenları</p>
        </article>
        <article class="metric-card metric-neutral" aria-describedby="workflow-token-note">
            <p class="metric-label">Çıktı token</p>
            <p class="metric-value workflow-total-value" data-workflow-total="outputTokens">{{ $formatNumber($runTotals['outputTokens'] ?? null) }}</p>
            <p class="metric-detail">Modellerin ürettiği tokenlar</p>
        </article>
        <article class="metric-card metric-neutral">
            <p class="metric-label">Maliyet</p>
            <p class="metric-value workflow-total-value" data-workflow-total="cost">{{ $formatCost($runTotals['costUsd'] ?? null) }}</p>
            <p class="metric-detail">Sağlayıcının bildirdiği tutar</p>
        </article>
        <article class="metric-card metric-neutral">
            <p class="metric-label">Model çağrısı</p>
            <p class="metric-value workflow-total-value" data-workflow-total="calls">{{ $formatNumber($runTotals['calls'] ?? null) }}</p>
            <p class="metric-detail">Denemeler dahil</p>
        </article>
        <article class="metric-card metric-neutral">
            <p class="metric-label">Öğeler</p>
            <p class="metric-value workflow-total-value" data-workflow-total="items">{{ number_format($itemsCompleted, 0, ',', '.') }}/{{ number_format($itemsTotal, 0, ',', '.') }}</p>
            <p class="metric-detail" data-workflow-total="itemsFailed">{{ $itemsFailed > 0 ? number_format($itemsFailed, 0, ',', '.').' öğe başarısız' : 'Başarısız öğe yok' }}</p>
        </article>
    </div>
    <p class="mt-2 text-xs leading-5 text-[var(--muted)]" id="workflow-token-note">Girdi önbellekten okunan ve yazılan token'ları, çıktı akıl yürütme token'larını da içerir.</p>
</section>
