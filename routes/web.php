<?php

use App\Http\Controllers\ArtifactController;
use App\Http\Controllers\BenchmarkComparisonController;
use App\Http\Controllers\BenchmarkExecutionController;
use App\Http\Controllers\BenchmarkExecutionStatusController;
use App\Http\Controllers\BenchmarkPreflightController;
use App\Http\Controllers\BenchmarkPreviewController;
use App\Http\Controllers\BenchmarkResultController;
use App\Http\Controllers\BenchmarkRunController;
use App\Http\Controllers\BenchmarkStandalonePreviewController;
use App\Http\Controllers\BenchmarkWorkspaceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ModelController;
use App\Http\Controllers\ModelEvaluationController;
use App\Http\Controllers\ModelGroupController;
use App\Http\Controllers\ModelRunAttemptController;
use App\Http\Controllers\OpenCodeGoCatalogController;
use App\Http\Controllers\OpenRouterCatalogController;
use App\Http\Controllers\OpenRouterEndpointController;
use App\Http\Controllers\PreparationComparisonController;
use App\Http\Controllers\PreparationEvaluationController;
use App\Http\Controllers\PreparationExecutionController;
use App\Http\Controllers\PreparationPackageController;
use App\Http\Controllers\PreparationStatusController;
use App\Http\Controllers\PromptController;
use App\Http\Controllers\StopBenchmarkExecutionController;
use App\Http\Controllers\SystemSettingsController;
use App\Http\Middleware\EnsureLocalPreparationAccess;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', DashboardController::class)->name('dashboard');
Route::get('/models', [ModelController::class, 'index'])->name('models.index');
Route::middleware(EnsureLocalPreparationAccess::class)->prefix('system')->name('system.')->group(function (): void {
    Route::get('/', [SystemSettingsController::class, 'index'])->middleware('throttle:20,1')->name('index');
    Route::put('/', [SystemSettingsController::class, 'update'])->middleware('throttle:5,1')->name('update');
});
Route::middleware(EnsureLocalPreparationAccess::class)->prefix('prompts')->name('prompts.')->group(function (): void {
    Route::get('/', [PromptController::class, 'index'])->name('index');
    Route::get('/create', [PromptController::class, 'create'])->name('create');
    Route::post('/', [PromptController::class, 'store'])->middleware('throttle:20,1')->name('store');
    Route::get('/{prompt}/edit', [PromptController::class, 'edit'])->where('prompt', '[A-Za-z0-9][A-Za-z0-9._-]*')->name('edit');
    Route::put('/{prompt}', [PromptController::class, 'update'])->where('prompt', '[A-Za-z0-9][A-Za-z0-9._-]*')->middleware('throttle:20,1')->name('update');
    Route::delete('/{prompt}', [PromptController::class, 'destroy'])->where('prompt', '[A-Za-z0-9][A-Za-z0-9._-]*')->middleware('throttle:20,1')->name('destroy');
});

Route::middleware(EnsureLocalPreparationAccess::class)->prefix('model-groups')->name('model-groups.')->group(function (): void {
    Route::get('/', [ModelGroupController::class, 'index'])->name('index');
    Route::post('/', [ModelGroupController::class, 'store'])->middleware('throttle:20,1')->name('store');
    Route::post('/selection', [ModelGroupController::class, 'storeSelection'])->middleware('throttle:20,1')->name('selection.store');
    Route::get('/{group}/edit', [ModelGroupController::class, 'edit'])->where('group', '[A-Za-z0-9][A-Za-z0-9._-]*')->name('edit');
    Route::put('/{group}', [ModelGroupController::class, 'update'])->where('group', '[A-Za-z0-9][A-Za-z0-9._-]*')->middleware('throttle:20,1')->name('update');
    Route::delete('/{group}', [ModelGroupController::class, 'destroy'])->where('group', '[A-Za-z0-9][A-Za-z0-9._-]*')->middleware('throttle:20,1')->name('destroy');
});
Route::middleware(EnsureLocalPreparationAccess::class)->prefix('preparations')->name('preparations.')->group(function (): void {
    Route::get('/', [PreparationComparisonController::class, 'index'])->name('index');
    Route::get('/create', [PreparationComparisonController::class, 'create'])->name('create');
    Route::post('/', [PreparationComparisonController::class, 'store'])->middleware('throttle:5,1')->name('store');
    Route::get('/{preparationComparison}', [PreparationComparisonController::class, 'show'])->name('show');
    Route::get('/{preparationComparison}/status', PreparationStatusController::class)->name('status');
    Route::delete('/{preparationComparison}/execution', [PreparationExecutionController::class, 'destroy'])
        ->middleware('throttle:10,1')->name('stop');
    Route::get('/{preparationComparison}/packages/{preparationPackage}/download', [PreparationPackageController::class, 'download'])
        ->scopeBindings()->name('packages.download');
    Route::put('/{preparationComparison}/packages/{preparationPackage}/evaluation', [PreparationEvaluationController::class, 'update'])
        ->scopeBindings()->middleware('throttle:30,1')->name('packages.evaluation.update');
});
Route::get('/models/{modelSlug}', [ModelController::class, 'show'])
    ->where('modelSlug', '[^/]+')
    ->name('models.show');
