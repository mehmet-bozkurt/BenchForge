<?php

namespace App\Services;

use App\Exceptions\BenchmarkRunnerException;
use App\Models\BenchmarkExecution;
use App\Models\BenchmarkPrompt;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Task prompts offered by the runner, from two places:
 * - `prompts/*.txt` files in the data root (new prompts are created here), and
 * - library prompts saved from the runner's custom prompt form (database).
 *
 * Every run copies its prompt when it starts, so editing or deleting a prompt never changes past runs.
 * Deleted files move to `prompts/.silinen/`; deleted library prompts are only deactivated.
 */
class PromptLibrary
{
    public const NAME_PATTERN = '/^[a-z0-9][a-z0-9-]{1,79}$/';

    public const MIN_LENGTH = 20;

    public const MAX_LENGTH = 60000;

    private const LIBRARY_PREFIX = 'kutuphane-';

    private const TRASH_DIRECTORY = '.silinen';

    /** Turns a free-form title such as "Plush Octopus v2" into a file name ("plush-octopus-v2"). */
    public static function normalizeName(string $title): string
    {
        return Str::limit(Str::slug($title), 80, '');
    }

    /**
     * @return list<array{key: string, value: string, title: string, source: 'file'|'library', location: string, content: string, characters: int, words: int, updatedAt: Carbon, inUse: bool}>
     */
    public function all(): array
    {
        $directory = $this->directory();
        $files = collect(File::files($directory))
            ->filter(fn (\SplFileInfo $file): bool => $file->isFile() && str_ends_with($file->getFilename(), '.txt'))
            ->map(fn (\SplFileInfo $file): array => $this->describeFile($file->getFilename()));
        $library = BenchmarkPrompt::query()
            ->where('active', true)
            ->get()
            ->map(fn (BenchmarkPrompt $prompt): array => $this->describeLibrary($prompt));

        return $files->concat($library)
            ->sortBy(fn (array $prompt): string => Str::lower($prompt['title']), SORT_NATURAL)
            ->values()
            ->all();
    }

    /**
     * @return array{key: string, value: string, title: string, source: 'file'|'library', location: string, content: string, characters: int, words: int, updatedAt: Carbon, inUse: bool}
     */
    public function find(string $key): array
    {
        if (str_starts_with($key, self::LIBRARY_PREFIX)) {
            return $this->describeLibrary($this->libraryPrompt($key));
        }

        return $this->describeFile(basename($this->existingFile($key)));
    }

    /** Creates a new prompt file and returns its key. */
    public function create(string $title, string $content): string
    {
        $name = $this->validName($title);
        $content = $this->validContent($content);

        $this->withLock(function () use ($name, $content): void {
            $path = $this->directory().DIRECTORY_SEPARATOR.$name.'.txt';

            if (File::exists($path)) {
                throw new BenchmarkRunnerException("“{$name}” adında bir prompt zaten var.");
            }

            $this->write($path, $content);
        });

        return $name;
    }

    /** Updates a prompt; a file prompt whose title changed is renamed (the old file goes to the trash). Returns the new key. */
    public function update(string $key, string $title, string $content): string
    {
        $content = $this->validContent($content);

        if (str_starts_with($key, self::LIBRARY_PREFIX)) {
            $prompt = $this->libraryPrompt($key);
            $title = trim($title);

            if ($title === '' || mb_strlen($title) > 120) {
                throw new BenchmarkRunnerException('Başlık 1–120 karakter olmalıdır.');
            }

            $prompt->update(['title' => $title, 'content' => $content, 'content_hash' => hash('sha256', $content)]);

            return $key;
        }

        $name = $this->validName($title);

        $this->withLock(function () use ($key, $name, $content): void {
            $current = $this->existingFile($key);
            $this->ensureNotInUse('prompts/'.basename($current));
            $target = $this->directory().DIRECTORY_SEPARATOR.$name.'.txt';
            $renaming = $target !== $current;

            if ($renaming && File::exists($target)) {
                throw new BenchmarkRunnerException("“{$name}” adında bir prompt zaten var.");
            }

            $this->write($target, $content);

            if ($renaming) {
                $this->moveToTrash($current);
            }
        });

        return $name;
    }

    public function delete(string $key): void
    {
        if (str_starts_with($key, self::LIBRARY_PREFIX)) {
            $this->libraryPrompt($key)->update(['active' => false]);

            return;
        }

        $this->withLock(function () use ($key): void {
            $path = $this->existingFile($key);
            $this->ensureNotInUse('prompts/'.basename($path));
            $this->moveToTrash($path);
        });
    }

