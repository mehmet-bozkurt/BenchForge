<?php

namespace App\Services;

use App\Exceptions\BenchmarkRunnerException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

/**
 * Workflow templates, from two places:
 * - built-in templates shipped in Git: `engine/workflow-templates/<id>/vNNN.json` (read-only), and
 * - user templates in the data root: `workflows/<id>/vNNN.json`.
 *
 * Saving never changes an existing file. Every save writes the next version number across both places,
 * so a built-in template saved by the user becomes a user version (v002, v003, …) and runs keep their exact source.
 * Symbolic links are never followed; unreadable or invalid files are listed as invalid and cannot be run.
 */
class WorkflowTemplateStore
{
    public const ID_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/';

    private const VERSION_FILE_PATTERN = '/^v([0-9]{3,9})\.json$/';

    private const BUILTIN_DIRECTORY = 'workflow-templates';

    private const USER_DIRECTORY = 'workflows';

    public function __construct(private WorkflowTemplateValidator $validator) {}

    /**
     * The latest version of every template, sorted by name.
     *
     * @return list<array{id: string, name: string, description: ?string, version: int, source: 'builtin'|'user', stepCount: int, valid: bool, error: ?string}>
     */
    public function all(): array
    {
        $templates = [];

        foreach ($this->catalog() as $id => $versions) {
            $version = (int) array_key_last($versions);
            $templates[] = $this->describe($id, $version, $versions[$version]);
        }

        usort($templates, fn (array $first, array $second): int => strcoll($first['name'], $second['name']) ?: strcmp($first['id'], $second['id']));

        return $templates;
    }