Route::get('/runner', [BenchmarkExecutionController::class, 'create'])->name('runner.create');
Route::post('/runner', [BenchmarkExecutionController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('runner.store');
Route::post('/runner/openrouter/sync', OpenRouterCatalogController::class)
    ->middleware('throttle:2,1')
    ->name('runner.openrouter.sync');
Route::get('/runner/openrouter/endpoints', OpenRouterEndpointController::class)
    ->middleware('throttle:60,1')
    ->name('runner.openrouter.endpoints');
Route::post('/runner/opencode-go/sync', OpenCodeGoCatalogController::class)
    ->middleware('throttle:2,1')
    ->name('runner.opencode_go.sync');
Route::get('/runner/status', BenchmarkExecutionStatusController::class)->name('runner.status');
Route::get('/runner/preflight', BenchmarkPreflightController::class)->name('runner.preflight');
Route::delete('/runner', StopBenchmarkExecutionController::class)
    ->middleware('throttle:10,1')
    ->name('runner.stop');
Route::get('/runs/{benchmarkRun}', [BenchmarkRunController::class, 'show'])
    ->name('runs.show');
Route::delete('/runs/{benchmarkRun}/workspace', [BenchmarkWorkspaceController::class, 'destroy'])
    ->middleware('throttle:5,1')
    ->name('runs.workspace.destroy');
Route::delete('/runs/{benchmarkRun}/results', [BenchmarkResultController::class, 'destroy'])
    ->middleware('throttle:5,1')
    ->name('runs.results.destroy');
Route::delete('/runs/{benchmarkRun}', [BenchmarkRunController::class, 'destroy'])
    ->middleware('throttle:5,1')
    ->name('runs.destroy');
Route::get('/runs/{benchmarkRun}/compare', BenchmarkComparisonController::class)
    ->name('runs.compare');
Route::get('/runs/{benchmarkRun}/models/{modelRun}/preview', BenchmarkStandalonePreviewController::class)
    ->scopeBindings()
    ->name('runs.models.preview');
Route::put('/runs/{benchmarkRun}/models/{modelRun}/evaluation', [ModelEvaluationController::class, 'update'])
    ->scopeBindings()
    ->middleware('throttle:30,1')
    ->name('runs.models.evaluation.update');
Route::post('/runs/{benchmarkRun}/models/{modelRun}/attempts', [ModelRunAttemptController::class, 'store'])
    ->scopeBindings()
    ->middleware('throttle:5,1')
    ->name('runs.models.attempts.store');
Route::delete('/runs/{benchmarkRun}/models/{modelRun}/attempts/{modelRunAttempt}', [ModelRunAttemptController::class, 'destroy'])
    ->scopeBindings()
    ->middleware('throttle:10,1')
    ->name('runs.models.attempts.destroy');
Route::get('/runs/{benchmarkRun}/artifacts/{artifact}', [ArtifactController::class, 'show'])
    ->scopeBindings()
    ->name('runs.artifacts.show');
Route::get('/previews/{benchmarkRun}/{modelRun}/{path?}', BenchmarkPreviewController::class)
    ->scopeBindings()
    ->where('path', '.*')
    ->withoutMiddleware([
        PreventRequestForgery::class,
        StartSession::class,
        ShareErrorsFromSession::class,
    ])
    ->name('runs.previews.show');
Route::get('/recovered-previews/{benchmarkRun}/{modelRun}/{path?}', BenchmarkPreviewController::class)
    ->scopeBindings()
    ->where('path', '.*')
    ->withoutMiddleware([
        PreventRequestForgery::class,
        StartSession::class,
        ShareErrorsFromSession::class,
    ])
    ->name('runs.previews.recovered');