    /**
     * @return array{key: string, value: string, title: string, source: 'file', location: string, content: string, characters: int, words: int, updatedAt: Carbon, inUse: bool}
     */
    private function describeFile(string $filename): array
    {
        $path = $this->directory().DIRECTORY_SEPARATOR.$filename;
        $content = File::get($path);
        $name = Str::beforeLast($filename, '.txt');

        return [
            'key' => $name,
            'value' => 'prompts/'.$filename,
            'title' => Str::of($name)->replace(['-', '_'], ' ')->title()->toString(),
            'source' => 'file',
            'location' => 'prompts/'.$filename,
            'content' => $content,
            'characters' => mb_strlen($content),
            'words' => str_word_count(Str::ascii($content)),
            'updatedAt' => Carbon::createFromTimestamp(File::lastModified($path)),
            'inUse' => $this->inUse('prompts/'.$filename),
        ];
    }

    /**
     * @return array{key: string, value: string, title: string, source: 'library', location: string, content: string, characters: int, words: int, updatedAt: Carbon, inUse: bool}
     */
    private function describeLibrary(BenchmarkPrompt $prompt): array
    {
        return [
            'key' => self::LIBRARY_PREFIX.$prompt->id,
            'value' => 'library:'.$prompt->id,
            'title' => $prompt->title,
            'source' => 'library',
            'location' => 'Yeni Benchmark ekranından kaydedildi',
            'content' => $prompt->content,
            'characters' => mb_strlen($prompt->content),
            'words' => str_word_count(Str::ascii($prompt->content)),
            'updatedAt' => Carbon::parse($prompt->updated_at),
            'inUse' => false,
        ];
    }

    private function libraryPrompt(string $key): BenchmarkPrompt
    {
        $id = filter_var(Str::after($key, self::LIBRARY_PREFIX), FILTER_VALIDATE_INT);
        $prompt = $id === false ? null : BenchmarkPrompt::query()->whereKey($id)->where('active', true)->first();

        if ($prompt === null) {
            throw new BenchmarkRunnerException('Prompt bulunamadı.');
        }

        return $prompt;
    }

    private function validName(string $title): string
    {
        $name = self::normalizeName($title);

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new BenchmarkRunnerException('Başlık en az 2 harf veya rakam içermeli.');
        }

        return $name;
    }

    private function validContent(string $content): string
    {
        $content = trim(str_replace("\r\n", "\n", $content));
        $length = mb_strlen($content);

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new BenchmarkRunnerException('Prompt '.self::MIN_LENGTH.'–'.number_format(self::MAX_LENGTH, 0, ',', '.').' karakter olmalıdır.');
        }

        return $content;
    }

    /** A prompt file must not change under a benchmark that is starting or running with it. */
    private function ensureNotInUse(string $value): void
    {
        if ($this->inUse($value)) {
            throw new BenchmarkRunnerException('Bu prompt şu anda çalışan bir koşuda kullanılıyor. Koşu bitince düzenleyebilir veya silebilirsiniz.');
        }
    }

    private function inUse(string $value): bool
    {
        return BenchmarkExecution::query()
            ->whereIn('status', BenchmarkExecution::ACTIVE_STATUSES)
            ->where('prompt_path', $value)
            ->exists();
    }

    private function write(string $path, string $content): void
    {
        $temporary = dirname($path).DIRECTORY_SEPARATOR.'.'.basename($path).'.'.Str::lower(Str::random(8)).'.tmp';
        File::put($temporary, $content.PHP_EOL, true);

        if (! @rename($temporary, $path)) {
            File::delete($temporary);

            throw new BenchmarkRunnerException('Prompt dosyası yazılamadı.');
        }
    }

    private function moveToTrash(string $path): void
    {
        $trash = $this->directory().DIRECTORY_SEPARATOR.self::TRASH_DIRECTORY;
        File::ensureDirectoryExists($trash, 0700);
        $target = $trash.DIRECTORY_SEPARATOR.Str::beforeLast(basename($path), '.txt').'-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(4)).'.txt';

        if (! @rename($path, $target)) {
            throw new BenchmarkRunnerException('Prompt dosyası taşınamadı.');
        }
    }

    private function existingFile(string $key): string
    {
        $directory = $this->directory();

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $key) !== 1) {
            throw new BenchmarkRunnerException('Prompt bulunamadı.');
        }

        $path = realpath($directory.DIRECTORY_SEPARATOR.$key.'.txt');

        if ($path === false || ! is_file($path) || dirname($path) !== $directory) {
            throw new BenchmarkRunnerException('Prompt bulunamadı.');
        }

        return $path;
    }

    private function directory(): string
    {
        $directory = realpath(rtrim((string) config('benchforge.root_path'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'prompts');

        if ($directory === false || ! is_dir($directory)) {
            throw new BenchmarkRunnerException('Prompt dizini bulunamadı.');
        }

        return $directory;
    }

    private function withLock(callable $callback): void
    {
        try {
            Cache::lock('benchforge:prompts', 30)->block(5, $callback);
        } catch (LockTimeoutException) {
            throw new BenchmarkRunnerException('Başka bir prompt işlemi devam ediyor. Biraz sonra yeniden deneyin.');
        } catch (BenchmarkRunnerException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw new BenchmarkRunnerException('Prompt dosyası güncellenemedi.');
        }
    }
}
