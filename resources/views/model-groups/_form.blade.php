@php
    $formId = $formId ?? 'model-group-form';
    $limit = \App\Services\BenchmarkRunner::CUSTOM_MODEL_LIMIT;
@endphp

<div class="grid gap-4" data-group-editor>
    <label class="grid gap-2 text-xs font-extrabold text-[var(--ink)]" for="{{ $formId }}-name">
        Grup adı
        <input class="h-11 rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3.5 font-mono text-sm font-semibold outline-none focus:border-[var(--forest)] focus:ring-3 focus:ring-[var(--mint-soft)]" id="{{ $formId }}-name" type="text" name="name" value="{{ old('name', $name) }}" maxlength="63" pattern="[a-z0-9][a-z0-9\-]{1,62}" required>
        <span class="font-normal leading-5 text-[var(--muted)]">Küçük harf, sayı ve tire. Dosya adı olarak kullanılır: <code>config/&lt;ad&gt;.conf</code></span>
    </label>

    <div class="grid gap-2">
        <label class="text-xs font-extrabold text-[var(--ink)]" for="{{ $formId }}-models">Modeller · en fazla {{ $limit }}</label>
        <textarea class="min-h-44 resize-y rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3.5 py-3 font-mono text-xs leading-6 outline-none focus:border-[var(--forest)] focus:ring-3 focus:ring-[var(--mint-soft)]" id="{{ $formId }}-models" name="models" rows="7" placeholder="openrouter/qwen/qwen3-coder&#10;opencode-go/glm-5.3-flash" required data-group-models>{{ old('models', $models) }}</textarea>
        <span class="text-[11px] leading-5 text-[var(--muted)]">Her satıra bir model: <code>sağlayıcı/model</code>. Grupta kalan modellerin klasör adı ve portu korunur; yeni modellere otomatik atanır.</span>
    </div>

    @if ($catalogModels !== [])
        <div class="flex flex-col gap-2 rounded-xl border border-[var(--line)] bg-[var(--surface-subtle)] p-3 sm:flex-row sm:items-center">
            <label class="sr-only" for="{{ $formId }}-picker">Katalogdan model ekle</label>
            <input class="h-10 min-w-0 flex-1 rounded-lg border border-[var(--line)] bg-[var(--surface)] px-3 font-mono text-xs outline-none focus:border-[var(--forest)]" id="{{ $formId }}-picker" type="search" list="{{ $formId }}-catalog" placeholder="Katalogda ara ve ekle…" data-group-model-picker>
            <datalist id="{{ $formId }}-catalog">
                @foreach ($catalogModels as $catalogModel)
                    <option value="{{ $catalogModel }}"></option>
                @endforeach
            </datalist>
            <button class="rounded-lg border border-[var(--line)] bg-[var(--surface)] px-3 py-2 text-[11px] font-extrabold text-[var(--forest)]" type="button" data-group-model-add>Listeye ekle</button>
        </div>
    @endif
</div>
