<?php

namespace App\Http\Controllers;

use App\Models\BenchmarkRun;
use App\Models\ModelRun;
use App\Services\BenchmarkPreview;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BenchmarkPreviewController extends Controller
{
    public function __invoke(
        BenchmarkRun $benchmarkRun,
        ModelRun $modelRun,
        BenchmarkPreview $benchmarkPreview,
        ?string $path = null,
    ): Response|BinaryFileResponse {
        $filePath = $benchmarkPreview->resolveFile($benchmarkRun, $modelRun, $path);

        abort_if($filePath === null, 404);

        $headers = [
            'Cache-Control' => 'no-store, private',
            'Content-Type' => $benchmarkPreview->contentType($filePath),
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];

        if ($benchmarkPreview->shouldRewrite($filePath)) {
            $previewUrl = route('runs.previews.show', [$benchmarkRun, $modelRun], absolute: false);

            return response(
                $benchmarkPreview->rewrittenContents($filePath, $modelRun, $previewUrl),
                headers: $headers,
            );
        }

        return response()->file($filePath, $headers);
    }
}
