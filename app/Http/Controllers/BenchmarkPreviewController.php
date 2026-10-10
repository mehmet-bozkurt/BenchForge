<?php

namespace App\Http\Controllers;

use App\Models\BenchmarkRun;
use App\Models\ModelRun;
use App\Services\BenchmarkPreview;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BenchmarkPreviewController extends Controller
{
    public function __invoke(
        Request $request,
        BenchmarkRun $benchmarkRun,
        ModelRun $modelRun,
        BenchmarkPreview $benchmarkPreview,
        ?string $path = null,
    ): Response|BinaryFileResponse {
        $routeName = $request->routeIs('runs.previews.recovered') ? 'runs.previews.recovered' : 'runs.previews.show';
        $version = $routeName === 'runs.previews.recovered' ? BenchmarkPreview::RECOVERED : BenchmarkPreview::INITIAL;
        $filePath = $benchmarkPreview->resolveFile($benchmarkRun, $modelRun, $path, $version);

        abort_if($filePath === null, 404);

        /*
         * Model output is untrusted and shares the admin origin. The CSP sandbox gives it an
         * opaque origin even when opened outside the sandboxed iframe (e.g. "open in new tab").
         */
        $headers = [
            'Content-Security-Policy' => 'sandbox allow-scripts',
            'Cache-Control' => 'no-store, private',
            'Content-Type' => $benchmarkPreview->contentType($filePath),
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];

        if ($benchmarkPreview->shouldRewrite($filePath)) {
            $previewUrl = route($routeName, [$benchmarkRun, $modelRun], absolute: false);

            return response(
                $benchmarkPreview->rewrittenContents($filePath, $modelRun, $previewUrl),
                headers: $headers,
            );
        }

        return response()->file($filePath, $headers);
    }
}
