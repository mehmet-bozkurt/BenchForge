@extends('layouts.app')

@section('content')
@php
    $formatFileSize = static function (int $bytes): string {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1, ',', '.').' KB';
        }

        return number_format($bytes / (1024 * 1024), 1, ',', '.').' MB';
    };
@endphp

<div class="content-frame">
    <nav class="mb-6 flex flex-wrap items-center gap-2 text-xs font-semibold text-[var(--muted)]" aria-label="Sayfa yolu">
        <a class="transition-colors hover:text-[var(--forest)]" href="{{ route('dashboard') }}">Genel Bakış</a>
        <span aria-hidden="true">/</span>
        <a class="transition-colors hover:text-[var(--forest)]" href="{{ route('runs.show', $run) }}">{{ $run->name }}</a>
        <span aria-hidden="true">/</span>
        <span class="text-[var(--ink)]" aria-current="page">{{ $artifact->label }}</span>
    </nav>

    <header class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="eyebrow">Güvenli dosya önizlemesi</p>
            <h1 class="mt-2 text-3xl font-black tracking-[-0.05em] text-[var(--ink)]">{{ $artifact->label }}</h1>
            <code class="mt-3 block max-w-4xl break-all text-[11px] text-[var(--muted)]">{{ $artifact->relative_path }}</code>
        </div>
        <div class="flex gap-2">
            <span class="schema-badge schema-badge-legacy">{{ $artifact->type }}</span>
            <span class="schema-badge schema-badge-extended">{{ $formatFileSize($preview['sizeBytes']) }}</span>
        </div>
    </header>

    @if ($preview['truncated'])
        <aside class="mt-5 rounded-xl border border-[#f1d7a5] bg-[var(--amber-soft)] px-4 py-3 text-xs font-semibold text-[#8b5a16]">
            Önizleme kısaltıldı. Dosyanın başlangıç ve son bölümü gösteriliyor.
        </aside>
    @endif

    <section class="log-viewer mt-6 overflow-hidden rounded-2xl border border-[#26304c]" aria-label="Dosya içeriği">
        <div class="flex items-center justify-between border-b border-white/10 bg-[#11182b] px-4 py-3">
            <div class="flex gap-1.5" aria-hidden="true">
                <span class="h-2.5 w-2.5 rounded-full bg-[#fb7185]"></span>
                <span class="h-2.5 w-2.5 rounded-full bg-[#fbbf24]"></span>
                <span class="h-2.5 w-2.5 rounded-full bg-[#34d399]"></span>
            </div>
            <span class="font-mono text-[10px] text-[#8490aa]">salt okunur</span>
        </div>
        <pre class="max-h-[72vh] overflow-auto whitespace-pre-wrap break-words p-5 font-mono text-[11px] leading-5 text-[#d7def0] sm:p-6">{{ $preview['content'] }}</pre>
    </section>
</div>
@endsection
