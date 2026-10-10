<?php

namespace App\Http\Controllers;

use App\Services\ModelLeaderboard;
use Illuminate\Contracts\View\View;

class ModelController extends Controller
{
    public function index(ModelLeaderboard $leaderboard): View
    {
        $models = $leaderboard->all();

        return view('models.index', [
            'models' => $models,
            'summary' => [
                'models' => $models->count(),
                'runs' => $models->sum('run_count'),
                'reviews' => $models->sum('manual.count'),
                'reviewed_models' => $models->where('manual.count', '>', 0)->count(),
            ],
        ]);
    }

    public function show(string $modelSlug, ModelLeaderboard $leaderboard): View
    {
        $model = $leaderboard->find($modelSlug);

        abort_if($model === null, 404);

        return view('models.show', ['model' => $model]);
    }
}
