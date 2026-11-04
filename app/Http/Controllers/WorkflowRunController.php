<?php

namespace App\Http\Controllers;

use App\Exceptions\BenchmarkRunnerException;
use App\Http\Requests\StartWorkflowRunRequest;
use App\Models\WorkflowRun;
use App\Services\WorkflowRunFiles;
use App\Services\WorkflowRunner;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class WorkflowRunController extends Controller
{
    public function store(StartWorkflowRunRequest $request, WorkflowRunner $runner): RedirectResponse
    {
        try {
            $workflowRun = $runner->start($request->workflowInput());
        } catch (BenchmarkRunnerException $exception) {
            return back()->withInput()->withErrors(['workflow' => $exception->getMessage()]);
        }

        return redirect()->route('workflows.runs.show', $workflowRun)
            ->with('status', 'İş akışı başlatıldı. Adımları bu sayfadan canlı izleyebilirsiniz.');
    }

    public function show(WorkflowRun $workflowRun, WorkflowRunner $runner, WorkflowRunFiles $files): View
    {
        $snapshot = $runner->snapshot($workflowRun);

        return view('workflows.runs.show', [
            'workflowRun' => $workflowRun,
            'snapshot' => $snapshot,
            'template' => $files->template($workflowRun),
            'gallery' => $files->galleryItems($workflowRun),
            'title' => $workflowRun->title.' · BenchForge',
        ]);
    }
}
