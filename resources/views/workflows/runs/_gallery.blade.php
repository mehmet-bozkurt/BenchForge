{{--
    One card per model and gallery item. workflows.js swaps the [data-workflow-gallery] region with a fresh copy of this
    page while items finish. Each playable card keeps its sandboxed player in an inert <template>; nothing loads until
    "Oyna" copies it into the player dialog. A card whose item ended without a demo says so and keeps the static check
    result and message of its last attempt; only pending or running items say the file is not there yet, and only while
    the run is active. Once it has ended, a pending item never started and says no demo was made.
--}}
@if ($galleryDefinition !== null || $gallery !== [])
    @php
        $hasBrowserCheck = is_string($galleryDefinition['check'] ?? null) && $galleryDefinition['check'] !== '';
        $playableCount = count(array_filter($gallery, static fn (mixed $card): bool => is_array($card) && is_string($card['demoUrl'] ?? null) && $card['demoUrl'] !== ''));
    @endphp
    <section class="mt-8" aria-labelledby="workflow-gallery-title">
        <div data-workflow-gallery>
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="eyebrow">Üretilen demolar</p>
                    <h2 id="workflow-gallery-title" class="mt-1 text-xl font-black tracking-[-0.03em]">Galeri</h2>
                    <p class="mt-1 max-w-3xl text-xs leading-5 text-[var(--muted)]">Demolar sandbox'lı çerçevede açılır ve uygulama oturumuna erişemez. Tarayıcı dış kaynak yüklemeyi ve bağlantı isteklerini (fetch, görsel, script, WebSocket) CSP ile engeller; WebRTC ve sayfa yönlendirmesi yalnız statik kontrolde yakalanır, yine de güvenilmeyen kod olarak düşünün. Kontrol özeti modelin yazdığı HTML'in statik incelemesidir; oynanabilirliği kendiniz deneyin.</p>
                </div>
                <span class="sync-pill">{{ $playableCount === count($gallery) ? $playableCount.' demo' : $playableCount.'/'.count($gallery).' demo hazır' }}</span>
            </div>

            @if ($gallery === [])
                <div class="rounded-2xl border border-dashed border-[var(--line-strong)] bg-[var(--surface)] px-6 py-10 text-center">
                    <h3 class="text-base font-black">Henüz demo yok</h3>
                    <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-[var(--muted)]">Demo adımları tamamlandıkça kartlar burada görünür.</p>
                </div>
            @else
                <div class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
                    @foreach ($gallery as $card)
                        @php
                            $cardChecks = is_array($card['checks'] ?? null) ? $card['checks'] : null;
                            $criticalChecks = is_array($cardChecks['critical'] ?? null) ? $cardChecks['critical'] : [];
                            $warningChecks = is_array($cardChecks['warnings'] ?? null) ? $cardChecks['warnings'] : [];
                            $cardModelName = is_string($card['modelName'] ?? null) && $card['modelName'] !== '' ? $card['modelName'] : $card['model'];
                            $cardDemoUrl = is_string($card['demoUrl'] ?? null) && $card['demoUrl'] !== '' ? $card['demoUrl'] : null;
                            $cardStatus = is_string($card['status'] ?? null) ? $card['status'] : 'pending';
                            $cardMessage = is_string($card['message'] ?? null) ? trim($card['message']) : '';
                            $cardDisplayStatus = $displayStatus($cardStatus);
                            $cardNotRun = $cardDisplayStatus === 'not_run';
                            $cardInProgress = ! $cardNotRun && in_array($cardStatus, ['pending', 'running'], true);
                            $cardFailed = $cardDemoUrl === null && ! $cardInProgress && ! $cardNotRun && $cardStatus !== 'completed';
                            $cardFacts = [$itemLabel(['key' => $card['item']])];

                            if ($cardDemoUrl !== null || $isKnownNumber($card['bytes'] ?? null)) {
                                $cardFacts[] = $formatBytes($card['bytes'] ?? null);
                            }
                        @endphp
                        <article class="workflow-gallery-card runner-card" data-workflow-gallery-card="{{ $card['model'] }}:{{ $card['item'] }}">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-[9px] font-black tracking-[0.1em] text-[var(--forest)] uppercase">{{ $cardModelName }}</p>
                                    <h3 class="mt-1 break-words text-base font-black tracking-[-0.02em]">{{ $card['title'] }}</h3>
                                    <p class="mt-1 text-[10px] font-semibold text-[var(--muted)]">{{ implode(' · ', $cardFacts) }}</p>
                                </div>
                                <span class="status-badge status-{{ $statusTone($cardDisplayStatus) }} shrink-0"><span class="status-dot"></span>{{ $statusLabel($cardDisplayStatus) }}</span>
                            </div>

                            <ul class="mt-4 flex flex-wrap gap-1.5" aria-label="Statik kontrol özeti">
                                @if ($cardChecks === null)
                                    <li class="status-badge status-skip">Kontrol sonucu yok</li>
                                @else
                                    @if ($criticalChecks !== [])
                                        <li class="status-badge status-fail">{{ count($criticalChecks) }} kritik sorun</li>
                                    @endif
                                    @if ($warningChecks !== [])
                                        <li class="status-badge status-unknown">{{ count($warningChecks) }} uyarı</li>
                                    @endif
                                    @if ($criticalChecks === [] && $warningChecks === [])
                                        <li class="status-badge status-pass">Statik kontrol temiz</li>
                                    @endif
                                @endif
                                @if ($hasBrowserCheck)
                                    <li class="status-badge status-skip">Tarayıcı testi Aşama 2'de</li>
                                @endif
                            </ul>

                            @if ($criticalChecks !== [] || $warningChecks !== [])
                                <details class="mt-3 text-xs" @if ($cardFailed && $criticalChecks !== []) open @endif>
                                    <summary class="cursor-pointer font-extrabold text-[var(--muted)]">Kontrol ayrıntıları</summary>
                                    <ul class="mt-2 grid gap-1.5 leading-5">
                                        @foreach ($criticalChecks as $check)
                                            <li class="text-[var(--red)]"><span class="font-extrabold">Kritik:</span> {{ $check['message'] ?? '' }}</li>
                                        @endforeach
                                        @foreach ($warningChecks as $check)
                                            <li class="text-[var(--amber-ink)]"><span class="font-extrabold">Uyarı:</span> {{ $check['message'] ?? '' }}</li>
                                        @endforeach
                                    </ul>
                                </details>
                            @endif

                            <div class="mt-auto flex flex-wrap items-center gap-2 pt-4">
                                @if ($cardDemoUrl !== null)
                                    <button class="btn-primary btn-xs" type="button" aria-haspopup="dialog" data-workflow-play>Oyna ▶</button>
                                    <a class="btn-ghost btn-xs" href="{{ $cardDemoUrl }}" target="_blank" rel="noopener noreferrer">Yeni sekmede aç ↗</a>
                                    <template data-workflow-demo-template>
                                        <div class="min-w-0" data-workflow-demo-part="heading">
                                            <p class="text-[9px] font-black tracking-[0.1em] text-[var(--forest)] uppercase">{{ $cardModelName }}</p>
                                            <h2 id="workflow-demo-title" class="mt-1 truncate text-base font-black">{{ $card['title'] }}</h2>
                                            <a class="mt-1 inline-flex text-[11px] font-extrabold text-[var(--forest)] underline" href="{{ $cardDemoUrl }}" target="_blank" rel="noopener noreferrer">Yeni sekmede aç ↗</a>
                                        </div>
                                        <iframe class="workflow-demo-frame" src="{{ $cardDemoUrl }}" title="{{ $card['title'] }} demosu" sandbox="allow-scripts" referrerpolicy="no-referrer" data-workflow-demo-part="frame"></iframe>
                                    </template>
                                @elseif ($cardInProgress)
                                    <p class="text-xs font-semibold text-[var(--muted)]">Demo dosyası henüz yok.</p>
                                @elseif ($cardNotRun)
                                    <p class="text-xs font-semibold text-[var(--muted)]">Demo üretilmedi (çalıştırma bitti).</p>
                                @elseif ($cardStatus === 'completed')
                                    <p class="text-xs font-semibold text-[var(--muted)]">Demo dosyası bulunamadı.</p>
                                @else
                                    <div class="min-w-0 text-xs leading-5" data-workflow-gallery-failure>
                                        <p class="font-extrabold text-[var(--red)]">Demo üretilemedi.</p>
                                        @if ($cardMessage !== '')
                                            <p class="mt-1 break-words text-[var(--muted)]">{{ $cardMessage }}</p>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </div>
        <div class="mt-4 hidden rounded-xl border border-[var(--indigo-line)] bg-[var(--indigo-soft)] p-3 text-xs leading-5 text-[var(--indigo-ink)]" role="status" data-workflow-refresh-notice>
            Galeri kendiliğinden güncellenemedi. Yeni demoları görmek için <a class="font-extrabold underline" href="{{ route('workflows.runs.show', $workflowRun) }}">Sonuçları yenile</a>.
        </div>
    </section>
@endif
