@extends('layouts.app')

@section('content')
@php
    $editing = $prompt !== null;
    $title = old('title', $prompt['title'] ?? '');
    $content = old('content', $prompt['content'] ?? '');
    $isFile = ! $editing || $prompt['source'] === 'file';
@endphp

<div class="content-frame">
    <nav class="mb-3 flex items-center gap-2 text-[13px] font-semibold text-[var(--muted)]" aria-label="Sayfa yolu">
        <a class="transition-colors hover:text-[var(--forest)]" href="{{ route('prompts.index') }}">Promptlar</a>
        <span aria-hidden="true">/</span>
        <span class="text-[var(--ink)]" aria-current="page">{{ $editing ? $prompt['title'] : 'Yeni prompt' }}</span>
    </nav>

    <header>
        <h1 class="text-3xl font-black tracking-[-0.05em] text-[var(--ink)] sm:text-[38px]">{{ $editing ? 'Promptu düzenle' : 'Yeni prompt' }}</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-[var(--muted)]">
            @if ($editing && ! $isFile)
                Bu prompt Yeni Benchmark ekranından kütüphaneye kaydedilmiş. Değişiklikler yalnızca bundan sonra başlatılan koşuları etkiler.
            @else
                Prompt veri kökündeki <code>prompts/</code> klasörüne dosya olarak kaydedilir. Başlık değişirse eski dosya <code>prompts/.silinen</code> klasörüne taşınır; değişiklikler yalnızca bundan sonra başlatılan koşuları etkiler.
            @endif
        </p>
    </header>

    @if ($errors->any())
        <div class="mt-6 rounded-xl border border-[var(--red-line)] bg-[var(--red-soft)] px-4 py-3 text-sm text-[var(--red)]" role="alert">
            <strong class="font-extrabold">Prompt kaydedilemedi.</strong>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-xs leading-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($editing && $prompt['inUse'])
        <p class="mt-6 rounded-xl border border-[var(--indigo-line)] bg-[var(--indigo-soft)] px-4 py-3 text-sm text-[var(--indigo-ink)]">Bu prompt şu anda çalışan bir koşuda kullanılıyor. Koşu bitince kaydedebilir veya silebilirsiniz.</p>
    @endif

    <form class="mt-6 grid gap-5 rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-5 sm:p-6" method="POST" action="{{ $editing ? route('prompts.update', $prompt['key']) : route('prompts.store') }}">
        @csrf
        @if ($editing)
            @method('PUT')
        @endif

        <label class="grid gap-2 text-xs font-extrabold text-[var(--ink)]" for="prompt-title">
            Başlık
            <input class="h-11 rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3.5 text-sm font-semibold text-[var(--ink)] outline-none focus:border-[var(--forest)] focus:ring-3 focus:ring-[var(--mint-soft)]" id="prompt-title" type="text" name="title" value="{{ $title }}" maxlength="120" required placeholder="Örnek: Plush Octopus v2" @if ($isFile) data-prompt-title @endif>
            @if ($isFile)
                <span class="font-mono text-[11px] font-normal text-[var(--muted)]" data-prompt-file aria-live="polite"></span>
            @endif
        </label>

        <div class="grid gap-2">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <label class="text-xs font-extrabold text-[var(--ink)]" for="prompt-content">Prompt metni</label>
                <span class="font-mono text-[11px] text-[var(--muted)]" data-prompt-count aria-live="polite"></span>
            </div>
            <textarea class="min-h-[28rem] resize-y rounded-xl border border-[var(--line)] bg-[var(--surface)] px-4 py-3.5 font-mono text-[13px] leading-6 text-[var(--ink)] outline-none focus:border-[var(--forest)] focus:ring-3 focus:ring-[var(--mint-soft)]" id="prompt-content" name="content" required minlength="{{ \App\Services\PromptLibrary::MIN_LENGTH }}" maxlength="{{ \App\Services\PromptLibrary::MAX_LENGTH }}" spellcheck="false" data-prompt-content>{{ $content }}</textarea>
            <span class="text-[11px] leading-5 text-[var(--muted)]">Modellere giden görev metni. Lint, build ve static export şartlarını içeren BenchForge teslim sözleşmesi koşu başlarken sona otomatik eklenir; buraya yazmanız gerekmez.</span>
        </div>

        <div class="flex flex-wrap items-center gap-2 border-t border-[var(--line)] pt-4">
            <button class="rounded-xl bg-[var(--forest)] px-5 py-2.5 text-sm font-extrabold text-white transition hover:bg-[#4338ca] disabled:cursor-not-allowed disabled:opacity-50" type="submit" @disabled($editing && $prompt['inUse'])>{{ $editing ? 'Değişiklikleri kaydet' : 'Promptu kaydet' }}</button>
            <a class="btn-ghost" href="{{ route('prompts.index') }}">Vazgeç</a>
        </div>
    </form>

    @if ($editing && ! $prompt['inUse'])
        <form class="mt-3 flex justify-end" method="POST" action="{{ route('prompts.destroy', $prompt['key']) }}" data-confirm="“{{ $prompt['title'] }}” promptu silinsin mi? {{ $isFile ? 'Dosya prompts/.silinen klasörüne taşınır. ' : '' }}Geçmiş koşular etkilenmez.">
            @csrf
            @method('DELETE')
            <button class="btn-danger" type="submit">Promptu sil</button>
        </form>
    @endif
</div>
@endsection
