<?php

namespace App\Providers;

use App\Services\BenchForgeSettings;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(BenchForgeSettings $settings): void
    {
        $settings->apply();
    }
}
