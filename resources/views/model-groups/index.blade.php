@extends('layouts.app')

@section('content')
@php
    $knownModels = array_flip($catalogModels);
@endphp

<div class="content-frame">
    <header class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="eyebrow">Yeni Benchmark</p>
            <h1 class="mt-2 text-3xl font-black tracking-[-0.05em] text-[var(--ink)] sm:text-[38px]">Model Grupları</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-[var(--muted)]">
                Sık kullandığınız model setlerini kaydedin, düzenleyin veya silin. Gruplar veri kökündeki <code>config/</code> klasöründe dosya olarak durur; geçmiş koşular kendi kopyalarını sakladığı için değişikliklerden etkilenmez.
            </p>
        </div>
        <a class="inline-flex shrink-0 items-center justify-center rounded-xl border border-[var(--line)] bg-white px-4 py-2.5 text-xs font-extrabold text-[var(--forest)]" href="{{ route('runner.create') }}">Yeni Benchmark'a dön</a>
    </header>

    @if (session('status'))
        <div class="mt-6 rounded-xl border border-[#a9dec9] bg-[var(--success-soft)] px-4 py-3 text-sm text-[var(--success)]" role="status">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any() || $directoryError)
        <div class="mt-6 rounded-xl border border-[#f2c9cf] bg-[var(--red-soft)] px-4 py-3 text-sm text-[var(--red)]" role="alert">
            <ul class="list-disc space-y-1 pl-5 text-xs leading-5">
                @if ($directoryError)<li>{{ $directoryError }}</li>@endif
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mt-7 grid gap-6 xl:grid-cols-[minmax(0,1fr)_400px]">
        <section class="grid content-start gap-3" aria-labelledby="groups-title">
            <h2 id="groups-title" class="text-sm font-black text-[var(--ink)]">Kayıtlı gruplar · {{ count($groups) }}</h2>

            @forelse ($groups as $group)
                <article class="rounded-2xl border border-[var(--line)] bg-white p-5" aria-labelledby="group-{{ $loop->index }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 id="group-{{ $loop->index }}" class="text-base font-black tracking-[-0.02em]">{{ $group['label'] }}</h3>
                            <p class="mt-1 font-mono text-[11px] text-[var(--muted)]">config/{{ $group['name'] }}.conf · {{ count($group['models']) }} model · {{ $group['updatedAt']->locale('tr')->isoFormat('D MMM YYYY HH:mm') }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <a class="rounded-lg border border-[var(--line)] bg-[var(--surface-subtle)] px-3 py-2 text-[11px] font-extrabold text-[var(--forest)]" href="{{ route('model-groups.edit', $group['name']) }}">Düzenle</a>
                            <form method="POST" action="{{ route('model-groups.destroy', $group['name']) }}" data-confirm="“{{ $group['label'] }}” grubu silinsin mi? Dosya config/.silinen klasörüne taşınır; geçmiş koşular etkilenmez.">
                                @csrf
                                @method('DELETE')
                                <button class="rounded-lg border border-[#f2c9cf] bg-[var(--red-soft)] px-3 py-2 text-[11px] font-extrabold text-[var(--red)]" type="submit">Sil</button>
                            </form>
                        </div>
                    </div>

                    @if ($group['error'])
                        <p class="mt-3 rounded-lg bg-[var(--amber-soft)] px-3 py-2 text-[11px] text-[var(--amber)]">{{ $group['error'] }} Bu grup Yeni Benchmark ekranında çalıştırılamaz; düzenleyerek onarabilirsiniz.</p>
                    @endif

                    @if ($group['models'] !== [])
                        <ul class="mt-4 grid gap-1.5 sm:grid-cols-2">
                            @foreach ($group['models'] as $entry)
                                <li class="flex min-w-0 items-center gap-2 rounded-lg bg-[var(--surface-subtle)] px-3 py-2">
                                    <span class="min-w-0 flex-1 truncate font-mono text-[11px]" title="{{ $entry['model'] }}">{{ $entry['model'] }}</span>
                                    @unless (isset($knownModels[$entry['model']]))
                                        <span class="shrink-0 rounded bg-[var(--amber-soft)] px-1.5 py-0.5 text-[8px] font-black text-[var(--amber)]" title="Bu kimlik güncel OpenRouter veya OpenCode Go kataloğunda yok. Başka bir sağlayıcıya aitse sorun değildir.">KATALOGDA YOK</span>
                                    @endunless
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </article>
            @empty
                <div class="rounded-2xl border border-dashed border-[var(--line)] p-8 text-center text-sm text-[var(--muted)]">
                    Henüz kayıtlı grup yok. Sağdaki formla veya Yeni Benchmark ekranındaki “Grup olarak kaydet” ile oluşturabilirsiniz.
                </div>
            @endforelse
        </section>

        <section class="h-fit rounded-2xl border border-[var(--line)] bg-white p-5" aria-labelledby="new-group-title">
            <p class="eyebrow">Yeni grup</p>
            <h2 id="new-group-title" class="mt-1 text-lg font-black tracking-[-0.03em]">Grup oluştur</h2>
            <form class="mt-4 grid gap-4" method="POST" action="{{ route('model-groups.store') }}">
                @csrf
                @include('model-groups._form', ['formId' => 'new-group', 'name' => '', 'models' => ''])
                <button class="rounded-xl bg-[var(--forest)] px-4 py-3 text-sm font-extrabold text-white" type="submit">Grubu kaydet</button>
            </form>
        </section>
    </div>
</div>
@endsection
