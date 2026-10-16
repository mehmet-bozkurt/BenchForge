@extends('layouts.app')

@section('content')
<div class="content-frame">
    <header class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="eyebrow">Görev promptları</p>
            <h1 class="mt-2 text-3xl font-black tracking-[-0.05em] text-[var(--ink)] sm:text-[38px]">Promptlar</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-[var(--muted)]">
                Yeni Benchmark ekranında seçilen görev promptları. Her koşu başlarken promptun kopyasını alır; düzenlemek veya silmek geçmiş ve çalışan koşuları değiştirmez. Teslim sözleşmesi (lint, build, static export) koşu başlarken otomatik eklenir.
            </p>
        </div>
        <a class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-[var(--forest)] px-4 py-2.5 text-sm font-extrabold text-white shadow-[0_8px_20px_rgb(79_70_229/0.2)] transition hover:bg-[#4338ca]" href="{{ route('prompts.create') }}">
            <span aria-hidden="true">+</span> Yeni prompt
        </a>
    </header>

    @if (session('status'))
        <div class="mt-6 rounded-xl border border-[var(--success-line)] bg-[var(--success-soft)] px-4 py-3 text-sm text-[var(--success)]" role="status">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any() || $directoryError)
        <div class="mt-6 rounded-xl border border-[var(--red-line)] bg-[var(--red-soft)] px-4 py-3 text-sm text-[var(--red)]" role="alert">
            {{ $directoryError ?? $errors->first() }}
        </div>
    @endif

    <section class="mt-7" aria-labelledby="prompts-title">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="prompts-title" class="text-sm font-black text-[var(--ink)]">Kayıtlı promptlar · {{ count($prompts) }}</h2>
            @if (count($prompts) > 4)
                <label class="relative w-full max-w-xs">
                    <span class="sr-only">Promptlarda ara</span>
                    <input class="h-10 w-full rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3.5 text-sm outline-none focus:border-[var(--forest)]" type="search" placeholder="Başlıkta veya metinde ara…" data-prompt-filter>
                </label>
            @endif
        </div>

        <div class="mt-3 grid gap-3 lg:grid-cols-2" data-prompt-list>
            @forelse ($prompts as $prompt)
                <article class="flex min-w-0 flex-col rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-5" data-prompt-item data-search="{{ \Illuminate\Support\Str::lower($prompt['title'].' '.\Illuminate\Support\Str::limit($prompt['content'], 4000, '')) }}" aria-labelledby="prompt-{{ $loop->index }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 id="prompt-{{ $loop->index }}" class="text-base font-black tracking-[-0.02em] text-[var(--ink)]">{{ $prompt['title'] }}</h3>
                            <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-[var(--muted)]">
                                <span class="rounded-full px-2 py-0.5 font-bold {{ $prompt['source'] === 'file' ? 'bg-[var(--slate-soft)] text-[var(--slate-ink)]' : 'bg-[var(--violet-soft)] text-[var(--violet)]' }}">{{ $prompt['source'] === 'file' ? 'Dosya' : 'Kütüphane' }}</span>
                                <span class="font-mono">{{ $prompt['location'] }}</span>
                                <span aria-hidden="true">·</span>
                                <span>{{ number_format($prompt['words'], 0, ',', '.') }} kelime</span>
                                <span aria-hidden="true">·</span>
                                <span>{{ $prompt['updatedAt']->locale('tr')->isoFormat('D MMM YYYY HH:mm') }}</span>
                            </p>
                        </div>
                        @if ($prompt['inUse'])
                            <span class="status-badge status-running"><span class="status-dot"></span>Koşuda kullanılıyor</span>
                        @endif
                    </div>

                    <p class="mt-3 line-clamp-4 flex-1 font-mono text-xs leading-5 whitespace-pre-line text-[var(--muted)]">{{ \Illuminate\Support\Str::limit($prompt['content'], 600) }}</p>

                    <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-[var(--line)] pt-3">
                        <a class="btn-ghost btn-xs" href="{{ route('prompts.edit', $prompt['key']) }}">Düzenle</a>
                        <a class="btn-ghost btn-xs" href="{{ route('runner.create', ['prompt' => $prompt['value']]) }}">Bu promptla benchmark</a>
                        @unless ($prompt['inUse'])
                            <form class="ml-auto" method="POST" action="{{ route('prompts.destroy', $prompt['key']) }}" data-confirm="“{{ $prompt['title'] }}” promptu silinsin mi? {{ $prompt['source'] === 'file' ? 'Dosya prompts/.silinen klasörüne taşınır. ' : '' }}Geçmiş koşular etkilenmez.">
                                @csrf
                                @method('DELETE')
                                <button class="btn-danger btn-xs" type="submit">Sil</button>
                            </form>
                        @endunless
                    </div>
                </article>
            @empty
                <div class="rounded-2xl border border-dashed border-[var(--line-strong)] p-10 text-center text-sm text-[var(--muted)] lg:col-span-2">
                    Henüz prompt yok. <a class="font-bold text-[var(--forest)]" href="{{ route('prompts.create') }}">İlk promptu ekleyin.</a>
                </div>
            @endforelse
        </div>
        <p class="mt-4 hidden text-center text-sm text-[var(--muted)]" data-prompt-empty>Aramayla eşleşen prompt yok.</p>
    </section>
</div>
@endsection
