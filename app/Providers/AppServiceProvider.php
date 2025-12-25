<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

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
    public function boot(): void
    {
        //
        // 检查是否在生产环境或特定的穿透域名下
        if (config('app.env') !== 'local' || str_contains(request()->getHost(), 'cpolar.top')) {
            URL::forceScheme('https');
        }
    }
}