    /**
     * @return list<array{version: int, source: 'builtin'|'user'}>
     */
    public function versions(string $id): array
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return [];
        }

        $versions = [];

        foreach ($this->catalog($id)[$id] ?? [] as $version => $entry) {
            $versions[] = ['version' => $version, 'source' => $entry['source']];
        }

        return $versions;
    }

    /**
     * A valid template version (the latest when no version is given) with its exact file bytes,
     * so a run can snapshot what was validated. Missing, unreadable or invalid versions return null.
     *
     * @return array{template: array<string, mixed>, raw: string, sha256: string, version: int, source: 'builtin'|'user'}|null
     */
    public function find(string $id, ?int $version = null): ?array
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1 || ($version !== null && $version < 1)) {
            return null;
        }

        $versions = $this->catalog($id)[$id] ?? [];
        $version ??= array_key_last($versions);
        $entry = $version === null ? null : ($versions[$version] ?? null);

        if ($entry === null) {
            return null;
        }

        $loaded = $this->load($id, $entry['path']);

        if ($loaded['error'] !== null || $loaded['template'] === null || $loaded['raw'] === null) {
            return null;
        }

        return [
            'template' => $loaded['template'],
            'raw' => $loaded['raw'],
            'sha256' => hash('sha256', $loaded['raw']),
            'version' => $version,
            'source' => $entry['source'],
        ];
    }

    /**
     * Validates the template and writes it as the next version in the data root. Existing versions are never overwritten.
     *
     * @param  array<string, mixed>  $template
     * @return array{template: array<string, mixed>, raw: string, sha256: string, version: int, source: 'builtin'|'user'}
     */
    public function save(array $template): array
    {
        $errors = $this->validator->validate($template);

        if ($errors !== []) {
            throw new BenchmarkRunnerException($errors[0], ['errors' => $errors]);
        }

        $id = (string) $template['id'];

        return $this->withLock(function () use ($template, $id): array {
            $directory = $this->writableTemplateDirectory($id);
            $builtinDirectory = $this->builtinDirectory();
            $existing = [
                ...$this->versionNumbers($directory),
                ...($builtinDirectory === null ? [] : $this->versionNumbers($builtinDirectory.DIRECTORY_SEPARATOR.$id)),
            ];
            $version = ($existing === [] ? 0 : max($existing)) + 1;
            $template['version'] = $version;
            $raw = json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;

            if (strlen($raw) > WorkflowTemplateValidator::MAX_TEMPLATE_BYTES) {
                throw new BenchmarkRunnerException('Şablon 512 KB sınırını aşıyor.');
            }

            $this->writeNewFile($directory.DIRECTORY_SEPARATOR.sprintf('v%03d.json', $version), $raw);
            $saved = $this->find($id, $version);

            if ($saved === null) {
                throw new BenchmarkRunnerException('Şablon kaydedildi ama yeniden okunamadı.');
            }

            return $saved;
        });
    }

    /**
     * Template versions by id; a user file wins over a built-in file with the same version number.
     *
     * @return array<string, array<int, array{source: 'builtin'|'user', path: string}>>
     */
    private function catalog(?string $onlyId = null): array
    {
        $catalog = [];

        foreach (['builtin' => $this->builtinDirectory(), 'user' => $this->userDirectory()] as $source => $directory) {
            if ($directory === null) {
                continue;
            }

            foreach ($onlyId === null ? $this->entries($directory) : [$onlyId] as $id) {
                $templateDirectory = $directory.DIRECTORY_SEPARATOR.$id;

                if (preg_match(self::ID_PATTERN, $id) !== 1 || ! $this->isCanonicalDirectory($templateDirectory)) {
                    continue;
                }

                foreach ($this->versionNumbers($templateDirectory) as $version) {
                    $path = $templateDirectory.DIRECTORY_SEPARATOR.sprintf('v%03d.json', $version);

                    if (! is_link($path) && is_file($path)) {
                        $catalog[$id][$version] = ['source' => $source, 'path' => $path];
                    }
                }
            }
        }

        return array_map(function (array $versions): array {
            ksort($versions);

            return $versions;
        }, $catalog);
    }

    /**
     * @param  array{source: 'builtin'|'user', path: string}  $entry
     * @return array{id: string, name: string, description: ?string, version: int, source: 'builtin'|'user', stepCount: int, valid: bool, error: ?string}
     */
    private function describe(string $id, int $version, array $entry): array
    {
        $loaded = $this->load($id, $entry['path']);
        $template = $loaded['decoded'];
        $name = is_array($template) ? ($template['name'] ?? null) : null;
        $description = is_array($template) ? ($template['description'] ?? null) : null;
        $steps = is_array($template) ? ($template['steps'] ?? null) : null;

        return [
            'id' => $id,
            'name' => is_string($name) && trim($name) !== '' ? $name : $id,
            'description' => is_string($description) && trim($description) !== '' ? $description : null,
            'version' => $version,
            'source' => $entry['source'],
            'stepCount' => is_array($steps) && array_is_list($steps) ? count($steps) : 0,
            'valid' => $loaded['error'] === null,
            'error' => $loaded['error'],
        ];
    }

    /**
     * @return array{raw: ?string, decoded: mixed, template: ?array<string, mixed>, error: ?string}
     */
    private function load(string $id, string $path): array
    {
        $raw = $this->readTemplateFile($path);

        if ($raw === null) {
            return ['raw' => null, 'decoded' => null, 'template' => null, 'error' => 'Şablon dosyası okunamadı ya da 512 KB sınırını aşıyor.'];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['raw' => $raw, 'decoded' => null, 'template' => null, 'error' => 'Şablon dosyası geçerli bir JSON değil.'];
        }

        $errors = $this->validator->validate($decoded);

        if ($errors === [] && $decoded['id'] !== $id) {
            $errors[] = "id: Şablon kimliği ({$decoded['id']}) bulunduğu klasörün adıyla ({$id}) aynı olmalı.";
        }

        return [
            'raw' => $raw,
            'decoded' => $decoded,
            'template' => $errors === [] ? $decoded : null,
            'error' => $errors[0] ?? null,
        ];
    }

    private function readTemplateFile(string $path): ?string
    {
        if (is_link($path) || realpath($path) !== $path || ! is_file($path)) {
            return null;
        }

        $size = filesize($path);

        if ($size === false || $size > WorkflowTemplateValidator::MAX_TEMPLATE_BYTES) {
            return null;
        }

        $raw = file_get_contents($path);

        return $raw === false ? null : $raw;
    }

    /**
     * Version numbers of every `vNNN.json` name in the directory, whatever the entry is, so a new version never reuses one.
     *
     * @return list<int>
     */
    private function versionNumbers(string $directory): array
    {
        if (! $this->isCanonicalDirectory($directory)) {
            return [];
        }

        $versions = [];

        foreach ($this->entries($directory) as $name) {
            if (preg_match(self::VERSION_FILE_PATTERN, $name, $matches) !== 1) {
                continue;
            }

            $version = (int) $matches[1];

            if ($version >= 1 && sprintf('v%03d.json', $version) === $name) {
                $versions[] = $version;
            }
        }

        sort($versions);

        return $versions;
    }

    /**
     * @return list<string> names in the directory, without dot entries and hidden (temporary) files
     */
    private function entries(string $directory): array
    {
        $names = scandir($directory);

        return $names === false
            ? []
            : array_values(array_filter($names, fn (string $name): bool => ! str_starts_with($name, '.')));
    }

    private function builtinDirectory(): ?string
    {
        $enginePath = realpath(rtrim((string) config('benchforge.engine_path'), DIRECTORY_SEPARATOR));

        if ($enginePath === false) {
            return null;
        }

        $directory = $enginePath.DIRECTORY_SEPARATOR.self::BUILTIN_DIRECTORY;

        return $this->isCanonicalDirectory($directory) ? $directory : null;
    }

    /**
     * The user template directory for reading; null while it does not exist or when it is not a plain directory.
     */
    private function userDirectory(): ?string
    {
        $root = $this->dataRoot();

        if ($root === null) {
            return null;
        }

        $directory = $root.DIRECTORY_SEPARATOR.self::USER_DIRECTORY;

        return $this->isCanonicalDirectory($directory) ? $directory : null;
    }

    /**
     * Creates `workflows/<id>` on first use and refuses symbolic links at both levels.
     */
    private function writableTemplateDirectory(string $id): string
    {
        $root = $this->dataRoot();

        if ($root === null) {
            throw new BenchmarkRunnerException('Veri kökü bulunamadı.');
        }

        $directory = $root.DIRECTORY_SEPARATOR.self::USER_DIRECTORY;
        $this->ensurePlainDirectory($directory, self::USER_DIRECTORY);
        $templateDirectory = $directory.DIRECTORY_SEPARATOR.$id;
        $this->ensurePlainDirectory($templateDirectory, self::USER_DIRECTORY.'/'.$id);

        return $templateDirectory;
    }

    private function ensurePlainDirectory(string $path, string $displayPath): void
    {
        if (is_link($path)) {
            throw new BenchmarkRunnerException("Şablon klasörü sembolik bağlantı olamaz: {$displayPath}");
        }

        if (! file_exists($path)) {
            File::ensureDirectoryExists($path, 0755, false);
        }

        if (! $this->isCanonicalDirectory($path)) {
            throw new BenchmarkRunnerException("Şablon klasörü kullanılamıyor: {$displayPath}");
        }
    }

    private function dataRoot(): ?string
    {
        $configured = rtrim((string) config('benchforge.root_path'), DIRECTORY_SEPARATOR);
        $root = $configured === '' ? false : realpath($configured);

        return $root === false || ! is_dir($root) ? null : $root;
    }

    private function isCanonicalDirectory(string $path): bool
    {
        return ! is_link($path) && realpath($path) === $path && is_dir($path);
    }

    /**
     * Writes a complete temporary file, then links it to the target name, which fails instead of replacing an existing file.
     */
    private function writeNewFile(string $path, string $content): void
    {
        $temporary = dirname($path).DIRECTORY_SEPARATOR.'.'.basename($path).'.'.Str::lower(Str::random(8)).'.tmp';

        try {
            if (File::put($temporary, $content, true) !== strlen($content)) {
                throw new BenchmarkRunnerException('Şablon dosyası yazılamadı.');
            }

            @chmod($temporary, 0644);

            if (! @link($temporary, $path)) {
                throw new BenchmarkRunnerException(file_exists($path) || is_link($path)
                    ? 'Bu şablon sürümü zaten var; mevcut dosyanın üzerine yazılmadı.'
                    : 'Şablon dosyası yazılamadı.');
            }
        } finally {
            File::delete($temporary);
        }
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    private function withLock(callable $callback): mixed
    {
        try {
            return Cache::lock('benchforge:workflow-templates', 30)->block(5, $callback);
        } catch (LockTimeoutException) {
            throw new BenchmarkRunnerException('Başka bir şablon işlemi devam ediyor. Biraz sonra yeniden deneyin.');
        } catch (BenchmarkRunnerException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw new BenchmarkRunnerException('Şablon dosyası kaydedilemedi.');
        }
    }
}
