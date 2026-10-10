<?php

namespace App\Services;

use App\Models\BenchmarkRun;
use App\Models\ModelRun;
use App\Models\ModelRunAttempt;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class BenchmarkPreview
{
    private const CONTENT_TYPES = [
        'avif' => 'image/avif',
        'css' => 'text/css; charset=UTF-8',
        'gif' => 'image/gif',
        'html' => 'text/html; charset=UTF-8',
        'ico' => 'image/x-icon',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'js' => 'application/javascript; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'map' => 'application/json; charset=UTF-8',
        'mjs' => 'application/javascript; charset=UTF-8',
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
        'ttf' => 'font/ttf',
        'txt' => 'text/plain; charset=UTF-8',
        'webmanifest' => 'application/manifest+json; charset=UTF-8',
        'webp' => 'image/webp',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];

    private const REWRITABLE_EXTENSIONS = [
        'css',
        'html',
        'js',
        'json',
        'map',
        'mjs',
        'svg',
        'txt',
        'webmanifest',
    ];

    /**
     * The first submission is the only scored output.
     */
    public const INITIAL = 'initial';

    /**
     * The export published by a later recovery attempt; review-only.
     */
    public const RECOVERED = 'recovered';

    public function isAvailable(BenchmarkRun $benchmarkRun, ModelRun $modelRun, string $version = self::INITIAL): bool
    {
        return $this->previewDirectory($benchmarkRun, $modelRun, $version) !== null;
    }

    public function hasAnyPreview(BenchmarkRun $benchmarkRun, ModelRun $modelRun): bool
    {
        return $this->isAvailable($benchmarkRun, $modelRun)
            || $this->isAvailable($benchmarkRun, $modelRun, self::RECOVERED);
    }

    /**
     * The earliest recovery attempt that passed every check and therefore replaced the published export.
     */
    public function firstPublishingAttempt(ModelRun $modelRun): ?ModelRunAttempt
    {
        return $this->publishingAttempts($modelRun)->first();
    }

    /**
     * The attempt whose export the recovered preview currently shows.
     */
    public function latestPublishingAttempt(ModelRun $modelRun): ?ModelRunAttempt
    {
        return $this->publishingAttempts($modelRun)->last();
    }

    /** @return Collection<int, ModelRunAttempt> */
    private function publishingAttempts(ModelRun $modelRun): Collection
    {
        return $modelRun->modelRunAttempts
            ->filter(fn (ModelRunAttempt $attempt): bool => $attempt->status === 'completed'
                && ($attempt->result['overallStatus'] ?? null) === 'pass')
            ->sortBy('attempt_number')
            ->values();
    }

    public function resolveFile(
        BenchmarkRun $benchmarkRun,
        ModelRun $modelRun,
        ?string $requestedPath = null,
        string $version = self::INITIAL,
    ): ?string {
        $previewDirectory = $this->previewDirectory($benchmarkRun, $modelRun, $version);

        if ($previewDirectory === null) {
            return null;
        }

        $relativePath = rawurldecode(trim($requestedPath ?? '', '/'));

        if ($relativePath === '') {
            $relativePath = 'index.html';
        }

        if (str_contains($relativePath, "\0") || str_contains($relativePath, '\\')) {
            return null;
        }

        $segments = explode('/', $relativePath);

        if (collect($segments)->contains(fn (string $segment): bool => in_array($segment, ['', '.', '..'], true))) {
            return null;
        }

        $candidate = $previewDirectory.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $segments);

        if (File::isDirectory($candidate)) {
            $candidate .= DIRECTORY_SEPARATOR.'index.html';
        }

        $resolvedPath = realpath($candidate);

        if ($resolvedPath === false || ! File::isFile($resolvedPath)) {
            return null;
        }

        if (! str_starts_with($resolvedPath, $previewDirectory.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $resolvedPath;
    }

    public function contentType(string $path): string
    {
        return self::CONTENT_TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))]
            ?? 'application/octet-stream';
    }

    public function shouldRewrite(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::REWRITABLE_EXTENSIONS, true);
    }

    public function rewrittenContents(string $path, ModelRun $modelRun, string $previewUrl): string
    {
        $contents = File::get($path);
        $sourcePrefix = '/'.$modelRun->slug;

        return str_replace(
            [$sourcePrefix.'/', $sourcePrefix],
            [rtrim($previewUrl, '/').'/', rtrim($previewUrl, '/')],
            $contents,
        );
    }

    /**
     * A successful recovery moves the first-submission export into its attempt folder
     * (`gallery-before` / `out-before`) and publishes its own export in the usual places.
     */
    private function previewDirectory(BenchmarkRun $benchmarkRun, ModelRun $modelRun, string $version): ?string
    {
        if (! $this->isSafeSegment($benchmarkRun->slug) || ! $this->isSafeSegment($modelRun->slug)) {
            return null;
        }

        $runsPath = realpath((string) config('benchforge.runs_path'));

        if ($runsPath === false || ! File::isDirectory($runsPath)) {
            return null;
        }

        $runPath = $runsPath.DIRECTORY_SEPARATOR.$benchmarkRun->slug;
        $publishedCandidates = [
            $runPath.DIRECTORY_SEPARATOR.'_gallery'.DIRECTORY_SEPARATOR.$modelRun->slug,
            $runPath.DIRECTORY_SEPARATOR.$modelRun->slug.DIRECTORY_SEPARATOR.'out',
        ];
        $publishingAttempt = $this->firstPublishingAttempt($modelRun);

        $candidates = match (true) {
            $version === self::RECOVERED => $publishingAttempt === null ? [] : $publishedCandidates,
            $publishingAttempt !== null => $this->attemptBackups($runPath, $modelRun, $publishingAttempt),
            default => [
                ...$publishedCandidates,
                ...$modelRun->modelRunAttempts
                    ->filter(fn (ModelRunAttempt $attempt): bool => $attempt->isActive())
                    ->flatMap(fn (ModelRunAttempt $attempt): array => $this->attemptBackups($runPath, $modelRun, $attempt))
                    ->all(),
            ],
        };

        foreach ($candidates as $candidate) {
            $resolvedPath = realpath($candidate);

            if ($resolvedPath === false || ! File::isFile($resolvedPath.DIRECTORY_SEPARATOR.'index.html')) {
                continue;
            }

            if (str_starts_with($resolvedPath, $runsPath.DIRECTORY_SEPARATOR)) {
                return $resolvedPath;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function attemptBackups(string $runPath, ModelRun $modelRun, ModelRunAttempt $attempt): array
    {
        $attemptPath = implode(DIRECTORY_SEPARATOR, [
            $runPath,
            '_attempts',
            $modelRun->slug,
            'attempt-'.(int) $attempt->attempt_number,
        ]);

        return [
            $attemptPath.DIRECTORY_SEPARATOR.'gallery-before',
            $attemptPath.DIRECTORY_SEPARATOR.'out-before',
        ];
    }

    private function isSafeSegment(string $segment): bool
    {
        return $segment !== ''
            && $segment !== '.'
            && $segment !== '..'
            && basename($segment) === $segment;
    }
}
