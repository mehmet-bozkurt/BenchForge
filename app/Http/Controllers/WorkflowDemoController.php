<?php

namespace App\Http\Controllers;

use App\Models\WorkflowRun;
use App\Services\WorkflowRunFiles;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WorkflowDemoController extends Controller
{
    /**
     * The sandbox gives model output an opaque origin even when it is opened outside the sandboxed iframe (e.g. "open
     * in new tab"), so it cannot reach the admin session. Demos must be self-contained (template rules), so everything
     * except inline code and data/blob media is refused: the browser blocks beacons, requests and remote loads even
     * when a demo hides them from the worker's static network check. WebRTC and self-navigation are outside CSP; the
     * static check flags them. eval stays allowed because the template rules do not forbid it.
     */
    private const CONTENT_SECURITY_POLICY = "sandbox allow-scripts; default-src 'none'; script-src 'unsafe-inline' 'unsafe-eval'; "
        ."style-src 'unsafe-inline'; img-src data: blob:; media-src data: blob:; font-src data:; connect-src 'none'; "
        ."worker-src blob:; form-action 'none'; base-uri 'none'";

    /**
     * The demo of one foreach code child for one item (`steps/<step>/items/<item>/<child>/demo.html`).
     */
    public function show(
        WorkflowRun $workflowRun,
        string $model,
        string $step,
        string $item,
        string $child,
        WorkflowRunFiles $files,
    ): BinaryFileResponse {
        return $this->demoResponse($files->demoPath($workflowRun, $model, $step, $item, $child));
    }

    /**
     * The demo of a top-level code step (`steps/<step>/demo.html`).
     */
    public function step(WorkflowRun $workflowRun, string $model, string $step, WorkflowRunFiles $files): BinaryFileResponse
    {
        return $this->demoResponse($files->demoPath($workflowRun, $model, $step));
    }

    private function demoResponse(?string $filePath): BinaryFileResponse
    {
        abort_if($filePath === null, 404);

        return response()->file($filePath, [
            'Content-Security-Policy' => self::CONTENT_SECURITY_POLICY,
            'Cache-Control' => 'no-store, private',
            'Content-Type' => 'text/html; charset=UTF-8',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
