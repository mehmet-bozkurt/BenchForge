<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Throwable;

class BenchmarkPreflight
{
    public function __construct(
        private ProviderConnectionStatus $providerConnectionStatus,
        private BenchmarkProcessEnvironment $processEnvironment,
    ) {}

    /**
     * @param  list<int>  $ports
     * @param  list<string>  $providers
     * @return array{
     *     ready: bool,
     *     checkedAt: string,
     *     summary: string,
     *     checks: list<array{key: string, label: string, status: 'pass'|'fail', message: string}>
     * }
     */
    public function inspect(array $ports, array $providers = []): array
    {
        $checks = [
            $this->databaseCheck(),
            $this->projectCheck(),
            $this->storageCheck(),
            $this->nodeCheck(),
            $this->openCodeCheck(),
            $this->requiredCommandsCheck(),
            $this->processControlCheck(),
        ];

        if (in_array('openrouter', $providers, true)) {
            $checks[] = $this->providerCredentialCheck('openrouter');
        }

        if (in_array('opencode-go', $providers, true)) {
            $checks[] = $this->providerCredentialCheck('opencode-go');
        }

        $checks[] = $this->portsCheck($ports);
        $passed = count(array_filter($checks, fn (array $check): bool => $check['status'] === 'pass'));
        $total = count($checks);

        return [
            'ready' => $passed === $total,
            'checkedAt' => now()->toIso8601String(),
            'summary' => $passed === $total
                ? "{$total}/{$total} kontrol geçti. Benchmark başlatılabilir."
                : "{$passed}/{$total} kontrol geçti. Başlatmadan önce sorunları giderin.",
            'checks' => $checks,
        ];
    }

    /** @return array<string, mixed> */
    public function connections(): array
    {
        return $this->providerConnectionStatus->inspect();
    }

    /**
     * @return array{key: string, label: string, status: 'pass'|'fail', message: string}
     */
    private function databaseCheck(): array
    {
        try {
            DB::connection()->select('SELECT 1');
            $driver = DB::connection()->getDriverName();

            return $this->pass('database', 'Veritabanı', strtoupper($driver).' bağlantısı hazır.');
        } catch (Throwable) {
            return $this->fail('database', 'Veritabanı', 'Bağlantı kurulamadı. Veritabanı yapılandırmasını ve erişim izinlerini kontrol edin.');
        }
    }

    /**
     * @return array{key: string, label: string, status: 'pass'|'fail', message: string}
     */
    private function projectCheck(): array
    {
        $root = realpath((string) config('benchforge.root_path'));

        if ($root === false || ! is_dir($root)) {
            return $this->fail('project', 'Benchmark projesi', 'Benchmark kök klasörü bulunamadı.');
        }

        foreach (['prompts', 'config', 'runs', 'sablon'] as $directory) {
            if (! is_dir($root.DIRECTORY_SEPARATOR.$directory)) {
                return $this->fail('project', 'Benchmark projesi', "{$directory} klasörü bulunamadı.");
            }
        }

        $runsPath = realpath((string) config('benchforge.runs_path', $root.'/runs'));

        if ($runsPath === false || $runsPath !== realpath($root.'/runs')) {
            return $this->fail('project', 'Benchmark projesi', 'Sonuç klasörü benchmark kökündeki runs klasörüyle eşleşmiyor.');
        }

        $requiredFiles = [
            (string) config('benchforge.benchmark_script', $root.'/tools/benchmark.zsh'),
            (string) config('benchforge.resume_script', $root.'/tools/resume-model.zsh'),
            $root.'/tools/browser-smoke/check.mjs',
            $root.'/tools/select-opencode-session.mjs',
            $root.'/tools/extract-opencode-event-session.mjs',
            $root.'/tools/benchforge-model-policy.mjs',
        ];

        foreach ($requiredFiles as $file) {
            if (! is_file($file) || ! is_readable($file)) {
                return $this->fail('project', 'Benchmark projesi', basename($file).' bulunamadı veya okunamıyor.');
            }
        }

        return $this->pass('project', 'Benchmark projesi', 'Şablon, sonuç klasörü ve gerekli benchmark araçları hazır.');
    }

    /**
     * @return array{key: string, label: string, status: 'pass'|'fail', message: string}
     */
    private function storageCheck(): array
    {
        $runsPath = (string) config('benchforge.runs_path', rtrim((string) config('benchforge.root_path'), DIRECTORY_SEPARATOR).'/runs');

        if (! is_dir($runsPath) || ! is_writable($runsPath)) {
            return $this->fail('storage', 'Çalışma alanı', 'Sonuç klasörüne yazma izni yok.');
        }

        $runnerPath = (string) config('benchforge.runner_path');
        $writablePath = $runnerPath;

        while (! is_dir($writablePath) && dirname($writablePath) !== $writablePath) {
            $writablePath = dirname($writablePath);
        }

        if (! is_dir($writablePath) || ! is_writable($writablePath)) {
            return $this->fail('storage', 'Çalışma alanı', 'Koşucu klasörüne yazma izni yok.');
        }

        return $this->pass('storage', 'Çalışma alanı', 'Sonuç klasörü, koşucu günlükleri ve durum dosyaları yazılabilir.');
    }

    /**
     * @return array{key: string, label: string, status: 'pass'|'fail', message: string}
     */
    private function nodeCheck(): array
    {
        $nodeBinary = (string) config('benchforge.node_binary');

        if (! is_file($nodeBinary) || ! is_executable($nodeBinary)) {
            return $this->fail('node', 'Node.js', 'Yapılandırılan Node.js çalıştırıcısı bulunamadı.');
        }

        $pathNode = $this->findExecutable('node');

        if ($pathNode === null || realpath($pathNode) !== realpath($nodeBinary)) {
            return $this->fail('node', 'Node.js', 'Node.js seçimi ile komut yolundaki Node.js eşleşmiyor. Sistem ayarlarını kontrol edin.');
        }

        return $this->versionCheck('node', 'Node.js', $nodeBinary);
    }

