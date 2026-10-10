@props(['model', 'details', 'provider', 'selected' => false, 'disabled' => false])

@php
    $isGo = $provider === 'opencode-go';
    $outputLimit = $isGo ? $model->output_limit : data_get($model->catalog_payload, 'top_provider.max_completion_tokens');
@endphp

<div @class([
    'min-w-0 rounded-xl border border-[var(--line)] bg-white p-3 text-xs',
    'has-checked:border-[var(--violet)] has-checked:bg-[var(--violet-soft)]' => $isGo,
    'has-checked:border-[var(--forest)] has-checked:bg-[var(--mint-soft)]' => ! $isGo,
]) data-preparation-model-card="{{ $provider }}" data-preparation-search-text="{{ Str::lower($model->name.' '.$model->model_id) }}">
    <label class="flex cursor-pointer items-start gap-3">
        <input class="mt-0.5 shrink-0" type="checkbox" name="models[]" value="{{ $model->model_id }}" data-preparation-model="{{ $provider }}" @checked($selected) @disabled($disabled)>
        <span class="min-w-0">
            <strong class="block break-words">{{ $model->name }}</strong>
            <code class="mt-1 block break-all text-[10px] text-[var(--muted)]">{{ $model->model_id }}</code>
        </span>
    </label>

    <div class="mt-3 rounded-lg bg-[var(--surface-subtle)] p-3">
        <p class="text-[10px] font-bold text-[var(--muted)]">{{ $isGo ? 'Resmî Go birim tarifesi' : 'OpenRouter yayımlanan birim fiyatı · USD / 1 milyon token' }}</p>
        <dl class="mt-2 grid grid-cols-2 gap-3 text-[11px]">
            <div><dt class="text-[var(--muted)]">Girdi</dt><dd class="mt-1 break-words font-extrabold">{{ $details['inputPrice'] }}</dd></div>
            <div><dt class="text-[var(--muted)]">Çıktı</dt><dd class="mt-1 break-words font-extrabold">{{ $details['outputPrice'] }}</dd></div>
            <div><dt class="text-[var(--muted)]">Bağlam sınırı</dt><dd class="mt-1 font-bold">{{ $model->context_length !== null ? number_format($model->context_length, 0, ',', '.').' token' : 'Yayımlanmamış' }}</dd></div>
            <div><dt class="text-[var(--muted)]">Çıktı sınırı</dt><dd class="mt-1 font-bold">{{ is_numeric($outputLimit) ? number_format((float) $outputLimit, 0, ',', '.').' token' : 'Yayımlanmamış' }}</dd></div>
        </dl>
    </div>
    <p class="mt-2 text-[10px] leading-5 text-[var(--muted)]">{{ $details['priceNotice'] }}</p>

    <details class="mt-3 border-t border-[var(--line)] pt-3">
        <summary class="cursor-pointer rounded font-extrabold text-[var(--forest)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--forest)]">Tüm model detayları</summary>
        <div class="mt-3 grid min-w-0 gap-4">
            <div class="rounded-lg border border-[var(--line)] p-3 text-[10px] leading-5 text-[var(--muted)]">
                <p>Kaynak: <a class="break-all font-bold text-[var(--forest)] underline" href="{{ $details['sourceUrl'] }}" target="_blank" rel="noopener noreferrer">{{ $details['sourceName'] }}</a></p>
                <p>Son eşitleme: <strong>{{ $details['syncedAt'] }}</strong></p>
                @if ($isGo)
                    <a class="font-bold text-[var(--forest)] underline" href="https://opencode.ai/docs/go/#usage-limits" target="_blank" rel="noopener noreferrer">Go resmî kullanım ve fiyat tablosu ↗</a>
                @endif
                @unless ($details['hasRawMetadata'])
                    <p class="mt-2 text-[#815d0c]">Bu kayıt eski katalogdan geliyor; yalnız önceden kaydedilmiş alanlar gösteriliyor. Tüm özgün verileri almak için kataloğu yenileyin.</p>
                @endunless
            </div>

            <dl class="grid min-w-0 gap-3 text-[11px] leading-5">
                @foreach ($details['fields'] as $field)
                    <div class="min-w-0"><dt class="font-extrabold text-[var(--muted)]">{{ $field['label'] }}</dt><dd class="mt-1 whitespace-pre-wrap break-words">{{ $field['value'] }}</dd></div>
                @endforeach
            </dl>

            <section class="min-w-0 rounded-lg border border-[var(--line)] p-3">
                <h3 class="text-[11px] font-extrabold">{{ $isGo ? 'models.dev referans maliyetleri · Resmî Go tarifesi değildir' : 'Katalogdaki tüm fiyat alanları' }}</h3>
                <dl class="mt-3 grid min-w-0 gap-3 text-[11px]">
                    @forelse ($details['pricing'] as $price)
                        <div class="min-w-0 border-b border-[var(--line)] pb-2 last:border-0 last:pb-0">
                            <dt class="break-words font-bold">{{ $price['label'] }}</dt>
                            <dd class="mt-1 whitespace-pre-wrap break-words font-mono">{{ $price['value'] }} <span class="font-sans text-[10px] text-[var(--muted)]">{{ $price['unit'] }}</span></dd>
                        </div>
                    @empty
                        <p class="text-[var(--muted)]">Kaynak fiyat bilgisi yayımlamamış.</p>
                    @endforelse
                </dl>
            </section>

            <details class="min-w-0">
                <summary class="cursor-pointer font-bold text-[var(--muted)]">{{ $details['hasRawMetadata'] ? 'Kaynaktan gelen tüm alanlar · JSON' : 'Kaydedilmiş katalog alanları · JSON' }}</summary>
                <pre class="mt-2 max-h-80 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-[var(--surface-subtle)] p-3 font-mono text-[10px] leading-5">{{ $details['rawJson'] }}</pre>
            </details>
        </div>
    </details>
</div>
