<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSystemSettingsRequest;
use App\Services\BenchForgeSettings;
use App\Services\BenchForgeSystemStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class SystemSettingsController extends Controller
{
    public function index(Request $request, BenchForgeSettings $settings, BenchForgeSystemStatus $systemStatus): View
    {
        abort_unless(in_array($request->ip(), ['127.0.0.1', '::1'], true), 403);

        return view('system.index', [
            'system' => $systemStatus->inspect(),
            'settings' => $settings->values(),
            'busy' => $settings->busy(),
        ]);
    }

    public function update(UpdateSystemSettingsRequest $request, BenchForgeSettings $settings): RedirectResponse
    {
        try {
            $settings->save($request->validated());
        } catch (RuntimeException $exception) {
            return back()->withInput()->withErrors(['settings' => $exception->getMessage()]);
        }

        return redirect()->route('system.index')->with('status', 'Sistem ayarları kaydedildi. Yeni işlemler bu ayarları kullanacak; mevcut dosyalar taşınmadı.');
    }
}
