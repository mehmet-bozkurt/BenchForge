<?php

namespace App\Http\Controllers;

use App\Exceptions\BenchmarkRunnerException;
use App\Http\Requests\StoreBenchmarkExecutionRequest;
use App\Models\BenchmarkPrompt;
use App\Services\BenchmarkRunner;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BenchmarkExecutionController extends Controller
{
    public function create(Request $request, BenchmarkRunner $benchmarkRunner): View
    {
        $options = $benchmarkRunner->options();
        $selectedConfig = $request->old('config', $options['configs'][0]['value'] ?? null);
        $selectedGoModels = array_values((array) $request->old('go_models', []));
        $modelMode = $request->old('model_mode', 'group');

        return view('runner.create', [
            'options' => $options,
            'state' => $benchmarkRunner->snapshot(),
            'connections' => $benchmarkRunner->connectionStatus(),
            'preflight' => match (true) {
                $modelMode === 'custom' => null,
                $modelMode === 'opencode_go' && $selectedGoModels !== [] => $benchmarkRunner->preflight(null, $selectedGoModels, 'opencode_go'),
                is_string($selectedConfig) && $selectedConfig !== '' => $benchmarkRunner->preflight($selectedConfig),
                default => null,
            },
        ]);
    }

    public function store(StoreBenchmarkExecutionRequest $request, BenchmarkRunner $benchmarkRunner): RedirectResponse
    {
        try {
            $input = $request->benchmarkInput();

            if ($input['prompt_mode'] === 'custom' && $input['save_prompt']) {
                $baseSlug = Str::slug($input['custom_prompt_title']) ?: 'prompt';
                BenchmarkPrompt::query()->create([
                    'title' => $input['custom_prompt_title'],
                    'slug' => $baseSlug.'-'.Str::lower(Str::random(6)),
                    'content' => $input['custom_prompt_content'],
                    'content_hash' => hash('sha256', $input['custom_prompt_content']),
                    'active' => true,
                ]);
            }

            $execution = $benchmarkRunner->start($input);
        } catch (BenchmarkRunnerException $exception) {
            return back()->withInput()->withErrors(['runner' => $exception->getMessage()]);
        }

        return redirect()
            ->route('runner.create')
            ->with('status', "{$execution->run_id} benchmark koşusu başlatıldı.");
    }
}
