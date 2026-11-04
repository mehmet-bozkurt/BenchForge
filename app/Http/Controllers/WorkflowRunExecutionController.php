<?php

namespace App\Http\Controllers;

use App\Exceptions\BenchmarkRunnerException;
use App\Models\WorkflowRun;
use App\Services\WorkflowRunner;
use Illuminate\Http\RedirectResponse;

class WorkflowRunExecutionController extends Controller
{
    public function destroy(WorkflowRun $workflowRun, WorkflowRunner $runner): RedirectResponse
    {
        try {
            $runner->stop($workflowRun);
        } catch (BenchmarkRunnerException $exception) {
            return back()->withErrors(['workflow' => $exception->getMessage()]);
        }

        return redirect()->route('workflows.runs.show', $workflowRun)
            ->with('status', 'İş akışı durduruluyor; tamamlanan adımlar korunacak.');
    }
}
