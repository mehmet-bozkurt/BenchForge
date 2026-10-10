<?php

namespace App\Http\Controllers;

use App\Models\Artifact;
use App\Models\BenchmarkRun;
use App\Services\ArtifactReader;
use Illuminate\Contracts\View\View;

class ArtifactController extends Controller
{
    public function show(
        BenchmarkRun $benchmarkRun,
        Artifact $artifact,
        ArtifactReader $artifactReader,
    ): View {
        $preview = $artifactReader->read($artifact);

        abort_if($preview === null, 404);

        return view('artifacts.show', [
            'run' => $benchmarkRun,
            'artifact' => $artifact,
            'preview' => $preview,
        ]);
    }
}
