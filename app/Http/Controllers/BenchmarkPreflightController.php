<?php

namespace App\Http\Controllers;

use App\Exceptions\BenchmarkRunnerException;
use App\Http\Requests\BenchmarkPreflightRequest;
use App\Services\BenchmarkRunner;
use Illuminate\Http\JsonResponse;

class BenchmarkPreflightController extends Controller
{
    public function __invoke(BenchmarkPreflightRequest $request, BenchmarkRunner $benchmarkRunner): JsonResponse
    {
        try {
            $preflight = match ($request->string('model_mode')->toString()) {
                'custom' => $benchmarkRunner->preflight(null, $request->selectedModels(), 'custom', $request->input('routing_mode', 'auto'), (array) $request->input('fixed_providers', [])),
                'opencode_go' => $benchmarkRunner->preflight(null, $request->selectedGoModels(), 'opencode_go'),
                default => $benchmarkRunner->preflight($request->string('config')->toString()),
            };
        } catch (BenchmarkRunnerException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'ready' => false], 422)->header('Cache-Control', 'no-store');
        }

        return response()
            ->json($preflight)
            ->header('Cache-Control', 'no-store');
    }
}
