<?php

namespace App\Providers;

use App\Models\Inventory;
use App\Observers\InventoryObserver;
use App\Models\TicketPrice;
use App\Observers\TicketPriceObserver;
use App\Models\Res\ResHotelDailyStock;
use App\Observers\ResHotelDailyStockObserver;
use App\Models\Pkg\PkgProductHotelRoomType;
use App\Observers\PkgProductHotelRoomTypeObserver;
use App\Models\Pkg\PkgProductBundleItem;
use App\Observers\PkgProductBundleItemObserver;
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
        Inventory::observe(InventoryObserver::class);
        
        // 注册打包产品价格更新观察者
        TicketPrice::observe(TicketPriceObserver::class);
        ResHotelDailyStock::observe(ResHotelDailyStockObserver::class);
        PkgProductHotelRoomType::observe(PkgProductHotelRoomTypeObserver::class);
        PkgProductBundleItem::observe(PkgProductBundleItemObserver::class);
    }
}
