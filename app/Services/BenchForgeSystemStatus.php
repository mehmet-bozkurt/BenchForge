<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Throwable;

class BenchForgeSystemStatus
{
    public function __construct(private BenchmarkProcessEnvironment $processEnvironment) {}

    /** @return array{ready: bool, checkedAt: string, summary: string, checks: list<array<string, string>>} */
    public function inspect(): array
    {
        $supported = in_array(PHP_OS_FAMILY, ['Darwin', 'Linux'], true);
        $processControl = function_exists('proc_open') && function_exists('posix_kill');
        $checks = [
            $this->check('platform', 'İşletim sistemi', $supported ? 'pass' : 'unsupported',
                $supported ? PHP_OS_FAMILY.' — macOS/Linux tabanlı kurulum.' : 'Bu koşucu Windows ve diğer işletim sistemlerini desteklemiyor.'),
            $this->check('php', 'PHP ve Laravel', version_compare(PHP_VERSION, '8.3.0', '>=') ? 'pass' : 'fail',
                'PHP '.PHP_VERSION.' · Laravel '.app()->version().' · PHP 8.3 veya üzeri gerekli.'),
            $this->check('process_control', 'Arka plan süreçleri', $processControl ? 'pass' : 'fail',
                'proc_open ve POSIX sinyal desteği '.($processControl ? 'hazır.' : 'eksik.')),
            $this->databaseCheck(),
            $this->projectCheck(),
            $this->resultsCheck(),
            $this->storageCheck(),
            $this->check('home', 'OpenCode kullanıcı klasörü', is_dir((string) config('benchforge.home_path')) && is_readable((string) config('benchforge.home_path')) ? 'pass' : 'fail',
                'Kullanıcı klasörü mevcut ve okunabilir olmalı. Kimlik dosyaları bu ekranda okunmaz.', (string) config('benchforge.home_path')),
        ];

        if ($supported) {
            foreach ([
                ['node', 'Node.js', (string) config('benchforge.node_binary')],
                ['zsh', 'zsh', (string) config('benchforge.shell_binary')],
                ['npm', 'npm', 'npm'],
                ['opencode', 'OpenCode CLI', 'opencode'],
                ['rsync', 'rsync', 'rsync'],
                ['curl', 'curl', 'curl'],
                ['python3', 'Python', 'python3'],
                ['shasum', 'SHA doğrulama', 'shasum'],
            ] as [$key, $label, $binary]) {
                $checks[] = $this->toolCheck($key, $label, $binary);
            }

            $node = $this->executable((string) config('benchforge.node_binary'));
            $pathNode = $this->executable('node');
            $sameNode = $node !== null && $node === $pathNode;
            $checks[] = $this->check('node_path', 'Node.js / komut yolları tutarlılığı', $sameNode ? 'pass' : 'fail',
                $sameNode ? 'Alt süreçler aynı Node.js çalıştırıcısını kullanacak.' : 'Seçili Node.js ile komut yollarındaki node eşleşmiyor.');
        }

        $playwright = rtrim((string) config('benchforge.engine_path'), '/').'/tools/browser-smoke/node_modules/playwright/package.json';
        $checks[] = $this->check('playwright', 'Browser runtime bağımlılığı', is_readable($playwright) ? 'warning' : 'fail',
            is_readable($playwright)
                ? 'Playwright paketi mevcut. Chromium açılışı ve işletim sistemi bağımlılıkları burada test edilmez.'
                : 'engine/tools/browser-smoke içinde Playwright bağımlılıkları kurulmamış.');
        $checks[] = $this->check('isolation', 'Kod çalıştırma sınırı', 'warning',
            'OS/container izolasyonu henüz yok. Üretilen projelerin npm işlemleri bu bilgisayarın kullanıcı yetkileriyle çalışır.');
        $ready = ! collect($checks)->contains(fn (array $check): bool => in_array($check['status'], ['fail', 'unsupported'], true));

        return [
            'ready' => $ready,
            'checkedAt' => now()->toIso8601String(),
            'summary' => $ready
                ? 'Temel kurulum kontrolleri geçti. Uyarıları okuyun; bu sonuç model bağlantısı, boş port veya benchmark başarısı garantisi değildir.'
                : 'Kurulumda giderilmesi gereken eksikler var. Hata ve destek sınırlarını aşağıda inceleyin.',
            'checks' => $checks,
        ];
    }

    /** @return array<string, string> */
    private function databaseCheck(): array
    {
        try {
            DB::connection()->select('SELECT 1');

            foreach (['benchmark_runs', 'benchmark_executions', 'model_run_attempts', 'preparation_comparisons', 'preparation_packages'] as $table) {
                if (! Schema::hasTable($table)) {
                    return $this->check('database', 'Veritabanı', 'fail', 'Bağlantı hazır; uygulama tabloları eksik. Yerel veritabanı kurulumunu (migration) tamamlayın.');
                }
            }

            return $this->check('database', 'Veritabanı', 'pass', strtoupper(DB::connection()->getDriverName()).' bağlantısı hazır.');
        } catch (Throwable) {
            return $this->check('database', 'Veritabanı', 'fail', 'Bağlantı kurulamadı. Veritabanı kurulumunu ve uygulama yapılandırmasını kontrol edin.');
        }
    }

