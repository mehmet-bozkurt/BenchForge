<?php

namespace App\Services;

use App\Exceptions\BenchmarkRunnerException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Saved model groups are the `*.conf` files in the data root's `config/` directory.
 * Each line is `folder|provider/model|port`; comment lines and `# max_parallel=N` are kept as written.
 * Past runs keep their own `_modeller.conf` copy, so editing or deleting a group never changes them.
 */
class ModelGroupStore
{
    public const NAME_PATTERN = '/^[a-z0-9][a-z0-9-]{1,62}$/';

    private const MODEL_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*\/[^\s|]+$/';

    private const FIRST_PORT = 3251;

    private const TRASH_DIRECTORY = '.silinen';

    /**
     * @return list<array{name: string, label: string, models: list<array{folder: string, model: string, port: int}>, valid: bool, error: ?string, updatedAt: Carbon}>
     */
    public function all(): array
    {
        $directory = $this->configDirectory();

        return collect(File::files($directory))
            ->filter(fn (\SplFileInfo $file): bool => $file->isFile() && str_ends_with($file->getFilename(), '.conf'))
            ->map(fn (\SplFileInfo $file): array => $this->describe($file->getFilename()))
            ->sortBy('name', SORT_NATURAL)
            ->values()
            ->all();
    }

    /**
     * @return array{name: string, label: string, models: list<array{folder: string, model: string, port: int}>, valid: bool, error: ?string, updatedAt: Carbon}
     */
    public function find(string $name): array
    {
        $path = $this->existingPath($name);

        return $this->describe(basename($path));
    }

    /**
     * Creates a group, or replaces the models of an existing one (optionally under a new name).
     * Models that stay in the group keep their folder and port, so their run folders keep the same name.
     *
     * @param  list<string>  $models
     */
    public function save(string $name, array $models, ?string $originalName = null): void
    {
        $name = trim($name);

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new BenchmarkRunnerException('Grup adı 2–63 karakter olmalı; yalnızca küçük harf, sayı ve tire kullanın.');
        }

        $models = $this->normalizeModels($models);

