@extends('layouts.app')

@section('content')
@php
    $statusLabels = [
        'pass' => 'Hazır',
        'fail' => 'Eksik veya hatalı',
        'unsupported' => 'Desteklenmiyor',
        'warning' => 'Kontrol gerekli',
    ];
@endphp

<div class="content-frame">
    <header class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="eyebrow">Yerel geliştirme ortamı</p>
            <h1 class="mt-2 text-3xl font-black tracking-[-0.05em] text-[var(--ink)] sm:text-[38px]">Sistem ve Kurulum</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-[var(--muted)]">
                BenchForge'un ihtiyaç duyduğu araçları ve bilgisayarınıza özel yolları tek yerden kontrol edin.
            </p>
        </div>
        <a class="inline-flex shrink-0 items-center justify-center rounded-xl border border-[var(--line)] bg-[var(--surface)] px-4 py-2.5 text-xs font-extrabold text-[var(--forest)]" href="{{ route('system.index') }}">Durumu yenile</a>
    </header>

    @if (session('status'))
        <div class="mt-6 rounded-xl border border-[var(--success-line)] bg-[var(--success-soft)] px-4 py-3 text-sm text-[var(--success)]" role="status">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mt-6 rounded-xl border border-[var(--red-line)] bg-[var(--red-soft)] px-4 py-3 text-sm text-[var(--red)]" role="alert">
            <strong class="font-extrabold">Ayarlar kaydedilemedi.</strong>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-xs leading-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="mt-7 overflow-hidden rounded-2xl border border-[var(--line)] bg-[var(--surface)]" aria-labelledby="system-readiness-title">
        <div class="flex flex-col gap-4 border-b border-[var(--line)] p-5 sm:flex-row sm:items-start sm:justify-between sm:p-6">
            <div>
                <p class="eyebrow">Kurulum ön kontrolü</p>
                <h2 id="system-readiness-title" class="mt-1 text-xl font-black tracking-[-0.035em]">{{ $system['summary'] }}</h2>
                <p class="mt-2 max-w-3xl text-xs leading-5 text-[var(--muted)]">Araç sürümleri ve erişilebilir yollar kontrol edilir. Model çağrısı veya benchmark başlatılmaz; hazır görünmesi modellerin başarılı sonuç üreteceğini garanti etmez.</p>
                <p class="mt-2 text-[11px] text-[var(--muted)]">Son kontrol: <time datetime="{{ $system['checkedAt'] }}">{{ $system['checkedAt'] }}</time></p>
            </div>
            <span @class([
                'inline-flex self-start rounded-full px-3 py-1.5 text-[10px] font-extrabold whitespace-nowrap',
                'bg-[var(--success-soft)] text-[var(--success)]' => $system['ready'],
                'bg-[var(--amber-soft)] text-[var(--amber)]' => ! $system['ready'],
            ])>{{ $system['ready'] ? 'Kurulum hazır' : 'Kontrol gerekli' }}</span>
        </div>

        <div class="grid gap-3 p-5 sm:p-6 lg:grid-cols-2">
            @foreach ($system['checks'] as $check)
                <article class="min-w-0 rounded-xl border border-[var(--line)] bg-[var(--surface-subtle)] p-4" aria-labelledby="system-check-{{ $loop->index }}">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <h3 id="system-check-{{ $loop->index }}" class="text-sm font-extrabold">{{ $check['label'] }}</h3>
                        <span @class([
                            'rounded-full px-2.5 py-1 text-[10px] font-extrabold',
                            'bg-[var(--success-soft)] text-[var(--success)]' => $check['status'] === 'pass',
                            'bg-[var(--red-soft)] text-[var(--red)]' => in_array($check['status'], ['fail', 'unsupported'], true),
                            'bg-[var(--amber-soft)] text-[var(--amber)]' => $check['status'] === 'warning',
                        ])>{{ $statusLabels[$check['status']] ?? 'Bilinmiyor' }}</span>
                    </div>
                    <p class="mt-2 text-xs leading-5 text-[var(--muted)]">{{ $check['message'] }}</p>
                    @if (! empty($check['detail']))
                        <code class="mt-2 block whitespace-pre-wrap break-all rounded-lg bg-[var(--surface)] px-3 py-2 text-[11px] leading-5 text-[var(--ink)]">{{ $check['detail'] }}</code>
                    @endif
                </article>
            @endforeach
        </div>
    </section>

    <div class="mt-6 grid items-start gap-6 2xl:grid-cols-[minmax(0,1.25fr)_minmax(300px,0.75fr)]">
        <section class="min-w-0 rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-5 sm:p-6" aria-labelledby="system-settings-title">
            <p class="eyebrow">Bilgisayara özel ayarlar</p>
            <h2 id="system-settings-title" class="mt-1 text-xl font-black tracking-[-0.035em]">Çalışma yolları</h2>
            <p class="mt-2 text-xs leading-5 text-[var(--muted)]">Kaydetmek araç yüklemez, servis başlatmaz veya dosya taşımaz. Yeni ayarlar sonraki işlemlerde kullanılır; mevcut koşular ve sonuçlar taşınmaz.</p>

            @if ($busy)
                <div class="mt-5 rounded-xl border border-[var(--amber-line)] bg-[var(--amber-soft)] px-4 py-3 text-xs leading-5 text-[var(--amber-ink)]" role="status">
                    <strong>Ayar değişikliği şu anda kilitli.</strong> Çalışan benchmark, kurtarma veya hazırlık tamamlanmadan ve çalışma kayıtları okunabilir olmadan yollar değiştirilemez. Kontrolleri görüntüleyebilirsiniz.
                </div>
            @endif

            <form class="mt-6" method="POST" action="{{ route('system.update') }}">
                @csrf
                @method('PUT')
                <fieldset class="grid min-w-0 gap-5 disabled:opacity-60" @disabled($busy)>
                    <legend class="sr-only">BenchForge çalışma yolları</legend>

                    <label class="grid min-w-0 gap-2 text-xs font-extrabold" for="system-root-path">
                        Benchmark araçları klasörü
                        <input class="h-11 min-w-0 w-full rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3.5 font-mono text-xs font-medium outline-none focus:border-[var(--forest)]" id="system-root-path" type="text" name="root_path" value="{{ old('root_path', $settings['root_path']) }}" maxlength="4096" required aria-describedby="system-root-help" @error('root_path') aria-invalid="true" @enderror>
                        <span id="system-root-help" class="text-[11px] font-normal leading-5 text-[var(--muted)]">tools, prompts, config ve sablon klasörlerini içeren tam yol. Geçmiş kayıtlar varsa bu kök değiştirilemez; kayıtları başka bilgisayara taşıma bu sürüme dahil değildir.</span>
                    </label>

                    <div class="min-w-0 rounded-xl border border-[var(--line)] bg-[var(--surface-subtle)] px-4 py-3">
                        <p class="text-xs font-extrabold">Sonuç klasörü · otomatik</p>
                        <code class="mt-2 block break-all text-xs text-[var(--muted)]">{{ rtrim($settings['root_path'], '/') }}/runs</code>
                        <p class="mt-2 text-[11px] leading-5 text-[var(--muted)]">Benchmark araçları klasöründen türetilir. Ayrı bir sonuç yolu tanımlanmaz.</p>
                    </div>

                    <label class="grid min-w-0 gap-2 text-xs font-extrabold" for="system-node-binary">
                        Node.js çalıştırıcısı
                        <input class="h-11 min-w-0 w-full rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3.5 font-mono text-xs font-medium outline-none focus:border-[var(--forest)]" id="system-node-binary" type="text" name="node_binary" value="{{ old('node_binary', $settings['node_binary']) }}" maxlength="4096" required aria-describedby="system-node-help" @error('node_binary') aria-invalid="true" @enderror>
                        <span id="system-node-help" class="text-[11px] font-normal leading-5 text-[var(--muted)]">Node.js dosyasının tam yolu. Seçilen dosyanın klasörü komut arama yolunun başına eklenir; alt işlemler aynı Node.js kurulumunu kullanır.</span>
                    </label>

                    <label class="grid min-w-0 gap-2 text-xs font-extrabold" for="system-shell-binary">
                        zsh çalıştırıcısı
                        <input class="h-11 min-w-0 w-full rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3.5 font-mono text-xs font-medium outline-none focus:border-[var(--forest)]" id="system-shell-binary" type="text" name="shell_binary" value="{{ old('shell_binary', $settings['shell_binary']) }}" maxlength="4096" required aria-describedby="system-shell-help" @error('shell_binary') aria-invalid="true" @enderror>
                        <span id="system-shell-help" class="text-[11px] font-normal leading-5 text-[var(--muted)]">zsh dosyasının tam yolu. Mevcut koşucu zsh ve POSIX süreç araçlarına ihtiyaç duyar; Windows desteği bu sürümde yoktur.</span>
                    </label>

                    <label class="grid min-w-0 gap-2 text-xs font-extrabold" for="system-command-path">
                        Komut arama yolu (PATH)
                        <textarea class="min-h-24 min-w-0 w-full resize-y rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3.5 py-3 font-mono text-xs font-medium leading-5 outline-none focus:border-[var(--forest)]" id="system-command-path" name="command_path" maxlength="8192" required aria-describedby="system-command-help" @error('command_path') aria-invalid="true" @enderror>{{ old('command_path', $settings['command_path']) }}</textarea>
                        <span id="system-command-help" class="text-[11px] font-normal leading-5 text-[var(--muted)]">npm, opencode, rsync, curl, python3 ve shasum araçlarının bulunduğu klasörler. Tam yolları iki nokta üst üste (:) ile ayırın; çalıştırılacak bir komut yazmayın.</span>
                    </label>

                    <label class="grid min-w-0 gap-2 text-xs font-extrabold" for="system-home-path">
                        OpenCode kullanıcı klasörü (HOME)
                        <input class="h-11 min-w-0 w-full rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3.5 font-mono text-xs font-medium outline-none focus:border-[var(--forest)]" id="system-home-path" type="text" name="home_path" value="{{ old('home_path', $settings['home_path']) }}" maxlength="4096" required aria-describedby="system-home-help" @error('home_path') aria-invalid="true" @enderror>
                        <span id="system-home-help" class="text-[11px] font-normal leading-5 text-[var(--muted)]">OpenCode bağlantılarının ve oturum verilerinin bulunduğu kullanıcı klasörünün tam yolu. Bu alan API anahtarı değildir; bağlantı kurmaz veya kimlik bilgilerini taşımaz.</span>
                    </label>

                    <button class="runner-primary-action flex min-h-12 items-center justify-center rounded-xl px-5 text-sm font-extrabold text-white disabled:cursor-not-allowed disabled:opacity-50" type="submit" @disabled($busy)>Ayarları kaydet</button>
                </fieldset>
            </form>
        </section>

        <aside class="grid min-w-0 gap-5" aria-label="Kurulum ve güvenlik sınırları">
            <section class="rounded-2xl border border-[var(--amber-line)] bg-[var(--amber-soft)] p-5 sm:p-6" aria-labelledby="system-isolation-title">
                <p class="eyebrow text-[var(--amber)]">Önemli güvenlik sınırı</p>
                <h2 id="system-isolation-title" class="mt-2 text-lg font-black tracking-[-0.03em]">İşletim sistemi izolasyonu yok</h2>
                <p class="mt-3 text-xs leading-6 text-[var(--amber-ink)]">Mevcut model izinleri ve klasör sınırları bir işletim sistemi veya container sandbox'ı değildir. Üretilen projelerin npm, lint ve build işlemleri bilgisayardaki kullanıcı hesabının yetkileriyle çalışır.</p>
                <p class="mt-3 text-xs leading-6 text-[var(--amber-ink)]">Bu ekran kurulum hazırlığını gösterir; güvenli izolasyon, her platformda uyumluluk veya tarayıcı testlerinin çalışacağı garantisini vermez.</p>
            </section>

            <section class="rounded-2xl border border-[var(--line)] bg-[var(--surface)] p-5 sm:p-6" aria-labelledby="system-setup-title">
                <p class="eyebrow">Eksik araç varsa</p>
                <h2 id="system-setup-title" class="mt-2 text-lg font-black tracking-[-0.03em]">Önce kurulum, sonra kontrol</h2>
                <ol class="mt-4 grid gap-3 text-xs leading-5 text-[var(--muted)]">
                    <li><strong class="text-[var(--ink)]">1.</strong> Eksik aracı bilgisayarınıza kendiniz kurun. BenchForge bu sayfadan kurulum veya güncelleme yapmaz.</li>
                    <li><strong class="text-[var(--ink)]">2.</strong> Araç yollarını kontrol edip ayarları kaydedin. Anahtarlarınızı bu formdaki alanlara yazmayın.</li>
                    <li><strong class="text-[var(--ink)]">3.</strong> Durumu yenileyin. Yeni bir koşu başlatmadan önce Yeni Benchmark ekranındaki başlatma ön kontrolünü de kullanın.</li>
                </ol>
            </section>
        </aside>
    </div>
</div>
@endsection
