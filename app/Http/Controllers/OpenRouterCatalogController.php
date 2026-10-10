<?php

namespace App\Http\Controllers;

use App\Http\Requests\SyncOpenRouterCatalogRequest;
use App\Services\OpenRouterCatalog;
use Illuminate\Http\RedirectResponse;
use Throwable;

class OpenRouterCatalogController extends Controller
{
    public function __invoke(
        SyncOpenRouterCatalogRequest $request,
        OpenRouterCatalog $catalog,
    ): RedirectResponse {
        try {
            $count = $catalog->sync();
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'catalog' => 'OpenRouter model kataloğu güncellenemedi. Bağlantıyı kontrol edip tekrar deneyin.',
            ]);
        }

        return back()->with('status', "OpenRouter kataloğu güncellendi: {$count} model.");
    }
}