        $this->withLock(function () use ($name, $models, $originalName): void {
            $directory = $this->configDirectory();
            $targetPath = $directory.DIRECTORY_SEPARATOR.$name.'.conf';
            $originalPath = $originalName === null ? null : $this->existingPath($originalName);
            $renaming = $originalPath !== null && $originalPath !== $targetPath;

            if (($originalPath === null || $renaming) && File::exists($targetPath)) {
                throw new BenchmarkRunnerException("“{$name}” adında bir grup zaten var.");
            }

            $previous = $originalPath === null ? ['comments' => [], 'entries' => []] : $this->read($originalPath);
            $contents = $this->render($models, $previous['comments'], $previous['entries']);

            $temporaryPath = $directory.DIRECTORY_SEPARATOR.'.'.$name.'.conf.'.Str::lower(Str::random(8)).'.tmp';
            File::put($temporaryPath, $contents, true);

            if (! @rename($temporaryPath, $targetPath)) {
                File::delete($temporaryPath);

                throw new BenchmarkRunnerException('Grup dosyası yazılamadı.');
            }

            if ($renaming) {
                $this->moveToTrash($originalPath);
            }
        });
    }

    /**
     * Moves the group file to `config/.silinen/` instead of deleting it, so it can be restored by hand.
     */
    public function delete(string $name): void
    {
        $this->withLock(function () use ($name): void {
            $this->moveToTrash($this->existingPath($name));
        });
    }

    /**
     * @param  list<string>  $models
     * @return list<string>
     */
    private function normalizeModels(array $models): array
    {
        $models = array_values(array_unique(array_filter(array_map(
            fn (mixed $model): string => trim((string) $model),
            $models,
        ))));

        if ($models === [] || count($models) > BenchmarkRunner::CUSTOM_MODEL_LIMIT) {
            throw new BenchmarkRunnerException('Bir grupta 1–'.BenchmarkRunner::CUSTOM_MODEL_LIMIT.' model olmalıdır.');
        }

        foreach ($models as $model) {
            if (mb_strlen($model) > 200 || preg_match(self::MODEL_PATTERN, $model) !== 1) {
                throw new BenchmarkRunnerException("Geçersiz model kimliği: {$model}. Biçim “sağlayıcı/model” olmalıdır.");
            }
        }

        return $models;
    }

    /**
     * @param  list<string>  $models
     * @param  list<string>  $comments
     * @param  array<string, array{folder: string, model: string, port: int}>  $previousEntries
     */
    private function render(array $models, array $comments, array $previousEntries): string
    {
        $usedPorts = [];
        $usedFolders = [];
        $lines = array_map(
            fn (string $comment): string => preg_replace_callback(
                '/^#\s*max_parallel=(\d+)$/',
                fn (array $matches): string => '# max_parallel='.max(1, min((int) $matches[1], count($models))),
                $comment,
            ) ?? $comment,
            $comments,
        );

        foreach ($models as $model) {
            if (isset($previousEntries[$model])) {
                $usedPorts[] = $previousEntries[$model]['port'];
                $usedFolders[] = $previousEntries[$model]['folder'];
            }
        }

        $port = self::FIRST_PORT;

        foreach ($models as $model) {
            $entry = $previousEntries[$model] ?? null;

            if ($entry === null) {
                while (in_array($port, $usedPorts, true)) {
                    $port++;
                }

                $folder = $this->folderFor($model);

                if (in_array($folder, $usedFolders, true)) {
                    throw new BenchmarkRunnerException("{$model} için klasör adı başka bir modelle çakışıyor.");
                }

                $entry = ['folder' => $folder, 'model' => $model, 'port' => $port];
                $usedPorts[] = $port;
                $usedFolders[] = $folder;
            }

            $lines[] = "{$entry['folder']}|{$entry['model']}|{$entry['port']}";
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    /**
     * Same folder naming as runs started from the catalogs, so a model keeps one recognisable folder name.
     */
    private function folderFor(string $model): string
    {
        $provider = Str::before($model, '/');
        $modelId = Str::after($model, '/');

        return match ($provider) {
            'openrouter' => 'or-'.Str::limit(Str::slug(Str::afterLast($modelId, '/')) ?: 'model', 36, '').'-'.substr(sha1($modelId), 0, 6),
            'opencode-go' => 'go-'.Str::limit(Str::slug($modelId) ?: 'model', 36, '').'-'.substr(sha1($modelId), 0, 6),
            default => Str::limit(Str::slug($provider.'-'.Str::afterLast($modelId, '/')) ?: 'model', 40, '').'-'.substr(sha1($model), 0, 6),
        };
    }

    /**
     * @return array{name: string, label: string, models: list<array{folder: string, model: string, port: int}>, valid: bool, error: ?string, updatedAt: Carbon}
     */
    private function describe(string $filename): array
    {
        $path = $this->configDirectory().DIRECTORY_SEPARATOR.$filename;
        $name = Str::beforeLast($filename, '.conf');
        $error = null;
        $models = [];

        try {
            $models = array_values($this->read($path)['entries']);
        } catch (BenchmarkRunnerException $exception) {
            $error = $exception->getMessage();
        }

        return [
            'name' => $name,
            'label' => Str::of($name)->replace(['-', '_'], ' ')->title()->toString(),
            'models' => $models,
            'valid' => $error === null && $models !== [],
            'error' => $error ?? ($models === [] ? 'Grupta çalıştırılabilir model yok.' : null),
            'updatedAt' => Carbon::createFromTimestamp(File::lastModified($path)),
        ];
    }

    /**
     * @return array{comments: list<string>, entries: array<string, array{folder: string, model: string, port: int}>}
     */
    private function read(string $path): array
    {
        $comments = [];
        $entries = [];

        foreach (preg_split('/\R/', File::get($path)) ?: [] as $index => $rawLine) {
            $line = trim($rawLine);

            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '#')) {
                $comments[] = $line;

                continue;
            }

            $parts = array_map('trim', explode('|', $line));
            $port = filter_var($parts[2] ?? null, FILTER_VALIDATE_INT);

            if (count($parts) !== 3 || $parts[0] === '' || $parts[1] === '' || $port === false) {
                throw new BenchmarkRunnerException(basename($path).':'.($index + 1).' geçerli bir model kaydı değil.');
            }

            $entries[$parts[1]] = ['folder' => $parts[0], 'model' => $parts[1], 'port' => $port];
        }

        return ['comments' => $comments, 'entries' => $entries];
    }

    private function moveToTrash(string $path): void
    {
        $trash = $this->configDirectory().DIRECTORY_SEPARATOR.self::TRASH_DIRECTORY;
        File::ensureDirectoryExists($trash, 0700);

        $target = $trash.DIRECTORY_SEPARATOR.Str::beforeLast(basename($path), '.conf').'-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(4)).'.conf';

        if (! @rename($path, $target)) {
            throw new BenchmarkRunnerException('Grup dosyası taşınamadı.');
        }
    }

    private function existingPath(string $name): string
    {
        $directory = $this->configDirectory();

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name) !== 1) {
            throw new BenchmarkRunnerException('Model grubu bulunamadı.');
        }

        $path = realpath($directory.DIRECTORY_SEPARATOR.$name.'.conf');

        if ($path === false || ! is_file($path) || dirname($path) !== $directory) {
            throw new BenchmarkRunnerException('Model grubu bulunamadı.');
        }

        return $path;
    }

    private function configDirectory(): string
    {
        $directory = realpath(rtrim((string) config('benchforge.root_path'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'config');

        if ($directory === false || ! is_dir($directory)) {
            throw new BenchmarkRunnerException('Model grubu dizini bulunamadı.');
        }

        return $directory;
    }

    private function withLock(callable $callback): void
    {
        try {
            Cache::lock('benchforge:model-groups', 30)->block(5, $callback);
        } catch (LockTimeoutException) {
            throw new BenchmarkRunnerException('Başka bir grup işlemi devam ediyor. Biraz sonra yeniden deneyin.');
        } catch (BenchmarkRunnerException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw new BenchmarkRunnerException('Grup dosyası güncellenemedi.');
        }
    }
}
