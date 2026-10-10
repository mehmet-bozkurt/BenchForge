<?php

namespace App\Services;

use App\Models\BenchmarkExecution;
use App\Models\BenchmarkRun;
use App\Models\ModelRunAttempt;
use App\Models\PreparationComparison;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class BenchForgeSettings
{
    private const FIELD_LIMITS = [
        'root_path' => 4096,
        'node_binary' => 4096,
        'shell_binary' => 4096,
        'command_path' => 16384,
        'home_path' => 4096,
    ];

    /**
     * The data root holds user content; the runner scripts and template ship in the repository's engine/.
     */
    private const REQUIRED_DIRECTORIES = ['prompts', 'config', 'runs'];

    public const REQUIRED_ENGINE_FILES = [
        'sablon/package.json',
        'tools/benchmark.zsh',
        'tools/resume-model.zsh',
        'tools/browser-smoke/check.mjs',
        'tools/select-opencode-session.mjs',
        'tools/extract-opencode-event-session.mjs',
        'tools/benchforge-model-policy.mjs',
    ];

    /** @return array{root_path: string, node_binary: string, shell_binary: string, command_path: string, home_path: string} */
    public function values(): array
    {
        $values = [];

        foreach (array_keys(self::FIELD_LIMITS) as $field) {
            $values[$field] = (string) config('benchforge.'.$field, '');
        }

        return $values;
    }

    public function apply(): void
    {
        $savedValues = $this->readSavedValues();

        if ($savedValues !== null) {
            foreach ($savedValues as $field => $value) {
                config()->set('benchforge.'.$field, $value);
            }

            $root = rtrim($savedValues['root_path'], DIRECTORY_SEPARATOR);
            config()->set('benchforge.runs_path', $root.'/runs');
        }

        $commandPath = (string) config('benchforge.command_path');

        foreach (['node_binary', 'shell_binary'] as $field) {
            $configured = (string) config('benchforge.'.$field);
            $resolved = $this->resolveExecutable($configured, $commandPath);

            if ($resolved !== null) {
                config()->set('benchforge.'.$field, $resolved);
            }
        }

        $nodeBinary = (string) config('benchforge.node_binary');

        if (str_starts_with($nodeBinary, '/') && is_file($nodeBinary) && is_executable($nodeBinary)) {
            $nodeDirectory = dirname($nodeBinary);
            $directories = array_values(array_filter(
                explode(PATH_SEPARATOR, $commandPath),
                fn (string $directory): bool => $directory !== '' && $directory !== $nodeDirectory,
            ));
            config()->set('benchforge.command_path', implode(PATH_SEPARATOR, [$nodeDirectory, ...$directories]));
        }

        if ($savedValues !== null && config('benchforge.opencode_db_path_derived', false)) {
            config()->set(
                'benchforge.opencode_db_path',
                rtrim((string) config('benchforge.home_path'), '/').'/.local/share/opencode/opencode.db',
            );
        }
    }

    /** @param array<string, mixed> $values */
    public function save(array $values): void
    {
        try {
            Cache::lock('benchforge:runner:start', 120)->block(5, function () use ($values): void {
                Cache::lock('benchforge:preparation:lifecycle', 120)->block(5, function () use ($values): void {
                    $this->apply();

                    if ($this->busy()) {
                        throw new RuntimeException('Çalışan benchmark, kurtarma veya hazırlık tamamlanmadan sistem ayarları değiştirilemez.');
                    }

                    $validated = $this->validateValues($values);
                    $this->guardHistory($validated['root_path']);
                    $this->persist($validated);
                });
            });
        } catch (LockTimeoutException) {
            throw new RuntimeException('Başka bir başlatma veya ayar işlemi devam ediyor. Lütfen tekrar deneyin.');
        }
    }

    public function busy(): bool
    {
        try {
            return BenchmarkExecution::query()->whereIn('status', BenchmarkExecution::ACTIVE_STATUSES)->exists()
                || ModelRunAttempt::query()->whereIn('status', ModelRunAttempt::ACTIVE_STATUSES)->exists()
                || PreparationComparison::query()->whereIn('status', PreparationComparison::ACTIVE_STATUSES)->exists();
        } catch (QueryException) {
            return true;
        }
    }

    /** @return array{root_path: string, node_binary: string, shell_binary: string, command_path: string, home_path: string}|null */
    private function readSavedValues(): ?array
    {
        try {
            $path = $this->settingsPath();

            if (is_link($path) || is_link(dirname($path))) {
                throw new RuntimeException;
            }

            if (! file_exists($path)) {
                return null;
            }

            if (! is_file($path) || ! is_readable($path) || File::size($path) > 65536) {
                throw new RuntimeException;
            }

            $data = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
            $expectedKeys = ['version', ...array_keys(self::FIELD_LIMITS)];
            $actualKeys = is_array($data) ? array_keys($data) : [];
            sort($expectedKeys);
            sort($actualKeys);

            if (! is_array($data) || $actualKeys !== $expectedKeys || ($data['version'] ?? null) !== 1) {
                throw new RuntimeException;
            }

            unset($data['version']);
            $this->assertShape($data);

            foreach (['root_path', 'home_path', 'node_binary', 'shell_binary'] as $field) {
                if (! str_starts_with($data[$field], '/') || rtrim($data[$field], '/') === '') {
                    throw new RuntimeException;
                }
            }

            $this->validateCommandPath($data['command_path']);

            return $data;
        } catch (Throwable) {
            throw new RuntimeException('Kaydedilmiş sistem ayarları okunamıyor veya geçersiz. Ayar dosyasını düzeltmeden çalıştırmaya devam etmeyin.');
        }
    }

    /** @param array<string, mixed> $values */
    private function assertShape(array $values): void
    {
        $actualKeys = array_keys($values);
        $expectedKeys = array_keys(self::FIELD_LIMITS);
        sort($actualKeys);
        sort($expectedKeys);

        if ($actualKeys !== $expectedKeys) {
            throw new RuntimeException('Yalnızca izin verilen beş sistem ayarı kaydedilebilir.');
        }

        foreach (self::FIELD_LIMITS as $field => $limit) {
            $value = $values[$field];

            if (! is_string($value) || $value === '' || strlen($value) > $limit
                || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new RuntimeException('Sistem ayarları boş olmayan, sınırlar içinde ve kontrol karakteri içermeyen metinler olmalıdır.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{root_path: string, node_binary: string, shell_binary: string, command_path: string, home_path: string}
     */
    private function validateValues(array $values): array
    {
        $this->assertShape($values);
        $values['root_path'] = $this->realDirectory($values['root_path'], 'Benchmark kök klasörü');
        $values['home_path'] = $this->realDirectory($values['home_path'], 'Koşucu kullanıcı klasörü');
        $values['node_binary'] = $this->realExecutable($values['node_binary'], 'Node.js');
        $values['shell_binary'] = $this->realExecutable($values['shell_binary'], 'zsh');

        if (preg_match('/^zsh(?:-[0-9][A-Za-z0-9._-]*)?$/', basename($values['shell_binary'])) !== 1) {
            throw new RuntimeException('Koşucu kabuğu zsh olmalıdır; bash veya başka bir kabuk seçilemez.');
        }

        $values['command_path'] = $this->validateCommandPath($values['command_path']);
        $root = $values['root_path'];

        foreach (self::REQUIRED_DIRECTORIES as $directory) {
            $path = $root.'/'.$directory;

            if (! is_dir($path) || ! is_readable($path) || realpath($path) !== $path) {
                throw new RuntimeException('Benchmark veri kökünde okunabilir ve doğrudan prompts, config ve runs klasörleri bulunmalıdır.');
            }
        }

        $enginePath = rtrim((string) config('benchforge.engine_path'), DIRECTORY_SEPARATOR);

        foreach (self::REQUIRED_ENGINE_FILES as $file) {
            if (! is_file($enginePath.'/'.$file) || ! is_readable($enginePath.'/'.$file)) {
                throw new RuntimeException('Uygulamanın engine klasöründe gerekli koşucu, şablon ve doğrulama dosyaları bulunamadı.');
            }
        }

        $nodeDirectory = dirname($values['node_binary']);
        $pathDirectories = array_values(array_filter(
            explode(PATH_SEPARATOR, $values['command_path']),
            fn (string $directory): bool => $directory !== $nodeDirectory,
        ));
        $values['command_path'] = implode(PATH_SEPARATOR, [$nodeDirectory, ...$pathDirectories]);
        $pathNode = $this->resolveExecutable('node', $values['command_path']);

        if ($pathNode === null || realpath($pathNode) !== $values['node_binary']) {
            throw new RuntimeException('Seçilen Node.js ile komut yolundaki node aynı çalıştırılabilir dosya olmalıdır.');
        }

        return $values;
    }

    private function validateCommandPath(string $commandPath): string
    {
        foreach (explode(PATH_SEPARATOR, $commandPath) as $directory) {
            if ($directory === '' || ! str_starts_with($directory, '/')
                || preg_match('/[\x00-\x1F\x7F]/', $directory) === 1) {
                throw new RuntimeException('Komut yolu yalnızca boş olmayan, mutlak klasör yolları içermelidir; göreli yollar kabul edilmez.');
            }
        }

        return $commandPath;
    }

    private function realDirectory(string $path, string $label): string
    {
        $resolved = str_starts_with($path, '/') ? realpath($path) : false;

        if ($resolved === false || $resolved === '/' || ! is_dir($resolved) || ! is_readable($resolved)) {
            throw new RuntimeException($label.' için mevcut, okunabilir ve mutlak bir klasör yolu girin.');
        }

        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    private function realExecutable(string $path, string $label): string
    {
        $resolved = str_starts_with($path, '/') ? realpath($path) : false;

        if ($resolved === false || ! is_file($resolved) || ! is_executable($resolved)) {
            throw new RuntimeException($label.' için çalıştırılabilir dosyanın mevcut mutlak yolunu girin.');
        }

        return $resolved;
    }

    private function resolveExecutable(string $binary, string $commandPath): ?string
    {
        if (str_starts_with($binary, '/')) {
            return is_file($binary) && is_executable($binary) ? $binary : null;
        }

        if ($binary === '' || basename($binary) !== $binary) {
            return null;
        }

        foreach (explode(PATH_SEPARATOR, $commandPath) as $directory) {
            if ($directory === '' || ! str_starts_with($directory, '/')) {
                continue;
            }

            $candidate = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$binary;

            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function guardHistory(string $rootPath): void
    {
        if (! BenchmarkRun::query()->exists() && ! BenchmarkExecution::query()->exists()) {
            return;
        }

        $currentRoot = realpath((string) config('benchforge.root_path'));
        $currentRuns = realpath((string) config('benchforge.runs_path'));

        if ($currentRoot !== $rootPath || $currentRuns !== $rootPath.'/runs') {
            throw new RuntimeException('Geçmiş benchmark kayıtları varken kök veya sonuç klasörü değiştirilemez. Mevcut sonuçları taşımak ayrı bir işlem gerektirir.');
        }
    }

    /** @param array{root_path: string, node_binary: string, shell_binary: string, command_path: string, home_path: string} $values */
    private function persist(array $values): void
    {
        try {
            $path = $this->settingsPath();
            $directory = dirname($path);

            if (is_link($path) || is_link($directory)) {
                throw new RuntimeException;
            }

            File::ensureDirectoryExists($directory, 0700, true);

            if (! is_dir($directory) || ! is_writable($directory)) {
                throw new RuntimeException;
            }

            File::replace(
                $path,
                json_encode(['version' => 1, ...$values], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL,
                0600,
            );
        } catch (Throwable) {
            throw new RuntimeException('Sistem ayarları özel depoya kaydedilemedi. Hiçbir çalışma alanı taşınmadı.');
        }
    }

    private function settingsPath(): string
    {
        $path = (string) config('benchforge.settings_path');

        if ($path === '' || ! str_starts_with($path, '/') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new RuntimeException('Özel sistem ayarları dosyasının yolu geçersiz.');
        }

        return $path;
    }
}
