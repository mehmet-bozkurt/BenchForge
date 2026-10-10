@if (($report['blockedCount'] ?? 0) > 0 || ($report['observedCrossModelReadCount'] ?? 0) > 0 || ($report['stoppedForBoundary'] ?? false))
    <div class="mt-3 rounded-lg border border-[#f1d7a5] bg-[var(--amber-soft)] px-3 py-2 text-[10px] leading-4 text-[#8b5a16]">
        @if (($report['blockedCount'] ?? 0) > 0)
            <p class="font-bold">Kapsam dışı erişim engellendi · {{ $report['blockedCount'] }} girişim</p>
        @endif
        @if ($report['stoppedForBoundary'] ?? false)
            <p class="mt-1 font-bold">Tekrarlanan sınır ihlalleri nedeniyle model çağrısı durduruldu.</p>
        @endif
        @if (($report['observedCrossModelReadCount'] ?? 0) > 0)
            <p class="mt-1 font-bold text-[var(--red)]">Başka modele ait kayıt görüldü. Karşılaştırma bağımsızlığını inceleyin.</p>
        @endif
        @if ($report['scanLimited'] ?? false)
            <p class="mt-1">Eski loglardan bulunan işaretlerdir; tam erişim denetimi değildir.</p>
        @endif
        @if (($report['entries'] ?? []) !== [])
            <details class="mt-2">
                <summary class="cursor-pointer font-bold">Erişim kayıtlarını göster</summary>
                <ul class="mt-2 grid gap-2">
                    @foreach ($report['entries'] as $entry)
                        <li class="break-words rounded-md bg-white/70 p-2">
                            <span class="font-bold">{{ $entry['tool'] }}</span> · {{ $entry['reason'] }}
                            <code class="mt-1 block break-all text-[9px]">{{ $entry['path'] }}</code>
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif
    </div>
@endif
