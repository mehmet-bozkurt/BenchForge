<?php

namespace App\Http\Controllers;

use App\Exceptions\BenchmarkRunnerException;
use App\Http\Requests\OpenRouterEndpointRequest;
use App\Services\OpenRouterProviderRouting;
use Illuminate\Http\JsonResponse;

class OpenRouterEndpointController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(OpenRouterEndpointRequest $request, OpenRouterProviderRouting $routing): JsonResponse
    {
        try {
            $result = $routing->endpoints($request->string('model')->toString(), $request->boolean('refresh'));
        } catch (BenchmarkRunnerException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503)->header('Cache-Control', 'no-store');
        }

        return response()->json($result)->header('Cache-Control', 'no-store');
    }
}
