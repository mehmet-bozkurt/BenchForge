<!DOCTYPE html>
<html lang="tr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Yerel yapay zekâ kodlama modeli değerlendirme merkezi">

        <title>{{ $title ?? 'BenchForge Studio' }}</title>
        <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
        <x-theme-script />

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <div class="app-shell">
            <aside class="sidebar">
                <a class="brand" href="{{ route('dashboard') }}" aria-label="BenchForge Studio ana sayfası">
                    <img class="brand-mark" src="{{ asset('brand/benchforge-mark.svg') }}" alt="" width="38" height="38">
                    <span>
                        <strong>BenchForge</strong>
                        <small>Evaluation Lab</small>
                    </span>
                </a>

                <nav class="primary-nav" aria-label="Ana navigasyon">
                    <a class="nav-item {{ request()->routeIs('dashboard', 'runs.*') ? 'nav-item-active' : '' }}" href="{{ route('dashboard') }}">
                        <span aria-hidden="true">◫</span>
                        Genel Bakış
                    </a>
                    <a class="nav-item {{ request()->routeIs('runner.*') ? 'nav-item-active' : '' }}" href="{{ route('runner.create') }}">
                        <span aria-hidden="true">▷</span>
                        Yeni Benchmark
                    </a>
                    <a class="nav-item {{ request()->routeIs('prompts.*') ? 'nav-item-active' : '' }}" href="{{ route('prompts.index') }}">
                        <span aria-hidden="true">¶</span>
                        Promptlar
                    </a>
                    <a class="nav-item {{ request()->routeIs('model-groups.*') ? 'nav-item-active' : '' }}" href="{{ route('model-groups.index') }}">
                        <span aria-hidden="true">≡</span>
                        Model Grupları
                    </a>
                    <a class="nav-item {{ request()->routeIs('preparations.*') ? 'nav-item-active' : '' }}" href="{{ route('preparations.index') }}">
                        <span aria-hidden="true">▧</span>
                        Hazırlık Paketleri
                    </a>
                    <a class="nav-item {{ request()->routeIs('models.*') ? 'nav-item-active' : '' }}" href="{{ route('models.index') }}">
                        <span aria-hidden="true">◇</span>
                        Modeller
                    </a>
                    <a class="nav-item {{ request()->routeIs('system.*') ? 'nav-item-active' : '' }}" href="{{ route('system.index') }}">
                        <span aria-hidden="true">⚙</span>
                        Sistem ve Kurulum
                    </a>
                </nav>

                <div class="theme-switch" role="group" aria-label="Görünüm">
                    <button type="button" data-theme-choice="light" aria-pressed="false" title="Açık tema"><span aria-hidden="true">&#x2600;&#xFE0E;</span>Açık</button>
                    <button type="button" data-theme-choice="dark" aria-pressed="false" title="Koyu tema"><span aria-hidden="true">☾</span>Koyu</button>
                    <button type="button" data-theme-choice="system" aria-pressed="false" title="Sistem ayarını izle"><span aria-hidden="true">◐</span>Sistem</button>
                </div>

                <div class="sidebar-note">
                    <span class="live-dot" aria-hidden="true"></span>
                    <div>
                        <strong>Güvenli yerel mod</strong>
                        <p>Koşular ayrı süreçte başlar; mevcut sonuçların üzerine yazılmaz.</p>
                    </div>
                </div>
            </aside>

            <main class="main-content">
                @yield('content')
            </main>
        </div>
    </body>
</html>
