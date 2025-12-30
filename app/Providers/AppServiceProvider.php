<?php

namespace App\Providers;

use App\Models\Inventory;
use App\Observers\InventoryObserver;
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

        // 注册库存观察者（用于单条通道的自动推送）
        // 注意：可以通过环境变量 ENABLE_INVENTORY_OBSERVER 控制是否启用
        if (env('ENABLE_INVENTORY_OBSERVER', true)) {
            Inventory::observe(InventoryObserver::class);
        }
    }
}
