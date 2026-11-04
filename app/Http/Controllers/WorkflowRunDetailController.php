<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShowWorkflowStepDetailRequest;
use App\Models\WorkflowRun;
use App\Services\WorkflowRunFiles;
use Illuminate\Http\JsonResponse;

class WorkflowRunDetailController extends Controller
{
    /**
     * One step of one model, or one foreach child of one item: prompts, responses, output and attempts.
     */
    public function __invoke(ShowWorkflowStepDetailRequest $request, WorkflowRun $workflowRun, WorkflowRunFiles $files): JsonResponse
    {
        $reference = $request->stepReference();
        $detail = $files->stepDetail($workflowRun, $reference['model'], $reference['step'], $reference['item'], $reference['child']);

        abort_if($detail === null, 404);

        return response()->json($detail)->header('Cache-Control', 'no-store');
    }
}
