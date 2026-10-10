<?php

namespace App\Services;

use App\Models\Artifact;

class ArtifactReader
{
    private const MAX_BYTES = 131072;

    private const TAIL_BYTES = 32768;

    /**
     * @return array{content: string, truncated: bool, sizeBytes: int}|null
     */
    public function read(Artifact $artifact): ?array
    {
        if ($artifact->kind !== 'file') {
            return null;
        }

        $runsRoot = realpath((string) config('benchforge.runs_path'));
        $path = realpath($artifact->path);

        if (
            $runsRoot === false
            || $path === false
            || ! str_starts_with($path, $runsRoot.DIRECTORY_SEPARATOR)
            || ! is_file($path)
            || ! is_readable($path)
        ) {
            return null;
        }

        $sizeBytes = filesize($path);

        if ($sizeBytes === false) {
            return null;
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            if ($sizeBytes <= self::MAX_BYTES) {
                $content = stream_get_contents($handle);

                return $content === false
                    ? null
                    : $this->preview($content, false, $sizeBytes);
            }

            $headBytes = self::MAX_BYTES - self::TAIL_BYTES;
            $head = stream_get_contents($handle, $headBytes);

            if ($head === false || fseek($handle, -self::TAIL_BYTES, SEEK_END) !== 0) {
                return null;
            }

            $tail = stream_get_contents($handle, self::TAIL_BYTES);

            if ($tail === false) {
                return null;
            }

            return $this->preview(
                $head."\n\n… dosyanın orta bölümü kısaltıldı …\n\n".$tail,
                true,
                $sizeBytes,
            );
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array{content: string, truncated: bool, sizeBytes: int}
     */
    private function preview(string $content, bool $truncated, int $sizeBytes): array
    {
        return [
            'content' => preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $content) ?? $content,
            'truncated' => $truncated,
            'sizeBytes' => $sizeBytes,
        ];
    }
}
