<?php

namespace App\Http\Controllers;

use App\Http\Requests\SyncOpenCodeGoCatalogRequest;
use App\Services\OpenCodeGoCatalog;
use Illuminate\Http\RedirectResponse;
use Throwable;

class OpenCodeGoCatalogController extends Controller
{
    public function __invoke(
        SyncOpenCodeGoCatalogRequest $request,
        OpenCodeGoCatalog $catalog,
    ): RedirectResponse {
        try {
            $count = $catalog->sync();
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'catalog' => 'OpenCode Go model kataloğu güncellenemedi. Bağlantıyı kontrol edip tekrar deneyin.',
            ]);
        }

        return back()->with('status', "OpenCode Go kataloğu güncellendi: {$count} model.");
    }
}
