<?php

namespace App\Http\Controllers;

use App\Services\BenchmarkRunner;
use Illuminate\Http\JsonResponse;

class BenchmarkExecutionStatusController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(BenchmarkRunner $benchmarkRunner): JsonResponse
    {
        return response()
            ->json($benchmarkRunner->snapshot())
            ->header('Cache-Control', 'no-store');
    }
}
