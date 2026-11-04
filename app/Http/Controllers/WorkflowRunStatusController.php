<?php

namespace App\Http\Controllers;

use App\Models\WorkflowRun;
use App\Services\WorkflowRunner;
use Illuminate\Http\JsonResponse;

class WorkflowRunStatusController extends Controller
{
    /**
     * The live snapshot the run page polls. It holds no file paths.
     */
    public function __invoke(WorkflowRun $workflowRun, WorkflowRunner $runner): JsonResponse
    {
        return response()->json($runner->snapshot($workflowRun))->header('Cache-Control', 'no-store');
    }
}