    /** @return array<string, string> */
    private function projectCheck(): array
    {
        $root = rtrim((string) config('benchforge.root_path'), '/');
        $missing = [];

        $enginePath = rtrim((string) config('benchforge.engine_path'), '/');

        foreach (['prompts', 'config', 'runs'] as $directory) {
            if (! is_dir($root.'/'.$directory) || ! is_readable($root.'/'.$directory)) {
                $missing[] = $directory;
            }
        }

        foreach (BenchForgeSettings::REQUIRED_ENGINE_FILES as $file) {
            if (! is_file($enginePath.'/'.$file) || ! is_readable($enginePath.'/'.$file)) {
                $missing[] = 'engine/'.$file;
            }
        }

        return $this->check('project', 'Benchmark kaynakları', $missing === [] ? 'pass' : 'fail',
            $missing === [] ? 'Şablon, prompt, konfigürasyon ve koşucu araçları mevcut.' : 'Eksik veya okunamayan: '.implode(', ', $missing), $root);
    }

    /** @return array<string, string> */
    private function resultsCheck(): array
    {
        $expected = realpath(rtrim((string) config('benchforge.root_path'), '/').'/runs');
        $actual = realpath((string) config('benchforge.runs_path'));
        $ready = $expected !== false && $expected === $actual && is_writable($actual);

        return $this->check('results', 'Sonuç klasörü', $ready ? 'pass' : 'fail',
            $ready ? 'Üretim ve arşiv aynı yazılabilir root/runs klasörünü kullanıyor.' : 'Sonuç yolu root/runs ile aynı olmalı ve yazılabilmeli.',
            (string) config('benchforge.runs_path'));
    }

    /** @return array<string, string> */
    private function storageCheck(): array
    {
        foreach (['runner_path', 'preparation_path', 'settings_path'] as $key) {
            $path = (string) config('benchforge.'.$key);

            if ($path === '') {
                return $this->check('storage', 'Özel uygulama depoları', 'fail', 'Özel depolama yolu tanımlanmamış.');
            }

            $directory = $key === 'settings_path' ? dirname($path) : $path;

            while (! is_dir($directory) && dirname($directory) !== $directory) {
                $directory = dirname($directory);
            }

            if (! is_writable($directory)) {
                return $this->check('storage', 'Özel uygulama depoları', 'fail', 'Koşucu, hazırlık veya ayar deposuna yazma izni yok.');
            }
        }

        return $this->check('storage', 'Özel uygulama depoları', 'pass', 'Günlük, hazırlık paketi ve özel ayar depoları yazılabilir.');
    }

    /** @return array<string, string> */
    private function toolCheck(string $key, string $label, string $binary): array
    {
        $resolved = $this->executable($binary);

        if ($resolved === null) {
            return $this->check($key, $label, 'fail', 'Çalıştırıcı bulunamadı; ilgili yolu veya komut yollarını kontrol edin.', $binary);
        }

        try {
            $result = Process::path(base_path())->env($this->processEnvironment->make())
                ->timeout(3)->run([$resolved, '--version']);

            if (! $result->successful() || preg_match('/(?<![\w])v?(\d+\.\d+(?:\.\d+)?)/', $result->output(), $matches) !== 1) {
                return $this->check($key, $label, 'fail', 'Sürüm bilgisi doğrulanamadı.', $resolved);
            }

            $version = $matches[1];

            if ($key === 'zsh' && preg_match('/^zsh\s+\d+\.\d+/m', $result->output()) !== 1) {
                return $this->check($key, $label, 'fail', 'Seçilen çalıştırıcı zsh değil. Benchmark betikleri başka bir kabukla çalıştırılamaz.', $resolved);
            }

            if ($key === 'node' && ! ((str_starts_with($version, '20.') && version_compare($version, '20.19.0', '>=')) || version_compare($version, '22.12.0', '>='))) {
                return $this->check($key, $label, 'fail', $version.' — mevcut Vite/Next araçları için Node 20.19+ veya 22.12+ gerekli.', $resolved);
            }

            if ($key === 'opencode' && ! str_starts_with($version, '1.')) {
                return $this->check($key, $label, 'warning', $version.' — mevcut entegrasyon OpenCode 1.x ile doğrulandı; bu sürümün uyumluluğu ayrıca test edilmeli.', $resolved);
            }

            return $this->check($key, $label, 'pass', 'Sürüm: '.$version, $resolved);
        } catch (Throwable) {
            return $this->check($key, $label, 'fail', 'Sürüm kontrolü çalıştırılamadı veya süre sınırını aştı.', $resolved);
        }
    }

    private function executable(string $command): ?string
    {
        $candidates = str_starts_with($command, '/') ? [$command] : array_map(
            fn (string $directory): string => rtrim($directory, '/').'/'.$command,
            array_filter(explode(PATH_SEPARATOR, (string) config('benchforge.command_path')), fn (string $directory): bool => str_starts_with($directory, '/')),
        );

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return realpath($candidate) ?: null;
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private function check(string $key, string $label, string $status, string $message, string $detail = ''): array
    {
        return compact('key', 'label', 'status', 'message', 'detail');
    }
}
