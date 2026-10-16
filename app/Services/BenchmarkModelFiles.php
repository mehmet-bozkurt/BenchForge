<?php

namespace App\Services;

use App\Models\BenchmarkRun;
use App\Models\ModelRun;

class BenchmarkModelFiles
{
    public function runPath(BenchmarkRun $run, ModelRun $model): ?string
    {
        if ($model->benchmark_run_id !== $run->id
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $run->slug) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $model->slug) !== 1) {
            return null;
        }

        $root = realpath((string) config('benchforge.runs_path'));

        if ($root === false) {
            return null;
        }

        $path = $root.'/'.$run->slug;

        return is_dir($path) && realpath($path) === $path ? $path : null;
    }

    public function read(
        BenchmarkRun $run,
        ModelRun $model,
        string $relativePath,
        int $limit = 8192,
        bool $tail = true,
    ): string {
        $slug = preg_quote($model->slug, '~');
        $stage = '(?:lint|build|export|routes|runtime|opencode)';
        $allowed = '~^(?:_logs/'.$slug.'\.(?:'.$stage.'\.log|opencode\.(?:stderr\.log|events\.jsonl|continue\.events\.jsonl|continue\.log)|auto-continue\.log)'
            .'|_attempts/'.$slug.'/attempt-[1-9][0-9]*/(?:'.$stage.'\.log|opencode\.(?:stderr\.log|events\.jsonl)|boundary-report\.json|model-policy/metadata\.json)'
            .'|_boundary/'.$slug.'\.json|_policies/'.$slug.'/metadata\.json)$~';
        $runPath = $this->runPath($run, $model);

        if ($runPath === null || preg_match($allowed, $relativePath) !== 1) {
            return '';
        }

        $path = $runPath.'/'.$relativePath;

        if (! is_file($path) || realpath($path) !== $path) {
            return '';
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        try {
            $size = (int) (fstat($handle)['size'] ?? 0);
            $length = min($size, max(1, min($limit, 2 * 1024 * 1024)));

            if ($tail) {
                fseek($handle, max(0, $size - $length));
            }

            return $length > 0 ? (fread($handle, $length) ?: '') : '';
        } finally {
            fclose($handle);
        }
    }

    public function sanitize(string $text, ?string $runPath = null, ?string $slug = null): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = preg_replace('/\x1B(?:[@-Z\\-_]|\[[0-?]*[ -\/]*[@-~])/', '', $text) ?? '';
        $text = preg_replace('/\bBearer\s+[^\s"\'<>]+/i', 'Bearer [gizlendi]', $text) ?? '';
        $text = preg_replace('/\bsk-(?:or-v1-)?[A-Za-z0-9_-]{8,}/', '[anahtar gizlendi]', $text) ?? '';
        $text = preg_replace('/\b([A-Za-z0-9_-]*(?:API[_-]?KEY|TOKEN|SECRET|PASSWORD|APP[_-]?KEY|SECRET[_-]?ACCESS[_-]?KEY|ACCESS[_-]?KEY(?:[_-]?ID)?))["\']?\s*[:=]\s*[^\r\n]+/i', '$1=[gizlendi]', $text) ?? '';

        if ($runPath !== null && $slug !== null) {
            $text = str_replace($runPath.'/'.$slug.'/', './', $text);
            $text = str_replace($runPath, '[koşu]', $text);
        }

        $text = preg_replace('~/(?:Users|home|private|var|tmp)/[^\s"\'<>)]+~', '[yerel yol]', $text) ?? '';

        return trim($text);
    }
}
