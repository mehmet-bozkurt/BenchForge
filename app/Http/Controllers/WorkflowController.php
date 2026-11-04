<?php

namespace App\Http\Controllers;

use App\Exceptions\BenchmarkRunnerException;
use App\Models\WorkflowRun;
use App\Services\ModelSelection;
use App\Services\WorkflowRunner;
use App\Services\WorkflowTemplateStore;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class WorkflowController extends Controller
{
    /**
     * The active run is reconciled before the run list is loaded, so a run whose worker finished since the last poll
     * is listed with its final status instead of as still running.
     */
    public function index(WorkflowTemplateStore $templateStore, WorkflowRunner $runner): View
    {
        $activeRun = $runner->active();

        return view('workflows.index', [
            'templates' => $templateStore->all(),
            'runs' => WorkflowRun::query()->withCount('models')->latest('id')->paginate(20),
            'activeRun' => $activeRun,
            'title' => 'İş Akışları · BenchForge',
        ]);
    }

    public function create(
        Request $request,
        string $template,
        WorkflowTemplateStore $templateStore,
        ModelSelection $modelSelection,
        WorkflowRunner $runner,
    ): View {
        $requestedVersion = $request->query('version');
        $version = $requestedVersion === null
            ? null
            : filter_var($requestedVersion, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        abort_if($version === false, 404);

        $found = $templateStore->find($template, $version);

        abort_if($found === null, 404);

        try {
            $groupOptions = $modelSelection->groupOptions();
            $groupError = null;
        } catch (BenchmarkRunnerException $exception) {
            $groupOptions = [];
            $groupError = $exception->getMessage();
        }

        return view('workflows.create', [
            'workflowTemplate' => $found,
            'versions' => $templateStore->versions($template),
            'pickerOptions' => [...$modelSelection->catalogOptions(), 'configs' => $groupOptions],
            'groupError' => $groupError,
            'activeRun' => $runner->active(),
            'title' => $found['template']['name'].' · BenchForge',
        ]);
    }
}
