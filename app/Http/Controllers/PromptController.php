<?php

namespace App\Http\Controllers;

use App\Exceptions\BenchmarkRunnerException;
use App\Http\Requests\SavePromptRequest;
use App\Services\PromptLibrary;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class PromptController extends Controller
{
    public function index(PromptLibrary $prompts): View
    {
        try {
            $items = $prompts->all();
            $directoryError = null;
        } catch (BenchmarkRunnerException $exception) {
            $items = [];
            $directoryError = $exception->getMessage();
        }

        return view('prompts.index', ['prompts' => $items, 'directoryError' => $directoryError]);
    }

    public function create(): View
    {
        return view('prompts.edit', ['prompt' => null]);
    }

    public function store(SavePromptRequest $request, PromptLibrary $prompts): RedirectResponse
    {
        try {
            $prompts->create($request->validated('title'), $request->validated('content'));
        } catch (BenchmarkRunnerException $exception) {
            return back()->withInput()->withErrors(['prompt' => $exception->getMessage()]);
        }

        return redirect()->route('prompts.index')->with('status', "“{$request->validated('title')}” promptu eklendi. Yeni Benchmark ekranındaki prompt listesinde görünür.");
    }

    public function edit(string $prompt, PromptLibrary $prompts): View
    {
        try {
            $item = $prompts->find($prompt);
        } catch (BenchmarkRunnerException) {
            abort(404);
        }

        return view('prompts.edit', ['prompt' => $item]);
    }

    public function update(SavePromptRequest $request, string $prompt, PromptLibrary $prompts): RedirectResponse
    {
        try {
            $prompts->update($prompt, $request->validated('title'), $request->validated('content'));
        } catch (BenchmarkRunnerException $exception) {
            return back()->withInput()->withErrors(['prompt' => $exception->getMessage()]);
        }

        return redirect()->route('prompts.index')->with('status', "“{$request->validated('title')}” promptu güncellendi. Geçmiş koşular kendi kopyalarını kullandığı için etkilenmez.");
    }

    public function destroy(string $prompt, PromptLibrary $prompts): RedirectResponse
    {
        try {
            $item = $prompts->find($prompt);
            $prompts->delete($prompt);
        } catch (BenchmarkRunnerException $exception) {
            return back()->withErrors(['prompt' => $exception->getMessage()]);
        }

        $where = $item['source'] === 'file' ? ' Dosya prompts/.silinen klasörüne taşındı.' : '';

        return redirect()->route('prompts.index')->with('status', "“{$item['title']}” promptu silindi.{$where} Geçmiş koşular etkilenmez.");
    }
}
