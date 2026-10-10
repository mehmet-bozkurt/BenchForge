<?php

namespace App\Services;

use App\Models\BenchmarkRun;
use App\Models\ModelRun;
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

    public function isAvailable(BenchmarkRun $benchmarkRun, ModelRun $modelRun): bool
    {
        return $this->previewDirectory($benchmarkRun, $modelRun) !== null;
    }

    public function resolveFile(
        BenchmarkRun $benchmarkRun,
        ModelRun $modelRun,
        ?string $requestedPath = null,
    ): ?string {
        $previewDirectory = $this->previewDirectory($benchmarkRun, $modelRun);

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

    private function previewDirectory(BenchmarkRun $benchmarkRun, ModelRun $modelRun): ?string
    {
        if (! $this->isSafeSegment($benchmarkRun->slug) || ! $this->isSafeSegment($modelRun->slug)) {
            return null;
        }

        $runsPath = realpath((string) config('benchforge.runs_path'));

        if ($runsPath === false || ! File::isDirectory($runsPath)) {
            return null;
        }

        $runPath = $runsPath.DIRECTORY_SEPARATOR.$benchmarkRun->slug;
        $candidates = [
            $runPath.DIRECTORY_SEPARATOR.'_gallery'.DIRECTORY_SEPARATOR.$modelRun->slug,
            $runPath.DIRECTORY_SEPARATOR.$modelRun->slug.DIRECTORY_SEPARATOR.'out',
        ];

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

    private function isSafeSegment(string $segment): bool
    {
        return $segment !== ''
            && $segment !== '.'
            && $segment !== '..'
            && basename($segment) === $segment;
    }
}
