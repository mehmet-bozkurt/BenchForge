<?php

namespace App\Http\Controllers;

use App\Exceptions\BenchmarkRunnerException;
use App\Http\Requests\StoreModelRunAttemptRequest;
use App\Models\BenchmarkRun;
use App\Models\ModelRun;
use App\Models\ModelRunAttempt;
use App\Services\ModelRunContinuationRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ModelRunAttemptController extends Controller
{
    public function store(
        StoreModelRunAttemptRequest $request,
        BenchmarkRun $benchmarkRun,
        ModelRun $modelRun,
        ModelRunContinuationRunner $runner,
    ): RedirectResponse {
        try {
            $attempt = $runner->start(
                $benchmarkRun,
                $modelRun,
                $request->string('mode')->toString(),
            );
        } catch (BenchmarkRunnerException $exception) {
            return back()->withErrors(['continuation' => $exception->getMessage()]);
        }

        $label = $attempt->mode === 'validate'
            ? 'Token harcamayan doğrulama'
            : 'Kaldığı yerden devam denemesi';

        return back()->with('status', "{$label} başlatıldı.");
    }

    public function destroy(
        Request $request,
        BenchmarkRun $benchmarkRun,
        ModelRun $modelRun,
        ModelRunAttempt $modelRunAttempt,
        ModelRunContinuationRunner $runner,
    ): RedirectResponse {
        abort_unless(in_array($request->ip(), ['127.0.0.1', '::1'], true), 403);

        try {
            $runner->stop($benchmarkRun, $modelRun, $modelRunAttempt);
        } catch (BenchmarkRunnerException $exception) {
            return back()->withErrors(['continuation' => $exception->getMessage()]);
        }

        return back()->with('status', 'Kurtarma denemesi güvenli biçimde durduruluyor.');
    }
}
