<?php

namespace App\Http\Controllers;

use App\Models\PreparationComparison;
use App\Models\PreparationPackage;
use App\Services\PreparationRunner;
use Illuminate\Http\JsonResponse;

class PreparationStatusController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(PreparationComparison $preparationComparison, PreparationRunner $runner): JsonResponse
    {
        $comparison = $runner->reconcile($preparationComparison)->load('packages');

        return response()->json([
            'status' => $comparison->status,
            'active' => $comparison->isActive(),
            'message' => $comparison->message,
            'finishedAt' => $comparison->finished_at?->toIso8601String(),
            'completedModels' => $comparison->packages->where('status', 'completed')->count(),
            'totalModels' => $comparison->packages->count(),
            'packages' => $comparison->packages->map(fn (PreparationPackage $package): array => [
                'id' => $package->id,
                'status' => $package->status,
                'errorMessage' => $package->error_message,
                'durationSeconds' => $package->duration_seconds,
                'costUsd' => $package->cost_usd,
                'inputTokens' => $package->input_tokens,
                'outputTokens' => $package->output_tokens,
            ])->values()->all(),
        ])->header('Cache-Control', 'no-store');
    }
}
