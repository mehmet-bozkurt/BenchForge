<?php

namespace App\Http\Controllers;

use App\Exceptions\BenchmarkRunnerException;
use App\Http\Requests\SaveModelGroupRequest;
use App\Http\Requests\StoreModelSelectionGroupRequest;
use App\Models\OpenCodeGoModel;
use App\Models\OpenRouterModel;
use App\Services\ModelGroupStore;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class ModelGroupController extends Controller
{
    public function index(ModelGroupStore $groups): View
    {
        try {
            $items = $groups->all();
            $directoryError = null;
        } catch (BenchmarkRunnerException $exception) {
            $items = [];
            $directoryError = $exception->getMessage();
        }

        return view('model-groups.index', [
            'groups' => $items,
            'directoryError' => $directoryError,
            'catalogModels' => $this->catalogModels(),
        ]);
    }

    public function store(SaveModelGroupRequest $request, ModelGroupStore $groups): RedirectResponse
    {
        try {
            $groups->save($request->validated('name'), $request->models());
        } catch (BenchmarkRunnerException $exception) {
            return back()->withInput()->withErrors(['group' => $exception->getMessage()]);
        }

        return redirect()->route('model-groups.index')->with('status', "“{$request->validated('name')}” grubu oluşturuldu.");
    }

    public function storeSelection(StoreModelSelectionGroupRequest $request, ModelGroupStore $groups): RedirectResponse
    {
        try {
            $groups->save($request->validated('group_name'), $request->models());
        } catch (BenchmarkRunnerException $exception) {
            return back()->withInput()->withErrors(['group_name' => $exception->getMessage()]);
        }

        return back()
            ->withInput($request->except('group_name'))
            ->with('status', "Seçim “{$request->validated('group_name')}” grubu olarak kaydedildi. Kayıtlı grup listesinde görünür.");
    }

    public function edit(string $group, ModelGroupStore $groups): View
    {
        try {
            $item = $groups->find($group);
        } catch (BenchmarkRunnerException) {
            abort(404);
        }

        return view('model-groups.edit', [
            'group' => $item,
            'catalogModels' => $this->catalogModels(),
        ]);
    }

    public function update(SaveModelGroupRequest $request, string $group, ModelGroupStore $groups): RedirectResponse
    {
        try {
            $groups->save($request->validated('name'), $request->models(), $group);
        } catch (BenchmarkRunnerException $exception) {
            return back()->withInput()->withErrors(['group' => $exception->getMessage()]);
        }

        return redirect()->route('model-groups.index')->with('status', "“{$request->validated('name')}” grubu güncellendi.");
    }

    public function destroy(string $group, ModelGroupStore $groups): RedirectResponse
    {
        try {
            $groups->delete($group);
        } catch (BenchmarkRunnerException $exception) {
            return back()->withErrors(['group' => $exception->getMessage()]);
        }

        return redirect()->route('model-groups.index')->with('status', "“{$group}” grubu silindi. Dosya config/.silinen klasörüne taşındı; geçmiş koşular etkilenmez.");
    }

    /**
     * Provider-prefixed ids of every model currently offered by the two catalogs, used to flag unknown ids.
     *
     * @return list<string>
     */
    private function catalogModels(): array
    {
        return OpenRouterModel::query()
            ->where('available', true)
            ->where('agent_compatible', true)
            ->orderBy('model_id')
            ->pluck('model_id')
            ->map(fn (string $id): string => 'openrouter/'.$id)
            ->concat(
                OpenCodeGoModel::query()
                    ->where('available', true)
                    ->where('agent_compatible', true)
                    ->orderBy('model_id')
                    ->pluck('model_id')
                    ->map(fn (string $id): string => 'opencode-go/'.$id),
            )
            ->values()
            ->all();
    }
}