    /**
     * @return array{key: string, label: string, status: 'pass'|'fail', message: string}
     */
    private function openCodeCheck(): array
    {
        $status = $this->providerConnectionStatus->inspect()['cli'];

        return $status['status'] === 'pass'
            ? $this->pass('opencode', 'OpenCode', $status['message'])
            : $this->fail('opencode', 'OpenCode', $status['message']);
    }

    /**
     * @return array{key: string, label: string, status: 'pass'|'fail', message: string}
     */
    private function providerCredentialCheck(string $provider): array
    {
        $status = $this->providerConnectionStatus->inspect()['providers'][$provider];

        return $status['status'] === 'pass'
            ? $this->pass($provider, $status['label'], $status['message'])
            : $this->fail($provider, $status['label'], $status['message']);
    }

    /**
     * @return array{key: string, label: string, status: 'pass'|'fail', message: string}
     */
    private function requiredCommandsCheck(): array
    {
        $missing = collect(['npm', 'rsync', 'curl', 'python3', 'shasum'])
            ->reject(fn (string $command): bool => $this->findExecutable($command) !== null)
            ->values()
            ->all();

        if ($missing !== []) {
            return $this->fail('commands', 'Gerekli komutlar', 'Eksik: '.implode(', ', $missing));
        }

        return $this->pass('commands', 'Gerekli komutlar', 'npm, rsync, curl, python3 ve shasum hazır.');
    }

    /**
     * @return array{key: string, label: string, status: 'pass'|'fail', message: string}
     */
    private function processControlCheck(): array
    {
        if (! in_array(PHP_OS_FAMILY, ['Darwin', 'Linux'], true)) {
            return $this->fail('process_control', 'Süreç kontrolü', 'Benchmark koşucusu yalnız macOS ve Linux için desteklenir.');
        }

        $shell = $this->findExecutable((string) config('benchforge.shell_binary', '/bin/zsh'));

        if (! function_exists('posix_kill') || $shell === null) {
            return $this->fail('process_control', 'Süreç kontrolü', 'Güvenli durdurma için POSIX ve zsh desteği gerekli.');
        }

        try {
            $process = new Process([$shell, '--version'], null, $this->runnerEnvironment());
            $process->setTimeout(3);
            $process->run();

            if (! $process->isSuccessful() || preg_match('/^zsh\s+\d+\.\d+/', trim($process->getOutput())) !== 1) {
                return $this->fail('process_control', 'Süreç kontrolü', 'Yapılandırılan shell çalıştırıcısı zsh olarak doğrulanamadı.');
            }
        } catch (Throwable) {
            return $this->fail('process_control', 'Süreç kontrolü', 'zsh çalıştırılamadı. Shell yolunu ve erişim izinlerini kontrol edin.');
        }

        return $this->pass('process_control', 'Süreç kontrolü', 'Arka plan çalıştırma ve güvenli durdurma destekleniyor.');
    }

    /**
     * @param  list<int>  $ports
     * @return array{key: string, label: string, status: 'pass'|'fail', message: string}
     */
    private function portsCheck(array $ports): array
    {
        if ($ports === []) {
            return $this->fail('ports', 'Model portları', 'Kontrol edilecek model portu bulunamadı.');
        }

        $busyPorts = [];

        foreach ($ports as $port) {
            $socket = @stream_socket_server("tcp://127.0.0.1:{$port}");

            if ($socket === false) {
                $busyPorts[] = $port;

                continue;
            }

            fclose($socket);
        }

        if ($busyPorts !== []) {
            return $this->fail('ports', 'Model portları', 'Kullanımda: '.implode(', ', $busyPorts));
        }

        return $this->pass('ports', 'Model portları', implode(', ', $ports).' kullanılabilir.');
    }

    /**
     * @return array{key: string, label: string, status: 'pass'|'fail', message: string}
     */
    private function versionCheck(string $key, string $label, string $binary): array
    {
        try {
            $process = new Process([$binary, '--version'], null, $this->runnerEnvironment());
            $process->setTimeout(5);
            $process->run();

            if (! $process->isSuccessful()) {
                return $this->fail($key, $label, 'Sürüm bilgisi alınamadı.');
            }

            $version = trim(strtok($process->getOutput(), "\r\n") ?: 'sürüm doğrulandı');

            return $this->pass($key, $label, $version);
        } catch (Throwable) {
            return $this->fail($key, $label, 'Çalıştırılamadı. Çalıştırıcı yolunu ve erişim izinlerini kontrol edin.');
        }
    }

    private function findExecutable(string $command): ?string
    {
        if (str_starts_with($command, DIRECTORY_SEPARATOR)) {
            return is_file($command) && is_executable($command) ? $command : null;
        }

        foreach (explode(PATH_SEPARATOR, (string) config('benchforge.command_path')) as $directory) {
            if ($directory === '') {
                continue;
            }

            $candidate = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$command;

            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<string, string|false>
     */
    private function runnerEnvironment(): array
    {
        return $this->processEnvironment->make();
    }

    /**
     * @return array{key: string, label: string, status: 'pass', message: string}
     */
    private function pass(string $key, string $label, string $message): array
    {
        return compact('key', 'label', 'message') + ['status' => 'pass'];
    }

    /**
     * @return array{key: string, label: string, status: 'fail', message: string}
     */
    private function fail(string $key, string $label, string $message): array
    {
        return compact('key', 'label', 'message') + ['status' => 'fail'];
    }
}
