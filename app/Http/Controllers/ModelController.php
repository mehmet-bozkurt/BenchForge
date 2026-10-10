<?php

namespace App\Http\Controllers;

use App\Models\BenchmarkRun;
use App\Services\ModelLeaderboard;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class ModelController extends Controller
{
    public function index(Request $request, ModelLeaderboard $leaderboard): View
    {
        $environmentProfile = $this->environmentProfile($request);
        $models = $leaderboard->all($environmentProfile);

        return view('models.index', [
            'models' => $models,
            'environmentProfile' => $environmentProfile,
            'environmentProfiles' => BenchmarkRun::ENVIRONMENT_PROFILES,
            'summary' => [
                'models' => $models->count(),
                'runs' => $models->sum('run_count'),
                'reviews' => $models->sum('manual.count'),
                'reviewed_models' => $models->where('manual.count', '>', 0)->count(),
            ],
        ]);
    }

    public function show(Request $request, string $modelSlug, ModelLeaderboard $leaderboard): View
    {
        $environmentProfile = $this->environmentProfile($request);
        $model = $leaderboard->find($modelSlug, $environmentProfile);

        abort_if($model === null, 404);

        return view('models.show', [
            'model' => $model,
            'environmentProfile' => $environmentProfile,
            'environmentProfiles' => BenchmarkRun::ENVIRONMENT_PROFILES,
        ]);
    }

    /**
     * An unknown condition falls back to the current one instead of mixing conditions.
     */
    private function environmentProfile(Request $request): string
    {
        $requested = $request->string('condition')->toString();

        return array_key_exists($requested, BenchmarkRun::ENVIRONMENT_PROFILES)
            ? $requested
            : BenchmarkRun::CURRENT_ENVIRONMENT_PROFILE;
    }
}
