{{-- Applies the saved theme before first paint, so dark mode never flashes white. --}}
<script>
    (() => {
        let preference = 'system';
        try {
            preference = localStorage.getItem('benchforge-theme') || 'system';
        } catch {}
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        const theme = preference === 'dark' || (preference === 'system' && prefersDark) ? 'dark' : 'light';
        document.documentElement.dataset.theme = theme;
        document.documentElement.dataset.themePreference = preference;
    })();
</script>
