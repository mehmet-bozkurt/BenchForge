@extends('layouts.app')

@section('content')
<div class="content-frame">
    <header class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="eyebrow">Model Grupları</p>
            <h1 class="mt-2 text-3xl font-black tracking-[-0.05em] text-[var(--ink)] sm:text-[38px]">{{ $group['label'] }}</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-[var(--muted)]">
                Değişiklikler yalnızca bundan sonra başlatılan koşuları etkiler. Ad değişirse eski dosya <code>config/.silinen</code> klasörüne taşınır.
            </p>
        </div>
        <a class="inline-flex shrink-0 items-center justify-center rounded-xl border border-[var(--line)] bg-[var(--surface)] px-4 py-2.5 text-xs font-extrabold text-[var(--forest)]" href="{{ route('model-groups.index') }}">Gruplara dön</a>
    </header>

    @if ($errors->any())
        <div class="mt-6 rounded-xl border border-[var(--red-line)] bg-[var(--red-soft)] px-4 py-3 text-sm text-[var(--red)]" role="alert">
            <strong class="font-extrabold">Grup kaydedilemedi.</strong>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-xs leading-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($group['error'])
        <p class="mt-6 rounded-xl bg-[var(--amber-soft)] px-4 py-3 text-xs text-[var(--amber)]">{{ $group['error'] }} Kaydettiğinizde dosya geçerli biçimde yeniden yazılır.</p>
    @endif

    <section class="mt-7 max-w-2xl rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-5 sm:p-6" aria-label="Grup düzenleme">
        <form class="grid gap-4" method="POST" action="{{ route('model-groups.update', $group['name']) }}">
            @csrf
            @method('PUT')
            @include('model-groups._form', [
                'formId' => 'edit-group',
                'name' => $group['name'],
                'models' => collect($group['models'])->pluck('model')->implode("\n"),
            ])
            <button class="rounded-xl bg-[var(--forest)] px-4 py-3 text-sm font-extrabold text-white" type="submit">Değişiklikleri kaydet</button>
        </form>
    </section>
</div>
@endsection
